<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiFailure;
use App\Services\Ai\CurlTransport;
use App\Services\Ai\EndpointPolicy;
use App\Services\Ai\ModelCapabilities;
use App\Services\Ai\ModelDiscovery;
use App\Services\Ai\Profile;
use Config\Ai;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiModelDiscoveryTest extends TestCase
{
    private function profile(array $changes = []): Profile
    {
        $config = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        foreach (array_replace(['baseUrl' => 'https://gateway.example.test/chat/completions/team/v1',
            'allowedOrigins' => 'https://gateway.example.test', 'apiKey' => 'synthetic-secret'], $changes) as $key => $value) { $config->$key = $value; }
        return Profile::fromConfig($config);
    }

    private function discover(array $data, array $config = []): array
    {
        return (new ModelDiscovery($this->profile($config), static function ($p, $e, $h, $b, $receive, $method) use ($data): int {
            self::assertSame('GET', $method); self::assertSame('', $b);
            $receive(json_encode(['data' => $data], JSON_THROW_ON_ERROR)); return 200;
        }, static fn (): array => ['8.8.8.8']))->discover();
    }

    public function testCurlRejectsOtherMethods(): void
    {
        if (!extension_loaded('curl')) { self::markTestSkipped('cURL unavailable'); }
        $p = $this->profile();
        $e = (new EndpointPolicy($p, static fn (): array => ['8.8.8.8']))->validate();
        $failure = null;
        $this->expectExceptionMessage('invalid_request');
        (new CurlTransport())->options($p, $e, [], '', static function (): void {}, $failure, 'DELETE');
    }

    public function testGetUsesOnlyFixedSuffixAndPreservesPinAndGeneratedAuth(): void
    {
        $called = false;
        $rows = (new ModelDiscovery($this->profile(), static function ($p, $endpoint, $headers, $body, $receive, $method) use (&$called): int {
            $called = true;
            self::assertSame('https://gateway.example.test/chat/completions/team/v1/models', $endpoint['url']);
            self::assertSame(['gateway.example.test:443:8.8.8.8'], $endpoint['resolve']);
            self::assertContains('Authorization: Bearer synthetic-secret', $headers);
            self::assertSame('GET', $method); self::assertSame('', $body);
            $receive('{"data":[{"id":"org/model","endpoint":"http://127.0.0.1","api_key":"private","description":"private prose"}]}');
            return 200;
        }, static fn (): array => ['8.8.8.8']))->discover();
        self::assertTrue($called);
        self::assertSame('org/model', $rows[0]['id']);
        self::assertStringNotContainsString('private', json_encode($rows));
        self::assertNull($rows[0]['context_window']);
    }

    public function testExplicitMetadataReservesOutputAndOverheadAndGuardsReasoning(): void
    {
        $row = $this->discover([['id' => 'test', 'context_length' => 4096, 'top_provider' => ['max_completion_tokens' => 512],
            'capabilities' => ['thinking' => false]]], ['model' => 'test', 'reasoningEffort' => 'high', 'thinkingMode' => 'enabled'])[0];
        self::assertSame(4096, $row['context_window']); self::assertSame(512, $row['max_output_tokens']);
        self::assertSame(3456, $row['defaults']['max_input_tokens']);
        self::assertSame('', $row['defaults']['reasoning_effort']); self::assertSame('', $row['defaults']['thinking_mode']);
        self::assertFalse($row['supports_thinking']);
    }

    public function testSmallContextModelsAreSkippedWithoutBlockingUsableChatModels(): void
    {
        $rows = $this->discover([
            ['id' => 'embedding', 'context_window' => 512],
            ['id' => 'chat', 'context_length' => 4096],
            ['id' => 'too-small', 'max_model_len' => 640],
            ['id' => 'minimum', 'top_provider' => ['context_length' => 641]],
            ['id' => 'unknown-capacity'],
        ]);
        self::assertSame(['chat', 'minimum', 'unknown-capacity'], array_column($rows, 'id'));
        self::assertSame(4096, $rows[0]['context_window']);
        self::assertSame(1968, $rows[0]['defaults']['max_input_tokens']);
        self::assertSame(641, $rows[1]['context_window']);
        self::assertSame(512, $rows[1]['defaults']['max_input_tokens']);
        self::assertSame(1, $rows[1]['defaults']['max_output_tokens']);
        self::assertNull($rows[2]['context_window']);
    }

    public function testSmallContextOnlyDiscoveryReturnsEmptyList(): void
    {
        self::assertSame([], $this->discover([
            ['id' => 'one', 'context_window' => 1], ['id' => 'embedding', 'context_length' => 512],
            ['id' => 'small', 'max_model_len' => 640], ['id' => 'nested', 'top_provider' => ['context_length' => 512]],
        ]));
    }

    public function testSmallContextDoesNotBypassMalformedIdValidation(): void
    {
        $this->expectExceptionMessage('invalid_response');
        $this->discover([['id' => 'chat', 'context_window' => 4096], ['id' => 'bad id', 'context_window' => 512]]);
    }

    public function testSmallContextDoesNotBypassCredentialReflectionChecks(): void
    {
        $this->expectExceptionMessage('invalid_response');
        $this->discover([['id' => 'embedding', 'name' => 'synthetic-secret', 'context_window' => 512]]);
    }

    public function testExplicitSupportedParametersPermitCurrentOptions(): void
    {
        $row = $this->discover([['id' => 'test', 'supported_parameters' => ['thinking', 'reasoning_effort', 'response_format', 'max_completion_tokens']]],
            ['model' => 'test', 'thinkingMode' => 'disabled', 'reasoningEffort' => 'low', 'structuredOutputMode' => 'json_object'])[0];
        self::assertTrue($row['supports_thinking']);
        self::assertSame('disabled', $row['defaults']['thinking_mode']);
        self::assertSame('low', $row['defaults']['reasoning_effort']);
        self::assertSame('json_object', $row['defaults']['structured_output']);
        self::assertSame('max_completion_tokens', $row['defaults']['token_field']);
    }

    #[DataProvider('presetIds')]
    public function testReviewedPresetIsAnchoredAndNeverInventsContext(string $id, bool $preset): void
    {
        $row = $this->discover([['id' => $id]])[0];
        self::assertSame($preset ? 'disabled' : '', $row['defaults']['thinking_mode']);
        self::assertNull($row['context_window']);
        if ($preset) { self::assertSame('reviewed preset', $row['sources']['defaults.thinking_mode']); }
    }

    public static function presetIds(): array
    {
        return [['MiniMax-M3', true], ['MINIMAX/minimax-m3', true], ['org/MiniMax-M3-Lightning', false],
            ['fake-MiniMax-M3', false], ['MiniMax-M30', false], ['MiniMax-M3-evil', false], ['org/other/MiniMax-M3', false], ['model-128k', false]];
    }

    public function testUnsafeDisplayNameFallsBackAndRowsSort(): void
    {
        $rows = $this->discover([['id' => 'z', 'name' => '<script>private</script>'], ['id' => 'a', 'name' => 'https://private.test']]);
        self::assertSame(['a', 'z'], array_column($rows, 'id'));
        self::assertSame(['a', 'z'], array_column($rows, 'name'));
    }

    #[DataProvider('invalidCatalogs')]
    public function testRejectsInvalidCatalogs(array $data, string $reason): void
    {
        $this->expectExceptionMessage($reason); $this->discover($data);
    }

    public static function invalidCatalogs(): array
    {
        return [[[['id' => 'x'], ['id' => 'x']], 'catalog_duplicate_id'], [[['id' => '']], 'invalid_response'],
            [[['id' => "bad\nname"]], 'invalid_response'], [array_fill(0, 201, ['id' => 'x']), 'catalog_too_large']];
    }

    #[DataProvider('opaqueIds')]
    public function testOpaqueIdsMatchProfileContractAndSurviveDiscovery(string $id): void
    {
        $profile = $this->profile(['model' => $id]);
        self::assertSame($id, $profile->config->model);
        $row = $this->discover([['id' => $id]])[0];
        self::assertSame($id, $row['id']); self::assertSame($id, $row['name']);
    }

    public static function opaqueIds(): array
    {
        return [['llama3.2:latest'], ['org/model@revision'], ['model+variant'], ['模型/日本語:最新版'],
            ['model?variant=fast&revision=2'], ['../opaque//id'], [str_repeat('x', 191)], ['1'], ['01']];
    }

    public function testCaseDistinctIdsAreNotDuplicates(): void
    {
        self::assertSame(['Foo', 'foo'], array_column($this->discover([['id' => 'foo'], ['id' => 'Foo']]), 'id'));
    }

    #[DataProvider('reflectedCredentials')]
    public function testReflectedCredentialsFailWithSafeResponse(array $model): void
    {
        try { $this->discover([$model]); self::fail('Expected reflection rejection'); }
        catch (AiFailure $error) {
            self::assertSame('invalid_response', $error->reason);
            self::assertStringNotContainsString('synthetic-secret', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public static function reflectedCredentials(): array
    {
        return [[[ 'id' => 'synthetic-secret' ]], [['id' => 'prefix-synthetic-secret-suffix']],
            [['id' => 'normal', 'name' => 'prefix synthetic-secret suffix']],
            [['id' => 'normal', 'name' => '<b>synthetic-secret</b>']]];
    }

    public function testUnicodeDisplayNameIsPreserved(): void
    {
        self::assertSame('模型 日本語: 最新版', $this->discover([['id' => 'model:latest', 'name' => '模型 日本語: 最新版']])[0]['name']);
    }

    #[DataProvider('invalidOpaqueIds')]
    public function testInvalidOpaqueIdsAreRejected(mixed $id): void
    {
        $this->expectExceptionMessage('invalid_response');
        ModelCapabilities::normalize([['id' => $id]], $this->profile());
    }

    public static function invalidOpaqueIds(): array
    {
        return [[null], [12], [''], ['with space'], ["with\tcontrol"], ["with\u{00a0}space"],
            ["with\u{200b}format"], [str_repeat('x', 192)], ["bad\xff"]];
    }

    #[DataProvider('authModes')]
    public function testAuthIsProfileOwned(string $mode, string $key, ?string $header): void
    {
        (new ModelDiscovery($this->profile(['authMode' => $mode, 'apiKey' => $key]), static function ($p, $e, $headers, $b, $receive) use ($header): int {
            self::assertSame($header === null ? ['Accept: application/json', 'Expect:'] : ['Accept: application/json', 'Expect:', $header], $headers);
            $receive('{"data":[]}'); return 200;
        }, static fn (): array => ['8.8.8.8']))->discover();
    }

    public static function authModes(): array
    {
        return [['none', '', null], ['api_key_header', 'key', 'X-API-Key: key'], ['bearer', 'key', 'Authorization: Bearer key']];
    }

    public function testMixedDnsAnswerNeverCallsTransport(): void
    {
        $this->expectExceptionMessage('address_not_allowed');
        (new ModelDiscovery($this->profile(), static function (): void { self::fail('No outbound request allowed'); },
            static fn (): array => ['8.8.8.8', '127.0.0.1']))->discover();
    }

    #[DataProvider('statuses')]
    public function testHttpFailuresNeverExposeBody(int $status, string $reason): void
    {
        $this->expectExceptionMessage($reason);
        (new ModelDiscovery($this->profile(), static function ($p, $e, $h, $b, $receive) use ($status): int {
            $receive('private provider prose'); return $status;
        }, static fn (): array => ['8.8.8.8']))->discover();
    }

    public static function statuses(): array
    {
        return [[302, 'provider_rejected'], [401, 'provider_auth_failed'], [403, 'provider_auth_failed'],
            [429, 'provider_rate_limited'], [500, 'provider_unavailable'], [0, 'transport_failed']];
    }

    public function testResponseBoundSurvivesTransportSwallowingConsumerFailure(): void
    {
        $this->expectExceptionMessage('response_too_large');
        (new ModelDiscovery($this->profile(['maxResponseBytes' => 256]), static function ($p, $e, $h, $b, $receive): int {
            try { $receive(str_repeat('x', 257)); } catch (AiFailure) {} return 200;
        }, static fn (): array => ['8.8.8.8']))->discover();
    }

    #[DataProvider('capacityFields')]
    public function testExplicitCapacityAliases(array $metadata, string $source): void
    {
        $row = $this->discover([['id' => 'test'] + $metadata])[0];
        self::assertSame(16384, $row['context_window']);
        self::assertSame('provider: ' . $source, $row['sources']['context_window']);
    }

    public static function capacityFields(): array
    {
        return [[['context_window' => 16384], 'context_window'], [['context_length' => 16384], 'context_length'],
            [['max_model_len' => 16384], 'max_model_len'], [['top_provider' => ['context_length' => 16384]], 'top_provider.context_length']];
    }

    #[DataProvider('malformedResponses')]
    public function testMalformedResponsesFailWithoutProviderProse(string $response): void
    {
        $this->expectExceptionMessage('invalid_response');
        (new ModelDiscovery($this->profile(), static function ($p, $e, $h, $b, $receive) use ($response): int {
            $receive($response); return 200;
        }, static fn (): array => ['8.8.8.8']))->discover();
    }

    public static function malformedResponses(): array
    {
        return [['private provider error'], ['[]'], ['{"data":{}}'], ['{"data":[[]]}'],
            ['{"error":"private","data":[]}']];
    }

    public function testInvalidMetadataCannotSetBudgetsOrCapabilities(): void
    {
        $row = $this->discover([['id' => 'test-128k', 'context_length' => '128000', 'max_output_tokens' => -1,
            'supports_thinking' => 'true', 'capabilities' => ['thinking' => 'enabled'],
            'defaults' => ['max_input_tokens' => 999999], 'supported_parameters' => ['reasoning_effort' => true]]])[0];
        self::assertNull($row['context_window']); self::assertNull($row['max_output_tokens']);
        self::assertNull($row['supports_thinking']); self::assertSame(8192, $row['defaults']['max_input_tokens']);
        self::assertSame('', $row['defaults']['reasoning_effort']);
    }

    public function testDiscoveryDoesNotRequireConfiguredCompletionModelOrEnableAnalytics(): void
    {
        $rows = $this->discover([['id' => 'discovered']], ['model' => '', 'enabled' => false]);
        self::assertCount(1, $rows);
        self::assertArrayNotHasKey('enabled', $rows[0]);
    }

    public function testGetCurlOptionsHaveNoPostFieldsAndKeepSecurityOptions(): void
    {
        if (!extension_loaded('curl')) { self::markTestSkipped('cURL unavailable'); }
        $p = $this->profile(); $e = (new EndpointPolicy($p, static fn (): array => ['8.8.8.8']))->validate();
        $failure = null; $transport = new CurlTransport();
        $get = $transport->options($p, $e, [], '', static function (): void {}, $failure, 'GET');
        $post = $transport->options($p, $e, [], '{}', static function (): void {});
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $get); self::assertArrayNotHasKey(CURLOPT_POST, $get);
        self::assertTrue($get[CURLOPT_HTTPGET]); self::assertSame('{}', $post[CURLOPT_POSTFIELDS]);
        unset($get[CURLOPT_HTTPGET], $post[CURLOPT_POST], $post[CURLOPT_POSTFIELDS], $get[CURLOPT_WRITEFUNCTION], $post[CURLOPT_WRITEFUNCTION], $get[CURLOPT_HEADERFUNCTION], $post[CURLOPT_HEADERFUNCTION]);
        self::assertSame($post, $get);
        $this->expectExceptionMessage('invalid_request');
        $transport->options($p, $e, [], '{}', static function (): void {}, $failure, 'GET');
    }
}

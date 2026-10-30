<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiFailure;
use App\Services\Ai\BoundedResolver;
use App\Services\Ai\ChatClient;
use App\Services\Ai\CurlTransport;
use App\Services\Ai\EndpointPolicy;
use App\Services\Ai\Profile;
use Config\Ai;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/** All addresses, credentials, prompts and provider responses are synthetic; no sockets or database. */
final class AiEndpointTest extends TestCase
{
    private function config(): Ai
    {
        $c = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        $c->baseUrl = 'https://gateway.example.test/team-a/v1/';
        $c->allowedOrigins = 'https://gateway.example.test';
        $c->model = 'org/test-model';
        $c->apiKey = 'synthetic-secret';

        return $c;
    }

    private static function resolver(string $host): array
    {
        self::assertSame('gateway.example.test', $host);

        return ['8.8.8.8', '2606:4700:4700::1111'];
    }

    private static function envelope(string $content = '{"ok":true}'): string
    {
        return json_encode(['choices' => [[
            'index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $content],
        ]]], JSON_THROW_ON_ERROR);
    }

    private function client(Ai $config, string $response, int $status = 200): ChatClient
    {
        return new ChatClient(Profile::fromConfig($config), static function ($p, $e, $h, $b, $receive) use ($response, $status): int {
            foreach (str_split($response, 17) as $chunk) {
                $receive($chunk);
            }

            return $status;
        }, self::resolver(...));
    }

    private function messages(): array
    {
        return [['role' => 'system', 'content' => 'Return synthetic JSON.'], ['role' => 'user', 'content' => '{"fixture":true}']];
    }

    public static function basePaths(): iterable
    {
        yield ['https://gateway.example.test', 'https://gateway.example.test/chat/completions'];
        yield ['https://gateway.example.test/', 'https://gateway.example.test/chat/completions'];
        yield ['https://gateway.example.test/team-a/v1', 'https://gateway.example.test/team-a/v1/chat/completions'];
        yield ['https://gateway.example.test/team-a/v1/', 'https://gateway.example.test/team-a/v1/chat/completions'];
        yield ['https://GATEWAY.example.test:443/Team.A/~v2/', 'https://gateway.example.test/Team.A/~v2/chat/completions'];
        yield ['https://gateway.example.test:8443/api', 'https://gateway.example.test:8443/api/chat/completions'];
        yield ['https://[2606:4700:4700::1111]:8443/v1', 'https://[2606:4700:4700::1111]:8443/v1/chat/completions'];
    }

    #[DataProvider('basePaths')]
    public function testPreservesExactBasePath(string $base, string $url): void
    {
        self::assertSame($url, EndpointPolicy::parseBaseUrl($base)['url']);
    }

    public static function badUrls(): iterable
    {
        foreach (['', 'ftp://example.test', 'https:///missing', '//example.test', 'HTTPS://example.test',
            'https://user:password@example.test/v1', 'https://example.test/v1?', 'https://example.test/v1?secret=x',
            'https://example.test/v1#', 'https://example.test/v1#secret', "https://example.test/\nv1", 'https://example.test\\@evil.test',
            'https://example.test/v1/chat/completions', 'https://example.test/v1/chat/completions/', 'https://example.test/CHAT/COMPLETIONS',
            'https://example.test:0', 'https://example.test:65536', 'https://example.test:abc', 'https://example.test:',
            'https://example.test:0443', 'https://example.test.', 'https://bad..test', 'https://-bad.test', 'https://bad_.test',
            'https://127.1', 'https://2130706433', 'https://0177.0.0.1', 'https://0x7f000001', 'https://0x7f.0.0.1',
            'https://[fe80::1%25eth0]', 'https://éxample.test', 'https://example.test/a/../v1', 'https://example.test/a/./v1',
            'https://example.test/a//v1', 'https://example.test/a/%2e%2e/v1', 'https://example.test/a%2fv1',
            'https://example.test/a%252fv1', 'https://example.test/a;b', 'https://example.test/a b'] as $url) {
            yield [$url];
        }
    }

    #[DataProvider('badUrls')]
    public function testAmbiguousUrlsAreRejected(string $url): void
    {
        $this->fails('invalid_config', static fn () => EndpointPolicy::parseBaseUrl($url));
    }

    public static function addresses(): iterable
    {
        foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '2001:4860:4860::8888'] as $ip) {
            yield [$ip, false, true];
            yield [$ip, true, false];
        }
        foreach (['10.1.2.3', '172.16.0.1', '192.168.2.1', '127.0.0.1', '::1', 'fd12:3456::1', 'fc00::1'] as $ip) {
            yield [$ip, false, false];
            yield [$ip, true, true];
        }
        foreach (['0.0.0.0', '0.1.2.3', '169.254.169.254', '169.254.1.1', '100.100.100.200', '100.64.0.1',
            '192.0.0.1', '192.0.2.1', '192.88.99.1', '198.18.0.1', '198.51.100.1', '203.0.113.1',
            '224.0.0.1', '240.0.0.1', '255.255.255.255', '168.63.129.16', '::', 'fe80::1', 'fec0::1', 'ff02::1',
            '::ffff:8.8.8.8', '::ffff:127.0.0.1', '::ffff:0808:0808', '::8.8.8.8', '64:ff9b::808:808',
            '2001::1', '2001:db8::1', '2001:20::1', '2002:0808:0808::1', '3fff::1', '4000::1',
                        'fd00:ec2::254', 'fd20:ce::254',
            '2130706433', '0177.0.0.1', '127.1', '0x7f000001', '[::1]', 'fe80::1%eth0', '', 'invalid'] as $ip) {
            yield [$ip, false, false];
            yield [$ip, true, false];
        }
    }

    #[DataProvider('addresses')]
    public function testAddressPolicy(string $address, bool $private, bool $allowed): void
    {
        self::assertSame($allowed, EndpointPolicy::addressAllowed($address, $private), $address);
    }

    public function testOriginMustMatchSchemeHostAndPortExactly(): void
    {
        foreach (['https://example.test', 'https://example.test.evil.test', 'http://gateway.example.test', 'https://gateway.example.test:8443'] as $origin) {
            $c = $this->config();
            $c->allowedOrigins = $origin;
            $this->fails('endpoint_not_allowed', static fn () => Profile::fromConfig($c));
        }
    }

    public function testResolvesAllAnswersAndPinsWithoutChangingUrlHostname(): void
    {
        $policy = new EndpointPolicy(Profile::fromConfig($this->config()), self::resolver(...));
        $endpoint = $policy();
        self::assertSame(['8.8.8.8', '2606:4700:4700::1111'], $endpoint['addresses']);
        self::assertSame(['gateway.example.test:443:8.8.8.8'], $endpoint['resolve']);
        self::assertSame('https://gateway.example.test/team-a/v1/chat/completions', $endpoint['url']);
        $policy = new EndpointPolicy(Profile::fromConfig($this->config()), static fn () => ['2606:4700:4700::1111']);
        self::assertSame(['gateway.example.test:443:[2606:4700:4700::1111]'], $policy()['resolve']);
    }

    public static function badDns(): iterable
    {
        yield [[], 'dns_failed'];
        yield [['bad' => '8.8.8.8'], 'dns_failed'];
        yield [array_fill(0, 65, '8.8.8.8'), 'dns_failed'];
        yield [['8.8.8.8', '127.0.0.1'], 'address_not_allowed'];
        yield [['8.8.8.8', '::1'], 'address_not_allowed'];
        yield [['2606:4700:4700::1111', '10.0.0.1'], 'address_not_allowed'];
        yield [['8.8.8.8', '::ffff:8.8.8.8'], 'address_not_allowed'];
        yield [['8.8.8.8', 'invalid'], 'address_not_allowed'];
        yield [[false], 'address_not_allowed'];
    }

    #[DataProvider('badDns')]
    public function testEveryDnsAnswerMustBeApproved(array $answers, string $reason): void
    {
        $policy = new EndpointPolicy(Profile::fromConfig($this->config()), static fn () => $answers);
        $this->fails($reason, $policy);
    }

    public function testDnsExceptionsAreRedacted(): void
    {
        $policy = new EndpointPolicy(Profile::fromConfig($this->config()), static function (): never {
            throw new RuntimeException('raw dns secret');
        });
        $this->fails('dns_failed', $policy);
    }

    public function testPrivateAndHttpExceptionsRequireExactDeploymentPolicy(): void
    {
        $c = $this->config();
        $c->baseUrl = 'http://gateway.example.test:8080/team/v1';
        $c->allowedOrigins = 'http://gateway.example.test:8080';
        $this->fails('endpoint_not_allowed', static fn () => Profile::fromConfig($c));
        $c->allowLocalHttp = true;
        $this->fails('endpoint_not_allowed', static fn () => Profile::fromConfig($c));
        $c->privateOrigins = $c->allowedOrigins;
        $profile = Profile::fromConfig($c);
        $policy = new EndpointPolicy($profile, static fn () => ['10.1.2.3', 'fd12::1']);
        self::assertSame(['gateway.example.test:8080:10.1.2.3'], $policy()['resolve']);
        foreach ([['169.254.169.254'], ['10.1.2.3', '8.8.8.8'], ['::ffff:10.1.2.3']] as $answers) {
            $this->fails('address_not_allowed', new EndpointPolicy($profile, static fn () => $answers));
        }
        $transport = static function ($p, $e, $h, $b, $receive): int {
            $receive(self::envelope());

            return 200;
        };
        $client = new ChatClient($profile, $transport, static fn () => ['10.1.2.3']);
        self::assertSame(['success' => true, 'verified' => true], $client->probe());
        $this->fails('http_dispatch_not_approved', fn () => $client->complete($this->messages()));
        $c->allowPrivateHttpDispatch = true;
        $client = new ChatClient(Profile::fromConfig($c), $transport, static fn () => ['10.1.2.3']);
        self::assertSame('{"ok":true}', $client->complete($this->messages()));
    }

    public function testIpLiteralHasNoDnsFallback(): void
    {
        foreach (['https://8.8.8.8/v1', 'https://[2606:4700:4700::1111]/v1'] as $base) {
            $c = $this->config();
            $c->baseUrl = $base;
            $c->allowedOrigins = substr($base, 0, -3);
            $policy = new EndpointPolicy(Profile::fromConfig($c), static function (): never {
                self::fail('IP literals must not use DNS.');
            });
            self::assertSame([], $policy()['resolve']);
        }
    }

    public function testDnsIsRefreshedAndRebindingBlockedBeforeSecondTransport(): void
    {
        $resolutions = 0;
        $calls = 0;
        $client = new ChatClient(Profile::fromConfig($this->config()), static function ($p, $e, $h, $b, $receive) use (&$calls): int {
            ++$calls;
            self::assertSame(['gateway.example.test:443:8.8.8.8'], $e['resolve']);
            $receive(self::envelope());

            return 200;
        }, static function () use (&$resolutions): array {
            return ++$resolutions === 1 ? ['8.8.8.8'] : ['127.0.0.1'];
        });
        $client->complete($this->messages());
        $this->fails('address_not_allowed', fn () => $client->complete($this->messages()));
        self::assertSame(2, $resolutions);
        self::assertSame(1, $calls);
    }

    public function testNativeCurlOptionsEnforceIsolationTlsPinningAndStreamingBounds(): void
    {
        $c = $this->config();
        $c->maxResponseBytes = 256;
        $p = Profile::fromConfig($c);
        $endpoint = (new EndpointPolicy($p, self::resolver(...)))();
        $received = '';
        $failure = null;
        $transport = new CurlTransport();
        $options = $transport->options($p, $endpoint, ['Accept: application/json'], '{}', static function (string $chunk) use (&$received): void {
            $received .= $chunk;
        }, $failure);
        foreach ([CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true, CURLOPT_VERBOSE => false, CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_HTTPAUTH => CURLAUTH_NONE, CURLOPT_PROXYAUTH => CURLAUTH_NONE, CURLOPT_UNRESTRICTED_AUTH => false,
            CURLOPT_DNS_CACHE_TIMEOUT => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 45, CURLOPT_PATH_AS_IS => true] as $option => $value) {
            self::assertSame($value, $options[$option]);
        }
        self::assertSame($endpoint['url'], $options[CURLOPT_URL]);
        self::assertSame($endpoint['resolve'], $options[CURLOPT_RESOLVE]);
        self::assertArrayNotHasKey(CURLOPT_COOKIE, $options);
        self::assertArrayNotHasKey(CURLOPT_COOKIEFILE, $options);
        self::assertArrayNotHasKey(CURLOPT_COOKIEJAR, $options);
        self::assertArrayNotHasKey(CURLOPT_USERPWD, $options);
        $handle = curl_init();
        try {
            // Installing options is offline; deliberately never call curl_exec().
            self::assertTrue(curl_setopt_array($handle, $options));
        } finally {
            curl_close($handle);
        }
        self::assertSame(20, $options[CURLOPT_HEADERFUNCTION](null, str_repeat('h', 20)));
        self::assertSame(230, $options[CURLOPT_WRITEFUNCTION](null, str_repeat('b', 230)));
        self::assertSame(0, $options[CURLOPT_WRITEFUNCTION](null, 'overflow'));
        self::assertSame('response_too_large', $failure);
        self::assertSame(str_repeat('b', 230), $received);
        self::assertSame(0, $options[CURLOPT_HEADERFUNCTION](null, str_repeat('h', 10)));
        $this->fails('request_too_large', fn () => $transport->options($p, $endpoint, [], str_repeat('x', 65537), static function (): void {}));
    }

    public function testManagedCaPathIsPassedToCurlAndIncludedInRevision(): void
        {
            $c = $this->config();
            $oldRevision = Profile::fromConfig($c)->revision();
            // An existing readable fixture path suffices to inspect options; no TLS connection is attempted.
            $c->caBundle = __FILE__;
            $p = Profile::fromConfig($c);
            self::assertNotSame($oldRevision, $p->revision());
            $options = (new CurlTransport())->options($p, (new EndpointPolicy($p, self::resolver(...)))(), [], '{}', static function (): void {});
            self::assertSame(__FILE__, $options[CURLOPT_CAINFO]);
            self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
            self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        }

        public function testNativeConsumerExceptionsBecomeSafeFailures(): void
    {
        $p = Profile::fromConfig($this->config());
        $failure = null;
        $options = (new CurlTransport())->options($p, (new EndpointPolicy($p, self::resolver(...)))(), [], '{}', static function (): never {
            throw new RuntimeException('raw provider body and secret');
        }, $failure);
        self::assertSame(0, $options[CURLOPT_WRITEFUNCTION](null, 'fixture'));
        self::assertSame('transport_failed', $failure);
    }

    public function testEachAuthAndTokenCapabilityProducesOnlyExpectedFields(): void
    {
        foreach (['bearer', 'api_key_header', 'none'] as $auth) {
            foreach (['max_tokens', 'max_completion_tokens'] as $field) {
                $c = $this->config();
                $c->authMode = $auth;
                $c->apiKey = $auth === 'none' ? '' : 'synthetic-secret';
                $c->maxOutputTokensField = $field;
                $client = new ChatClient(Profile::fromConfig($c), function ($p, $e, $headers, $body, $receive) use ($auth, $field): int {
                    $expected = ['Content-Type: application/json', 'Accept: application/json', 'Expect:'];
                    if ($auth !== 'none') {
                        $expected[] = ($auth === 'bearer' ? 'Authorization: Bearer ' : 'X-API-Key: ') . 'synthetic-secret';
                    }
                    self::assertSame($expected, $headers);
                    self::assertSame(['model' => 'org/test-model', 'messages' => $this->messages(), $field => 2000, 'stream' => false], json_decode($body, true, 32, JSON_THROW_ON_ERROR));
                    $receive(self::envelope());

                    return 200;
                }, self::resolver(...));
                self::assertSame('{"ok":true}', $client->complete($this->messages()));
            }
        }
    }

    public static function invalidMessages(): iterable
    {
        yield [[]];
        yield [[1 => ['role' => 'user', 'content' => 'test']]];
        yield [[['role' => 'assistant', 'content' => 'test']]];
        yield [[['role' => 'tool', 'content' => 'test']]];
        yield [[['role' => 'system', 'content' => 'test']]];
        yield [[['role' => 'user', 'content' => 'test', 'tools' => []]]];
        yield [[['role' => 'user', 'content' => ['image_url' => 'test']]]];
        yield [[['role' => 'user', 'content' => "\0"]]];
        yield [[['role' => 'user', 'content' => ' ']]];
        yield [[['role' => 'user', 'content' => "\xff"]]];
        yield [[['role' => 'user', 'content' => 'test'], ['role' => 'system', 'content' => 'test']]];
        yield [array_fill(0, 33, ['role' => 'user', 'content' => 'test'])];
    }

    #[DataProvider('invalidMessages')]
    public function testInvalidMessagesNeverReachTransport(array $messages): void
    {
        $client = new ChatClient(Profile::fromConfig($this->config()), static function (): never {
            self::fail('No transport for invalid messages.');
        }, static function (): never {
            self::fail('No DNS for invalid messages.');
        });
        $this->fails('invalid_request', static fn () => $client->complete($messages));
    }

    public function testRequestSizeIsBoundedBeforeDnsIncludingJsonExpansion(): void
    {
        $c = $this->config();
        $c->maxRequestBytes = 512;
        $client = new ChatClient(Profile::fromConfig($c), static function (): never {
            self::fail('No transport for oversized requests.');
        }, static function (): never {
            self::fail('No DNS for oversized requests.');
        });
        foreach ([str_repeat('x', 513), 'x' . str_repeat("\n", 220)] as $content) {
            $this->fails('request_too_large', static fn () => $client->complete([['role' => 'user', 'content' => $content]]));
        }
    }

    public static function httpFailures(): iterable
    {
        yield [401, 'provider_auth_failed'];
        yield [403, 'provider_auth_failed'];
        yield [429, 'provider_rate_limited'];
        yield [500, 'provider_unavailable'];
        yield [503, 'provider_unavailable'];
        yield [302, 'provider_rejected'];
        yield [400, 'provider_rejected'];
        yield [204, 'provider_rejected'];
        yield [0, 'transport_failed'];
    }

    #[DataProvider('httpFailures')]
    public function testHttpFailuresNeverExposeBodies(int $status, string $reason): void
    {
        $client = $this->client($this->config(), 'synthetic-secret raw error body', $status);
        $this->fails($reason, fn () => $client->complete($this->messages()));
    }

    public static function invalidResponses(): iterable
    {
        foreach (['not json raw secret', '{}', '[]', 'null', '{"error":{"message":"secret"}}', '{"choices":[]}',
            '{"choices":[{},{}]}', '{"choices":[null]}', '{"choices":[{"message":[]}]}'] as $body) {
            yield [$body, 'invalid_response'];
        }
        foreach (['length', 'tool_calls', 'function_call', 'content_filter', null] as $finish) {
            $json = json_decode(self::envelope(), true);
            $json['choices'][0]['finish_reason'] = $finish;
            yield [json_encode($json), 'completion_rejected'];
        }
        foreach (['tool_calls' => [['id' => 'tool']], 'function_call' => ['name' => 'tool'], 'refusal' => 'blocked',
            'audio' => ['data' => 'unwanted'], 'role' => 'user'] as $field => $value) {
            $json = json_decode(self::envelope(), true);
            $json['choices'][0]['message'][$field] = $value;
            yield [json_encode($json), 'completion_rejected'];
        }
        foreach (['', ' ', [], null, 123, "bad\0content"] as $content) {
            $json = json_decode(self::envelope(), true);
            $json['choices'][0]['message']['content'] = $content;
            yield [json_encode($json), 'invalid_response'];
        }
    }

    #[DataProvider('invalidResponses')]
    public function testResponseEnvelopeMustBeCompletePlainAssistantContent(string $body, string $reason): void
    {
        $client = $this->client($this->config(), $body);
        $this->fails($reason, fn () => $client->complete($this->messages()));
    }

    public function testResponseLimitStopsDeliveryWhileReceiving(): void
    {
        $c = $this->config();
        $c->maxResponseBytes = 256;
        $chunks = 0;
        $client = new ChatClient(Profile::fromConfig($c), static function ($p, $e, $h, $b, $receive) use (&$chunks): int {
            for ($i = 0; $i < 100; ++$i) {
                ++$chunks;
                $receive(str_repeat('x', 100));
            }

            return 200;
        }, self::resolver(...));
        $this->fails('response_too_large', fn () => $client->complete($this->messages()));
        self::assertSame(3, $chunks);
    }

    public function testTransportExceptionsAreSafeAndNeverAutomaticallyRetried(): void
    {
        $calls = 0;
        $client = new ChatClient(Profile::fromConfig($this->config()), static function () use (&$calls): never {
            ++$calls;
            throw new RuntimeException('synthetic-secret request/response details');
        }, self::resolver(...));
        $this->fails('transport_failed', fn () => $client->complete($this->messages()));
        self::assertSame(1, $calls);
    }

    public function testSwallowingAnOverflowDoesNotTurnItIntoSuccess(): void
        {
            $c = $this->config();
            $c->maxResponseBytes = 256;
            $client = new ChatClient(Profile::fromConfig($c), static function ($p, $e, $h, $b, $receive): int {
                try {
                    $receive(str_repeat('x', 257));
                } catch (AiFailure) {
                    // A broken injected transport must still fail closed.
                }

                return 200;
            }, self::resolver(...));
            $this->fails('response_too_large', fn () => $client->complete($this->messages()));
        }

        public function testPrivateHttpsRequiresAnExactException(): void
        {
            $c = $this->config();
            $this->fails('address_not_allowed', new EndpointPolicy(Profile::fromConfig($c), static fn () => ['10.1.2.3']));
            $c->privateOrigins = $c->allowedOrigins;
            $policy = new EndpointPolicy(Profile::fromConfig($c), static fn () => ['10.1.2.3']);
            self::assertSame(['10.1.2.3'], $policy()['addresses']);
            $c->baseUrl = 'https://gateway.example.test:8443/v1';
            $c->allowedOrigins .= ',https://gateway.example.test:8443';
            $this->fails('address_not_allowed', new EndpointPolicy(Profile::fromConfig($c), static fn () => ['10.1.2.3']));
        }

        public function testHttpExceptionNeverAllowsPublicDestinations(): void
        {
            $c = $this->config();
            $c->baseUrl = 'http://gateway.example.test/v1';
            $c->allowedOrigins = 'http://gateway.example.test';
            $c->privateOrigins = $c->allowedOrigins;
            $c->allowLocalHttp = true;
            $c->allowPrivateHttpDispatch = true;
            $this->fails('address_not_allowed', new EndpointPolicy(Profile::fromConfig($c), static fn () => ['8.8.8.8']));
        }

        public function testJsonObjectCapabilityIsExplicitAndLocallyValidated(): void
    {
        $c = $this->config();
        $c->structuredOutputMode = 'json_object';
        $client = new ChatClient(Profile::fromConfig($c), static function ($p, $e, $h, $body, $receive): int {
            self::assertSame(['type' => 'json_object'], json_decode($body, true)['response_format']);
            $receive(self::envelope());

            return 200;
        }, self::resolver(...));
        self::assertSame('{"ok":true}', $client->complete($this->messages()));
        foreach (['not JSON', '[]', 'true', 'null'] as $content) {
            $this->fails('structured_output_invalid', fn () => $this->client($c, self::envelope($content))->complete($this->messages()));
        }
    }

    public function testProbeUsesFixedFixtureWhenDisabledAndNeverReturnsModelOutput(): void
    {
        $c = $this->config();
        self::assertFalse($c->enabled);
        self::assertFalse($c->governanceApproved);
        $c->maxInputTokens = 512;
        $c->maxOutputTokens = 16;
        $c->maxOutputTokensField = 'max_completion_tokens';
        $c->reasoningEffort = 'low';
        $client = new ChatClient(Profile::fromConfig($c), static function ($p, $e, $h, $body, $receive): int {
            $payload = json_decode($body, true);
            self::assertSame(16, $payload['max_completion_tokens']);
            self::assertArrayNotHasKey('max_tokens', $payload);
            self::assertArrayNotHasKey('maxInputTokens', $payload);
            self::assertSame('low', $payload['reasoning_effort']);
            self::assertSame('This is a synthetic connection test. Return only the JSON object {"ok":true}.', $payload['messages'][0]['content']);
            self::assertSame('Return {"ok":true}. No personal or clinical data is present.', $payload['messages'][1]['content']);
            self::assertFalse($payload['stream']);
            $receive(self::envelope());

            return 200;
        }, self::resolver(...));
        self::assertSame(['success' => true, 'verified' => true], $client->probe());
        foreach (['unverified secret output', '{"ok":false}', '{"ok":true,"secret":"raw output"}', '[]'] as $content) {
            $this->fails('probe_failed', fn () => $this->client($c, self::envelope($content))->probe());
        }
        foreach (['model', 'apiKey'] as $field) {
            $invalid = clone $c;
            $invalid->$field = '';
            $this->fails('invalid_config', fn () => $this->client($invalid, self::envelope())->probe());
        }
    }

    public function testUnsupportedReasoningChoiceFailsProbeWithoutFallbackOrRetry(): void
    {
        $c = $this->config();
        $c->reasoningEffort = 'xhigh';
        $calls = 0;
        $client = new ChatClient(Profile::fromConfig($c), static function ($p, $e, $h, $body, $receive) use (&$calls): int {
            ++$calls;
            self::assertSame('xhigh', json_decode($body, true, 32, JSON_THROW_ON_ERROR)['reasoning_effort']);
            $receive('{"error":{"message":"unsupported reasoning option; synthetic-secret"}}');

            return 400;
        }, self::resolver(...));
        $this->fails('provider_rejected', static fn () => $client->probe());
        self::assertSame(1, $calls);
    }

    public function testDispatchGateRunsOnceAfterDnsAndImmediatelyBeforeTransport(): void
    {
        $events = [];
        $client = new ChatClient(Profile::fromConfig($this->config()), static function ($p, $e, $h, $b, $receive) use (&$events): int {
            $events[] = 'transport';
            self::assertSame(['gateway.example.test:443:8.8.8.8'], $e['resolve']);
            $receive(self::envelope());

            return 200;
        }, static function () use (&$events): array {
            $events[] = 'dns';

            return ['8.8.8.8'];
        });
        self::assertSame('{"ok":true}', $client->complete($this->messages(), static function () use (&$events): void {
            self::assertSame(0, func_num_args());
            $events[] = 'gate';
        }));
        self::assertSame(['dns', 'gate', 'transport'], $events);
        $events = [];
        self::assertSame(['success' => true, 'verified' => true], $client->probe());
        self::assertSame(['dns', 'transport'], $events);
    }

    public function testPostDnsGateSafelyStopsExpiredLeasesPauseAndBudgetFailures(): void
    {
        foreach (['lease_lost' => 409, 'dispatch_paused' => 503, 'dispatch_budget_exceeded' => 429] as $reason => $status) {
            $resolved = false;
            $calls = 0;
            $client = new ChatClient(Profile::fromConfig($this->config()), static function () use (&$calls): int {
                ++$calls;

                return 200;
            }, static function () use (&$resolved): array {
                $resolved = true;

                return ['8.8.8.8'];
            });
            $this->fails($reason, function () use ($client, &$resolved, $reason, $status): void {
                $client->complete($this->messages(), static function () use (&$resolved, $reason, $status): never {
                    self::assertTrue($resolved);
                    throw new AiFailure($reason, $status);
                });
            }, $status);
            self::assertSame(0, $calls);
        }
        $client = new ChatClient(Profile::fromConfig($this->config()), static function (): never {
            self::fail('A failed dispatch gate must never reach transport.');
        }, self::resolver(...));
        $this->fails('dispatch_paused', fn () => $client->complete($this->messages(), static function (): never {
            throw new RuntimeException('raw lease database details and synthetic-secret');
        }));
    }

    public function testDnsDenialNeverCallsDispatchGate(): void
    {
        $called = false;
        $client = new ChatClient(Profile::fromConfig($this->config()), static function (): never {
            self::fail('No transport after DNS denial.');
        }, static fn () => ['8.8.8.8', '127.0.0.1']);
        $this->fails('address_not_allowed', fn () => $client->complete($this->messages(), static function () use (&$called): void {
            $called = true;
        }));
        self::assertFalse($called);
    }

    /** Real finite child processes, but DNS is disabled and replaced with synthetic PHP fixtures. */
    private function boundedFixture(string $code, mixed &$process = null, ?callable $inspect = null, bool $helper = false, int $timeoutSeconds = 3): BoundedResolver
    {
        $c = $this->config();
        $c->connectTimeoutSeconds = $timeoutSeconds;
        $c->resolverPhpBinary = PHP_BINARY;

        return new BoundedResolver(Profile::fromConfig($c), static function ($command, $descriptors, $cwd, $env, $options) use ($code, &$process, $inspect, $helper) {
            if ($inspect !== null) {
                $inspect($command, $descriptors, $cwd, $env, $options);
            }
            $fixture = [$command[0], '-n', '-d', 'display_errors=0', '-d', 'log_errors=0',
                '-d', 'disable_functions=dns_get_record', '-r', $code, '--'];
            if ($helper) {
                $fixture[] = $command[count($command) - 2];
            }
            $fixture[] = $command[count($command) - 1];
            $process = proc_open($fixture, $descriptors, $pipes, $cwd, $env, $options);

            return $process;
        });
    }

    public function testExactLocalhostResolvesWithoutStartingAProcess(): void
    {
        $resolver = new BoundedResolver(Profile::fromConfig($this->config()), static function (): never {
            self::fail('Exact localhost must not start a DNS process.');
        });
        self::assertSame(['127.0.0.1'], $resolver('localhost'));
    }

    public function testLocalhostMappingDoesNotApplyToOtherNamesOrSuffixes(): void
    {
        foreach (['localhost.example.test', 'sub.localhost', 'notlocalhost', 'local-host', 'gateway.example.test'] as $host) {
            $process = null;
            $resolver = $this->boundedFixture('echo "[\\"8.8.8.8\\"]";', $process,
                static function ($command) use ($host): void {
                    self::assertSame($host, $command[count($command) - 1]);
                });
            self::assertSame(['8.8.8.8'], $resolver($host));
            self::assertFalse(is_resource($process));
        }
        foreach (['localhost.', 'LOCALHOST', 'localhost;command', "localhost\0"] as $host) {
            $resolver = new BoundedResolver(Profile::fromConfig($this->config()), static function (): never {
                self::fail('Invalid localhost variant must not start a process.');
            });
            $this->fails('dns_failed', static fn () => $resolver($host));
        }
    }

    public function testLocalhostEndpointRequiresExactPrivateOriginAndHttpOptIn(): void
    {
        $c = $this->config();
        $c->baseUrl = 'http://LOCALHOST:20128/v1';
        $c->allowedOrigins = 'http://localhost:20128';
        $c->privateOrigins = $c->allowedOrigins;
        $this->fails('endpoint_not_allowed', static fn () => Profile::fromConfig($c));
        $c->allowLocalHttp = true;
        $endpoint = (new EndpointPolicy(Profile::fromConfig($c)))();
        self::assertSame(['127.0.0.1'], $endpoint['addresses']);
        self::assertSame(['localhost:20128:127.0.0.1'], $endpoint['resolve']);
        self::assertSame('http://localhost:20128/v1/chat/completions', $endpoint['url']);

        foreach (['http://127.0.0.1:20128', 'http://localhost:20129', 'https://localhost:20128', 'http://sub.localhost:20128'] as $origin) {
            $c->allowedOrigins = 'http://localhost:20128,' . $origin;
            $c->privateOrigins = $origin;
            $this->fails('endpoint_not_allowed', static fn () => Profile::fromConfig($c));
        }
    }

    public function testLocalhostLoopbackIsBlockedForPublicOnlyOrigin(): void
    {
        $c = $this->config();
        $c->baseUrl = 'https://localhost:20128/v1';
        $c->allowedOrigins = 'https://localhost:20128,https://gateway.example.test';
        foreach (['', 'https://gateway.example.test'] as $privateOrigins) {
            $c->privateOrigins = $privateOrigins;
            $this->fails('address_not_allowed', new EndpointPolicy(Profile::fromConfig($c)));
        }
    }

    public function testStandaloneHelperResolvesExactLocalhostWithDnsDisabled(): void
    {
        $process = null;
        $code = '$argv[2]="localhost"; array_shift($argv); $argc=count($argv); require $argv[0];';
        $resolver = $this->boundedFixture($code, $process, null, true);
        self::assertSame(['127.0.0.1'], $resolver('gateway.example.test'));
        self::assertFalse(is_resource($process));
    }

    public function testBoundedResolverUsesShellFreeSecretFreeChildAndReapsIt(): void
    {
        $process = null;
        $resolver = $this->boundedFixture('echo json_encode(["8.8.8.8", "2606:4700:4700::1111"]);', $process,
            static function ($command, $descriptors, $cwd, $env, $options): void {
                self::assertIsArray($command);
                self::assertSame(realpath(PHP_BINARY), $command[0]);
                self::assertSame('gateway.example.test', $command[count($command) - 1]);
                self::assertSame('resolve-endpoint.php', basename($command[count($command) - 2]));
                self::assertContains('-n', $command);
                self::assertSame(['bypass_shell' => true], $options);
                self::assertSame('file', $descriptors[1][0]);
                self::assertSame('Ai', basename($cwd));
                self::assertSame([], array_diff(array_keys($env), PHP_OS_FAMILY === 'Windows' ? ['SystemRoot'] : []));
            });
        self::assertSame(['8.8.8.8', '2606:4700:4700::1111'], $resolver('gateway.example.test'));
        self::assertFalse(is_resource($process), 'Exited child must be reaped.');
    }

    public function testChildCannotInheritProcessSecretsOrPhpIniOverrides(): void
    {
        $saved = getenv('HUMUS_AI_RESOLVER_TEST_SECRET');
        putenv('HUMUS_AI_RESOLVER_TEST_SECRET=synthetic-secret');
        try {
            $resolver = $this->boundedFixture('if (getenv("HUMUS_AI_RESOLVER_TEST_SECRET") !== false || getenv("PHPRC") !== false || getenv("HTTP_PROXY") !== false) { exit(7); } echo "[\\"8.8.8.8\\"]";');
            self::assertSame(['8.8.8.8'], $resolver('gateway.example.test'));
        } finally {
            putenv($saved === false ? 'HUMUS_AI_RESOLVER_TEST_SECRET' : 'HUMUS_AI_RESOLVER_TEST_SECRET=' . $saved);
        }
    }

    public static function invalidChildResponses(): iterable
    {
        foreach (['{}', '[]', 'null', '{"0":"8.8.8.8"}', 'raw secret error', '["invalid"]', '["8.8.8.8",false]',
            json_encode(array_fill(0, 65, '8.8.8.8')), str_repeat('x', 8193)] as $body) {
            yield ['echo ' . var_export($body, true) . ';'];
        }
        yield ['echo "[\\"8.8.8.8\\"]"; exit(7);'];
    }

    #[DataProvider('invalidChildResponses')]
    public function testMalformedOversizedOrFailedChildIsRejectedAndReaped(string $code): void
    {
        $process = null;
        $resolver = $this->boundedFixture($code, $process);
        $this->fails('dns_failed', static fn () => $resolver('gateway.example.test'));
        self::assertFalse(is_resource($process));
    }

    public function testHungResolverIsKilledInsideBudgetWithoutWaitingForChildSleep(): void
    {
        $process = null;
        $resolver = $this->boundedFixture('usleep(5000000); echo "[\\"8.8.8.8\\"]";', $process, null, false, 1);
        $start = hrtime(true);
        $this->fails('dns_failed', static fn () => $resolver('gateway.example.test'));
        self::assertLessThan(2.0, (hrtime(true) - $start) / 1000000000);
        self::assertFalse(is_resource($process), 'Timed-out child must be killed and reaped.');
    }

    public function testOversizedLiveChildIsKilledBeforeItsSleepCompletes(): void
    {
        $process = null;
        $resolver = $this->boundedFixture('echo str_repeat("x",8193); usleep(5000000);', $process, null, false, 1);
        $start = hrtime(true);
        $this->fails('dns_failed', static fn () => $resolver('gateway.example.test'));
        self::assertLessThan(2.0, (hrtime(true) - $start) / 1000000000);
        self::assertFalse(is_resource($process));
    }

    public function testProcessCreationFailureAndInvalidHostsFailWithoutDns(): void
    {
        $c = $this->config();
        $c->resolverPhpBinary = PHP_BINARY;
        $p = Profile::fromConfig($c);
        $this->fails('dns_failed', static fn () => (new BoundedResolver($p, static fn () => false))('gateway.example.test'));
        $this->fails('dns_failed', static fn () => (new BoundedResolver($p, static function (): never {
            throw new RuntimeException('raw process secret');
        }))('gateway.example.test'));
        foreach (['', '-argument', 'host.', 'bad..host', 'https://host', 'host;command', 'host name', '127.1', '2130706433', '::1', str_repeat('x', 64) . '.test'] as $host) {
            self::assertFalse(BoundedResolver::validHost($host));
            $resolver = new BoundedResolver($p, static function (): never {
                self::fail('Invalid host must never start a process.');
            });
            $this->fails('dns_failed', static fn () => $resolver($host));
        }
    }

    public function testStandaloneHelperUsesAllDnsRecordsWithoutFrameworkOrNetwork(): void
    {
        $process = null;
        $code = 'function dns_get_record($host, $type) { if ($host !== "gateway.example.test" || $type !== (DNS_A | DNS_AAAA)) { exit(7); } return [["type"=>"A","ip"=>"8.8.8.8"],["type"=>"AAAA","ipv6"=>"2606:4700:4700::1111"]]; } array_shift($argv); $argc=count($argv); require $argv[0];';
        $resolver = $this->boundedFixture($code, $process, null, true);
        self::assertSame(['8.8.8.8', '2606:4700:4700::1111'], $resolver('gateway.example.test'));
        self::assertFalse(is_resource($process));
        $code = 'function dns_get_record($host, $type) { return [["type"=>"A","ip"=>"8.8.8.8"]]; } $argv[2]="host;command"; array_shift($argv); $argc=count($argv); require $argv[0];';
        $resolver = $this->boundedFixture($code, $process, null, true);
        $this->fails('dns_failed', static fn () => $resolver('gateway.example.test'));
        self::assertFalse(is_resource($process));
    }

    public function testStandaloneHelperFailsClosedWithoutLeakingDnsErrors(): void
    {
        foreach (['return false;', 'return [];', 'return [["type"=>"A","ip"=>"invalid"]];',
            'return array_fill(0,65,["type"=>"A","ip"=>"8.8.8.8"]);',
            'throw new RuntimeException("raw resolver secret");'] as $body) {
            $process = null;
            $code = 'function dns_get_record($host,$type) { ' . $body . ' } array_shift($argv); $argc=count($argv); require $argv[0];';
            $resolver = $this->boundedFixture($code, $process, null, true);
            $this->fails('dns_failed', static fn () => $resolver('gateway.example.test'));
            self::assertFalse(is_resource($process));
        }
    }

    public function testChildStderrIsDiscardedRatherThanReturnedOrBuffered(): void
    {
        $resolver = $this->boundedFixture('fwrite(STDERR,str_repeat("raw secret",10000)); echo "[\\"8.8.8.8\\"]";');
        self::assertSame(['8.8.8.8'], $resolver('gateway.example.test'));
    }

    public function testStandaloneHelperHasNoBootstrapOrDotenvDependencies(): void
    {
        $helper = dirname((new ReflectionClass(BoundedResolver::class))->getFileName()) . '/resolve-endpoint.php';
        $source = file_get_contents($helper);
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                self::assertNotContains($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE]);
            }
        }
        self::assertStringContainsString('DNS_A | DNS_AAAA', $source);
        self::assertStringContainsString('strlen($json) > 8192', $source);
    }

    public function testChildAnswersStillUndergoAllAnswerEndpointPolicy(): void
    {
        $resolver = $this->boundedFixture('echo "[\\"8.8.8.8\\",\\"::1\\"]";');
        $policy = new EndpointPolicy(Profile::fromConfig($this->config()), $resolver);
        $this->fails('address_not_allowed', $policy);
    }

    private function fails(string $reason, callable $action, int $status = 503): void
    {
        try {
            $action();
            self::fail('Expected ' . $reason);
        } catch (AiFailure $error) {
            self::assertSame($reason, $error->reason);
            self::assertSame($reason, $error->getMessage());
            self::assertSame($status, $error->getCode());
            self::assertNull($error->getPrevious());
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiFailure;
use App\Services\Ai\Profile;
use App\Services\Ai\SettingsStore;
use Config\Ai;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/** Unsaved discovery must neither require a completion model nor write provider settings. */
final class AiModelConnectionTest extends TestCase
{
    private const SECRET = 'synthetic-discovery-saved-secret';
    private string $directory;
    private SettingsStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/humus-model-connection-' . bin2hex(random_bytes(12));
        $defaults = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        $this->store = new SettingsStore($this->directory, $defaults);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
            rmdir($this->directory);
        }
    }

    private function input(array $changes = []): array
    {
        return array_replace(['version' => '', 'base_url' => 'https://gateway.example.test/team/v1',
            'api_key' => self::SECRET, 'auth_mode' => 'bearer', 'remove_api_key' => '0', 'allow_private_network' => '0'], $changes);
    }

    private function saved(): array
    {
        return $this->store->save(17, 23, $this->input() + ['model' => 'org/saved-model']);
    }

    private function failure(string $reason, int $status, callable $call): void
    {
        try { $call(); self::fail('Expected ' . $reason); }
        catch (AiFailure $error) {
            self::assertSame($reason, $error->reason);
            self::assertSame($status, $error->getCode());
        }
    }

    private function assertUnapproved(Profile $profile): void
    {
        self::assertFalse($profile->config->enabled);
        self::assertFalse($profile->config->governanceApproved);
        self::assertFalse($profile->config->programLibraryApproved);
        self::assertSame('', $profile->config->approvedRevision);
        self::assertSame('', $profile->config->compatibilityApprovedRevision);
        $profile->assertUnchanged();
    }

    public function testInitialDiscoveryUsesUnsavedConnectionWithoutModelOrPersistence(): void
    {
        $before = $this->store->summary(17);
        $profile = $this->store->discoveryProfile(17, $this->input());
        self::assertSame($this->input()['base_url'], $profile->config->baseUrl);
        self::assertSame(self::SECRET, $profile->config->apiKey);
        self::assertSame('', $profile->config->model);
        self::assertSame('https://gateway.example.test', $profile->config->allowedOrigins);
        $this->assertUnapproved($profile);
        self::assertSame($before, $this->store->summary(17));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testCanonicalFullBaseAndSameAuthReuseKeyWithoutChangingTestedSettings(): void
    {
        $saved = $this->saved();
        $revision = $this->store->testingProfile(17, $saved['version'])->revision();
        $this->store->markTested(17, 23, $saved['version'], $revision);
        $this->store->enable(17, 23, $saved['version']);
        $before = file_get_contents($this->directory . '/provider.enc');
        $summary = $this->store->summary(17);
        foreach (['https://gateway.example.test/team/v1', 'https://GATEWAY.example.test:443/team/v1/'] as $base) {
            $profile = $this->store->discoveryProfile(17, $this->input(['version' => $saved['version'], 'base_url' => $base, 'api_key' => '']));
            self::assertSame(self::SECRET, $profile->config->apiKey);
            $this->assertUnapproved($profile);
        }
        self::assertTrue($summary['enabled']);
        self::assertTrue($summary['connection_tested']);
        self::assertSame($summary, $this->store->summary(17));
        self::assertSame($before, file_get_contents($this->directory . '/provider.enc'));
    }

    public static function changedConnections(): iterable
    {
        yield 'host' => [['base_url' => 'https://other.example.test/team/v1']];
        yield 'path on same origin' => [['base_url' => 'https://gateway.example.test/other/v1']];
        yield 'path case' => [['base_url' => 'https://gateway.example.test/Team/v1']];
        yield 'port' => [['base_url' => 'https://gateway.example.test:8443/team/v1']];
        yield 'scheme' => [['base_url' => 'http://gateway.example.test/team/v1', 'allow_private_network' => '1']];
        yield 'authentication mode' => [['auth_mode' => 'api_key_header']];
    }

    #[DataProvider('changedConnections')]
    public function testBlankKeyCannotFollowEndpointOrAuthenticationChange(array $changes): void
    {
        $saved = $this->saved();
        $before = file_get_contents($this->directory . '/provider.enc');
        $input = $this->input(array_replace(['version' => $saved['version'], 'api_key' => ''], $changes));
        $this->failure('discovery_key_required', 422, fn () => $this->store->discoveryProfile(17, $input));
        self::assertSame($before, file_get_contents($this->directory . '/provider.enc'));
        $input['api_key'] = 'synthetic-new-endpoint-key';
        self::assertSame($input['api_key'], $this->store->discoveryProfile(17, $input)->config->apiKey);
        self::assertSame(self::SECRET, $this->store->configuration()->apiKey);
    }

    public function testNoAuthAndExplicitRemovalNeverReuseSavedKey(): void
    {
        $saved = $this->saved();
        $input = $this->input(['version' => $saved['version'], 'base_url' => 'http://localhost:8080/v1',
            'allow_private_network' => 1, 'api_key' => '', 'auth_mode' => 'none', 'remove_api_key' => 1]);
        $profile = $this->store->discoveryProfile(17, $input);
        self::assertSame('', $profile->config->apiKey);
        self::assertTrue($profile->config->allowLocalHttp);
        self::assertTrue($profile->config->allowPrivateHttpDispatch);
        self::assertSame('http://localhost:8080', $profile->config->privateOrigins);
        $this->assertUnapproved($profile);
        $input['auth_mode'] = 'bearer';
        $this->failure('discovery_key_required', 422, fn () => $this->store->discoveryProfile(17, $input));
        $input['api_key'] = 'synthetic-conflicting-key';
        $this->failure('invalid_settings', 422, fn () => $this->store->discoveryProfile(17, $input));
        self::assertSame(self::SECRET, $this->store->configuration()->apiKey);
    }

    public static function invalidInputs(): iterable
    {
        foreach (['allow_private_network', 'remove_api_key'] as $field) {
            foreach ([true, false, 'true', 'on', '01', 2, [], null] as $i => $value) {
                yield "$field/$i" => [[$field => $value], 'invalid_settings', 422];
            }
        }
        foreach (['model', 'enabled', 'tenant_id', 'allowed_origins', 'approved_revision', 'api_key_header', 'messages'] as $field) {
            yield $field => [[$field => 'forged'], 'invalid_settings', 422];
        }
        yield 'array base' => [['base_url' => []], 'invalid_settings', 422];
        yield 'array key' => [['api_key' => []], 'invalid_settings', 422];
        yield 'array mode' => [['auth_mode' => []], 'invalid_settings', 422];
        yield 'missing key' => [['api_key' => ''], 'discovery_key_required', 422];
        yield 'http without opt in' => [['base_url' => 'http://localhost:8080/v1'], 'https_required', 422];
        foreach ([null, [], 0, false, 'stale'] as $i => $value) {
            yield "version/$i" => [['version' => $value], 'settings_conflict', 409];
        }
    }

    #[DataProvider('invalidInputs')]
    public function testStrictDiscoveryContractDoesNotCreateSettings(array $changes, string $reason, int $status): void
    {
        $this->failure($reason, $status, fn () => $this->store->discoveryProfile(17, $this->input($changes)));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testSavedVersionAndTenantAreRequiredEvenWithNewCredentials(): void
    {
        $saved = $this->saved();
        $before = file_get_contents($this->directory . '/provider.enc');
        foreach ([[], ['version' => ''], ['version' => str_repeat('a', 32)], ['version' => []]] as $changes) {
            $this->failure('settings_conflict', 409, fn () => $this->store->discoveryProfile(17, $this->input($changes)));
        }
        foreach ([0, -1, 18] as $tenant) {
            $this->failure('access_denied', 403, fn () => $this->store->discoveryProfile($tenant, $this->input(['version' => $saved['version']])));
        }
        self::assertSame($before, file_get_contents($this->directory . '/provider.enc'));
    }
}

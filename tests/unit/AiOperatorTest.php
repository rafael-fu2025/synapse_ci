<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Commands\AiProbe;
use App\Commands\AiStatus;
use App\Commands\DispatchAiInsights;
use App\Commands\PurgeAiInsights;
use App\Controllers\AiConfigurationController;
use App\Services\Ai\AiAccess;
use App\Services\Ai\AiFailure;
use App\Services\Ai\ChatClient;
use App\Services\Ai\Profile;
use App\Services\Ai\SettingsStore;
use App\Services\Ai\SyntheticProbe;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\Commands;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Ai;
use Config\Services;
use org\bovigo\vfs\vfsStream;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/** Synthetic fixtures and an in-memory filesystem only: no operational SQL, DNS, sockets or cache files. */
final class AiOperatorTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    private array $savedOptions;
    private string $directory;
    private int $now = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        $options = new ReflectionProperty(CLI::class, 'options');
        $this->savedOptions = $options->getValue();
        $options->setValue(null, []);
        $this->directory = vfsStream::setup('ai-operator', 0700)->url();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(CLI::class, 'options'))->setValue(null, $this->savedOptions);
        parent::tearDown();
    }

    private function config(): Ai
    {
        $config = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        $config->baseUrl = 'https://gateway.example.test/private/v1';
        $config->allowedOrigins = 'https://gateway.example.test';
        $config->model = 'synthetic-model';
        $config->apiKey = 'SYNTHETIC-SECRET';
        $config->approvedTenants = '7,8';
        return $config;
    }

    private function probe(?callable $callback = null, ?Profile $profile = null): SyntheticProbe
    {
        return new SyntheticProbe($profile ?? Profile::fromConfig($this->config()),
            $callback ?? static fn (): array => ['success' => true, 'verified' => true, 'raw' => 'SYNTHETIC-SECRET'],
            $this->directory, fn (): int => $this->now);
    }

    private function assertFailure(string $reason, int $status, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected a controlled AI failure.');
        } catch (AiFailure $error) {
            self::assertSame($reason, $error->reason);
            self::assertSame($status, $error->getCode());
            self::assertSame($reason, $error->getMessage());
        }
    }

    public function testProbeUsesFixedClientFixtureAndDoesNotApproveOrMutateProfile(): void
    {
        $profile = Profile::fromConfig($this->config());
        $before = get_object_vars($profile->config);
        $probe = $this->probe(static function (Profile $profile): array {
            $client = new ChatClient($profile, static function ($p, $endpoint, $headers, $body, $receive): int {
                $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
                self::assertSame('Return {"ok":true}. No personal or clinical data is present.', $payload['messages'][1]['content']);
                self::assertCount(2, $payload['messages']);
                $receive('{"choices":[{"finish_reason":"stop","message":{"role":"assistant","content":"{\\"ok\\":true}"}}]}');
                return 200;
            }, static fn (): array => ['8.8.8.8']);
            return $client->probe();
        }, $profile);
        self::assertSame(['success' => true, 'verified' => true, 'revision' => $profile->revision()], $probe->execute(7));
        self::assertSame($before, get_object_vars($profile->config));
        self::assertFalse($profile->publicStatus()['revisionApproved']);
        self::assertSame(['timestamp' => 1000, 'count' => 1], json_decode(file_get_contents($this->directory . '/tenant-7.json'), true));
        self::assertSame(0600, fileperms($this->directory . '/tenant-7.json') & 0777);
    }

    public function testProbeRequiresExplicitApprovedTenantBeforeCallbackOrFiles(): void
    {
        $calls = 0;
        $probe = $this->probe(static function () use (&$calls): array { ++$calls; return []; });
        foreach ([0, -1, 1, 9] as $tenant) {
            $this->assertFailure('tenant_not_approved', 403, static fn () => $probe->execute($tenant));
        }
        self::assertSame(0, $calls);
        self::assertSame([], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }

    public function testRateLimitIsSharedAcrossInstancesAndIndependentPerTenant(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            self::assertTrue($this->probe()->execute(7)['success']);
        }
        $this->assertFailure('daily_budget_exceeded', 429, fn () => $this->probe()->execute(7));
        self::assertTrue($this->probe()->execute(8)['success']);
        $this->now = 900;
        $this->assertFailure('daily_budget_exceeded', 429, fn () => $this->probe()->execute(7));
        $this->now = 1059;
        $this->assertFailure('daily_budget_exceeded', 429, fn () => $this->probe()->execute(7));
        $this->now = 1060;
        self::assertTrue($this->probe()->execute(7)['success']);
    }

    public function testLockSpansCallbackAndReleasesAfterFailureWithAttemptCharged(): void
    {
        $probe = $this->probe(function (): array {
            $this->assertFailure('worker_busy', 409, fn () => $this->probe()->execute(7));
            throw new RuntimeException('SYNTHETIC-SECRET https://private.example/path');
        });
        $this->assertFailure('probe_failed', 503, static fn () => $probe->execute(7));
        self::assertTrue($this->probe()->execute(7)['success']);
        self::assertSame(2, json_decode(file_get_contents($this->directory . '/tenant-7.json'), true)['count']);
    }

    public function testCorruptCounterFailsClosedAndInvalidResponseIsRedacted(): void
    {
        foreach (['{broken', '{"timestamp":1000,"count":0}' . str_repeat(' ', 1100),
            '{"timestamp":1000,"count":11}', '{"timestamp":"1000","count":0}'] as $raw) {
            file_put_contents($this->directory . '/tenant-7.json', $raw);
            $this->assertFailure('store_unavailable', 503, fn () => $this->probe()->execute(7));
        }
        $this->assertFailure('probe_failed', 503, fn () => $this->probe(static fn (): array => ['success' => true])->execute(8));
        self::assertSame(1, json_decode(file_get_contents($this->directory . '/tenant-8.json'), true)['count']);
    }

    public function testProbeRequiresConfiguredAllowlistedEndpointWithoutDispatchApprovals(): void
    {
        $calls = 0;
        $callback = static function () use (&$calls): array { ++$calls; return ['success' => true, 'verified' => true]; };
        $config = $this->config();
        $config->allowedOrigins = '';
        $this->assertFailure('endpoint_not_allowed', 503, static fn () => Profile::fromConfig($config));
        $config = $this->config();
        $config->model = '';
        $this->assertFailure('invalid_config', 503, fn () => $this->probe($callback, Profile::fromConfig($config))->execute(7));
        self::assertSame(0, $calls);
        self::assertSame([], array_values(array_diff(scandir($this->directory), ['.', '..'])));
        $profile = Profile::fromConfig($this->config());
        self::assertFalse($profile->config->enabled);
        self::assertFalse($profile->config->governanceApproved);
        self::assertSame('', $profile->config->approvedRevision);
        self::assertTrue($this->probe($callback, $profile)->execute(7)['success']);
        self::assertSame(1, $calls);
    }

    public function testProbeDetectsProfileMutationAndAlwaysUnlocks(): void
    {
        $profile = Profile::fromConfig($this->config());
        $probe = $this->probe(static function (Profile $profile): array {
            $profile->config->model = 'changed-model';
            return ['success' => true, 'verified' => true];
        }, $profile);
        $this->assertFailure('invalid_config', 503, static fn () => $probe->execute(7));
        self::assertTrue($this->probe()->execute(7)['success']);
    }

    private function command(string $kind)
    {
        $logger = new NullLogger();
        $commands = new Commands();
        $command = match ($kind) {
            'status' => new class($logger, $commands) extends AiStatus {
                public Profile $fixture;
                public int $calls = 0;
                public bool $fail = false;
                protected function profile(): Profile { ++$this->calls; if ($this->fail) throw new RuntimeException('SYNTHETIC-SECRET'); return $this->fixture; }
            },
            'probe' => new class($logger, $commands) extends AiProbe {
                public array $calls = [];
                public bool $fail = false;
                protected function probe(int $tenantId): array { $this->calls[] = $tenantId; if ($this->fail) throw new RuntimeException('SYNTHETIC-SECRET'); return ['success' => true, 'verified' => true, 'revision' => str_repeat('a', 64)]; }
            },
            'dispatch' => new class($logger, $commands) extends DispatchAiInsights {
                public array $calls = [];
                public bool $fail = false;
                public array $result = ['processed' => 2, 'failed' => 0, 'outcome_unknown' => 0];
                protected function dispatch(int $tenantId): array { $this->calls[] = $tenantId; if ($this->fail) throw new RuntimeException('SYNTHETIC-SECRET'); return $this->result; }
            },
            'purge' => new class($logger, $commands) extends PurgeAiInsights {
                public array $calls = [];
                public bool $fail = false;
                protected function purge(int $tenantId): array { $this->calls[] = $tenantId; if ($this->fail) throw new RuntimeException('SYNTHETIC-SECRET'); return ['ai_snapshots' => 3, 'ai_insights' => 2, 'raw' => 'SYNTHETIC-SECRET']; }
            },
        };
        if ($kind === 'status') $command->fixture = Profile::fromConfig($this->config());
        return $command;
    }

    private function runCommand($command, array $params = []): array
    {
        $this->resetStreamFilterBuffer();
        $exit = $command->run($params);
        return [$exit, trim($this->getStreamFilterBuffer())];
    }

    public function testStatusIsSafeConfigOnlyAndRejectsAllOptions(): void
    {
        $command = $this->command('status');
        [$exit, $output] = $this->runCommand($command);
        self::assertSame(EXIT_SUCCESS, $exit);
        self::assertSame('enabled=0 configured=1 governanceApproved=0 revisionApproved=0 compatibilityVerified=0 revision=' . $command->fixture->revision(), $output);
        self::assertSame([EXIT_ERROR, 'error=invalid_options'], $this->runCommand($command, ['tenant' => '7']));
        self::assertSame(1, $command->calls);
        $command->fail = true;
        self::assertSame([EXIT_ERROR, 'error=status_unavailable'], $this->runCommand($command));
    }

    public function testTenantCommandsRejectMissingMalformedUnknownAndPositionalOptions(): void
    {
        foreach (['probe', 'dispatch', 'purge'] as $kind) {
            $command = $this->command($kind);
            foreach ([[], ['tenant' => null], ['tenant' => true], ['tenant' => []], ['tenant' => '0'], ['tenant' => '-1'],
                ['tenant' => '01'], ['tenant' => '+7'], ['tenant' => ' 7'], ['tenant' => '7.0'], ['tenant' => '1e2'],
                ['tenant' => str_repeat('9', 30)], ['tenant' => '7', 'url' => 'https://evil.example'], ['7']] as $params) {
                [$exit, $output] = $this->runCommand($command, $params);
                self::assertSame(EXIT_ERROR, $exit, $kind);
                self::assertStringNotContainsString('https://', $output);
            }
            self::assertSame([], $command->calls);
        }
    }

    public function testTenantCommandsAcceptNamedCliOptionsAndPrintOnlySafeResults(): void
    {
        (new ReflectionProperty(CLI::class, 'options'))->setValue(null, ['tenant' => '7']);
        $probe = $this->command('probe');
        self::assertSame([EXIT_SUCCESS, 'success=1 verified=1 revision=' . str_repeat('a', 64)], $this->runCommand($probe));
        $dispatch = $this->command('dispatch');
        self::assertSame([EXIT_SUCCESS, 'processed=2 failed=0 outcome_unknown=0'], $this->runCommand($dispatch));
        $purge = $this->command('purge');
        self::assertSame([EXIT_SUCCESS, 'ai_snapshots=3 ai_insights=2 failed=0'], $this->runCommand($purge));
        foreach ([$probe, $dispatch, $purge] as $command) {
            self::assertSame([7], $command->calls);
            self::assertSame(EXIT_SUCCESS, $this->runCommand($command, array_merge([], CLI::getOptions()))[0]);
            self::assertSame([7, 7], $command->calls);
            self::assertSame(EXIT_ERROR, $this->runCommand($command, ['tenant' => '8'])[0]);
            self::assertSame(EXIT_ERROR, $this->runCommand($command, ['tenant' => 7])[0]);
            self::assertSame([7, 7], $command->calls);
            $command->fail = true;
            [$exit, $output] = $this->runCommand($command);
            self::assertSame(EXIT_ERROR, $exit);
            self::assertStringNotContainsString('SYNTHETIC-SECRET', $output);
        }
        $dispatch->fail = false;
        $dispatch->result = ['processed' => 0, 'failed' => 0, 'outcome_unknown' => 1];
        self::assertSame([EXIT_ERROR, 'processed=0 failed=0 outcome_unknown=1'], $this->runCommand($dispatch));
    }

    private function controller(string $method = 'POST', array $post = [])
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        $request->removeHeader('Content-Type');
        $request->setGlobal('get', []);
        $request->setGlobal('post', [csrf_token() => csrf_hash()] + $post);
        Services::injectMock('request', $request);
        $response = new Response(config('App'));
        Services::injectMock('response', $response);
        $controller = new class extends AiConfigurationController {
            public Ai $config;
            public SettingsStore $fixtureSettings;
            public array $events = [];
            public ?array $identity = ['id' => 12, 'tenant_id' => 7, 'logged_in' => true, 'role' => 'superadmin', 'auth_version' => 1,
                'permissions' => ['ai.configuration.read', 'ai.configuration.test']];
            public ?array $after = null;
            public bool $revoke = false;
            public bool $change = false;
            public bool $failProbe = false;
            protected function actor(string $permission): array { $this->events[] = $permission; return AiAccess::assertActor($this->identity, 7, $permission); }
            protected function freshIdentity(int $id, int $tenant): ?array { $this->events[] = 'fresh'; return $this->revoke ? null : ($this->after ?? $this->identity); }
            protected function profile(): Profile { $this->events[] = 'profile'; return Profile::fromConfig($this->config); }
            protected function settings(): SettingsStore { return $this->fixtureSettings; }
            protected function accessSummary(array $actor): array { return ['ready' => false, 'grants' => []]; }
            protected function releaseSession(): void { $this->events[] = 'release'; }
            protected function probe(Profile $profile, int $tenantId): array {
                $this->events[] = 'probe';
                \PHPUnit\Framework\Assert::assertSame(7, $tenantId);
                if ($this->change) $this->config->model = 'changed-model';
                if ($this->failProbe) throw new RuntimeException('SYNTHETIC-SECRET https://private.example');
                return ['success' => true, 'verified' => true, 'revision' => $profile->revision(), 'raw' => 'SYNTHETIC-SECRET'];
            }
            protected function renderStatus(array $status): string { return json_encode($status, JSON_THROW_ON_ERROR); }
        };
        $controller->config = $this->config();
        $controller->fixtureSettings = new SettingsStore($this->directory . '/settings', $controller->config);
        $controller->initController($request, $response, new NullLogger());
        return $controller;
    }

    public function testControllerReadOnlyStatusIsSafeAndNeverProbes(): void
    {
        $controller = $this->controller('GET');
        $response = $controller->index();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['ai.configuration.read', 'profile'], $controller->events);
        self::assertStringNotContainsString('SYNTHETIC-SECRET', $response->getBody());
        $status = json_decode($response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($controller->config->baseUrl, $status['settings']['base_url']);
        self::assertTrue($status['settings']['has_api_key']);
        self::assertFalse($status['settings']['saved']);
        self::assertArrayNotHasKey('api_key', $status['settings']);
        self::assertFalse(is_dir($this->directory . '/settings'));
        self::assertSame(405, $this->controller('POST')->index()->getStatusCode());
        self::assertSame(405, $this->controller('GET')->test()->getStatusCode());
    }

    public function testControllerReleasesSessionBeforeProbeAndRechecksIdentityAndRevision(): void
    {
        $controller = $this->controller();
        $before = get_object_vars($controller->config);
        $response = $controller->test();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ai.configuration.test', 'profile', 'release', 'probe', 'fresh', 'profile'], $controller->events);
        self::assertTrue(json_decode($response->getBody(), true)['verified']);
        self::assertStringNotContainsString('SYNTHETIC-SECRET', $response->getBody());
        self::assertSame($before, get_object_vars($controller->config));
        $controller = $this->controller();
        $controller->change = true;
        self::assertSame(409, $controller->test()->getStatusCode());
    }

    public function testControllerRejectsUserPayloadAndMissingCsrfBeforeProbe(): void
    {
        foreach (['url', 'model', 'messages', 'tenant'] as $field) {
            $controller = $this->controller('POST', [$field => 'untrusted']);
            self::assertSame(400, $controller->test()->getStatusCode());
            self::assertSame(['ai.configuration.test'], $controller->events);
        }
        $controller = $this->controller();
        service('request')->setHeader('Content-Type', 'application/json');
        self::assertSame(400, $controller->test()->getStatusCode());
        self::assertSame(['ai.configuration.test'], $controller->events);
        $controller = $this->controller();
        service('request')->setGlobal('post', []);
        self::assertSame(403, $controller->test()->getStatusCode());
        self::assertSame(['ai.configuration.test'], $controller->events);
    }

    public function testControllerRequiresExplicitSuperadminPermissionsAndHonorsRevocation(): void
    {
        $controller = $this->controller();
        $controller->identity['role'] = 'clinic_admin';
        self::assertSame(403, $controller->test()->getStatusCode());
        self::assertSame(['ai.configuration.test'], $controller->events);
        $controller = $this->controller('GET');
        $controller->identity['permissions'] = [];
        self::assertSame(403, $controller->index()->getStatusCode());
        $controller = $this->controller();
        $controller->revoke = true;
        $response = $controller->test();
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('authentication_required', $response->getHeaderLine('X-Synapse-Auth'));
        self::assertSame(['ai.configuration.test', 'profile', 'release', 'probe', 'fresh'], $controller->events);
        $controller = $this->controller();
        $controller->after = $controller->identity;
        $controller->after['auth_version'] = 2;
        self::assertSame(401, $controller->test()->getStatusCode());
    }

    public function testControllerRedactsUnexpectedFailureAndStillRechecksAuthority(): void
    {
        $controller = $this->controller();
        $controller->failProbe = true;
        $response = $controller->test();
        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['ai.configuration.test', 'profile', 'release', 'probe', 'fresh'], $controller->events);
        self::assertStringNotContainsString('SYNTHETIC-SECRET', $response->getBody());
        self::assertStringNotContainsString('https://', $response->getBody());
    }

    public function testViewExposesSecureProviderSetupAndWorkerForwardsValidatedUnit(): void
    {
        $view = file_get_contents(APPPATH . 'Views/admin/ai.php');
        self::assertStringContainsString("extend('layouts/app')", $view);
        self::assertStringContainsString("site_url('admin/ai/test')", $view);
        self::assertStringContainsString('csrf_field()', $view);
        self::assertStringContainsString('assets/js/ai-settings.js', $view);
        self::assertStringContainsString('name="model"', $view);
        self::assertStringContainsString('name="base_url"', $view);
        self::assertStringContainsString('name="reasoning_effort"', $view);
        self::assertStringContainsString('name="max_input_tokens"', $view);
        self::assertStringContainsString('name="max_output_tokens"', $view);
        self::assertStringNotContainsString('value="<?= esc($s[\'api_key\']', $view);
        $worker = file_get_contents(APPPATH . 'Commands/DispatchAiInsights.php');
        self::assertStringContainsString('(new InsightWorker())->run($tenantId, $this->unit)', $worker);
        $purge = file_get_contents(APPPATH . 'Commands/PurgeAiInsights.php');
        self::assertStringContainsString('purgeExpired($tenantId, $this->unit)', $purge);
    }
}

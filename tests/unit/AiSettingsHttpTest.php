<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AiConfigurationController;
use App\Services\Ai\AiAccess;
use App\Services\Ai\AiFailure;
use App\Services\Ai\ChatClient;
use App\Services\Ai\DeploymentControl;
use App\Services\Ai\PayloadCipher;
use App\Services\Ai\Profile;
use App\Services\Ai\SettingsStore;
use App\Services\Ai\SyntheticProbe;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Ai;
use Config\Services;
use FilesystemIterator;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Run without dotenv:
 * php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap tests/_support/fresh-framework-bootstrap.php tests/unit/AiSettingsHttpTest.php
 * Only disposable settings/control/probe files, synthetic identities and injected DNS/HTTP are used.
 */
final class AiSettingsHttpTest extends CIUnitTestCase
{
    private const SECRET = 'synthetic-http-settings-secret';
    private string $directory;
    private SettingsStore $store;
    private DeploymentControl $control;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        $this->directory = sys_get_temp_dir() . '/humus-ai-settings-http-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->directory, 0700));
        $defaults = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        $this->store = new SettingsStore($this->directory . '/settings', $defaults);
        // Never call the production source-stamp function, which reads the deployment's .env.
        $this->control = new DeploymentControl($this->directory . '/control.json', static fn (): string => str_repeat('a', 64));
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function input(array $changes = []): array
    {
        // Exact SettingsStore::save write contract; CSRF is added only at the HTTP boundary.
        return array_replace([
            'version' => '', 'base_url' => 'https://gateway.example.test/team/v1', 'api_key' => self::SECRET,
            'remove_api_key' => '0', 'model' => 'org/synthetic-model', 'reasoning_effort' => 'high', 'thinking_mode' => 'disabled',
            'max_input_tokens' => '16384', 'max_output_tokens' => '3072', 'auth_mode' => 'bearer',
            'token_field' => 'max_completion_tokens', 'structured_output' => 'json_object', 'allow_private_network' => '0',
        ], $changes);
    }

    private function controller(string $method = 'POST', array $post = [], bool $csrf = true): AiSettingsHttpFixtureController
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $request->removeHeader(csrf_header());
        $request->setGlobal('get', []);
        $request->setGlobal('post', ($csrf ? [csrf_token() => csrf_hash()] : []) + $post);
        Services::injectMock('request', $request);
        $response = new Response(config('App'));
        Services::injectMock('response', $response);
        $controller = new AiSettingsHttpFixtureController();
        $controller->fixtureStore = $this->store;
        $controller->fixtureControl = $this->control;
        $controller->probeDirectory = $this->directory;
        $controller->initController($request, $response, new NullLogger());
        return $controller;
    }

    private function payload($response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), $response->getBody());
        self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString(self::SECRET, $response->getBody());
        return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function saved(): array
    {
        return $this->payload($this->controller('POST', $this->input())->save())['settings'];
    }

    private function tested(): array
    {
        $saved = $this->saved();
        return $this->payload($this->controller('POST', ['version' => $saved['version']])->test())['settings'];
    }

    private function record(): array
    {
        $cipher = new PayloadCipher(file_get_contents($this->directory . '/settings/settings.key'));
        return $cipher->decrypt(1, 'configuration', 'provider-settings', file_get_contents($this->directory . '/settings/provider.enc'));
    }

    public function testConfigGetAndStatusAllowRbacSuperadminWithoutAiOrClinicalPermissions(): void
    {
        $controller = $this->controller('GET');
        $data = $this->payload($controller->index());
        self::assertFalse($data['settings']['saved']);
        self::assertSame(['ai.configuration.read', 'settings', 'profile', 'render'], $controller->events);
        self::assertDirectoryDoesNotExist($this->directory . '/settings');
        self::assertFileDoesNotExist($this->directory . '/control.json');
        $controller = $this->controller('GET');
        $data = $this->payload($controller->status());
        self::assertFalse($data['settings']['saved']);
        self::assertSame(['ai.configuration.read', 'settings'], $controller->events);
        self::assertSame(['rbac.manage'], $controller->identity['permissions']);
        try {
            AiAccess::assertActor($controller->identity, 17, 'ai.insights.read');
            self::fail('Configuration access must not grant clinical analytics access.');
        } catch (AiFailure $error) {
            self::assertSame('access_denied', $error->reason);
        }
    }

    public function testSettingsNavigationAndRoutesExposeConfigurationSeparatelyFromClinicalGrants(): void
    {
        // Static wiring check only; rendering the full sidebar would boot unrelated portal/session services.
        $sidebar = file_get_contents(APPPATH . 'Views/partials/sidebar.php');
        self::assertStringContainsString("\$canAiSettings = \$canMain && \$role === 'superadmin'", $sidebar);
        self::assertStringContainsString("in_array('rbac.manage', \$currentUser['permissions'] ?? [], true) || in_array('ai.configuration.read', \$currentUser['permissions'] ?? [], true)", $sidebar);
        self::assertStringContainsString('<?php if ($canAiSettings): ?>', $sidebar);
        self::assertStringContainsString("\$sidebarLink('main', 'admin/ai', 'AI Settings'", $sidebar);
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        foreach (['admin/ai' => 'index', 'admin/ai/status' => 'status'] as $path => $action) {
            self::assertStringContainsString("\$routes->get('$path', 'AiConfigurationController::$action')", $routes);
        }
        foreach (['save', 'test', 'enable', 'disable'] as $action) {
            self::assertStringContainsString("\$routes->post('admin/ai/$action', 'AiConfigurationController::$action')", $routes);
        }
    }

    public function testSaveAcceptsExactStoreFieldsEncryptsAndReturnsOnlyRedactedSettings(): void
    {
        $controller = $this->controller('POST', $this->input());
        $data = $this->payload($controller->save());
        self::assertTrue($data['success']);
        $saved = $data['settings'];
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $saved['version']);
        self::assertTrue($saved['has_api_key']);
        self::assertTrue($saved['storage_key_ready']);
        self::assertFalse($saved['enabled']);
        self::assertFalse($saved['connection_tested']);
        self::assertArrayNotHasKey('api_key', $saved);
        self::assertArrayNotHasKey('storage_key', $saved);
        foreach (['base_url', 'model', 'reasoning_effort', 'thinking_mode', 'auth_mode', 'token_field', 'structured_output'] as $field) {
            self::assertSame($this->input()[$field], $saved[$field]);
        }
        self::assertSame(16384, $saved['max_input_tokens']);
        self::assertSame(3072, $saved['max_output_tokens']);
        self::assertFalse($saved['allow_private_network']);
        self::assertSame(self::SECRET, $this->store->configuration()->apiKey);
        self::assertSame(23, $this->record()['history'][0]['actor_id']);
        self::assertSame(17, $this->record()['tenant_id']);
        $encrypted = file_get_contents($this->directory . '/settings/provider.enc');
        self::assertStringStartsWith('v1.', $encrypted);
        self::assertStringNotContainsString(self::SECRET, $encrypted);
        self::assertStringNotContainsString($this->store->configuration()->encryptionKey, json_encode($data, JSON_THROW_ON_ERROR));
        self::assertTrue(json_decode(file_get_contents($this->directory . '/control.json'), true)['paused']);
        self::assertSame(['ai.configuration.write', 'control', 'settings'], $controller->events);
    }

    public function testThinkingModeHttpValidationAndLegacyFormOmission(): void
    {
        $input = $this->input();
        unset($input['thinking_mode']);
        $saved = $this->payload($this->controller('POST', $input)->save())['settings'];
        self::assertSame('', $saved['thinking_mode']);
        foreach (['none', 'default', 'DISABLED', ['type' => 'disabled']] as $invalid) {
            $response = $this->payload($this->controller('POST', $this->input([
                'version' => $saved['version'], 'thinking_mode' => $invalid,
            ]))->save(), is_array($invalid) ? 422 : 503);
            self::assertFalse($response['success']);
            self::assertSame('', $this->store->configuration()->thinkingMode);
        }
    }

    public function testApprovedUnitSelectionFlowsThroughTheHttpSaveContract(): void
    {
        $saved = $this->payload($this->controller('POST', $this->input([
            'unit_selection' => '1', 'allow_guidance' => '1', 'allow_bmg' => '1',
        ]))->save())['settings'];
        self::assertSame(['guidance', 'bmg'], $saved['approved_units']);
        self::assertFalse($saved['enabled']);
        self::assertFalse($saved['connection_tested']);
    }

    public function testBlankKeyRetainsSecretAndSaveInvalidatesTestAndChangesVersion(): void
    {
        $tested = $this->tested();
        $saved = $this->payload($this->controller('POST', $this->input(['version' => $tested['version'], 'api_key' => '', 'model' => 'new-model']))->save())['settings'];
        self::assertNotSame($tested['version'], $saved['version']);
        self::assertSame(self::SECRET, $this->store->configuration()->apiKey);
        self::assertTrue($saved['has_api_key']);
        self::assertFalse($saved['connection_tested']);
        self::assertFalse($saved['enabled']);
    }

    public static function csrfActions(): iterable
    {
        foreach (['save', 'test', 'enable', 'disable', 'access'] as $action) {
            foreach (['missing', 'invalid'] as $token) { yield "$action/$token" => [$action, $token]; }
        }
    }

    #[DataProvider('csrfActions')]
    public function testEveryMutationRequiresRealCsrfBeforeAnyWriteOrProbe(string $action, string $token): void
    {
        $post = $action === 'save' ? $this->input() : ['version' => str_repeat('a', 32), 'confirm_analytics' => '1'];
        if ($token === 'invalid') { $post[csrf_token()] = 'invalid-token'; }
        $controller = $this->controller('POST', $post, false);
        $data = $this->payload($controller->{$action}(), 403);
        self::assertFalse($data['success']);
        self::assertTrue($data['reload']);
        self::assertCount(1, $controller->events);
        self::assertSame([], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }

    public static function forbiddenFields(): iterable
    {
        foreach (['storage_key', 'encryptionKey', 'api_key_header', 'allowed_origins', 'approved_revision', 'tenant_id', 'messages', 'enabled'] as $field) {
            foreach (['save', 'test', 'enable', 'disable'] as $action) { yield "$action/$field" => [$action, $field]; }
        }
        foreach (['api_key', 'base_url', 'model', 'reasoning_effort', 'thinking_mode', 'max_input_tokens', 'max_output_tokens'] as $field) {
            foreach (['test', 'enable', 'disable'] as $action) { yield "$action/$field" => [$action, $field]; }
        }
    }

    #[DataProvider('forbiddenFields')]
    public function testHttpRejectsArbitraryFieldsBeforeSideEffects(string $action, string $field): void
    {
        $post = $action === 'save' ? $this->input() : ['version' => str_repeat('a', 32)];
        $controller = $this->controller('POST', $post + [$field => 'untrusted']);
        self::assertSame('invalid_request', $this->payload($controller->{$action}(), 400)['reason']);
        self::assertCount(1, $controller->events);
        self::assertFileDoesNotExist($this->directory . '/control.json');
        self::assertDirectoryDoesNotExist($this->directory . '/settings');
    }

    public function testStoreIndependentlyRejectsNonContractWriteFields(): void
    {
        foreach (['storage_key', 'apiKeyHeader', 'tenant_id', 'enabled', 'tested_revision', 'csrf_fixture'] as $field) {
            try {
                $this->store->save(17, 23, $this->input() + [$field => 'untrusted']);
                self::fail('Unexpected SettingsStore write field: ' . $field);
            } catch (AiFailure $error) {
                self::assertSame('invalid_settings', $error->reason);
                self::assertSame(422, $error->getCode());
            }
        }
        self::assertDirectoryDoesNotExist($this->directory . '/settings');
    }

    public function testTestUsesSavedProfileAndFixedHarmlessMessageThenRechecksFreshAuthority(): void
    {
        $saved = $this->saved();
        $controller = $this->controller('POST', ['version' => $saved['version']]);
        $data = $this->payload($controller->test());
        self::assertTrue($data['verified']);
        self::assertTrue($data['settings']['connection_tested']);
        self::assertSame($saved['version'], $data['settings']['version']);
        self::assertFalse($data['settings']['enabled']);
        self::assertSame(['ai.configuration.test', 'control', 'settings', 'settings', 'release', 'probe', 'fresh', 'settings'], $controller->events);
        self::assertSame([[23, 17]], $controller->freshCalls);
        self::assertCount(1, $controller->wire);
        $wire = $controller->wire[0];
        self::assertSame('https://gateway.example.test/team/v1/chat/completions', $wire['endpoint']['url']);
        self::assertSame('org/synthetic-model', $wire['body']['model']);
        self::assertSame('high', $wire['body']['reasoning_effort']);
        self::assertSame(['type' => 'disabled'], $wire['body']['thinking']);
        self::assertCount(2, $wire['body']['messages']);
        self::assertSame('Return {"ok":true}. No personal or clinical data is present.', $wire['body']['messages'][1]['content']);
        self::assertSame('connection_tested', array_slice($this->record()['history'], -1)[0]['action']);
    }

    public static function unsuccessfulTests(): iterable
    {
        yield 'provider rejects model' => ['provider', 'provider_rejected', 503];
        yield 'unverified result' => ['unverified', 'probe_failed', 503];
        yield 'unsuccessful result' => ['unsuccessful', 'probe_failed', 503];
        yield 'identity revoked during request' => ['revoked', 'authentication_required', 401];
        yield 'permissions revoked during request' => ['permissions', 'access_denied', 403];
        yield 'write authority revoked but test authority retained' => ['write', 'access_denied', 403];
        yield 'role revoked during request' => ['role', 'access_denied', 403];
        yield 'auth version changed' => ['auth_version', 'authentication_required', 401];
        yield 'different actor' => ['actor', 'authentication_required', 401];
        yield 'different tenant' => ['tenant', 'access_denied', 403];
        yield 'configuration changed during probe' => ['version', 'settings_conflict', 409];
    }

    #[DataProvider('unsuccessfulTests')]
    public function testFailedOrRacingProbeNeverMarksConfigurationTested(string $scenario, string $reason, int $status): void
    {
        $saved = $this->saved();
        $controller = $this->controller('POST', ['version' => $saved['version']]);
        $controller->duringProbe = function (AiSettingsHttpFixtureController $fixture) use ($scenario, $saved): void {
            switch ($scenario) {
                case 'provider': $fixture->providerStatus = 400; break;
                case 'unverified': $fixture->result = ['success' => true, 'verified' => false]; break;
                case 'unsuccessful': $fixture->result = ['success' => false, 'verified' => true]; break;
                case 'revoked': $fixture->identity = null; break;
                case 'permissions': $fixture->identity['permissions'] = []; break;
                case 'write': $fixture->identity['permissions'] = ['ai.configuration.test']; break;
                case 'role': $fixture->identity['role'] = 'clinic_admin'; break;
                case 'auth_version': $fixture->identity['auth_version'] = 2; break;
                case 'actor': $fixture->identity['id'] = 24; break;
                case 'tenant': $fixture->identity['tenant_id'] = 18; break;
                case 'version': $this->store->save(17, 23, $this->input(['version' => $saved['version'], 'model' => 'racing-model'])); break;
            }
        };
        $response = $controller->test();
        $data = $this->payload($response, $status);
        if ($status === 401) {
            self::assertSame($reason, $response->getHeaderLine('X-Synapse-Auth'));
        } else {
            if ($status === 403) {
                self::assertSame('access_denied', $reason);
                self::assertStringContainsString('platform administration access', $data['message']);
            } else {
                self::assertSame($reason, $data['reason']);
            }
            self::assertFalse($response->hasHeader('X-Synapse-Auth'));
            self::assertFalse($response->hasHeader('Location'));
        }
        self::assertContains('fresh', $controller->events, 'Even failed probes must recheck authority.');
        self::assertFalse($this->store->summary(17)['connection_tested']);
        self::assertFalse($this->store->summary(17)['enabled']);
        self::assertNotContains('connection_tested', array_column($this->record()['history'], 'action'));
    }

    public function testStaleVersionCannotProbeOrEnable(): void
    {
        $saved = $this->saved();
        $current = $this->store->save(17, 23, $this->input(['version' => $saved['version']]));
        foreach (['test', 'enable'] as $action) {
            $controller = $this->controller('POST', ['version' => $saved['version'], ...($action === 'enable' ? ['confirm_analytics' => '1'] : [])]);
            self::assertSame('settings_conflict', $this->payload($controller->{$action}(), 409)['reason']);
            self::assertSame([], $controller->wire);
            self::assertNotContains('probe', $controller->events);
        }
        self::assertSame($current, $this->store->summary(17));
    }

    public function testEnableRequiresConsentAndPassedTestThenDisablePreservesKey(): void
    {
        $saved = $this->saved();
        self::assertSame('privacy_confirmation_required', $this->payload($this->controller('POST', ['version' => $saved['version']])->enable(), 422)['reason']);
        self::assertSame('compatibility_required', $this->payload($this->controller('POST', ['version' => $saved['version'], 'confirm_analytics' => '1'])->enable(), 422)['reason']);
        $this->payload($this->controller('POST', ['version' => $saved['version']])->test());
        $controller = $this->controller('POST', ['version' => $saved['version'], 'confirm_analytics' => '1']);
        $controller->schemaReady = true;
        self::assertTrue($this->payload($controller->enable())['settings']['enabled']);
        self::assertContains('schema', $controller->events);
        $this->control->assertAllowed(Profile::fromConfig($this->store->configuration()), 17);
        $data = $this->payload($this->controller('POST', ['version' => $saved['version']])->disable());
        self::assertFalse($data['settings']['enabled']);
        self::assertTrue($data['settings']['has_api_key']);
        self::assertSame(self::SECRET, $this->store->configuration()->apiKey);
        self::assertTrue(json_decode(file_get_contents($this->directory . '/control.json'), true)['paused']);
    }

    public function testMissingSchemaRollsBackEnableAndPausesControlWithoutDatabaseAccess(): void
    {
        $tested = $this->tested();
        $controller = $this->controller('POST', ['version' => $tested['version'], 'confirm_analytics' => '1']);
        $controller->schemaReady = false;
        self::assertSame('store_unavailable', $this->payload($controller->enable(), 503)['reason']);
        self::assertContains('schema', $controller->events);
        self::assertFalse($this->store->summary(17)['enabled']);
        self::assertTrue($this->store->summary(17)['connection_tested']);
        self::assertTrue(json_decode(file_get_contents($this->directory . '/control.json'), true)['paused']);
    }

    public function testStatusReadsCurrentRedactedSavedVersionWithoutProbeOrMutation(): void
    {
        $old = $this->saved();
        $controller = $this->controller('GET');
        $current = $this->store->save(17, 23, $this->input(['version' => $old['version'], 'model' => 'current-model']));
        $before = file_get_contents($this->directory . '/settings/provider.enc');
        $data = $this->payload($controller->status());
        self::assertSame($current, $data['settings']);
        self::assertNotSame($old['version'], $data['settings']['version']);
        self::assertArrayNotHasKey('api_key', $data['settings']);
        self::assertArrayNotHasKey('storage_key', $data['settings']);
        self::assertSame(['ai.configuration.read', 'settings'], $controller->events);
        self::assertSame($before, file_get_contents($this->directory . '/settings/provider.enc'));
    }

    public function testOnlySuperadminWithConfigurationAuthorityCanReadOrWrite(): void
    {
        foreach (['index', 'status', 'save', 'test', 'enable', 'disable'] as $action) {
            foreach ([['role' => 'clinic_admin'], ['permissions' => []], ['permissions' => ['ai.insights.read']]] as $changes) {
                $controller = $this->controller(in_array($action, ['index', 'status'], true) ? 'GET' : 'POST', $this->input());
                $controller->identity = array_replace($controller->identity, $changes);
                self::assertSame(403, $controller->{$action}()->getStatusCode());
                self::assertCount(1, $controller->events);
            }
        }
        self::assertDirectoryDoesNotExist($this->directory . '/settings');
    }

    public function testMethodsAndNonFormOrQueryPayloadsAreRejected(): void
    {
        foreach (['index', 'status', 'save', 'test', 'enable', 'disable', 'access'] as $action) {
            $read = in_array($action, ['index', 'status'], true);
            $controller = $this->controller($read ? 'POST' : 'GET');
            $response = $controller->{$action}();
            $this->payload($response, 405);
            self::assertSame($read ? 'GET' : 'POST', $response->getHeaderLine('Allow'));
            self::assertSame([], $controller->events);
        }
        foreach (['json', 'query'] as $invalid) {
            $controller = $this->controller('POST', $this->input());
            if ($invalid === 'json') { service('request')->setHeader('Content-Type', 'application/json'); }
            else { service('request')->setGlobal('get', ['api_key' => 'untrusted']); }
            self::assertSame('invalid_request', $this->payload($controller->save(), 400)['reason']);
        }
        self::assertDirectoryDoesNotExist($this->directory . '/settings');
    }
}

/** Overrides every hook that could touch live settings, authority, schema, session or transport. */
final class AiSettingsHttpFixtureController extends AiConfigurationController
{
    public SettingsStore $fixtureStore;
    public DeploymentControl $fixtureControl;
    public string $probeDirectory;
    public array $events = [];
    public array $freshCalls = [];
    public array $wire = [];
    public bool $schemaReady = false;
    public int $providerStatus = 200;
    public ?array $result = null;
    public ?\Closure $duringProbe = null;
    public ?array $identity = ['id' => 23, 'tenant_id' => 17, 'logged_in' => true, 'role' => 'superadmin', 'auth_version' => 1, 'permissions' => ['rbac.manage']];

    protected function actor(string $permission): array
    {
        $this->events[] = $permission;
        return AiAccess::assertActor($this->identity, 17, $permission);
    }
    protected function freshIdentity(int $actorId, int $tenantId): ?array
    {
        $this->events[] = 'fresh';
        $this->freshCalls[] = [$actorId, $tenantId];
        return $this->identity;
    }
    protected function settings(): SettingsStore { $this->events[] = 'settings'; return $this->fixtureStore; }
    protected function accessSummary(array $actor): array { return ['ready' => false, 'grants' => []]; }
    protected function control(): DeploymentControl { $this->events[] = 'control'; return $this->fixtureControl; }
    protected function storageReady(): bool { $this->events[] = 'schema'; return $this->schemaReady; }
    protected function profile(): Profile { $this->events[] = 'profile'; return Profile::fromConfig($this->fixtureStore->configuration()); }
    protected function releaseSession(): void { $this->events[] = 'release'; }
    protected function renderStatus(array $status): string { $this->events[] = 'render'; return json_encode($status, JSON_THROW_ON_ERROR); }
    protected function probe(Profile $profile, int $tenantId): array
    {
        $this->events[] = 'probe';
        Assert::assertSame(17, $tenantId);
        Assert::assertSame('release', $this->events[count($this->events) - 2]);
        if ($this->duringProbe !== null) { ($this->duringProbe)($this); }
        return (new SyntheticProbe($profile, function (Profile $profile): array {
            if ($this->result !== null) { return $this->result; }
            return (new ChatClient($profile, function ($p, $endpoint, $headers, $body, $receive): int {
                $this->wire[] = ['endpoint' => $endpoint, 'body' => json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
                $receive('{"choices":[{"finish_reason":"stop","message":{"role":"assistant","content":"{\\"ok\\":true}"}}]}');
                return $this->providerStatus;
            }, static fn (): array => ['8.8.8.8']))->probe();
        }, $this->probeDirectory, static fn (): int => 1000))->execute($tenantId);
    }
}

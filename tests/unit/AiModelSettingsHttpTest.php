<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AiConfigurationController;
use App\Services\Ai\AiAccess;
use App\Services\Ai\AiFailure;
use App\Services\Ai\DeploymentControl;
use App\Services\Ai\ModelCapabilities;
use App\Services\Ai\ModelCatalogStore;
use App\Services\Ai\Profile;
use App\Services\Ai\SettingsStore;
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
use RuntimeException;
use Throwable;

/** Run with the fresh-framework-bootstrap.php bootstrap (no dotenv). No live stores or network. */
final class AiModelSettingsHttpTest extends CIUnitTestCase
{
    private const SECRET = 'synthetic-model-http-secret';
    private string $directory;
    private SettingsStore $store;
    private ModelCatalogStore $catalog;
    private int $time = 1800000000;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        $this->directory = sys_get_temp_dir() . '/humus-model-http-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->directory, 0700));
        $defaults = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        $this->store = new SettingsStore($this->directory . '/settings', $defaults);
        $this->catalog = new ModelCatalogStore($this->directory . '/catalog', fn (): int => $this->time);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function input(array $changes = []): array
    {
        return array_replace(['version' => '', 'base_url' => 'https://gateway.example.test/team/v1',
            'api_key' => self::SECRET, 'auth_mode' => 'bearer', 'remove_api_key' => '0', 'allow_private_network' => '0'], $changes);
    }

    private function saved(bool $enable = false): array
    {
        $saved = $this->store->save(17, 23, $this->input() + ['model' => 'org/saved-model']);
        if ($enable) {
            $this->store->markTested(17, 23, $saved['version'], $this->store->testingProfile(17, $saved['version'])->revision());
            $this->store->enable(17, 23, $saved['version']);
        }
        return $this->store->summary(17);
    }

    private function controller(string $method = 'POST', array $post = [], bool $csrf = true): AiModelSettingsHttpFixtureController
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
        $controller = new AiModelSettingsHttpFixtureController();
        $controller->fixtureStore = $this->store;
        $controller->fixtureCatalog = $this->catalog;
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

    private function preview(): array
    {
        return $this->payload($this->controller('POST', $this->input())->previewModels())['preview'];
    }

    private function selection(array $preview, array $changes = []): array
    {
        return array_replace(['preview_token' => $preview['preview_token'], 'model_ids' => '["org/a"]', 'import_all' => '0'], $changes);
    }

    public function testInitialPreviewUsesUnsavedConnectionAndStagesOnlyAfterFreshAuthority(): void
    {
        $controller = $this->controller('POST', $this->input());
        $before = $this->store->summary(17);
        $data = $this->payload($controller->previewModels());
        self::assertTrue($data['success']);
        self::assertSame(['org/a', 'org/b'], array_column($data['preview']['models'], 'id'));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $data['preview']['preview_token']);
        self::assertSame($this->time + 600, $data['preview']['expires_at']);
        self::assertSame($this->input()['base_url'], $data['preview']['base_url']);
        self::assertSame(['ai.configuration.write', 'csrf', 'settings', 'catalog', 'release', 'discover', 'fresh', 'settings'], $controller->events);
        self::assertSame([[23, 17]], $controller->freshCalls);
        self::assertCount(1, $controller->discovered);
        $profile = $controller->discovered[0];
        self::assertSame(self::SECRET, $profile->config->apiKey);
        self::assertSame($this->input()['base_url'], $profile->config->baseUrl);
        self::assertSame('', $profile->config->model);
        self::assertFalse($profile->config->enabled);
        self::assertFalse($profile->config->governanceApproved);
        self::assertFalse($profile->config->programLibraryApproved);
        self::assertSame('', $profile->config->approvedRevision);
        self::assertSame('', $profile->config->compatibilityApprovedRevision);
        self::assertSame([], $this->catalog->summary(17)['models']);
        self::assertSame($before, $this->store->summary(17));
        self::assertDirectoryDoesNotExist($this->directory . '/settings');
    }

    public function testPreviewAndImportPreserveEnabledTestedSettingsByteForByte(): void
    {
        $saved = $this->saved(true);
        $before = file_get_contents($this->directory . '/settings/provider.enc');
        $control = new DeploymentControl($this->directory . '/control.json', static fn (): string => str_repeat('a', 64));
        $control->resume(Profile::fromConfig($this->store->configuration()), 17);
        $controlBefore = file_get_contents($this->directory . '/control.json');
        $controller = $this->controller('POST', $this->input(['version' => $saved['version'],
            'base_url' => 'https://GATEWAY.example.test:443/team/v1/', 'api_key' => '']));
        $preview = $this->payload($controller->previewModels())['preview'];
        self::assertSame(self::SECRET, $controller->discovered[0]->config->apiKey);
        $preview['models'][0]['defaults']['max_output_tokens'] = 1;
        $result = $this->payload($this->controller('POST', $this->selection($preview))->importModels());
        self::assertSame(['org/a'], array_column($result['catalog']['models'], 'id'));
        self::assertSame(1, $result['catalog']['imported_count']);
        self::assertSame(2000, $result['catalog']['models'][0]['defaults']['max_output_tokens']);
        self::assertTrue($saved['enabled']);
        self::assertTrue($saved['connection_tested']);
        self::assertSame($saved, $this->store->summary(17));
        self::assertSame($before, file_get_contents($this->directory . '/settings/provider.enc'));
        self::assertSame($controlBefore, file_get_contents($this->directory . '/control.json'));
        self::assertSame('catalog_preview_invalid', $this->payload($this->controller('POST', $this->selection($preview))->importModels(), 409)['reason']);
    }

    public static function changedConnections(): iterable
    {
        yield 'same origin different base path' => [['base_url' => 'https://gateway.example.test/other/v1']];
        yield 'different host' => [['base_url' => 'https://other.example.test/team/v1']];
        yield 'different port' => [['base_url' => 'https://gateway.example.test:8443/team/v1']];
        yield 'different auth' => [['auth_mode' => 'api_key_header']];
    }

    #[DataProvider('changedConnections')]
    public function testBlankKeyOnChangedConnectionNeverMakesOutboundCall(array $changes): void
    {
        $saved = $this->saved();
        $before = file_get_contents($this->directory . '/settings/provider.enc');
        $controller = $this->controller('POST', $this->input(array_replace(['version' => $saved['version'], 'api_key' => ''], $changes)));
        $data = $this->payload($controller->previewModels(), 422);
        self::assertSame([], $controller->discovered);
        self::assertNotContains('catalog', $controller->events);
        self::assertSame($before, file_get_contents($this->directory . '/settings/provider.enc'));
        self::assertSame('discovery_key_required', $data['reason']);
    }

    public function testNoAuthInitialPreviewNeedsNeitherSavedModelNorKey(): void
    {
        $controller = $this->controller('POST', $this->input(['base_url' => 'http://localhost:8080/v1',
            'auth_mode' => 'none', 'api_key' => '', 'remove_api_key' => '1', 'allow_private_network' => '1']));
        $preview = $this->payload($controller->previewModels())['preview'];
        self::assertSame('http://localhost:8080/v1', $preview['base_url']);
        self::assertSame('', $controller->discovered[0]->config->apiKey);
        self::assertTrue($controller->discovered[0]->config->allowLocalHttp);
        self::assertSame('', $controller->discovered[0]->config->model);
        self::assertDirectoryDoesNotExist($this->directory . '/settings');
    }

    public static function invalidConnections(): iterable
    {
        yield 'array version' => [['version' => []], 'settings_conflict', 409];
        yield 'stale version' => [['version' => str_repeat('a', 32)], 'settings_conflict', 409];
        yield 'array base' => [['base_url' => []], 'invalid_settings', 422];
        yield 'array key' => [['api_key' => []], 'invalid_settings', 422];
        yield 'array auth' => [['auth_mode' => []], 'invalid_settings', 422];
        yield 'bad private flag' => [['allow_private_network' => 'true'], 'invalid_settings', 422];
        yield 'array private flag' => [['allow_private_network' => []], 'invalid_settings', 422];
        yield 'bad remove flag' => [['remove_api_key' => 'on'], 'invalid_settings', 422];
        yield 'remove and provide key' => [['remove_api_key' => '1'], 'invalid_settings', 422];
        yield 'http not accepted' => [['base_url' => 'http://localhost:8080/v1'], 'https_required', 422];
    }

    #[DataProvider('invalidConnections')]
    public function testInvalidConnectionNeverReservesOrCallsDiscovery(array $changes, string $reason, int $status): void
    {
        $controller = $this->controller('POST', $this->input($changes));
        self::assertSame($reason, $this->payload($controller->previewModels(), $status)['reason']);
        self::assertSame(['ai.configuration.write', 'csrf', 'settings'], $controller->events);
        self::assertSame([], $controller->discovered);
        self::assertSame(['.', '..'], scandir($this->directory));
    }

    public function testSavedStaleVersionCannotDiscover(): void
    {
        $saved = $this->saved();
        $this->store->save(17, 23, $this->input(['version' => $saved['version']]) + ['model' => 'new-model']);
        $controller = $this->controller('POST', $this->input(['version' => $saved['version']]));
        self::assertSame('settings_conflict', $this->payload($controller->previewModels(), 409)['reason']);
        self::assertSame([], $controller->discovered);
        self::assertDirectoryDoesNotExist($this->directory . '/catalog');
    }

    public static function mutations(): iterable
    {
        yield 'preview' => ['previewModels'];
        yield 'import' => ['importModels'];
    }

    #[DataProvider('mutations')]
    public function testMethodsWritePermissionAndRealCsrfPrecedeAllSideEffects(string $action): void
    {
        $controller = $this->controller('GET');
        $response = $controller->{$action}();
        $this->payload($response, 405);
        self::assertSame('POST', $response->getHeaderLine('Allow'));
        self::assertSame([], $controller->events);
        foreach ([['role' => 'clinic_admin'], ['permissions' => ['ai.configuration.read']], ['permissions' => ['ai.configuration.test']], ['permissions' => []]] as $changes) {
            $controller = $this->controller('POST', $this->input());
            $controller->identity = array_replace($controller->identity, $changes);
            $this->payload($controller->{$action}(), 403);
            self::assertSame(['ai.configuration.write'], $controller->events);
        }
        foreach ([[], [csrf_token() => 'invalid-token']] as $post) {
            $controller = $this->controller('POST', $post, false);
            self::assertTrue($this->payload($controller->{$action}(), 403)['reload']);
            self::assertSame(['ai.configuration.write', 'csrf'], $controller->events);
        }
        self::assertSame(['.', '..'], scandir($this->directory));
    }

    public static function invalidRequests(): iterable
    {
        foreach (['previewModels', 'importModels'] as $action) {
            foreach (['query', 'json', 'multipart', 'tenant_id', 'enabled', 'approved_revision', 'messages', 'defaults', 'models', 'url'] as $field) {
                yield "$action/$field" => [$action, $field];
            }
        }
        foreach (['model', 'max_input_tokens', 'thinking_mode'] as $field) { yield "preview/$field" => ['previewModels', $field]; }
        foreach (['base_url', 'api_key', 'auth_mode'] as $field) { yield "import/$field" => ['importModels', $field]; }
    }

    #[DataProvider('invalidRequests')]
    public function testNoArbitraryInputOrQueryIsAccepted(string $action, string $field): void
    {
        $post = $action === 'previewModels' ? $this->input() : ['preview_token' => str_repeat('a', 64), 'model_ids' => '[]', 'import_all' => '1'];
        if (!in_array($field, ['query', 'json', 'multipart'], true)) { $post[$field] = 'forged'; }
        $controller = $this->controller('POST', $post);
        if ($field === 'query') { service('request')->setGlobal('get', ['base_url' => 'https://forged.example.test/v1']); }
        if ($field === 'json') { service('request')->setHeader('Content-Type', 'application/json'); }
        if ($field === 'multipart') { service('request')->setHeader('Content-Type', 'multipart/form-data'); }
        self::assertSame('invalid_request', $this->payload($controller->{$action}(), 400)['reason']);
        self::assertSame(['ai.configuration.write', 'csrf'], $controller->events);
        self::assertSame(['.', '..'], scandir($this->directory));
    }

    public static function authorityRaces(): iterable
    {
        yield 'identity revoked' => ['revoked', 401];
        yield 'write permission revoked' => ['permission', 403];
        yield 'role revoked' => ['role', 403];
        yield 'auth version changed' => ['auth_version', 401];
        yield 'actor changed' => ['actor', 401];
        yield 'tenant changed' => ['tenant', 403];
        yield 'settings changed' => ['settings', 409];
    }

    #[DataProvider('authorityRaces')]
    public function testAuthorityAndSettingsAreRecheckedAfterRemoteBeforeStaging(string $scenario, int $status): void
    {
        $saved = $this->saved();
        $controller = $this->controller('POST', $this->input(['version' => $saved['version']]));
        $controller->duringDiscovery = function (AiModelSettingsHttpFixtureController $fixture) use ($scenario, $saved): void {
            switch ($scenario) {
                case 'revoked': $fixture->identity = null; break;
                case 'permission': $fixture->identity['permissions'] = ['ai.configuration.read']; break;
                case 'role': $fixture->identity['role'] = 'clinic_admin'; break;
                case 'auth_version': $fixture->identity['auth_version'] = 2; break;
                case 'actor': $fixture->identity['id'] = 24; break;
                case 'tenant': $fixture->identity['tenant_id'] = 18; break;
                case 'settings': $this->store->save(17, 23, $this->input(['version' => $saved['version']]) + ['model' => 'racing-model']); break;
            }
        };
        $response = $controller->previewModels();
        $data = $this->payload($response, $status);
        if ($status === 401) { self::assertSame('authentication_required', $response->getHeaderLine('X-Synapse-Auth')); }
        if ($status === 409) { self::assertSame('settings_conflict', $data['reason']); }
        self::assertContains('fresh', $controller->events);
        self::assertCount(1, $controller->discovered);
        self::assertFileDoesNotExist($this->directory . '/catalog/17.preview.enc');
        self::assertSame([], $this->catalog->summary(17)['models']);
        self::assertFalse($this->store->summary(17)['connection_tested']);
    }

    public static function invalidSelections(): iterable
    {
        yield 'unknown ID' => [['model_ids' => '["forged"]'], 'catalog_invalid_selection'];
        yield 'duplicate IDs' => [['model_ids' => '["org/a","org/a"]'], 'catalog_invalid_selection'];
        yield 'forged defaults object' => [['model_ids' => '[{"id":"org/a","defaults":{"max_output_tokens":1}}]'], 'invalid_request'];
                yield 'forged model object' => [['model_ids' => '[{"id":"org/a"}]'], 'catalog_invalid_selection'];
        yield 'empty selection' => [['model_ids' => '[]'], 'catalog_invalid_selection'];
        yield 'all plus selection' => [['import_all' => '1'], 'catalog_invalid_selection'];
        yield 'token altered' => [['preview_token' => str_repeat('a', 64)], 'catalog_preview_invalid'];
        yield 'bad token type' => [['preview_token' => []], 'invalid_request'];
        yield 'bad ids type' => [['model_ids' => []], 'invalid_request'];
        yield 'malformed JSON' => [['model_ids' => '['], 'invalid_request'];
        yield 'JSON object' => [['model_ids' => '{"id":"org/a"}'], 'invalid_request'];
        yield 'JSON null' => [['model_ids' => 'null'], 'invalid_request'];
        yield 'oversized selection' => [['model_ids' => str_repeat(' ', 65537)], 'invalid_request'];
        foreach (['true', '01', '2', [], null] as $i => $value) { yield "strict import flag/$i" => [['import_all' => $value], 'invalid_request']; }
    }

    #[DataProvider('invalidSelections')]
    public function testImportRejectsClientForgedSelectionWithoutConsumingValidPreview(array $changes, string $reason): void
    {
        $preview = $this->preview();
        $data = $this->payload($this->controller('POST', $this->selection($preview, $changes))->importModels(), $reason === 'catalog_preview_invalid' ? 409 : 422);
        self::assertSame($reason, $data['reason']);
        self::assertSame([], $this->catalog->summary(17)['models']);
        $result = $this->payload($this->controller('POST', $this->selection($preview, ['model_ids' => '[]', 'import_all' => '1']))->importModels());
        self::assertSame($preview['models'], $result['catalog']['models']);
        self::assertSame(2, $result['catalog']['imported_count']);
        self::assertDirectoryDoesNotExist($this->directory . '/settings');
    }

    public function testImportIsBoundToTenantActorCurrentTokenAndExpiry(): void
    {
        $preview = $this->preview();
        foreach ([['id' => 24], ['tenant_id' => 18]] as $changes) {
            $controller = $this->controller('POST', $this->selection($preview));
            $controller->identity = array_replace($controller->identity, $changes);
            self::assertSame('catalog_preview_invalid', $this->payload($controller->importModels(), 409)['reason']);
        }
        $current = $this->preview();
        self::assertSame('catalog_preview_invalid', $this->payload($this->controller('POST', $this->selection($preview))->importModels(), 409)['reason']);
        $this->time += 600;
        self::assertSame('catalog_preview_expired', $this->payload($this->controller('POST', $this->selection($current))->importModels(), 409)['reason']);
        self::assertSame([], $this->catalog->summary(17)['models']);
        self::assertSame([], $this->catalog->summary(18)['models']);
    }

    public static function discoveryErrors(): iterable
    {
        foreach (['catalog_rate_limited' => 429, 'catalog_too_large' => 422, 'catalog_duplicate_id' => 422,
            'catalog_invalid_selection' => 422, 'catalog_preview_invalid' => 409, 'catalog_preview_expired' => 409,
            'catalog_invalid_models' => 422] as $reason => $code) {
            yield $reason => [new RuntimeException($reason, $code), $reason, $code];
        }
        yield 'known reason untrusted status' => [new RuntimeException('catalog_invalid_models', 418), 'catalog_invalid_models', 422];
        yield 'unknown runtime message' => [new RuntimeException('provider leaked ' . self::SECRET, 422), 'settings_unavailable', 503];
        yield 'unknown throwable' => [new \LogicException(self::SECRET), 'settings_unavailable', 503];
        yield 'provider authentication' => [new AiFailure('provider_auth_failed', 503), 'provider_auth_failed', 503];
        yield 'provider transport' => [new AiFailure('transport_failed', 503), 'transport_failed', 503];
    }

    #[DataProvider('discoveryErrors')]
    public function testDiscoveryErrorsHaveAllowlistedReasonsSanitizedMessagesAndStatuses(Throwable $error, string $reason, int $status): void
    {
        $controller = $this->controller('POST', $this->input());
        $controller->discoveryError = $error;
        $data = $this->payload($controller->previewModels(), $status);
        self::assertFalse($data['success']);
        self::assertSame($reason, $data['reason']);
        self::assertArrayHasKey('csrf', $data);
        self::assertArrayNotHasKey('preview', $data);
        self::assertFileDoesNotExist($this->directory . '/catalog/17.preview.enc');
        self::assertDirectoryDoesNotExist($this->directory . '/settings');
    }

    public function testFailedRemoteRequestsStillUseTenantRateBudget(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $controller = $this->controller('POST', $this->input());
            $controller->discoveryError = new AiFailure('transport_failed');
            $this->payload($controller->previewModels(), 503);
            self::assertCount(1, $controller->discovered);
        }
        $controller = $this->controller('POST', $this->input());
        self::assertSame('catalog_rate_limited', $this->payload($controller->previewModels(), 429)['reason']);
        self::assertSame([], $controller->discovered);
        self::assertNotContains('release', $controller->events);
    }

    public function testStatusAndSsrReceiveOnlyTenantCatalogAndRedactedSettings(): void
    {
        $preview = $this->preview();
        $this->payload($this->controller('POST', $this->selection($preview))->importModels());
        $otherTenant = $this->controller('GET');
        $otherTenant->identity['tenant_id'] = 18;
        self::assertSame([], $this->payload($otherTenant->status())['catalog']['models']);
        $saved = $this->saved();
        $before = file_get_contents($this->directory . '/settings/provider.enc');
        foreach (['index', 'status'] as $action) {
            $controller = $this->controller('GET');
            $data = $this->payload($controller->{$action}());
            self::assertSame($this->catalog->summary(17), $data['catalog']);
            self::assertSame($saved, $data['settings']);
            self::assertArrayNotHasKey('api_key', $data['settings']);
            self::assertArrayNotHasKey('preview_token', $data['catalog']);
            self::assertStringNotContainsString($this->store->configuration()->encryptionKey, json_encode($data, JSON_THROW_ON_ERROR));
            self::assertNotContains('discover', $controller->events);
            self::assertNotContains('csrf', $controller->events);
        }
        $controller = $this->controller('GET');
        $controller->identity['tenant_id'] = 18;
        // The deployment settings are owned by tenant 17; a second tenant cannot read either payload.
        $this->payload($controller->status(), 403);
        self::assertSame($before, file_get_contents($this->directory . '/settings/provider.enc'));
    }

    public function testFixedPostRoutesAndSsrCatalogWiring(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        foreach (['preview' => 'previewModels', 'import' => 'importModels'] as $path => $action) {
            self::assertStringContainsString("\$routes->post('admin/ai/models/$path', 'AiConfigurationController::$action')", $routes);
        }
        // Check the SSR template contract without booting the unrelated portal layout/session/database.
        $view = file_get_contents(APPPATH . 'Views/admin/ai.php');
        self::assertStringContainsString('data-model-preview-url=', $view);
        self::assertStringContainsString('data-model-import-url=', $view);
        self::assertStringContainsString('data-ai-model-catalog data-catalog="<?= esc(json_encode($catalog,', $view);
        self::assertStringContainsString("JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT", $view);
        self::assertMatchesRegularExpression('/<input[^>]*name="api_key"[^>]*type="password"[^>]*>/', $view);
        preg_match('/<input[^>]*name="api_key"[^>]*>/', $view, $keyInput);
        self::assertStringNotContainsString('value=', $keyInput[0]);
    }
}

/** Every persistence/authority/transport hook is local; unexpected activation is a test failure. */
final class AiModelSettingsHttpFixtureController extends AiConfigurationController
{
    public SettingsStore $fixtureStore;
    public ModelCatalogStore $fixtureCatalog;
    public array $events = [];
    public array $freshCalls = [];
    public array $discovered = [];
    public ?\Closure $duringDiscovery = null;
    public ?Throwable $discoveryError = null;
    public ?array $identity = ['id' => 23, 'tenant_id' => 17, 'logged_in' => true, 'role' => 'superadmin',
        'auth_version' => 1, 'permissions' => ['rbac.manage']];

    protected function actor(string $permission): array
    {
        $this->events[] = $permission;
        return AiAccess::assertActor($this->identity, (int) ($this->identity['tenant_id'] ?? 17), $permission);
    }
    protected function freshIdentity(int $actorId, int $tenantId): ?array
    {
        $this->events[] = 'fresh';
        $this->freshCalls[] = [$actorId, $tenantId];
        return $this->identity;
    }
    protected function settings(): SettingsStore { $this->events[] = 'settings'; return $this->fixtureStore; }
    protected function catalog(): ModelCatalogStore { $this->events[] = 'catalog'; return $this->fixtureCatalog; }
    protected function profile(): Profile { return Profile::fromConfig($this->fixtureStore->configuration()); }
    protected function accessSummary(array $actor): array { return ['ready' => false, 'grants' => []]; }
    protected function renderStatus(array $status): string { return json_encode($status, JSON_THROW_ON_ERROR); }
    protected function verifyCsrf() { $this->events[] = 'csrf'; return parent::verifyCsrf(); }
    protected function releaseSession(): void { $this->events[] = 'release'; }
    protected function control(): DeploymentControl { throw new \LogicException('Discovery/import must not touch deployment control'); }
    protected function storageReady(): bool { throw new \LogicException('Discovery/import must not inspect analytics storage'); }
    protected function discoverModels(Profile $profile): array
    {
        Assert::assertSame('release', $this->events[count($this->events) - 1]);
        $this->events[] = 'discover';
        $this->discovered[] = $profile;
        if ($this->duringDiscovery !== null) { ($this->duringDiscovery)($this); }
        if ($this->discoveryError !== null) { throw $this->discoveryError; }
        return ModelCapabilities::normalize([['id' => 'org/a'], ['id' => 'org/b']], $profile);
    }
}

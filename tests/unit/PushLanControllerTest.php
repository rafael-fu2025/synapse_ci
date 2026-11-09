<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Api\V1\PushController;
use App\Database\Migrations\AddLanPushRegistrationScope;
use App\Models\ApiTokenModel;
use App\Services\Api\ApiContext;
use App\Services\Push\PushTokenService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use Config\App;
use Config\Database;
use Config\Push;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Controller HTTP envelopes on injected :memory: storage; authentication context never touches live services. */
final class PushLanControllerTest extends TestCase
{
    private const INSTALLATION = '11111111-1111-4111-8111-111111111111';
    private const REGISTRATION = '22222222-2222-4222-8222-222222222222';
    private const REPLACEMENT = '33333333-3333-4333-8333-333333333333';
    private const TOKEN_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const FAMILY = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private BaseConnection $db;
    private Push $config;
    private PushTokenService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        ApiContext::clear();
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'lancontroller_',
            'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->db->query('CREATE TABLE lancontroller_push_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            token TEXT NOT NULL UNIQUE, platform TEXT NOT NULL, installation_id TEXT NULL, api_family_id TEXT NULL,
            team_workspace_capable INTEGER NOT NULL DEFAULT 0, device_label TEXT NULL, created_ip TEXT NULL,
            last_used_at TEXT NULL, revoked_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NULL
        )');
        $this->db->query('CREATE TABLE lancontroller_api_tokens (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, token_hash TEXT,
            family_id TEXT, revoked_at TEXT NULL, rotated_at TEXT NULL
        )');
        $this->db->table('api_tokens')->insert(['id' => 1, 'tenant_id' => 7, 'user_id' => 5,
            'token_hash' => self::TOKEN_HASH, 'family_id' => self::FAMILY]);
        require_once APPPATH . 'Database/Migrations/2026-11-09-000001_AddLanPushRegistrationScope.php';
        (new AddLanPushRegistrationScope(Database::forge($this->db)))->up();
        $this->config = new Push();
        $this->config->deploymentId = 'synapse-lan-university';
        $this->registry = new PushTokenService($this->db, $this->config);
        ApiContext::set(['id' => 5, 'tenant_id' => 7], self::TOKEN_HASH);
    }

    protected function tearDown(): void
    {
        ApiContext::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function call(string $action, array $body = []): ResponseInterface
    {
        $app = new App();
        $app->baseURL = 'http://example.test/';
        $request = new IncomingRequest($app, new URI('http://example.test/api/v1/push/token'),
            json_encode((object) $body, JSON_THROW_ON_ERROR), new UserAgent());
        $request->setMethod($action === 'capabilities' ? 'GET' : ($action === 'revoke' ? 'DELETE' : 'POST'));
        $request->setHeader('Content-Type', 'application/json');
        $controller = new PushController($this->registry, new ApiTokenModel($this->db));
        $controller->initController($request, new Response($app), new NullLogger());
        return $controller->{$action}();
    }

    private function registration(): array
    {
        return ['token' => str_repeat('a', 40), 'platform' => 'android', 'installation_id' => self::INSTALLATION,
            'audience' => 'lan-test', 'registration_id' => self::REGISTRATION, 'capabilities' => ['team_workspace']];
    }

    public function testCapabilitiesRouteIsBearerAuthenticatedAndNoStore(): void
    {
        $routes = service('routes');
        $routes->loadRoutes();
        self::assertSame('\\App\\Controllers\\Api\\V1\\PushController::capabilities',
            $routes->getRoutes('GET')['api/v1/push/capabilities']);
        self::assertContains('api_auth', $routes->getFiltersForRoute('api/v1/push/capabilities', 'GET'));
        $response = $this->call('capabilities');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['data' => ['scope_version' => 1, 'deployment_id' => 'synapse-lan-university',
            'audiences' => ['lan-test']]], json_decode($response->getBody(), true));
        ApiContext::clear();
        self::assertSame(401, $this->call('capabilities')->getStatusCode());
    }

    public function testLanRegistrationUsesAuthenticatedFamilyAndReturnsExactScopeEnvelope(): void
    {
        $body = $this->registration() + ['tenant_id' => 99, 'user_id' => 99, 'api_family_id' => str_repeat('c', 32)];
        $response = $this->call('register', $body);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['data' => ['registered' => true, 'scope_version' => 1,
            'deployment_id' => 'synapse-lan-university', 'registration_id' => self::REGISTRATION]],
            json_decode($response->getBody(), true));
        $row = $this->db->table('push_tokens')->get()->getRowArray();
        self::assertSame([7, 5, self::FAMILY, 1], [$row['tenant_id'], $row['user_id'], $row['api_family_id'], $row['team_workspace_capable']]);
        self::assertStringNotContainsString($body['token'], $response->getBody());
    }

    public function testScopedDeleteIsConditionalAndBareDeleteReturnsValidationFailure(): void
    {
        self::assertSame(200, $this->call('register', $this->registration())->getStatusCode());
        $replacement = array_replace($this->registration(), ['registration_id' => self::REPLACEMENT]);
        self::assertSame(200, $this->call('register', $replacement)->getStatusCode());
        self::assertSame(204, $this->call('revoke', ['token' => $replacement['token'], 'audience' => 'lan-test',
            'registration_id' => self::REGISTRATION])->getStatusCode());
        self::assertNull($this->db->table('push_tokens')->get()->getRowArray()['revoked_at']);
        $response = $this->call('revoke', ['token' => $replacement['token']]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', json_decode($response->getBody(), true)['error']['code']);
        self::assertSame(204, $this->call('revoke', ['token' => $replacement['token'], 'audience' => 'lan-test',
            'registration_id' => self::REPLACEMENT])->getStatusCode());
        self::assertNotNull($this->db->table('push_tokens')->get()->getRowArray()['revoked_at']);
    }

    public function testLegacyRequestAndResponseAreUnchanged(): void
    {
        $response = $this->call('register', ['token' => str_repeat('b', 40), 'platform' => 'ios']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['data' => ['registered' => true]], json_decode($response->getBody(), true));
        self::assertSame(204, $this->call('revoke', ['token' => str_repeat('b', 40)])->getStatusCode());
    }

    public function testExplicitNullPartialUnknownAndMalformedScopesDoNotDowngradeToLegacy(): void
    {
        foreach ([['audience' => null, 'registration_id' => null], ['audience' => 'lan-test'],
            ['registration_id' => self::REGISTRATION], ['audience' => 'normal', 'registration_id' => self::REGISTRATION],
            ['audience' => 'lan-test', 'registration_id' => ['bad']], ['deployment_id' => 'synapse-lan-university']] as $scope) {
            $body = ['token' => str_repeat('a', 40), 'platform' => 'android'] + $scope;
            self::assertSame(422, $this->call('register', $body)->getStatusCode());
            self::assertSame(422, $this->call('revoke', $body)->getStatusCode());
        }
        $body = $this->registration();
        unset($body['installation_id']);
        self::assertSame(422, $this->call('register', $body)->getStatusCode());
        self::assertSame(0, $this->db->table('push_tokens')->countAllResults());
    }

    public function testMissingScopeConfigurationReturns503WithoutAFalseSuccess(): void
    {
        $this->config->deploymentId = '';
        foreach (['capabilities', 'register'] as $action) {
            $response = $this->call($action, $this->registration());
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('service_unavailable', json_decode($response->getBody(), true)['error']['code']);
        }
        self::assertSame(0, $this->db->table('push_tokens')->countAllResults());
    }

    public function testUnmatchedRevokedOrRotatedBearerFamiliesCannotSupplyPushBinding(): void
    {
        ApiContext::set(['id' => 6, 'tenant_id' => 7], self::TOKEN_HASH);
        self::assertSame(401, $this->call('register', $this->registration())->getStatusCode());
        ApiContext::set(['id' => 5, 'tenant_id' => 7], self::TOKEN_HASH);
        $this->db->table('api_tokens')->update(['rotated_at' => '2026-11-09 12:00:00']);
        self::assertSame(401, $this->call('register', $this->registration())->getStatusCode());
        $this->db->table('api_tokens')->update(['rotated_at' => null, 'revoked_at' => '2026-11-09 12:00:00']);
        self::assertSame(401, $this->call('register', $this->registration())->getStatusCode());
        self::assertSame(0, $this->db->table('push_tokens')->countAllResults());
    }
}

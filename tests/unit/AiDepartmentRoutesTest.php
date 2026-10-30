<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AiConfigurationController;
use App\Controllers\AiInsightsController;
use App\Filters\WebAccessFilter;
use App\Services\Ai\AiAccess;
use App\Services\Ai\AiFailure;
use App\Services\Ai\InsightApplication;
use App\Services\AuthenticatedUser;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\Filters;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Router\Router;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Security;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionProperty;

/** Only the application boundary is replaced; actor authorization remains the production implementation. */
final class RouteAuthorizedInsightsController extends AiInsightsController
{
    public int $applicationCalls = 0;

    protected function application(): InsightApplication
    {
        ++$this->applicationCalls;
        throw new AiFailure('disabled');
    }
}

/** Real router/filter chain, SQLite :memory: identity/grants, in-memory session. No settings or provider access.
 * Run with --no-configuration --bootstrap tests/_support/ai-runtime-bootstrap.php.
 */
final class AiDepartmentRoutesTest extends CIUnitTestCase
{
    private BaseConnection $fixture;
    private array $connections;
    private array $sessionData = [];
    private const STAFF = ['clinic' => 'clinic_staff', 'guidance' => 'counsellor', 'facilities' => 'facilities_op'];
    private const SOURCE = ['clinic' => 'clinic.encounters.read', 'guidance' => 'guidance.cases.read_any', 'facilities' => 'facilities.read'];

    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        if (!extension_loaded('sqlite3')) { $this->markTestSkipped('Disposable SQLite requires sqlite3.'); }
        $this->connections = Database::getConnections();
        $this->fixture = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'ai_route_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        (new ReflectionProperty(Database::class, 'instances'))->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        self::assertSame($this->fixture, Database::connect());
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, email TEXT, mis_id TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'employee_profiles' => 'person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'ai_department_access' => 'tenant_id INTEGER, unit TEXT, role TEXT, permission TEXT, created_by INTEGER, created_at TEXT,
                UNIQUE (tenant_id, unit, role, permission)',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ai_route_' . $table . ' (' . $columns . ')');
        }
        $this->fixture->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        foreach ([...array_values(self::STAFF), 'superadmin', 'student'] as $i => $role) {
            $id = $i + 1;
            $this->fixture->table('people')->insert(['id' => $id, 'tenant_id' => 1, 'first_name' => 'Synthetic', 'last_name' => $role]);
            $this->fixture->table('users')->insert(['id' => $id, 'tenant_id' => 1, 'person_id' => $id,
                'username' => $role, 'role' => $role, 'status' => 'active']);
        }
        foreach ([...array_values(self::SOURCE), 'rbac.manage'] as $i => $permission) {
            $this->fixture->table('permissions')->insert(['id' => $i + 1, 'code' => $permission]);
            $this->fixture->table('role_permissions')->insert(['role' => $i === 3 ? 'superadmin' : array_values(self::STAFF)[$i], 'permission_id' => $i + 1]);
        }
        foreach (self::STAFF as $path => $role) {
            foreach (['read', 'generate', 'review', 'approve'] as $action) {
                $this->fixture->table('ai_department_access')->insert(['tenant_id' => 1, 'unit' => self::unit($path),
                    'role' => $role, 'permission' => 'ai.insights.' . $action, 'created_by' => 4, 'created_at' => '2026-10-29 00:00:00']);
            }
        }
        $session = $this->getMockBuilder(Session::class)->disableOriginalConstructor()->getMock();
        $session->method('get')->willReturnCallback(fn ($key = null) => $key === null ? $this->sessionData : ($this->sessionData[$key] ?? null));
        $session->method('set')->willReturnCallback(function ($key, $value = null): void {
            $this->sessionData = array_replace($this->sessionData, is_array($key) ? $key : [$key => $value]);
        });
        $session->method('remove')->willReturnCallback(function ($keys): void {
            foreach ((array) $keys as $key) { unset($this->sessionData[$key]); }
        });
        $session->method('getFlashdata')->willReturn(null);
        Services::injectMock('session', $session);
        $security = clone config('Security');
        $security->csrfProtection = 'cookie';
        $security->regenerate = false;
        Factories::injectMock('config', Security::class, $security);
        Services::resetSingle('security');
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        if (isset($this->fixture)) {
            (new ReflectionProperty(Database::class, 'instances'))->setValue(null, $this->connections);
            $this->fixture->close();
        }
        parent::tearDown();
    }

    private static function unit(string $path): string { return $path === 'facilities' ? 'bmg' : $path; }

    private function signIn(string $role, int $tenant = 1): void
    {
        $user = $this->fixture->table('users')->where('role', $role)->get()->getRowArray();
        $this->sessionData = ['tenant_id' => $tenant, 'user' => ['id' => (int) $user['id'], 'tenant_id' => $tenant,
            'logged_in' => true, 'role' => 'superadmin', 'permissions' => ['*', 'facilities.manage']],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]];
    }

    private function request(string $path, string $method, bool $csrf = true, string $host = 'example.com',
        string $prefix = '', string $index = '', array $post = []): IncomingRequest
    {
        $app = clone config('App');
        $app->baseURL = 'http://' . $host . '/' . $prefix;
        $app->indexPage = $index;
        $uri = new SiteURI($app, $path);
        $request = new IncomingRequest($app, $uri, null, new UserAgent());
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $request->setGlobal('server', ['HTTP_HOST' => $host]);
        $request->setGlobal('get', []);
        $hash = csrf_hash();
        $request->setGlobal('cookie', [config('Cookie')->prefix . config('Security')->cookieName => $hash]);
        $request->setGlobal('post', ($csrf && $method === 'POST' ? [csrf_token() => $hash] : []) + $post);
        Services::injectMock('uri', $uri);
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response($app));
        return $request;
    }

    private function router(IncomingRequest $request): Router
    {
        $routes = Services::routes();
        $routes->loadRoutes();
        $router = new Router($routes, $request);
        $router->handle($request->getUri()->getRoutePath());
        return $router;
    }

    private function boundary(IncomingRequest $request): RequestInterface|ResponseInterface|string|null
    {
        $router = $this->router($request);
        $filters = new Filters(config('Filters'), $request, service('response'));
        $filters->enableFilters($router->getFilters(), 'before');
        return $filters->run($request->getUri()->getRoutePath(), 'before');
    }

    public static function insightRoutes(): iterable
    {
        foreach (array_keys(self::STAFF) as $unit) {
            foreach (['' => ['GET', 'index'], '/panel' => ['GET', 'panel'], '/prepare' => ['POST', 'prepare'],
                '/generate' => ['POST', 'generate'], '/47/review' => ['POST', 'review'], '/jobs/31/cancel' => ['POST', 'cancel']] as $suffix => [$verb, $action]) {
                yield $unit . $suffix => [$unit, $suffix, $verb, $action];
            }
        }
    }

    #[DataProvider('insightRoutes')]
    public function testRegisteredDepartmentsReachAiAuthorityWithOnlySourceReadAndAiGrants(string $unit, string $suffix, string $verb, string $action): void
    {
        foreach ([['', ''], ['', 'index.php'], ['campus/', ''], ['campus/', 'index.php']] as [$prefix, $index]) {
            $this->signIn(self::STAFF[$unit]);
            $post = $action === 'review' ? ['version' => '1', 'decision' => 'accept'] : [];
            $request = $this->request($unit . '/insights' . $suffix, $verb, prefix: $prefix, index: $index, post: $post);
            $router = $this->router($request);
            self::assertSame('\\App\\Controllers\\AiInsightsController', $router->controllerName());
            self::assertSame($action, $router->methodName());
            self::assertSame(['auth', 'web_csrf', 'portal_host', 'web_access', 'portal_access:' . self::unit($unit)], $router->getFilters());
            self::assertInstanceOf(RequestInterface::class, $this->boundary($request), 'Registered route filters must not hide an authorized workstation.');
            self::assertSame(self::STAFF[$unit], session('user')['role']);
            self::assertSame([self::SOURCE[$unit]], session('user')['permissions'], 'Forged cached grants must be replaced.');
            self::assertSame(1, AiAccess::current('ai.insights.read', self::unit($unit))['tenant_id']);
            if ($verb === 'POST') {
                $controller = new RouteAuthorizedInsightsController();
                $controller->initController($request, service('response'), new NullLogger());
                $response = in_array($action, ['review', 'cancel'], true) ? $controller->$action(47) : $controller->$action();
                self::assertSame('disabled', json_decode($response->getBody(), true)['reason']);
                self::assertSame(1, $controller->applicationCalls, 'Production AI authority must allow this action to reach its application boundary.');
            }
        }
    }

    #[DataProvider('insightRoutes')]
    public function testWrongVerbsNeverDispatchInsightHandlers(string $unit, string $suffix, string $verb, string $action): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
            if ($method === $verb) { continue; }
            $request = $this->request($unit . '/insights' . $suffix, $method);
            if ($method === 'HEAD' && $verb === 'GET') {
                try { self::assertSame($action, $this->router($request)->methodName()); }
                catch (PageNotFoundException) { self::assertTrue(true); }
                continue;
            }
            try { $this->router($request); self::fail('Unexpected ' . $method . ' route: ' . $unit . $suffix); }
            catch (PageNotFoundException) { self::assertTrue(true); }
        }
    }

    #[DataProvider('insightRoutes')]
    public function testHostRoleAndTenantGatesRemainEnforced(string $unit, string $suffix, string $verb): void
    {
        $path = $unit . '/insights' . $suffix;
        $this->sessionData = [];
        self::assertSame(401, $this->boundary($this->request($path, $verb))->getStatusCode());
        $this->signIn('student');
        self::assertSame(403, $this->boundary($this->request($path, $verb))->getStatusCode());
        $this->signIn(self::STAFF[$unit], 2); // Valid tenant, but this user belongs to tenant 1.
        self::assertSame(401, $this->boundary($this->request($path, $verb))->getStatusCode());
        $this->signIn(self::STAFF[$unit]);
        $host = self::unit($unit) . '.localhost';
        self::assertInstanceOf(RequestInterface::class, $this->boundary($this->request($path, $verb, host: $host)));
        $this->signIn(self::STAFF[$unit]);
        self::assertSame(403, $this->boundary($this->request($path, $verb, host: $unit === 'clinic' ? 'guidance.localhost' : 'clinic.localhost'))->getStatusCode());
        if ($verb === 'POST') {
            $this->signIn(self::STAFF[$unit]);
            $response = $this->boundary($this->request($path, $verb, csrf: false));
            self::assertSame(403, $response->getStatusCode());
            self::assertStringContainsString('security token', $response->getBody());
        }
    }

    public function testFacilitiesExemptionDoesNotBroadenOtherWritesOrRemoveControllerSourceAuthority(): void
    {
        $this->signIn('facilities_op');
        foreach (['facilities/insights-extra/prepare', 'facilities/insights/prepare/extra', 'facilities/unit/1/device',
            'facilities/insights/anything', 'facilities/batch/1/complete'] as $path) {
            $response = (new WebAccessFilter())->before($this->request($path, 'POST'));
            self::assertSame(403, $response->getStatusCode());
            self::assertStringContainsString('facilities.manage', $response->getBody());
        }
        foreach (['PUT', 'PATCH', 'DELETE'] as $verb) {
            self::assertSame(403, (new WebAccessFilter())->before($this->request('facilities/insights/prepare', $verb))->getStatusCode());
        }
        $permission = $this->fixture->table('permissions')->where('code', 'facilities.read')->get()->getRowArray();
        $this->fixture->table('role_permissions')->where('role', 'facilities_op')->where('permission_id', $permission['id'])->delete();
        $request = $this->request('facilities/insights/generate', 'POST');
        self::assertInstanceOf(RequestInterface::class, $this->boundary($request));
        $controller = new RouteAuthorizedInsightsController();
        $controller->initController($request, service('response'), new NullLogger());
        self::assertSame(403, $controller->generate()->getStatusCode());
        self::assertSame(0, $controller->applicationCalls, 'AI generation still requires source read.');
        self::assertSame(403, (new WebAccessFilter())->before($this->request('facilities/insights', 'GET'))->getStatusCode(), 'GET retains facilities.read.');
    }

    public function testConfigurationAccessRouteHasRealMutationFiltersAndIndependentAdminAuthority(): void
    {
        $path = 'admin/ai/access';
        $request = $this->request($path, 'POST');
        $router = $this->router($request);
        self::assertSame('\\App\\Controllers\\AiConfigurationController', $router->controllerName());
        self::assertSame('access', $router->methodName());
        self::assertSame(['auth', 'web_csrf', 'portal_host', 'web_access'], $router->getFilters());
        foreach (['GET', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $verb) {
            try { $this->router($this->request($path, $verb)); self::fail('Access changes must be POST-only.'); }
            catch (PageNotFoundException) { self::assertTrue(true); }
        }
        $this->signIn('superadmin');
        self::assertInstanceOf(RequestInterface::class, $this->boundary($this->request($path, 'POST', host: 'synapse.localhost')));
        $request = service('request');
        $controller = new AiConfigurationController();
        $controller->initController($request, service('response'), new NullLogger());
        self::assertSame(422, $controller->access()->getStatusCode(), 'RBAC admin reaches input validation without touching settings or changing grants.');
        $this->signIn('facilities_op');
        $request = $this->request($path, 'POST');
        self::assertInstanceOf(RequestInterface::class, $this->boundary($request));
        $controller = new AiConfigurationController();
        $controller->initController($request, service('response'), new NullLogger());
        self::assertSame(403, $controller->access()->getStatusCode(), 'Department AI grants do not grant configuration access.');
        $this->signIn('superadmin');
        self::assertSame(403, $this->boundary($this->request($path, 'POST', csrf: false))->getStatusCode());
        self::assertSame(403, $this->boundary($this->request($path, 'POST', host: 'bmg.localhost'))->getStatusCode(), 'Admin mutations are never forwarded between hosts.');
        $this->signIn('superadmin', 2);
        self::assertSame(401, $this->boundary($this->request($path, 'POST'))->getStatusCode());
    }
}

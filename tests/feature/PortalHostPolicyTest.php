<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Filters\PortalHostFilter;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\PortalContext;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PortalHostPolicyTest extends CIUnitTestCase
{
    use FeatureTestTrait { setupRequest as private setupBaseRequest; }
    private string $httpHost = 'clinic.localhost';

    protected function setupRequest(string $method, ?string $path = null): IncomingRequest
    {
        $request = $this->setupBaseRequest($method, $path);
        $request->setGlobal('server', ['HTTP_HOST' => $this->httpHost . ':8080']);
        $request->getUri()->setHost($this->httpHost);
        $request->getUri()->setPort(8080);
        return $request;
    }
    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = ['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'host_', 'DBDebug' => true];
        $this->db = Database::connect();
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT',
            'employee_profiles' => 'person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, password_hash TEXT, last_login_at TEXT, updated_at TEXT, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
        ] as $table => $columns) $this->db->query('CREATE TABLE host_' . $table . ' (' . $columns . ')');
        $this->db->table('tenants')->insert(['id' => 1, 'is_active' => 1]);
        $hash = password_hash('fixture-password', PASSWORD_DEFAULT);
        foreach (PortalContext::allowedRoles('main') as $i => $role) {
            $id = $i + 1;
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => 1, 'first_name' => 'Test', 'last_name' => $role, 'mis_id' => 'FIXTURE-' . $id]);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => 1, 'person_id' => $id, 'username' => $role, 'role' => $role, 'status' => 'active', 'password_hash' => $hash]);
        }
        (new PermissionsAndRolesSeeder(config('Database')))->run();
        config('App')->baseURL = 'http://localhost:8080/base/';
        config('App')->indexPage = 'index.php';
        helper(['url', 'form']);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        foreach (['request', 'uri', 'response', 'redirectresponse', 'renderer'] as $name) Services::resetSingle($name);
        unset($_SERVER['HTTP_HOST']);
        $this->db->close();
        parent::tearDown();
    }

    private function request(string $host, string $path, string $method = 'GET', bool $json = false): IncomingRequest
    {
        Services::resetSingle('response');
        Services::resetSingle('redirectresponse');
        Services::resetSingle('renderer');
        $uri = new SiteURI(config('App'), $path);
        $uri->setHost($host);
        $uri->setPort(8080);
        $request = new IncomingRequest(config('App'), $uri, null, new UserAgent());
        $request->setMethod($method);
        $_SERVER['HTTP_HOST'] = $host . ':8080';
        $request->setGlobal('server', ['HTTP_HOST' => $host . ':8080']);
        if ($json) $request->setHeader('Accept', 'application/json');
        $request->setGlobal('get', []);
        Services::injectMock('request', $request);
        Services::injectMock('uri', $uri);
        return $request;
    }

    private function signIn(string $role): void
    {
        $user = $this->db->table('users')->where('username', $role)->get()->getRowArray();
        session()->set(['tenant_id' => 1, 'user' => ['id' => (int) $user['id'], 'tenant_id' => 1, 'role' => 'superadmin', 'permissions' => ['*'], 'logged_in' => true],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
    }

    public static function roleHosts(): iterable
    {
        foreach (['superadmin', 'clinic_admin', 'clinic_staff', 'guidance_admin', 'guidance_supervisor', 'counsellor', 'bmg_admin', 'facilities_op', 'audit_reader', 'report_viewer', 'student', 'employee'] as $role) {
            foreach (['synapse.localhost' => ['main', 'portal'], 'clinic.localhost' => ['clinic', 'clinic/teams'], 'guidance.localhost' => ['guidance', 'guidance/teams'], 'bmg.localhost' => ['bmg', 'facilities/teams']] as $host => [$portal, $path]) {
                yield $role . '@' . $host => [$role, $host, $portal, $path];
            }
        }
    }

    #[DataProvider('roleHosts')]
    public function testRoleAndHostMatrix(string $role, string $host, string $portal, string $path): void
    {
        $this->signIn($role);
        $request = $this->request($host, $path, 'GET', true);
        $this->assertSame($portal, PortalContext::hostPortal());
        $result = (new PortalHostFilter())->before($request);
        if (PortalContext::isRolePermitted($role, $portal)) $this->assertNull($result);
        else {
            $this->assertSame(403, $result->getStatusCode());
            $this->assertSame('This account cannot use this workstation.', json_decode($result->getBody(), true)['message']);
        }
        $this->assertSame($role, session('user')['role'], 'Cached superadmin role must be replaced');
        $html = view('partials/sidebar');
        foreach (['clinic' => 'Clinic Workstation', 'guidance' => 'Guidance Workstation', 'bmg' => 'Facilities (BMG)'] as $workspace => $heading) {
            if ($workspace === $portal && PortalContext::isRolePermitted($role, $workspace)) $this->assertStringContainsString($heading, $html);
            else $this->assertStringNotContainsString($heading, $html);
        }
    }

    public function testCrossHostGetRedirectsOnlyToConfiguredDestinationAndPreservesPath(): void
    {
        $this->signIn('superadmin');
        $request = $this->request('clinic.localhost', 'guidance/teams');
        $request->setGlobal('get', ['mine' => '1', 'q' => '//evil.invalid']);
        $result = (new PortalHostFilter())->before($request);
        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('http://guidance.localhost:8080/base/index.php/guidance/teams?mine=1&q=%2F%2Fevil.invalid', $result->getHeaderLine('Location'));
        $this->assertStringContainsString('no-store', $result->getHeaderLine('Cache-Control'));
    }

    public function testCrossHostMutationsAndAjaxAreDeniedWithoutRedirect(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'GET'] as $method) {
            $this->signIn('superadmin');
            $request = $this->request('clinic.localhost', 'guidance/teams/store', $method, true);
            $result = (new PortalHostFilter())->before($request);
            $this->assertSame(403, $result->getStatusCode());
            $this->assertSame('', $result->getHeaderLine('Location'));
            $this->assertSame('http://guidance.localhost:8080/base/index.php/guidance/teams/store', json_decode($result->getBody(), true)['portal_url']);
        }
    }

    public function testOwnRoleCannotRedirectIntoUnauthorizedOtherDepartment(): void
    {
        $this->signIn('clinic_staff');
        $request = $this->request('clinic.localhost', 'guidance/teams');
        $result = (new PortalHostFilter())->before($request);
        $this->assertSame(403, $result->getStatusCode());
        $this->assertSame('', $result->getHeaderLine('Location'));
    }

    public function testMainHostDepartmentGetUsesCanonicalHostButPlainLocalhostKeepsPathMode(): void
    {
        $this->signIn('clinic_staff');
        $request = $this->request('synapse.localhost', 'clinic/teams');
        $result = (new PortalHostFilter())->before($request);
        $this->assertSame('http://clinic.localhost:8080/base/index.php/clinic/teams', $result->getHeaderLine('Location'));
        $request = $this->request('localhost', 'clinic/teams', 'POST', true);
        $this->assertNull((new PortalHostFilter())->before($request));
        $this->assertNull(PortalContext::hostPortal());
        $this->assertSame('clinic', PortalContext::current());
        $this->assertSame('http://localhost:8080/base/index.php/clinic', PortalContext::landing('clinic_staff'));
    }

    public function testStudentRootAndLoginOnClinicHostShowNoticeNotRedirectBounce(): void
    {
        $this->signIn('student');
        $request = $this->request('clinic.localhost', '');
        $result = (new PortalHostFilter())->before($request);
        $this->assertSame(403, $result->getStatusCode());
        $this->assertStringContainsString('http://synapse.localhost:8080/base/index.php/portal', $result->getBody());
        $this->assertSame('', $result->getHeaderLine('Location'));
        $this->request('clinic.localhost', 'auth/login');
        $controller = new AuthController();
        $controller->initController(service('request'), service('response'), service('logger'));
        $this->assertSame(403, $controller->login()->getStatusCode());
        $controller = new DashboardController();
        $controller->initController(service('request'), service('response'), service('logger'));
        $this->assertSame(403, $controller->index()->getStatusCode());
    }

    public static function loginDestinations(): iterable
    {
        yield 'clinic employee on main' => ['synapse.localhost', 'clinic_staff', 'portal'];
        yield 'guidance employee on main' => ['synapse.localhost', 'counsellor', 'portal'];
        yield 'facilities employee on main' => ['synapse.localhost', 'facilities_op', 'portal'];
        yield 'clinic workstation' => ['clinic.localhost', 'clinic_admin', 'clinic'];
        yield 'facilities alias' => ['facilities.localhost', 'bmg_admin', 'facilities/dashboard'];
        yield 'path fallback' => ['localhost', 'clinic_staff', 'clinic'];
        yield 'main administrator' => ['synapse.localhost', 'superadmin', 'dashboard'];
        yield 'main employee' => ['synapse.localhost', 'employee', 'portal'];
        yield 'main student' => ['synapse.localhost', 'student', 'portal'];
    }

    #[DataProvider('loginDestinations')]
    public function testSuccessfulLoginFinishesOnItsOwnOrigin(string $host, string $role, string $path): void
    {
        CurrentTenant::set(1);
        session()->set('tenant_id', 1);
        $request = $this->request($host, 'auth/login', 'POST');
        $request->setGlobal('post', ['identifier' => $role, 'password' => 'fixture-password']);
        $controller = new AuthController();
        $controller->initController($request, service('response'), service('logger'));
        $result = $controller->attemptLogin();
        $this->assertSame('http://' . $host . ':8080/base/index.php/' . $path, $result->getHeaderLine('Location'));
        $this->assertSame($role, session('user')['role']);
        $this->assertStringContainsString('no-store', $result->getHeaderLine('Cache-Control'));
        $this->assertSame('', config('Cookie')->domain);
    }

    public function testWrongHostSuccessfulPasswordDoesNotLeaveAuthenticatedSession(): void
    {
        CurrentTenant::set(1);
        session()->set('tenant_id', 1);
        $request = $this->request('clinic.localhost', 'auth/login', 'POST');
        $request->setGlobal('post', ['identifier' => 'student', 'password' => 'fixture-password']);
        $controller = new AuthController();
        $controller->initController($request, service('response'), service('logger'));
        $result = $controller->attemptLogin();
        $this->assertSame(403, $result->getStatusCode());
        $this->assertNull(session('user'));
        $this->assertNull(session('_auth'));
        $this->assertStringNotContainsString('fixture-password', $result->getBody());
    }

    public function testActualRoutesRunHostFilterBeforeCrossWorkspaceController(): void
    {
        config('App')->baseURL = 'http://localhost:8080/';
        foreach ([['clinic.localhost', 'guidance/teams/store'], ['synapse.localhost', 'clinic/teams/store']] as [$host, $path]) {
            $this->httpHost = $host;
            $this->signIn('superadmin');
            $identity = ['tenant_id' => session('tenant_id'), 'user' => session('user'), '_auth' => session('_auth')];
            $hash = service('security')->generateHash();
            service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
            Services::resetSingle('security');
            $result = $this->withSession($identity)->withHeaders(['Accept' => 'application/json'])
                ->post($path, [config('Security')->tokenName => $hash, 'title' => 'Must not be stored']);
            $result->assertStatus(403);
            $this->assertStringContainsString('Requests are not forwarded between hosts.', $result->getBody());
            $this->assertStringContainsString('portal_url', $result->getBody());
        }
        $this->httpHost = 'clinic.localhost';
        $this->signIn('superadmin');
        $result = $this->withSession(['tenant_id' => session('tenant_id'), 'user' => session('user'), '_auth' => session('_auth')])
            ->withHeaders(['Accept' => 'text/html'])->get('guidance/teams');
        $result->assertStatus(302);
        $this->assertSame('http://guidance.localhost:8080/index.php/guidance/teams', $result->response()->getHeaderLine('Location'));
    }

    public function testUnsafeDestinationStringsAreRejected(): void
    {
        $this->request('clinic.localhost', 'clinic/teams');
        foreach (['//attacker.invalid', 'https://attacker.invalid', "portal\r\nInjected: value", '\\attacker'] as $path) {
            try {
                PortalContext::urlFor('main', $path);
                $this->fail('Unsafe destination was accepted');
            } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function testAliasesExplicitHostsAndNoParentDomainCookie(): void
    {
        $this->request('facilities.localhost', 'facilities/teams');
        $this->assertSame('bmg', PortalContext::hostPortal());
        $this->assertSame('http://bmg.localhost:8080/base/index.php/facilities/dashboard', PortalContext::landing('facilities_op'));
        $this->request('clinic.foundationu.com', 'clinic/teams');
        $this->assertSame('http://guidance.foundationu.com:8080/base/index.php/guidance', PortalContext::urlFor('guidance', 'guidance'));
        $this->request('clinic.attacker.invalid', 'portal');
        $this->assertNull(PortalContext::hostPortal());
        $this->assertSame('main', PortalContext::current());
        $this->assertSame('', config('Cookie')->domain, 'This feature must not widen session cookie scope');
        $this->assertSame('main', PortalContext::routePortal('portal/appointments/clinic/create'));
        $this->assertNull(PortalContext::routePortal('clinicish'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Filters\AuthFilter;
use App\Filters\PortalAccessFilter;
use App\Filters\PortalHostFilter;
use App\Filters\WebAccessFilter;
use App\Filters\WebCsrfFilter;
use App\Services\AuthenticatedUser;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCodeIgniter;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** AUTH-01: installation prefixes must not bypass the real HTTP authorization boundary. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DeploymentAuthorizationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'deployment_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ];
        $this->db = Database::connect();
        $this->assertSame('SQLite3', $this->db->DBDriver);
        $this->assertSame(':memory:', $this->db->database);
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT',
            'employee_profiles' => 'person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, status TEXT, deleted_at TEXT',
        ] as $table => $columns) {
            $this->db->query('CREATE TABLE deployment_' . $table . ' (' . $columns . ')');
        }
        $this->db->table('tenants')->insert(['id' => 1, 'is_active' => 1]);
        foreach ([1 => 'counsellor', 2 => 'clinic_staff'] as $id => $role) {
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => 1,
                'first_name' => 'Deployment', 'last_name' => $role]);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => 1, 'person_id' => $id,
                'username' => $role, 'role' => $role, 'status' => 'active']);
        }
        $this->db->table('counselling_cases')->insertBatch([
            ['id' => 501, 'tenant_id' => 1, 'person_id' => 1, 'counsellor_user_id' => 1, 'status' => 'open'],
            ['id' => 502, 'tenant_id' => 1, 'person_id' => 1, 'counsellor_user_id' => 999, 'status' => 'open'],
        ]);
        (new PermissionsAndRolesSeeder(config('Database')))->run();
        helper(['form', 'url']);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    public static function deployments(): iterable
    {
        yield 'root' => ['http://example.com/', ''];
        yield 'index' => ['http://example.com/', 'index.php'];
        yield 'subdirectory' => ['http://example.com/campus/', ''];
        yield 'subdirectory and index' => ['http://example.com/campus/', 'index.php'];
    }

    private function setApp(string $baseURL, string $indexPage): void
    {
        $config = config('App');
        $config->baseURL = $baseURL;
        $config->indexPage = $indexPage;
        $this->app = new MockCodeIgniter($config);
        $this->app->initialize();
    }

    private function identity(int $id): array
    {
        return ['tenant_id' => 1, 'user' => ['id' => $id, 'tenant_id' => 1, 'logged_in' => true,
            'role' => 'superadmin', 'permissions' => ['*']],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1,
                'session_id' => session_id()]];
    }

    private function deny(int $id, string $method, string $route, string $message): void
    {
        Services::resetSingle('response');
        Services::resetSingle('redirectresponse');
        $data = [];
        if ($method === 'POST') {
            $hash = service('security')->generateHash();
            service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
            Services::resetSingle('security');
            $data[config('Security')->tokenName] = $hash;
        }
        $controllerCreated = false;
        $listener = static function () use (&$controllerCreated): void {
            $controllerCreated = true;
        };
        Events::on('post_controller_constructor', $listener);
        try {
            $result = $this->withSession($this->identity($id))->withHeaders(['Accept' => 'application/json'])
                ->call($method, $route, $data);
        } finally {
            Events::removeListener('post_controller_constructor', $listener);
        }
        $this->assertFalse($controllerCreated, 'Authorization must reject before controller construction.');
        $result->assertStatus(403);
        $this->assertSame(['message' => $message], json_decode($result->response()->getBody(), true, 512, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('application/json', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));

        // Inspect the request actually dispatched by FeatureTestTrait, not a separately constructed URI.
        $uri = service('request')->getUri();
        $config = config('App');
        $fullURL = $config->baseURL . ($config->indexPage === '' ? '' : $config->indexPage . '/') . $route;
        $this->assertSame($fullURL, (string) $uri);
        $this->assertSame(parse_url($fullURL, PHP_URL_PATH), $uri->getPath());
        $this->assertSame($route, $uri->getRoutePath());
        $this->assertSame($method, strtoupper(service('request')->getMethod()));
        $classes = array_column(service('filters')->getFiltersClass()['before'], 0);
        foreach ([AuthFilter::class, WebCsrfFilter::class, PortalHostFilter::class,
            WebAccessFilter::class, PortalAccessFilter::class] as $class) {
            $this->assertContains($class, $classes);
        }
        $this->assertSame($id === 1 ? 'counsellor' : 'clinic_staff', session('user')['role']);
        $this->assertNotContains('*', session('user')['permissions']);
    }

    #[DataProvider('deployments')]
    public function testUnassignedCasePhotoAndCloseStayForbidden(string $baseURL, string $indexPage): void
    {
        $this->setApp($baseURL, $indexPage);
        $this->deny(1, 'GET', 'guidance/cases/502/photo', 'This case is assigned to another counselor.');
        $this->assertContains('guidance.cases.read', session('user')['permissions']);
        $this->assertNotContains('guidance.cases.read_any', session('user')['permissions']);
        $this->deny(1, 'POST', 'guidance/cases/502/close', 'This case is assigned to another counselor.');
        $this->assertContains('guidance.cases.write', session('user')['permissions']);
        $this->assertSame('open', $this->db->table('counselling_cases')->where('id', 502)->get()->getRowArray()['status']);
    }

    private function revoke(string $role, string $code): void
    {
        $permission = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
        $this->assertNotNull($permission);
        $grant = $this->db->table('role_permissions')->where('role', $role)->where('permission_id', $permission['id']);
        $this->assertSame(1, $grant->countAllResults(false));
        $grant->delete();
        $this->assertSame(1, $this->db->affectedRows());
    }

    public function testRevokedClinicQueueReadIsForbiddenOnCombinedDeployment(): void
    {
        $this->setApp('http://example.com/campus/', 'index.php');
        $this->revoke('clinic_staff', 'clinic.queue.read');
        $this->deny(2, 'GET', 'clinic/queue', 'Permission required: clinic.queue.read');
        $this->assertNotContains('clinic.queue.read', session('user')['permissions']);
    }

    public function testCorrectDepartmentAndOwnCaseDoNotBypassRevokedRead(): void
    {
        $this->setApp('http://example.com/campus/', 'index.php');
        $this->assertSame(1, (int) $this->db->table('counselling_cases')->where('id', 501)
            ->get()->getRowArray()['counsellor_user_id']);
        $this->revoke('counsellor', 'guidance.cases.read');
        $this->deny(1, 'GET', 'guidance/cases/501/photo', 'Permission required: guidance.cases.read');
        $this->assertNotContains('guidance.cases.read', session('user')['permissions']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\AuthenticatedUser;
use App\Services\Audit\AuditService;
use App\Services\EmployeeAccessService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmployeeAccessHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait { setupRequest as private setupBaseRequest; }
    private string $httpHost = 'localhost';

    protected function setupRequest(string $method, ?string $path = null): \CodeIgniter\HTTP\IncomingRequest
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
        config('Database')->tests = ['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'ea_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000];
        $this->db = Database::connect();
        $this->assertSame(':memory:', $this->db->database);
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT',
            'employee_profiles' => 'person_id INTEGER, tenant_id INTEGER, department TEXT, employment_status TEXT, is_teaching INTEGER',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, password_hash TEXT, updated_at TEXT, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'audit_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER, occurred_at TEXT',
        ] as $table => $columns) $this->db->query('CREATE TABLE ea_' . $table . ' (' . $columns . ')');
        $this->db->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        foreach ([1 => ['superadmin', 'Administration'], 2 => ['clinic_admin', 'Health Services'],
            3 => ['guidance_admin', 'Guidance'], 4 => ['employee', 'Health Services'], 5 => ['employee', 'Guidance'],
            6 => ['clinic_staff', 'Health Services'], 7 => ['counsellor', 'Guidance'], 8 => ['bmg_admin', 'Facilities'],
            9 => ['student', 'Health Services'], 10 => ['employee', 'Health Services'], 11 => ['superadmin', 'Administration'],
            12 => ['guidance_supervisor', 'Guidance'], 13 => ['employee', 'Facilities']] as $id => [$role, $department]) {
            $tenant = $id === 10 ? 2 : 1;
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => $tenant, 'kind' => $id === 9 ? 'student' : 'employee',
                'first_name' => 'Employee' . $id, 'last_name' => 'Test', 'mis_id' => 'MIS-' . $id]);
            $this->db->table('employee_profiles')->insert(['person_id' => $id, 'tenant_id' => $tenant,
                'department' => $department, 'employment_status' => 'active']);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id, 'username' => 'user' . $id,
                'role' => $role, 'status' => 'active', 'password_hash' => 'untouched']);
        }
        (new PermissionsAndRolesSeeder(config('Database')))->run();
        helper(['form', 'url']);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function identity(int $id, int $version = 1): array
    {
        $tenant = $id === 10 ? 2 : 1;
        return ['tenant_id' => $tenant, 'user' => ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true,
            'role' => 'superadmin', 'permissions' => ['rbac.manage']], '_auth' => [
                'started_at' => time(), 'last_seen_at' => time(), 'version' => $version, 'session_id' => session_id()]];
    }

    private function requestAs(int $actor, string $method, string $path, array $input = [], bool $csrf = true)
    {
        Services::resetSingle('renderer');
        Services::resetSingle('response');
        Services::resetSingle('redirectresponse');
        if ($method === 'POST' && $csrf) {
            $hash = service('security')->generateHash();
            service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
            Services::resetSingle('security');
            $input[config('Security')->tokenName] = $hash;
        }
        return $this->withSession($this->identity($actor))->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->call($method, $path, $input);
    }

    private function assign(int $actor, int $target, string $role, array $extra = [], bool $csrf = true)
    {
        return $this->requestAs($actor, 'POST', 'admin/access/' . $target . '/update', $extra + [
            'role' => $role, 'reason' => 'Approved staff assignment', 'version' => '1'], $csrf);
    }

    private function row(int $id): array
    {
        return $this->db->table('users')->where('id', $id)->get()->getRowArray();
    }

    public function testDirectoryAndModalRenderWithScopedEmployeesAndRealPermissions(): void
    {

        $result = $this->requestAs(2, 'GET', 'admin/access');
        $result->assertStatus(200);
        $this->assertStringContainsString('Employee4', $result->getBody());
        $this->assertStringNotContainsString('Employee5', $result->getBody());
        $this->assertStringNotContainsString('Employee10', $result->getBody());
        $this->assertStringContainsString('Employees &amp; Access', $result->getBody());
        $this->assertStringContainsString('data-live-table', $result->getBody());
        $this->assertStringContainsString('assets/js/live-table.js', $result->getBody());
        $result = $this->requestAs(2, 'GET', 'admin/access/4/edit?fragment=1');
        $result->assertStatus(200);
        $this->assertStringContainsString('data-app-form-modal', $result->getBody());
        $this->assertStringContainsString('data-modal-submit', $result->getBody());
        $this->assertStringNotContainsString('<!DOCTYPE html>', $result->getBody());
        $this->assertStringContainsString('clinic.encounters.read', $result->getBody());
        $this->assertStringNotContainsString('value="superadmin"', $result->getBody());
        $this->assertStringContainsString('name="version" value="1"', $result->getBody());
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }

    public function testDirectoryFragmentSupportsLiveSearchAndValidatesPageSize(): void
    {
        $result = $this->requestAs(2, 'GET', 'admin/access?fragment=1&q=MIS-4');
        $result->assertStatus(200);
        $this->assertStringContainsString('data-table-content', $result->getBody());
        $this->assertStringContainsString('data-table-pagination', $result->getBody());
        $this->assertStringContainsString('MIS-4', $result->getBody());
        $this->assertStringContainsString('data-page="1"', $result->getBody());
        $this->assertStringNotContainsString('Employee6', $result->getBody());
        $this->assertStringNotContainsString('<!DOCTYPE html>', $result->getBody());

        $this->requestAs(2, 'GET', 'admin/access?fragment=1&per_page=7')->assertStatus(422);
        $this->requestAs(2, 'GET', 'admin/access?per_page=50')->assertStatus(200);
    }

    public function testDepartmentAssignmentsAreAuditedAndRevokeSessionsWithoutTouchingIdentity(): void
    {
        $before = $this->row(4);
        $this->assign(2, 4, 'clinic_staff', ['tenant_id' => 2, 'username' => 'hacked', 'password_hash' => 'hacked'])->assertStatus(200);
        $after = $this->row(4);
        $this->assertSame('clinic_staff', $after['role']);
        $this->assertSame(2, (int) $after['auth_version']);
        foreach (['person_id', 'tenant_id', 'username', 'password_hash', 'status'] as $field) $this->assertSame($before[$field], $after[$field]);
        $event = $this->db->table('audit_events')->get()->getRowArray();
        $this->assertSame(2, (int) $event['actor_user_id']);
        $payload = json_decode($event['payload_json'], true);
        $this->assertSame('employee', $payload['previous_role']);
        $this->assertSame('clinic_staff', $payload['new_role']);
        $this->assertTrue((new AuditService())->verifyChain(1)['valid']);
        session()->set($this->identity(4));
        $this->assertNull((new AuthenticatedUser())->current());
        session()->set($this->identity(4, 2));
        $this->assertSame('clinic_staff', (new AuthenticatedUser())->current()['role']);
        $this->assign(2, 6, 'employee')->assertStatus(200);
        $this->assertSame('employee', $this->row(6)['role']);
        $this->assign(3, 5, 'counsellor')->assertStatus(200);
    }

    public function testAllNonManagersCannotUseForgedCachedPrivileges(): void
    {
        foreach ([4, 6, 7, 8, 9, 12, 13] as $id) {
            $this->requestAs($id, 'GET', 'admin/access')->assertStatus(403);
            $this->assign($id, 5, 'superadmin')->assertStatus(403);
        }
        $this->assertSame(0, $this->db->table('audit_events')->countAllResults());
    }

    public function testManagersCannotEscalateOrCrossDepartmentsOrTenants(): void
    {
        foreach ([[2, 4, 'superadmin', 403], [2, 4, 'clinic_admin', 403], [3, 5, 'guidance_supervisor', 403],
            [2, 5, 'clinic_staff', 404], [3, 4, 'counsellor', 404], [2, 10, 'clinic_staff', 404],
            [1, 10, 'clinic_staff', 404], [1, 9, 'clinic_staff', 404], [2, 11, 'employee', 404],
            [3, 12, 'employee', 404], [1, 4, 'invented', 403], [1, 1, 'employee', 403]] as [$actor, $target, $role, $status]) {
            $this->assign($actor, $target, $role)->assertStatus($status);
        }
        $this->assertSame(0, $this->db->table('audit_events')->countAllResults());
    }

    public function testStaleWritesValidationAndCsrfFailWithoutChanges(): void
    {
        $this->assign(2, 4, 'clinic_staff', ['version' => '9'])->assertStatus(409);
        $this->assign(2, 4, 'clinic_staff', ['version' => 'x'])->assertStatus(422);
        $this->assign(2, 4, 'clinic_staff', ['reason' => '  '])->assertStatus(422);
        $this->assign(2, 4, 'clinic_staff', ['reason' => str_repeat('x', 501)])->assertStatus(422);
        $this->assign(2, 4, 'employee')->assertStatus(422);
        $this->assign(2, 4, 'clinic_staff', [], false)->assertStatus(403);
        $this->assertSame('employee', $this->row(4)['role']);
        $this->assertSame(1, (int) $this->row(4)['auth_version']);
        $this->assertSame(0, $this->db->table('audit_events')->countAllResults());
    }

    public function testInactiveEmployeesCannotBePromotedButCanHaveAccessRevoked(): void
    {
        $this->db->table('employee_profiles')->where('person_id', 4)->update(['employment_status' => 'inactive']);
        $this->assign(2, 4, 'clinic_staff')->assertStatus(422);
        $this->db->table('users')->where('id', 5)->update(['status' => 'suspended']);
        $this->assign(3, 5, 'counsellor')->assertStatus(422);
        $this->db->table('employee_profiles')->where('person_id', 6)->update(['employment_status' => 'inactive']);
        $this->assign(2, 6, 'employee')->assertStatus(200);
    }

    public function testAuditFailureRollsBackRoleAndVersion(): void
    {
        $this->db->query("CREATE TRIGGER ea_fail_audit BEFORE INSERT ON ea_audit_events BEGIN SELECT RAISE(ABORT, 'Simulated audit failure'); END");
        $this->assign(2, 4, 'clinic_staff')->assertStatus(503);
        $this->assertSame('employee', $this->row(4)['role']);
        $this->assertSame(1, (int) $this->row(4)['auth_version']);
    }

    public function testSuperadminCanAssignPrivilegedRolesButNotSelfAndStaleRetriesFail(): void
    {
        $this->assign(1, 4, 'guidance_admin')->assertStatus(200);
        $this->assign(1, 4, 'employee')->assertStatus(409);
        $this->assign(1, 11, 'employee')->assertStatus(200);
        $this->assertSame('superadmin', $this->row(1)['role']);
        $this->assign(1, 1, 'employee')->assertStatus(403);
        $this->assertSame(2, $this->db->table('audit_events')->countAllResults());
    }

    public function testDirectorySearchEscapesOutputAndRejectsInvalidInput(): void
    {
        $this->db->table('people')->where('id', 4)->update(['first_name' => '<script>unsafe</script>']);
        $result = $this->requestAs(1, 'GET', 'admin/access?q=MIS-4');
        $result->assertStatus(200);
        $this->assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $result->getBody());
        $this->assertStringNotContainsString('<script>unsafe</script>', $result->getBody());
        $this->assertStringNotContainsString('Employee5', $result->getBody());
        $this->requestAs(1, 'GET', 'admin/access?q[]=bad')->assertStatus(422);
        $this->requestAs(1, 'GET', 'admin/access?page=-1')->assertStatus(422);
    }

    public function testFullNameSearchHandlesOrderWhitespaceAndDepartmentScope(): void
    {
        $this->db->table('people')->where('id', 5)->update(['first_name' => 'Employee4', 'last_name' => 'Test']);
        foreach (['Employee4 Test', 'Test Employee4', '  Employee4   Test  '] as $query) {
            $result = $this->requestAs(2, 'GET', 'admin/access?' . http_build_query(['q' => $query]));
            $result->assertStatus(200);
            $this->assertStringContainsString('MIS-4', $result->getBody());
            $this->assertStringNotContainsString('MIS-5', $result->getBody());
        }
        $this->db->table('people')->where('id', 4)->update(['first_name' => 'María', 'last_name' => 'José']);
        $result = $this->requestAs(2, 'GET', 'admin/access?' . http_build_query(['q' => 'María José']));
        $result->assertStatus(200);
        $this->assertStringContainsString('MIS-4', $result->getBody());
        session()->set($this->identity(2));
        $literal = (new EmployeeAccessService())->listing(['q' => '%']);
        $this->assertCount(0, $literal['employees'], 'A literal percent is not a search wildcard');
                $this->db->table('people')->where('id', 4)->update(['first_name' => "O'Neil", 'last_name' => '100%_Test!']);
                foreach (["O'Neil", '100%_Test!'] as $query) {
                    $literal = (new EmployeeAccessService())->listing(['q' => $query]);
                    $this->assertSame([4], array_map('intval', array_column($literal['employees'], 'id')));
                }
    }

    public function testAuditLedgerDisplaysEscapedAccessChangeDetailsOnly(): void
    {
        $this->assign(2, 4, 'clinic_staff', ['reason' => '<script>not executable</script>'])->assertStatus(200);
        $result = $this->requestAs(1, 'GET', 'admin/audit');
        $result->assertStatus(200);
        $this->assertStringContainsString('data-role-change-details', $result->getBody());
        $this->assertStringContainsString('Previous role', $result->getBody());
        $this->assertStringContainsString('New role', $result->getBody());
        $this->assertStringContainsString('&lt;script&gt;not executable&lt;/script&gt;', $result->getBody());
        $this->assertStringNotContainsString('<script>not executable</script>', $result->getBody());
    }

    public function testSuperadminCanAssignEverySupportedRoleWithoutReseedingGrants(): void
    {
        $grants = $this->db->table('role_permissions')->get()->getResultArray();
        $version = 1;
        foreach (array_diff(array_keys(EmployeeAccessService::ROLES), ['employee']) as $role) {
            $this->assign(1, 4, $role, ['version' => $version])->assertStatus(200);
            $this->assertSame($role, $this->row(4)['role']);
            $this->assertSame(++$version, (int) $this->row(4)['auth_version']);
        }
        $this->assertSame($grants, $this->db->table('role_permissions')->get()->getResultArray());
        $this->assertTrue((new AuditService())->verifyChain(1)['valid']);
    }

    public function testRevokedManagementPermissionAndExpiredSessionsFailClosed(): void
    {
        $this->db->table('role_permissions')->where('role', 'clinic_admin')->delete();
        $this->assign(2, 4, 'clinic_staff')->assertStatus(403);
        $this->db->table('users')->where('id', 3)->update(['auth_version' => 2]);
        $this->assign(3, 5, 'counsellor')->assertStatus(401);
        $this->assertSame('employee', $this->row(4)['role']);
        $this->assertSame('employee', $this->row(5)['role']);
    }

    public function testAccessWritesStayOnTheMainHost(): void
    {
        config('App')->baseURL = 'http://localhost:8080/';
        $this->httpHost = 'clinic.localhost';
        $result = $this->assign(2, 4, 'clinic_staff');
        $result->assertStatus(403);
        $this->assertStringContainsString('synapse.localhost:8080/index.php/admin/access/4/update', $result->getBody());
        $this->assertSame('employee', $this->row(4)['role']);
        $this->httpHost = 'synapse.localhost';
        $this->assign(2, 4, 'clinic_staff')->assertStatus(200);
    }

    public function testDeletedEmployeesAndProfilesOutsideTenantAreNotAssignable(): void
    {
        $this->db->table('people')->where('id', 4)->update(['deleted_at' => '2026-01-01']);
        $this->assign(1, 4, 'clinic_staff')->assertStatus(404);
        $this->db->table('employee_profiles')->where('person_id', 5)->update(['tenant_id' => 2]);
        $this->assign(1, 5, 'counsellor')->assertStatus(404);
    }
}

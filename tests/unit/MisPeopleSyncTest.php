<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\MisSyncController;
use App\Integrations\Mis\MisClientInterface;
use App\Services\Audit\AuditService;
use App\Services\AuthenticatedUser;
use App\Services\Directory\MisPeopleSyncService;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use ReflectionProperty;
use RuntimeException;

final class MisPeopleSyncTest extends CIUnitTestCase
{
    protected $db;
    private array $connections;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sqlite3')) { $this->markTestSkipped('Private SQLite requires sqlite3.'); }
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $instances->getValue();
        $instances->setValue(null, ['tests' => $this->db]);
        foreach ([
            'CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, role TEXT, username TEXT, email TEXT, status TEXT, deleted_at TEXT, auth_version INTEGER DEFAULT 1)',
            'CREATE TABLE people (id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT)',
            'CREATE TABLE employee_profiles (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, is_teaching INTEGER)',
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY, code TEXT)',
            'CREATE TABLE role_permissions (role TEXT, permission_id INTEGER)',
            'CREATE TABLE mis_departments (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, code TEXT NOT NULL, name TEXT NOT NULL, synced_at TEXT NOT NULL, UNIQUE(tenant_id, code))',
            'CREATE TABLE mis_programs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, code TEXT NOT NULL, name TEXT NOT NULL, department_code TEXT, synced_at TEXT NOT NULL, UNIQUE(tenant_id, code))',
            'CREATE TABLE mis_year_levels (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, code TEXT NOT NULL, name TEXT NOT NULL, synced_at TEXT NOT NULL, UNIQUE(tenant_id, code))',
            'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER DEFAULT 1, occurred_at TEXT)',
            'INSERT INTO tenants (id, is_active) VALUES (1, 1), (2, 1)',
            "INSERT INTO people (id, tenant_id, first_name, last_name) VALUES (7, 1, 'Audit', 'Admin')",
            'INSERT INTO employee_profiles (id, tenant_id, person_id, is_teaching) VALUES (7, 1, 7, 0)',
            "INSERT INTO users (id, tenant_id, person_id, role, username, status) VALUES (7, 1, 7, 'clinic_admin', 'audit-admin', 'active')",
            "INSERT INTO permissions (id, code) VALUES (1, 'mis.sync')",
            "INSERT INTO role_permissions (role, permission_id) VALUES ('superadmin', 1), ('clinic_admin', 1), ('guidance_admin', 1), ('employee', 1)",
        ] as $sql) { $this->db->query($sql); }
        foreach ([
            "ALTER TABLE people ADD COLUMN kind TEXT",
            "ALTER TABLE people ADD COLUMN middle_name TEXT",
            "ALTER TABLE people ADD COLUMN mis_synced_at TEXT",
            "ALTER TABLE people ADD COLUMN mis_payload_hash TEXT",
            "ALTER TABLE people ADD COLUMN created_at TEXT",
            "ALTER TABLE people ADD COLUMN updated_at TEXT",
            "ALTER TABLE employee_profiles ADD COLUMN department TEXT",
            "ALTER TABLE employee_profiles ADD COLUMN position TEXT",
            "ALTER TABLE employee_profiles ADD COLUMN employment_status TEXT DEFAULT 'active'",
            "ALTER TABLE employee_profiles ADD COLUMN created_at TEXT",
            "ALTER TABLE employee_profiles ADD COLUMN updated_at TEXT",
            "CREATE TABLE student_profiles (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, program TEXT, year_level INTEGER, section TEXT, department TEXT, enrollment_type TEXT, is_graduating INTEGER, created_at TEXT, updated_at TEXT)",
            "CREATE UNIQUE INDEX person_identity ON people(tenant_id, kind, mis_id)",
        ] as $sql) { $this->db->query($sql); }
        $this->signIn();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            AuthenticatedUser::clear();
            $this->db->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        }
        parent::tearDown();
    }

    private function signIn(int $tenant = 1): void
    {
        session()->set('_auth', ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]);
        session()->set(['tenant_id' => $tenant, 'user' => ['id' => 7, 'tenant_id' => $tenant, 'logged_in' => true,
            'role' => 'superadmin', 'permissions' => ['mis.sync']]]);
    }

    private function service(): MisPeopleSyncService
    {
        return new MisPeopleSyncService(new \App\Integrations\Mis\FakeMisClient(), $this->db);
    }

    public function testDirectoryOnlyRepeatAndAudit(): void
    {
        $users = $this->db->table('users')->get()->getResultArray();
        $result = $this->service()->sync();
        $this->assertSame(3, $result['counts']['student']['inserted']);
        $this->assertSame(3, $result['counts']['employee']['inserted']);
        $this->assertSame(3, $this->service()->sync()['counts']['employee']['unchanged']);
        $this->assertSame($users, $this->db->table('users')->get()->getResultArray());
        $this->assertTrue((new AuditService(null, $this->db))->verifyChain(1)['valid']);
        $audit = $this->db->table('audit_events')->get()->getRowArray();
        $this->assertStringNotContainsString('ROBERTO', $audit['payload_json']);
        $this->assertSame('mis.people.synced', $audit['event_type']);
        $this->assertSame(3, $this->service()->status()['counts']['student']);
    }

    public function testRetainsMissingSkipsDeletedAndIsolatesTenants(): void
    {
        foreach ([[1, '20230001', '2000-01-01'], [2, '20240102', null], [1, 'missing', null]] as [$tenant, $id, $deleted]) {
            $this->db->table('people')->insert(['tenant_id' => $tenant, 'kind' => 'student', 'mis_id' => $id,
                'first_name' => 'Keep', 'last_name' => 'Name', 'deleted_at' => $deleted]);
        }
        $result = $this->service()->sync();
        $this->assertSame(1, $result['counts']['student']['skipped_deleted']);
        $this->assertSame(3, $this->db->table('people')->where('first_name', 'Keep')->countAllResults());
        $this->assertSame(2, $result['counts']['student']['inserted']);
    }

    public function testAuditAndProfileFailureRollBackPeople(): void
    {
        foreach (['audit_events', 'employee_profiles'] as $table) {
            $before = $this->db->table('people')->get()->getResultArray();
            $this->db->query("CREATE TRIGGER reject_write BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'secret'); END");
            try { $this->service()->sync(); $this->fail('Must roll back.'); }
            catch (RuntimeException $error) { $this->assertSame(503, $error->getCode()); }
            $this->assertSame($before, $this->db->table('people')->get()->getResultArray());
            $this->assertSame(0, $this->db->table('student_profiles')->countAllResults());
            $this->db->query('DROP TRIGGER reject_write');
        }
    }

    public function testPaginationAndDuplicatesFailClosed(): void
    {
        $row = ['student_id' => '001', 'first_name' => 'One', 'last_name' => 'Name'];
        $good = ['data' => [$row], 'current_page' => 1, 'max_page' => 1, 'limit' => 25, 'total' => 1];
        foreach ([array_replace($good, ['total' => 2]), array_replace($good, ['current_page' => 2]),
            array_replace($good, ['data' => [$row, $row], 'total' => 2]),
            array_replace($good, ['data' => [array_replace($row, ['level' => '999'])]]),
            array_replace($good, ['data' => [array_replace($row, ['first_name' => str_repeat('x', 101)])]])] as $page) {
            $client = $this->createMock(MisClientInterface::class);
            $client->method('fetchStudentsPage')->willReturn($page);
            $client->expects($this->never())->method('searchStudents');
            try { (new MisPeopleSyncService($client, $this->db))->sync(); $this->fail('Must reject.'); }
            catch (RuntimeException $error) { $this->assertSame(503, $error->getCode()); }
            $this->assertSame(1, $this->db->table('people')->countAllResults());
        }
    }

    public function testBoundedMultiplePagesAndOmissionSemantics(): void
    {
        $rows = [];
        for ($i = 0; $i < 26; $i++) {
            $rows[] = ['student_id' => sprintf('%05d', $i), 'first_name' => 'Student', 'last_name' => 'Name'];
        }
        $client = $this->createMock(MisClientInterface::class);
        $client->expects($this->exactly(4))->method('fetchStudentsPage')->willReturnCallback(function ($page, $limit) use ($rows) {
            $this->assertSame(25, $limit);
            return ['data' => array_slice($rows, ($page - 1) * 25, 25), 'current_page' => $page,
                'max_page' => 2, 'limit' => 25];
        });
        $client->method('fetchEmployeesPage')->willReturn(['data' => [], 'current_page' => 1, 'max_page' => 1, 'limit' => 25, 'total' => 0]);
        $service = new MisPeopleSyncService($client, $this->db);
        $this->assertSame(26, $service->sync()['counts']['student']['inserted']);
        $this->db->table('student_profiles')->where('tenant_id', 1)->update(['program' => 'Keep', 'enrollment_type' => 'new', 'is_graduating' => 1]);
        $service->sync();
        $profile = $this->db->table('student_profiles')->get()->getRowArray();
        $this->assertSame('Keep', $profile['program']);
        $this->assertSame('new', $profile['enrollment_type']);
        $this->assertSame(1, $profile['is_graduating']);
        $this->assertSame('00000', $this->db->table('people')->where('kind', 'student')->get()->getRowArray()['mis_id']);
    }

    public function testExplicitUnknownAudienceClearsAndAuthorityIsRechecked(): void
    {
        $this->service()->sync();
        $this->db->table('student_profiles')->update(['enrollment_type' => 'new', 'is_graduating' => 1]);
        $client = $this->createMock(MisClientInterface::class);
        $client->method('fetchStudentsPage')->willReturn(['data' => [['student_id' => '20230001', 'student_type' => null, 'is_graduating' => null]],
            'current_page' => 1, 'max_page' => 1, 'limit' => 25, 'total' => 1]);
        $client->method('fetchEmployeesPage')->willReturn(['data' => [], 'current_page' => 1, 'max_page' => 1, 'limit' => 25, 'total' => 0]);
        (new MisPeopleSyncService($client, $this->db))->sync();
        $person = $this->db->table('people')->where('mis_id', '20230001')->get()->getRowArray();
        $profile = $this->db->table('student_profiles')->where('person_id', $person['id'])->get()->getRowArray();
        $this->assertNull($profile['enrollment_type']);
        $this->assertNull($profile['is_graduating']);
        $before = $this->db->table('people')->get()->getResultArray();
        try {
            $this->service()->sync(function ($stage) {
                if ($stage['percent'] === 80) { $this->db->table('role_permissions')->where('role', 'clinic_admin')->delete(); }
            });
            $this->fail('Revocation must stop import.');
        } catch (RuntimeException $error) { $this->assertSame(403, $error->getCode()); }
        $this->assertSame($before, $this->db->table('people')->get()->getResultArray());
    }

    public function testLiveBulkMethodsNormalizeDocumentedPagesUsingOnlyStubTransport(): void
    {
        $cache = $this->createMock(\CodeIgniter\Cache\CacheInterface::class);
        $cache->method('get')->willReturn('fixture-access');
        \Config\Services::injectMock('cache', $cache);
        foreach (['fetchStudentsPage' => 'students', 'fetchEmployeesPage' => 'employees'] as $method => $resource) {
            foreach ([false, true] as $nested) {
                $client = new \App\Integrations\Mis\HttpMisClient('https://mis.example.test/sandbox', 'fixture-key', 7,
                    function ($verb, $url, $headers, $body) use ($resource, $nested): array {
                        $this->assertSame('GET', $verb);
                        $this->assertSame('https://mis.example.test/sandbox/api/v1/' . $resource . '?limit=25&page=1', $url);
                        $this->assertNull($body);
                        $this->assertContains('Authorization: Bearer fixture-access', $headers);
                        $page = ['data' => [], 'limit' => '25', 'current_page' => '1', 'max_page' => '0'];
                        return ['status' => 200, 'body' => json_encode($nested ? ['data' => $page] : $page, JSON_THROW_ON_ERROR)];
                    });
                $this->assertSame(['limit' => 25, 'current_page' => 1, 'max_page' => 0, 'data' => []], $client->$method());
            }
        }
    }

    public function testCanonicalIdsAreRequiredBeforeAnyWrites(): void
    {
        foreach ([['id' => '001'], ['student_id' => 1], ['student_id' => ' 001'],
            ['student_id' => '001', 'student_number' => '002']] as $identity) {
            $client = $this->createMock(MisClientInterface::class);
            $client->method('fetchStudentsPage')->willReturn(['data' => [$identity + ['first_name' => 'One', 'last_name' => 'Name']],
                'current_page' => 1, 'max_page' => 1, 'limit' => 25]);
            $client->expects($this->never())->method('fetchEmployeesPage');
            try { (new MisPeopleSyncService($client, $this->db))->sync(); $this->fail('Must reject identity.'); }
            catch (RuntimeException $error) { $this->assertSame(503, $error->getCode()); }
            $this->assertSame(1, $this->db->table('people')->countAllResults());
        }
    }

    public function testImportedPeopleAreReusedByLaterLoginAndAssignedRoleSurvivesSync(): void
    {
        foreach (['password_hash', 'created_at', 'updated_at', 'last_login_at'] as $field) {
            $this->db->query("ALTER TABLE users ADD COLUMN {$field} TEXT");
        }
        $this->service()->sync();
        $person = $this->db->table('people')->where('kind', 'student')->where('mis_id', '20230001')->get()->getRowArray();
        $auth = new \App\Services\AuthService(misClient: new \App\Integrations\Mis\FakeMisClient());
        $identity = $auth->authenticate('20230001', 'fixture-password');
        $this->assertNotNull($identity);
        $this->assertSame((int) $person['id'], (int) $identity['person_id']);
        $this->assertSame(7, $this->db->table('people')->countAllResults());
        $this->assertSame(2, $this->db->table('users')->countAllResults());
        $employee = $auth->authenticate('20269001', 'fixture-password');
        $this->assertNotNull($employee);
        $this->db->table('users')->where('id', $employee['id'])->update(['role' => 'clinic_admin']);
        $before = $this->db->table('users')->get()->getResultArray();
        $this->service()->sync();
        $this->assertSame($before, $this->db->table('users')->get()->getResultArray());
        $this->assertSame('clinic_admin', $auth->authenticate('20269001', 'fixture-password')['role']);
    }

    public function testPeopleRouteRejectsWrongVerbAndCsrf(): void
    {
        $controller = new MisSyncController();
        $request = service('request');
        $request->setMethod('GET');
        $controller->initController($request, service('response'), service('logger'));
        $this->assertSame(405, $controller->people()->getStatusCode());
        $request->setMethod('POST');
        $request->setGlobal('post', []);
        $this->assertSame(403, $controller->people()->getStatusCode());
    }
}

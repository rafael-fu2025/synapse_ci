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

    public function testLiveDirectoryBatchRetriesTransientFailuresAndRefreshesExpiredToken(): void
    {
        $cache = $this->createMock(\CodeIgniter\Cache\CacheInterface::class);
        $cache->method('get')->with('fumis_service_access_token')->willReturn('stale-access');
        $cache->expects($this->once())->method('delete')->with('fumis_service_access_token');
        $cache->expects($this->once())->method('save')->with('fumis_service_access_token', 'fresh-access', 480);
        \Config\Services::injectMock('cache', $cache);

        $page2Attempts = 0;
        $page3Attempts = 0;
        $client = new \App\Integrations\Mis\HttpMisClient('https://mis.example.test/sandbox', 'fixture-key', 7,
            static function (string $verb, string $url, array $headers, ?string $body) use (&$page2Attempts, &$page3Attempts): array {
                if (str_ends_with($url, '/api/tokens/generate')) {
                    return ['status' => 200, 'body' => json_encode(['data' => ['refresh_token' => 'fresh-refresh']], JSON_THROW_ON_ERROR)];
                }
                if (str_ends_with($url, '/api/tokens/refresh')) {
                    return ['status' => 200, 'body' => json_encode(['data' => ['access_token' => 'fresh-access']], JSON_THROW_ON_ERROR)];
                }
                if (str_contains($url, 'page=2')) {
                    ++$page2Attempts;
                    if ($page2Attempts === 1) {
                        throw new \RuntimeException('simulated cURL timeout');
                    }
                    $rows = array_map(static fn (int $i): array => [
                        'student_id' => sprintf('2023%04d', 25 + $i),
                        'first_name' => 'Student',
                        'last_name'  => 'Two',
                    ], range(1, 25));
                    return ['status' => 200, 'body' => json_encode([
                        'data' => ['data' => $rows, 'limit' => '25', 'current_page' => '2', 'max_page' => '3'],
                    ], JSON_THROW_ON_ERROR)];
                }
                if (str_contains($url, 'page=3')) {
                    ++$page3Attempts;
                    if ($page3Attempts === 1) {
                        return ['status' => 401, 'body' => '{"message":"Expired"}'];
                    }
                    if (!in_array('Authorization: Bearer fresh-access', $headers, true)) {
                        return ['status' => 401, 'body' => '{"message":"Expected refreshed token"}'];
                    }
                    return ['status' => 200, 'body' => json_encode([
                        'data' => [
                            'data' => [['student_id' => '20230099', 'first_name' => 'Last', 'last_name' => 'Student']],
                            'limit' => '25', 'current_page' => '3', 'max_page' => '3',
                        ],
                    ], JSON_THROW_ON_ERROR)];
                }
                throw new \RuntimeException('Unexpected URL: ' . $url);
            });

        $pages = $client->fetchStudentsPages([2, 3], 25);
        $this->assertSame([2, 3], array_keys($pages));
        $this->assertCount(25, $pages[2]['data']);
        $this->assertCount(1, $pages[3]['data']);
        $this->assertSame(2, $page2Attempts);
        $this->assertSame(2, $page3Attempts);
    }

    public function testPeopleSyncEmitsMonotonicPageProgressAndUsesPeopleErrorMessage(): void
    {
        $rows = [];
        for ($i = 0; $i < 55; $i++) {
            $rows[] = ['student_id' => sprintf('2024%04d', $i + 1), 'first_name' => 'Student', 'last_name' => 'Batch'];
        }
        $client = $this->createMock(MisClientInterface::class);
        $client->method('fetchStudentsPage')->willReturnCallback(static function (int $page, int $limit) use ($rows): array {
            return ['data' => array_slice($rows, ($page - 1) * 25, 25), 'current_page' => $page, 'max_page' => 3, 'limit' => $limit];
        });
        $client->method('fetchEmployeesPage')->willReturn(['data' => [], 'current_page' => 1, 'max_page' => 0, 'limit' => 25]);

        $stages = [];
        (new MisPeopleSyncService($client, $this->db))->sync(static function (array $stage) use (&$stages): void {
            $stages[] = $stage;
        });

        $percents = array_column($stages, 'percent');
        $sorted = $percents;
        sort($sorted);
        $this->assertSame($sorted, $percents, 'Progress percentages must be monotonically non-decreasing.');
        $this->assertSame(5, $percents[0]);
        $this->assertContains(40, $percents);
        $this->assertContains(80, $percents);
        $this->assertSame(100, $percents[count($percents) - 1]);
        $this->assertGreaterThan(4, count($stages), 'Multi-page people sync must emit intermediate page progress.');

        // Verify MisSyncController::people() reports a people-specific error message on 503.
        try {
            \Config\Services::injectMock('misClient', new \App\Integrations\Mis\DisabledMisClient());
            $controller = new MisSyncController();
            $request = service('request', null, false);
            $request->setMethod('POST');
            $request->setHeader('Accept', 'application/json');
            $request->setGlobal('post', [csrf_token() => csrf_hash()]);
            $controller->initController($request, service('response', null, false), service('logger'));
            $response = $controller->people();
            $this->assertSame(503, $response->getStatusCode());
            $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('MIS people directory synchronization', $payload['message']);
            $this->assertStringNotContainsString('MIS reference synchronization', $payload['message']);
        } finally {
            \Config\Services::resetSingle('misClient');
            \Config\Services::resetSingle('request');
            \Config\Services::resetSingle('response');
        }
    }

    public function testFloorMaxPageOverflowFetchesTrailingRecordsAndRejectsFullOverflow(): void
    {
        $cache = $this->createMock(\CodeIgniter\Cache\CacheInterface::class);
        $cache->method('get')->with('fumis_service_access_token')->willReturn('fixture-access');
        \Config\Services::injectMock('cache', $cache);

        $students = [];
        for ($i = 1; $i <= 28; $i++) {
            $students[] = ['student_id' => sprintf('2025%04d', $i), 'first_name' => 'Student', 'last_name' => 'Row' . $i];
        }
        $employees = [];
        for ($i = 1; $i <= 25; $i++) {
            $employees[] = ['employee_id' => sprintf('22%04d', $i), 'first_name' => 'Emp', 'last_name' => 'Row' . $i];
        }

        // Case 1: 28 students (max_page=1 via floor(28/25), 3 trailing rows on page 2)
        //       + 25 employees (max_page=1 exact multiple, 0 rows on page 2).
        $client = new \App\Integrations\Mis\HttpMisClient('https://mis.example.test/sandbox', 'fixture-key', 7,
            static function (string $verb, string $url) use ($students, $employees): array {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $page = (int) ($q['page'] ?? 1);
                if (str_contains($url, '/students')) {
                    $slice = array_slice($students, ($page - 1) * 25, 25);
                    return ['status' => 200, 'body' => json_encode([
                        'data' => ['data' => $slice, 'limit' => '25', 'current_page' => (string) $page, 'max_page' => 1],
                    ], JSON_THROW_ON_ERROR)];
                }
                $slice = array_slice($employees, ($page - 1) * 25, 25);
                return ['status' => 200, 'body' => json_encode([
                    'data' => ['data' => $slice, 'limit' => '25', 'current_page' => (string) $page, 'max_page' => 1],
                ], JSON_THROW_ON_ERROR)];
            });

        $result = (new MisPeopleSyncService($client, $this->db))->sync();
        $this->assertSame(28, $result['counts']['student']['inserted']);
        $this->assertSame(25, $result['counts']['employee']['inserted']);
        $this->assertNotNull($this->db->table('people')->where('kind', 'student')->where('mis_id', '20250028')->get()->getRowArray());

        // Case 2: If page (max_page + 1) itself returns a full 25 rows, max_page is off by >1 page -> fail closed.
        $brokenClient = new \App\Integrations\Mis\HttpMisClient('https://mis.example.test/sandbox', 'fixture-key', 7,
            static function (string $verb, string $url): array {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $page = (int) ($q['page'] ?? 1);
                $rows = [];
                for ($i = 1; $i <= 25; $i++) {
                    $rows[] = ['student_id' => sprintf('2026%04d', ($page - 1) * 25 + $i), 'first_name' => 'Over', 'last_name' => 'Full'];
                }
                return ['status' => 200, 'body' => json_encode([
                    'data' => ['data' => $rows, 'limit' => '25', 'current_page' => (string) $page, 'max_page' => 1],
                ], JSON_THROW_ON_ERROR)];
            });

        try {
            (new MisPeopleSyncService($brokenClient, $this->db))->sync();
            $this->fail('Full 25-row overflow page must fail closed.');
        } catch (RuntimeException $error) {
            $this->assertSame(503, $error->getCode());
        }
    }
}

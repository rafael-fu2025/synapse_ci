<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\MisSyncController;
use App\Integrations\Mis\MisClientInterface;
use App\Services\Audit\AuditService;
use App\Services\AuthenticatedUser;
use App\Services\Directory\MisSyncService;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use ReflectionProperty;
use RuntimeException;

/** Mocked MIS boundary and private SQLite only; no migrations, operational DB, or HTTP requests. */
final class MisSyncAuditTest extends CIUnitTestCase
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

    private function catalogs(): array
    {
        return [
            'departments' => [['code' => 'CCS', 'name' => 'Computer Studies', 'api_key' => 'must-not-be-stored']],
            'programs' => [['code' => 'BSIT', 'name' => 'Information Technology', 'department_code' => 'CCS']],
            'year_levels' => [['code' => '1', 'name' => 'First Year']],
        ];
    }

    private function client(?array $catalogs = null): MisClientInterface
    {
        $catalogs ??= $this->catalogs();
        $client = $this->createMock(MisClientInterface::class);
        foreach (['studentLogin', 'employeeLogin', 'searchStudents', 'searchEmployees'] as $method) {
            $client->expects($this->never())->method($method);
        }
        $client->method('fetchDepartments')->willReturn($catalogs['departments']);
        $client->method('fetchPrograms')->willReturn($catalogs['programs']);
        $client->method('fetchYearLevels')->willReturn($catalogs['year_levels']);
        return $client;
    }

    private function rejected(callable $operation, int $code): void
    {
        try { $operation(); $this->fail('Operation must fail closed.'); }
        catch (RuntimeException $error) {
            $this->assertSame($code, $error->getCode());
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('must-not-be-stored', $error->getMessage());
        }
    }

    private function references(): array
    {
        $rows = [];
        foreach (['mis_departments', 'mis_programs', 'mis_year_levels', 'audit_events'] as $table) {
            $rows[$table] = $this->db->table($table)->orderBy('id')->get()->getResultArray();
        }
        return $rows;
    }

    public function testUpsertsPreserveIdsAndOtherTenantsAndAuditOnlyCounts(): void
    {
        $this->db->table('mis_departments')->insertBatch([
            ['id' => 10, 'tenant_id' => 1, 'code' => 'CCS', 'name' => 'Old name', 'synced_at' => '2000-01-01 00:00:00'],
            ['id' => 11, 'tenant_id' => 2, 'code' => 'CCS', 'name' => 'Other tenant', 'synced_at' => '2000-01-01 00:00:00'],
            ['id' => 12, 'tenant_id' => 1, 'code' => 'RETAIN', 'name' => 'Not returned', 'synced_at' => '2000-01-01 00:00:00'],
        ]);
        $untouched = [];
        foreach (['users', 'people', 'employee_profiles', 'role_permissions'] as $table) {
            $untouched[$table] = $this->db->table($table)->get()->getResultArray();
        }
        $service = new MisSyncService($this->client(), $this->db);
        $result = $service->sync();
        $this->assertSame(['inserted' => 0, 'updated' => 1, 'unchanged' => 0], $result['counts']['departments']);
        $rows = $this->references();
        $this->assertSame(10, $rows['mis_departments'][0]['id']);
        $this->assertSame('Computer Studies', $rows['mis_departments'][0]['name']);
        $this->assertSame('Other tenant', $rows['mis_departments'][1]['name']);
        $this->assertSame('2000-01-01 00:00:00', $rows['mis_departments'][1]['synced_at']);
        $this->assertSame('Not returned', $rows['mis_departments'][2]['name']);
        $audit = $rows['audit_events'][0];
        $this->assertSame(1, $audit['tenant_id']);
        $this->assertSame(7, $audit['actor_user_id']);
        $this->assertSame('clinic_admin', $audit['actor_role']);
        $payload = json_decode($audit['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($result['counts'], $payload['counts']);
        $this->assertSame('references_only', $payload['scope']);
        $this->assertStringNotContainsString('must-not-be-stored', $audit['payload_json']);
        $this->assertStringNotContainsString('Computer Studies', $audit['payload_json']);
        $ids = array_column($rows['mis_programs'], 'id');
        $this->assertSame(1, $service->sync()['counts']['programs']['unchanged']);
        $this->assertSame($ids, array_column($this->references()['mis_programs'], 'id'));
        $this->assertTrue((new AuditService(null, $this->db))->verifyChain(1)['valid']);
        foreach ($untouched as $table => $expected) {
            $this->assertSame($expected, $this->db->table($table)->get()->getResultArray());
        }
    }

    public function testProgressFollowsActualStagesAndCompletesOnlyAfterCommit(): void
    {
        $trace = [];
        $observations = [];
        $client = $this->createMock(MisClientInterface::class);
        foreach (['fetchDepartments' => 'departments', 'fetchPrograms' => 'programs', 'fetchYearLevels' => 'year_levels'] as $method => $catalog) {
            $client->expects($this->once())->method($method)->willReturnCallback(function () use (&$trace, $catalog) {
                $trace[] = $catalog;
                return $this->catalogs()[$catalog];
            });
        }
        (new MisSyncService($client, $this->db))->sync(function (array $stage) use (&$trace, &$observations) {
            $trace[] = $stage['percent'];
            $observations[] = ['stage' => $stage, 'depth' => $this->db->transDepth,
                'rows' => $this->references()];
        });
        $this->assertSame([5, 'departments', 25, 'programs', 45, 'year_levels', 65, 85, 100], $trace);
        $this->assertSame([
            ['percent' => 5, 'message' => 'Fetching all departments.'],
            ['percent' => 25, 'message' => 'Departments validated. Fetching programs.'],
            ['percent' => 45, 'message' => 'Fetching year levels.'],
            ['percent' => 65, 'message' => 'Validating catalog relationships.'],
            ['percent' => 85, 'message' => 'Saving reference catalogs and auditing synchronization.'],
            ['percent' => 100, 'message' => 'MIS reference catalogs synchronized.'],
        ], array_column($observations, 'stage'));
        $this->assertSame([0, 0, 0, 0, 0, 0], array_column($observations, 'depth'));
        foreach (array_slice($observations, 0, 5) as $observation) {
            foreach ($observation['rows'] as $rows) { $this->assertSame([], $rows); }
        }
        foreach ($observations[5]['rows'] as $rows) { $this->assertCount(1, $rows); }
        $this->assertTrue((new AuditService(null, $this->db))->verifyChain(1)['valid']);
    }

    public function testObserverExceptionsNeverInterruptOrRollBackSynchronization(): void
    {
        $percentages = [];
        $depths = [];
        $result = (new MisSyncService($this->client(), $this->db))->sync(function (array $stage) use (&$percentages, &$depths) {
            $percentages[] = $stage['percent'];
            $depths[] = $this->db->transDepth;
            throw new RuntimeException('Observer unavailable must-not-be-stored');
        });
        $this->assertSame([5, 25, 45, 65, 85, 100], $percentages);
        $this->assertSame([0, 0, 0, 0, 0, 0], $depths);
        $this->assertSame(1, $result['counts']['departments']['inserted']);
        $this->assertCount(1, $this->references()['audit_events']);
        $this->assertTrue((new AuditService(null, $this->db))->verifyChain(1)['valid']);
    }

    public function testAllAllowedRolesStillRequireFreshExplicitPermission(): void
    {
        foreach (['superadmin', 'clinic_admin', 'guidance_admin'] as $role) {
            $this->db->table('users')->where('id', 7)->update(['role' => $role]);
            $this->assertSame($role, (new MisSyncService($this->client(), $this->db))->actor()['role']);
            $this->db->table('role_permissions')->where('role', $role)->delete();
            $client = $this->createMock(MisClientInterface::class);
            $client->expects($this->never())->method('fetchDepartments');
            $this->rejected(fn () => (new MisSyncService($client, $this->db))->sync(), 403);
            $this->db->table('role_permissions')->insert(['role' => $role, 'permission_id' => 1]);
        }
        $this->db->table('users')->where('id', 7)->update(['role' => 'employee']);
        $this->rejected(fn () => (new MisSyncService($this->client(), $this->db))->sync(), 403);
        $this->assertSame([], $this->references()['audit_events']);
    }

    public function testInvalidTenantOrInactiveIdentityCannotReadOrSync(): void
    {
        $service = new MisSyncService($this->client(), $this->db);
        $this->signIn(2);
        $this->rejected(fn () => $service->status(), 401);
        $this->signIn();
        $this->db->table('users')->where('id', 7)->update(['status' => 'inactive']);
        $this->rejected(fn () => $service->sync(), 401);
        $this->signIn();
        $this->db->table('users')->where('id', 7)->update(['status' => 'active']);
        $this->db->table('tenants')->where('id', 1)->update(['is_active' => 0]);
        $this->rejected(fn () => $service->status(), 401);
    }

    public function testStatusNeverFetchesAndIsTenantScoped(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        foreach (['fetchDepartments', 'fetchPrograms', 'fetchYearLevels'] as $method) {
            $client->expects($this->never())->method($method);
        }
        $before = $this->references();
        $status = (new MisSyncService($client, $this->db))->status();
        $this->assertSame(0, $status['catalogs']['departments']['total']);
        $this->assertNull($status['lastSync']);
        $this->assertSame($before, $this->references());
    }

    public function testIncompleteOrMalformedCatalogsNeverWrite(): void
    {
        $cases = [];
        foreach (['departments', 'programs', 'year_levels'] as $catalog) {
            $case = $this->catalogs(); $case[$catalog] = []; $cases[] = $case;
        }
        foreach ([
            [['code' => 'CCS']], [['code' => 12, 'name' => 'Invalid']],
            [['code' => 'CCS', 'name' => str_repeat('x', 151)]],
            [['code' => 'CCS', 'name' => "invalid\nname"]],
            [['code' => 'CCS', 'name' => 'One'], ['code' => 'ccs', 'name' => 'Duplicate']],
            ['data' => [['code' => 'CCS', 'name' => 'Envelope not a list']]],
        ] as $rows) {
            $case = $this->catalogs(); $case['departments'] = $rows; $cases[] = $case;
        }
        $case = $this->catalogs(); $case['programs'][0]['department_code'] = 'UNKNOWN'; $cases[] = $case;
        $case = $this->catalogs(); $case['programs'][0]['department_code'] = null; $cases[] = $case;
        foreach ($cases as $case) {
            $before = $this->references();
            $this->rejected(fn () => (new MisSyncService($this->client($case), $this->db))->sync(), 503);
            $this->assertSame($before, $this->references());
        }
    }

    public function testTransportFailureDoesNotExposeSecretsOrPartiallyWrite(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->method('fetchDepartments')->willReturn($this->catalogs()['departments']);
        $client->method('fetchPrograms')->willThrowException(new RuntimeException('API-Key: must-not-be-stored raw payload'));
        $before = $this->references();
        $stages = [];
        $progress = function (array $stage) use (&$stages) { $stages[] = [$stage['percent'], $this->db->transDepth]; };
        $this->rejected(fn () => (new MisSyncService($client, $this->db))->sync($progress), 503);
        $this->assertSame([[5, 0], [25, 0]], $stages);
        $this->assertSame($before, $this->references());
    }

    public function testRevocationDuringFetchIsRecheckedBeforeWrites(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->method('fetchDepartments')->willReturn($this->catalogs()['departments']);
        $client->method('fetchPrograms')->willReturn($this->catalogs()['programs']);
        $client->method('fetchYearLevels')->willReturnCallback(function () {
            $this->db->table('role_permissions')->where('role', 'clinic_admin')->delete();
            return $this->catalogs()['year_levels'];
        });
        $before = $this->references();
        $this->rejected(fn () => (new MisSyncService($client, $this->db))->sync(), 403);
        $this->assertSame($before, $this->references());
    }

    public function testAuditFailureRollsBackEveryCatalogAndExistingUpdate(): void
    {
        $this->db->table('mis_departments')->insert(['tenant_id' => 1, 'code' => 'CCS', 'name' => 'Original', 'synced_at' => '2000-01-01 00:00:00']);
        $this->db->query("CREATE TRIGGER reject_sync_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'must-not-be-stored'); END");
        $before = $this->references();
        $stages = [];
        $progress = function (array $stage) use (&$stages) { $stages[] = [$stage['percent'], $this->db->transDepth]; };
        $this->rejected(fn () => (new MisSyncService($this->client(), $this->db))->sync($progress), 503);
        $this->assertSame([[5, 0], [25, 0], [45, 0], [65, 0], [85, 0]], $stages);
        $this->assertSame($before, $this->references());
        $this->assertSame(0, $this->db->transDepth);
    }

    private function controller(string $method): MisSyncController
    {
        $request = service('request');
        $request->setMethod($method);
        $request->removeHeader('Accept');
        $request->removeHeader('X-Requested-With');
        $request->setGlobal('post', []);
        $request->setBody('');
        $controller = new MisSyncController();
        $controller->initController($request, service('response')->setStatusCode(200), service('logger'));
        return $controller;
    }

    public function testControllerRejectsWrongVerbsAndMissingCsrfWithoutSyncing(): void
    {
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $response = $this->controller($method)->sync();
            $this->assertSame(405, $response->getStatusCode());
            $this->assertSame('POST', $response->getHeaderLine('Allow'));
        }
        $this->assertSame(405, $this->controller('POST')->index()->getStatusCode());
        $this->assertSame(403, $this->controller('POST')->sync()->getStatusCode());
        $this->assertSame([], $this->references()['audit_events']);
    }

    public function testCatalogWriteFailureRollsBackEarlierCatalogs(): void
    {
        $this->db->query("CREATE TRIGGER reject_program BEFORE INSERT ON mis_programs BEGIN SELECT RAISE(ABORT, 'write failed'); END");
        $before = $this->references();
        $this->rejected(fn () => (new MisSyncService($this->client(), $this->db))->sync(), 503);
        $this->assertSame($before, $this->references());
    }

    public function testDisabledAdapterFailsClosedAndOptionalDepartmentIsSupported(): void
    {
        $this->rejected(fn () => (new MisSyncService(new \App\Integrations\Mis\DisabledMisClient(), $this->db))->sync(), 503);
        $this->assertSame([], $this->references()['mis_departments']);
        $catalogs = $this->catalogs();
        unset($catalogs['programs'][0]['department_code']);
        $service = new MisSyncService($this->client($catalogs), $this->db);
        $service->sync();
        $this->assertNull($this->references()['mis_programs'][0]['department_code']);
        $status = $service->status();
        $this->assertSame(1, $status['catalogs']['programs']['total']);
        $this->assertSame(7, $status['lastSync']['actor_user_id']);
    }

    public function testControllerAcceptsCsrfProtectedPostWithMockedMisAndFreshAuthority(): void
    {
        \Config\Services::injectMock('misClient', $this->client());
        $controller = $this->controller('POST');
        $request = service('request');
        $request->setHeader('Accept', 'application/json');
        $request->setGlobal('post', [csrf_token() => csrf_hash()]);
        $response = $controller->sync();
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $body = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($body['success']);
        $this->assertSame(1, $body['result']['counts']['programs']['inserted']);
        $this->assertArrayHasKey('csrf', $body);
        $this->assertSame(1, count($this->references()['audit_events']));
    }

    public function testIndexJsonIsReadOnlyLocalTenantScopedAndContainsNoActor(): void
    {
        (new MisSyncService($this->client(), $this->db))->sync();
        $this->db->table('mis_departments')->insert(['tenant_id' => 2, 'code' => 'OTHER',
            'name' => 'Other tenant', 'synced_at' => '2000-01-01 00:00:00']);
        $client = $this->createMock(MisClientInterface::class);
        foreach (['fetchDepartments', 'fetchPrograms', 'fetchYearLevels'] as $method) {
            $client->expects($this->never())->method($method);
        }
        \Config\Services::injectMock('misClient', $client);
        $before = $this->references();
        $controller = $this->controller('GET');
        service('request')->setHeader('Accept', 'application/json');
        $response = $controller->index();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $body = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $status = (new MisSyncService(null, $this->db))->status();
        $this->assertSame(['catalogs' => $status['catalogs'],
            'lastSync' => ['occurred_at' => $status['lastSync']['occurred_at']]], $body);
        $this->assertSame(1, $body['catalogs']['departments']['total']);
        $this->assertSame($before, $this->references());
    }

    public function testInvalidProgressIdsAreRejectedWithSafeCsrfJsonBeforeAnyMisOrWrites(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        foreach (['fetchDepartments', 'fetchPrograms', 'fetchYearLevels'] as $method) {
            $client->expects($this->never())->method($method);
        }
        \Config\Services::injectMock('misClient', $client);
        $before = $this->references();
        foreach (['short', str_repeat('x', 65), str_repeat('x', 16) . "\n", 'must-not-be-stored!', ['nested'], 123] as $id) {
            $controller = $this->controller('POST');
            service('request')->setGlobal('post', [csrf_token() => csrf_hash(), 'progress_id' => $id]);
            $response = $controller->sync();
            $this->assertSame(400, $response->getStatusCode(), json_encode($id) . ': ' . $response->getBody());
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            $body = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['success' => false, 'message' => 'Invalid synchronization progress ID.',
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]], $body);
            $this->assertSame($before, $this->references());
        }
    }

    public function testValidProgressIdsWorkWithDisabledRealtimeWithoutMutatingSharedConfig(): void
    {
        $config = config(\Config\Realtime::class);
        $enabled = $config->enabled;
        $timeout = $config->publishTimeout;
        $config->enabled = false;
        try {
            foreach ([str_repeat('a', 16), str_repeat('Z_-9', 16)] as $id) {
                \Config\Services::injectMock('misClient', $this->client());
                $controller = $this->controller('POST');
                service('request')->setHeader('Accept', 'application/json');
                service('request')->setGlobal('post', [csrf_token() => csrf_hash(), 'progress_id' => $id]);
                $response = $controller->sync();
                $this->assertSame(200, $response->getStatusCode(), $response->getBody());
                $body = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(['success', 'message', 'result', 'csrf'], array_keys($body));
                $this->assertTrue($body['success']);
                $this->assertSame($timeout, $config->publishTimeout);
            }
            $this->assertCount(2, $this->references()['audit_events']);
        } finally {
            $config->enabled = $enabled;
        }
    }

    public function testViewLabelsReferenceOnlyAndEscapesStatusAndIncludesPostCsrfForm(): void
    {
        helper('form');
        $renderer = new class {
            public function extend(string $layout): string { return ''; }
            public function section(string $name): string { return ''; }
            public function endSection(): string { return ''; }
            public function render(array $data): string
            {
                extract($data);
                ob_start();
                try { include APPPATH . 'Views/admin/mis_sync.php'; return ob_get_contents(); }
                finally { ob_end_clean(); }
            }
        };
        $data = (new MisSyncService($this->client(), $this->db))->status();
        $data['lastSync'] = ['occurred_at' => '<script>unsafe</script>', 'actor_user_id' => '7'];
        $html = $renderer->render($data);
        $this->assertStringContainsString('MIS reference synchronization', $html);
        $this->assertStringContainsString('not employee or student directory synchronization', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString(esc(csrf_token()), $html);
        $this->assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>unsafe</script>', $html);
    }
}

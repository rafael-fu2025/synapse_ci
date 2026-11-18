<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\TablePage;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceClientDirectoryService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/** Run only with the no-dotenv guidance-read bootstrap; all rows are synthetic SQLite. */
final class GuidanceClientDirectoryTest extends TestCase
{
    private ?BaseConnection $fixture = null;
    private array $savedConnections = [];
    private ?int $savedTenant = null;
    private ?array $identity = ['id' => 1, 'tenant_id' => 1];
    private GuidanceClientDirectoryService $service;
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $bootstrap = realpath(dirname(__DIR__) . '/_support/guidance-read-bootstrap.php');
        if (!defined('ENVIRONMENT') || ENVIRONMENT !== 'testing'
            || !in_array($bootstrap, array_map('realpath', get_included_files()), true)) {
            $this->markTestSkipped('The no-dotenv, private SQLite guidance-read bootstrap is required.');
        }
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('Private SQLite fixtures require sqlite3.');
        }
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->savedConnections = $instances->getValue();
        $this->savedTenant = (new ReflectionProperty(CurrentTenant::class, 'id'))->getValue();
        $this->fixture = Database::connect([
            'DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => 'client_directory_', 'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        self::assertSame('SQLite3', $this->fixture->DBDriver);
        self::assertSame(':memory:', $this->fixture->database);
        $instances->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        self::assertSame($this->fixture, Database::connect());
        CurrentTenant::set(1);
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, mis_id TEXT, first_name TEXT, middle_name TEXT, last_name TEXT, email TEXT, phone TEXT, date_of_birth TEXT, address TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, password_hash TEXT, deleted_at TEXT',
            'student_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, program TEXT, year_level INTEGER, section TEXT, department TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, department TEXT, position TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER, PRIMARY KEY(role, permission_id)',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $this->fixture->prefixTable($table) . ' (' . $columns . ')');
        }
        $this->seed();
        $policy = new GuidanceAccessPolicy(identitySource: fn (): ?array => $this->identity, db: $this->fixture);
        $this->service = new GuidanceClientDirectoryService(db: $this->fixture, accessPolicy: $policy);
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->fixture->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))
                ->setValue(null, $this->savedConnections);
            CurrentTenant::set($this->savedTenant);
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): void
    {
        self::assertTrue($this->fixture->table($table)->insert($data));
    }

    private function person(int $id, array $overrides = []): void
    {
        $this->insert('people', array_replace([
            'id' => $id, 'tenant_id' => 1, 'kind' => 'student', 'mis_id' => 'SYNTH-' . $id,
            'first_name' => 'Synthetic', 'middle_name' => null, 'last_name' => 'Person ' . $id,
            'email' => 'SYNTHETIC-PRIVATE-EMAIL', 'phone' => 'SYNTHETIC-PRIVATE-PHONE',
            'address' => 'SYNTHETIC-PRIVATE-ADDRESS', 'date_of_birth' => '2000-01-01',
            'deleted_at' => null,
        ], $overrides));
    }

    private function seed(): void
    {
        foreach ([1, 2] as $id) $this->insert('tenants', ['id' => $id, 'is_active' => 1]);
        $this->person(100, ['kind' => 'employee', 'last_name' => 'ZCounselor']);
        $this->insert('users', ['id' => 1, 'tenant_id' => 1, 'person_id' => 100,
            'username' => 'synthetic-counselor', 'role' => 'counsellor', 'status' => 'active',
            'password_hash' => 'SYNTHETIC-PRIVATE-HASH']);
        $this->insert('permissions', ['id' => 1, 'code' => 'guidance.cases.read']);
        $this->insert('permissions', ['id' => 2, 'code' => 'guidance.cases.read_any']);
        $this->insert('role_permissions', ['role' => 'counsellor', 'permission_id' => 1]);
        $this->person(10, ['mis_id' => 'FU_100%', 'first_name' => 'Ana', 'middle_name' => 'Marie', 'last_name' => 'Alpha']);
        $this->person(20, ['kind' => 'employee', 'mis_id' => 'EMP_20', 'first_name' => 'Juan', 'last_name' => 'Beta']);
        $this->person(30, ['kind' => 'guest', 'first_name' => 'Excluded', 'last_name' => 'Guest']);
        $this->person(31, ['kind' => 'external', 'first_name' => 'Excluded', 'last_name' => 'External']);
        $this->person(40, ['tenant_id' => 2, 'mis_id' => 'FOREIGN-40']);
        $this->person(50, ['deleted_at' => '2026-01-01']);
        $this->person(60, ['mis_id' => 'NO_LOGIN_60', 'first_name' => 'No', 'last_name' => 'Login']);
        $this->person(61, ['kind' => 'employee', 'mis_id' => 'NO_LOGIN_61', 'first_name' => 'No', 'last_name' => 'LoginEmployee']);
        $this->person(62, ['mis_id' => 'INACTIVE-62', 'first_name' => 'Client', 'last_name' => 'InactiveLogin']);
        $this->insert('users', ['id' => 2, 'tenant_id' => 1, 'person_id' => 62,
            'username' => 'inactive-student', 'role' => 'student', 'status' => 'inactive']);
        $this->person(63, ['mis_id' => 'DELETED-ACCOUNT-63', 'first_name' => 'Client', 'last_name' => 'DeletedLogin']);
        $this->insert('users', ['id' => 3, 'tenant_id' => 1, 'person_id' => 63,
            'username' => 'deleted-student', 'role' => 'student', 'status' => 'active', 'deleted_at' => '2026-01-01']);
        $this->insert('student_profiles', ['id' => 1, 'tenant_id' => 1, 'person_id' => 10,
            'program' => 'Synthetic BS', 'year_level' => 2]);
        $this->insert('student_profiles', ['id' => 2, 'tenant_id' => 2, 'person_id' => 10,
            'program' => 'SYNTHETIC-FOREIGN-PROFILE', 'year_level' => 9]);
        $this->insert('employee_profiles', ['id' => 1, 'tenant_id' => 1, 'person_id' => 20,
            'department' => 'Synthetic Teaching', 'position' => 'SYNTHETIC-PRIVATE-POSITION']);
        $this->insert('employee_profiles', ['id' => 2, 'tenant_id' => 2, 'person_id' => 20,
            'department' => 'SYNTHETIC-FOREIGN-PROFILE']);
    }

    private function list(array $query = [], ?array $actor = null): array
    {
        $page = TablePage::fromQuery($query, ['all'], 'Guidance client');
        $this->queries = [];
        $before = $this->fixture->query('SELECT total_changes() AS changes')->getRowArray()['changes'];
        $listener = function ($sql): void { $this->queries[] = (string) $sql; };
        $this->fixture->query('PRAGMA query_only = ON');
        Events::on('DBQuery', $listener);
        try {
            $result = $this->service->paginateClients($page, $actor);
            foreach ($result['clients'] as $client) {
                self::assertSame(['id', 'kind', 'mis_id', 'first_name', 'middle_name', 'last_name', 'program', 'year_level', 'department'], array_keys($client));
            }
            self::assertStringNotContainsString('SYNTHETIC-PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('SYNTHETIC-FOREIGN', json_encode($result, JSON_THROW_ON_ERROR));
            return $result;
        } finally {
            Events::removeListener('DBQuery', $listener);
            $this->fixture->query('PRAGMA query_only = OFF');
            self::assertSame($before, $this->fixture->query('SELECT total_changes() AS changes')->getRowArray()['changes']);
            self::assertSame(0, $this->fixture->transDepth);
            foreach ($this->queries as $sql) {
                self::assertMatchesRegularExpression('/^\s*select\b/i', $sql);
                foreach (['guidance_form_', 'counselling_', 'guidance_intake_', 'guidance_mi_', 'audit_events'] as $table) {
                    self::assertStringNotContainsString($table, strtolower($sql));
                }
            }
        }
    }

    private function ids(array $result): array
    {
        return array_map('intval', array_column($result['clients'], 'id'));
    }

    private function denied(array $query, int $code, ?array $actor = null): void
    {
        try {
            $this->list($query, $actor);
        } catch (DomainException | InvalidArgumentException | RuntimeException $error) {
            self::assertSame($code, $error->getCode(), $error->getMessage());
            return;
        }
        self::fail('Expected directory read to be rejected.');
    }

    public function testStudentAndEmployeeDirectoryUsesMinimalProjectionWithoutLoginEligibility(): void
    {
        $result = $this->list();
        self::assertSame([10, 20, 63, 62, 60, 61, 100], $this->ids($result));
        self::assertSame(7, $result['pagination']['total']);
        self::assertSame(7, $result['pagination']['filtered']);
        self::assertSame('Synthetic BS', $result['clients'][0]['program']);
        self::assertSame(2, (int) $result['clients'][0]['year_level']);
        self::assertSame('Synthetic Teaching', $result['clients'][1]['department']);
        self::assertNull($result['clients'][4]['program']);
        self::assertNull($result['clients'][5]['department']);
        self::assertSame(['q' => '', 'kind' => 'all', 'per_page' => 25], $result['filters']);
    }

    public function testKindFiltersHaveIndependentFilteredCountAndNoGuestRows(): void
    {
        $students = $this->list(['kind' => 'student']);
        self::assertSame([10, 63, 62, 60], $this->ids($students));
        self::assertSame(7, $students['pagination']['total']);
        self::assertSame(4, $students['pagination']['filtered']);
        $employees = $this->list(['kind' => 'employee']);
        self::assertSame([20, 61, 100], $this->ids($employees));
        self::assertSame(3, $employees['pagination']['filtered']);
        $this->denied(['kind' => 'guest'], 422);
    }

    public function testLiteralIdentifierAndNameSearchDoesNotPromoteWildcardsToPatterns(): void
    {
        $this->person(71, ['mis_id' => 'FUx1009', 'last_name' => 'PatternDecoy']);
        $this->person(72, ['mis_id' => 'PLAIN-72', 'last_name' => 'A_B%Name']);
        $this->person(73, ['mis_id' => 'PLAIN-73', 'last_name' => 'AxBzName']);
        self::assertSame([10], $this->ids($this->list(['q' => 'FU_100%'])));
        self::assertSame([10], $this->ids($this->list(['q' => '  Ana Alpha  '])));
        self::assertSame([10], $this->ids($this->list(['q' => 'Alpha Ana'])));
        self::assertSame([10], $this->ids($this->list(['q' => 'Marie Ana'])));
        self::assertSame([72], $this->ids($this->list(['q' => 'A_B%'])));
        self::assertSame([72, 10], $this->ids($this->list(['q' => '%'])));
        self::assertSame([], $this->ids($this->list(['q' => "' OR 1=1 --"])));
    }

    public function testPaginationClampsAfterFilterAndKeepsStableClientOrdering(): void
    {
        for ($id = 1000; $id < 1060; ++$id) {
            $this->person($id, ['kind' => 'employee', 'mis_id' => 'PAGE-' . $id,
                'first_name' => 'Synthetic', 'last_name' => 'Paging']);
        }
        $first = $this->list(['q' => 'PAGE-', 'page' => '1']);
        self::assertSame(range(1000, 1024), $this->ids($first));
        $last = $this->list(['q' => 'PAGE-', 'page' => '999']);
        self::assertSame(range(1050, 1059), $this->ids($last));
        self::assertSame(['page' => 3, 'per_page' => 25, 'total' => 67, 'filtered' => 60,
            'pages' => 3, 'from' => 51, 'to' => 60], $last['pagination']);
        $empty = $this->list(['q' => 'never-present', 'page' => '999']);
        self::assertSame([], $empty['clients']);
        self::assertSame(1, $empty['pagination']['page']);
        self::assertSame(0, $empty['pagination']['from']);
        self::assertSame(0, $empty['pagination']['to']);
    }

    public function testFreshReadGrantIsRequiredAndForgedHintsDoNotGrantAuthority(): void
    {
        $forged = ['id' => 1, 'tenant_id' => 1, 'role' => 'superadmin',
            'permissions' => ['guidance.cases.read', 'guidance.cases.read_any']];
        self::assertCount(7, $this->list([], $forged)['clients']);
        $this->insert('role_permissions', ['role' => 'counsellor', 'permission_id' => 2]);
        $this->fixture->table('role_permissions')->where('role', 'counsellor')->where('permission_id', 1)->delete();
        $this->denied([], 403, $forged);
    }

    public function testTransportIdentityAndTenantBoundaryAreMandatory(): void
    {
        $this->denied([], 403, ['id' => 2, 'tenant_id' => 1]);
        $this->denied([], 403, ['id' => 1, 'tenant_id' => 2]);
        CurrentTenant::set(2);
        $this->denied([], 403);
        CurrentTenant::set(1);
        $this->identity = null;
        $this->denied([], 401, ['id' => 1, 'tenant_id' => 1]);
    }

    public static function invalidQueries(): iterable
    {
        yield 'query array' => [['q' => ['id']]];
        yield 'invalid utf8' => [['q' => "\xFF"]];
        yield 'query length' => [['q' => str_repeat('x', 151)]];
        yield 'page zero' => [['page' => 0]];
        yield 'page array' => [['page' => ['1']]];
        yield 'page range' => [['page' => 1000001]];
        yield 'per page' => [['per_page' => 30]];
        yield 'kind array' => [['kind' => ['student']]];
        yield 'external kind' => [['kind' => 'external']];
        yield 'status' => [['status' => 'active']];
        yield 'fragment' => [['fragment' => '2']];
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidDirectoryQueryIsRejected(array $query): void
    {
        $this->denied($query, 422);
    }

    public function testDisabledOrWrongDepartmentAccountCannotReadDirectory(): void
    {
        $this->fixture->table('users')->where('id', 1)->update(['status' => 'inactive']);
        $this->denied([], 403);
        $this->fixture->table('users')->where('id', 1)->update(['status' => 'active', 'role' => 'clinic_staff']);
        $this->denied([], 403);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use CodeIgniter\Database\SQLite3\Connection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionProperty;

/** Real routes and aggregate SQL, isolated from migrations and any external database. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AppointmentCalendarCountsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = ['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'badge_', 'DBDebug' => true];
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = config('Database')->default = $settings;
        $this->db = new Connection($settings);
        (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))
            ->setValue(null, ['tests' => $this->db, 'default' => $this->db]);
        $this->db->initialize();
        $this->assertSame(':memory:', Database::connect()->database);
        $this->assertSame($this->db, Database::connect('default'));
        $tables = [
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, first_name TEXT, last_name TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'employee_profiles' => 'tenant_id INTEGER, person_id INTEGER, is_teaching INTEGER',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'appointments' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, person_id INTEGER, unit TEXT, local_date TEXT, status TEXT, deleted_at TEXT',
        ];
        foreach ($tables as $table => $columns) {
            $this->db->query('CREATE TABLE ' . $this->db->prefixTable($table) . ' (' . $columns . ')');
        }
        $this->db->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        foreach ([1 => 'clinic_staff', 2 => 'counsellor', 3 => 'student', 4 => 'clinic_staff'] as $id => $role) {
            $tenant = $id === 4 ? 2 : 1;
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => $tenant, 'kind' => 'employee',
                'first_name' => 'Private', 'last_name' => 'Patient']);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id,
                'username' => 'badge_' . $id, 'role' => $role, 'status' => 'active']);
        }
        foreach ([1 => ['clinic.appointments.read', 'clinic_staff'], 2 => ['guidance.schedule.read', 'counsellor']] as $id => [$code, $role]) {
            $this->db->table('permissions')->insert(['id' => $id, 'code' => $code]);
            $this->db->table('role_permissions')->insert(['role' => $role, 'permission_id' => $id]);
        }
        AuthenticatedUser::clear();
        CurrentTenant::set(1);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function booking(string $unit, string $date, string $status = 'confirmed', int $tenant = 1, int $person = 3, ?string $deleted = null): void
    {
        $this->db->table('appointments')->insert(['tenant_id' => $tenant, 'person_id' => $person,
            'unit' => $unit, 'local_date' => $date, 'status' => $status, 'deleted_at' => $deleted]);
    }

    private function fetch(string $unit, array $query, ?int $actor)
    {
        Services::resetSingle('response');
        $session = [];
        if ($actor !== null) {
            $user = $this->db->table('users')->where('id', $actor)->get()->getRowArray();
            $session = ['user' => $user + ['logged_in' => true], 'tenant_id' => (int) $user['tenant_id'],
                '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]];
        }
        return $this->withSession($session)->withHeaders(['Accept' => 'application/json'])
            ->get($unit . '/appointments/calendar-counts?' . http_build_query($query));
    }

    private function assertPrivate($result): void
    {
        $directives = array_map('trim', explode(',', $result->response()->getHeaderLine('Cache-Control')));
        $this->assertContains('private', $directives);
        $this->assertContains('no-store', $directives);
        $this->assertNotContains('public', $directives);
    }

    public static function units(): iterable
    {
        yield 'clinic' => ['clinic', 1];
        yield 'guidance' => ['guidance', 2];
    }

    #[DataProvider('units')]
    public function testCountsStatusesInclusiveBoundsAndListScopes(string $unit, int $actor): void
    {
        foreach (['confirmed', 'scheduled', 'requested', 'cancelled', 'no_show', 'completed', 'checked_in'] as $status) {
            $this->booking($unit, '2026-10-01', $status);
        }
        $this->booking($unit, '2026-11-11'); // 42nd date, inclusive.
        $this->booking($unit, '2026-09-30');
        $this->booking($unit, '2026-11-12');
        $this->booking($unit, '2026-10-01', 'confirmed', 2, 4);
        $this->booking($unit, '2026-10-01', 'confirmed', 1, 4); // Wrong-tenant person.
        $this->booking($unit, '2026-10-01', 'confirmed', 1, 999); // Missing person.
        $this->booking($unit, '2026-10-01', 'confirmed', 1, 3, '2026-10-01 00:00:00');
        $this->booking($unit === 'clinic' ? 'guidance' : 'clinic', '2026-10-01');
        // Staff lists join people without a people.deleted_at predicate; preserve that scope.
        $this->db->table('people')->where('id', 3)->update(['deleted_at' => '2026-09-01 00:00:00']);
        $result = $this->fetch($unit, ['from' => '2026-10-01', 'to' => '2026-11-11'], $actor);
        $result->assertStatus(200);
        $this->assertSame(['success' => true, 'data' => ['counts' => ['2026-10-01' => 2, '2026-11-11' => 1]]],
            json_decode($result->getJSON(), true));
        $this->assertPrivate($result);
    }

    #[DataProvider('units')]
    public function testEmptyCountsRemainAnObjectAndSingleDayIsValid(string $unit, int $actor): void
    {
        $result = $this->fetch($unit, ['from' => '2028-02-29', 'to' => '2028-02-29'], $actor);
        $result->assertStatus(200);
        $body = json_decode($result->getJSON());
        $this->assertTrue($body->success);
        $this->assertInstanceOf(\stdClass::class, $body->data->counts);
        $this->assertSame([], get_object_vars($body->data->counts));
        $this->assertPrivate($result);
    }

    public static function invalidRanges(): iterable
    {
        yield 'missing both' => [[]];
        yield 'missing to' => [['from' => '2026-10-01']];
        yield 'missing from' => [['to' => '2026-10-01']];
        yield 'from array' => [['from' => ['2026-10-01'], 'to' => '2026-10-02']];
        yield 'nested to array' => [['from' => '2026-10-01', 'to' => ['date' => ['2026-10-02']]]];
        yield 'impossible date' => [['from' => '2026-02-29', 'to' => '2026-03-01']];
        yield 'non ISO' => [['from' => '2026-1-01', 'to' => '2026-01-02']];
        yield 'timestamp' => [['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02']];
        yield 'whitespace' => [['from' => '2026-10-01 ', 'to' => '2026-10-02']];
        yield 'reversed' => [['from' => '2026-10-02', 'to' => '2026-10-01']];
        yield '43 dates' => [['from' => '2026-10-01', 'to' => '2026-11-12']];
        yield 'huge range' => [['from' => '0001-01-01', 'to' => '9999-12-31']];
        yield 'SQL input' => [['from' => "2026-10-01' OR 1=1", 'to' => '2026-10-02']];
    }

    #[DataProvider('invalidRanges')]
    public function testInvalidRangesNeverQueryAppointments(array $query): void
    {
        // Any accidental aggregate (bounded or broad) will fail, not silently return an empty result.
        $this->db->query('DROP TABLE badge_appointments');
        foreach (['clinic' => 1, 'guidance' => 2] as $unit => $actor) {
            $result = $this->fetch($unit, $query, $actor);
            $result->assertStatus(422);
            $this->assertFalse(json_decode($result->getJSON(), true)['success']);
            $this->assertPrivate($result);
        }
    }

    #[DataProvider('units')]
    public function testReadPermissionRevocationAndWrongDepartmentAreDenied(string $unit, int $actor): void
    {
        $query = ['from' => '2026-10-01', 'to' => '2026-10-02'];
        $this->db->query('DROP TABLE badge_appointments');
        $wrong = $this->fetch($unit, $query, $unit === 'clinic' ? 2 : 1);
        $wrong->assertStatus(403);
        $this->assertPrivate($wrong);
        $student = $this->fetch($unit, $query, 3);
        $student->assertStatus(403);
        $this->assertPrivate($student);
        $this->db->table('role_permissions')->where('role', $unit === 'clinic' ? 'clinic_staff' : 'counsellor')->delete();
        $revoked = $this->fetch($unit, $query, $actor);
        $revoked->assertStatus(403);
        $this->assertPrivate($revoked);
    }

    #[DataProvider('units')]
    public function testAnonymousRequestsArePrivate(string $unit, int $actor): void
    {
        $this->db->query('DROP TABLE badge_appointments');
        $result = $this->fetch($unit, ['from' => '2026-10-01', 'to' => '2026-10-02'], null);
        $result->assertStatus(401);
        $this->assertPrivate($result);
    }

    public function testSecondTenantGetsOnlyItsOwnCounts(): void
    {
        $this->booking('clinic', '2026-10-01');
        $this->booking('clinic', '2026-10-01', 'scheduled', 2, 4);
        $this->booking('clinic', '2026-10-01', 'confirmed', 2, 4);
        $result = $this->fetch('clinic', ['from' => '2026-10-01', 'to' => '2026-10-01'], 4);
        $result->assertStatus(200);
        $this->assertSame(['2026-10-01' => 2], json_decode($result->getJSON(), true)['data']['counts']);
        $this->assertPrivate($result);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AppointmentController;
use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\AuthenticatedUser;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Real DB identities/grants on private SQLite; no migrations, dotenv or encryption keys. */
final class SlotsAuthorizationTest extends CIUnitTestCase
{
    private $fixture;
    private array $savedConnections = [];
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', ENVIRONMENT);
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->savedConnections = $instances->getValue();
        $this->fixture = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        $instances->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        self::assertSame($this->fixture, Database::connect());
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, mis_id TEXT, first_name TEXT, last_name TEXT, email TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, is_teaching INTEGER, department TEXT, employment_status TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'department_staff' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, unit TEXT, status TEXT',
            'provider_availability' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, staff_assignment_id INTEGER, unit TEXT, day_of_week INTEGER, start_time TEXT, end_time TEXT, slot_duration_minutes INTEGER, is_active INTEGER',
            'availability_exceptions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, staff_assignment_id INTEGER, exception_date TEXT, is_unavailable INTEGER, custom_start_time TEXT, custom_end_time TEXT',
            'appointments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, staff_assignment_id INTEGER, provider_user_id INTEGER, status TEXT, starts_at TEXT, ends_at TEXT, deleted_at TEXT',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
        (new PermissionsAndRolesSeeder(config('Database'), $this->fixture))->run();
        $this->date = SchedulingClock::now()->modify('+7 days')->format('Y-m-d');
        foreach ([1, 2] as $tenant) {
            $this->fixture->table('tenants')->insert(['id' => $tenant, 'is_active' => 1]);
            foreach (['clinic', 'guidance'] as $index => $unit) {
                $id = $tenant * 10 + $index + 1;
                $this->fixture->table('people')->insert(['id' => $id, 'tenant_id' => $tenant,
                    'kind' => 'employee', 'mis_id' => 'SLOTS-' . $id, 'first_name' => 'Synthetic', 'last_name' => 'Provider']);
                $this->fixture->table('employee_profiles')->insert(['id' => $id, 'tenant_id' => $tenant,
                    'person_id' => $id, 'is_teaching' => 0, 'department' => $unit === 'clinic' ? 'Health Services' : 'Guidance',
                    'employment_status' => 'active']);
                $this->fixture->table('department_staff')->insert(['id' => $id, 'tenant_id' => $tenant,
                    'person_id' => $id, 'unit' => $unit, 'status' => 'active']);
                $this->fixture->table('provider_availability')->insert(['id' => $id, 'tenant_id' => $tenant,
                    'staff_assignment_id' => $id, 'unit' => $unit,
                    'day_of_week' => (int) (new \DateTimeImmutable($this->date))->format('w'),
                    'start_time' => '09:00:00', 'end_time' => '10:00:00', 'slot_duration_minutes' => 30, 'is_active' => 1]);
            }
        }
        $this->fixture->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'person_id' => 11,
            'username' => 'synthetic-slots', 'role' => 'clinic_staff', 'status' => 'active']);
        AuthenticatedUser::clear();
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        foreach (['request', 'response'] as $service) Services::resetSingle($service);
        if ($this->fixture !== null) {
            $this->fixture->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->savedConnections);
        }
        parent::tearDown();
    }

    public static function staffRoles(): iterable
    {
        foreach (['clinic_staff', 'clinic_admin'] as $role) yield $role => [$role, 'clinic', 11, 'clinic.appointments.read', 'clinic.appointments.write'];
        foreach (['counsellor', 'guidance_admin', 'guidance_supervisor'] as $role) yield $role => [$role, 'guidance', 12, 'guidance.schedule.read', 'guidance.schedule.manage'];
    }

    private function signIn(string $role): void
    {
        $this->fixture->table('users')->where('id', 1)->update(['role' => $role]);
        session()->set(['tenant_id' => 1, 'user' => ['id' => 1, 'tenant_id' => 1, 'logged_in' => true],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
    }

    private function revoke(string $role, string $code): void
    {
        $permission = $this->fixture->table('permissions')->where('code', $code)->get()->getRowArray();
        self::assertNotNull($permission);
        $this->fixture->table('role_permissions')->where('role', $role)->where('permission_id', $permission['id'])->delete();
    }

    private function slots(string $unit = 'clinic', int $assignment = 11): array
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod('GET');
        $request->getUri()->setPath('/api/appointments/slots');
        $request->setHeader('Accept', 'application/json');
        $request->setGlobal('get', ['unit' => $unit, 'staff_assignment_id' => $assignment, 'date' => $this->date]);
        Services::injectMock('request', $request);
        Services::injectMock('response', new \CodeIgniter\HTTP\Response(config('App')));
        $controller = new AppointmentController();
        $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
        $response = $controller->slots();
        return [$response->getStatusCode(), json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function assertAllowed(string $unit, int $assignment): void
    {
        [$status, $body] = $this->slots($unit, $assignment);
        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['slots'], array_keys($body));
        self::assertCount(2, $body['slots']);
        self::assertSame(['slot_time' => '09:00', 'starts_at' => $this->date . ' 09:00:00',
            'ends_at' => $this->date . ' 09:30:00', 'available' => true], $body['slots'][0]);
    }

    #[DataProvider('staffRoles')]
    public function testReadOnlyStaffCanReadSlotsWithoutMutationAuthority(string $role, string $unit, int $assignment, string $read, string $write): void
    {
        $this->signIn($role);
        $this->revoke($role, $write);
        $this->assertAllowed($unit, $assignment);
        $actor = (new AuthenticatedUser())->current();
        self::assertContains($read, $actor['permissions']);
        self::assertNotContains($write, $actor['permissions']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Permission required: ' . $write);
        (new AppointmentService())->assertSchedulingPermission($unit, $actor);
    }

    #[DataProvider('staffRoles')]
    public function testReadRevocationIsImmediateEvenWithWriteAndStaleSessionGrants(string $role, string $unit, int $assignment, string $read, string $write): void
    {
        $this->signIn($role);
        $this->assertAllowed($unit, $assignment);
        $stale = session()->get('user');
        self::assertContains($read, $stale['permissions']);
        self::assertContains($write, $stale['permissions']);
        $this->revoke($role, $read);
        foreach ([false, true] as $revokeWrite) {
            if ($revokeWrite) $this->revoke($role, $write);
            session()->set('user', $stale);
            [$status, $body] = $this->slots($unit, $assignment);
            self::assertSame(403, $status);
            self::assertSame([], $body['slots']);
            self::assertSame('Permission required: ' . $read, $body['message']);
        }
    }

    public function testWrongUnitAndNonStaffRolesCannotReadSlots(): void
    {
        foreach (['clinic_staff', 'clinic_admin', 'counsellor', 'guidance_admin', 'guidance_supervisor',
            'student', 'employee', 'bmg_admin', 'facilities_op', 'audit_reader', 'report_viewer'] as $role) {
            $this->signIn($role);
            $unit = str_starts_with($role, 'clinic') ? 'guidance' : 'clinic';
            [$status, $body] = $this->slots($unit, $unit === 'clinic' ? 11 : 12);
            self::assertSame(403, $status, $role);
            self::assertSame([], $body['slots']);
        }
    }

    public function testNoActorInactiveAndForeignIdentitiesCannotReadSlots(): void
    {
        self::assertSame([401, []], $this->deniedSlots());
        foreach ([['status' => 'inactive'], ['tenant_id' => 2, 'person_id' => 21]] as $changes) {
            $this->signIn('clinic_staff');
            $this->fixture->table('users')->where('id', 1)->update(array_replace(
                            ['status' => 'active', 'tenant_id' => 1, 'person_id' => 11], $changes));
            self::assertSame([401, []], $this->deniedSlots());
        }
    }

    private function deniedSlots(): array
    {
        [$status, $body] = $this->slots();
        return [$status, $body['slots']];
    }

    public function testInactiveTenantCannotReadSlots(): void
    {
        $this->signIn('clinic_staff');
        $this->fixture->table('tenants')->where('id', 1)->update(['is_active' => 0]);
        self::assertSame([401, []], $this->deniedSlots());
    }

    public function testSuperadminBypassDoesNotBypassProviderTenantOrUnit(): void
    {
        $this->signIn('superadmin');
        $this->fixture->table('role_permissions')->where('role', 'superadmin')->delete();
        foreach (['clinic' => 11, 'guidance' => 12] as $unit => $assignment) {
            $this->assertAllowed($unit, $assignment);
            foreach ([$assignment + 10, $assignment === 11 ? 12 : 11] as $invalid) {
                [$status, $body] = $this->slots($unit, $invalid);
                self::assertSame(422, $status);
                self::assertSame([], $body['slots']);
            }
        }
    }

    public function testAuthorizedStaffCannotReadForeignOrInactiveAssignment(): void
    {
        $this->signIn('clinic_staff');
        self::assertSame(422, $this->slots('clinic', 21)[0]);
        $this->fixture->table('department_staff')->where('id', 11)->update(['status' => 'inactive']);
        [$status, $body] = $this->slots();
        self::assertSame(422, $status);
        self::assertSame([], $body['slots']);
    }
}

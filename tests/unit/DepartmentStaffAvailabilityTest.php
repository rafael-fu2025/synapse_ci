<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\DepartmentStaffModel;
use App\Models\EmployeeProfileModel;
use App\Models\PersonModel;
use App\Models\ProviderAvailabilityModel;
use App\Models\UserModel;
use App\Services\CurrentTenant;
use App\Services\Scheduling\AppointmentService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\InventoryTestDatabase;

final class DepartmentStaffAvailabilityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use \Tests\Support\AppointmentFixtureCleanup;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private AppointmentService $service;
    private array $admin;
    private int $tenant;

    protected function setUp(): void
    {
        InventoryTestDatabase::verify();
        parent::setUp();
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('department_'), 'name' => 'Department fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->tenant = (int) $this->db->insertID();
        $this->appointmentFixtureTenants[] = $this->tenant;
        CurrentTenant::set($this->tenant);
        $this->service = new AppointmentService();
        $person = $this->employee('Administration');
        $id = (int) (new UserModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $person,
            'username' => uniqid('manager_'), 'role' => 'superadmin', 'status' => 'active']);
        $this->appointmentFixtureUsers[] = $id;
        $this->admin = (new UserModel())->find($id);
    }

    protected function tearDown(): void
    {
        $ids = array_column($this->db->table('department_staff')->whereIn('person_id', $this->appointmentFixturePeople)->get()->getResultArray(), 'id');
        if ($ids !== []) {
            $this->db->table('availability_exceptions')->whereIn('staff_assignment_id', $ids)->delete();
            $this->db->table('provider_availability')->whereIn('staff_assignment_id', $ids)->delete();
            $this->db->table('department_staff')->whereIn('id', $ids)->delete();
        }
        $this->cleanupAppointmentFixtures();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function employee(string $department = 'Health Services', string $status = 'active', ?int $tenant = null, string $position = 'Stored position'): int
    {
        $tenant ??= $this->tenant;
        $person = (int) (new PersonModel())->insert(['tenant_id' => $tenant, 'kind' => 'employee',
            'mis_id' => uniqid('department_'), 'first_name' => 'Local', 'last_name' => uniqid('Staff_')]);
        $this->appointmentFixturePeople[] = $person;
        (new EmployeeProfileModel())->insert(['tenant_id' => $tenant, 'person_id' => $person,
            'department' => $department, 'position' => $position, 'employment_status' => $status]);
        return $person;
    }

    private function enroll(int $person, string $unit = 'clinic'): int
    {
        $this->service->enrollDepartmentStaff($unit, [$person], $this->admin);
        return (int) (new DepartmentStaffModel())->scoped()->where('unit', $unit)->where('person_id', $person)->first()['id'];
    }

    private function template(array $ids): array
    {
        return ['staff_assignment_ids' => $ids, 'days' => ['Monday', 'tuesday'], 'start_time' => '08:00', 'end_time' => '17:00', 'slot_duration_minutes' => 30];
    }

    private function rows(string $unit = 'clinic'): array
    {
        return (new ProviderAvailabilityModel())->scoped()->where('unit', $unit)->orderBy('id')->findAll();
    }

    private function reject(callable $operation, string $class = \InvalidArgumentException::class, int $code = 0): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertSame($code, $e->getCode());
            return;
        }
        $this->fail('Expected operation to be rejected.');
    }

    public function testExactNormalizedMappingAndExplicitEnrollmentWithoutAccountsOrRoleGrants(): void
    {
        $clinic = $this->employee(" \tHeAlTh SeRvIcEs \n", position: 'Assistant');
        $guidance = $this->employee(' GUIDANCE ', position: 'Director');
        foreach (['Clinic', 'Clinic Annex', 'Health Services Office', 'Guidance Office', 'Health  Services'] as $department) {
            $this->employee($department);
        }
        $beforeUsers = $this->db->table('users')->orderBy('id')->get()->getResultArray();
        $beforeGrants = $this->db->table('role_permissions')->orderBy('role')->orderBy('permission_id')->get()->getResultArray();
        $row = $this->service->departmentStaff('clinic', $this->admin)[0];
        $this->assertSame(['person_id', 'name', 'department', 'position', 'staff_assignment_id', 'eligible', 'can_enroll', 'status'], array_keys($row));
        $this->assertSame($clinic, $row['person_id']);
        $this->assertSame('Assistant', $row['position']);
        $this->assertNull($row['staff_assignment_id']);
        $this->assertFalse($row['eligible']);
        $this->assertTrue($row['can_enroll']);
        $this->assertSame('Not enrolled for scheduling', $row['status']);
        $this->assertSame([], $this->service->providers('clinic'));
        $this->assertSame(['created' => 1, 'skipped' => 0], $this->service->enrollDepartmentStaff('clinic', [$clinic, (string) $clinic], $this->admin));
        $this->assertSame(['created' => 0, 'skipped' => 1], $this->service->enrollDepartmentStaff('clinic', [$clinic], $this->admin));
        $row = $this->service->departmentStaff('clinic', $this->admin)[0];
        $this->assertTrue($row['eligible']);
        $this->assertFalse($row['can_enroll']);
        $this->assertSame('Ready to schedule', $row['status']);
        $this->assertSame($this->admin['id'], (new DepartmentStaffModel())->find($row['staff_assignment_id'])['enrolled_by_user_id']);
        $this->assertSame([['id' => $row['staff_assignment_id'], 'name' => $row['name'], 'position' => 'Assistant']], $this->service->providers('clinic'));
        $this->service->applyDepartmentTemplate('clinic', $this->template([$row['staff_assignment_id']]), $this->admin);
        $this->assertSame('Schedule configured', $this->service->departmentStaff('clinic', $this->admin)[0]['status']);
        $this->assertSame([$guidance], array_column($this->service->departmentStaff('guidance', $this->admin), 'person_id'));
        $this->assertSame($beforeUsers, $this->db->table('users')->orderBy('id')->get()->getResultArray());
        $this->assertSame($beforeGrants, $this->db->table('role_permissions')->orderBy('role')->orderBy('permission_id')->get()->getResultArray());
        foreach ($this->rows() as $window) {
            $this->assertNull($window['provider_user_id']);
        }
    }

    public function testEnrollmentValidatesEntireSelectionAndNeverReactivatesAssignments(): void
    {
        $valid = $this->employee();
        $inactive = $this->employee(status: 'inactive');
        $onLeave = $this->employee(status: 'on_leave');
        $foreign = $this->employee(tenant: 1);
        $other = $this->employee('Guidance');
        $deleted = $this->employee();
        (new PersonModel())->delete($deleted);
        $student = $this->employee();
        (new PersonModel())->update($student, ['kind' => 'student']);
        foreach ([[], [$valid, ''], [$valid, true], [$valid, 1.0], [$valid, []], [$valid, '1e2'], [$valid, PHP_INT_MAX],
            [$valid, $inactive], [$valid, $onLeave], [$valid, $foreign], [$valid, $other], [$valid, $deleted], [$valid, $student]] as $ids) {
            $this->reject(fn () => $this->service->enrollDepartmentStaff('clinic', $ids, $this->admin));
            $this->assertSame(0, (new DepartmentStaffModel())->scoped()->countAllResults());
        }
        $id = $this->enroll($valid);
        (new DepartmentStaffModel())->update($id, ['status' => 'inactive']);
        $this->assertSame(['created' => 0, 'skipped' => 1], $this->service->enrollDepartmentStaff('clinic', [$valid], $this->admin));
        $this->assertSame('inactive', (new DepartmentStaffModel())->find($id)['status']);
        $rows = array_column($this->service->departmentStaff('clinic', $this->admin), null, 'person_id');
        $this->assertSame('Inactive assignment', $rows[$valid]['status']);
        $this->assertSame('Inactive employee', $rows[$inactive]['status']);
        $this->assertFalse($rows[$valid]['can_enroll']);
        $this->assertFalse($rows[$inactive]['can_enroll']);
        $this->assertSame([], $this->service->providers('clinic'));
    }

    public function testRosterExcludesForeignDeletedAndNonEmployeeProfiles(): void
    {
        $valid = $this->employee();
        $this->employee(tenant: 1);
        $deleted = $this->employee();
        (new PersonModel())->delete($deleted);
        $student = $this->employee();
        (new PersonModel())->update($student, ['kind' => 'student']);
        $noProfile = $this->employee();
        $this->db->table('employee_profiles')->where('person_id', $noProfile)->delete();
        $foreignProfile = $this->employee();
        $this->db->table('employee_profiles')->where('person_id', $foreignProfile)->update(['tenant_id' => 1]);
        $this->assertSame([$valid], array_column($this->service->departmentStaff('clinic', $this->admin), 'person_id'));
        foreach ([$deleted, $student, $noProfile, $foreignProfile] as $id) {
            $this->reject(fn () => $this->service->enrollDepartmentStaff('clinic', [$valid, $id], $this->admin));
            $this->assertSame(0, (new DepartmentStaffModel())->scoped()->countAllResults());
        }
    }

    public function testEnrollmentRollsBackWholeBatchAndCanRetryAfterInsertFailure(): void
    {
        $first = $this->employee();
        $second = $this->employee();
        $table = $this->db->prefixTable('department_staff');
        $trigger = 'enrollment_failure_' . $second;
        $this->db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.person_id = {$second} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enrollment fixture failure'; END IF; END");
        try {
            $this->reject(fn () => $this->service->enrollDepartmentStaff('clinic', [$second, $first], $this->admin), \RuntimeException::class);
            $this->assertSame(0, (new DepartmentStaffModel())->scoped()->countAllResults());
            $this->assertSame(0, $this->db->transDepth);
        } finally {
            $this->db->query("DROP TRIGGER {$trigger}");
        }
        $this->assertSame(['created' => 2, 'skipped' => 0], $this->service->enrollDepartmentStaff('clinic', [$second, $first], $this->admin));
        $this->assertSame(['created' => 0, 'skipped' => 2], $this->service->enrollDepartmentStaff('clinic', [$first, $second], $this->admin));
    }

    public function testTemplatePreservesEveryWindowAndOnlyFillsExplicitMissingDays(): void
    {
        $first = $this->enroll($this->employee());
        $second = $this->enroll($this->employee());
        $unselected = $this->enroll($this->employee());
        foreach ([[1, '08:00:00', '09:00:00', 0], [1, '10:00:00', '11:00:00', 1], [3, '12:00:00', '13:00:00', 0]] as [$day, $start, $end, $active]) {
            (new ProviderAvailabilityModel())->insert(['tenant_id' => $this->tenant, 'staff_assignment_id' => $first, 'provider_user_id' => null,
                'unit' => 'clinic', 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end, 'slot_duration_minutes' => 15, 'is_active' => $active]);
        }
        $before = $this->rows();
        $payload = array_replace($this->template([$second, (string) $first, $first]), ['days' => [' MONDAY ', 'Tuesday', 'monday']]);
        $this->assertSame(['created' => 3, 'skipped' => 1], $this->service->applyDepartmentTemplate('clinic', $payload, $this->admin));
        $after = $this->rows();
        $this->assertSame($before, array_slice($after, 0, 3));
        $this->assertNotContains($unselected, array_map('intval', array_column($after, 'staff_assignment_id')));
        $this->assertSame(['created' => 0, 'skipped' => 4], $this->service->applyDepartmentTemplate('clinic', $payload, $this->admin));
        $this->assertSame($after, $this->rows());
    }

    public function testMalformedLegacyAndForgedTemplatesRejectEntireBatch(): void
    {
        $valid = $this->enroll($this->employee());
        $inactive = $this->enroll($this->employee());
        (new DepartmentStaffModel())->update($inactive, ['status' => 'inactive']);
        $other = $this->enroll($this->employee('Guidance'), 'guidance');
        $bad = [['staff_assignment_ids' => []], ['staff_assignment_ids' => null], ['staff_assignment_ids' => '1'],
            ['staff_assignment_ids' => [$valid, $inactive]], ['staff_assignment_ids' => [$valid, $other]],
            ['staff_assignment_ids' => [$valid, PHP_INT_MAX]], ['staff_assignment_ids' => [$valid, '1e2']],
            ['days' => []], ['days' => 'Monday'], ['days' => [1]], ['days' => ['Monday', 'noday']],
            ['start_time' => '9:00'], ['start_time' => []], ['end_time' => '24:00'], ['end_time' => '08:00'],
            ['end_time' => '08:15'], ['slot_duration_minutes' => 20], ['slot_duration_minutes' => []]];
        foreach ($bad as $values) {
            $this->reject(fn () => $this->service->applyDepartmentTemplate('clinic', array_replace($this->template([$valid]), $values), $this->admin));
            $this->assertSame([], $this->rows());
            $this->assertSame(0, $this->db->transDepth);
        }
        $legacy = $this->template([$valid]);
        unset($legacy['staff_assignment_ids']);
        $legacy['provider_user_ids'] = [$valid];
        $this->reject(fn () => $this->service->applyDepartmentTemplate('clinic', $legacy, $this->admin));
    }

    public function testAllManagementApisRecheckPersistedActorPrivileges(): void
    {
        $person = $this->employee();
        (new UserModel())->update($this->admin['id'], ['role' => 'clinic_staff']);
        $this->reject(fn () => $this->service->enrollDepartmentStaff('clinic', [$person], $this->admin), \DomainException::class, 403);
        $this->reject(fn () => $this->service->departmentStaff('clinic', $this->admin), \DomainException::class, 403);
        $this->reject(fn () => $this->service->applyDepartmentTemplate('clinic', $this->template([1]), $this->admin), \DomainException::class, 403);
        $this->reject(fn () => $this->service->saveAvailability('clinic', [], $this->admin), \DomainException::class, 403);
    }

    public function testBatchRollbackAndRetryAfterInsertFailure(): void
    {
        $first = $this->enroll($this->employee());
        $second = $this->enroll($this->employee());
        $payload = $this->template([$second, $first]);
        $table = $this->db->prefixTable('provider_availability');
        $trigger = 'department_failure_' . $second;
        $this->db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.staff_assignment_id = {$second} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Department fixture failure'; END IF; END");
        try {
            $this->reject(fn () => $this->service->applyDepartmentTemplate('clinic', $payload, $this->admin), \RuntimeException::class);
            $this->assertSame([], $this->rows());
            $this->assertSame(0, $this->db->transDepth);
        } finally {
            $this->db->query("DROP TRIGGER {$trigger}");
        }
        $this->assertSame(['created' => 4, 'skipped' => 0], $this->service->applyDepartmentTemplate('clinic', $payload, $this->admin));
    }

    public function testRefusesUnsafeOuterTransaction(): void
    {
        $id = $this->enroll($this->employee());
        $this->db->transBegin();
        try {
            $this->reject(fn () => $this->service->applyDepartmentTemplate('clinic', $this->template([$id]), $this->admin), \RuntimeException::class);
            $this->assertSame(1, $this->db->transDepth);
        } finally {
            $this->db->transRollback();
        }
    }
}

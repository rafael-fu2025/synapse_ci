<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Mis\MisClientInterface;
use App\Models\AppointmentModel;
use App\Models\AvailabilityExceptionModel;
use App\Models\DepartmentStaffModel;
use App\Models\EmployeeProfileModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\QueueService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\InventoryTestDatabase;

final class StaffAssignmentSchedulingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use \Tests\Support\AppointmentFixtureCleanup;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private AppointmentService $service;
    private array $actor;
    private int $tenant;
    private int $patient;

    protected function setUp(): void
    {
        InventoryTestDatabase::verify();
        parent::setUp();
        AuthenticatedUser::clear();
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('assignment_'), 'name' => 'Assignment fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->tenant = (int) $this->db->insertID();
        $this->appointmentFixtureTenants[] = $this->tenant;
        CurrentTenant::set($this->tenant);
        $this->service = new AppointmentService();
        $person = $this->employee('Administration');
        $id = (int) (new UserModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $person,
            'username' => uniqid('assignment_manager_'), 'role' => 'superadmin', 'status' => 'active']);
        $this->appointmentFixtureUsers[] = $id;
        $this->actor = (new UserModel())->find($id);
        $this->patient = $this->employee('Administration');
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        session()->remove(['user', 'tenant_id']);
        $this->db->table('notifications')->whereIn('recipient_person_id', $this->appointmentFixturePeople)->delete();
        $this->db->table('queue_entries')->whereIn('person_id', $this->appointmentFixturePeople)->delete();
        $this->db->table('appointments')->whereIn('person_id', $this->appointmentFixturePeople)->delete();
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

    private function employee(string $department = 'Health Services', ?int $tenant = null): int
    {
        $tenant ??= $this->tenant;
        $id = (int) (new PersonModel())->insert(['tenant_id' => $tenant, 'kind' => 'employee',
            'mis_id' => uniqid('ASSIGN_'), 'first_name' => 'NoLogin', 'last_name' => uniqid('Employee_')]);
        $this->appointmentFixturePeople[] = $id;
        (new EmployeeProfileModel())->insert(['tenant_id' => $tenant, 'person_id' => $id,
            'department' => $department, 'position' => 'Clinician', 'employment_status' => 'active']);
        return $id;
    }

    private function enroll(int $person, string $unit = 'clinic'): int
    {
        $this->service->enrollDepartmentStaff($unit, [$person], $this->actor);
        return (int) (new DepartmentStaffModel())->scoped()->where('person_id', $person)->where('unit', $unit)->first()['id'];
    }

    private function window(int $assignment, string $date, string $unit = 'clinic', string $start = '08:00', string $end = '17:00'): array
    {
        return $this->service->saveAvailability($unit, ['staff_assignment_id' => $assignment,
            'day_of_week' => (int) date('w', strtotime($date)), 'start_time' => $start, 'end_time' => $end,
            'slot_duration_minutes' => 30, 'is_active' => 1], $this->actor);
    }

    private function booking(int $assignment, string $date, string $unit = 'clinic', string $start = '09:00:00'): array
    {
        return ['unit' => $unit, 'person_id' => $this->patient, 'staff_assignment_id' => $assignment,
            'starts_at' => $date . ' ' . $start, 'ends_at' => (new \DateTimeImmutable($date . ' ' . $start))->modify('+30 minutes')->format('Y-m-d H:i:s'),
            'booked_by_user_id' => $this->actor['id']];
    }

    private function reject(callable $operation, int $code = 0): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($code === 409 ? \RuntimeException::class : \InvalidArgumentException::class, $e);
            $this->assertSame($code, $e->getCode());
            return;
        }
        $this->fail('Expected scheduling rejection.');
    }

    public function testNoAccountCanBookConfirmAndCheckInAndRetainAuditUsers(): void
    {
        foreach (['clinic' => 'Health Services', 'guidance' => 'Guidance'] as $unit => $department) {
            $person = $this->employee($department);
            $assignment = $this->enroll($person, $unit);
            $date = date('Y-m-d', strtotime('+2 days'));
            $this->window($assignment, $date, $unit);
            $slots = $this->service->getAvailableSlots($unit, $assignment, $date);
            $this->assertTrue($slots[0]['available']);
            $booked = $this->service->bookAppointment($this->booking($assignment, $date, $unit));
            $this->assertSame($assignment, (int) $booked['staff_assignment_id']);
            $this->assertNull($booked['provider_user_id']);
            $provider = (new PersonModel())->find($person);
            $this->assertSame($provider['first_name'] . ' ' . $provider['last_name'], $booked['provider_name']);
            $this->assertSame((int) $this->actor['id'], (int) $booked['booked_by_user_id']);
            $request = $this->service->requestAppointment($unit, ['person_id' => $this->patient, 'date' => $date,
                'preferred_time' => '10:00', 'type' => $unit === 'clinic' ? 'routine' : 'initial'], $this->actor);
            $data = $this->booking($assignment, $date, $unit, '10:00:00') + ['version' => $request['version']];
            $confirmed = $this->service->confirmAppointment($unit, (int) $request['id'], $data, $this->actor);
            $this->assertNull($confirmed['provider_user_id']);
            $this->assertSame($assignment, (int) $confirmed['staff_assignment_id']);
            $this->assertSame((int) $this->actor['id'], (int) $confirmed['confirmed_by_user_id']);
            $this->assertSame((int) $this->actor['id'], (int) $confirmed['assigned_by_user_id']);
            $this->reject(fn () => $this->service->confirmAppointment($unit, (int) $request['id'], $data, $this->actor), 409);
            // Check-in uses today's date, but does not require the consultation to be in the future.
            $today = date('Y-m-d');
            $this->window($assignment, $today, $unit);
            $id = (int) (new AppointmentModel())->insert(['tenant_id' => $this->tenant, 'unit' => $unit,
                'person_id' => $this->patient, 'staff_assignment_id' => $assignment, 'provider_user_id' => null,
                'starts_at' => $today . ' 08:00:00', 'ends_at' => $today . ' 08:30:00', 'local_date' => $today,
                'status' => 'confirmed', 'type' => 'follow_up', 'version' => 1]);
            $queue = new QueueService();
            $first = $queue->enqueueAppointment($id, $unit, $this->actor);
            $second = $queue->enqueueAppointment($id, $unit, $this->actor);
            $this->assertSame($first['id'], $second['id']);
            $this->assertSame('checked_in', $this->service->appointment($unit, $id)['status']);
            $this->assertSame(0, $this->db->table('users')->where('person_id', $person)->countAllResults());
        }
    }

    public function testConfirmationPreservesHistoricalUserAndLegacyDisplayFallback(): void
    {
        $assignment = $this->enroll($this->employee());
        $date = date('Y-m-d', strtotime('+3 days'));
        $this->window($assignment, $date);
        $request = $this->service->requestAppointment('clinic', ['person_id' => $this->patient, 'date' => $date,
            'preferred_time' => '09:00', 'type' => 'routine'], $this->actor);
        (new AppointmentModel())->update($request['id'], ['provider_user_id' => $this->actor['id']]);
        $this->assertSame($this->actor['username'], $this->service->appointment('clinic', (int) $request['id'])['provider_name']);
        $confirmed = $this->service->confirmAppointment('clinic', (int) $request['id'],
            $this->booking($assignment, $date) + ['version' => 1], $this->actor);
        $this->assertSame((int) $this->actor['id'], (int) $confirmed['provider_user_id']);
        $this->assertSame($assignment, (int) $confirmed['staff_assignment_id']);
    }

    public function testIndividualAvailabilityEditPreservesHistoricalUserLink(): void
    {
        $assignment = $this->enroll($this->employee());
        $date = date('Y-m-d', strtotime('+3 days'));
        $window = $this->window($assignment, $date);
        $this->db->table('provider_availability')->where('id', $window['id'])->update(['provider_user_id' => $this->actor['id']]);
        $edited = $this->window($assignment, $date, start: '10:00', end: '12:00');
        $this->assertSame($window['id'], $edited['id']);
        $this->assertSame((int) $this->actor['id'], (int) $edited['provider_user_id']);
        $this->assertSame($assignment, (int) $edited['staff_assignment_id']);
        $this->assertNotEmpty($this->service->getAvailability('clinic', $this->actor)[0]['provider_name']);
    }

    public function testConflictsSpanHistoricalOtherUnitAssignmentAndLegacyUserForSamePerson(): void
    {
        $person = $this->employee('Guidance');
        $guidance = $this->enroll($person, 'guidance');
        $date = date('Y-m-d', strtotime('+3 days'));
        $this->window($guidance, $date, 'guidance');
        $historical = $this->service->bookAppointment($this->booking($guidance, $date, 'guidance'));
        $this->db->table('employee_profiles')->where('person_id', $person)->update(['department' => 'Health Services']);
        $clinic = $this->enroll($person);
        $this->window($clinic, $date);
        $this->reject(fn () => $this->service->bookAppointment($this->booking($clinic, $date)), 409);
        $slots = array_column($this->service->getAvailableSlots('clinic', $clinic, $date), null, 'slot_time');
        $this->assertFalse($slots['09:00']['available']);
        (new AppointmentModel())->update($historical['id'], ['status' => 'cancelled']);
        $legacyUser = (int) (new UserModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $person,
            'username' => uniqid('legacy_'), 'role' => 'employee', 'status' => 'suspended']);
        $this->appointmentFixtureUsers[] = $legacyUser;
        (new AppointmentModel())->update($historical['id'], ['status' => 'scheduled', 'staff_assignment_id' => null, 'provider_user_id' => $legacyUser]);
        $this->reject(fn () => $this->service->bookAppointment($this->booking($clinic, $date)), 409);
        $this->service->bookAppointment($this->booking($clinic, $date, start: '09:30:00'));
    }

    public function testRejectsUnknownForeignWrongUnitDeletedAndInactiveAssignments(): void
    {
        $person = $this->employee();
        $valid = $this->enroll($person);
        $other = $this->enroll($this->employee('Guidance'), 'guidance');
        $foreignPerson = $this->employee(tenant: 1);
        $foreign = (int) (new DepartmentStaffModel())->insert(['tenant_id' => 1, 'person_id' => $foreignPerson, 'unit' => 'clinic', 'status' => 'active']);
        $inactive = $this->enroll($this->employee());
        (new DepartmentStaffModel())->update($inactive, ['status' => 'inactive']);
        $deletedPerson = $this->employee();
        $deleted = $this->enroll($deletedPerson);
        (new PersonModel())->delete($deletedPerson);
        $inactivePerson = $this->employee();
        $inactiveEmployee = $this->enroll($inactivePerson);
        $this->db->table('employee_profiles')->where('person_id', $inactivePerson)->update(['employment_status' => 'inactive']);
        $date = date('Y-m-d', strtotime('+3 days'));
        foreach ([0, PHP_INT_MAX, $other, $foreign, $inactive, $deleted, $inactiveEmployee] as $id) {
            $this->reject(fn () => $this->service->getAvailableSlots('clinic', $id, $date));
            $this->reject(fn () => $this->window($id, $date));
            $this->reject(fn () => $this->service->bookAppointment($this->booking($id, $date)));
            $request = $this->service->requestAppointment('clinic', ['person_id' => $this->patient,
                'date' => $date, 'preferred_time' => '09:00', 'type' => 'routine'], $this->actor);
            $this->reject(fn () => $this->service->confirmAppointment('clinic', (int) $request['id'],
                $this->booking($id, $date) + ['version' => 1], $this->actor));
            $historical = ['status' => 'confirmed', 'staff_assignment_id' => $id === 0 || $id === PHP_INT_MAX ? null : $id, 'local_date' => date('Y-m-d')];
            if (in_array($id, [$other, $foreign], true)) {
                // These tenant/unit mismatches cannot exist under the composite FK.
                try {
                    (new AppointmentModel())->update($request['id'], $historical);
                    $this->fail('The database must reject a foreign-tenant or wrong-unit assignment.');
                } catch (DatabaseException $e) {
                    $this->assertStringContainsString('foreign key constraint fails', $e->getMessage());
                }
                $unchanged = (new AppointmentModel())->find($request['id']);
                $this->assertNull($unchanged['staff_assignment_id']);
                $this->assertSame('requested', $unchanged['status']);
                continue;
            }
            (new AppointmentModel())->update($request['id'], $historical);
            $this->reject(fn () => (new QueueService())->enqueueAppointment((int) $request['id'], 'clinic', $this->actor));
        }
        $this->assertSame([$valid], array_column($this->service->providers('clinic'), 'id'));
    }

    public function testBookingAndConfirmationHonorGridAndAssignmentExceptionsWithoutUserFallback(): void
    {
        $id = $this->enroll($this->employee());
        $date = date('Y-m-d', strtotime('+3 days'));
        $this->window($id, $date);
        $request = $this->service->requestAppointment('clinic', ['person_id' => $this->patient,
            'date' => $date, 'preferred_time' => '09:00', 'type' => 'routine'], $this->actor);
        foreach (['07:30:00', '09:05:00', '17:00:00'] as $time) {
            $this->reject(fn () => $this->service->bookAppointment($this->booking($id, $date, start: $time)));
            $this->reject(fn () => $this->service->confirmAppointment('clinic', (int) $request['id'],
                $this->booking($id, $date, start: $time) + ['version' => 1], $this->actor));
        }
        $legacy = $this->booking($id, $date);
        unset($legacy['staff_assignment_id']);
        $legacy['provider_user_id'] = $this->actor['id'];
        $this->reject(fn () => $this->service->bookAppointment($legacy));
        $this->reject(fn () => $this->service->confirmAppointment('clinic', (int) $request['id'], $legacy + ['version' => 1], $this->actor));
        $exception = (int) (new AvailabilityExceptionModel())->insert(['tenant_id' => $this->tenant, 'staff_assignment_id' => $id,
            'provider_user_id' => null, 'exception_date' => $date, 'is_unavailable' => 1]);
        $this->assertSame([], $this->service->getAvailableSlots('clinic', $id, $date));
        $this->reject(fn () => $this->service->bookAppointment($this->booking($id, $date)));
        (new AvailabilityExceptionModel())->update($exception, ['is_unavailable' => 0, 'custom_start_time' => '12:00:00', 'custom_end_time' => '13:00:00']);
        $this->assertSame(['12:00', '12:30'], array_column($this->service->getAvailableSlots('clinic', $id, $date), 'slot_time'));
        $this->reject(fn () => $this->service->bookAppointment($this->booking($id, $date)));
        $this->service->bookAppointment($this->booking($id, $date, start: '12:00:00'));
    }

    public function testFirstMisLoginRetainsAssignmentAndDoesNotGrantClinicalRoles(): void
    {
        $person = $this->employee();
        $assignment = $this->enroll($person);
        $before = (new DepartmentStaffModel())->find($assignment);
        $date = date('Y-m-d', strtotime('+3 days'));
        $this->window($assignment, $date);
        $windows = $this->db->table('provider_availability')->where('staff_assignment_id', $assignment)->get()->getResultArray();
        $grants = $this->db->table('role_permissions')->orderBy('role')->orderBy('permission_id')->get()->getResultArray();
        $stored = (new PersonModel())->find($person);
        $mis = $this->createMock(MisClientInterface::class);
        $mis->expects($this->once())->method('studentLogin')->willReturn(null);
        $mis->expects($this->once())->method('employeeLogin')->willReturn(['response' => 'success', 'data' => ['data' => [
            'employeeId' => $stored['mis_id'], 'firstName' => $stored['first_name'], 'lastName' => $stored['last_name'],
            'departmentName' => 'Health Services', 'employmentStatus' => 'active', 'position' => 'Clinician',
            'role' => 'clinic_staff', 'permissions' => ['rbac.manage']]]]);
        $identity = (new AuthService(misClient: $mis))->login($stored['mis_id'], 'fixture-password');
        $this->assertNotNull($identity);
        $this->appointmentFixtureUsers[] = (int) $identity['id'];
        $this->assertSame($person, (int) $identity['person_id']);
        $this->assertSame('employee', $identity['role']);
        $this->assertSame($before, (new DepartmentStaffModel())->find($assignment));
        $this->assertSame($windows, $this->db->table('provider_availability')->where('staff_assignment_id', $assignment)->get()->getResultArray());
        $this->assertSame($grants, $this->db->table('role_permissions')->orderBy('role')->orderBy('permission_id')->get()->getResultArray());
        $this->assertSame([$assignment], array_column($this->service->providers('clinic'), 'id'));
        $this->assertNull($this->service->bookAppointment($this->booking($assignment, $date))['provider_user_id']);
    }

    public function testConcurrentEnrollmentTemplateAndIndividualEditSharePersonMutex(): void
    {
        $person = $this->employee();
        $results = $this->race($person, [
            ['operation' => 'enroll', 'data' => [$person]], ['operation' => 'enroll', 'data' => [$person]],
        ]);
        $this->assertSame(1, array_sum(array_column(array_column($results, 'result'), 'created')));
        $this->assertSame(1, array_sum(array_column(array_column($results, 'result'), 'skipped')));
        $id = (int) (new DepartmentStaffModel())->scoped()->where('person_id', $person)->first()['id'];
        $template = ['staff_assignment_ids' => [$id], 'days' => ['Monday'], 'start_time' => '08:00', 'end_time' => '17:00', 'slot_duration_minutes' => 30];
        $results = $this->race($person, [['operation' => 'template', 'data' => $template], ['operation' => 'template', 'data' => $template]]);
        $this->assertSame(1, array_sum(array_column(array_column($results, 'result'), 'created')));
        $this->assertSame(1, array_sum(array_column(array_column($results, 'result'), 'skipped')));
        $this->race($person, [['operation' => 'template', 'data' => array_replace($template, ['days' => ['Tuesday']])],
            ['operation' => 'individual', 'data' => ['staff_assignment_id' => $id, 'day_of_week' => 2,
                'start_time' => '10:00', 'end_time' => '12:00', 'slot_duration_minutes' => 15, 'is_active' => 0]]]);
        $rows = $this->db->table('provider_availability')->where('staff_assignment_id', $id)->where('day_of_week', 2)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame('10:00:00', $rows[0]['start_time']);
        $this->assertSame(0, (int) $rows[0]['is_active']);
    }

    public function testReverseOrderBatchesLockPeopleBeforeAssignmentsWithoutDeadlock(): void
    {
        $firstPerson = $this->employee();
        $secondPerson = $this->employee();
        // Assignment order deliberately disagrees with person order.
        $second = $this->enroll($secondPerson);
        $first = $this->enroll($firstPerson);
        $template = ['staff_assignment_ids' => [$first, $second], 'days' => ['Monday', 'Tuesday'],
            'start_time' => '08:00', 'end_time' => '17:00', 'slot_duration_minutes' => 30];
        $results = $this->race($firstPerson, [['operation' => 'template', 'data' => $template],
            ['operation' => 'template', 'data' => array_replace($template, ['staff_assignment_ids' => [$second, $first]])]]);
        $this->assertSame(4, array_sum(array_column(array_column($results, 'result'), 'created')));
        $this->assertSame(4, array_sum(array_column(array_column($results, 'result'), 'skipped')));
    }

    public function testConcurrentConfirmationAndBookingCannotDoubleAllocatePerson(): void
    {
        $person = $this->employee();
        $id = $this->enroll($person);
        $date = date('Y-m-d', strtotime('+3 days'));
        $this->window($id, $date);
        $request = $this->service->requestAppointment('clinic', ['person_id' => $this->patient,
            'date' => $date, 'preferred_time' => '09:00', 'type' => 'routine'], $this->actor);
        $data = $this->booking($id, $date);
        $results = $this->race($person, [['operation' => 'book', 'data' => $data],
            ['operation' => 'confirm', 'id' => $request['id'], 'data' => $data + ['version' => 1]]], true);
        $this->assertCount(1, array_filter($results, static fn (array $row): bool => isset($row['result'])));
        $errors = array_values(array_filter($results, static fn (array $row): bool => isset($row['error'])));
        $this->assertCount(1, $errors);
        $this->assertSame(409, $errors[0]['code']);
        $this->assertSame(1, (new AppointmentModel())->scoped()->where('staff_assignment_id', $id)
            ->whereIn('status', ['scheduled', 'confirmed'])->countAllResults());
    }

    /** Independent CLI connections only use the verified test DB configuration, never live MIS. */
    private function race(int $person, array $jobs, bool $allowConflict = false): array
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for independent-connection concurrency tests.');
        }
        $code = <<<'PHP'
$job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
require $job['bootstrap'];
config('Database')->tests = $job['database'];
config('Database')->defaultGroup = 'tests';
\App\Services\CurrentTenant::set($job['tenant']);
echo "READY\n";
flush();
fgets(STDIN);
try {
    $service = new \App\Services\Scheduling\AppointmentService();
    $result = match ($job['operation']) {
        'enroll' => $service->enrollDepartmentStaff('clinic', $job['data'], $job['actor']),
        'template' => $service->applyDepartmentTemplate('clinic', $job['data'], $job['actor']),
        'individual' => $service->saveAvailability('clinic', $job['data'], $job['actor']),
        'book' => $service->bookAppointment($job['data']),
        'confirm' => $service->confirmAppointment('clinic', (int) $job['id'], $job['data'], $job['actor']),
    };
    echo json_encode(['result' => $result], JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage(), 'code' => $e->getCode()], JSON_THROW_ON_ERROR) . "\n";
}
PHP;
        $workers = [];
        $this->db->transBegin();
        try {
            $this->db->query('SELECT id FROM ' . $this->db->prefixTable('people') . ' WHERE id = ? FOR UPDATE', [$person]);
            foreach ($jobs as $job) {
                $process = proc_open([PHP_BINARY, '-r', $code],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
                fwrite($pipes[0], json_encode($job + ['bootstrap' => ROOTPATH . 'vendor/codeigniter4/framework/system/Test/bootstrap.php',
                    'database' => config('Database')->tests, 'tenant' => $this->tenant, 'actor' => $this->actor], JSON_THROW_ON_ERROR) . "\n");
                stream_set_timeout($pipes[1], 15);
                $this->assertSame("READY\n", fgets($pipes[1]));
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "GO\n");
                fclose($pipes[0]);
            }
            usleep(150000);
            $this->db->transCommit();
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $line = fgets($pipes[1]);
                $this->assertIsString($line, 'Assignment worker timed out.');
                $result = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!$allowConflict) {
                    $this->assertArrayNotHasKey('error', $result, $line);
                }
                $results[] = $result;
                $this->assertSame('', stream_get_contents($pipes[2]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process));
            }
            $workers = [];
            return $results;
        } finally {
            if ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) fclose($pipe);
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }
}

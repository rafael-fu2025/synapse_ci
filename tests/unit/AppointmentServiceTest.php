<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AppointmentModel;
use App\Models\AvailabilityExceptionModel;
use App\Models\DepartmentStaffModel;
use App\Models\EmployeeProfileModel;
use App\Models\PersonModel;
use App\Models\ProviderAvailabilityModel;
use App\Services\CurrentTenant;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\SchedulingClock;
use Tests\Support\FreshDatabaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/** Migration-backed regressions; run only through the guarded fresh-database runner. */
final class AppointmentServiceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private AppointmentService $appointmentService;
    private ProviderAvailabilityModel $availModel;
    private AvailabilityExceptionModel $exceptionModel;
    private AppointmentModel $appointmentModel;
    private PersonModel $personModel;
    private int $guidanceAssignment;
    private int $personId;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
        $this->availModel = new ProviderAvailabilityModel();
        $this->exceptionModel = new AvailabilityExceptionModel();
        $this->appointmentModel = new AppointmentModel();
        $this->personModel = new PersonModel();
        $this->appointmentService = new AppointmentService($this->appointmentModel, $this->availModel, $this->exceptionModel);
        $this->guidanceAssignment = $this->enroll('guidance');
        $this->personId = (int) $this->personModel->insert([
            'tenant_id' => 1, 'kind' => 'student', 'mis_id' => 'APPT_' . uniqid(),
            'first_name' => 'Alice', 'last_name' => 'Walker',
        ]);
    }

    protected function tearDown(): void
    {
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function enroll(string $unit): int
    {
        // Scheduling enrollment is person-based and must not depend on a login account.
        $person = $this->personModel->insert([
            'tenant_id' => 1, 'kind' => 'employee', 'mis_id' => 'APPT_PROVIDER_' . uniqid(),
            'first_name' => 'Provider', 'last_name' => ucfirst($unit),
        ]);
        $this->assertNotFalse($person);
        $this->assertNotFalse((new EmployeeProfileModel())->insert([
            'tenant_id' => 1, 'person_id' => $person,
            'department' => $unit === 'clinic' ? 'Health Services' : 'Guidance',
            'position' => 'Test practitioner', 'employment_status' => 'active',
        ]));
        $assignment = (new DepartmentStaffModel())->insert([
            'tenant_id' => 1, 'person_id' => $person, 'unit' => $unit, 'status' => 'active',
        ]);
        $this->assertNotFalse($assignment);
        return (int) $assignment;
    }

    private function window(string $unit, int $assignment, string $date, string $start, string $end): void
    {
        $this->assertNotFalse($this->availModel->insert([
            'tenant_id' => 1, 'staff_assignment_id' => $assignment, 'provider_user_id' => null,
            'unit' => $unit, 'day_of_week' => (int) (new \DateTimeImmutable($date, SchedulingClock::timezone()))->format('w'),
            'start_time' => $start, 'end_time' => $end, 'slot_duration_minutes' => 30, 'is_active' => 1,
        ]));
    }

    private function book(string $unit, int $assignment, string $date, string $start, string $end): array
    {
        return $this->appointmentService->bookAppointment([
            'unit' => $unit, 'person_id' => $this->personId, 'staff_assignment_id' => $assignment,
            'starts_at' => $date . ' ' . $start, 'ends_at' => $date . ' ' . $end,
        ]);
    }

    public function testSlotCalculationRespectsAvailabilityTemplate(): void
    {
        $monday = SchedulingClock::now()->modify('next monday')->format('Y-m-d');
        $this->window('guidance', $this->guidanceAssignment, $monday, '08:00:00', '10:00:00');
        $slots = $this->appointmentService->getAvailableSlots('guidance', $this->guidanceAssignment, $monday);
        $this->assertCount(4, $slots);
        $this->assertSame(['08:00', '08:30', '09:00', '09:30'], array_column($slots, 'slot_time'));
        $this->assertTrue($slots[0]['available']);
    }

    public function testProviderExceptionOverridesNormalSchedule(): void
    {
        $monday = SchedulingClock::now()->modify('next monday')->format('Y-m-d');
        $this->window('guidance', $this->guidanceAssignment, $monday, '08:00:00', '10:00:00');
        $this->assertNotFalse($this->exceptionModel->insert([
            'tenant_id' => 1, 'staff_assignment_id' => $this->guidanceAssignment, 'provider_user_id' => null,
            'exception_date' => $monday, 'is_unavailable' => 1, 'reason' => 'Annual Leave',
        ]));
        $this->assertSame([], $this->appointmentService->getAvailableSlots('guidance', $this->guidanceAssignment, $monday));
    }

    public function testConflictDetectionMarksSlotUnavailable(): void
    {
        $assignment = $this->enroll('clinic');
        $tuesday = SchedulingClock::now()->modify('next tuesday')->format('Y-m-d');
        $this->window('clinic', $assignment, $tuesday, '09:00:00', '11:00:00');
        $this->book('clinic', $assignment, $tuesday, '09:30:00', '10:00:00');
        $slots = $this->appointmentService->getAvailableSlots('clinic', $assignment, $tuesday);
        $this->assertCount(4, $slots);
        $this->assertSame([true, false, true, true], array_column($slots, 'available'));
    }

    public function testDoubleBookingThrowsException(): void
    {
        $date = SchedulingClock::now()->modify('+7 days')->format('Y-m-d');
        $this->window('guidance', $this->guidanceAssignment, $date, '14:00:00', '15:00:00');
        $this->book('guidance', $this->guidanceAssignment, $date, '14:00:00', '14:30:00');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('The selected time slot is already booked.');
        $this->book('guidance', $this->guidanceAssignment, $date, '14:00:00', '14:30:00');
    }

    public function testCancelAppointment(): void
    {
        $assignment = $this->enroll('clinic');
        $date = SchedulingClock::now()->modify('+7 days')->format('Y-m-d');
        $this->window('clinic', $assignment, $date, '10:00:00', '11:00:00');
        $appointment = $this->book('clinic', $assignment, $date, '10:00:00', '10:30:00');
        $this->appointmentService->cancelAppointment((int) $appointment['id'], 'Patient requested reschedule');
        $updated = $this->appointmentModel->find($appointment['id']);
        $this->assertSame('cancelled', $updated['status']);
        $this->assertSame('Patient requested reschedule', $updated['cancellation_reason']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AppointmentModel;
use App\Models\PersonModel;
use App\Models\QueueEntryModel;
use App\Models\UserModel;
use App\Services\Scheduling\QueueService;
use App\Services\Scheduling\SchedulingClock;
use Tests\Support\FreshDatabaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class QueueServiceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private QueueService $queueService;
    private PersonModel $personModel;
    private QueueEntryModel $queueModel;
    private AppointmentModel $appointmentModel;
    private int $staffUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueModel = new QueueEntryModel();
        $this->appointmentModel = new AppointmentModel();
        $this->personModel = new PersonModel();
        $this->queueService = new QueueService($this->queueModel, $this->appointmentModel);
        // queue_entries.called_by_user_id has a foreign key to users.
        $this->staffUserId = (int) (new UserModel())->insert([
            'tenant_id' => 1,
            'username' => 'queue_staff_' . uniqid(),
            'role' => 'clinic_staff',
            'status' => 'active',
        ]);
    }

    public function testAtomicTicketGenerationAndSequencing(): void
    {
        $today = SchedulingClock::today(); // Sequence is unique within this disposable database.
        $person1 = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => '20260001',
            'first_name' => 'John',
            'last_name'  => 'Doe',
        ]);
        $person2 = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => '20260002',
            'first_name' => 'Jane',
            'last_name'  => 'Smith',
        ]);

        $ticket1 = $this->queueService->enqueueWalkIn('clinic', $person1, 'routine', $today);
        $ticket2 = $this->queueService->enqueueWalkIn('clinic', $person2, 'routine', $today);

        $this->assertSame('C-001', $ticket1['ticket_code']);
        $this->assertSame(1, (int) $ticket1['ticket_no']);
        $this->assertSame('waiting', $ticket1['status']);

        $this->assertSame('C-002', $ticket2['ticket_code']);
        $this->assertSame(2, (int) $ticket2['ticket_no']);
    }

    public function testPriorityCallingOrder(): void
    {
        $today = SchedulingClock::today();
        $p1 = (int) $this->personModel->insert(['tenant_id' => 1, 'kind' => 'student', 'mis_id' => 'P1', 'first_name' => 'A', 'last_name' => 'One']);
        $p2 = (int) $this->personModel->insert(['tenant_id' => 1, 'kind' => 'student', 'mis_id' => 'P2', 'first_name' => 'B', 'last_name' => 'Two']);

        // Person 1 arrives first as routine
        $this->queueService->enqueueWalkIn('clinic', $p1, 'routine', $today);

        // Person 2 arrives second as EMERGENCY
        $emergency = $this->queueService->enqueueWalkIn('clinic', $p2, 'emergency', $today);

        // Calling next should pick Person 2 (emergency) despite Person 1 arriving earlier
        $called = $this->queueService->callNext('clinic', $this->staffUserId, $today);

        $this->assertNotNull($called);
        $this->assertSame($emergency['id'], $called['id']);
        $this->assertSame('called', $called['status']);
        $this->assertNotNull($called['called_at']);
    }

    public function testQueueLifecycleTransitions(): void
    {
        $today = SchedulingClock::today();
        $p = (int) $this->personModel->insert(['tenant_id' => 1, 'kind' => 'student', 'mis_id' => 'P3', 'first_name' => 'Lifecycle', 'last_name' => 'User']);

        $entry = $this->queueService->enqueueWalkIn('clinic', $p, 'routine', $today);

        // 1. Call
        $called = $this->queueService->callTicket((int) $entry['id'], $this->staffUserId);
        $this->assertSame('called', $called['status']);

        // 2. Start consultation
        $inSession = $this->queueService->startConsultation((int) $entry['id']);
        $this->assertSame('in_consultation', $inSession['status']);
        $this->assertNotNull($inSession['started_at']);

        // 3. Complete
        $done = $this->queueService->completeConsultation((int) $entry['id']);
        $this->assertSame('done', $done['status']);
        $this->assertNotNull($done['completed_at']);
    }

    public function testSkipAndRecallWindow(): void
    {
        $today = SchedulingClock::today();
        $p = (int) $this->personModel->insert(['tenant_id' => 1, 'kind' => 'student', 'mis_id' => 'P4', 'first_name' => 'Skip', 'last_name' => 'User']);

        $entry = $this->queueService->enqueueWalkIn('clinic', $p, 'routine', $today);
        $this->queueService->callTicket((int) $entry['id'], $this->staffUserId);

        // Skip for 60 minutes
        $skipped = $this->queueService->skipTicket((int) $entry['id'], 60);
        $this->assertSame('skipped', $skipped['status']);
        $this->assertNotNull($skipped['skip_until']);

        // Recall back to waiting
        $recalled = $this->queueService->recallTicket((int) $entry['id']);
        $this->assertSame('waiting', $recalled['status']);
        $this->assertNull($recalled['skip_until']);
    }

    public function testAppointmentPromotionToQueue(): void
    {
        $today = SchedulingClock::today();
        $person = (int) $this->personModel->insert(['tenant_id' => 1, 'kind' => 'student', 'mis_id' => 'P5', 'first_name' => 'Appt', 'last_name' => 'User']);

        $providerPerson = (int) $this->personModel->insert([
            'tenant_id' => 1, 'kind' => 'employee', 'mis_id' => 'P5-PROVIDER',
            'first_name' => 'Provider', 'last_name' => 'User',
        ]);
        $provider = (int) (new UserModel())->insert([
            'tenant_id' => 1, 'person_id' => $providerPerson,
            'username' => 'doctor_' . uniqid(), 'role' => 'clinic_staff', 'status' => 'active',
        ]);
        $this->assertNotFalse((new \App\Models\EmployeeProfileModel())->insert([
            'tenant_id' => 1, 'person_id' => $providerPerson,
            'department' => 'Health Services', 'position' => 'Clinic Staff',
            'employment_status' => 'active',
        ]));
        $assignment = (new \App\Models\DepartmentStaffModel())->insert([
            'tenant_id' => 1, 'person_id' => $providerPerson, 'unit' => 'clinic',
            'status' => 'active', 'enrolled_by_user_id' => $this->staffUserId,
        ]);
        $this->assertNotFalse($assignment);

        $apptId = (int) $this->appointmentModel->insert([
            'tenant_id'           => 1,
            'unit'                => 'clinic',
            'person_id'           => $person,
            'provider_user_id'    => $provider,
            'staff_assignment_id' => $assignment,
            'starts_at'           => $today . ' 09:00:00',
            'ends_at'             => $today . ' 09:30:00',
            'local_date'          => $today,
            'status'              => 'scheduled',
            'type'                => 'routine',
        ]);

        $queueEntry = $this->queueService->enqueueAppointment($apptId);

        $this->assertSame($apptId, (int) $queueEntry['appointment_id']);
        $this->assertSame('waiting', $queueEntry['status']);

        // Check appointment status updated to checked_in
        $updatedAppt = $this->appointmentModel->find($apptId);
        $this->assertSame('checked_in', $updatedAppt['status']);
    }
}

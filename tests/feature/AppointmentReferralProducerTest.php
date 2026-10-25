<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClinicEncounterModel;
use App\Models\CounsellingCaseModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Referrals\ReferralService;
use App\Services\Scheduling\AppointmentService;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FreshDatabaseTestCase;

/** Real migrations and outbox only; the guarded runner owns and drops all fixture data. */
final class AppointmentReferralProducerTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private int $tenant;
    private array $patient;
    private array $clinic;
    private array $guidance;
    private ?string $trigger = null;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('producer_'), 'name' => 'Producer fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->tenant = (int) $this->db->insertID();
        CurrentTenant::set($this->tenant);
        $this->patient = $this->account('student');
        $this->clinic = $this->account('clinic_staff');
        $this->guidance = $this->account('counsellor');
    }

    protected function tearDown(): void
    {
        if ($this->trigger !== null) {
            $this->db->query('DROP TRIGGER IF EXISTS ' . $this->db->protectIdentifiers($this->trigger));
        }
        AuthenticatedUser::clear();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function account(string $role): array
    {
        $person = (int) (new PersonModel())->insert(['tenant_id' => $this->tenant,
            'kind' => $role === 'student' ? 'student' : 'employee', 'mis_id' => uniqid('producer_person_'),
            'first_name' => 'SECRET', 'last_name' => 'PRIVATE']);
        $id = (int) (new UserModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $person,
            'username' => uniqid('producer_user_'), 'role' => $role, 'status' => 'active']);
        // Forged cached authority is deliberately not the stored authority.
        return ['id' => $id, 'person_id' => $person, 'tenant_id' => $this->tenant,
            'role' => 'superadmin', 'permissions' => ['*']];
    }

    private function request(string $unit = 'clinic', bool $personal = true): array
    {
        return (new AppointmentService())->requestAppointment($unit, [
            'person_id' => $this->patient['person_id'], 'date' => date('Y-m-d', strtotime('+7 days')),
            'preferred_time' => '09:00', 'type' => $unit === 'clinic' ? 'routine' : 'initial',
            'reason' => 'SECRET clinical reason',
        ], $personal ? $this->patient : ($unit === 'clinic' ? $this->clinic : $this->guidance), $personal);
    }

    private function events(): array
    {
        return $this->db->table('workflow_events')->where('tenant_id', $this->tenant)->orderBy('id')->get()->getResultArray();
    }

    private function notices(): array
    {
        return $this->db->table('notifications')->where('tenant_id', $this->tenant)->orderBy('id')->get()->getResultArray();
    }

    private function assertEvent(array $event, string $kind, string $unit, int $id, string $occurrence, array $actor): void
    {
        $this->assertSame($this->tenant, (int) $event['tenant_id']);
        $this->assertSame($kind, $event['event_kind']);
        $this->assertSame($unit, $event['unit']);
        $this->assertSame($id, (int) $event['subject_id']);
        $this->assertSame($occurrence, $event['occurrence_key']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.:-]{1,96}$/D', $event['occurrence_key']);
        $this->assertSame((int) $actor['id'], (int) $event['actor_user_id']);
        $this->assertNull($event['processed_at']);
        $this->assertSame(0, (int) $event['attempts']);
        $this->assertStringNotContainsString('SECRET', json_encode($event));
        $this->assertStringNotContainsString('clinical', json_encode($event));
    }

    private function fails(callable $operation): void
    {
        $failure = null;
        try {
            $operation();
        } catch (\Exception $e) {
            $failure = $e;
        }
        $this->assertNotNull($failure, 'The operation must fail, not silently omit a required event.');
        $this->assertSame(0, $this->db->transDepth);
        $this->assertTrue($this->db->transStatus());
    }

    private function failEventInsert(): void
    {
        $this->trigger = uniqid('producer_event_fail_');
        $trigger = $this->db->protectIdentifiers($this->trigger);
        $table = $this->db->protectIdentifiers($this->db->prefixTable('workflow_events'));
        $this->assertNotFalse($this->db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW
            BEGIN IF NEW.tenant_id = {$this->tenant} THEN SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Fixture outbox failure'; END IF; END"));
    }

    public static function appointments(): array
    {
        return ['clinic patient' => ['clinic', true], 'guidance patient' => ['guidance', true],
            'clinic staff' => ['clinic', false], 'guidance staff' => ['guidance', false]];
    }

    #[DataProvider('appointments')]
    public function testRequestAndCancellationProduceVersionedEventsOnly(string $unit, bool $personal): void
    {
        $service = new AppointmentService();
        $staff = $unit === 'clinic' ? $this->clinic : $this->guidance;
        $actor = $personal ? $this->patient : $staff;
        $appt = $this->request($unit, $personal);
        $this->assertCount(1, $this->events());
        $this->assertEvent($this->events()[0], 'appointment_requested_staff', $unit, (int) $appt['id'], '1', $actor);
        $this->assertSame([], $this->notices());
        $this->assertTrue($service->cancelAppointment((int) $appt['id'], 'SECRET cancellation', $unit, $actor, $personal));
        $this->assertCount(2, $this->events());
        $this->assertEvent($this->events()[1], $personal ? 'appointment_cancelled_staff' : 'appointment_cancelled_patient',
            $unit, (int) $appt['id'], '2', $actor);
        $this->assertSame('cancelled', $service->appointment($unit, (int) $appt['id'])['status']);
        $this->fails(fn () => $service->cancelAppointment((int) $appt['id'], '', $unit, $actor, $personal));
        $this->assertCount(2, $this->events());
        $this->assertSame([], $this->notices());
    }

    public function testRequestEventFailureRollsBackInsertionAndCanRetry(): void
    {
        $this->failEventInsert();
        $this->fails(fn () => $this->request());
        $this->assertSame(0, $this->db->table('appointments')->where('tenant_id', $this->tenant)->countAllResults());
        $this->assertSame([], $this->events());
        $this->db->query('DROP TRIGGER ' . $this->db->protectIdentifiers($this->trigger));
        $this->trigger = null;
        $this->request();
        $this->assertCount(1, $this->events());
        $this->assertSame([], $this->notices());
    }

    public function testCancellationEventFailureAndFreshRevocationLeaveAppointmentUntouched(): void
    {
        $appt = $this->request();
        $service = new AppointmentService();
        $this->failEventInsert();
        $this->fails(fn () => $service->cancelAppointment((int) $appt['id'], '', 'clinic', $this->clinic));
        $this->assertSame('requested', $service->appointment('clinic', (int) $appt['id'])['status']);
        $this->assertSame(1, (int) $service->appointment('clinic', (int) $appt['id'])['version']);
        $this->assertCount(1, $this->events());
        $this->db->table('users')->where('id', $this->clinic['id'])->update(['role' => 'student']);
        $this->fails(fn () => $service->cancelAppointment((int) $appt['id'], '', 'clinic', $this->clinic));
        $this->assertCount(1, $this->events());
    }


    private function referral(bool $reverse): array
    {
        $sender = $reverse ? $this->guidance : $this->clinic;
        $data = ['patient_person_id' => $this->patient['person_id'], 'issuer_user_id' => $sender['id'],
            'reason_code' => $reverse ? 'medical_assessment' : 'academic_distress',
            'reason_description' => 'SECRET clinical summary', 'request_key' => bin2hex(random_bytes(32))];
        $service = new ReferralService();
        $ref = $service->createReferral($data, $sender);
        $before = $this->notices();
        $retry = $service->createReferral($data, $sender);
        $this->assertSame($ref['id'], $retry['id']);
        $this->assertSame($before, $this->notices());
        $this->assertSame([], $this->events());
        return $ref;
    }

    private function target(bool $clinic): int
    {
        if ($clinic) {
            return (int) (new ClinicEncounterModel())->insert(['tenant_id' => $this->tenant,
                'person_id' => $this->patient['person_id'], 'attending_user_id' => $this->clinic['id'],
                'status' => 'in_progress', 'started_at' => date('Y-m-d H:i:s'), 'chief_complaint' => 'SECRET complaint']);
        }
        return (int) (new CounsellingCaseModel())->insert(['tenant_id' => $this->tenant,
            'person_id' => $this->patient['person_id'], 'counsellor_user_id' => $this->guidance['id'],
            'case_number' => uniqid('PRODUCER-'), 'title' => 'SECRET title', 'category' => 'personal',
            'status' => 'open', 'opened_at' => date('Y-m-d H:i:s'), 'summary_notes' => 'SECRET summary']);
    }

    public static function handoffs(): array
    {
        return ['link case' => [false, false], 'create case' => [false, true],
            'link encounter' => [true, false], 'create encounter' => [true, true]];
    }

    #[DataProvider('handoffs')]
    public function testReferralTransitionsAndFirstHandoffOnlyProduceSourceEvents(bool $reverse, bool $create): void
    {
        $ref = $this->referral($reverse);
        $service = new ReferralService();
        $actor = $reverse ? $this->clinic : $this->guidance;
        $id = (int) $ref['id'];
        $before = $this->notices();
        $service->acknowledge($id, (int) $actor['id'], $actor);
        $service->review($id, (int) $actor['id'], $actor);
        $data = $create ? ['mode' => 'create', 'title' => 'SECRET case title', 'category' => 'personal']
            : ['mode' => 'link', 'target_id' => $this->target($reverse)];
        $handoff = $service->handoff($id, $data, $actor);
        $target = (int) $handoff['target_id'];
        $retry = $service->handoff($id, $data, $actor);
        $this->assertSame($target, (int) $retry['target_id']);
        $this->assertFalse($retry['created']);
        $service->close($id, $actor);
        $events = $this->events();
        $this->assertCount(4, $events);
        foreach ([['referral_acknowledged', 'acknowledged'], ['referral_reviewed', 'under_review'],
            ['referral_handoff', ($reverse ? 'encounter_' : 'case_') . $target], ['referral_closed', 'closed']] as $i => [$kind, $occurrence]) {
            $this->assertEvent($events[$i], $kind, $ref['source_module'], $id, $occurrence, $actor);
        }
        $this->assertSame($before, $this->notices(), 'New producer hooks must not fan out immediately.');
        $this->fails(fn () => $service->close($id, $actor));
        $this->assertSame($events, $this->events());
    }

    public static function failedTransitions(): array
    {
        $cases = [];
        foreach ([false, true] as $reverse) {
            foreach (['acknowledge', 'review', 'handoff', 'close'] as $action) {
                $cases[($reverse ? 'clinic ' : 'guidance ') . $action] = [$reverse, $action];
            }
        }
        return $cases;
    }

    #[DataProvider('failedTransitions')]
    public function testReferralEventFailureRollsBackTransitionAndFreshActorIsRequired(bool $reverse, string $action): void
    {
        $ref = $this->referral($reverse);
        $actor = $reverse ? $this->clinic : $this->guidance;
        $service = new ReferralService();
        $id = (int) $ref['id'];
        if ($action !== 'acknowledge') {
            $service->acknowledge($id, (int) $actor['id'], $actor);
        }
        $stored = $this->db->table('referrals')->where('id', $id)->get()->getRowArray();
        $events = $this->events();
        $targets = $this->db->table($reverse ? 'clinic_encounters' : 'counselling_cases')
            ->where('tenant_id', $this->tenant)->countAllResults();
        $operation = fn () => match ($action) {
            'acknowledge' => $service->acknowledge($id, (int) $actor['id'], $actor),
            'review' => $service->review($id, (int) $actor['id'], $actor),
            'handoff' => $service->handoff($id, ['mode' => 'create', 'title' => 'SECRET title', 'category' => 'personal'], $actor),
            'close' => $service->close($id, $actor),
        };
        $this->failEventInsert();
        $before = $this->notices();
        $this->fails($operation);
        $this->assertSame($stored, $this->db->table('referrals')->where('id', $id)->get()->getRowArray());
        $this->assertSame($events, $this->events());
        $this->assertSame($targets, $this->db->table($reverse ? 'clinic_encounters' : 'counselling_cases')
            ->where('tenant_id', $this->tenant)->countAllResults(), 'Failed handoff must roll back downstream creation too.');
        $this->assertSame($before, $this->notices());
        $this->db->table('users')->where('id', $actor['id'])->update(['status' => 'suspended']);
        $this->fails($operation);
        $this->assertSame($events, $this->events());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\NotificationService;
use App\Services\PortalContext;
use App\Services\Scheduling\AppointmentService;
use App\Services\WorkflowNotifications;
use App\Services\WorkflowNotificationScanner;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\WorkflowAlerts;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

/** Actual migrated InnoDB outbox and bell; never connects without the fresh-schema guard. */
final class WorkflowNotificationDeliveryTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private int $tenant;
    private DateTimeImmutable $now;
    private WorkflowAlerts $alerts;
    private NotificationService $bell;
    private array $patient;
    private array $clinic;
    private array $guidance;
    private array $operator;
    private array $addedGrants = [];
    private array $removedGrants = [];
    private ?string $trigger = null;
    private bool $realtimeEnabled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->realtimeEnabled = config(\Config\Realtime::class)->enabled;
        config(\Config\Realtime::class)->enabled = false;
        AuthenticatedUser::clear();
        // A real current day also keeps NotificationService's independently constructed
        // authority predicate in the same reminder/expiry window as the injected worker.
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->alerts = new WorkflowAlerts();
        $this->bell = new NotificationService();
        $this->tenant = $this->newTenant();
        $this->patient = $this->account('student');
        $this->clinic = $this->account('clinic_staff');
        $this->guidance = $this->account('counsellor');
        $this->operator = $this->account('facilities_op');
        $this->grant('student', ['portal.appointments.read']);
        $this->grant('clinic_staff', ['clinic.appointments.read', 'clinic.appointments.write', 'referrals.read',
            'clinic.inventory.read', 'clinic.inventory.write']);
        $this->grant('counsellor', ['guidance.schedule.read', 'guidance.schedule.manage', 'referrals.read', 'guidance.cases.read']);
        $this->grant('facilities_op', ['facilities.read', 'facilities.manage']);
        $this->login($this->clinic);
    }

    protected function tearDown(): void
    {
        if ($this->trigger !== null) $this->dropTrigger();
        foreach ($this->removedGrants as $row) $this->db->table('role_permissions')->insert($row);
        foreach ($this->addedGrants as $row) $this->db->table('role_permissions')->where($row)->delete();
        AuthenticatedUser::clear();
        if (isset($this->realtimeEnabled)) config(\Config\Realtime::class)->enabled = $this->realtimeEnabled;
        parent::tearDown();
    }

    private function insert(string $table, array $row): int
    {
        $this->assertTrue($this->db->table($table)->insert($row));
        return (int) $this->db->insertID();
    }

    private function stamp(): string { return $this->now->format('Y-m-d H:i:s'); }
    private function local(string $modify = '+0 seconds'): string
    {
        return $this->now->setTimezone(new DateTimeZone('Asia/Manila'))->modify($modify)->format('Y-m-d H:i:s');
    }

    private function newTenant(): int
    {
        return $this->insert('tenants', ['slug' => 'workflow_' . bin2hex(random_bytes(8)), 'name' => 'Synthetic workflow',
            'is_active' => 1, 'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
    }

    private function account(string $role, ?int $tenant = null): array
    {
        $tenant ??= $this->tenant;
        $nonce = bin2hex(random_bytes(8));
        $person = $this->insert('people', ['tenant_id' => $tenant, 'kind' => $role === 'student' ? 'student' : 'employee',
            'mis_id' => $nonce, 'first_name' => 'SECRET', 'last_name' => 'PRIVATE',
            'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        $id = $this->insert('users', ['tenant_id' => $tenant, 'person_id' => $person, 'username' => 'workflow_' . $nonce,
            'role' => $role, 'status' => 'active', 'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        return ['id' => $id, 'person_id' => $person, 'tenant_id' => $tenant, 'role' => $role];
    }

    private function login(array $user): void
    {
        CurrentTenant::reset();
        session()->set(['tenant_id' => $user['tenant_id'], 'user' => ['id' => $user['id'], 'tenant_id' => $user['tenant_id'],
            'logged_in' => true, 'role' => 'superadmin', 'permissions' => ['*']],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
        CurrentTenant::set($user['tenant_id']);
    }

    private function grant(string $role, array $codes): void
    {
        foreach ($codes as $code) {
            $id = $this->db->table('permissions')->where('code', $code)->get()->getRow('id');
            if ($id === null) $id = $this->insert('permissions', ['code' => $code, 'name' => $code, 'module' => explode('.', $code)[0]]);
            $row = ['role' => $role, 'permission_id' => (int) $id];
            if ($this->db->table('role_permissions')->where($row)->countAllResults() === 0) {
                $this->insert('role_permissions', $row);
                $this->addedGrants[] = $row;
            }
        }
    }

    private function revoke(string $role, string $code): void
    {
        $id = $this->db->table('permissions')->where('code', $code)->get()->getRow('id');
        $row = ['role' => $role, 'permission_id' => (int) $id];
        if ($this->db->table('role_permissions')->where($row)->countAllResults() > 0) {
            $this->removedGrants[] = $row;
            $this->db->table('role_permissions')->where($row)->delete();
        }
    }

    private function appointment(string $unit = 'clinic', string $status = 'requested', array $changes = []): int
    {
        return $this->insert('appointments', array_replace(['tenant_id' => $this->tenant, 'unit' => $unit,
            'person_id' => $this->patient['person_id'], 'status' => $status, 'version' => 1,
            'starts_at' => $this->local('+2 hours'), 'ends_at' => $this->local('+3 hours'),
            'local_date' => substr($this->local('+2 hours'), 0, 10), 'type' => 'routine', 'reason' => 'SECRET reason',
            'created_at' => $this->stamp(), 'updated_at' => $this->stamp()], $changes));
    }

    private function followup(string $date): int
    {
        $case = $this->insert('counselling_cases', ['tenant_id' => $this->tenant, 'person_id' => $this->patient['person_id'],
            'counsellor_user_id' => $this->guidance['id'], 'case_number' => 'WF-' . bin2hex(random_bytes(8)),
            'title' => 'SECRET case', 'summary_notes' => 'PRIVATE notes', 'status' => 'open', 'opened_at' => $this->stamp(),
            'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        return $this->insert('guidance_followups', ['tenant_id' => $this->tenant, 'case_id' => $case, 'due_date' => $date,
            'action_plan' => 'SECRET action plan', 'status' => 'pending', 'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
    }

    private function medicine(): int
    {
        return $this->insert('clinic_medicines', ['tenant_id' => $this->tenant, 'name' => 'SECRET medicine',
            'minimum_stock_level' => 10, 'is_active' => 1, 'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
    }

    private function batch(int $medicine, string $date, array $changes = []): int
    {
        return $this->insert('clinic_medicine_batches', array_replace(['tenant_id' => $this->tenant, 'medicine_id' => $medicine,
            'batch_number' => 'SECRET-' . bin2hex(random_bytes(8)), 'expiration_date' => $date,
            'initial_quantity' => 2, 'remaining_quantity' => 2, 'status' => 'active',
            'created_at' => $this->stamp(), 'updated_at' => $this->stamp()], $changes));
    }

    private function device(): array
    {
        $unit = $this->insert('bmg_units', ['tenant_id' => $this->tenant, 'unit_code' => bin2hex(random_bytes(8)),
            'name' => 'SECRET device location', 'status' => 'active', 'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        $device = $this->insert('bmg_devices', ['tenant_id' => $this->tenant, 'unit_id' => $unit,
            'chip_mac' => bin2hex(random_bytes(6)), 'device_token_hash' => hash('sha256', random_bytes(32)),
            'status' => 'active', 'rotation_bound_at' => $this->local('-2 hours'), 'last_seen_at' => $this->local('-1 hour'),
            'rtc_valid' => 0, 'rtc_reported_at' => $this->local('-1 hour'),
            'created_at' => $this->local('-2 hours'), 'updated_at' => $this->stamp()]);
        return [$unit, $device];
    }

    private function episode(string $kind, int $id, bool $active = true): void
    {
        $this->insert('workflow_alert_states', ['tenant_id' => $this->tenant, 'event_kind' => $kind, 'subject_id' => $id,
            'active' => (int) $active, 'episode_number' => 1, 'started_at' => $this->stamp(),
            'resolved_at' => $active ? null : $this->stamp()]);
    }

    /** Returns [subject id, occurrence, intended recipient, source table]. */
    private function source(string $kind, string $unit): array
    {
        if (str_starts_with($kind, 'appointment_')) {
            $status = $kind === 'appointment_reminder' ? 'confirmed' : ($kind === 'appointment_requested_staff' ? 'requested' : 'cancelled');
            $id = $this->appointment($unit, $status);
            return [$id, $kind === 'appointment_reminder' ? 'v1_' . str_replace(' ', 'T', $this->local('+2 hours')) : '1',
                in_array($kind, ['appointment_reminder', 'appointment_cancelled_patient'], true) ? $this->patient : ($unit === 'clinic' ? $this->clinic : $this->guidance), 'appointments'];
        }
        if (str_starts_with($kind, 'referral_')) {
            $issuer = $unit === 'clinic' ? $this->clinic : $this->guidance;
            $id = $this->insert('referrals', ['tenant_id' => $this->tenant, 'referral_code' => 'WF-' . bin2hex(random_bytes(8)),
                'patient_person_id' => $this->patient['person_id'], 'source_module' => $unit,
                'target_module' => $unit === 'clinic' ? 'guidance' : 'clinic', 'issuer_user_id' => $issuer['id'],
                'reason_code' => 'academic_distress', 'reason_description' => 'SECRET referral summary',
                'status' => $kind === 'referral_closed' ? 'closed' : 'under_review',
                'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
            return [$id, '1', $issuer, 'referrals'];
        }
        if (str_starts_with($kind, 'guidance_')) {
            $date = substr($this->local($kind === 'guidance_followup_due' ? '+0 days' : '-1 day'), 0, 10);
            return [$this->followup($date), $date, $this->guidance, 'guidance_followups'];
        }
        if (str_starts_with($kind, 'inventory_')) {
            $medicine = $this->medicine();
            if ($kind === 'inventory_low_stock') {
                $this->episode($kind, $medicine);
                return [$medicine, 'episode_1', $this->clinic, 'clinic_medicines'];
            }
            $date = substr($this->local($kind === 'inventory_expired' ? '-1 day' : '+1 day'), 0, 10);
            return [$this->batch($medicine, $date), $date, $this->clinic, 'clinic_medicine_batches'];
        }
        [$drum, $device] = $this->device();
        if (str_starts_with($kind, 'bmg_command_')) {
            $outcome = substr($kind, strlen('bmg_command_'));
            $id = $this->insert('bmg_rotation_commands', ['tenant_id' => $this->tenant, 'unit_id' => $drum, 'device_id' => $device,
                'command' => 'rotate', 'created_by_user_id' => $this->operator['id'],
                'status' => in_array($outcome, ['cancelled', 'expired'], true) ? $outcome : 'acknowledged',
                'ack_status' => in_array($outcome, ['cancelled', 'expired'], true) ? null : $outcome,
                'ack_message' => 'SECRET controller message', 'created_at' => $this->local('-2 hours'), 'expires_at' => $this->local('-1 hour')]);
            return [$id, 'terminal', $this->operator, 'bmg_rotation_commands'];
        }
        if (str_starts_with($kind, 'bmg_run_')) {
            $id = $this->insert('bmg_rotation_runs', ['tenant_id' => $this->tenant, 'unit_id' => $drum, 'device_id' => $device,
                'run_uid' => bin2hex(random_bytes(8)), 'motor_state' => substr($kind, strlen('bmg_run_')),
                'duration_seconds' => 60, 'occurred_at' => $this->local(), 'created_at' => $this->stamp()]);
            return [$id, '1', $this->operator, 'bmg_rotation_runs'];
        }
        $opening = str_starts_with($kind, 'bmg_device_') ? 'bmg_device_offline' : 'bmg_rtc_invalid';
        $this->episode($opening, $device, in_array($kind, ['bmg_device_offline', 'bmg_rtc_invalid'], true));
        return [$device, 'episode_1', $this->operator, 'bmg_devices'];
    }

    private function record(string $kind, string $unit, int $id, string $occurrence, ?int $actor = null): int
    {
        $this->assertTrue($this->db->transBegin());
        try {
            WorkflowNotifications::record($this->db, $kind, $unit, $id, $occurrence, $actor);
            $this->assertTrue($this->db->transCommit());
        } catch (\Throwable $error) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
            throw $error;
        }
        return (int) $this->db->table('workflow_events')->selectMax('id')->where('tenant_id', $this->tenant)->get()->getRow('id');
    }

    private function worker(?DateTimeImmutable $now = null): WorkflowNotifications
    {
        // record() timestamps with wall time, so don't freeze the worker before record().
        return new WorkflowNotifications($this->db, $this->alerts, $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    private function notices(): array
    {
        return $this->db->table('notifications')->where('tenant_id', $this->tenant)->where('type', 'workflow_alert')->orderBy('id')->get()->getResultArray();
    }

    private function events(): array
    {
        return $this->db->table('workflow_events')->where('tenant_id', $this->tenant)->orderBy('id')->get()->getResultArray();
    }

    private function denied(int $code, callable $operation): void
    {
        try { $operation(); $this->fail('Expected workflow operation to be rejected.'); }
        catch (RuntimeException $error) { $this->assertSame($code, $error->getCode(), $error->getMessage()); }
    }

    private function hidden(array $user, int $id): void
    {
        $this->login($user);
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
        $this->assertFalse($this->bell->canReceiveInvalidation($user['tenant_id'], $user['id'], [$id]));
        $this->denied(404, fn () => $this->bell->markRead($id));
        $this->bell->markAllRead();
        $this->assertNull($this->db->table('notifications')->where('id', $id)->get()->getRow('read_at'));
    }

    public static function kinds(): array
    {
        return [
            'appointment request' => ['appointment_requested_staff', 'clinic', 'Appointment request', 'clinic', 'clinic/appointments'],
            'staff cancellation' => ['appointment_cancelled_patient', 'guidance', 'Appointment cancelled', 'main', 'portal#my-appointments'],
            'self-service cancellation' => ['appointment_cancelled_staff', 'guidance', 'Appointment cancelled', 'guidance', 'guidance/appointments'],
            'reminder' => ['appointment_reminder', 'clinic', 'Upcoming appointment', 'main', 'portal#my-appointments'],
            'referral acknowledged' => ['referral_acknowledged', 'clinic', 'Referral acknowledged', 'clinic', 'clinic/referrals?tab=sent'],
            'referral reviewed' => ['referral_reviewed', 'guidance', 'Referral under review', 'guidance', 'guidance/referrals?tab=sent'],
            'referral handoff' => ['referral_handoff', 'clinic', 'Referral handoff completed', 'clinic', 'clinic/referrals?tab=sent'],
            'referral closed' => ['referral_closed', 'guidance', 'Referral closed', 'guidance', 'guidance/referrals?tab=history'],
            'followup due' => ['guidance_followup_due', 'guidance', 'Follow-up due', 'guidance', 'guidance/cases'],
            'followup overdue' => ['guidance_followup_overdue', 'guidance', 'Follow-up overdue', 'guidance', 'guidance/cases'],
            'low stock' => ['inventory_low_stock', 'clinic', 'Medicine stock low', 'clinic', 'clinic/inventory'],
            'near expiry' => ['inventory_near_expiry', 'clinic', 'Medicine stock nearing expiry', 'clinic', 'clinic/inventory'],
            'expired stock' => ['inventory_expired', 'clinic', 'Expired medicine stock', 'clinic', 'clinic/inventory'],
            'command complete' => ['bmg_command_completed', 'facilities', 'BMG command reported complete', 'bmg', 'facilities'],
            'command failed' => ['bmg_command_failed', 'facilities', 'BMG command failed', 'bmg', 'facilities'],
            'command rejected' => ['bmg_command_rejected', 'facilities', 'BMG command rejected', 'bmg', 'facilities'],
            'command cancelled' => ['bmg_command_cancelled', 'facilities', 'BMG command cancelled', 'bmg', 'facilities'],
            'command expired' => ['bmg_command_expired', 'facilities', 'BMG command expired', 'bmg', 'facilities'],
            'run failed' => ['bmg_run_failed', 'facilities', 'BMG rotation failure reported', 'bmg', 'facilities'],
            'run interrupted' => ['bmg_run_interrupted', 'facilities', 'BMG rotation interruption reported', 'bmg', 'facilities'],
            'device offline' => ['bmg_device_offline', 'facilities', 'BMG contact overdue', 'bmg', 'facilities'],
            'device recovered' => ['bmg_device_recovered', 'facilities', 'BMG contact restored', 'bmg', 'facilities'],
            'rtc invalid' => ['bmg_rtc_invalid', 'facilities', 'BMG clock fault reported', 'bmg', 'facilities'],
            'rtc recovered' => ['bmg_rtc_recovered', 'facilities', 'BMG clock recovery reported', 'bmg', 'facilities'],
        ];
    }

    #[DataProvider('kinds')]
    public function testEveryKindDeliversIdentifierOnlyRowsAndAnAuthorizedGenericInboxLink(
        string $kind, string $unit, string $title, string $portal, string $path,
    ): void {
        [$source, $occurrence, $recipient, $table] = $this->source($kind, $unit);
        $eventId = $this->record($kind, $unit, $source, $occurrence);
        $this->assertSame([], $this->notices());
        $this->assertSame(['processed' => 1, 'failed' => 0], $this->worker()->dispatch(1));
        $rows = $this->notices();
        $this->assertCount(1, $rows, $kind);
        $personal = in_array($kind, ['appointment_cancelled_patient', 'appointment_reminder'], true);
        $this->assertSame($personal ? null : $recipient['id'], $rows[0]['recipient_user_id'] === null ? null : (int) $rows[0]['recipient_user_id']);
        $this->assertSame($personal ? $recipient['person_id'] : null, $rows[0]['recipient_person_id'] === null ? null : (int) $rows[0]['recipient_person_id']);
        $this->assertSame($eventId, (int) $rows[0]['workflow_event_id']);
        foreach (['appointment_id', 'appointment_version', 'referral_id', 'announcement_id', 'survey_id',
            'team_unit', 'team_event_id', 'team_task_id', 'team_version', 'team_comment_id'] as $column) $this->assertNull($rows[0][$column]);
        $event = $this->events()[0];
        $this->assertSame($table, $event['subject_kind']);
        $this->assertSame($source, (int) $event['subject_id']);
        $this->assertSame(hash('sha256', implode('|', [$kind, $unit, $source, $occurrence])), $event['event_key']);
        $this->assertNotNull($event['processed_at']);
        $this->assertSame(1, (int) $event['attempts']);
        $this->login($recipient);
        $inbox = $this->bell->ownInbox();
        $this->assertSame(1, $inbox['unread_count']);
        $this->assertCount(1, $inbox['items']);
        $item = $inbox['items'][0];
        $this->assertSame(['id', 'title', 'message', 'created_at', 'read_at', 'url'], array_keys($item));
        $this->assertSame($title, $item['title']);
        $expectedUrl = $portal === 'main'
            ? PortalContext::urlFor(PortalContext::PORTAL_MAIN, 'portal') . '#my-appointments'
            : PortalContext::urlFor($portal, $path);
        $this->assertSame($expectedUrl, $item['url']);
        $this->assertNotEmpty($item['message']);
        foreach (['SECRET', 'PRIVATE', 'action_plan', 'device_token_hash', 'reason_description'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode([$event, $rows, $inbox], JSON_THROW_ON_ERROR));
        }
        $this->assertTrue($this->bell->canReceiveInvalidation($this->tenant, $recipient['id'], [(int) $item['id']]));
        $this->bell->markRead((int) $item['id']);
        $read = $this->bell->ownInbox();
        $this->assertSame(0, $read['unread_count']);
        $this->assertNotNull($read['items'][0]['read_at']);
        $this->bell->markRead((int) $item['id']);
        $this->assertSame($read, $this->bell->ownInbox());
        $this->record($kind, $unit, $source, $occurrence);
        $this->assertSame(['processed' => 0, 'failed' => 0], $this->worker()->dispatch(1));
        $this->assertCount(1, $this->events());
        $this->assertCount(1, $this->notices());
    }

    public function testCatalogFixtureCoversEverySupportedKind(): void
    {
        $this->assertCount(24, self::kinds());
        $this->assertSame(WorkflowNotifications::kinds(), array_column(array_values(self::kinds()), 0));
    }

    public static function departments(): array { return [['clinic'], ['guidance']]; }

    #[DataProvider('departments')]
    public function testSchedulersAreActiveSameUnitGrantedStaffAndNeverTheActor(string $unit): void
    {
        $role = $unit === 'clinic' ? 'clinic_staff' : 'counsellor';
        $actor = $unit === 'clinic' ? $this->clinic : $this->guidance;
        $recipient = $this->account($role);
        $inactive = $this->account($role);
        $deleted = $this->account($role);
        $this->db->table('users')->where('id', $inactive['id'])->update(['status' => 'suspended']);
        $this->db->table('users')->where('id', $deleted['id'])->update(['deleted_at' => $this->stamp()]);
        $foreign = $this->account($role, $this->newTenant());
        $source = $this->appointment($unit);
        $this->record('appointment_requested_staff', $unit, $source, '1', $actor['id']);
        $this->worker()->dispatch(1);
        $this->assertSame([$recipient['id']], array_map('intval', array_column($this->notices(), 'recipient_user_id')));
        $id = (int) $this->notices()[0]['id'];
        foreach ([$actor, $inactive, $deleted, $foreign, $this->patient, $this->operator] as $excluded) {
            $this->assertFalse($this->bell->canReceiveInvalidation($excluded['tenant_id'], $excluded['id'], [$id]));
        }
        $this->revoke($role, $unit === 'clinic' ? 'clinic.appointments.write' : 'guidance.schedule.manage');
        $this->hidden($recipient, $id);
        $this->record('appointment_requested_staff', $unit, $source, '2', $actor['id']);
        $this->worker()->dispatch(1);
        $this->assertCount(1, $this->notices(), 'Revoked staff must not receive a new delivery either.');
    }

    #[DataProvider('departments')]
    public function testStaffSelfServiceCancellationReachesSuperadminWithoutInventingAPatientActor(string $unit): void
    {
        $actor = $unit === 'clinic' ? $this->clinic : $this->guidance;
        $admin = $this->account('superadmin');
        $this->grant($actor['role'], ['portal.appointments.create', 'portal.appointments.read']);
        $id = $this->appointment($unit, 'confirmed', ['person_id' => $actor['person_id']]);
        $this->login($actor);
        $this->assertTrue((new AppointmentService())->cancelAppointment($id, 'SECRET cancellation', $unit, $actor, true));
        $this->assertSame(0, $this->worker()->dispatch(20)['failed']);
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame('appointment_cancelled_staff', $events[0]['event_kind']);
        $this->assertSame($actor['id'], (int) $events[0]['actor_user_id']);

        $this->login($admin);
        $inbox = $this->bell->ownInbox();
        $this->assertCount(1, $inbox['items']);
        $item = $inbox['items'][0];
        $this->assertSame('Appointment cancelled', $item['title']);
        $this->assertSame('An appointment was cancelled. Review the department schedule.', $item['message']);
        $this->assertSame(PortalContext::urlFor($unit, $unit . '/appointments'), $item['url']);
        $this->assertStringNotContainsString('SECRET', json_encode($item, JSON_THROW_ON_ERROR));
        $this->bell->markRead($item['id']);
        $page = $this->bell->ownPage();
        $this->assertSame('Appointment cancelled', $page['items'][0]['title']);
        $this->assertNotNull($page['items'][0]['read_at']);
        $this->assertSame(0, $page['unread_count']);
        $this->login($actor);
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
    }

    #[DataProvider('departments')]
    public function testStaffConsoleCancellationNotifiesPatientWithoutManufacturingASchedulerEvent(string $unit): void
    {
        $actor = $unit === 'clinic' ? $this->clinic : $this->guidance;
        $admin = $this->account('superadmin');
        $id = $this->appointment($unit, 'confirmed');
        $this->login($actor);
        $this->assertTrue((new AppointmentService())->cancelAppointment($id, 'SECRET department cancellation', $unit, $actor, false));
        $this->assertSame(0, $this->worker()->dispatch(20)['failed']);
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame('appointment_cancelled_patient', $events[0]['event_kind']);
        $this->assertSame($actor['id'], (int) $events[0]['actor_user_id']);
        $this->login($this->patient);
        $inbox = $this->bell->ownInbox();
        $this->assertCount(1, $inbox['items']);
        $this->assertSame('Appointment cancelled', $inbox['items'][0]['title']);
        $this->assertSame('An appointment was cancelled. Open your appointments for details.', $inbox['items'][0]['message']);
        $this->login($admin);
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
    }

    public function testCancellationAudienceAndSelfNotificationExclusion(): void
    {
        $id = $this->appointment('clinic', 'cancelled');
        $this->record('appointment_cancelled_patient', 'clinic', $id, '1', $this->clinic['id']);
        $this->record('appointment_cancelled_staff', 'clinic', $id, '2', $this->patient['id']);
        $this->record('appointment_cancelled_patient', 'clinic', $id, '3', $this->patient['id']);
        $this->record('appointment_cancelled_staff', 'clinic', $id, '4', $this->clinic['id']);
        $this->assertSame(['processed' => 4, 'failed' => 0], $this->worker()->dispatch(4));
        $this->assertCount(2, $this->notices());
        $this->assertSame($this->patient['person_id'], (int) $this->notices()[0]['recipient_person_id']);
        $this->assertSame($this->clinic['id'], (int) $this->notices()[1]['recipient_user_id']);
    }

    #[DataProvider('departments')]
    public function testReferralGoesOnlyToSourceIssuerAndClosedHistoryRetainsFreshAuthority(string $unit): void
    {
        [$source, $occurrence, $issuer] = $this->source('referral_acknowledged', $unit);
        $other = $this->account($issuer['role']);
        $target = $unit === 'clinic' ? $this->guidance : $this->clinic;
        $this->record('referral_acknowledged', $unit, $source, $occurrence, $target['id']);
        $this->worker()->dispatch(1);
        $this->assertSame([$issuer['id']], array_map('intval', array_column($this->notices(), 'recipient_user_id')));
        $old = (int) $this->notices()[0]['id'];
        $this->hidden($other, $old);
        $this->db->table('referrals')->where('id', $source)->update(['status' => 'closed']);
        $this->hidden($issuer, $old);
        $this->record('referral_closed', $unit, $source, 'closed', $target['id']);
        $this->worker()->dispatch(1);
        $this->login($issuer);
        $inbox = $this->bell->ownInbox();
        $this->assertSame(1, $inbox['unread_count']);
        $this->assertSame(PortalContext::urlFor($unit, $unit . '/referrals?tab=history'), $inbox['items'][0]['url']);
        $this->revoke($issuer['role'], 'referrals.read');
        $this->hidden($issuer, (int) $this->notices()[1]['id']);
    }

    public function testFollowupsRequireCurrentAssignedCounsellorPendingCaseAndDueDate(): void
    {
        $other = $this->account('counsellor');
        $due = $this->followup(substr($this->local(), 0, 10));
        $overdue = $this->followup(substr($this->local('-1 day'), 0, 10));
        $future = $this->followup(substr($this->local('+2 days'), 0, 10));
        foreach ([[$due, 'guidance_followup_due'], [$overdue, 'guidance_followup_overdue'], [$future, 'guidance_followup_due']] as [$id, $kind]) {
            $this->record($kind, 'guidance', $id, '1');
        }
        $this->worker()->dispatch(3);
        $this->assertSame([$this->guidance['id'], $this->guidance['id']], array_map('intval', array_column($this->notices(), 'recipient_user_id')));
        $id = (int) $this->notices()[0]['id'];
        $this->db->table('guidance_followups')->where('id', $overdue)->update(['status' => 'completed']);
        $case = $this->db->table('guidance_followups')->where('id', $due)->get()->getRow('case_id');
        $this->db->table('counselling_cases')->where('id', $case)->update(['counsellor_user_id' => $other['id']]);
        $this->hidden($this->guidance, $id);
        $this->hidden($other, $id);
    }

    public function testInventoryRequiresBothGrantsAndCurrentActiveUnexpiredOrExpiredStock(): void
    {
        $medicine = $this->medicine();
        $near = $this->batch($medicine, substr($this->local('+1 day'), 0, 10));
        $expired = $this->batch($medicine, substr($this->local('-1 day'), 0, 10));
        $far = $this->batch($medicine, substr($this->local('+200 days'), 0, 10));
        foreach ([[$near, 'inventory_near_expiry'], [$expired, 'inventory_expired'], [$far, 'inventory_near_expiry'], [$near, 'inventory_expired']] as [$id, $kind]) {
            $this->record($kind, 'clinic', $id, '1');
        }
        $this->worker()->dispatch(4);
        $this->assertCount(2, $this->notices());
        $this->db->table('clinic_medicine_batches')->where('id', $expired)->update(['remaining_quantity' => 0]);
        $this->db->table('clinic_medicines')->where('id', $medicine)->update(['is_active' => 0]);
        $this->hidden($this->clinic, (int) $this->notices()[0]['id']);
        $this->db->table('clinic_medicines')->where('id', $medicine)->update(['is_active' => 1]);
        $this->revoke('clinic_staff', 'clinic.inventory.write');
        $this->hidden($this->clinic, (int) $this->notices()[0]['id']);
        $this->record('inventory_near_expiry', 'clinic', $near, '2');
        $this->worker()->dispatch(1);
        $this->assertCount(2, $this->notices());
    }

    public function testLowStockRechecksUsableQuantityAndThresholdBeforeDeliveryAndEveryRead(): void
    {
        $medicine = $this->medicine();
        $this->episode('inventory_low_stock', $medicine);
        $this->record('inventory_low_stock', 'clinic', $medicine, 'episode_1');
        $batch = $this->batch($medicine, substr($this->local('+30 days'), 0, 10), ['initial_quantity' => 20, 'remaining_quantity' => 20]);
        $this->assertSame(['processed' => 1, 'failed' => 0], $this->worker()->dispatch(1));
        $this->assertSame([], $this->notices(), 'Replenishment must suppress queued low-stock alerts before another scan.');
        $this->db->table('clinic_medicine_batches')->where('id', $batch)->update(['remaining_quantity' => 2]);
        $this->record('inventory_low_stock', 'clinic', $medicine, 'episode_1');
        // A processed, recipient-less occurrence is not backfilled. A later real episode is distinct.
        $this->db->table('workflow_alert_states')->where('tenant_id', $this->tenant)->where('subject_id', $medicine)
            ->where('event_kind', 'inventory_low_stock')->update(['episode_number' => 2]);
        $this->record('inventory_low_stock', 'clinic', $medicine, 'episode_2');
        $this->worker()->dispatch(1);
        $this->assertCount(1, $this->notices());
        $notice = (int) $this->notices()[0]['id'];
        $this->db->table('clinic_medicines')->where('id', $medicine)->update(['minimum_stock_level' => 1]);
        $this->hidden($this->clinic, $notice);
        $this->db->table('clinic_medicines')->where('id', $medicine)->update(['minimum_stock_level' => 10]);
        $this->db->table('clinic_medicine_batches')->where('id', $batch)->update(['remaining_quantity' => 20]);
        $this->hidden($this->clinic, $notice);
    }

    public static function deviceEpisodeKinds(): array
    {
        return array_map(static fn ($kind) => [$kind], ['bmg_device_offline', 'bmg_device_recovered', 'bmg_rtc_invalid', 'bmg_rtc_recovered']);
    }

    #[DataProvider('deviceEpisodeKinds')]
    public function testRebindingSuppressesOldOpeningAndRecoveryEpisodesBeforeAndAfterDispatch(string $kind): void
    {
        foreach ([false, true] as $deliverFirst) {
            [$device] = $this->source($kind, 'facilities');
            $opening = str_starts_with($kind, 'bmg_device_') ? 'bmg_device_offline' : 'bmg_rtc_invalid';
            $this->db->table('workflow_alert_states')->where('tenant_id', $this->tenant)->where('event_kind', $opening)->where('subject_id', $device)
                ->update(['started_at' => $this->now->modify('-10 minutes')->format('Y-m-d H:i:s')]);
            $this->record($kind, 'facilities', $device, 'episode_1');
            if ($deliverFirst) {
                $this->worker()->dispatch(1);
                $this->assertCount(1, $this->notices());
            }
            $this->db->table('bmg_devices')->where('id', $device)->update([
                'rotation_bound_at' => $this->local('-1 minute'), 'last_seen_at' => null, 'rtc_valid' => null, 'rtc_reported_at' => null,
            ]);
            if (!$deliverFirst) {
                $this->assertSame(['processed' => 1, 'failed' => 0], $this->worker()->dispatch(1));
                $this->assertSame([], $this->notices(), 'The old binding must not alert during the new binding grace period.');
            } else {
                $notice = (int) $this->notices()[0]['id'];
                $this->hidden($this->operator, $notice);
                $this->assertSame(0, (new WorkflowNotificationScanner($this->db, $this->alerts, $this->now))->scan()['failed']);
                $this->hidden($this->operator, $notice);
            }
        }
    }

    public function testBmgRequesterOnlyAndOperationsFaultsExcludeActorAndRequireBothGrants(): void
    {
        $other = $this->account('facilities_op');
        [$command, $occurrence] = $this->source('bmg_command_failed', 'facilities');
        $this->record('bmg_command_failed', 'facilities', $command, $occurrence);
        $this->worker()->dispatch(1);
        $this->assertSame([$this->operator['id']], array_map('intval', array_column($this->notices(), 'recipient_user_id')));
        $this->hidden($other, (int) $this->notices()[0]['id']);
        [$run] = $this->source('bmg_run_failed', 'facilities');
        $this->record('bmg_run_failed', 'facilities', $run, '1', $this->operator['id']);
        $this->worker()->dispatch(1);
        $this->assertSame($other['id'], (int) $this->notices()[1]['recipient_user_id']);
        $this->revoke('facilities_op', 'facilities.manage');
        $this->hidden($other, (int) $this->notices()[1]['id']);
        $this->record('bmg_run_failed', 'facilities', $run, '2');
        $this->worker()->dispatch(1);
        $this->assertCount(2, $this->notices());
    }

    public function testActualDueScannerRecordsDeduplicatesThenDispatchesAndReadsWithoutDomainMutation(): void
    {
        $appointment = $this->appointment('clinic', 'confirmed');
        $this->followup(substr($this->local(), 0, 10));
        $medicine = $this->medicine();
        $this->batch($medicine, substr($this->local('+1 day'), 0, 10));
        $this->batch($medicine, substr($this->local('-1 day'), 0, 10));
        $this->alerts->batchLimit = 1;
        $tables = ['appointments', 'guidance_followups', 'clinic_medicines', 'clinic_medicine_batches', 'bmg_rotation_commands'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $this->db->table($table)->where('tenant_id', $this->tenant)->orderBy('id')->get()->getResultArray();
        $scanner = new WorkflowNotificationScanner($this->db, $this->alerts, $this->now);
        $scan = $scanner->scan();
        $this->assertSame(0, $scan['failed']);
        $this->assertSame(5, $scan['recorded']);
        $this->assertCount(5, $this->events());
        $this->assertSame([], $this->notices());
        $this->assertSame(0, $scanner->scan()['failed']);
        $this->assertCount(5, $this->events());
        $this->assertSame(['processed' => 2, 'failed' => 0], $this->worker()->dispatch(2));
        $this->assertSame(['processed' => 3, 'failed' => 0], $this->worker()->dispatch(5));
        $this->assertCount(5, $this->notices());
        foreach ($before as $table => $rows) $this->assertSame($rows, $this->db->table($table)->where('tenant_id', $this->tenant)->orderBy('id')->get()->getResultArray(), $table);
        $this->login($this->patient);
        $inbox = $this->bell->ownInbox();
        $this->assertSame(1, $inbox['unread_count']);
        $this->assertSame(PortalContext::urlFor(PortalContext::PORTAL_MAIN, 'portal') . '#my-appointments', $inbox['items'][0]['url']);
        $this->bell->markRead((int) $inbox['items'][0]['id']);
        $this->assertSame(0, $this->bell->ownInbox()['unread_count']);
        $this->db->table('appointments')->where('id', $appointment)->update(['version' => 2, 'starts_at' => $this->local('+4 hours')]);
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox(), 'Old reminder occurrence must disappear after rescheduling.');
    }

    public function testOutboxRequiresOwningTransactionCannotFanoutPrecommitAndRollbackCanRetry(): void
    {
        $source = $this->appointment();
        $this->denied(503, fn () => WorkflowNotifications::record($this->db, 'appointment_requested_staff', 'clinic', $source, '1'));
        $this->assertTrue($this->db->transBegin());
        try {
            WorkflowNotifications::record($this->db, 'appointment_requested_staff', 'clinic', $source, '1');
            $this->assertSame([], $this->notices());
            $this->denied(0, fn () => $this->worker()->dispatch(1));
        } finally {
            $this->assertTrue($this->db->transRollback());
            $this->db->resetTransStatus();
        }
        $this->assertSame([], $this->events());
        $this->assertSame([], $this->notices());
        $this->record('appointment_requested_staff', 'clinic', $source, '1');
        $this->worker()->dispatch(1);
        $this->assertCount(1, $this->notices());
        // Reopening a successfully delivered event simulates a worker replay, not a new occurrence.
        $this->db->table('workflow_events')->where('id', $this->events()[0]['id'])->update(['processed_at' => null]);
        $this->worker()->dispatch(1);
        $this->assertCount(1, $this->notices());
    }

    private function dropTrigger(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS ' . $this->db->protectIdentifiers($this->trigger));
        $this->trigger = null;
    }

    public function testWorkerFailureRollsBackEntireFanoutBacksOffRetriesAndStopsAtMaxAttempts(): void
    {
        $this->account('clinic_staff');
        $source = $this->appointment();
        $event = $this->record('appointment_requested_staff', 'clinic', $source, '1');
        $this->trigger = 'workflow_fail_' . bin2hex(random_bytes(8));
        $trigger = $this->db->protectIdentifiers($this->trigger);
        // Reject the second recipient, proving that the first recipient is rolled back too.
        $recipient = $this->clinic['id'];
        $this->db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON notifications FOR EACH ROW
            BEGIN IF NEW.tenant_id = {$this->tenant} AND NEW.type = 'workflow_alert' AND NEW.recipient_user_id <> {$recipient}
            THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SECRET synthetic delivery failure'; END IF; END");
        $this->alerts->maxAttempts = 2;
        $clock = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->assertSame(['processed' => 0, 'failed' => 1], $this->worker($clock)->dispatch(1));
        $row = $this->events()[0];
        $this->assertSame([], $this->notices());
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertNull($row['processed_at']);
        $this->assertSame($clock->modify('+2 minutes')->format('Y-m-d H:i:s'), $row['available_at']);
        $this->assertNotEmpty($row['last_error']);
        $this->assertStringNotContainsString('SECRET', $row['last_error']);
        $this->assertSame(['processed' => 0, 'failed' => 0], $this->worker($clock->modify('+1 minute'))->dispatch(1));
        $this->assertSame(['processed' => 0, 'failed' => 1], $this->worker($clock->modify('+2 minutes'))->dispatch(1));
        $this->assertSame(2, (int) $this->events()[0]['attempts']);
        $this->assertSame($clock->modify('+6 minutes')->format('Y-m-d H:i:s'), $this->events()[0]['available_at']);
        $this->dropTrigger();
        $this->assertSame(['processed' => 0, 'failed' => 0], $this->worker($clock->modify('+1 day'))->dispatch(1));
        $this->assertSame([], $this->notices());
        $this->db->table('workflow_events')->where('id', $event)->update(['attempts' => 1]);
        $this->assertSame(['processed' => 1, 'failed' => 0], $this->worker($clock->modify('+1 day'))->dispatch(1));
        $this->assertCount(2, $this->notices());
        $this->assertNull($this->events()[0]['last_error']);
        $this->assertSame(0, $this->db->transDepth);
        $this->assertTrue($this->db->transStatus());
    }

    public function testFailureBackoffCapsAtSixtyMinutesAndDisabledWorkerDoesNothing(): void
    {
        $source = $this->appointment();
        $event = $this->record('appointment_requested_staff', 'clinic', $source, 'cap');
        $clock = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->db->table('workflow_events')->where('id', $event)->update([
            'subject_kind' => 'referrals', 'attempts' => 5, 'available_at' => $clock->format('Y-m-d H:i:s'),
        ]);
        $this->alerts->maxAttempts = 6;
        $this->alerts->enabled = false;
        $this->assertSame(['processed' => 0, 'failed' => 0], $this->worker($clock)->dispatch(1));
        $this->assertSame(5, (int) $this->events()[0]['attempts']);
        $this->alerts->enabled = true;
        $this->assertSame(['processed' => 0, 'failed' => 1], $this->worker($clock)->dispatch(1));
        $this->assertSame(6, (int) $this->events()[0]['attempts']);
        $this->assertSame($clock->modify('+60 minutes')->format('Y-m-d H:i:s'), $this->events()[0]['available_at']);
        $this->assertSame(['processed' => 0, 'failed' => 0], $this->worker($clock->modify('+2 hours'))->dispatch(1));
        $this->assertSame([], $this->notices());
    }

    public function testTenantIsolationBadSubjectShapeAndBoundedDispatch(): void
    {
        $source = $this->appointment();
        $foreign = $this->account('clinic_staff', $this->newTenant());
        $this->denied(503, fn () => $this->record('appointment_requested_staff', 'guidance', $source, '1'));
        $this->denied(503, fn () => $this->record('appointment_requested_staff', 'clinic', $source, '1', $foreign['id']));
        $this->denied(503, fn () => $this->record('appointment_requested_staff', 'clinic', PHP_INT_MAX, '1'));
        foreach ([['unknown_kind', 'clinic', $source, '1'], ['appointment_requested_staff', 'clinic', 0, '1'],
            ['appointment_requested_staff', 'clinic', $source, 'SECRET free text'],
            ['appointment_requested_staff', 'clinic', $source, str_repeat('a', 97)]] as $args) {
            try { $this->record(...$args); $this->fail('Invalid source shape must be rejected.'); }
            catch (InvalidArgumentException $error) { $this->assertSame(422, $error->getCode()); }
        }
        $event = $this->record('appointment_requested_staff', 'clinic', $source, '1');
        $this->login($foreign);
        $this->assertSame(['processed' => 0, 'failed' => 0], $this->worker()->dispatch(1));
        $this->login($this->clinic);
        foreach ([0, -1, $this->alerts->maximumDispatchLimit + 1] as $limit) {
            try { $this->worker()->dispatch($limit); $this->fail('Unbounded/invalid limit accepted.'); }
            catch (InvalidArgumentException) { $this->assertSame([], $this->notices()); }
        }
        // Valid storage but a mismatched polymorphic source must never fan out.
        $this->db->table('workflow_events')->where('id', $event)->update(['subject_kind' => 'referrals']);
        $this->assertSame(['processed' => 0, 'failed' => 1], $this->worker()->dispatch(1));
        $this->assertSame([], $this->notices());
        $this->assertNull($this->events()[0]['processed_at']);
    }
}

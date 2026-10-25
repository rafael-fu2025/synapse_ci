<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\WorkflowAlerts;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Identifier-only transactional outbox. Dispatch separately; never fan out during care/device writes. */
final class WorkflowNotifications
{
    /** kind => [subject table, audience, title, generic message] */
    private const EVENTS = [
        'appointment_requested_staff' => ['appointments', 'schedulers', 'Appointment request', 'An appointment request needs scheduling review.'],
        'appointment_cancelled_patient' => ['appointments', 'patient', 'Appointment cancelled', 'An appointment was cancelled. Open your appointments for details.'],
        'appointment_cancelled_staff' => ['appointments', 'schedulers', 'Appointment cancelled by patient', 'A patient cancelled an appointment. Review the department schedule.'],
        'appointment_reminder' => ['appointments', 'patient', 'Upcoming appointment', 'An appointment is approaching. Open your appointments for details.'],
        'referral_acknowledged' => ['referrals', 'issuer', 'Referral acknowledged', 'The receiving department acknowledged a referral you sent.'],
        'referral_reviewed' => ['referrals', 'issuer', 'Referral under review', 'The receiving department started reviewing a referral you sent.'],
        'referral_handoff' => ['referrals', 'issuer', 'Referral handoff completed', 'Receiving care was linked to a referral you sent.'],
        'referral_closed' => ['referrals', 'issuer', 'Referral closed', 'The receiving department closed a referral you sent.'],
        'guidance_followup_due' => ['guidance_followups', 'counsellor', 'Follow-up due', 'An internal follow-up assigned to your case is due. Open Guidance cases.'],
        'guidance_followup_overdue' => ['guidance_followups', 'counsellor', 'Follow-up overdue', 'An internal follow-up remains pending. Review your Guidance cases.'],
        'inventory_low_stock' => ['clinic_medicines', 'inventory', 'Medicine stock low', 'An active medicine needs stock review. Open medicine inventory.'],
        'inventory_near_expiry' => ['clinic_medicine_batches', 'inventory', 'Medicine stock nearing expiry', 'Stock is approaching its expiration date. Open medicine inventory.'],
        'inventory_expired' => ['clinic_medicine_batches', 'inventory', 'Expired medicine stock', 'Expired stock remains on hand. Review it in medicine inventory.'],
        'bmg_command_completed' => ['bmg_rotation_commands', 'requester', 'BMG command reported complete', 'A controller reported a command complete. This is not independent verification of physical operation.'],
        'bmg_command_failed' => ['bmg_rotation_commands', 'requester', 'BMG command failed', 'A controller reported a command failure. Review Facilities operations.'],
        'bmg_command_rejected' => ['bmg_rotation_commands', 'requester', 'BMG command rejected', 'A controller rejected a command. Review Facilities operations.'],
        'bmg_command_cancelled' => ['bmg_rotation_commands', 'requester', 'BMG command cancelled', 'A pending command was cancelled. Review Facilities operations.'],
        'bmg_command_expired' => ['bmg_rotation_commands', 'requester', 'BMG command expired', 'A command deadline elapsed without an accepted completion. Review Facilities operations.'],
        'bmg_run_failed' => ['bmg_rotation_runs', 'facilities', 'BMG rotation failure reported', 'A controller reported a failed rotation. Review Facilities operations.'],
        'bmg_run_interrupted' => ['bmg_rotation_runs', 'facilities', 'BMG rotation interruption reported', 'A controller reported an interrupted rotation. Review Facilities operations.'],
        'bmg_device_offline' => ['bmg_devices', 'facilities', 'BMG contact overdue', 'An active controller has not contacted the server within the configured interval.'],
        'bmg_device_recovered' => ['bmg_devices', 'facilities', 'BMG contact restored', 'Controller contact resumed after an overdue-contact alert. This does not verify motor operation.'],
        'bmg_rtc_invalid' => ['bmg_devices', 'facilities', 'BMG clock fault reported', 'A controller explicitly reported an invalid clock. Review Facilities operations.'],
        'bmg_rtc_recovered' => ['bmg_devices', 'facilities', 'BMG clock recovery reported', 'A controller explicitly reported clock recovery after a clock fault.'],
    ];

    private BaseConnection $db;
    private WorkflowAlerts $config;
    public function __construct(?BaseConnection $db = null, ?WorkflowAlerts $config = null, private ?DateTimeImmutable $now = null)
    {
        $this->db = $db ?? Database::connect();
        $this->config = $config ?? config(WorkflowAlerts::class);
    }

    public static function available(?BaseConnection $db = null): bool
    {
        $db ??= Database::connect();
        return $db->tableExists('workflow_events') && $db->fieldExists('workflow_event_id', 'notifications');
    }

    public static function kinds(): array { return array_keys(self::EVENTS); }

    public static function record(BaseConnection $db, string $kind, string $unit, int $subjectId, string $occurrence, ?int $actorId = null): void
    {
        if (!isset(self::EVENTS[$kind]) || !in_array($unit, ['clinic', 'guidance', 'facilities'], true)
            || $subjectId < 1 || !preg_match('/\A[A-Za-z0-9_.:-]{1,96}\z/D', $occurrence) || ($actorId !== null && $actorId < 1)) {
            throw new InvalidArgumentException('Invalid workflow event.', 422);
        }
        if ($db->transDepth < 1 || !$db->transStatus()) throw new RuntimeException('Workflow events require the owning transaction.', 503);
        $table = self::EVENTS[$kind][0];
        $source = $db->table($table)->where('id', $subjectId)->get()->getRowArray();
        $tenant = (int) ($source['tenant_id'] ?? 0);
        $sourceUnit = match ($table) {
            'appointments' => $source['unit'] ?? null,
            'referrals' => $source['source_module'] ?? null,
            'guidance_followups' => 'guidance',
            'clinic_medicines', 'clinic_medicine_batches' => 'clinic',
            default => 'facilities',
        };
        if ($tenant < 1 || $sourceUnit !== $unit || ($actorId !== null
            && $db->table('users')->where('tenant_id', $tenant)->where('id', $actorId)->countAllResults() !== 1)) {
            throw new RuntimeException('Workflow source not found in the expected scope.', 503);
        }
        // Device APIs derive tenant from their persisted source, not an unrelated human session.
        // A command deadline has one identity whether discovered by polling or the scheduled scanner.
        if ($kind === 'bmg_command_expired') $occurrence = 'terminal';
        $key = hash('sha256', implode('|', [$kind, $unit, $subjectId, $occurrence]));
        $identity = ['tenant_id' => $tenant, 'event_key' => $key];
        if ($db->table('workflow_events')->where($identity)->countAllResults() > 0) return;
        $stamp = gmdate('Y-m-d H:i:s');
        if (!$db->table('workflow_events')->insert($identity + [
            'unit' => $unit, 'event_kind' => $kind, 'subject_kind' => $table, 'subject_id' => $subjectId,
            'actor_user_id' => $actorId, 'occurrence_key' => $occurrence, 'occurred_at' => $stamp,
            'created_at' => $stamp, 'available_at' => $stamp, 'attempts' => 0, 'processed_at' => null, 'last_error' => null,
        ])) throw new RuntimeException('Unable to record workflow event.', 503);
    }

    /** Finite current-tenant batch. Row locks and delivery keys serialize concurrent workers. */
    public function dispatch(int $limit = 100): array
    {
        if ($limit < 1 || $limit > $this->config->maximumDispatchLimit || $this->config->maxAttempts < 1) throw new InvalidArgumentException('Invalid dispatch configuration.');
        if ($this->db->transDepth !== 0) throw new RuntimeException('Workflow dispatch requires independent transactions.');
        $result = ['processed' => 0, 'failed' => 0];
        if (!$this->config->enabled) return $result;
        if (!self::available($this->db)) throw new RuntimeException('Workflow notification migration is required.', 503);
        $tenant = CurrentTenant::id();
        if ($this->db->table('tenants')->where('id', $tenant)->where('is_active', 1)->where('deleted_at', null)->countAllResults() !== 1) return $result;
        $stamp = $this->clock()->format('Y-m-d H:i:s');
        $pending = $this->db->table('workflow_events')->select('id')->where('tenant_id', $tenant)->where('processed_at', null)
            ->where('available_at <=', $stamp)->where('attempts <', $this->config->maxAttempts)->orderBy('id')->limit($limit)->get()->getResultArray();
        foreach ($pending as $candidate) {
            $event = null;
            $ids = [];
            $this->db->resetTransStatus();
            if (!$this->db->transBegin()) throw new RuntimeException('Unable to begin notification delivery.');
            try {
                $event = $this->db->query('SELECT * FROM ' . $this->table('workflow_events') . ' WHERE tenant_id=? AND id=?'
                    . ($this->db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : ''), [$tenant, $candidate['id']])->getRowArray();
                if ($event === null || $event['processed_at'] !== null || $event['available_at'] > $stamp || (int) $event['attempts'] >= $this->config->maxAttempts) {
                    $this->must($this->db->transCommit());
                    continue;
                }
                $definition = self::EVENTS[$event['event_kind']] ?? null;
                if ($definition === null || $definition[0] !== $event['subject_kind']) throw new RuntimeException('Unsupported workflow event.');
                if ($definition[1] === 'patient') {
                    $person = $this->personalRecipient($event);
                    if ($person !== null) $ids = $this->deliver($event, ['recipient_person_id' => $person, 'recipient_user_id' => null]);
                } else {
                    $users = $this->candidates($event, $definition[1]);
                    foreach ($users as $user) {
                        $scope = ['tenant_id' => $tenant, 'recipient_user_id' => (int) $user['id'],
                            'recipient_person_id' => $user['person_id'] === null ? null : (int) $user['person_id']];
                        if ($this->eligibleEvents($scope)->where('we.id', $event['id'])->countAllResults() > 0) {
                            $ids = [...$ids, ...$this->deliver($event, ['recipient_user_id' => (int) $user['id'], 'recipient_person_id' => null])];
                        }
                    }
                }
                $this->must($this->db->table('workflow_events')->where('tenant_id', $tenant)->where('id', $event['id'])
                    ->update(['attempts' => (int) $event['attempts'] + 1, 'processed_at' => $stamp, 'last_error' => null]));
                if (!$this->db->transStatus()) throw new RuntimeException('Notification delivery transaction failed.');
                $this->must($this->db->transCommit());
                $result['processed']++;
            } catch (Throwable) {
                $this->db->transRollback();
                $this->db->resetTransStatus();
                $result['failed']++;
                $ids = [];
                if ($event !== null) {
                    $attempt = (int) $event['attempts'] + 1;
                    $this->db->table('workflow_events')->where('tenant_id', $tenant)->where('id', $event['id'])
                        ->where('processed_at', null)->where('attempts', $event['attempts'])->update([
                            'attempts' => $attempt, 'last_error' => 'DELIVERY_FAILED',
                            'available_at' => $this->clock()->modify('+' . min(60, 2 ** min(6, $attempt)) . ' minutes')->format('Y-m-d H:i:s'),
                        ]);
                }
            }
            if ($ids !== []) (new NotificationInvalidation($this->db))->publishCommitted($tenant, $ids);
        }
        return $result;
    }

    private function candidates(array $event, string $audience): array
    {
        $query = $this->db->table('users')->select('id,person_id')->where('tenant_id', $event['tenant_id'])
            ->where('status', 'active')->where('deleted_at', null)
            ->whereIn('role', PortalContext::allowedRoles($event['unit'] === 'facilities' ? 'bmg' : $event['unit']));
        if (in_array($audience, ['issuer', 'counsellor', 'requester'], true)) {
            $source = $this->db->table($event['subject_kind'])->where('tenant_id', $event['tenant_id'])->where('id', $event['subject_id'])->get()->getRowArray();
            $owner = match ($audience) {
                'issuer' => $source['issuer_user_id'] ?? null,
                'requester' => $source['created_by_user_id'] ?? null,
                default => $source === null ? null : $this->db->table('counselling_cases')->where('tenant_id', $event['tenant_id'])
                    ->where('id', $source['case_id'])->get()->getRow('counsellor_user_id'),
            };
            if ($owner === null) return [];
            $query->where('id', $owner);
        }
        if ($event['actor_user_id'] !== null) $query->where('id !=', $event['actor_user_id']);
        return $query->orderBy('id')->get()->getResultArray();
    }

    private function deliver(array $event, array $recipient): array
    {
        $key = ['tenant_id' => $event['tenant_id'], 'type' => 'workflow_alert', 'workflow_event_id' => $event['id']] + $recipient;
        if ($this->db->table('notifications')->where($key)->countAllResults() > 0) return [];
        $this->must($this->db->table('notifications')->insert($key + ['created_at' => $this->clock()->format('Y-m-d H:i:s'), 'read_at' => null]));
        return [(int) $this->db->insertID()];
    }

    private function personalRecipient(array $event): ?int
    {
        $source = $this->db->table('appointments')->where('tenant_id', $event['tenant_id'])->where('id', $event['subject_id'])
            ->where('unit', $event['unit'])->where('deleted_at', null)->get()->getRowArray();
        if ($source === null || !$this->appointmentCurrent($event['event_kind'], $source)) return null;
        if ($event['event_kind'] === 'appointment_reminder' && $event['occurrence_key'] !== 'v' . $source['version'] . '_' . str_replace(' ', 'T', $source['starts_at'])) return null;
        $id = (int) $source['person_id'];
        if ($this->db->table('people')->where('tenant_id', $event['tenant_id'])->where('id', $id)->where('deleted_at', null)->countAllResults() !== 1) return null;
        if ($event['actor_user_id'] !== null && $this->db->table('users')->where('tenant_id', $event['tenant_id'])
            ->where('id', $event['actor_user_id'])->where('person_id', $id)->countAllResults() > 0) return null;
        return $id;
    }

    private function appointmentCurrent(string $kind, array $source): bool
    {
        return match ($kind) {
            'appointment_requested_staff' => $source['status'] === 'requested',
            'appointment_cancelled_staff', 'appointment_cancelled_patient' => $source['status'] === 'cancelled',
            'appointment_reminder' => in_array($source['status'], ['confirmed', 'scheduled'], true)
                && $source['starts_at'] >= $this->localNow() && $source['starts_at'] <= $this->clock()->setTimezone(new DateTimeZone('Asia/Manila'))
                    ->modify('+' . $this->config->appointmentLeadHours . ' hours')->format('Y-m-d H:i:s'),
            default => false,
        };
    }

    /** One source/authority predicate reused for delivery, inbox, counts, reads and realtime. */
    public function eligibleEvents(array $scope, ?string $notificationTable = null): BaseBuilder
    {
        $query = $this->db->table('workflow_events we')->select('we.id')->where('we.tenant_id', $scope['tenant_id']);
        if ($notificationTable !== null) $query->where("we.id={$notificationTable}.workflow_event_id AND we.tenant_id={$notificationTable}.tenant_id", null, false);
        $actor = (new AuthenticatedUser())->forIdentity((int) $scope['recipient_user_id'], (int) $scope['tenant_id']);
        if ($actor === null) return $query->where('1=0', null, false);
        $user = (int) $actor['id'];
        $person = (int) ($actor['person_id'] ?? 0);
        $grant = static fn (string $permission): bool => $actor['role'] === 'superadmin' || in_array($permission, $actor['permissions'], true);
        $member = static fn (string $unit): bool => in_array($actor['role'], PortalContext::allowedRoles($unit === 'facilities' ? 'bmg' : $unit), true);
        $query->groupStart()->where('1=0', null, false);
        foreach (self::EVENTS as $kind => [$table, $audience]) {
            $source = $this->db->table($table . ' ws')->select('1', false)
                ->where('ws.tenant_id=we.tenant_id AND ws.id=we.subject_id', null, false);
            $allowed = false;
            $unit = match ($audience) { 'counsellor' => 'guidance', 'inventory' => 'clinic', 'facilities', 'requester' => 'facilities', default => null };
            if ($unit !== null) $source->where('we.unit', $unit);
            if ($table === 'appointments') {
                $source->where('ws.unit=we.unit', null, false)->where('ws.deleted_at', null)
                    ->join('people workflow_patient', 'workflow_patient.tenant_id=ws.tenant_id AND workflow_patient.id=ws.person_id AND workflow_patient.deleted_at IS NULL');
                if ($audience === 'patient') {
                    $allowed = $person > 0 && $grant('portal.appointments.read');
                    $source->where('ws.person_id', $person);
                    if ($notificationTable !== null) $source->where("{$notificationTable}.recipient_person_id", $person)->where("{$notificationTable}.recipient_user_id", null);
                } else {
                    $allowed = true;
                    $source->groupStart()->where('1=0', null, false);
                    foreach (['clinic', 'guidance'] as $department) {
                        if ($member($department) && $grant($department === 'clinic' ? 'clinic.appointments.read' : 'guidance.schedule.read')
                            && $grant($department === 'clinic' ? 'clinic.appointments.write' : 'guidance.schedule.manage')) {
                            $source->orWhere('we.unit', $department);
                        }
                    }
                    $source->groupEnd();
                }
                if ($kind === 'appointment_requested_staff') $source->where('ws.status', 'requested');
                elseif ($kind === 'appointment_reminder') {
                    $occurrence = $this->db->DBDriver === 'SQLite3'
                        ? "'v' || ws.version || '_' || REPLACE(ws.starts_at,' ','T')"
                        : "CONCAT('v',ws.version,'_',REPLACE(ws.starts_at,' ','T'))";
                    $source->whereIn('ws.status', ['confirmed', 'scheduled'])
                        ->where('ws.starts_at >=', $this->localNow())->where('ws.starts_at <=', $this->clock()->setTimezone(new DateTimeZone('Asia/Manila'))->modify('+' . $this->config->appointmentLeadHours . ' hours')->format('Y-m-d H:i:s'))
                        ->where('we.occurrence_key=' . $occurrence, null, false);
                }
                else $source->where('ws.status', 'cancelled');
            } elseif ($table === 'referrals') {
                $allowed = $grant('referrals.read');
                $source->join('people workflow_patient', 'workflow_patient.tenant_id=ws.tenant_id AND workflow_patient.id=ws.patient_person_id AND workflow_patient.deleted_at IS NULL')
                    ->where('ws.source_module=we.unit', null, false)->where('ws.issuer_user_id', $user)->groupStart()->where('1=0', null, false);
                foreach (['clinic', 'guidance'] as $department) if ($member($department)) $source->orWhere('we.unit', $department);
                $source->groupEnd();
                if ($kind === 'referral_closed') $source->where('ws.status', 'closed');
                else $source->where('ws.status !=', 'closed');
            } elseif ($audience === 'counsellor') {
                $allowed = $member('guidance') && $grant('guidance.cases.read');
                $source->join('counselling_cases workflow_case', 'workflow_case.tenant_id=ws.tenant_id AND workflow_case.id=ws.case_id')
                    ->join('people workflow_patient', 'workflow_patient.tenant_id=workflow_case.tenant_id AND workflow_patient.id=workflow_case.person_id AND workflow_patient.deleted_at IS NULL')
                    ->where('workflow_case.counsellor_user_id', $user)->where('workflow_case.deleted_at', null)->where('workflow_case.status !=', 'closed')
                    ->where('ws.status', 'pending');
                $kind === 'guidance_followup_due' ? $source->where('ws.due_date', substr($this->localNow(), 0, 10)) : $source->where('ws.due_date <', substr($this->localNow(), 0, 10));
            } elseif ($audience === 'inventory') {
                $allowed = $member('clinic') && $grant('clinic.inventory.read') && $grant('clinic.inventory.write');
                if ($table === 'clinic_medicines') {
                    $source->where('ws.is_active', 1);
                    $stock = $this->db->table('clinic_medicine_batches workflow_batch')->select('COALESCE(SUM(workflow_batch.remaining_quantity),0)', false)
                        ->where('workflow_batch.tenant_id=ws.tenant_id AND workflow_batch.medicine_id=ws.id', null, false)
                        ->where('workflow_batch.status', 'active')->where('workflow_batch.expiration_date >=', substr($this->localNow(), 0, 10));
                    $source->where('(' . $stock->getCompiledSelect() . ') <= ws.minimum_stock_level', null, false);
                    $this->activeEpisode($source, $kind, true);
                } else {
                    $source->join('clinic_medicines workflow_medicine', 'workflow_medicine.tenant_id=ws.tenant_id AND workflow_medicine.id=ws.medicine_id')
                        ->where('workflow_medicine.is_active', 1)->where('ws.status', 'active')->where('ws.remaining_quantity >', 0);
                    if ($kind === 'inventory_expired') $source->where('ws.expiration_date <', substr($this->localNow(), 0, 10));
                    else $source->where('ws.expiration_date >=', substr($this->localNow(), 0, 10))
                        ->where('ws.expiration_date <=', $this->clock()->setTimezone(new DateTimeZone('Asia/Manila'))->modify('+' . $this->config->nearExpiryDays . ' days')->format('Y-m-d'));
                }
            } else {
                $allowed = $member('facilities') && $grant('facilities.read') && $grant('facilities.manage');
                $source->join('bmg_units workflow_unit', 'workflow_unit.tenant_id=ws.tenant_id AND workflow_unit.id=ws.unit_id')
                    ->where('workflow_unit.deleted_at', null);
                if ($audience === 'requester') {
                    $source->where('ws.created_by_user_id', $user);
                    if ($kind === 'bmg_command_expired') $source->groupStart()->where('ws.status', 'expired')
                        ->orGroupStart()->where('ws.status', 'pending')->where('ws.expires_at <', $this->localNow())->groupEnd()->groupEnd();
                    elseif ($kind === 'bmg_command_cancelled') $source->where('ws.status', 'cancelled');
                    else $source->where('ws.status', 'acknowledged')->where('ws.ack_status', substr($kind, strlen('bmg_command_')));
                } elseif ($table === 'bmg_rotation_runs') {
                    $source->where('ws.motor_state', $kind === 'bmg_run_failed' ? 'failed' : 'interrupted');
                } else {
                    $source->where('workflow_unit.status', 'active')->where('ws.status', 'active')->where('ws.revoked_at', null)->where('ws.rotation_bound_at !=', null);
                    $this->activeEpisode($source, str_starts_with($kind, 'bmg_device_') ? 'bmg_device_offline' : 'bmg_rtc_invalid',
                        in_array($kind, ['bmg_device_offline', 'bmg_rtc_invalid'], true), true);
                }
            }
            if (!$allowed) continue;
            if ($audience !== 'patient') {
                $source->groupStart()->where('we.actor_user_id', null)->orWhere('we.actor_user_id !=', $user)->groupEnd();
                if ($notificationTable !== null) $source->where("{$notificationTable}.recipient_user_id", $user)->where("{$notificationTable}.recipient_person_id", null);
            }
            $query->orGroupStart()->where('we.event_kind', $kind)->where('we.subject_kind', $table)
                ->where('EXISTS (' . $source->getCompiledSelect() . ')', null, false)->groupEnd();
        }
        return $query->groupEnd();
    }

    private function activeEpisode(BaseBuilder $source, string $opening, bool $active, bool $currentBinding = false): void
    {
        $number = $this->db->DBDriver === 'SQLite3' ? "'episode_' || episode_number" : "CONCAT('episode_',episode_number)";
        $state = $this->db->table('workflow_alert_states')->select('1', false)->where('tenant_id=we.tenant_id AND subject_id=we.subject_id', null, false)
            ->where('event_kind', $opening)->where('active', $active ? 1 : 0)->where('we.occurrence_key=' . $number, null, false);
        if ($currentBinding) {
            // Domain binding times are Manila wall time; episode times are UTC.
            $binding = $this->db->DBDriver === 'SQLite3' ? "datetime(ws.rotation_bound_at,'-8 hours')" : 'DATE_SUB(ws.rotation_bound_at, INTERVAL 8 HOUR)';
            $state->where('started_at >= ' . $binding, null, false);
        }
        $source->where('EXISTS (' . $state->getCompiledSelect() . ')', null, false);
    }

    public static function presentation(string $kind, string $unit, array $destinations): array
    {
        $definition = self::EVENTS[$kind] ?? null;
        if ($definition === null || !in_array($unit, ['clinic', 'guidance', 'facilities'], true)) throw new RuntimeException('Unknown workflow notification.');
        $destination = match ($definition[1]) {
            'patient' => 'portal',
            'schedulers' => $unit . 'Appointments',
            'issuer' => $unit . ($kind === 'referral_closed' ? 'ReferralHistory' : 'ReferralSent'),
            'counsellor' => 'guidanceCases',
            'inventory' => 'clinicInventory',
            default => 'facilitiesOperations',
        };
        return ['title' => $definition[2], 'message' => $definition[3], 'url' => $destinations[$destination]];
    }

    private function clock(): DateTimeImmutable { return ($this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC')); }
    private function localNow(): string { return $this->clock()->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s'); }
    private function table(string $name): string { return $this->db->protectIdentifiers($this->db->prefixTable($name)); }
    private function must(bool $result): void { if (!$result) throw new RuntimeException('Workflow notification write failed.'); }
}

<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\AppointmentModel;
use App\Models\QueueEntryModel;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;

final class QueueService
{
    private QueueEntryModel $queueModel;
    private AppointmentModel $appointmentModel;
    private BaseConnection $db;
    private array $invalidations = [];
    private ?\Closure $invalidate;
    private ?\Closure $captureInvalidation = null;

    public function __construct(
        ?QueueEntryModel $queueModel = null,
        ?AppointmentModel $appointmentModel = null,
        ?BaseConnection $db = null,
        ?callable $invalidate = null
    ) {
        $this->invalidate = $invalidate === null ? null : \Closure::fromCallable($invalidate);
        $this->db = $db ?? \Config\Database::connect();
        $this->queueModel = $queueModel ?? new QueueEntryModel($this->db);
        $this->appointmentModel = $appointmentModel ?? new AppointmentModel($this->db);
    }

    /**
     * Enqueue a walk-in patient.
     *
     * @return array<string, mixed>
     */
    public function enqueueWalkIn(
        string $unit,
        int $personId,
        string $priority = 'routine',
        ?string $queueDate = null
    ): array {
        $this->assertUnit($unit);
        $date = $queueDate ?? SchedulingClock::today();
        if ($date !== SchedulingClock::today()) {
            throw new \InvalidArgumentException('New tickets may only be issued for today in Manila.', 422);
        }
        $validPriority = in_array($priority, ['routine', 'urgent', 'emergency'], true) ? $priority : 'routine';

        return $this->transaction(function () use ($unit, $personId, $date, $validPriority): array {
            $people = $this->db->prefixTable('people');
            if ($this->db->query("SELECT id FROM {$people} WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE",
                [$personId, CurrentTenant::id()])->getRowArray() === null) {
                throw new \InvalidArgumentException('A valid patient in this tenant is required.', 422);
            }
            if ($this->queueModel->scoped()->where('unit', $unit)->where('person_id', $personId)->where('queue_date', $date)
                ->whereIn('status', ['waiting', 'called', 'in_consultation', 'skipped'])->first() !== null) {
                throw new \RuntimeException('This patient already has an active or skipped ticket. Resolve it explicitly before issuing another.', 409);
            }
            $ticket = $this->queueModel->issueNextTicket($unit, $date);
            $id = (int) $this->queueModel->insert([
                'tenant_id'   => CurrentTenant::id(),
                'unit'        => $unit,
                'queue_date'  => $date,
                'ticket_no'   => $ticket['ticket_no'],
                'ticket_code' => $ticket['ticket_code'],
                'person_id'   => $personId,
                'status'      => 'waiting',
                'priority'    => $validPriority,
            ]);
            if ($id < 1) {
                throw new \RuntimeException('Unable to issue queue ticket.');
            }
            $entry = $this->queueModel->scoped()->find($id);
            $this->recordInvalidation($entry);
            return $entry;
        });
    }

    /**
     * Check in an appointment and promote it to an active queue ticket.
     *
     * @return array<string, mixed>
     */
    public function enqueueAppointment(int $appointmentId, ?string $unit = null, ?array $actor = null): array
    {
        $service = new AppointmentService();
        if ($actor !== null) {
            $service->assertStaff((string) $unit, $actor);
            $user = (new \App\Models\UserModel())->scoped()->where('status', 'active')->find((int) ($actor['id'] ?? 0));
            if ($user === null) {
                throw new \DomainException('A valid active staff account is required.', 403);
            }
            $service->assertStaff((string) $unit, $user);
        }
        if ($unit !== null) {
            $service->assertUnit($unit);
        }
        $candidate = $this->appointmentModel->scoped()->find($appointmentId);
        if ($candidate === null || ($unit !== null && $candidate['unit'] !== $unit)) {
            throw new \InvalidArgumentException('Appointment not found.', 404);
        }
        $assignment = $service->staffAssignment($candidate['unit'], (int) ($candidate['staff_assignment_id'] ?? 0));
        $db = \Config\Database::connect();
        if ($db->transDepth !== 0) {
            throw new \RuntimeException('Appointment changes require an independent transaction.');
        }
        $db->resetTransStatus();
        if ($db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || !$db->transBegin()) {
            throw new \RuntimeException('Unable to begin appointment check-in.');
        }
        try {
            $people = $db->prefixTable('people');
            if ($db->query("SELECT id FROM {$people} WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE",
                [$assignment['person_id'], CurrentTenant::id()])->getRowArray() === null) {
                throw new \RuntimeException('Provider is no longer available.', 409);
            }
            $staff = $db->prefixTable('department_staff');
            $locked = $db->query("SELECT * FROM {$staff} WHERE id = ? AND tenant_id = ? FOR UPDATE",
                [$assignment['id'], CurrentTenant::id()])->getRowArray();
            if ($locked === null || (int) $locked['person_id'] !== (int) $assignment['person_id']) {
                throw new \RuntimeException('Staff assignment changed. Reload before check-in.', 409);
            }
            $service->staffAssignment($candidate['unit'], (int) $assignment['id']);
            $table = $db->prefixTable('appointments');
            $sql = "SELECT * FROM {$table} WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL";
            $params = [$appointmentId, CurrentTenant::id()];
            if ($unit !== null) {
                $sql .= ' AND unit = ?';
                $params[] = $unit;
            }
            $appt = $db->query($sql . ' FOR UPDATE', $params)->getRowArray();
            if ($appt === null) {
                throw new \InvalidArgumentException('Appointment not found.', 404);
            }
            if ($appt['unit'] !== $candidate['unit'] || (int) ($appt['staff_assignment_id'] ?? 0) !== (int) $assignment['id']) {
                throw new \RuntimeException('Appointment changed. Reload before check-in.', 409);
            }
            $unit = $appt['unit'];
            $date = $appt['local_date'];
            if ($date !== SchedulingClock::today()) {
                throw new \RuntimeException('Appointments may only be checked in on their appointment date.', 409);
            }
            $existing = $this->queueModel->scoped()->where('unit', $unit)
                ->where('appointment_id', $appointmentId)->first();
            $changed = false;
            if ($appt['status'] === 'checked_in' && $existing !== null) {
                $entry = $existing;
            } else {
                if (!in_array($appt['status'], ['scheduled', 'confirmed'], true) || empty($appt['staff_assignment_id']) || $existing !== null) {
                    throw new \RuntimeException('Only assigned scheduled or confirmed appointments can be checked in.', 409);
                }
                $service->person((int) $appt['person_id']);

                $ticket = $this->queueModel->issueNextTicket($unit, $date);
                $queueId = $this->queueModel->insert([
                    'tenant_id' => CurrentTenant::id(), 'unit' => $unit, 'queue_date' => $date,
                    'ticket_no' => $ticket['ticket_no'], 'ticket_code' => $ticket['ticket_code'],
                    'person_id' => (int) $appt['person_id'], 'appointment_id' => $appointmentId,
                    'status' => 'waiting', 'priority' => $appt['type'] === 'crisis' ? 'urgent' : 'routine',
                ]);
                if ($queueId === false || !$this->appointmentModel->scoped()->where('unit', $unit)->update($appointmentId,
                    ['status' => 'checked_in', 'version' => (int) $appt['version'] + 1])) {
                    throw new \RuntimeException('Unable to check in appointment.');
                }
                $entry = $this->queueModel->scoped()->find($queueId);
                $changed = true;
            }
            if (!$db->transStatus() || !$db->transCommit()) {
                throw new \RuntimeException('Unable to check in appointment.');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();
            throw $e;
        }
        if ($changed) {
            $this->publishInvalidation((int) $entry['tenant_id'], $entry['unit'], $entry['queue_date'], (int) $entry['person_id']);
        }
        return $entry;
    }

    /**
     * Call the next waiting patient in line based on priority and sequence.
     *
     * @return array<string, mixed>|null
     */
    public function callNext(string $unit, int $calledByUserId, ?string $queueDate = null): ?array
    {
        $this->assertUnit($unit);
        $this->assertActor($calledByUserId);
        $date = $queueDate ?? SchedulingClock::today();

        return $this->transaction(function () use ($unit, $calledByUserId, $date): ?array {
            $table = $this->db->prefixTable('queue_entries');
            $next = $this->db->query("SELECT * FROM {$table}
                WHERE tenant_id = ? AND unit = ? AND queue_date = ? AND status = 'waiting'
                ORDER BY FIELD(priority, 'emergency', 'urgent', 'routine'), ticket_no, id
                LIMIT 1 FOR UPDATE", [CurrentTenant::id(), $unit, $date])->getRowArray();
            if ($next === null) {
                return null;
            }

            return $this->updateLockedEntry($next, [
                'status' => 'called', 'called_at' => SchedulingClock::timestamp(),
                'called_by_user_id' => $calledByUserId, 'skip_until' => null,
            ]);
        });
    }

    /**
     * Call a specific ticket.
     *
     * @return array<string, mixed>
     */
    public function callTicket(int $queueEntryId, int $calledByUserId, ?string $unit = null): array
    {
        $this->assertActor($calledByUserId);
        return $this->transition($queueEntryId, $unit, 'waiting', [
            'status' => 'called', 'called_at' => SchedulingClock::timestamp(),
            'called_by_user_id' => $calledByUserId, 'skip_until' => null,
        ]);
    }

    /**
     * Start consultation with the patient.
     *
     * @return array<string, mixed>
     */
    public function startConsultation(int $queueEntryId, ?string $unit = null): array
    {
        // Opening a chart after the queue's Start action must not reset the start time.
        return $this->transition($queueEntryId, $unit, 'called', [
            'status' => 'in_consultation', 'started_at' => SchedulingClock::timestamp(),
        ], true);
    }

    /**
     * Mark ticket as completed.
     *
     * @return array<string, mixed>
     */
    public function completeConsultation(int $queueEntryId, ?string $unit = null): array
    {
        return $this->transaction(function () use ($queueEntryId, $unit): array {
            $candidate = $this->queueModel->scoped()->find($queueEntryId);
            if ($candidate === null || ($unit !== null && $candidate['unit'] !== $unit)) {
                throw new \InvalidArgumentException('Queue ticket not found.', 404);
            }

            // Check-in locks the appointment before its queue ticket. Keep that lock order.
            $appointment = null;
            if (!empty($candidate['appointment_id'])) {
                $table = $this->db->prefixTable('appointments');
                $appointment = $this->db->query("SELECT * FROM {$table}
                    WHERE id = ? AND tenant_id = ? AND unit = ? AND deleted_at IS NULL FOR UPDATE",
                    [$candidate['appointment_id'], CurrentTenant::id(), $candidate['unit']])->getRowArray();
                if ($appointment === null) {
                    throw new \RuntimeException('Linked appointment is unavailable.', 409);
                }
            }

            $entry = $this->lockEntry($queueEntryId, $unit);
            if (($entry['appointment_id'] ?? null) != ($candidate['appointment_id'] ?? null)) {
                throw new \RuntimeException('Queue ticket changed. Reload before completing.', 409);
            }
            if ($entry['status'] === 'done') {
                return $entry;
            }
            $this->assertState($entry, 'in_consultation');
            if ($appointment !== null) {
                if ($appointment['status'] !== 'checked_in') {
                    throw new \RuntimeException('Linked appointment is not checked in.', 409);
                }
                if (!$this->db->table('appointments')->where('tenant_id', CurrentTenant::id())
                    ->where('id', $appointment['id'])->where('unit', $entry['unit'])
                    ->where('status', 'checked_in')->where('version', $appointment['version'])
                    ->update(['status' => 'completed', 'version' => (int) $appointment['version'] + 1,
                        'updated_at' => SchedulingClock::timestamp()]) || $this->db->affectedRows() !== 1) {
                    throw new \RuntimeException('Unable to complete linked appointment.', 409);
                }
            }

            return $this->updateLockedEntry($entry, [
                'status' => 'done', 'completed_at' => SchedulingClock::timestamp(),
            ]);
        });
    }

    /**
     * Skip a called patient who is momentarily absent (60-minute recall window).
     *
     * @return array<string, mixed>
     */
    public function skipTicket(int $queueEntryId, int $recallWindowMinutes = 60, ?string $unit = null): array
    {
        if ($recallWindowMinutes < 1 || $recallWindowMinutes > 1440) {
            throw new \InvalidArgumentException('Recall window must be between 1 and 1440 minutes.', 422);
        }
        return $this->transition($queueEntryId, $unit, 'called', [
            'status' => 'skipped',
            'skip_until' => SchedulingClock::now()->modify("+{$recallWindowMinutes} minutes")->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Recall a skipped patient back into the active waiting queue.
     *
     * @return array<string, mixed>
     */
    public function recallTicket(int $queueEntryId, ?string $unit = null): array
    {
        return $this->transaction(function () use ($queueEntryId, $unit): array {
            $entry = $this->lockEntry($queueEntryId, $unit);
            $this->assertState($entry, 'skipped');
            $deadline = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $entry['skip_until'], SchedulingClock::timezone());
            if ($deadline === false || $deadline->format('Y-m-d H:i:s') !== $entry['skip_until']
                || $deadline < SchedulingClock::now()) {
                throw new \RuntimeException('The recall window has expired. Explicitly mark this ticket no-show, then issue a new walk-in ticket.', 409);
            }
            return $this->updateLockedEntry($entry, [
                'status' => 'waiting', 'skip_until' => null,
                'called_at' => null, 'called_by_user_id' => null,
            ]);
        });
    }

    /** Explicit staff decision only; never inferred by polling or recall expiry. */
    public function markAppointmentNoShow(string $unit, int $id, int $version, array $actor, ?int $expectedTicketId = null): array
    {
        return $this->transaction(function () use ($unit, $id, $version, $actor, $expectedTicketId): array {
            $service = new AppointmentService();
            $service->assertSchedulingPermission($unit, $actor);
            if ($expectedTicketId !== null) {
                $service->assertSchedulingPermission($unit, $actor, true);
            }
            $table = $this->db->prefixTable('appointments');
            $appt = $this->db->query("SELECT * FROM {$table} WHERE id = ? AND tenant_id = ? AND unit = ? AND deleted_at IS NULL FOR UPDATE",
                [$id, CurrentTenant::id(), $unit])->getRowArray();
            if ($appt === null) {
                throw new \InvalidArgumentException('Appointment not found.', 404);
            }
            $this->assertNoShowAppointment($appt, $version);
            $clinicalTable = $unit === 'clinic' ? 'clinic_encounters' : 'counselling_sessions';
            if ($this->db->table($clinicalTable)->where('tenant_id', CurrentTenant::id())->where('appointment_id', $id)->countAllResults() > 0) {
                throw new \RuntimeException('This appointment has a clinical record and cannot be marked no-show.', 409);
            }
            $queue = $this->db->prefixTable('queue_entries');
            $entries = $this->db->query("SELECT * FROM {$queue} WHERE tenant_id = ? AND appointment_id = ? ORDER BY id FOR UPDATE",
                [CurrentTenant::id(), $id])->getResultArray();
            if ($expectedTicketId !== null && !in_array($expectedTicketId, array_map('intval', array_column($entries, 'id')), true)) {
                throw new \RuntimeException('Ticket linkage changed. Reload the board.', 409);
            }
            foreach ($entries as $entry) {
                if ($entry['unit'] !== $unit || (int) $entry['person_id'] !== (int) $appt['person_id']) {
                    throw new \RuntimeException('Linked queue ticket does not match the appointment.', 409);
                }
                $this->assertNoShowEntry($entry);
                $this->assertNoClinicalRecord($entry);
            }
            if ($appt['status'] === 'checked_in' && $entries === []) {
                throw new \RuntimeException('Checked-in appointment has no queue ticket. Review before marking no-show.', 409);
            }
            foreach ($entries as $entry) {
                $this->updateLockedEntry($entry, ['status' => 'no_show']);
            }
            if (!$this->db->table('appointments')->where('id', $id)->where('tenant_id', CurrentTenant::id())
                ->where('unit', $unit)->where('version', $version)->where('status', $appt['status'])
                ->update(['status' => 'no_show', 'version' => $version + 1, 'updated_at' => SchedulingClock::timestamp()])
                || $this->db->affectedRows() !== 1) {
                throw new \RuntimeException('Appointment changed. Reload before marking no-show.', 409);
            }
            return array_replace($appt, ['status' => 'no_show', 'version' => $version + 1]);
        });
    }

    public function markNoShow(string $unit, int $id, array $actor): array
    {
        (new AppointmentService())->assertSchedulingPermission($unit, $actor, true);
        $candidate = $this->queueModel->scoped()->where('unit', $unit)->find($id);
        if ($candidate === null) {
            throw new \InvalidArgumentException('Queue ticket not found.', 404);
        }
        if (!empty($candidate['appointment_id'])) {
            // Appointment first, then all linked tickets: same lock order as check-in/completion.
            $appt = $this->appointmentModel->scoped()->where('unit', $unit)->find((int) $candidate['appointment_id']);
            if ($appt === null) {
                throw new \RuntimeException('Linked appointment is unavailable.', 409);
            }
            $this->markAppointmentNoShow($unit, (int) $appt['id'], (int) $appt['version'], $actor, $id);
            return array_replace($candidate, ['status' => 'no_show']);
        }
        return $this->transaction(function () use ($unit, $id, $actor): array {
            (new AppointmentService())->assertSchedulingPermission($unit, $actor, true);
            $entry = $this->lockEntry($id, $unit);
            if (!empty($entry['appointment_id'])) {
                throw new \RuntimeException('Ticket linkage changed. Reload the board.', 409);
            }
            $this->assertNoShowEntry($entry);
            $this->assertNoClinicalRecord($entry);
            return $this->updateLockedEntry($entry, ['status' => 'no_show']);
        });
    }

    private function assertNoShowAppointment(array $appt, int $version): void
    {
        if ($version < 1 || (int) $appt['version'] !== $version
            || !in_array($appt['status'], ['scheduled', 'confirmed', 'checked_in'], true)
            || $appt['local_date'] > SchedulingClock::today()
            || $appt['starts_at'] > SchedulingClock::timestamp()) {
            throw new \RuntimeException('Only due scheduled, confirmed or checked-in appointments may be marked no-show. Reload before trying again.', 409);
        }
    }

    private function assertNoShowEntry(array $entry): void
    {
        if (!in_array($entry['status'], ['waiting', 'called', 'skipped'], true)
            || !empty($entry['started_at']) || !empty($entry['completed_at'])
            || $entry['queue_date'] > SchedulingClock::today()) {
            throw new \RuntimeException('Only unstarted waiting, called or skipped tickets may be marked no-show.', 409);
        }
    }

    private function assertNoClinicalRecord(array $entry): void
    {
        $table = $entry['unit'] === 'clinic' ? 'clinic_encounters' : 'counselling_sessions';
        // Even a soft-deleted clinical record is evidence that care began; never close it here.
        if ($this->db->table($table)->where('tenant_id', CurrentTenant::id())
            ->where('queue_entry_id', $entry['id'])->countAllResults() > 0) {
            throw new \RuntimeException('This ticket has a clinical record and cannot be marked no-show.', 409);
        }
    }

    private function transition(int $id, ?string $unit, string $from, array $changes, bool $idempotent = false): array
    {
        return $this->transaction(function () use ($id, $unit, $from, $changes, $idempotent): array {
            $entry = $this->lockEntry($id, $unit);
            if ($idempotent && $entry['status'] === $changes['status']) {
                return $entry;
            }
            $this->assertState($entry, $from);
            return $this->updateLockedEntry($entry, $changes);
        });
    }

    private function lockEntry(int $id, ?string $unit): array
    {
        if ($unit !== null) {
            $this->assertUnit($unit);
        }
        $table = $this->db->prefixTable('queue_entries');
        $sql = "SELECT * FROM {$table} WHERE id = ? AND tenant_id = ?";
        $params = [$id, CurrentTenant::id()];
        if ($unit !== null) {
            $sql .= ' AND unit = ?';
            $params[] = $unit;
        }
        $entry = $this->db->query($sql . ' FOR UPDATE', $params)->getRowArray();
        if ($entry === null) {
            throw new \InvalidArgumentException('Queue ticket not found.', 404);
        }
        return $entry;
    }

    private function assertState(array $entry, string $expected): void
    {
        if ($entry['status'] !== $expected) {
            throw new \RuntimeException('Queue ticket state changed. Reload the board before trying again.', 409);
        }
    }

    private function updateLockedEntry(array $entry, array $changes): array
    {
        $changes['updated_at'] = SchedulingClock::timestamp();
        if (!$this->db->table('queue_entries')->where('id', $entry['id'])
            ->where('tenant_id', CurrentTenant::id())->where('unit', $entry['unit'])
            ->where('status', $entry['status'])->update($changes) || $this->db->affectedRows() !== 1) {
            throw new \RuntimeException('Unable to update queue ticket. Reload the board.', 409);
        }
        $this->recordInvalidation($entry);
        return array_replace($entry, $changes);
    }

    /**
     * Capture successful joined mutations for an owning transaction. Hints are not
     * delivery work: the caller must discard them on rollback and publish after commit.
     *
     * @param array<string, array{int, string, string, int}> $hints
     */
    public function captureInvalidations(callable $operation, array &$hints): mixed
    {
        $hints = [];
        $previous = $this->captureInvalidation;
        $this->captureInvalidation = static function (int $tenant, string $unit, string $date, int $person = 0) use (&$hints): void {
            $hint = [$tenant, $unit, $date, $person];
            $hints[implode(':', $hint)] = $hint;
        };
        try {
            return $operation();
        } catch (\Throwable $e) {
            $hints = [];
            throw $e;
        } finally {
            $this->captureInvalidation = $previous;
        }
    }

    /** Only an owner that has successfully committed may deliver captured hints. */
    public function publishCommittedInvalidations(array $hints): void
    {
        foreach ($hints as $hint) {
            $this->publishInvalidation(...$hint);
        }
    }

    private function recordInvalidation(array $entry): void
    {
        $hint = [(int) $entry['tenant_id'], $entry['unit'], $entry['queue_date'], (int) ($entry['person_id'] ?? 0)];
        $this->invalidations[implode(':', $hint)] = $hint;
    }

    private function publishInvalidation(int $tenant, string $unit, string $date, int $person = 0): void
    {
        try {
            if ($this->invalidate !== null) {
                ($this->invalidate)($tenant, $unit, $date, $person);
            } else {
                (new QueueInvalidation())->publish($tenant, $unit, $date);
                                (new PersonalQueueInvalidation())->publish($tenant, $person);
            }
        } catch (\Throwable) {
            // This runs after commit: even construction/configuration failures are best-effort.
        }
    }

    private function assertUnit(string $unit): void
    {
        if (!in_array($unit, ['clinic', 'guidance'], true)) {
            throw new \InvalidArgumentException('Invalid queue unit.', 422);
        }
    }

    private function assertActor(int $userId): void
    {
        if ($userId < 1) {
            throw new \DomainException('An authenticated staff account is required.', 403);
        }
    }

    private function transaction(callable $operation): ?array
    {
        $this->invalidations = [];
        $ownsTransaction = $this->db->transDepth === 0;
        if ($ownsTransaction) {
            $this->db->resetTransStatus();
            if (!$this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') || !$this->db->transBegin()) {
                throw new \RuntimeException('Unable to begin queue transaction.');
            }
        } elseif (!$this->db->transStatus()) {
            throw new \RuntimeException('Queue changes require a healthy caller transaction.');
        }

        try {
            $result = $operation();
            if (!$this->db->transStatus() || ($ownsTransaction && !$this->db->transCommit())) {
                throw new \RuntimeException('Unable to commit queue transaction.');
            }
        } catch (\Throwable $e) {
            $this->invalidations = [];
            // Encounter completion owns its transaction; let that caller roll back all changes.
            if ($ownsTransaction) {
                try {
                    $this->db->transRollback();
                } finally {
                    $this->db->resetTransStatus();
                }
            }
            throw $e;
        }
        $hints = $this->invalidations;
        $this->invalidations = [];
        if ($ownsTransaction) {
            $this->publishCommittedInvalidations($hints);
        } elseif ($this->captureInvalidation !== null) {
            foreach ($hints as $hint) {
                ($this->captureInvalidation)(...$hint);
            }
        }
        // Uncaptured caller-owned transactions must never publish before their owner commits.
        return $result;
    }

    /**
     * Returns grouped board buckets for rendering the Kanban queue.
     *
     * @return array{
     *     waiting: array<int, array<string, mixed>>,
     *     called: array<int, array<string, mixed>>,
     *     in_consultation: array<int, array<string, mixed>>,
     *     done: array<int, array<string, mixed>>,
     *     skipped: array<int, array<string, mixed>>,
     *     counts: array<string, int>
     * }
     */
    public function getBoardSummary(string $unit, ?string $queueDate = null): array
    {
        $this->assertUnit($unit);
        $date = $queueDate ?? SchedulingClock::today();
        $all = $this->queueModel->getDailyQueueWithPatients($unit, $date);

        $buckets = [
            'waiting'         => [],
            'called'          => [],
            'in_consultation' => [],
            'done'            => [],
            'skipped'         => [],
            'no_show'         => [],
        ];

        foreach ($all as $item) {
            $status = $item['status'];
            if (isset($buckets[$status])) {
                $buckets[$status][] = $item;
            }
        }

        return [
            'waiting'         => $buckets['waiting'],
            'called'          => $buckets['called'],
            'in_consultation' => $buckets['in_consultation'],
            'done'            => $buckets['done'],
            'skipped'         => $buckets['skipped'],
            'no_show'         => $buckets['no_show'],
            'counts'          => [
                'total'           => count($all),
                'no_show'         => count($buckets['no_show']),
                'waiting'         => count($buckets['waiting']),
                'called'          => count($buckets['called']),
                'in_consultation' => count($buckets['in_consultation']),
                'done'            => count($buckets['done']),
                'skipped'         => count($buckets['skipped']),
            ],
        ];
    }
}

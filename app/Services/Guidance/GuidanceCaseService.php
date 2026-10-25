<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\CounsellingCaseModel;
use App\Models\CounsellingNoteModel;
use App\Models\CounsellingSessionModel;
use App\Models\EmployeeProfileModel;
use App\Models\GuidanceFollowupModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Scheduling\QueueService;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GuidanceCaseService
{
    public const SESSION_TYPES = ['intake', 'regular', 'crisis', 'follow_up', 'termination'];
    private CounsellingCaseModel $caseModel;
    private CounsellingSessionModel $sessionModel;
    private CounsellingNoteModel $noteModel;
    private GuidanceFollowupModel $followupModel;
    private PersonModel $personModel;
    private StudentProfileModel $studentModel;
    private EncryptionService $encryptionService;
    private QueueService $queueService;
    private BaseConnection $db;

    public function __construct(
        ?CounsellingCaseModel $caseModel = null,
        ?CounsellingSessionModel $sessionModel = null,
        ?CounsellingNoteModel $noteModel = null,
        ?GuidanceFollowupModel $followupModel = null,
        ?PersonModel $personModel = null,
        ?StudentProfileModel $studentModel = null,
        ?EncryptionService $encryptionService = null,
        ?QueueService $queueService = null,
        ?BaseConnection $db = null
    ) {
        $models = array_filter([$caseModel, $sessionModel, $noteModel, $followupModel, $personModel, $studentModel]);
        $this->db = $db ?? ($models !== [] ? reset($models)->db : \Config\Database::connect());
        foreach ($models as $model) {
            if ($model->db !== $this->db) {
                throw new InvalidArgumentException('Guidance models must share their transaction connection.');
            }
        }
        $this->caseModel = $caseModel ?? new CounsellingCaseModel($this->db);
        $this->sessionModel = $sessionModel ?? new CounsellingSessionModel($this->db);
        $this->noteModel = $noteModel ?? new CounsellingNoteModel($this->db);
        $this->followupModel = $followupModel ?? new GuidanceFollowupModel($this->db);
        $this->personModel = $personModel ?? new PersonModel($this->db);
        $this->studentModel = $studentModel ?? new StudentProfileModel($this->db);
        $this->encryptionService = $encryptionService ?? new EncryptionService();
        $this->queueService = $queueService ?? new QueueService(null, null, $this->db);
    }

    public function createCase(int $personId, int $counsellorUserId, string $title, string $category = 'personal', ?string $summaryNotes = null): array
    {
        return $this->transaction(function () use ($personId, $counsellorUserId, $title, $category, $summaryNotes): array {
            $this->person($personId);
            $this->counsellor($counsellorUserId);
            if (trim($title) === '' || mb_strlen($title) > 255
                || !in_array($category, ['academic', 'personal', 'career', 'crisis', 'behavioral', 'family'], true)) {
                throw new InvalidArgumentException('Provide a valid case title and category.', 422);
            }
            $id = $this->caseModel->insert([
                'tenant_id' => CurrentTenant::id(), 'person_id' => $personId,
                'counsellor_user_id' => $counsellorUserId, 'case_number' => $this->caseModel->nextCaseNumber(),
                'title' => trim($title), 'category' => $category, 'status' => 'open',
                'opened_at' => date('Y-m-d H:i:s'), 'summary_notes' => $summaryNotes ? trim($summaryNotes) : null,
            ]);
            $this->must($id !== false);
            // Return only the case just created, without requiring a separate read grant.
            return $this->caseDetails($this->caseRow((int) $id));
        });
    }

    public function openSession(int $caseId, int $counsellorUserId, string $sessionType = 'regular', ?int $queueEntryId = null, ?int $appointmentId = null): array
    {
        return $this->transaction(function () use ($caseId, $counsellorUserId, $sessionType, $queueEntryId, $appointmentId): array {
            $this->counsellor($counsellorUserId);
            if (!in_array($sessionType, self::SESSION_TYPES, true)) {
                throw new InvalidArgumentException('Invalid counseling session type.', 422);
            }
            $ticket = $queueEntryId === null ? null : $this->lockTicket($queueEntryId);
            $case = $this->caseRow($caseId, true);
            if ($case['status'] === 'closed') {
                throw new RuntimeException('Closed cases cannot open new sessions.', 409);
            }
            $this->person((int) $case['person_id']);
            if ($ticket !== null) {
                if ((int) $ticket['person_id'] !== (int) $case['person_id']
                    || ($appointmentId !== null && (int) ($ticket['appointment_id'] ?? 0) !== $appointmentId)) {
                    throw new InvalidArgumentException('Queue ticket does not match this case or appointment.', 422);
                }
                $appointmentId = !empty($ticket['appointment_id']) ? (int) $ticket['appointment_id'] : null;
                $existing = $this->current('counselling_sessions', 'queue_entry_id = ?', [$queueEntryId]);
                if ($existing !== null) {
                    if ((int) $existing['case_id'] !== $caseId || $existing['deleted_at'] !== null
                        || $existing['status'] !== 'in_progress') {
                        throw new RuntimeException('Queue ticket already has a different or closed session.', 409);
                    }
                    $this->queueService->startConsultation($queueEntryId, 'guidance');
                    return $existing;
                }
                $this->queueService->startConsultation($queueEntryId, 'guidance');
            }
            if ($appointmentId !== null) {
                $this->appointment($appointmentId, (int) $case['person_id']);
            }
            // Include deleted history and use a current read, not COUNT or a stale snapshot.
            $table = $this->table('counselling_sessions');
            $last = $this->db->query("SELECT session_number FROM {$table}
                WHERE tenant_id = ? AND case_id = ? ORDER BY session_number DESC LIMIT 1 FOR UPDATE",
                [CurrentTenant::id(), $caseId])->getRowArray();
            $number = (int) ($last['session_number'] ?? 0) + 1;
            if ($number > 4294967295) {
                throw new RuntimeException('Session numbering is exhausted.', 409);
            }
            $id = $this->sessionModel->insert([
                'tenant_id' => CurrentTenant::id(), 'case_id' => $caseId,
                'appointment_id' => $appointmentId, 'queue_entry_id' => $queueEntryId,
                'session_number' => $number, 'session_type' => $sessionType,
                'counsellor_user_id' => $counsellorUserId, 'status' => 'in_progress',
                'started_at' => date('Y-m-d H:i:s'),
            ]);
            $this->must($id !== false);
            $this->must($this->caseModel->scoped()->update($caseId, ['status' => 'active']));
            return $this->getSession((int) $id);
        });
    }

    /** Read-only picker boundary; intake revalidates the ticket under locks. */
    public function getTicketForSessionSelection(int $queueEntryId): array
    {
        $actor = \App\Services\EngagementAccess::actor('guidance.cases.create', 'guidance');
        $ticket = $this->db->table('queue_entries')->where('tenant_id', $actor['tenant_id'])
            ->where('unit', 'guidance')->where('id', $queueEntryId)->get()->getRowArray();
        if ($ticket === null) {
            throw new InvalidArgumentException('Guidance queue ticket not found.', 404);
        }
        $this->person((int) $ticket['person_id']);
        if ($ticket['queue_date'] !== \App\Services\Scheduling\SchedulingClock::today()
            || !in_array($ticket['status'], ['called', 'in_consultation'], true)) {
            throw new RuntimeException('Only called or active tickets today may open a session.', 409);
        }
        return $ticket;
    }

    /** Atomically find/create the accessible case and start/resume this guidance ticket. */
    public function openSessionForTicket(int $queueEntryId, int $counsellorUserId, bool $readAny = false, string $sessionType = 'regular'): array
    {
        if (!in_array($sessionType, self::SESSION_TYPES, true)) {
            throw new InvalidArgumentException('Invalid counseling session type.', 422);
        }
        return $this->transaction(function () use ($queueEntryId, $counsellorUserId, $readAny, $sessionType): array {
            $this->counsellor($counsellorUserId);
            $ticket = $this->lockTicket($queueEntryId);
            if (!in_array($ticket['status'], ['called', 'in_consultation'], true)) {
                throw new RuntimeException('Only called or active queue tickets may open a session.', 409);
            }
            // The tenant mutex serializes guidance writers, so this read is stable without
            // taking a session lock before the case lock (openSession locks case then session).
            $sessions = $this->table('counselling_sessions');
            $existing = $this->db->query("SELECT * FROM {$sessions} WHERE tenant_id = ? AND queue_entry_id = ?",
                [CurrentTenant::id(), $queueEntryId])->getRowArray();
            if ($existing !== null) {
                $case = $this->caseRow((int) $existing['case_id'], true);
                if (!$readAny && (int) $case['counsellor_user_id'] !== $counsellorUserId) {
                    throw new RuntimeException('This ticket is linked to another counselor\'s case.', 403);
                }
                return $this->openSession((int) $case['id'], $counsellorUserId, $sessionType, $queueEntryId);
            }
            $cases = $this->table('counselling_cases');
            $sql = "SELECT * FROM {$cases} WHERE tenant_id = ? AND person_id = ? AND deleted_at IS NULL
                AND status IN ('open', 'active', 'monitoring')";
            $params = [CurrentTenant::id(), $ticket['person_id']];
            if (!$readAny) {
                $sql .= ' AND counsellor_user_id = ?';
                $params[] = $counsellorUserId;
            }
            $case = $this->db->query($sql . ' ORDER BY id DESC LIMIT 1 FOR UPDATE', $params)->getRowArray();
            if ($case === null) {
                $case = $this->createCase((int) $ticket['person_id'], $counsellorUserId,
                    'Intake Guidance Session ' . date('Y-m-d'), 'personal')['case'];
            }
            return $this->openSession((int) $case['id'], $counsellorUserId, $sessionType, $queueEntryId);
        });
    }

    public function saveDraftNotes(int $sessionId, string $plaintext, int $userId): array
    {
        return $this->transaction(function () use ($sessionId, $plaintext, $userId): array {
            $this->lockedSession($sessionId);
            $this->counsellor($userId);
            $payload = $this->notePayload($sessionId, $plaintext, $userId) + ['version' => 0, 'is_draft' => 1];
            $draft = $this->current('counselling_notes', 'session_id = ? AND is_draft = 1', [$sessionId]);
            if ($draft !== null) {
                $id = (int) $draft['id'];
                $this->must($this->noteModel->scoped()->where('session_id', $sessionId)->where('is_draft', 1)->update($id, $payload));
            } else {
                $id = $this->noteModel->insert($payload);
                $this->must($id !== false);
            }
            return $this->noteModel->scoped()->find($id);
        });
    }

    public function finalizeSessionNotes(int $sessionId, string $plaintext, int $userId): array
    {
        return $this->transaction(function () use ($sessionId, $plaintext, $userId): array {
            $this->lockedSession($sessionId);
            $this->counsellor($userId);
            $table = $this->table('counselling_notes');
            $latest = $this->db->query("SELECT * FROM {$table} WHERE tenant_id = ? AND session_id = ? AND is_draft = 0
                ORDER BY version DESC, id DESC LIMIT 1 FOR UPDATE", [CurrentTenant::id(), $sessionId])->getRowArray();
            $version = (int) ($latest['version'] ?? 0) + 1;
            if ($version > 4294967295) {
                throw new RuntimeException('Note version numbering is exhausted.', 409);
            }
            $id = $this->noteModel->insert($this->notePayload($sessionId, $plaintext, $userId) + [
                'version' => $version, 'is_draft' => 0, 'supersedes_note_id' => $latest['id'] ?? null,
            ]);
            $this->must($id !== false);
            $this->must($this->db->table('counselling_notes')->where('tenant_id', CurrentTenant::id())
                ->where('session_id', $sessionId)->where('is_draft', 1)->delete());
            return $this->noteModel->scoped()->find($id);
        });
    }

    /** Refresh read authority before ciphertext retrieval, including when no note exists. */
    public function getDecryptedSessionNote(int $sessionId): ?array
    {
        \App\Services\EngagementAccess::actor('guidance.cases.read', 'guidance');
        $session = $this->getSession($sessionId);
        $this->authorizedCase((int) $session['case_id'], 'guidance.cases.read');
        $note = $this->noteModel->getDraftNote($sessionId) ?? $this->noteModel->getLatestFinalNote($sessionId);
        if ($note === null) {
            return null;
        }
        return [
            'text' => $this->encryptionService->decrypt($note['notes_cipher'], $note['notes_nonce'], $note['notes_tag'], (int) $note['notes_key_version']),
            'version' => (int) $note['version'], 'is_draft' => (bool) $note['is_draft'],
            'created_at' => $note['created_at'], 'author_id' => (int) $note['created_by_user_id'],
        ];
    }

    public function getSession(int $sessionId): array
    {
        $session = $this->sessionModel->scoped()->find($sessionId);
        if ($session === null) {
            throw new InvalidArgumentException('Counseling session not found.', 404);
        }
        $this->caseRow((int) $session['case_id']);
        return $session;
    }

    public function closeSession(int $sessionId): array
    {
        return $this->transaction(function () use ($sessionId): array {
            $candidate = $this->getSession($sessionId);
            $ticket = !empty($candidate['queue_entry_id']) ? $this->lockTicket((int) $candidate['queue_entry_id']) : null;
            $session = $this->lockedSession($sessionId);
            if (($session['queue_entry_id'] ?? null) != ($candidate['queue_entry_id'] ?? null)) {
                throw new RuntimeException('Session changed. Reload before closing.', 409);
            }
            if ($session['status'] === 'completed') {
                return $session;
            }
            if ($session['status'] !== 'in_progress') {
                throw new RuntimeException('Only an active session may be closed.', 409);
            }
            if ($ticket !== null) {
                $case = $this->caseRow((int) $session['case_id']);
                if ((int) $ticket['person_id'] !== (int) $case['person_id']
                    || (int) ($ticket['appointment_id'] ?? 0) !== (int) ($session['appointment_id'] ?? 0)) {
                    throw new RuntimeException('Session queue linkage is inconsistent.', 409);
                }
                $this->queueService->completeConsultation((int) $ticket['id'], 'guidance');
            }
            $this->must($this->sessionModel->scoped()->update($sessionId, ['status' => 'completed', 'ended_at' => date('Y-m-d H:i:s')]));
            return $this->getSession($sessionId);
        });
    }

    public function closeCase(int $caseId, ?string $summaryNotes = null, bool $confirmed = false): array
    {
        if (!$confirmed) {
            throw new InvalidArgumentException('Explicit case closure confirmation is required.', 422);
        }
        return $this->transaction(function () use ($caseId, $summaryNotes): array {
            [$case, $actor] = $this->authorizedCase($caseId, 'guidance.cases.write', true);
            $this->assertNoActiveSessions($caseId);
            if ($case['status'] !== 'closed') {
                $changes = ['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s')];
                if ($summaryNotes !== null) {
                    $changes['summary_notes'] = trim($summaryNotes);
                }
                $this->must($this->caseModel->scoped()->update($caseId, $changes));
                $this->audit('case', $caseId, 'closed', $actor);
            }
            return $this->caseDetails($this->caseRow($caseId));
        });
    }

    public function monitorCase(int $caseId): array
    {
        return $this->transaction(function () use ($caseId): array {
            [$case, $actor] = $this->authorizedCase($caseId, 'guidance.cases.write', true);
            if (!in_array($case['status'], ['open', 'active'], true)) {
                throw new RuntimeException('Only open or active cases may enter monitoring.', 409);
            }
            $this->assertNoActiveSessions($caseId);
            $this->must($this->caseModel->scoped()->update($caseId, ['status' => 'monitoring']));
            $this->audit('case', $caseId, 'monitoring', $actor);
            return $this->caseDetails($this->caseRow($caseId));
        });
    }

    private function assertNoActiveSessions(int $caseId): void
    {
        // The tenant and case locks serialize this check with openSession().
        if ($this->current('counselling_sessions', "case_id = ? AND status = 'in_progress' AND deleted_at IS NULL", [$caseId]) !== null) {
            throw new RuntimeException('Close all in-progress sessions before changing case status.', 409);
        }
    }

    /** Refresh authority before retrieving any historical ciphertext. */
    public function getDecryptedSessionNoteHistory(int $sessionId): array
    {
        $session = $this->getSession($sessionId);
        [, $actor] = $this->authorizedCase((int) $session['case_id'], 'guidance.cases.read');
        $history = [];
        foreach ($this->noteModel->getVersionHistory($sessionId) as $note) {
            $history[] = [
                'version' => (int) $note['version'], 'is_draft' => false,
                'created_at' => $note['created_at'], 'author_name' => $note['author_name'],
                'text' => $this->encryptionService->decrypt($note['notes_cipher'], $note['notes_nonce'], $note['notes_tag'], (int) $note['notes_key_version']),
            ];
        }
        // Fail closed if the metadata-only audit append cannot be persisted.
        $this->audit('session', $sessionId, 'note_history_read', $actor);
        return $history;
    }

    private function authorizedCase(int $caseId, string $permission, bool $lock = false): array
    {
        $actor = \App\Services\EngagementAccess::actor($permission, 'guidance');
        $case = $this->caseRow($caseId, $lock);
        $this->person((int) $case['person_id']);
        if ((int) $case['tenant_id'] !== (int) $actor['tenant_id']
            || ($actor['role'] !== 'superadmin' && (int) $case['counsellor_user_id'] !== (int) $actor['id']
                && !in_array('guidance.cases.read_any', $actor['permissions'], true))) {
            throw new \DomainException('Case access forbidden.', 403);
        }
        return [$case, $actor];
    }

    private function audit(string $entity, int $id, string $action, array $actor): void
    {
        (new \App\Services\Audit\AuditService(null, $this->db))->log(
            'guidance.' . $entity . '.' . $action, match ($entity) {
                            'case' => 'counselling_cases', 'session' => 'counselling_sessions', 'followup' => 'guidance_followups',
                        },
            (string) $id, $action, [], (int) $actor['id'], $actor['role']
        );
    }

    public function addFollowup(int $caseId, ?int $sessionId, string $dueDate, string $actionPlan): array
    {
        return $this->transaction(function () use ($caseId, $sessionId, $dueDate, $actionPlan): array {
            $this->caseRow($caseId, true);
            if ($sessionId !== null && (int) $this->getSession($sessionId)['case_id'] !== $caseId) {
                throw new InvalidArgumentException('Follow-up session does not belong to this case.', 422);
            }
            $parsed = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $dueDate)
                ? \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate) : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $dueDate || trim($actionPlan) === '') {
                throw new InvalidArgumentException('Provide a valid due date and action plan.', 422);
            }
            $id = $this->followupModel->insert([
                'tenant_id' => CurrentTenant::id(), 'case_id' => $caseId, 'session_id' => $sessionId,
                'due_date' => $dueDate, 'action_plan' => trim($actionPlan), 'status' => 'pending',
            ]);
            $this->must($id !== false);
            return $this->followupModel->scoped()->find($id);
        });
    }

    public function completeFollowup(int $followupId): array
    {
        return $this->transitionFollowup($followupId, 'completed');
    }

    public function missFollowup(int $followupId): array
    {
        return $this->transitionFollowup($followupId, 'missed');
    }

    private function transitionFollowup(int $followupId, string $status): array
    {
        return $this->transaction(function () use ($followupId, $status): array {
            // Resolve without a lock, then follow the shared case -> session/follow-up order.
            $candidate = $this->followupModel->scoped()->find($followupId);
            if ($candidate === null) {
                throw new InvalidArgumentException('Follow-up not found.', 404);
            }
            [, $actor] = $this->authorizedCase((int) $candidate['case_id'], 'guidance.cases.write', true);
            $followup = $this->current('guidance_followups', 'id = ?', [$followupId]);
            if ($followup === null || (int) $followup['case_id'] !== (int) $candidate['case_id']) {
                throw new RuntimeException('Follow-up changed. Reload before trying again.', 409);
            }
            if ($followup['session_id'] !== null && (int) $this->getSession((int) $followup['session_id'])['case_id'] !== (int) $followup['case_id']) {
                throw new RuntimeException('Follow-up session linkage is inconsistent.', 409);
            }
            if ($followup['status'] !== $status) {
                if ($followup['status'] !== 'pending') {
                    throw new RuntimeException('Only pending follow-ups may be resolved.', 409);
                }
                $this->must($this->followupModel->scoped()->update($followupId, [
                    'status' => $status, 'completed_at' => $status === 'completed' ? date('Y-m-d H:i:s') : null,
                ]));
                $this->audit('followup', $followupId, $status, $actor);
            }
            return $this->followupModel->scoped()->find($followupId);
        });
    }

    public function getCaseDetails(int $caseId): array
    {
        [$case] = $this->authorizedCase($caseId, 'guidance.cases.read');
        return $this->caseDetails($case);
    }

    /** Assemble a package only after read authorization or the originating case write. */
    private function caseDetails(array $case): array
    {
        $caseId = (int) $case['id'];
        $patient = $this->person((int) $case['person_id']);
        return [
            'case' => $case, 'patient' => $patient,
            'student' => $patient['kind'] === 'student' ? $this->studentModel->findByPersonId((int) $patient['id']) : null,
            'employee' => $patient['kind'] === 'employee'
                ? (new EmployeeProfileModel($this->db))->scoped()->where('person_id', $patient['id'])->first() : null,
            'sessions' => $this->sessionModel->getForCase($caseId),
            'followups' => $this->followupModel->getForCase($caseId),
        ];
    }

    private function caseRow(int $id, bool $lock = false): array
    {
        $row = $lock ? $this->current('counselling_cases', 'id = ? AND deleted_at IS NULL', [$id]) : $this->caseModel->scoped()->find($id);
        if ($row === null) {
            throw new InvalidArgumentException('Counseling case not found.', 404);
        }
        return $row;
    }

    private function person(int $id): array
    {
        $person = $this->personModel->scoped()->find($id);
        if ($person === null) {
            throw new InvalidArgumentException('Case patient not found.', 404);
        }
        return $person;
    }

    private function counsellor(int $id): void
    {
        $user = (new UserModel($this->db))->scoped()->where('status', 'active')->find($id);
        if ($user === null || !in_array($user['role'], ['superadmin', 'guidance_admin', 'guidance_supervisor', 'counsellor'], true)) {
            throw new \DomainException('An active same-tenant guidance account is required.', 403);
        }
    }

    private function lockedSession(int $id): array
    {
        $candidate = $this->getSession($id);
        $this->caseRow((int) $candidate['case_id'], true);
        $session = $this->current('counselling_sessions', 'id = ? AND deleted_at IS NULL', [$id]);
        if ($session === null || (int) $session['case_id'] !== (int) $candidate['case_id']) {
            throw new RuntimeException('Session changed. Reload before trying again.', 409);
        }
        if ($session['status'] === 'cancelled') {
            throw new RuntimeException('Cancelled sessions cannot be modified.', 409);
        }
        return $session;
    }

    /** The appointment must be locked before its existing ticket, matching QueueService. */
    private function lockTicket(int $id): array
    {
        $candidate = $this->db->table('queue_entries')->where('tenant_id', CurrentTenant::id())->where('unit', 'guidance')
            ->where('id', $id)->get()->getRowArray();
        if ($candidate === null) {
            throw new InvalidArgumentException('Guidance queue ticket not found.', 404);
        }
        if (!empty($candidate['appointment_id'])) {
            $table = $this->table('appointments');
            $appointment = $this->db->query("SELECT * FROM {$table} WHERE tenant_id = ? AND unit = 'guidance'
                AND id = ? AND deleted_at IS NULL FOR UPDATE", [CurrentTenant::id(), $candidate['appointment_id']])->getRowArray();
            if ($appointment === null || (int) $appointment['person_id'] !== (int) $candidate['person_id']) {
                throw new RuntimeException('Queue appointment linkage is inconsistent.', 409);
            }
        }
        $ticket = $this->current('queue_entries', "id = ? AND unit = 'guidance'", [$id]);
        if ($ticket === null || (int) $ticket['person_id'] !== (int) $candidate['person_id']
            || (int) ($ticket['appointment_id'] ?? 0) !== (int) ($candidate['appointment_id'] ?? 0)) {
            throw new RuntimeException('Queue ticket changed. Reload before trying again.', 409);
        }
        return $ticket;
    }

    private function appointment(int $id, int $personId): void
    {
        $row = $this->db->table('appointments')->where('tenant_id', CurrentTenant::id())->where('unit', 'guidance')
            ->where('id', $id)->where('person_id', $personId)->where('deleted_at', null)->get()->getRowArray();
        if ($row === null) {
            throw new InvalidArgumentException('Guidance appointment does not match this patient.', 422);
        }
    }

    private function notePayload(int $sessionId, string $plaintext, int $userId): array
    {
        $plaintext = trim($plaintext);
        if (strlen($plaintext) > 8192) {
            throw new InvalidArgumentException('Session notes must not exceed 8192 bytes.', 422);
        }
        $encrypted = $this->encryptionService->encrypt($plaintext);
        return [
            'tenant_id' => CurrentTenant::id(), 'session_id' => $sessionId,
            'notes_cipher' => $encrypted['cipher'], 'notes_nonce' => $encrypted['nonce'],
            'notes_tag' => $encrypted['tag'], 'notes_key_version' => $encrypted['key_version'],
            'created_by_user_id' => $userId, 'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function table(string $name): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable($name));
    }

    private function current(string $table, string $where, array $params): ?array
    {
        $table = $this->table($table);
        return $this->db->query("SELECT * FROM {$table} WHERE tenant_id = ? AND {$where} FOR UPDATE",
            array_merge([CurrentTenant::id()], $params))->getRowArray();
    }

    private function must(bool $success): void
    {
        if (!$success) {
            throw new RuntimeException('Guidance changes could not be saved.', 503);
        }
    }

    /** Short tenant-first transactions also coordinate case numbering and ticket intake. */
    private function transaction(callable $operation): array
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('Guidance writes require MySQL/MariaDB transactions.', 503);
        }
        $ownsTransaction = $this->db->transDepth === 0;
        if ($ownsTransaction) {
            $this->db->resetTransStatus();
            if (!$this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') || !$this->db->transBegin()) {
                throw new RuntimeException('Unable to begin guidance transaction.', 503);
            }
        } elseif (!$this->db->transStatus()) {
            throw new RuntimeException('Guidance changes require a healthy caller transaction.', 503);
        }
        $queueHints = [];
        try {
            $table = $this->table('tenants');
            if ($this->db->query("SELECT id FROM {$table} WHERE id = ? FOR UPDATE", [CurrentTenant::id()])->getRowArray() === null) {
                throw new RuntimeException('Guidance tenant is unavailable.', 503);
            }
            // Nested intake/session calls contribute to the outer capture, not a new queue.
            $result = $ownsTransaction
                ? $this->queueService->captureInvalidations($operation, $queueHints)
                : $operation();
            if (!$this->db->transStatus() || ($ownsTransaction && !$this->db->transCommit())) {
                throw new RuntimeException('Unable to commit guidance changes.', 503);
            }
        } catch (Throwable $e) {
            $retryable = in_array((int) $e->getCode(), [1205, 1213], true)
                || in_array((int) ($this->db->error()['code'] ?? 0), [1205, 1213], true);
            if ($ownsTransaction) {
                try {
                    $this->db->transRollback();
                } finally {
                    $this->db->resetTransStatus();
                }
            }
            if ($retryable) {
                throw new RuntimeException('Guidance record is busy. Reload and retry the entire operation.', 409, $e);
            }
            throw $e;
        }
        if ($ownsTransaction) {
            $this->queueService->publishCommittedInvalidations($queueHints);
        }
        return $result;
    }
}

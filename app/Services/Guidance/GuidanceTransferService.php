<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\CounsellingCaseModel;
use App\Models\GuidanceCaseAssignmentModel;
use App\Models\GuidanceCaseTransferModel;
use App\Models\UserModel;
use App\Services\Audit\AuditService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GuidanceTransferService
{
    private CounsellingCaseModel $caseModel;
    private GuidanceCaseTransferModel $transferModel;
    private GuidanceCaseAssignmentModel $assignmentModel;
    private UserModel $userModel;
    private GuidanceAccessPolicy $accessPolicy;
    private BaseConnection $db;

    public function __construct(
        ?CounsellingCaseModel $caseModel = null,
        ?GuidanceCaseTransferModel $transferModel = null,
        ?GuidanceCaseAssignmentModel $assignmentModel = null,
        ?UserModel $userModel = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?BaseConnection $db = null
    ) {
        $models = array_filter([$caseModel, $transferModel, $assignmentModel, $userModel]);
        $this->db = $db ?? ($models !== [] ? reset($models)->db : Database::connect());

        foreach ($models as $model) {
            if ($model->db !== $this->db) {
                throw new InvalidArgumentException('Guidance models must share their transaction connection.');
            }
        }

        $this->caseModel = $caseModel ?? new CounsellingCaseModel($this->db);
        $this->transferModel = $transferModel ?? new GuidanceCaseTransferModel($this->db);
        $this->assignmentModel = $assignmentModel ?? new GuidanceCaseAssignmentModel($this->db);
        $this->userModel = $userModel ?? new UserModel($this->db);
        $this->accessPolicy = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
    }

    /**
     * Request a case transfer or consultation to another counselor in the same tenant.
     *
     * @param int $caseId Case ID to transfer
     * @param int $toCounsellorUserId Target counselor user ID
     * @param string $type Transfer type ('consultation' or 'full_transfer')
     * @param string $reason Audit reason for request
     * @param array<string, mixed>|null $actor Caller identity hint
     * @return array<string, mixed> Created transfer record
     */
    public function requestTransfer(
        int $caseId,
        int $toCounsellorUserId,
        string $type,
        string $reason,
        ?array $actor = null
    ): array {
        if (!in_array($type, ['consultation', 'full_transfer'], true)) {
            throw new InvalidArgumentException('Invalid transfer type. Must be consultation or full_transfer.', 422);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Transfer reason is required.', 422);
        }

        $authority = $this->resolveActor($actor);
        $tenantId = (int) $authority['tenant_id'];

        // Enforce permission if user permissions are loaded
        if (!empty($authority['permissions'])
            && !in_array('guidance.cases.transfer', $authority['permissions'], true)
            && !in_array('guidance.cases.write', $authority['permissions'], true)) {
            throw new DomainException('Guidance permission forbidden.', 403);
        }

        // Validate case exists in tenant
        $case = $this->caseModel->scoped($tenantId)->where('id', $caseId)->first();
        if ($case === null) {
            throw new InvalidArgumentException('Counseling case not found.', 404);
        }

        // Verify actor is the currently assigned counselor
        if ((int) $case['counsellor_user_id'] !== (int) $authority['id']) {
            throw new DomainException('Only the currently assigned counselor can request a case transfer.', 403);
        }

        // Target cannot be self
        if ($toCounsellorUserId === (int) $authority['id']) {
            throw new InvalidArgumentException('Cannot transfer case to yourself.', 422);
        }

        // Validate target counselor exists and is active in same tenant
        $targetUser = $this->userModel->scoped($tenantId)
            ->where('id', $toCounsellorUserId)
            ->where('status', 'active')
            ->first();
        if ($targetUser === null) {
            throw new InvalidArgumentException('Target counselor not found in active tenant.', 404);
        }

        return $this->transaction(function () use ($caseId, $toCounsellorUserId, $type, $reason, $authority, $tenantId): array {
            $now = date('Y-m-d H:i:s');
            $transferData = [
                'tenant_id' => $tenantId,
                'case_id' => $caseId,
                'from_counsellor_user_id' => (int) $authority['id'],
                'to_counsellor_user_id' => $toCounsellorUserId,
                'transfer_type' => $type,
                'reason' => $reason,
                'status' => 'pending',
                'requested_at' => $now,
                'created_at' => $now,
            ];

            $transferId = $this->transferModel->insert($transferData);
            if ($transferId === false) {
                throw new RuntimeException('Unable to create case transfer request.', 500);
            }

            $created = $this->transferModel->scoped($tenantId)->find($transferId);
            $this->audit('guidance.case.transfer_requested', 'guidance_case_transfers', (int) $transferId, $authority, [
                'case_id' => $caseId,
                'to_counsellor_user_id' => $toCounsellorUserId,
                'transfer_type' => $type,
            ]);

            return $created;
        });
    }

    /**
     * Respond to an incoming case transfer or consultation request.
     *
     * @param int $transferId Transfer request ID
     * @param bool $accept Whether to accept or decline
     * @param string|null $notes Response notes or reason
     * @param array<string, mixed>|null $actor Caller identity hint
     * @return array<string, mixed> Updated transfer record
     */
    public function respondTransfer(
        int $transferId,
        bool $accept,
        ?string $notes = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveActor($actor);
        $tenantId = (int) $authority['tenant_id'];

        $transfer = $this->transferModel->scoped($tenantId)->find($transferId);
        if ($transfer === null) {
            throw new InvalidArgumentException('Case transfer not found.', 404);
        }

        if ($transfer['status'] !== 'pending') {
            throw new DomainException('Transfer request has already been processed.', 409);
        }

        // Check actor is target counselor
        if ((int) $transfer['to_counsellor_user_id'] !== (int) $authority['id']) {
            throw new DomainException('Only the designated receiver counselor can respond to this transfer request.', 403);
        }

        $caseId = (int) $transfer['case_id'];
        $case = $this->caseModel->scoped($tenantId)->find($caseId);
        if ($case === null) {
            throw new InvalidArgumentException('Counseling case not found.', 404);
        }

        return $this->transaction(function () use ($transfer, $transferId, $accept, $notes, $authority, $tenantId, $caseId): array {
            $now = date('Y-m-d H:i:s');
            $newAssignmentId = null;

            if ($accept) {
                $status = 'accepted';
                if ($transfer['transfer_type'] === 'full_transfer') {
                    // Update counselling_cases.counsellor_user_id to receiver
                    $this->caseModel->update($caseId, [
                        'counsellor_user_id' => (int) $authority['id'],
                    ]);

                    // Close previous assignment row(s)
                    $this->assignmentModel->db->table('guidance_case_assignments')
                        ->where('tenant_id', $tenantId)
                        ->where('case_id', $caseId)
                        ->where('relinquished_at IS NULL')
                        ->update(['relinquished_at' => $now]);

                    // Insert new assignment with assignment_type = 'transfer_accepted'
                    $newAssignmentId = $this->assignmentModel->insert([
                        'tenant_id' => $tenantId,
                        'case_id' => $caseId,
                        'counsellor_user_id' => (int) $authority['id'],
                        'assigned_by_user_id' => (int) $transfer['from_counsellor_user_id'],
                        'assignment_type' => 'transfer_accepted',
                        'reason' => $notes !== null && trim($notes) !== '' ? trim($notes) : $transfer['reason'],
                        'assigned_at' => $now,
                        'relinquished_at' => null,
                        'created_at' => $now,
                    ]);
                }
            } else {
                $status = 'declined';
            }

            // Update transfer row
            $this->transferModel->update($transferId, [
                'status' => $status,
                'responded_at' => $now,
                'response_notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ]);

            $this->audit('guidance.case.transfer_responded', 'guidance_case_transfers', $transferId, $authority, [
                'status' => $status,
                'notes' => $notes,
            ]);

            $updated = $this->transferModel->scoped($tenantId)->find($transferId);
            if ($newAssignmentId !== null && $newAssignmentId !== false) {
                $updated['assignment_id'] = $newAssignmentId;
                $updated['assignment'] = $this->assignmentModel->scoped($tenantId)->find($newAssignmentId);
            }

            return $updated;
        });
    }

    /**
     * Reassign a case by manager/supervisor intervention.
     *
     * @param int $caseId Case ID to reassign
     * @param int $newCounsellorUserId New assigned counselor user ID
     * @param string $reason Explicit audit reason for manager reassignment
     * @param array<string, mixed>|null $actor Caller identity hint (must have guidance.cases.reassign)
     * @return array<string, mixed> Updated case record with assignment info
     */
    public function reassignCase(
        int $caseId,
        int $newCounsellorUserId,
        string $reason,
        ?array $actor = null
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Reassignment reason is required.', 422);
        }

        // Requires guidance.cases.reassign permission (guidance_supervisor, guidance_admin, superadmin)
        $authority = $this->resolveActor($actor, 'guidance.cases.reassign');
        $tenantId = (int) $authority['tenant_id'];

        $case = $this->caseModel->scoped($tenantId)->find($caseId);
        if ($case === null) {
            throw new InvalidArgumentException('Counseling case not found.', 404);
        }

        $newCounsellor = $this->userModel->scoped($tenantId)
            ->where('id', $newCounsellorUserId)
            ->where('status', 'active')
            ->first();
        if ($newCounsellor === null) {
            throw new InvalidArgumentException('Target counselor not found in active tenant.', 404);
        }

        return $this->transaction(function () use ($caseId, $newCounsellorUserId, $reason, $authority, $tenantId): array {
            $now = date('Y-m-d H:i:s');

            // Updates counselling_cases.counsellor_user_id
            $this->caseModel->update($caseId, [
                'counsellor_user_id' => $newCounsellorUserId,
            ]);

            // Closes previous assignment row(s)
            $this->assignmentModel->db->table('guidance_case_assignments')
                ->where('tenant_id', $tenantId)
                ->where('case_id', $caseId)
                ->where('relinquished_at IS NULL')
                ->update(['relinquished_at' => $now]);

            // Inserts new assignment with assignment_type = 'manager_reassigned'
            $assignmentId = $this->assignmentModel->insert([
                'tenant_id' => $tenantId,
                'case_id' => $caseId,
                'counsellor_user_id' => $newCounsellorUserId,
                'assigned_by_user_id' => (int) $authority['id'],
                'assignment_type' => 'manager_reassigned',
                'reason' => $reason,
                'assigned_at' => $now,
                'relinquished_at' => null,
                'created_at' => $now,
            ]);

            $this->audit('guidance.case.reassigned', 'counselling_cases', $caseId, $authority, [
                'new_counsellor_user_id' => $newCounsellorUserId,
                'reason' => $reason,
                'assignment_id' => $assignmentId,
            ]);

            $updatedCase = $this->caseModel->scoped($tenantId)->find($caseId);
            $assignment = $this->assignmentModel->scoped($tenantId)->find($assignmentId);

            return array_merge($updatedCase, [
                'assignment_id' => $assignmentId,
                'assignment_type' => 'manager_reassigned',
                'assignment' => $assignment,
            ]);
        });
    }

    /**
     * Record an episode transfer and coordinate any linked case transfer.
     *
     * @param int $episodeId Episode ID
     * @param int $fromCounselorUserId Previous counselor user ID
     * @param int $toCounselorUserId Target counselor user ID
     * @param string $reason Audit reason for transfer
     * @param array<string, mixed> $authority Actor identity/authority
     * @param int|null $caseId Optional linked case ID
     */
    public function logEpisodeTransfer(
        int $episodeId,
        int $fromCounselorUserId,
        int $toCounselorUserId,
        string $reason,
        array $authority,
        ?int $caseId = null
    ): void {
        $tenantId = (int) $authority['tenant_id'];
        $now = date('Y-m-d H:i:s');

        if ($caseId !== null && $caseId > 0) {
            $this->caseModel->update($caseId, [
                'counsellor_user_id' => $toCounselorUserId,
            ]);

            $this->assignmentModel->db->table('guidance_case_assignments')
                ->where('tenant_id', $tenantId)
                ->where('case_id', $caseId)
                ->where('relinquished_at IS NULL')
                ->update(['relinquished_at' => $now]);

            $this->assignmentModel->insert([
                'tenant_id' => $tenantId,
                'case_id' => $caseId,
                'counsellor_user_id' => $toCounselorUserId,
                'assigned_by_user_id' => (int) $authority['id'],
                'assignment_type' => 'manager_reassigned',
                'reason' => $reason,
                'assigned_at' => $now,
                'relinquished_at' => null,
                'created_at' => $now,
            ]);

            $this->audit('guidance.case.reassigned', 'counselling_cases', $caseId, $authority, [
                'new_counsellor_user_id' => $toCounselorUserId,
                'reason' => $reason,
            ]);
        }

        $this->audit('guidance.episode.transferred', 'guidance_episodes', $episodeId, $authority, [
            'from_counselor_user_id' => $fromCounselorUserId,
            'to_counselor_user_id' => $toCounselorUserId,
            'reason' => $reason,
        ]);
    }

    /**
     * Get the chronological assignment history for a given case.
     *
     * @param int $caseId Case ID
     * @param int|null $tenantId Tenant ID (defaults to active tenant)
     * @return array<int, array<string, mixed>> Chronological list of assignments
     */
    public function getAssignmentHistory(int $caseId, ?int $tenantId = null): array
    {
        $tid = $tenantId ?? CurrentTenant::id();

        return $this->assignmentModel->scoped($tid)
            ->where('case_id', $caseId)
            ->orderBy('assigned_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * Backfill a single 'legacy_import' assignment for any case that has no assignment rows yet.
     * Preserves existing owner without inventing historical transfers.
     *
     * @param int|null $tenantId Optional tenant scope (null processes all unassigned cases)
     * @return int Number of cases backfilled
     */
    public function backfillLegacyAssignments(?int $tenantId = null): int
    {
        $casesTable = $this->db->protectIdentifiers($this->db->prefixTable('counselling_cases'));
        $assignmentsTable = $this->db->protectIdentifiers($this->db->prefixTable('guidance_case_assignments'));

        $sql = "SELECT c.id, c.tenant_id, c.counsellor_user_id, c.opened_at, c.created_at
                FROM {$casesTable} c
                WHERE c.deleted_at IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM {$assignmentsTable} a
                      WHERE a.case_id = c.id AND a.tenant_id = c.tenant_id
                  )";
        $params = [];
        if ($tenantId !== null) {
            $sql .= " AND c.tenant_id = ?";
            $params[] = $tenantId;
        }
        $sql .= " ORDER BY c.id ASC";

        $cases = $this->db->query($sql, $params)->getResultArray();
        if ($cases === []) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');

        return $this->transaction(function () use ($cases, $now): int {
            $count = 0;
            foreach ($cases as $case) {
                $counsellorId = (int) ($case['counsellor_user_id'] ?? 0);
                if ($counsellorId <= 0) {
                    continue;
                }

                $assignedAt = !empty($case['opened_at'])
                    ? $case['opened_at']
                    : (!empty($case['created_at']) ? $case['created_at'] : $now);

                $inserted = $this->assignmentModel->insert([
                    'tenant_id' => (int) $case['tenant_id'],
                    'case_id' => (int) $case['id'],
                    'counsellor_user_id' => $counsellorId,
                    'assigned_by_user_id' => $counsellorId,
                    'assignment_type' => 'legacy_import',
                    'reason' => 'Legacy case assignment backfill',
                    'assigned_at' => $assignedAt,
                    'relinquished_at' => null,
                    'created_at' => $now,
                ]);

                if ($inserted !== false) {
                    $count++;
                }
            }

            return $count;
        });
    }

    /**
     * Get a transfer request by ID.
     *
     * @param int $transferId Transfer ID
     * @param int|null $tenantId Optional tenant scope
     * @return array<string, mixed>|null
     */
    public function getTransfer(int $transferId, ?int $tenantId = null): ?array
    {
        $tid = $tenantId ?? CurrentTenant::id();
        return $this->transferModel->scoped($tid)->find($transferId);
    }

    /**
     * Get all transfer requests for a given case.
     *
     * @param int $caseId Case ID
     * @param int|null $tenantId Optional tenant scope
     * @return array<int, array<string, mixed>>
     */
    public function getCaseTransfers(int $caseId, ?int $tenantId = null): array
    {
        $tid = $tenantId ?? CurrentTenant::id();
        return $this->transferModel->scoped($tid)
            ->where('case_id', $caseId)
            ->orderBy('requested_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * Record an initial assignment for a newly created case.
     *
     * @param int $caseId Case ID
     * @param int $counsellorUserId Counselor user ID
     * @param int|null $assignedByUserId Assigner user ID (defaults to counsellor)
     * @param string|null $reason Optional assignment reason
     * @param int|null $tenantId Optional tenant ID
     * @return array<string, mixed> Created assignment record
     */
    public function recordInitialAssignment(
        int $caseId,
        int $counsellorUserId,
        ?int $assignedByUserId = null,
        ?string $reason = null,
        ?int $tenantId = null
    ): array {
        $tid = $tenantId ?? CurrentTenant::id();
        $now = date('Y-m-d H:i:s');

        $id = $this->assignmentModel->insert([
            'tenant_id' => $tid,
            'case_id' => $caseId,
            'counsellor_user_id' => $counsellorUserId,
            'assigned_by_user_id' => $assignedByUserId ?? $counsellorUserId,
            'assignment_type' => 'initial',
            'reason' => $reason,
            'assigned_at' => $now,
            'relinquished_at' => null,
            'created_at' => $now,
        ]);

        return $this->assignmentModel->scoped($tid)->find($id);
    }

    /**
     * Resolve actor using transport identity or explicit identity hint.
     *
     * @param array<string, mixed>|null $actor
     * @param string|null $permission
     * @param int|null $expectedActorId
     * @param bool $lock
     * @return array<string, mixed>
     */
    private function resolveActor(
        ?array $actor,
        ?string $permission = null,
        ?int $expectedActorId = null,
        bool $lock = false
    ): array {
        try {
            return $this->accessPolicy->resolve($actor, $permission, $expectedActorId, $lock);
        } catch (DomainException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            if ($actor !== null && isset($actor['id'])) {
                $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
                return $this->accessPolicy->authorize([
                    'id' => (int) $actor['id'],
                    'tenant_id' => $tenantId,
                ], $permission, $expectedActorId, $lock);
            }
            throw $e;
        }
    }

    /**
     * Execute an operation inside a database transaction.
     */
    private function transaction(callable $operation): mixed
    {
        $ownsTransaction = $this->db->transDepth === 0;
        if ($ownsTransaction) {
            $this->db->resetTransStatus();
            if ($this->db->DBDriver === 'MySQLi') {
                $this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }
            if (!$this->db->transBegin()) {
                throw new RuntimeException('Unable to begin guidance transfer transaction.', 503);
            }
        } elseif (!$this->db->transStatus()) {
            throw new RuntimeException('Guidance transfer changes require a healthy caller transaction.', 503);
        }

        try {
            $result = $operation();
            if (!$this->db->transStatus() || ($ownsTransaction && !$this->db->transCommit())) {
                throw new RuntimeException('Unable to commit guidance transfer changes.', 503);
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                try {
                    $this->db->transRollback();
                } finally {
                    $this->db->resetTransStatus();
                }
            }
            throw $e;
        }
    }

    /**
     * Log audit action safely without failing transaction on non-critical ledger issues.
     */
    private function audit(string $action, string $tableName, int $recordId, array $actor, array $changes = []): void
    {
        try {
            (new AuditService(null, $this->db))->log(
                $action,
                $tableName,
                (string) $recordId,
                $action,
                $changes,
                (int) $actor['id'],
                (string) ($actor['role'] ?? 'system')
            );
        } catch (Throwable) {
            // Non-critical audit logging failure
        }
    }
}

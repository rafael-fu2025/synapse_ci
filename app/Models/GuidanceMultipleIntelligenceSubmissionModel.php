<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceMultipleIntelligenceSubmissionModel extends TenantModel
{
    protected $table = 'guidance_mi_submissions';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'assignment_id',
        'person_id',
        'status',
        'draft_responses_cipher',
        'scores_json',
        'domain_scores_json',
        'attested_by_user_id',
        'attested_at',
        'attestation_statement_hash',
        'snapshot_hash',
        'form_instance_id',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    /**
     * Find submission by assignment ID.
     *
     * @param int $assignmentId
     * @param int|null $tenantId
     * @return array<string, mixed>|null
     */
    public function findByAssignmentId(int $assignmentId, ?int $tenantId = null): ?array
    {
        return $this->scoped($tenantId)
            ->where('assignment_id', $assignmentId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Find finalized submission by assignment ID.
     *
     * @param int $assignmentId
     * @param int|null $tenantId
     * @return array<string, mixed>|null
     */
    public function findFinalizedByAssignmentId(int $assignmentId, ?int $tenantId = null): ?array
    {
        return $this->scoped($tenantId)
            ->where('assignment_id', $assignmentId)
            ->where('status', 'finalized')
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Get all submissions for a person.
     *
     * @param int $personId
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getByPersonId(int $personId, ?int $tenantId = null): array
    {
        return $this->scoped($tenantId)
            ->where('person_id', $personId)
            ->orderBy('id', 'DESC')
            ->findAll();
    }
}

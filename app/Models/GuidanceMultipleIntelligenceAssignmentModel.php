<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceMultipleIntelligenceAssignmentModel extends TenantModel
{
    protected $table = 'guidance_mi_assignments';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'assigned_by_user_id',
        'status',
        'academic_term',
        'academic_year',
        'assigned_at',
        'due_at',
        'completed_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    /**
     * Find active assessment assignment for a person.
     * Active statuses are 'assigned' and 'in_progress'.
     *
     * @param int $personId
     * @param int|null $tenantId
     * @return array<string, mixed>|null
     */
    public function findActiveByPersonId(int $personId, ?int $tenantId = null): ?array
    {
        return $this->scoped($tenantId)
            ->where('person_id', $personId)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Get all assessment assignments for a person.
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

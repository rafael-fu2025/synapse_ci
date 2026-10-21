<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceFollowupModel extends TenantModel
{
    protected $table = 'guidance_followups';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'case_id',
        'session_id',
        'due_date',
        'action_plan',
        'status',
        'completed_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function getForCase(int $caseId): array
    {
        return $this->scoped()
            ->where('case_id', $caseId)
            ->orderBy('due_date', 'ASC')
            ->findAll();
    }
}

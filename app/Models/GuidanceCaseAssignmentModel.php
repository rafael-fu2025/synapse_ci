<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceCaseAssignmentModel extends TenantModel
{
    protected $table = 'guidance_case_assignments';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'case_id',
        'counsellor_user_id',
        'assigned_by_user_id',
        'assignment_type',
        'reason',
        'assigned_at',
        'relinquished_at',
        'created_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';
}


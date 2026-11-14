<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceSupervisionAssignmentModel extends TenantModel
{
    protected $table = 'guidance_supervision_assignments';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'associate_user_id',
        'supervisor_user_id',
        'status',
        'co_sign_required',
        'starts_at',
        'ends_at',
        'created_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';
}


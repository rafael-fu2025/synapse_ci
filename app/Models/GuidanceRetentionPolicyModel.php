<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceRetentionPolicyModel extends TenantModel
{
    protected $table = 'guidance_retention_policies';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'policy_code',
        'title',
        'duration_months',
        'legal_basis',
        'purge_enabled',
        'is_active',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';
}


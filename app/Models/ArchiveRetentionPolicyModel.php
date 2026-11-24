<?php

declare(strict_types=1);

namespace App\Models;

final class ArchiveRetentionPolicyModel extends TenantModel
{
    protected $table            = 'archive_retention_policies';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'tenant_id',
        'policy_code',
        'module',
        'entity_type',
        'title',
        'duration_months',
        'legal_basis',
        'auto_archive_after_months',
        'purge_enabled',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}

<?php

declare(strict_types=1);

namespace App\Models;

final class ArchiveLegalHoldModel extends TenantModel
{
    protected $table            = 'archive_legal_holds';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'tenant_id',
        'module',
        'entity_type',
        'entity_id',
        'record_ref',
        'reason',
        'reference_code',
        'placed_by_user_id',
        'placed_at',
        'lifted_by_user_id',
        'lifted_at',
        'lift_reason',
        'is_active',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}

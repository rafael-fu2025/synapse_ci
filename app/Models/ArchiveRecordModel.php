<?php

declare(strict_types=1);

namespace App\Models;

final class ArchiveRecordModel extends TenantModel
{
    protected $table            = 'archive_records';
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
        'unit',
        'state',
        'reason',
        'archived_by_user_id',
        'archived_at',
        'restored_by_user_id',
        'restored_at',
        'restore_reason',
        'retention_policy_id',
        'retention_due_at',
        'metadata_json',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}

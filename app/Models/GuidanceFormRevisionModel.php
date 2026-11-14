<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceFormRevisionModel extends TenantModel
{
    protected $table = 'guidance_form_revisions';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'form_instance_id',
        'revision_number',
        'revision_type',
        'supersedes_revision_id',
        'snapshot_hash',
        'snapshot_cipher',
        'snapshot_nonce',
        'snapshot_tag',
        'snapshot_key_version',
        'schema_version',
        'definition_revision_id',
        'created_by_user_id',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';
}


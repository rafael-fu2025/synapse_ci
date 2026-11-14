<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceFormDraftModel extends TenantModel
{
    protected $table = 'guidance_form_drafts';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'form_instance_id',
        'version',
        'draft_cipher',
        'draft_nonce',
        'draft_tag',
        'draft_key_version',
        'draft_schema_version',
        'updated_by_user_id',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}


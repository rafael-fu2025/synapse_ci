<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceFormDefinitionModel extends TenantModel
{
    protected $table = 'guidance_form_definitions';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'internal_key',
        'form_code',
        'canonical_title',
        'owner',
        'is_active',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}


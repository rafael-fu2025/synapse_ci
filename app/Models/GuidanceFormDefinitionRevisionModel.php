<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceFormDefinitionRevisionModel extends TenantModel
{
    protected $table = 'guidance_form_definition_revisions';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'form_definition_id',
        'revision_number',
        'schema_version',
        'renderer_version',
        'schema_json',
        'schema_hash',
        'effectivity_date',
        'approval_state',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';
}


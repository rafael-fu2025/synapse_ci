<?php

declare(strict_types=1);

namespace App\Models;

final class GuidancePractitionerProfileModel extends TenantModel
{
    protected $table = 'guidance_practitioner_profiles';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'user_id',
        'credential_tier',
        'license_number',
        'credential_expiry',
        'professional_standing',
        'service_scope_json',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}


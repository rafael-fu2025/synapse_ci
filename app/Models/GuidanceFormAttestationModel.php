<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceFormAttestationModel extends TenantModel
{
    protected $table = 'guidance_form_attestations';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'form_instance_id',
        'form_revision_id',
        'signer_user_id',
        'signer_person_id',
        'signer_role',
        'signer_auth_version',
        'credential_snapshot',
        'attestation_statement_hash',
        'snapshot_hash',
        'attested_at',
        'attestation_method',
        'ip_address',
        'user_agent',
    ];

    protected $useTimestamps = false;
}


<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceDocumentIssuanceModel extends TenantModel
{
    protected $table = 'guidance_document_issuances';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'form_instance_id',
        'form_revision_id',
        'verification_code',
        'pdf_sha256',
        'renderer_version',
        'issued_by_user_id',
        'issued_at',
        'status',
        'void_reason',
        'voided_by_user_id',
        'voided_at',
    ];

    protected $useTimestamps = false;
}


<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceConsentReceiptModel extends TenantModel
{
    protected $table = 'guidance_consent_receipts';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'user_id',
        'privacy_notice_id',
        'collection_basis',
        'consent_given',
        'statement_hash',
        'recorded_at',
    ];

    protected $useTimestamps = false;
}


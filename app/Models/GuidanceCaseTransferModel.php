<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceCaseTransferModel extends TenantModel
{
    protected $table = 'guidance_case_transfers';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'case_id',
        'from_counsellor_user_id',
        'to_counsellor_user_id',
        'transfer_type',
        'reason',
        'status',
        'requested_at',
        'responded_at',
        'response_notes',
        'created_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';
}


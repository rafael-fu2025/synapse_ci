<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceLegalHoldModel extends TenantModel
{
    protected $table = 'guidance_legal_holds';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'entity_type',
        'entity_id',
        'reason',
        'placed_by_user_id',
        'placed_at',
        'lifted_by_user_id',
        'lifted_at',
        'is_active',
    ];

    protected $useTimestamps = false;
}


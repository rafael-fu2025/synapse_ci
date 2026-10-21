<?php

declare(strict_types=1);

namespace App\Models;

final class MisYearLevelModel extends TenantModel
{
    protected $table = 'mis_year_levels';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'code',
        'name',
        'synced_at',
    ];

    protected $useTimestamps = false;
}

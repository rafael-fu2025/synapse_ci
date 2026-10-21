<?php

declare(strict_types=1);

namespace App\Models;

final class MisDepartmentModel extends TenantModel
{
    protected $table = 'mis_departments';
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

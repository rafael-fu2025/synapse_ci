<?php

declare(strict_types=1);

namespace App\Models;

final class MisProgramModel extends TenantModel
{
    protected $table = 'mis_programs';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'code',
        'name',
        'department_code',
        'synced_at',
    ];

    protected $useTimestamps = false;
}

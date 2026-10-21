<?php

declare(strict_types=1);

namespace App\Models;

final class DepartmentStaffModel extends TenantModel
{
    protected $table = 'department_staff';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'unit',
        'status',
        'enrolled_by_user_id',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}

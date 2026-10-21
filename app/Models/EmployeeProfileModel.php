<?php

declare(strict_types=1);

namespace App\Models;

final class EmployeeProfileModel extends TenantModel
{
    protected $table = 'employee_profiles';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'department',
        'position',
        'employment_status',
        'is_teaching',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByPersonId(int $personId): ?array
    {
        return $this->scoped()->where('person_id', $personId)->first();
    }
}

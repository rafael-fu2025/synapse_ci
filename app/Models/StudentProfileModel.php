<?php

declare(strict_types=1);

namespace App\Models;

final class StudentProfileModel extends TenantModel
{
    protected $table = 'student_profiles';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'program',
        'year_level',
        'section',
        'department',
        'enrollment_type',
        'is_graduating',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByPersonId(int $personId): ?array
    {
        return $this->scoped()->where('person_id', $personId)->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

final class HealthProfileModel extends TenantModel
{
    protected $table = 'health_profiles';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'blood_type',
        'allergies',
        'chronic_conditions',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByPersonId(int $personId): ?array
    {
        return $this->scoped()->where('person_id', $personId)->first();
    }
}

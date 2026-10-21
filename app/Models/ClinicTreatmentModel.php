<?php

declare(strict_types=1);

namespace App\Models;

final class ClinicTreatmentModel extends TenantModel
{
    protected $table = 'clinic_treatments';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'encounter_id',
        'diagnosis',
        'treatment_plan',
        'services_rendered',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByEncounterId(int $encounterId): ?array
    {
        return $this->scoped()->where('encounter_id', $encounterId)->first();
    }
}

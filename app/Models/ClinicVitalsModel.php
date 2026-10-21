<?php

declare(strict_types=1);

namespace App\Models;

final class ClinicVitalsModel extends TenantModel
{
    protected $table = 'clinic_vitals';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'encounter_id',
        'bp_systolic',
        'bp_diastolic',
        'pulse_bpm',
        'temp_c',
        'spo2_pct',
        'weight_kg',
        'height_cm',
        'bmi',
        'recorded_by_user_id',
        'recorded_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByEncounterId(int $encounterId): ?array
    {
        return $this->scoped()->where('encounter_id', $encounterId)->first();
    }
}

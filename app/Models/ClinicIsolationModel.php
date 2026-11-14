<?php

declare(strict_types=1);

namespace App\Models;

final class ClinicIsolationModel extends TenantModel
{
    protected $table = 'clinic_isolation_cases';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'encounter_id',
        'condition_code',
        'condition_other',
        'isolation_start_date',
        'isolation_days',
        'isolation_end_date',
        'clearance_status',
        'cleared_at',
        'cleared_by_user_id',
        'notes',
        'reported_by_user_id',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
}


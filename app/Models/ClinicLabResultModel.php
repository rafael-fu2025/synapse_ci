<?php

declare(strict_types=1);

namespace App\Models;

final class ClinicLabResultModel extends TenantModel
{
    protected $table = 'clinic_lab_results';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'encounter_id',
        'school_year',
        'context_type',
        'test_name',
        'result_status',
        'result_summary',
        'clinical_notes',
        'requested_at',
        'submitted_at',
        'recorded_by_user_id',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
}


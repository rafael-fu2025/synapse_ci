<?php

declare(strict_types=1);

namespace App\Models;

final class ClinicEnrollmentClearanceModel extends TenantModel
{
    protected $table = 'clinic_enrollment_clearances';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'school_year',
        'semester',
        'applicant_category',
        'required_labs_json',
        'clearance_status',
        'remarks',
        'cleared_by_user_id',
        'cleared_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
}


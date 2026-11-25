<?php

declare(strict_types=1);

namespace App\Models;

/** Time-bounded student internship placement records for Clinic, Guidance, and BMG. */
final class StudentInternPlacementModel extends TenantModel
{
    protected $table = 'student_intern_placements';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'user_id',
        'person_id',
        'unit',
        'intern_role',
        'supervisor_user_id',
        'status',
        'starts_at',
        'ends_at',
        'academic_term',
        'assignment_reason',
        'activated_at',
        'ended_at',
        'revoked_by_user_id',
        'revoke_reason',
        'created_by_user_id',
        'updated_by_user_id',
        'version',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}

<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceFormInstanceModel extends TenantModel
{
    protected $table = 'guidance_form_instances';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'form_definition_id',
        'definition_revision_id',
        'person_id',
        'case_id',
        'episode_id',
        'session_id',
        'referral_id',
        'appointment_id',
        'intake_id',
        'instance_number',
        'status',
        'privacy_notice_id',
        'retention_policy_id',
        'retention_due_at',
        'created_by_user_id',
        'finalized_by_user_id',
        'finalized_at',
        'assigned_supervisor_user_id',
        'submitted_for_cosign_at',
        'submission_notes',
        'supervisor_notes',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}


<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceSafetyAssessmentModel extends TenantModel
{
    protected $table = 'guidance_safety_assessments';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'intake_referral_id',
        'case_id',
        'person_id',
        'assessor_user_id',
        'risk_level',
        'self_harm_risk',
        'harm_to_others_risk',
        'indicators_cipher',
        'indicators_nonce',
        'indicators_tag',
        'indicators_key_version',
        'immediate_actions_taken',
        'safety_plan_cipher',
        'safety_plan_nonce',
        'safety_plan_tag',
        'safety_plan_key_version',
        'assessed_at',
    ];

    protected $useTimestamps = false;

    /**
     * Get safety assessments for a person.
     *
     * @param int $personId
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getByPersonId(int $personId, ?int $tenantId = null): array
    {
        return $this->scoped($tenantId)
            ->where('person_id', $personId)
            ->orderBy('assessed_at', 'DESC')
            ->findAll();
    }
}


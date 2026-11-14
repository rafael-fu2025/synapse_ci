<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceSafetyEscalationModel extends TenantModel
{
    protected $table = 'guidance_safety_escalations';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'safety_assessment_id',
        'escalated_by_user_id',
        'escalation_channel',
        'recipient_agency_or_person',
        'summary_notes',
        'acknowledged_at',
        'acknowledged_by',
        'occurred_at',
    ];

    protected $useTimestamps = false;

    /**
     * Get escalations by safety assessment id.
     *
     * @param int $safetyAssessmentId
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getByAssessmentId(int $safetyAssessmentId, ?int $tenantId = null): array
    {
        return $this->scoped($tenantId)
            ->where('safety_assessment_id', $safetyAssessmentId)
            ->orderBy('occurred_at', 'ASC')
            ->findAll();
    }
}


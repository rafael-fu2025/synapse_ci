<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceIntakeReferralModel extends TenantModel
{
    protected $table = 'guidance_intake_referrals';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'referral_number',
        'source_type',
        'clinic_referral_id',
        'subject_person_id',
        'referrer_user_id',
        'referrer_person_id',
        'referrer_name_snapshot',
        'referrer_designation_snapshot',
        'referrer_department_snapshot',
        'referrer_contact_snapshot',
        'priority_hint',
        'status',
        'concern_catalog_version',
        'selections_cipher',
        'selections_nonce',
        'selections_tag',
        'selections_key_version',
        'narrative_cipher',
        'narrative_nonce',
        'narrative_tag',
        'narrative_key_version',
        'prior_actions_cipher',
        'prior_actions_nonce',
        'prior_actions_tag',
        'prior_actions_key_version',
        'assigned_counsellor_user_id',
        'linked_case_id',
        'triage_notes',
        'decline_reason',
        'external_facility',
        'submitted_at',
        'received_at',
        'triaged_at',
        'responded_at',
        'closed_at',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Find an intake referral by referral number within tenant scope.
     *
     * @param string $referralNumber
     * @param int|null $tenantId
     * @return array<string, mixed>|null
     */
    public function findByReferralNumber(string $referralNumber, ?int $tenantId = null): ?array
    {
        return $this->scoped($tenantId)
            ->where('referral_number', $referralNumber)
            ->first();
    }
}


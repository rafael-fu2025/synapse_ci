<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceEpisodeModel extends TenantModel
{
    protected $table = 'guidance_episodes';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'intake_referral_id',
        'case_id',
        'status',
        'opened_at',
        'closed_at',
        'primary_counselor_user_id',
        'closure_reason',
        'closure_notes_cipher',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    /**
     * Get episodes for a person.
     *
     * @param int $personId
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getByPersonId(int $personId, ?int $tenantId = null): array
    {
        return $this->scoped($tenantId)
            ->where('person_id', $personId)
            ->orderBy('opened_at', 'DESC')
            ->findAll();
    }

    /**
     * Get the active or open episode for a person.
     *
     * @param int $personId
     * @param int|null $tenantId
     * @return array<string, mixed>|null
     */
    public function findActiveByPersonId(int $personId, ?int $tenantId = null): ?array
    {
        return $this->scoped($tenantId)
            ->where('person_id', $personId)
            ->whereIn('status', ['open', 'active'])
            ->orderBy('opened_at', 'DESC')
            ->first();
    }

    /**
     * Get episodes assigned to a counselor.
     *
     * @param int $counselorUserId
     * @param string|null $status
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getByCounselor(int $counselorUserId, ?string $status = null, ?int $tenantId = null): array
    {
        $builder = $this->scoped($tenantId)
            ->where('primary_counselor_user_id', $counselorUserId);

        if ($status !== null) {
            $builder->where('status', $status);
        }

        return $builder->orderBy('opened_at', 'DESC')->findAll();
    }
}

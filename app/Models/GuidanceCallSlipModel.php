<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceCallSlipModel extends TenantModel
{
    protected $table = 'guidance_call_slips';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'episode_id',
        'person_id',
        'issuing_counselor_user_id',
        'call_slip_number',
        'call_date',
        'call_time',
        'purpose',
        'purpose_other',
        'attendance_status',
        'parent_guardian_delivery',
        'delivery_notes',
        'form_instance_id',
        'acknowledged_at',
        'attended_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    /**
     * Find call slip by unique call slip number.
     *
     * @param string $callSlipNumber
     * @param int|null $tenantId
     * @return array<string, mixed>|null
     */
    public function findByCallSlipNumber(string $callSlipNumber, ?int $tenantId = null): ?array
    {
        return $this->scoped($tenantId)
            ->where('call_slip_number', $callSlipNumber)
            ->first();
    }

    /**
     * Get call slips for an episode.
     *
     * @param int $episodeId
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getByEpisodeId(int $episodeId, ?int $tenantId = null): array
    {
        return $this->scoped($tenantId)
            ->where('episode_id', $episodeId)
            ->orderBy('call_date', 'DESC')
            ->orderBy('call_time', 'DESC')
            ->findAll();
    }

    /**
     * Get call slips for a student/person.
     *
     * @param int $personId
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getByPersonId(int $personId, ?int $tenantId = null): array
    {
        return $this->scoped($tenantId)
            ->where('person_id', $personId)
            ->orderBy('call_date', 'DESC')
            ->orderBy('call_time', 'DESC')
            ->findAll();
    }

    /**
     * Get call slips issued by a counselor.
     *
     * @param int $counselorUserId
     * @param string|null $date
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getByCounselor(int $counselorUserId, ?string $date = null, ?int $tenantId = null): array
    {
        $builder = $this->scoped($tenantId)
            ->where('issuing_counselor_user_id', $counselorUserId);

        if ($date !== null) {
            $builder->where('call_date', $date);
        }

        return $builder->orderBy('call_date', 'ASC')
            ->orderBy('call_time', 'ASC')
            ->findAll();
    }
}

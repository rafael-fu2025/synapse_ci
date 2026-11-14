<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceAppointmentModel extends TenantModel
{
    protected $table = 'guidance_appointments';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'episode_id',
        'person_id',
        'counselor_user_id',
        'appointment_number',
        'scheduled_date',
        'scheduled_time',
        'purpose',
        'purpose_other',
        'status',
        'form_instance_id',
        'queue_ticket_id',
        'notes_cipher',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    /**
     * Find appointment by unique appointment number.
     *
     * @param string $appointmentNumber
     * @param int|null $tenantId
     * @return array<string, mixed>|null
     */
    public function findByAppointmentNumber(string $appointmentNumber, ?int $tenantId = null): ?array
    {
        return $this->scoped($tenantId)
            ->where('appointment_number', $appointmentNumber)
            ->first();
    }

    /**
     * Get appointments for an episode.
     *
     * @param int $episodeId
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getByEpisodeId(int $episodeId, ?int $tenantId = null): array
    {
        return $this->scoped($tenantId)
            ->where('episode_id', $episodeId)
            ->orderBy('scheduled_date', 'DESC')
            ->orderBy('scheduled_time', 'DESC')
            ->findAll();
    }

    /**
     * Get appointments for a student/person.
     *
     * @param int $personId
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getByPersonId(int $personId, ?int $tenantId = null): array
    {
        return $this->scoped($tenantId)
            ->where('person_id', $personId)
            ->orderBy('scheduled_date', 'DESC')
            ->orderBy('scheduled_time', 'DESC')
            ->findAll();
    }

    /**
     * Get appointments for a counselor.
     *
     * @param int $counselorUserId
     * @param string|null $date
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getByCounselor(int $counselorUserId, ?string $date = null, ?int $tenantId = null): array
    {
        $builder = $this->scoped($tenantId)
            ->where('counselor_user_id', $counselorUserId);

        if ($date !== null) {
            $builder->where('scheduled_date', $date);
        }

        return $builder->orderBy('scheduled_date', 'ASC')
            ->orderBy('scheduled_time', 'ASC')
            ->findAll();
    }
}

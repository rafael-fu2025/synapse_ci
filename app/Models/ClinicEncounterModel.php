<?php

declare(strict_types=1);

namespace App\Models;

final class ClinicEncounterModel extends TenantModel
{
    protected $table = 'clinic_encounters';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'attending_user_id',
        'appointment_id',
        'queue_entry_id',
        'status',
        'chief_complaint',
        'assessment_notes',
        'disposition',
        'disposition_notes',
        'follow_up_date',
        'started_at',
        'completed_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    /**
     * Get encounters list with joined patient information.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getListWithPatients(?string $status = null, ?string $date = null, int $limit = 50): array
    {
        $builder = $this->scoped()
            ->select('clinic_encounters.*, p.first_name, p.last_name, p.mis_id, p.kind, u.username as attending_name')
            ->join('people p', 'p.id = clinic_encounters.person_id AND p.tenant_id = clinic_encounters.tenant_id AND p.deleted_at IS NULL')
            ->join('users u', 'u.id = clinic_encounters.attending_user_id AND u.tenant_id = clinic_encounters.tenant_id', 'left')
            ->orderBy('clinic_encounters.started_at', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('clinic_encounters.status', $status);
        }
        if ($date !== null && $date !== '') {
            $builder->where('DATE(clinic_encounters.started_at)', $date);
        }

        return $builder->findAll($limit);
    }
}

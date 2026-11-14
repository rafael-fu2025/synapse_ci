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
        'encoded_by_user_id',
        'appointment_id',
        'queue_entry_id',
        'status',
        'chief_complaint',
        'complaint_category',
        'complaint_qualifier',
        'assessment_notes',
        'disposition',
        'disposition_notes',
        'follow_up_date',
        'started_at',
        'visited_at',
        'completed_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    /** @param array{q?: string, from?: string, to?: string, complaint?: string, disposition?: string} $filters */
    public function getListPage(\App\Libraries\TablePage $page, ?string $status = null, ?string $date = null, array $filters = []): array
    {
        $total = $this->listQuery()->countAllResults();
        $filtered = $this->listQuery($status, $date, $filters)->countAllResults();
        $pagination = $page->metadata($total, $filtered);
        $encounters = $this->listQuery($status, $date, $filters)
            ->orderBy('COALESCE(clinic_encounters.visited_at, clinic_encounters.started_at)', 'DESC', false)
            ->orderBy('clinic_encounters.id', 'DESC')
            ->findAll($page->perPage, ($pagination['page'] - 1) * $page->perPage);
        return compact('encounters', 'pagination');
    }

    /**
     * Get encounters list with joined patient information.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getListWithPatients(?string $status = null, ?string $date = null, int $limit = 50): array
    {
        return $this->listQuery($status, $date)
            ->orderBy('COALESCE(clinic_encounters.visited_at, clinic_encounters.started_at)', 'DESC', false)
            ->orderBy('clinic_encounters.id', 'DESC')->findAll($limit);
    }

    /** @param array{q?: string, from?: string, to?: string, complaint?: string, disposition?: string} $filters */
    private function listQuery(?string $status = null, ?string $date = null, array $filters = []): self
    {
        $builder = $this->scoped()
            ->select('clinic_encounters.*, p.first_name, p.last_name, p.mis_id, p.kind, u.username as attending_name, enc_u.username as encoder_name')
            ->join('people p', 'p.id = clinic_encounters.person_id AND p.tenant_id = clinic_encounters.tenant_id AND p.deleted_at IS NULL')
            ->join('users u', 'u.id = clinic_encounters.attending_user_id AND u.tenant_id = clinic_encounters.tenant_id', 'left')
            ->join('users enc_u', 'enc_u.id = clinic_encounters.encoded_by_user_id AND enc_u.tenant_id = clinic_encounters.tenant_id', 'left');

        if ($status !== null && $status !== '') {
            $builder->where('clinic_encounters.status', $status);
        }

        // Interpret Manila calendar dates cleanly against UTC-stored timestamps.
        $fromDate = trim((string) ($filters['from'] ?? ''));
        $toDate = trim((string) ($filters['to'] ?? ''));
        if ($date !== null && $date !== '' && $fromDate === '' && $toDate === '') {
            $fromDate = $date;
            $toDate = $date;
        }
        $manilaTz = \App\Services\Scheduling\SchedulingClock::timezone();
        $utcTz = new \DateTimeZone('UTC');
        if ($fromDate !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $fromDate)) {
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate, $manilaTz);
            if ($start !== false && $start->format('Y-m-d') === $fromDate) {
                $builder->where('COALESCE(clinic_encounters.visited_at, clinic_encounters.started_at) >=', $start->setTimezone($utcTz)->format('Y-m-d H:i:s'));
            }
        }
        if ($toDate !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $toDate)) {
            $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $toDate, $manilaTz);
            if ($end !== false && $end->format('Y-m-d') === $toDate) {
                $builder->where('COALESCE(clinic_encounters.visited_at, clinic_encounters.started_at) <', $end->modify('+1 day')->setTimezone($utcTz)->format('Y-m-d H:i:s'));
            }
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $builder->groupStart();
            \App\Libraries\LikePattern::apply($builder->builder(), 'p.mis_id', $q);
            $tokens = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
            if ($tokens !== []) {
                $builder->orGroupStart();
                foreach ($tokens as $token) {
                    \App\Libraries\LikePattern::applyGroup($builder->builder(), ['p.first_name', 'p.last_name', 'p.middle_name'], $token);
                }
                $builder->groupEnd();
            }
            $builder->groupEnd();
        }

        $complaint = trim((string) ($filters['complaint'] ?? ''));
        if ($complaint !== '') {
            $builder->where('clinic_encounters.complaint_category', $complaint);
        }

        $disposition = trim((string) ($filters['disposition'] ?? ''));
        if ($disposition !== '') {
            $builder->where('clinic_encounters.disposition', $disposition);
        }

        return $builder;
    }
}

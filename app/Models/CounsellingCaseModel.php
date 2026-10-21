<?php

declare(strict_types=1);

namespace App\Models;

final class CounsellingCaseModel extends TenantModel
{
    protected $table = 'counselling_cases';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'person_id',
        'counsellor_user_id',
        'case_number',
        'title',
        'category',
        'status',
        'opened_at',
        'closed_at',
        'summary_notes',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    /**
     * Get list of cases with student demographics and counsellor info.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getListWithPatients(?string $status = null, ?string $category = null, int $limit = 50, ?int $page = null): array
    {
        $builder = $this->scoped()
            ->select('counselling_cases.*, p.first_name, p.last_name, p.mis_id, p.kind, u.username as counsellor_name, sp.program, sp.year_level, ep.department as emp_dept, ep.position as emp_position')
            ->join('people p', 'p.id = counselling_cases.person_id AND p.tenant_id = counselling_cases.tenant_id')
            ->join('users u', 'u.id = counselling_cases.counsellor_user_id AND u.tenant_id = counselling_cases.tenant_id')
            ->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = counselling_cases.tenant_id', 'left')
            ->join('employee_profiles ep', 'ep.person_id = p.id AND ep.tenant_id = counselling_cases.tenant_id', 'left')
            ->where('p.deleted_at', null)
            ->orderBy('counselling_cases.opened_at', 'DESC')
            ->orderBy('counselling_cases.id', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('counselling_cases.status', $status);
        }
        if ($category !== null && $category !== '') {
            $builder->where('counselling_cases.category', $category);
        }

        return $page === null ? $builder->findAll($limit) : $builder->paginate($limit, 'default', max(1, $page));
    }

    /**
     * Generate the next sequential case number (e.g. GC-2026-001).
     */
    public function nextCaseNumber(): string
    {
        $db = $this->db;
        if ($db->transDepth < 1 || !$db->transStatus()) {
            throw new \RuntimeException('Case numbering requires an active transaction.', 409);
        }
        // Ordinary case creation and referral handoff must hold the same tenant mutex until insert commits.
        $tenantTable = $db->protectIdentifiers($db->prefixTable('tenants'));
        $tenant = \App\Services\CurrentTenant::id();
        if ($db->query("SELECT id FROM {$tenantTable} WHERE id = ? FOR UPDATE", [$tenant])->getRowArray() === null) {
            throw new \RuntimeException('Case tenant not found.', 409);
        }
        $year = \App\Services\Scheduling\SchedulingClock::now()->format('Y');
        $prefix = "GC-{$year}-";
        $table = $db->protectIdentifiers($db->prefixTable('counselling_cases'));
        // Current read, including deleted rows: an earlier repeatable-read snapshot must not reuse a number.
        $last = $db->query("SELECT case_number FROM {$table} WHERE tenant_id = ? AND case_number LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE",
            [$tenant, $prefix . '%'])->getRowArray();

        $nextSeq = 1;
        if ($last !== null) {
            $numPart = (int) substr($last['case_number'], strlen($prefix));
            $nextSeq = $numPart + 1;
        }

        return sprintf('%s%03d', $prefix, $nextSeq);
    }
}

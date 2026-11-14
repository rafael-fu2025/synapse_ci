<?php

declare(strict_types=1);

namespace App\Services\Clinic;

use App\Models\ClinicIsolationModel;
use App\Services\CurrentTenant;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/** Communicable disease monitoring and isolation calendar (Clinic RBAC only for patient rows; anonymized threshold alerts for leadership). */
final class IsolationTrackingService
{
    public const CONDITIONS = [
        'chickenpox' => 'Chickenpox (Varicella)',
        'measles'    => 'Measles (Rubeola)',
        'dengue'     => 'Dengue Fever',
        'covid19'    => 'COVID-19',
        'influenza'  => 'Influenza / ILI',
        'ptb'        => 'Pulmonary Tuberculosis (PTB)',
        'mumps'      => 'Mumps',
        'other'      => 'Other Communicable Illness',
    ];

    public const DEFAULT_ISOLATION_DAYS = [
        'chickenpox' => 7,
        'measles'    => 7,
        'dengue'     => 5,
        'covid19'    => 5,
        'influenza'  => 5,
        'ptb'        => 14,
        'mumps'      => 5,
        'other'      => 5,
    ];

    private BaseConnection $db;
    private ClinicIsolationModel $model;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->model = new ClinicIsolationModel($this->db);
    }

    /** Monthly/filtered isolation calendar data + condition summary totals + anonymized threshold alerts. */
    public function listing(array $filters = []): array
    {
        $tenantId = CurrentTenant::id();
        $manila = SchedulingClock::timezone();
        $today = SchedulingClock::today();

        $month = trim((string) ($filters['month'] ?? ''));
        if ($month === '' || !preg_match('/\A\d{4}-\d{2}\z/D', $month)) {
            $month = substr($today, 0, 7);
        }
        $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $manila);
        if ($monthStart === false) {
            $month = substr($today, 0, 7);
            $monthStart = new DateTimeImmutable($month . '-01', $manila);
        }
        $monthEnd = $monthStart->modify('last day of this month');
        $condition = trim((string) ($filters['condition'] ?? 'all'));
        $status = trim((string) ($filters['status'] ?? 'all'));

        $builder = $this->db->table('clinic_isolation_cases c')
            ->select('c.*, p.mis_id, p.first_name, p.last_name, p.kind, sp.program, sp.year_level, ep.department AS emp_dept, rep.username AS reported_by_name, clr.username AS cleared_by_name')
            ->join('people p', 'p.id = c.person_id AND p.tenant_id = c.tenant_id AND p.deleted_at IS NULL')
            ->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = p.tenant_id', 'left')
            ->join('employee_profiles ep', 'ep.person_id = p.id AND ep.tenant_id = p.tenant_id', 'left')
            ->join('users rep', 'rep.id = c.reported_by_user_id AND rep.tenant_id = c.tenant_id', 'left')
            ->join('users clr', 'clr.id = c.cleared_by_user_id AND clr.tenant_id = c.tenant_id', 'left')
            ->where('c.tenant_id', $tenantId)
            ->where('c.deleted_at', null);

        if ($condition !== 'all' && isset(self::CONDITIONS[$condition])) {
            $builder->where('c.condition_code', $condition);
        }
        if (in_array($status, ['active_isolation', 'cleared', 'hospitalized'], true)) {
            $builder->where('c.clearance_status', $status);
        }

        // Cases overlapping the selected month
        $monthCases = (clone $builder)
            ->where('c.isolation_start_date <=', $monthEnd->format('Y-m-d'))
            ->where('c.isolation_end_date >=', $monthStart->format('Y-m-d'))
            ->orderBy('c.isolation_start_date', 'ASC')
            ->orderBy('c.id', 'DESC')
            ->get()->getResultArray();

        $allActiveCount = (int) $this->db->table('clinic_isolation_cases')
            ->where('tenant_id', $tenantId)->where('deleted_at', null)
            ->where('clearance_status', 'active_isolation')
            ->countAllResults();

        $conditionCounts = array_fill_keys(array_keys(self::CONDITIONS), ['active' => 0, 'total' => 0]);
        foreach ($this->db->table('clinic_isolation_cases')
            ->select('condition_code, clearance_status, COUNT(*) AS cnt')
            ->where('tenant_id', $tenantId)->where('deleted_at', null)
            ->groupBy(['condition_code', 'clearance_status'])
            ->get()->getResultArray() as $row) {
            $code = (string) $row['condition_code'];
            if (!isset($conditionCounts[$code])) {
                continue;
            }
            $conditionCounts[$code]['total'] += (int) $row['cnt'];
            if ($row['clearance_status'] === 'active_isolation') {
                $conditionCounts[$code]['active'] += (int) $row['cnt'];
            }
        }

        return [
            'month'           => $monthStart->format('Y-m'),
            'monthLabel'      => $monthStart->format('F Y'),
            'prevMonth'       => $monthStart->modify('-1 month')->format('Y-m'),
            'nextMonth'       => $monthStart->modify('+1 month')->format('Y-m'),
            'monthStart'      => $monthStart->format('Y-m-d'),
            'monthEnd'        => $monthEnd->format('Y-m-d'),
            'today'           => $today,
            'cases'           => $monthCases,
            'activeCount'     => $allActiveCount,
            'conditionCounts' => $conditionCounts,
            'alerts'          => $this->anonymizedThresholdAlerts(),
            'filters'         => ['month' => $monthStart->format('Y-m'), 'condition' => $condition, 'status' => $status],
        ];
    }

    public function logCase(array $input, int $actorId): array
    {
        if ($actorId < 1) {
            throw new InvalidArgumentException('Authenticated clinician is required.', 422);
        }
        $tenantId = CurrentTenant::id();
        $personId = filter_var($input['person_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($personId === false) {
            throw new InvalidArgumentException('Please select a patient.', 422);
        }
        $person = $this->db->table('people')->where('tenant_id', $tenantId)->where('id', $personId)->where('deleted_at', null)->get()->getRowArray();
        if ($person === null) {
            throw new InvalidArgumentException('Patient not found.', 404);
        }

        $condition = trim((string) ($input['condition_code'] ?? ''));
        if (!isset(self::CONDITIONS[$condition])) {
            throw new InvalidArgumentException('Select a valid communicable disease category.', 422);
        }
        $conditionOther = trim((string) ($input['condition_other'] ?? ''));
        if ($condition === 'other' && $conditionOther === '') {
            throw new InvalidArgumentException('Please specify the illness when selecting Other.', 422);
        }

        $startRaw = trim((string) ($input['isolation_start_date'] ?? SchedulingClock::today()));
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $startRaw, SchedulingClock::timezone());
        if ($startDate === false || $startDate->format('Y-m-d') !== $startRaw) {
            throw new InvalidArgumentException('Provide a valid isolation start date (YYYY-MM-DD).', 422);
        }

        $daysRaw = $input['isolation_days'] ?? (self::DEFAULT_ISOLATION_DAYS[$condition] ?? 7);
        $days = filter_var($daysRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]);
        if ($days === false) {
            throw new InvalidArgumentException('Isolation duration must be between 1 and 90 days.', 422);
        }
        $endDate = $startDate->modify('+' . ($days - 1) . ' days');

        $encounterId = null;
        if (!empty($input['encounter_id'])) {
            $encId = filter_var($input['encounter_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($encId !== false) {
                $enc = $this->db->table('clinic_encounters')
                    ->where('tenant_id', $tenantId)->where('id', $encId)->where('person_id', $personId)->where('deleted_at', null)
                    ->get()->getRowArray();
                if ($enc !== null) {
                    $encounterId = $encId;
                }
            }
        }

        $notes = trim((string) ($input['notes'] ?? ''));
        if (mb_strlen($notes) > 2000) {
            throw new InvalidArgumentException('Isolation notes must be at most 2000 characters.', 422);
        }

        $status = trim((string) ($input['clearance_status'] ?? 'active_isolation'));
        if (!in_array($status, ['active_isolation', 'hospitalized', 'cleared'], true)) {
            $status = 'active_isolation';
        }

        $id = $this->model->insert([
            'tenant_id'            => $tenantId,
            'person_id'            => $personId,
            'encounter_id'         => $encounterId,
            'condition_code'       => $condition,
            'condition_other'      => $conditionOther !== '' ? mb_substr($conditionOther, 0, 100) : null,
            'isolation_start_date' => $startDate->format('Y-m-d'),
            'isolation_days'       => $days,
            'isolation_end_date'   => $endDate->format('Y-m-d'),
            'clearance_status'     => $status,
            'notes'                => $notes !== '' ? $notes : null,
            'reported_by_user_id'  => $actorId,
        ]);
        if ($id === false) {
            throw new RuntimeException('Could not log communicable isolation case.');
        }
        return $this->model->scoped()->find((int) $id);
    }

    public function clearCase(int $caseId, int $actorId, string $status = 'cleared', ?string $notes = null): array
    {
        if (!in_array($status, ['cleared', 'hospitalized'], true)) {
            throw new InvalidArgumentException('Invalid clearance status.', 422);
        }
        $existing = $this->model->scoped()->find($caseId);
        if ($existing === null) {
            throw new InvalidArgumentException('Isolation case not found.', 404);
        }
        $update = [
            'clearance_status'   => $status,
            'cleared_at'         => gmdate('Y-m-d H:i:s'),
            'cleared_by_user_id' => $actorId,
        ];
        if ($notes !== null && trim($notes) !== '') {
            $existingNotes = trim((string) ($existing['notes'] ?? ''));
            $update['notes'] = trim(($existingNotes !== '' ? $existingNotes . "\n" : '') . 'Clearance note: ' . trim($notes));
        }
        $this->model->scoped()->update($caseId, $update);
        return $this->model->scoped()->find($caseId);
    }

    /**
     * Computes anonymized cluster/threshold alerts over the trailing N days.
     * Contains strictly condition name, count, unit/department aggregate, and date span — NEVER patient PII.
     *
     * @return list<array{condition_code: string, condition_label: string, case_count: int, window_days: int, cohort_summary: string, severity: string}>
     */
    public function anonymizedThresholdAlerts(int $windowDays = 14, int $threshold = 2): array
    {
        $tenantId = CurrentTenant::id();
        $today = SchedulingClock::now();
        $since = $today->modify('-' . ($windowDays - 1) . ' days')->format('Y-m-d');

        $rows = $this->db->table('clinic_isolation_cases c')
            ->select('c.condition_code, COUNT(*) AS case_count')
            ->where('c.tenant_id', $tenantId)
            ->where('c.deleted_at', null)
            ->where('c.isolation_start_date >=', $since)
            ->groupBy('c.condition_code')
            ->having('COUNT(*) >=', $threshold)
            ->orderBy('case_count', 'DESC')
            ->get()->getResultArray();

        $alerts = [];
        foreach ($rows as $row) {
            $code = (string) $row['condition_code'];
            $count = (int) $row['case_count'];
            $cohorts = $this->db->table('clinic_isolation_cases c')
                ->select("COALESCE(sp.department, sp.program, ep.department, 'General Campus') AS unit_group, COUNT(*) AS unit_count", false)
                ->join('people p', 'p.id = c.person_id AND p.tenant_id = c.tenant_id AND p.deleted_at IS NULL')
                ->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = p.tenant_id', 'left')
                ->join('employee_profiles ep', 'ep.person_id = p.id AND ep.tenant_id = p.tenant_id', 'left')
                ->where('c.tenant_id', $tenantId)
                ->where('c.deleted_at', null)
                ->where('c.condition_code', $code)
                ->where('c.isolation_start_date >=', $since)
                ->groupBy('unit_group')
                ->orderBy('unit_count', 'DESC')
                ->limit(3)
                ->get()->getResultArray();

            $cohortSummary = implode(', ', array_map(
                static fn (array $c): string => $c['unit_group'] . ' (' . (int) $c['unit_count'] . ')',
                $cohorts
            ));

            $alerts[] = [
                'condition_code'  => $code,
                'condition_label' => self::CONDITIONS[$code] ?? ucfirst($code),
                'case_count'      => $count,
                'window_days'     => $windowDays,
                'cohort_summary'  => $cohortSummary,
                'severity'        => $count >= 5 ? 'danger' : 'warning',
            ];
        }

        return $alerts;
    }
}


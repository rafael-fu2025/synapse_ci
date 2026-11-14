<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Services\Clinic\InventoryService;
use App\Services\Clinic\IsolationTrackingService;
use App\Services\Clinic\PatientHistoryService;
use App\Services\CurrentTenant;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Extended-horizon Clinic summary reports supporting weekly, monthly, semester,
 * June-to-present School Year (CHED spot-check), and custom ranges up to 366 days.
 */
final class ClinicSummaryReportService
{
    public const MAX_DAYS = 366;

    public const PRESETS = [
        'weekly'      => 'Weekly (Last 7 Days)',
        'monthly'     => 'Current Month',
        'semester'    => 'Current Semester',
        'school_year' => 'School Year to Date (June–Present · CHED)',
        'custom'      => 'Custom Range (up to 366 days)',
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{preset: string, label: string, from: string, to: string, utc_start: string, utc_end: string} */
    public function resolvePeriod(array $input): array
    {
        $manila = SchedulingClock::timezone();
        $utc = new DateTimeZone('UTC');
        $today = SchedulingClock::now();
        $preset = trim((string) ($input['preset'] ?? 'monthly'));
        if (!isset(self::PRESETS[$preset])) {
            $preset = 'monthly';
        }

        if ($preset === 'weekly') {
            $start = $today->modify('-6 days');
            $end = $today;
        } elseif ($preset === 'monthly') {
            $start = new DateTimeImmutable($today->format('Y-m-01'), $manila);
            $end = $today;
        } elseif ($preset === 'semester') {
            $month = (int) $today->format('n');
            $year = (int) $today->format('Y');
            if ($month >= 6 && $month <= 10) {
                $start = new DateTimeImmutable($year . '-06-01', $manila);
            } elseif ($month >= 11) {
                $start = new DateTimeImmutable($year . '-11-01', $manila);
            } elseif ($month <= 3) {
                $start = new DateTimeImmutable(($year - 1) . '-11-01', $manila);
            } else {
                $start = new DateTimeImmutable($year . '-04-01', $manila);
            }
            $end = $today;
        } elseif ($preset === 'school_year') {
            $sy = PatientHistoryService::currentSchoolYear();
            $startYear = (int) substr($sy, 0, 4);
            $start = new DateTimeImmutable($startYear . '-06-01', $manila);
            $end = $today;
        } else {
            $fromRaw = trim((string) ($input['from'] ?? $today->format('Y-m-01')));
            $toRaw = trim((string) ($input['to'] ?? $today->format('Y-m-d')));
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $fromRaw, $manila);
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $toRaw, $manila);
            if ($start === false || $start->format('Y-m-d') !== $fromRaw || $end === false || $end->format('Y-m-d') !== $toRaw) {
                throw new InvalidArgumentException('Provide From and To dates in YYYY-MM-DD format.', 422);
            }
            if ($end < $start || (int) $start->diff($end)->days >= self::MAX_DAYS) {
                throw new InvalidArgumentException('Choose an ordered custom range of at most 366 days.', 422);
            }
        }

        return [
            'preset'    => $preset,
            'label'     => self::PRESETS[$preset],
            'from'      => $start->format('Y-m-d'),
            'to'        => $end->format('Y-m-d'),
            'utc_start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'utc_end'   => $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    public function buildSummary(array $input, ?int $tenantId = null): array
    {
        $tenantId ??= CurrentTenant::id();
        $period = $this->resolvePeriod($input);
        $hasVisitedAt = $this->db->fieldExists('visited_at', 'clinic_encounters');
        $timeExpr = $hasVisitedAt ? 'COALESCE(e.visited_at, e.started_at)' : 'e.started_at';

        $rows = $this->db->table('clinic_encounters e')
            ->select('e.id, e.status, e.chief_complaint, ' . ($hasVisitedAt ? 'e.complaint_category, ' : '') . 'e.disposition, ' . $timeExpr . ' AS effective_at, p.kind', false)
            ->join('people p', 'p.id = e.person_id AND p.tenant_id = e.tenant_id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.deleted_at', null)
            ->where($timeExpr . ' >= ' . $this->db->escape($period['utc_start']), null, false)
            ->where($timeExpr . ' < ' . $this->db->escape($period['utc_end']), null, false)
            ->get()->getResultArray();

        $manila = SchedulingClock::timezone();
        $utc = new DateTimeZone('UTC');
        $totalEncounters = count($rows);
        $completedCount = 0;
        $referredCount = 0;
        $inProgressCount = 0;
        $patientKindCounts = ['student' => 0, 'employee' => 0, 'guest' => 0];
        $monthlyCounts = [];
        $complaintCounts = [];
        $dispositionCounts = [];

        foreach ($rows as $row) {
            match ($row['status']) {
                'completed' => $completedCount++,
                'referred' => $referredCount++,
                'in_progress' => $inProgressCount++,
                default => null,
            };
            $kind = (string) ($row['kind'] ?? 'student');
            $patientKindCounts[$kind] = ($patientKindCounts[$kind] ?? 0) + 1;

            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $row['effective_at'], $utc);
            if ($dt !== false) {
                $mKey = $dt->setTimezone($manila)->format('Y-m');
                $monthlyCounts[$mKey] = ($monthlyCounts[$mKey] ?? 0) + 1;
            }

            $cat = strtolower(trim((string) ($row['complaint_category'] ?? '')));
            $raw = strtolower(trim((string) ($row['chief_complaint'] ?? '')));
            $cLabel = 'Other recorded';
            if ($cat === '' && $raw === '') {
                $cLabel = 'Not recorded';
            } elseif (isset(ClinicReportInsights::COMPLAINTS[$cat])) {
                $cLabel = ClinicReportInsights::COMPLAINTS[$cat];
            } elseif (isset(ClinicReportInsights::COMPLAINTS[$raw])) {
                $cLabel = ClinicReportInsights::COMPLAINTS[$raw];
            }
            $complaintCounts[$cLabel] = ($complaintCounts[$cLabel] ?? 0) + 1;

            $dispKey = strtolower(trim((string) ($row['disposition'] ?? '')));
            $dLabel = $dispKey === '' ? 'Not recorded' : (ClinicReportInsights::DISPOSITIONS[$dispKey] ?? 'Other recorded');
            $dispositionCounts[$dLabel] = ($dispositionCounts[$dLabel] ?? 0) + 1;
        }

        ksort($monthlyCounts);
        arsort($complaintCounts);
        arsort($dispositionCounts);

        $monthlySeries = [];
        foreach ($monthlyCounts as $ym => $count) {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ym . '-01', $manila);
            $monthlySeries[] = [
                'ym'    => $ym,
                'label' => $dt ? $dt->format('M Y') : $ym,
                'count' => $count,
            ];
        }

        $topMedicines = [];
        $dispensedUnits = 0;
        if ((new InventoryService($this->db))->isInstalled()) {
            $operations = $this->db->escapeIdentifiers($this->db->prefixTable('inventory_operations'));
            $medRows = $this->db->table('clinic_encounters e')
                ->select('m.name, m.strength, m.dosage_form, m.unit_of_measure, SUM(d.quantity) AS total_qty, COUNT(DISTINCT e.id) AS encounter_count', false)
                ->join('clinic_dispensations d', 'd.encounter_id = e.id AND d.tenant_id = e.tenant_id')
                ->join('inventory_operations o', 'o.id = d.operation_id AND o.tenant_id = d.tenant_id')
                ->join('clinic_medicines m', 'm.id = d.medicine_id AND m.tenant_id = d.tenant_id')
                ->where('e.tenant_id', $tenantId)->where('e.deleted_at', null)
                ->where($timeExpr . ' >= ' . $this->db->escape($period['utc_start']), null, false)
                ->where($timeExpr . ' < ' . $this->db->escape($period['utc_end']), null, false)
                ->where('o.type', 'dispense')->where('o.reversal_of', null)->where('d.quantity >', 0)
                ->where('NOT EXISTS (SELECT 1 FROM ' . $operations . ' r WHERE r.tenant_id = o.tenant_id AND r.reversal_of = o.id)', null, false)
                ->groupBy(['m.id', 'm.name', 'm.strength', 'm.dosage_form', 'm.unit_of_measure'])
                ->orderBy('total_qty', 'DESC')->limit(10)->get()->getResultArray();
            foreach ($medRows as $mr) {
                $qty = (int) $mr['total_qty'];
                $dispensedUnits += $qty;
                $topMedicines[] = [
                    'name'            => trim($mr['name'] . ' ' . ($mr['strength'] ?? '')),
                    'unit'            => $mr['unit_of_measure'],
                    'total_qty'       => $qty,
                    'encounter_count' => (int) $mr['encounter_count'],
                ];
            }
        }

        $isolationSummary = [];
        $isolationTotal = 0;
        if ($this->db->tableExists('clinic_isolation_cases')) {
            foreach ($this->db->table('clinic_isolation_cases')
                ->select('condition_code, COUNT(*) AS cnt')
                ->where('tenant_id', $tenantId)->where('deleted_at', null)
                ->where('isolation_start_date >=', $period['from'])
                ->where('isolation_start_date <=', $period['to'])
                ->groupBy('condition_code')->orderBy('cnt', 'DESC')
                ->get()->getResultArray() as $ir) {
                $cnt = (int) $ir['cnt'];
                $isolationTotal += $cnt;
                $isolationSummary[] = [
                    'code'  => $ir['condition_code'],
                    'label' => IsolationTrackingService::CONDITIONS[$ir['condition_code']] ?? ucfirst((string) $ir['condition_code']),
                    'count' => $cnt,
                ];
            }
        }

        $clearanceSummary = ['pending' => 0, 'cleared' => 0, 'hold_followup' => 0];
        $currentSy = PatientHistoryService::currentSchoolYear();
        if ($this->db->tableExists('clinic_enrollment_clearances')) {
            foreach ($this->db->table('clinic_enrollment_clearances')
                ->select('clearance_status, COUNT(*) AS cnt')
                ->where('tenant_id', $tenantId)->where('deleted_at', null)
                ->where('school_year', $currentSy)
                ->groupBy('clearance_status')->get()->getResultArray() as $cr) {
                if (isset($clearanceSummary[$cr['clearance_status']])) {
                    $clearanceSummary[$cr['clearance_status']] = (int) $cr['cnt'];
                }
            }
        }

        return [
            'period'            => $period,
            'school_year'       => $currentSy,
            'totals'            => [
                'encounters'    => $totalEncounters,
                'completed'     => $completedCount,
                'referred'      => $referredCount,
                'in_progress'   => $inProgressCount,
                'student'       => $patientKindCounts['student'],
                'employee'      => $patientKindCounts['employee'],
                'dispensed'     => $dispensedUnits,
                'isolation'     => $isolationTotal,
            ],
            'monthly_series'    => $monthlySeries,
            'complaints'        => $complaintCounts,
            'dispositions'      => $dispositionCounts,
            'top_medicines'     => $topMedicines,
            'isolation_summary' => $isolationSummary,
            'clearances'        => $clearanceSummary,
            'isolation_alerts'  => (new IsolationTrackingService($this->db))->anonymizedThresholdAlerts(),
        ];
    }
}

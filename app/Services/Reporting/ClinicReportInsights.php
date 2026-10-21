<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Services\Clinic\InventoryService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/** Aggregate-only, current corrected view of the daily clinic metric's cohort. */
final class ClinicReportInsights
{
    public const COMPLAINTS = [
        'headache' => 'Headache', 'fever' => 'Fever', 'dizziness' => 'Dizziness',
        'abdominal pain' => 'Abdominal Pain', 'cough' => 'Cough', 'colds' => 'Colds',
        'toothache' => 'Toothache', 'injury' => 'Injury', 'nausea' => 'Nausea',
        'vomiting' => 'Vomiting', 'menstrual cramps' => 'Menstrual Cramps', 'checkup' => 'Checkup',
    ];
    public const DISPOSITIONS = [
        'discharged' => 'Discharged', 'admitted' => 'Admitted',
        'referred_to_guidance' => 'Referred to Guidance', 'sent_home' => 'Sent Home',
        'referred_to_hospital' => 'Referred to Hospital',
    ];

    public static function collect(BaseConnection $db, int $tenantId, ExportRange $range, string $storageTimezone): array
    {
        [$from, $until] = $range->bounds($storageTimezone);
        // Deliberately no deleted_at predicate: this is also the daily metric's denominator.
        $cohort = static fn (): BaseBuilder => $db->table('clinic_encounters e')
            ->where('e.tenant_id', $tenantId)->where('e.status', 'completed')
            ->where('e.started_at >=', $from)->where('e.started_at <', $until);
        $complaints = self::buckets($cohort(), $db, 'chief_complaint', self::COMPLAINTS);
        $dispositions = self::buckets($cohort(), $db, 'disposition', self::DISPOSITIONS);
        $known = [];
        $other = $complaints['Other recorded'] ?? 0;
        foreach (self::COMPLAINTS as $label) {
            if (isset($complaints[$label])) {
                $known[] = ['label' => $label, 'count' => $complaints[$label]];
            }
        }
        usort($known, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp($a['label'], $b['label']));
        foreach (array_slice($known, 5) as $row) {
            $other += $row['count'];
        }
        $complaintRows = array_slice($known, 0, 5);
        if ($other > 0) {
            $complaintRows[] = ['label' => 'Other recorded', 'count' => $other];
        }
        $complaintRows[] = ['label' => 'Not recorded', 'count' => $complaints['Not recorded'] ?? 0];
        $dispositionRows = [];
        foreach (self::DISPOSITIONS as $label) {
            $dispositionRows[] = ['label' => $label, 'count' => $dispositions[$label] ?? 0];
        }
        if (($dispositions['Other recorded'] ?? 0) > 0) {
            $dispositionRows[] = ['label' => 'Other recorded', 'count' => $dispositions['Other recorded']];
        }
        $dispositionRows[] = ['label' => 'Not recorded', 'count' => $dispositions['Not recorded'] ?? 0];
        $result = [
            'encounter_total' => (int) $cohort()->countAllResults(),
            'complaints' => $complaintRows, 'dispositions' => $dispositionRows,
            'medicine_status' => 'unavailable', 'medicines' => [],
            'medicine_encounters' => 0, 'legacy_medicine_encounters' => 0,
        ];
        // Never reference cutover columns until the complete ledger and history marker are ready.
        if (!(new InventoryService($db))->isInstalled()) {
            // Legacy coverage is unknown without the ledger schema; zero is a sentinel, not evidence of no dispensing.
            return $result;
        }
        $operations = $db->escapeIdentifiers($db->prefixTable('inventory_operations'));
        $eligible = static fn (): BaseBuilder => $cohort()
            ->join('clinic_dispensations d', 'd.encounter_id = e.id AND d.tenant_id = e.tenant_id')
            ->join('inventory_operations o', 'o.id = d.operation_id AND o.tenant_id = d.tenant_id AND o.encounter_id = d.encounter_id AND o.medicine_id = d.medicine_id')
            ->join('clinic_medicines m', 'm.id = d.medicine_id AND m.tenant_id = d.tenant_id')
            ->where('o.type', 'dispense')->where('o.reversal_of', null)->where('d.quantity >', 0)
            ->where('NOT EXISTS (SELECT 1 FROM ' . $operations . ' r WHERE r.tenant_id = o.tenant_id AND r.reversal_of = o.id)', null, false);
        $rows = $eligible()->select('m.id, m.name, m.strength, m.dosage_form')
            ->select('COUNT(DISTINCT e.id) AS count', false)
            ->groupBy(['m.id', 'm.name', 'm.strength', 'm.dosage_form'])
            ->orderBy('count', 'DESC')->orderBy('m.id', 'ASC')->limit(5)->get()->getResultArray();
        foreach ($rows as $row) {
            $parts = array_filter(array_map(self::catalogText(...), [$row['name'], $row['strength'], $row['dosage_form']]), static fn (string $part): bool => $part !== '');
            $label = implode(' / ', $parts) ?: 'Unnamed medicine';
            if (mb_strlen($label, 'UTF-8') > 96) $label = mb_substr($label, 0, 93, 'UTF-8') . '...';
            $result['medicines'][] = ['label' => $label, 'count' => (int) $row['count']];
        }
        $result['medicine_encounters'] = (int) $eligible()->select('COUNT(DISTINCT e.id) AS count', false)->get()->getRowArray()['count'];
        // Unlinked legacy dispensing is reported separately, never ranked as ledger-backed usage.
        $result['legacy_medicine_encounters'] = (int) $cohort()
            ->join('clinic_dispensations d', 'd.encounter_id = e.id AND d.tenant_id = e.tenant_id')
            ->where('d.operation_id', null)->where('d.quantity >', 0)
            ->select('COUNT(DISTINCT e.id) AS count', false)->get()->getRowArray()['count'];
        $result['medicine_status'] = 'ready';
        return $result;
    }

    /** The CASE emits only constants: neither narratives nor person-level rows reach PHP. */
    private static function buckets(BaseBuilder $cohort, BaseConnection $db, string $column, array $vocabulary): array
    {
        $value = 'LOWER(TRIM(e.' . $column . '))';
        $case = 'CASE WHEN e.' . $column . " IS NULL OR $value = '' THEN 'Not recorded'";
        foreach ($vocabulary as $key => $label) {
            $case .= ' WHEN ' . $value . ' = ' . $db->escape($key) . ' THEN ' . $db->escape($label);
        }
        $case .= " ELSE 'Other recorded' END";
        $rows = $cohort->select($case . ' AS label, COUNT(*) AS count', false)
            ->groupBy($case, false)->get()->getResultArray();
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['label']] = (int) $row['count'];
        }
        return $counts;
    }

    private static function catalogText(?string $value): string
    {
        $text = mb_convert_encoding($value ?? '', 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $text) ?? '';
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 120, 'UTF-8');
    }
}

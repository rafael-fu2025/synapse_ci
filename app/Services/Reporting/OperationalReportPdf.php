<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use DateTimeImmutable;
use RuntimeException;

/** Validates the aggregate dataset before creating a server-owned LaTeX document. */
final class OperationalReportPdf
{
    private const METRICS = [
        'completed_clinic_encounters_started' => ['Completed encounters', 'Clinic'],
        'completed_guidance_sessions_started' => ['Completed sessions', 'Guidance'],
        'bmg_batches_started' => ['Batches started', 'BMG'],
        'bmg_batches_released' => ['Batches released', 'BMG'],
        'bmg_batches_voided' => ['Batches voided', 'BMG'],
    ];

    public static function encode(array $rows, string $from, string $to, ?array $clinicInsights = null): string
    {
        return LatexReportCompiler::compile(self::source($rows, $from, $to, $clinicInsights));
    }

    /** Source is exposed for DB-free layout/validation tests; never accepted from a request. */
    public static function source(array $rows, string $from, string $to, ?array $clinicInsights = null): string
    {
        $start = self::date($from);
        $end = self::date($to);
        $days = (int) $start->diff($end)->format('%r%a');
        if ($days < 0 || $days > 30 || count($rows) < 2 || count($rows) > 156
            || $rows[0] !== ['date_manila', 'unit', 'metric', 'count']) {
            throw new RuntimeException('Invalid PDF export input.', 503);
        }
        $data = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) $data[$day->format('Y-m-d')] = [];
        $selected = [];
        foreach (array_slice($rows, 1) as $row) {
            if (!is_array($row) || count($row) !== 4) throw new RuntimeException('Invalid PDF report row.', 503);
            [$date, $unit, $metric, $count] = array_values($row);
            if (!is_string($metric) || !is_string($date) || !isset(self::METRICS[$metric])) throw new RuntimeException('Invalid PDF report data.', 503);
            if ($unit !== strtolower(self::METRICS[$metric][1]) || !array_key_exists($date, $data)
                || !(is_int($count) || (is_string($count) && ctype_digit($count))) || (float) $count < 0
                || (float) $count > 999999999 || isset($data[$date][$metric])) {
                throw new RuntimeException('Invalid PDF report data.', 503);
            }
            $selected[$metric] = true;
            $data[$date][$metric] = (int) $count;
        }
        $metrics = array_intersect_key(self::METRICS, $selected);
        foreach ($data as $daily) {
            if (count($daily) !== count($metrics)) throw new RuntimeException('Incomplete PDF report data.', 503);
        }
        $groups = [];
        foreach ($metrics as $metric => $definition) $groups[$definition[1]][$metric] = $definition;
        if (isset($groups['BMG']) && count($groups['BMG']) !== 3) throw new RuntimeException('Incomplete BMG report data.', 503);
        if ($clinicInsights !== null) {
            if (!isset($groups['Clinic'])) throw new RuntimeException('Clinic insights require Clinic report scope.', 503);
            self::validateInsights($clinicInsights, array_sum(array_column($data, 'completed_clinic_encounters_started')));
        }
        return OperationalReportLatex::source($data, $groups, $start->format('d M Y') . ' - ' . $end->format('d M Y'), $clinicInsights);
    }

    private static function validateInsights(array $insights, int $total): void
    {
        $invalid = static function (): never { throw new RuntimeException('Invalid Clinic report aggregates.', 503); };
        foreach (['encounter_total', 'medicine_encounters', 'legacy_medicine_encounters'] as $key) {
            if (!isset($insights[$key]) || !is_int($insights[$key]) || $insights[$key] < 0 || $insights[$key] > $total) $invalid();
        }
        if ($insights['encounter_total'] !== $total || !in_array($insights['medicine_status'] ?? null, ['ready', 'unavailable'], true)) $invalid();
        foreach (['complaints' => 7, 'dispositions' => 7, 'medicines' => 5] as $key => $limit) {
            $rows = $insights[$key] ?? null;
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > $limit || ($key !== 'medicines' && $rows === [])) $invalid();
            $labels = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['label'] ?? null) || trim($row['label']) === ''
                    || !mb_check_encoding($row['label'], 'UTF-8') || mb_strlen($row['label'], 'UTF-8') > 96
                    || preg_match('/[\p{Cc}\p{Cf}]/u', $row['label']) || ($key !== 'medicines' && isset($labels[$row['label']]))
                    || !is_int($row['count'] ?? null) || $row['count'] < 0 || $row['count'] > ($key === 'medicines' ? $insights['medicine_encounters'] : $total)) $invalid();
                if ($key !== 'medicines') {
                    $vocabulary = $key === 'complaints' ? ClinicReportInsights::COMPLAINTS : ClinicReportInsights::DISPOSITIONS;
                    if (!in_array($row['label'], [...array_values($vocabulary), 'Other recorded', 'Not recorded'], true)) $invalid();
                }
                $labels[$row['label']] = true;
            }
            if ($key !== 'medicines' && (array_sum(array_column($rows, 'count')) !== $total || !isset($labels['Not recorded']))) $invalid();
        }
        if ($insights['medicine_status'] === 'unavailable' && ($insights['medicines'] !== [] || $insights['medicine_encounters'] !== 0 || $insights['legacy_medicine_encounters'] !== 0)) $invalid();
        if ($insights['medicine_status'] === 'ready' && (($insights['medicine_encounters'] === 0) !== ($insights['medicines'] === []))) $invalid();
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new RuntimeException('Invalid PDF date.', 503);
        return $date;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Reporting\ClinicReportInsights;

/** Fixed cohorts only. Catalog permissions supplement, never replace, AI/tenant authorization. */
final class AnalysisCatalog
{
    public static function definitions(): array
    {
        $rows = [
            ['clinic.activity', 'clinic', 'Clinic activity', 'Completed clinic encounters by calendar month.', 'clinic.encounters.read'],
            ['clinic.complaints', 'clinic', 'Recorded clinic complaints', 'Protected shares of fixed recorded complaint categories.', 'clinic.encounters.read'],
            ['guidance.activity', 'guidance', 'Guidance activity', 'Completed counselling sessions by calendar month.', 'guidance.cases.read_any'],
            ['guidance.themes', 'guidance', 'Recorded guidance themes', 'Protected shares of eligible recorded case categories.', 'guidance.cases.read_any'],
            ['bmg.batches', 'bmg', 'Batch activity', 'Separate monthly starts and terminal-event cohorts.', 'facilities.read'],
            ['bmg.rotations', 'bmg', 'Reported rotation events', 'Accepted rotation reports and reported failure or interruption shares.', 'facilities.read'],
        ];
        $definitions = [];
        foreach ($rows as [$code, $unit, $title, $description, $permission]) {
            $definitions[$code] = compact('code', 'unit', 'title', 'description', 'permission');
        }
        return $definitions;
    }

    public static function forUnit(string $unit): array
    {
        return array_values(array_filter(self::definitions(), static fn (array $row): bool => $row['unit'] === $unit));
    }

    public static function get(string $code): array
    {
        return self::definitions()[$code] ?? throw new AiFailure('invalid_request', 422);
    }

    /** Ordered descriptors: metric_code, label, unit_of_analysis, denominator_metric_code (or null). */
    public static function metrics(string $analysis): array
    {
        self::get($analysis);
        $rows = match ($analysis) {
            'clinic.activity', 'clinic.complaints' => [['completed_encounters', 'Completed encounters', 'encounter', null]],
            'guidance.activity', 'guidance.themes' => [['completed_sessions', 'Completed sessions', 'session', null]],
            'bmg.batches' => [
                ['batches_started', 'Batches started', 'batch', null],
                ['batches_released', 'Batches released', 'batch', null],
                ['batches_voided', 'Batches voided', 'batch', null],
            ],
            'bmg.rotations' => [
                ['rotation_reports', 'Reported rotation events', 'reported_event', null],
                ['rotation_failed', 'Reported failures', 'reported_event', 'rotation_reports'],
                ['rotation_interrupted', 'Reported interruptions', 'reported_event', 'rotation_reports'],
            ],
        };
        if ($analysis === 'clinic.complaints') {
            foreach (ClinicReportInsights::COMPLAINTS as $key => $label) {
                $rows[] = ['complaint_' . str_replace(' ', '_', $key), $label, 'encounter', 'completed_encounters'];
            }
        }
        if ($analysis === 'guidance.themes') {
            foreach (['academic', 'career', 'behavioral', 'family'] as $key) {
                $rows[] = ['category_' . $key, ucfirst($key), 'session', 'completed_sessions'];
            }
        }
        return array_map(static fn (array $row): array => array_combine(
            ['metric_code', 'label', 'unit_of_analysis', 'denominator_metric_code'], $row), $rows);
    }

    public static function metricLabel(string $analysis, string $metricCode): string
    {
        foreach (self::metrics($analysis) as $metric) {
            if ($metric['metric_code'] === $metricCode) {
                return $metric['label'];
            }
        }
        throw new AiFailure('invalid_request', 422);
    }

    public static function limitations(string $analysis): array
    {
        self::get($analysis);
        $specific = match ($analysis) {
            'clinic.activity', 'clinic.complaints' => [
                'Counts are completed clinic encounters started in each month, not unique people.',
                'Retained soft-deleted completed encounters and linked people are included.',
                'Complaint categories use fixed exact vocabulary matching after case and edge-space normalization only.',
            ],
            'guidance.activity', 'guidance.themes' => [
                'Counts are completed counselling sessions started in each month, not unique people or cases.',
                'Retained deleted sessions, cases, and linked people are included.',
                'Recorded case categories are not diagnoses, severity, outcomes, or a reliable measure of counselling need.',
                'Only eligible fixed categories may be released; excluded, unmapped, and missing categories remain internal.',
            ],
            'bmg.batches' => [
                'Starts use started_at; releases and voids use completed_at in separate monthly cohorts.',
                'Terminal events can relate to batches started in other months; these counts are not completion rates.',
                'Counts describe recorded operational events, not output quality, durations, or environmental outcomes.',
            ],
            'bmg.rotations' => [
                'Counts include all accepted rotation reports by occurred_at stored in Asia/Manila.',
                'Failure and interruption shares describe reports, not mechanical reliability, uptime, or unreported events.',
                'A share with a zero recorded denominator is undefined; its stable signal means no directional comparison is available.',
                'Rotation reports have no clinical linkage and do not measure compost quality or duration.',
            ],
        };
        $health = str_starts_with($analysis, 'clinic.') || str_starts_with($analysis, 'guidance.');
        return [...$specific, ...($health ? [
            'Released category shares use all completed source events as their denominator, including unreleased categories.',
            'Unreleased categories and missing or unmapped values must not be inferred or interpreted as absent.',
            'Service use is not campus population prevalence; repeated events are not independent people.',
        ] : [
            'A zero count means no recorded events in the selected cohort; historical coverage is unknown, not proof of absence.',
        ]),
            'Two monthly observations do not establish statistical significance, seasonality, or causation.',
            'Recording practices and historical coverage require staff review.',
            'Operational proposals require staff review; no clinical recommendations or automated actions.',
        ];
    }
}

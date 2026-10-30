<?php

declare(strict_types=1);

namespace App\Services\Ai;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure v2 release boundary; never persist internal aggregates.
 *
 * Internal monthly shapes (all numbers are nonnegative PHP integers):
 * - guidance.activity: {count, contributors, unlinked}.
 * - clinic.complaints / guidance.themes: the same plus buckets, keyed by EVERY
 *   category metric_code and __other, __missing; guidance also has __personal,
 *   __crisis. Every bucket is {count, contributors, complement_contributors}.
 *   Complements are distinct people in all OTHER buckets, computed in SQL, NOT
 *   total contributors minus bucket contributors (people can overlap buckets).
 * - BMG: {counts: {metric_code: count}} containing every catalog metric.
 *
 * Authorization and provenance are caller responsibilities. The store owner MUST
 * freeze all monthly metric values AND category denominators across overlapping
 * releases (including activity vs category analyses), atomically refusing changed
 * evidence. Monthly contributor coverage and suppression inputs must also remain
 * immutable: value-only freezes cannot detect changes in unreleased buckets or
 * contributor eligibility. The same threshold/catalog/suppression policy must be
 * used across releases; pre-change snapshots require publication-history review.
 * Shape validation alone cannot prove publication safety.
 */
final class BroadDisclosurePolicy
{
    public static function release(string $analysis, array $current, array $previous, string $month, string $previousMonth, int $threshold = 10): array
    {
        $definition = AnalysisCatalog::get($analysis);
        if ($analysis === 'clinic.activity') {
            throw new AiFailure('invalid_request', 422);
        }
        self::months($month, $previousMonth);
        if ($threshold < 1) {
            self::insufficient();
        }
        $descriptors = AnalysisCatalog::metrics($analysis);
        $health = in_array($definition['unit'], ['clinic', 'guidance'], true);
        $categorical = in_array($analysis, ['clinic.complaints', 'guidance.themes'], true);
        if ($health) {
            foreach ([$current, $previous] as $aggregate) {
                self::health($analysis, $aggregate, $threshold, $categorical);
            }
            $selected = [];
            if ($categorical) {
                // Fix each month's protective cells independently of its comparison partner.
                // Otherwise adjacent releases can swap camouflage cells and expose a hidden cell
                // through their union, even when every released value passes the store's freeze.
                $selected = array_values(array_intersect(
                    self::monthlyCategories($descriptors, $current['buckets'], $threshold),
                    self::monthlyCategories($descriptors, $previous['buckets'], $threshold)));
                // Remove the last catalog-ordered eligible cell until BOTH hidden pools are safe.
                while ($selected !== [] && (!self::safePool($current['buckets'], $selected, $threshold)
                    || !self::safePool($previous['buckets'], $selected, $threshold))) {
                    array_pop($selected);
                }
                if ($selected === []) {
                    self::insufficient();
                }
            }
            $descriptors = array_values(array_filter($descriptors, static fn (array $d): bool =>
                $d['denominator_metric_code'] === null || in_array($d['metric_code'], $selected, true)));
        } else {
            $codes = array_column($descriptors, 'metric_code');
            foreach ([$current, $previous] as $aggregate) {
                self::keys($aggregate, ['counts']);
                if (!is_array($aggregate['counts'])) {
                    self::invalid();
                }
                self::keys($aggregate['counts'], $codes);
                foreach ($aggregate['counts'] as $value) {
                    self::integer($value);
                }
                if ($analysis === 'bmg.rotations'
                    && ($aggregate['counts']['rotation_failed'] > $aggregate['counts']['rotation_reports']
                        || $aggregate['counts']['rotation_interrupted'] > $aggregate['counts']['rotation_reports'] - $aggregate['counts']['rotation_failed'])) {
                    self::insufficient();
                }
            }
            if (max($current['counts']) === 0 && max($previous['counts']) === 0) {
                self::insufficient();
            }
        }
        $metrics = [];
        foreach ($descriptors as $d) {
            $code = $d['metric_code'];
            $category = $d['denominator_metric_code'] !== null;
            $value = static fn (array $a): int => $health
                ? ($category ? $a['buckets'][$code]['count'] : $a['count']) : $a['counts'][$code];
            $denominator = static fn (array $a): ?int => !$category ? null
                : ($health ? $a['count'] : $a['counts'][$d['denominator_metric_code']]);
            $metrics[] = self::metric($d, 'm' . (count($metrics) + 1), $value($current), $value($previous),
                $denominator($current), $denominator($previous));
        }
        $payload = [
            'schema_version' => '2', 'analysis_code' => $analysis, 'department' => $definition['unit'],
            'reporting_timezone' => 'Asia/Manila',
            'period' => ['kind' => 'calendar_month', 'label' => $month],
            'comparison_period' => ['kind' => 'calendar_month', 'label' => $previousMonth],
            'metrics' => $metrics, 'signals' => array_map(self::signal(...), $metrics),
            'limitations' => AnalysisCatalog::limitations($analysis),
        ];
        self::validatePayload($payload);
        return $payload;
    }

    /** Strict released vocabulary/arithmetic, not proof of contributor coverage or publication history. */
    public static function validatePayload(array $payload): void
    {
        self::keys($payload, ['schema_version', 'analysis_code', 'department', 'reporting_timezone',
            'period', 'comparison_period', 'metrics', 'signals', 'limitations']);
        $analysis = $payload['analysis_code'];
        if (!is_string($analysis) || $analysis === 'clinic.activity'
            || !isset(AnalysisCatalog::definitions()[$analysis])) {
            self::invalid();
        }
        if ($payload['schema_version'] !== '2' || $payload['department'] !== AnalysisCatalog::get($analysis)['unit']
            || $payload['reporting_timezone'] !== 'Asia/Manila'
            || $payload['limitations'] !== AnalysisCatalog::limitations($analysis)) {
            self::invalid();
        }
        foreach (['period', 'comparison_period'] as $key) {
            if (!is_array($payload[$key])) {
                self::invalid();
            }
            self::keys($payload[$key], ['kind', 'label']);
            if ($payload[$key]['kind'] !== 'calendar_month' || !is_string($payload[$key]['label'])) {
                self::invalid();
            }
        }
        self::months($payload['period']['label'], $payload['comparison_period']['label']);
        if (!is_array($payload['metrics']) || !array_is_list($payload['metrics'])
            || count($payload['metrics']) < 1 || count($payload['metrics']) > 16
            || !is_array($payload['signals']) || !array_is_list($payload['signals'])
            || count($payload['signals']) !== count($payload['metrics'])) {
            self::invalid();
        }
        $descriptors = AnalysisCatalog::metrics($analysis);
        $positions = array_flip(array_column($descriptors, 'metric_code'));
        $last = -1;
        $byCode = [];
        foreach ($payload['metrics'] as $i => $metric) {
            if (!is_array($metric) || !is_string($metric['metric_code'] ?? null)) {
                self::invalid();
            }
            $position = $positions[$metric['metric_code']] ?? -1;
            if ($position <= $last) {
                self::invalid();
            }
            $last = $position;
            $d = $descriptors[$position];
            foreach (['current_value', 'previous_value'] as $key) {
                if (!is_int($metric[$key] ?? null) || $metric[$key] < 0) {
                    self::invalid();
                }
            }
            $cd = $pd = null;
            if ($d['denominator_metric_code'] !== null) {
                $total = $byCode[$d['denominator_metric_code']] ?? null;
                if ($total === null) {
                    self::invalid();
                }
                $cd = $total['current_value'];
                $pd = $total['previous_value'];
                if ($metric['current_value'] > $cd || $metric['previous_value'] > $pd) {
                    self::invalid();
                }
            }
            $expected = self::metric($d, 'm' . ($i + 1), $metric['current_value'], $metric['previous_value'], $cd, $pd);
            self::keys($metric, array_keys($expected));
            foreach ($expected as $key => $value) {
                if (is_float($value)) {
                    if ((!is_float($metric[$key]) && !is_int($metric[$key]))
                        || !is_finite((float) $metric[$key]) || (float) $metric[$key] !== $value) {
                        self::invalid();
                    }
                } elseif ($metric[$key] !== $value) {
                    self::invalid();
                }
            }
            $signal = $payload['signals'][$i];
            if (!is_array($signal)) {
                self::invalid();
            }
            $expectedSignal = self::signal($expected);
            self::keys($signal, array_keys($expectedSignal));
            foreach ($expectedSignal as $key => $value) {
                if ($signal[$key] !== $value) {
                    self::invalid();
                }
            }
            $byCode[$d['metric_code']] = $metric;
        }
        $codes = array_keys($byCode);
        $health = in_array($payload['department'], ['clinic', 'guidance'], true);
        if ($health) {
            // The configurable contributor threshold is private, but no health release can have
            // an empty month or a released category with an empty cell or empty complement.
            foreach ($payload['metrics'] as $metric) {
                foreach (['current', 'previous'] as $period) {
                    if ($metric[$period . '_value'] < 1 || ($metric[$period . '_denominator'] !== null
                        && $metric[$period . '_value'] >= $metric[$period . '_denominator'])) {
                        self::invalid();
                    }
                }
            }
        } elseif (max(array_column($payload['metrics'], 'current_value')) === 0
            && max(array_column($payload['metrics'], 'previous_value')) === 0) {
            self::invalid();
        }
        if (in_array($analysis, ['clinic.complaints', 'guidance.themes'], true)) {
            if (count($codes) < 2 || $codes[0] !== $descriptors[0]['metric_code']) {
                self::invalid();
            }
            foreach (['current_value', 'previous_value'] as $key) {
                $remaining = $payload['metrics'][0][$key];
                foreach (array_slice($payload['metrics'], 1) as $metric) {
                    if ($metric[$key] > $remaining) {
                        self::invalid();
                    }
                    $remaining -= $metric[$key];
                }
            }
        } elseif ($codes !== array_column($descriptors, 'metric_code')) {
            self::invalid();
        }
        if ($analysis === 'bmg.rotations') {
            foreach (['current_value', 'previous_value'] as $key) {
                if ($byCode['rotation_interrupted'][$key] > $byCode['rotation_reports'][$key] - $byCode['rotation_failed'][$key]) {
                    self::invalid();
                }
            }
        }
    }

    private static function health(string $analysis, array $a, int $threshold, bool $categorical): void
    {
        self::keys($a, $categorical ? ['count', 'contributors', 'unlinked', 'buckets'] : ['count', 'contributors', 'unlinked']);
        foreach (['count', 'contributors', 'unlinked'] as $key) {
            self::integer($a[$key]);
        }
        if ($a['contributors'] > $a['count'] || $a['unlinked'] !== 0 || $a['contributors'] < $threshold) {
            self::insufficient();
        }
        if (!$categorical) {
            return;
        }
        if (!is_array($a['buckets'])) {
            self::invalid();
        }
        $codes = array_column(array_slice(AnalysisCatalog::metrics($analysis), 1), 'metric_code');
        $codes = [...$codes, '__other', '__missing', ...($analysis === 'guidance.themes' ? ['__personal', '__crisis'] : [])];
        self::keys($a['buckets'], $codes);
        $remaining = $a['count'];
        foreach ($a['buckets'] as $bucket) {
            if (!is_array($bucket)) {
                self::invalid();
            }
            self::keys($bucket, ['count', 'contributors', 'complement_contributors']);
            foreach ($bucket as $value) {
                self::integer($value);
            }
            if ($bucket['count'] > $remaining || $bucket['contributors'] > min($bucket['count'], $a['contributors'])
                || ($bucket['count'] > 0 && $bucket['contributors'] === 0)
                || $bucket['complement_contributors'] > min($a['count'] - $bucket['count'], $a['contributors'])
                || $bucket['complement_contributors'] < $a['contributors'] - $bucket['contributors']) {
                self::insufficient();
            }
            $remaining -= $bucket['count'];
        }
        if ($remaining !== 0) {
            self::insufficient();
        }
    }

    private static function monthlyCategories(array $descriptors, array $buckets, int $threshold): array
    {
        $selected = [];
        foreach (array_slice($descriptors, 1) as $metric) {
            $code = $metric['metric_code'];
            if ($buckets[$code]['contributors'] >= $threshold
                && $buckets[$code]['complement_contributors'] >= $threshold) {
                $selected[] = $code;
            }
        }
        while ($selected !== [] && !self::safePool($buckets, $selected, $threshold)) {
            array_pop($selected);
        }
        return $selected;
    }

    private static function safePool(array $buckets, array $selected, int $threshold): bool
    {
        $positive = 0;
        $max = 0;
        foreach ($buckets as $code => $bucket) {
            if (!in_array($code, $selected, true) && $bucket['count'] > 0) {
                $positive++;
                $max = max($max, $bucket['contributors']);
            }
        }
        return $positive === 0 || ($positive >= 2 && $max >= $threshold);
    }

    private static function metric(array $d, string $id, int $current, int $previous, ?int $cd, ?int $pd): array
    {
        $change = $current - $previous;
        $cs = $cd === null || $cd === 0 ? null : round($current / $cd * 100, 2);
        $ps = $pd === null || $pd === 0 ? null : round($previous / $pd * 100, 2);
        return [
            'evidence_id' => $id, 'metric_code' => $d['metric_code'], 'unit_of_analysis' => $d['unit_of_analysis'],
            'current_value' => $current, 'previous_value' => $previous, 'absolute_change' => $change,
            'relative_change_percent' => $previous === 0 ? null : round($change / $previous * 100, 2),
            'current_denominator' => $cd, 'previous_denominator' => $pd,
            'current_share_percent' => $cs, 'previous_share_percent' => $ps,
            'share_change_points' => $cs === null || $ps === null ? null : round($cs - $ps, 2),
            'quality_status' => 'usable',
        ];
    }

    private static function signal(array $metric): array
    {
        $category = $metric['current_denominator'] !== null;
        // A zero denominator yields an undefined share, never evidence of an increase/decrease.
        $change = $category ? $metric['share_change_points'] : $metric['absolute_change'];
        $direction = $change === null || $change == 0 ? 'stable' : ($change > 0 ? 'increased' : 'decreased');
        return ['signal_id' => ($category ? 'share_' : 'activity_') . $direction,
            'evidence_id' => $metric['evidence_id'], 'metric_code' => $metric['metric_code']];
    }

    private static function keys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            self::invalid();
        }
    }

    private static function integer(mixed $value): void
    {
        if (!is_int($value) || $value < 0) {
            self::insufficient();
        }
    }

    private static function months(string $month, string $previous): void
    {
        foreach ([$month, $previous] as $label) {
            if (!preg_match('/\A[1-9][0-9]{3}-(?:0[1-9]|1[0-2])\z/', $label)) {
                self::invalid();
            }
        }
        $start = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone('Asia/Manila'));
        if ($start->modify('-1 month')->format('Y-m') !== $previous) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new AiFailure('invalid_snapshot', 422);
    }

    private static function insufficient(): never
    {
        throw new AiFailure('insufficient_data', 422);
    }
}

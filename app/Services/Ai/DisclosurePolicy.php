<?php

declare(strict_types=1);

namespace App\Services\Ai;

use DateTimeImmutable;
use DateTimeZone;

final class DisclosurePolicy
{
    private const LIMITATIONS = [
        'Counts are completed clinic encounters started in each month, not unique people.',
        'Counts follow operational export rules, including soft-deleted completed encounters.',
        'Service use is not campus population prevalence.',
        'Two monthly counts do not establish statistical significance, seasonality, causation, or clinical need.',
        'Operating days, attendance, recording practices, and historical coverage require staff review.',
        'Complaint trends, guidance, and BMG analytics are unsupported pending separate validation.',
        'Operational proposals require staff review; no clinical recommendations or automated actions.',
    ];

    /** Internal aggregates never cross this boundary, including when either month is suppressed. */
    public static function release(array $current, array $previous, string $month, string $prevMonth, int $threshold = 10): array
    {
        self::validateMonths($month, $prevMonth);
        if ($threshold < 1) {
            throw new AiFailure('insufficient_data', 422);
        }
        foreach ([$current, $previous] as $aggregate) {
            self::requireKeys($aggregate, ['count', 'contributors', 'unlinked']);
            foreach ($aggregate as $value) {
                if (!is_int($value) || $value < 0) {
                    throw new AiFailure('insufficient_data', 422);
                }
            }
            if ($aggregate['contributors'] > $aggregate['count'] || $aggregate['unlinked'] !== 0
                || $aggregate['contributors'] < $threshold) {
                throw new AiFailure('insufficient_data', 422);
            }
        }

        $change = $current['count'] - $previous['count'];
        $metric = [
            'evidence_id' => 'm1',
            'metric_code' => 'completed_encounters',
            'unit_of_analysis' => 'encounter',
            'current_value' => $current['count'],
            'previous_value' => $previous['count'],
            'absolute_change' => $change,
            'relative_change_percent' => $previous['count'] === 0 ? null : round($change / $previous['count'] * 100, 2),
            'quality_status' => 'usable',
        ];
        $payload = [
            'schema_version' => '1',
            'department' => 'clinic',
            'reporting_timezone' => 'Asia/Manila',
            'period' => ['kind' => 'calendar_month', 'label' => $month],
            'comparison_period' => ['kind' => 'calendar_month', 'label' => $prevMonth],
            'metrics' => [$metric],
            'signals' => SignalDetector::detect($metric),
            'limitations' => self::LIMITATIONS,
        ];
        self::validatePayload($payload);
        return $payload;
    }

    /** Shape/arithmetic validation is not proof of contributor coverage or authorization. */
    public static function validatePayload(array $payload): void
    {
        if (($payload['schema_version'] ?? null) === '2') {
            BroadDisclosurePolicy::validatePayload($payload);
            return;
        }
        self::requireKeys($payload, ['schema_version', 'department', 'reporting_timezone', 'period',
            'comparison_period', 'metrics', 'signals', 'limitations']);
        if ($payload['schema_version'] !== '1' || $payload['department'] !== 'clinic'
            || $payload['reporting_timezone'] !== 'Asia/Manila') {
            throw new AiFailure('invalid_snapshot', 422);
        }
        foreach (['period', 'comparison_period'] as $key) {
            if (!is_array($payload[$key])) {
                throw new AiFailure('invalid_snapshot', 422);
            }
            self::requireKeys($payload[$key], ['kind', 'label']);
            if ($payload[$key]['kind'] !== 'calendar_month' || !is_string($payload[$key]['label'])) {
                throw new AiFailure('invalid_snapshot', 422);
            }
        }
        self::validateMonths($payload['period']['label'], $payload['comparison_period']['label']);
        if (!is_array($payload['metrics']) || !array_is_list($payload['metrics'])
            || count($payload['metrics']) !== 1 || !is_array($payload['metrics'][0])) {
            throw new AiFailure('invalid_snapshot', 422);
        }
        $expectedSignals = SignalDetector::detect($payload['metrics'][0]);
        if (!is_array($payload['signals']) || !array_is_list($payload['signals'])
            || count($payload['signals']) !== 1 || !is_array($payload['signals'][0])) {
            throw new AiFailure('invalid_snapshot', 422);
        }
        $signal = $payload['signals'][0];
        self::requireKeys($signal, array_keys($expectedSignals[0]));
        foreach ($expectedSignals[0] as $key => $expected) {
            $value = $signal[$key];
            if ($key === 'relative_change_percent' && $expected !== null) {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || (float) $value !== $expected) {
                    throw new AiFailure('invalid_snapshot', 422);
                }
            } elseif ($value !== $expected) {
                throw new AiFailure('invalid_snapshot', 422);
            }
        }
        if ($payload['limitations'] !== self::LIMITATIONS) {
            throw new AiFailure('invalid_snapshot', 422);
        }
    }

    private static function requireKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new AiFailure('invalid_snapshot', 422);
        }
    }

    private static function validateMonths(string $month, string $prevMonth): void
    {
        foreach ([$month, $prevMonth] as $label) {
            if (!preg_match('/\A[1-9][0-9]{3}-(?:0[1-9]|1[0-2])\z/', $label)) {
                throw new AiFailure('invalid_snapshot', 422);
            }
        }
        $start = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone('Asia/Manila'));
        if ($start->modify('-1 month')->format('Y-m') !== $prevMonth) {
            throw new AiFailure('invalid_snapshot', 422);
        }
    }
}

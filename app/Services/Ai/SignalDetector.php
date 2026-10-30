<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** Descriptive two-period arithmetic only; no significance, prediction or clinical rule. */
final class SignalDetector
{
    public static function detect(array $metric): array
    {
        $keys = ['evidence_id', 'metric_code', 'unit_of_analysis', 'current_value', 'previous_value',
            'absolute_change', 'relative_change_percent', 'quality_status'];
        $actual = array_keys($metric);
        sort($keys);
        sort($actual);
        if ($actual !== $keys
            || $metric['evidence_id'] !== 'm1'
            || $metric['metric_code'] !== 'completed_encounters'
            || $metric['unit_of_analysis'] !== 'encounter'
            || $metric['quality_status'] !== 'usable'
            || !is_int($metric['current_value']) || $metric['current_value'] < 0
            || !is_int($metric['previous_value']) || $metric['previous_value'] < 0) {
            throw new AiFailure('invalid_snapshot', 422);
        }

        $change = $metric['current_value'] - $metric['previous_value'];
        $percent = $metric['previous_value'] === 0
            ? null : round($change / $metric['previous_value'] * 100, 2);
        $suppliedPercent = $metric['relative_change_percent'];
        // JSON has one number type: a whole-valued percentage may decode as an integer.
        $percentMatches = $percent === null ? $suppliedPercent === null
            : ((is_int($suppliedPercent) || is_float($suppliedPercent))
                && is_finite((float) $suppliedPercent) && (float) $suppliedPercent === $percent);
        if (!is_int($metric['absolute_change']) || $metric['absolute_change'] !== $change || !$percentMatches) {
            throw new AiFailure('invalid_snapshot', 422);
        }

        return [[
            'signal_id' => $change > 0 ? 'activity_increased' : ($change < 0 ? 'activity_decreased' : 'activity_stable'),
            'evidence_id' => 'm1',
            'unit_of_analysis' => 'encounter',
            'absolute_change' => $change,
            'relative_change_percent' => $percent,
        ]];
    }
}

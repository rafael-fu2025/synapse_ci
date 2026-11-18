<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;
use Throwable;

/** Unit-specific count reads on the caller's connection; no transaction or persistent state. */
final class HybridOperationalAggregateReader
{
    private const FAILURE = 'Operational report aggregation is unavailable.';
    private const METRICS = [
        'clinic' => ['completed_clinic_encounters_started'],
        'guidance' => ['completed_guidance_sessions_started'],
        'bmg' => ['bmg_batches_started', 'bmg_batches_released', 'bmg_batches_voided'],
    ];

    public function aggregate(BaseConnection $db, int $tenantId, ExportRange $range, string $storageTimezone, array $units): array
    {
        try {
            $rows = [['date_manila', 'unit', 'metric', 'count']];
            $selected = array_filter(self::METRICS, static fn (string $unit): bool => in_array($unit, $units, true), ARRAY_FILTER_USE_KEY);
            if ($selected === []) return $rows;

            $days = $this->dayBounds($range, $storageTimezone);
            $counts = array_fill_keys(array_keys($days), []);
            if (isset($selected['clinic'])) {
                foreach ($days as $date => [$from, $until]) {
                    $counts[$date]['clinic']['completed_clinic_encounters_started'] = $this->clinicCount($db, $tenantId, $from, $until);
                }
            }
            [$from, $until] = $range->bounds($storageTimezone);
            if (isset($selected['guidance'])) {
                [$sql, $bindings] = $this->guidanceQuery($db, $tenantId, $days, $from, $until);
                $this->mergeGroups($this->readRows($db, $sql, $bindings), $counts, 'guidance');
            }
            if (isset($selected['bmg'])) {
                [$sql, $bindings] = $this->bmgQuery($db, $tenantId, $days, $from, $until);
                $this->mergeGroups($this->readRows($db, $sql, $bindings), $counts, 'bmg');
            }
            foreach ($days as $date => $bounds) {
                foreach ($selected as $unit => $metrics) {
                    foreach ($metrics as $metric) $rows[] = [$date, $unit, $metric, $counts[$date][$unit][$metric] ?? 0];
                }
            }
            return $rows;
        } catch (Throwable) {
            // Do not chain driver errors, query text, bindings, or partially assembled report data.
            throw new RuntimeException(self::FAILURE, 503);
        }
    }

    /** Each Manila day retains its own half-open bounds, including custom storage timezones. */
    private function dayBounds(ExportRange $range, string $timezone): array
    {
        $days = [];
        for ($day = $range->start; $day <= $range->end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $days[$date] = ExportRange::fromInput(['from' => $date, 'to' => $date])->bounds($timezone);
        }
        return $days;
    }

    private function clinicCount(BaseConnection $db, int $tenantId, string $from, string $until): int
    {
        $table = $db->escapeIdentifiers($db->prefixTable('clinic_encounters'));
        $tenant = $db->escapeIdentifiers('tenant_id');
        $status = $db->escapeIdentifiers('status');
        $started = $db->escapeIdentifiers('started_at');
        $count = $db->escapeIdentifiers('aggregate_count');
        // Preserve the bare timestamp range COUNT; no grouping, joins, date functions, or new deletion filter.
        $rows = $this->readRows($db, "SELECT COUNT(*) AS {$count} FROM {$table}"
            . " WHERE {$tenant} = ? AND {$status} = ? AND {$started} >= ? AND {$started} < ?",
            [$tenantId, 'completed', $from, $until]);
        if (count($rows) !== 1 || !is_array($rows[0]) || count($rows[0]) !== 1 || !array_key_exists('aggregate_count', $rows[0])) {
            throw new RuntimeException(self::FAILURE, 503);
        }
        return $this->countValue($rows[0]['aggregate_count']);
    }

    private function guidanceQuery(BaseConnection $db, int $tenantId, array $days, string $from, string $until): array
    {
        $sessions = $db->escapeIdentifiers($db->prefixTable('counselling_sessions'));
        $cases = $db->escapeIdentifiers($db->prefixTable('counselling_cases'));
        $s = $db->escapeIdentifiers('s');
        $c = $db->escapeIdentifiers('c');
        $caseId = $db->escapeIdentifiers('c.id');
        $sessionCase = $db->escapeIdentifiers('s.case_id');
        $caseTenant = $db->escapeIdentifiers('c.tenant_id');
        $sessionTenant = $db->escapeIdentifiers('s.tenant_id');
        $status = $db->escapeIdentifiers('s.status');
        $started = $db->escapeIdentifiers('s.started_at');
        return $this->groupedQuery($db, $days, $started, 'guidance', 'completed_guidance_sessions_started',
            "{$sessions} {$s} INNER JOIN {$cases} {$c} ON {$caseId} = {$sessionCase} AND {$caseTenant} = {$sessionTenant}",
            "{$sessionTenant} = ? AND {$status} = ? AND {$started} >= ? AND {$started} < ?",
            [$tenantId, 'completed', $from, $until]);
    }

    private function bmgQuery(BaseConnection $db, int $tenantId, array $days, string $from, string $until): array
    {
        $table = $db->escapeIdentifiers($db->prefixTable('bmg_batches'));
        $tenant = $db->escapeIdentifiers('tenant_id');
        $status = $db->escapeIdentifiers('status');
        $queries = $bindings = [];
        // Starts and terminal transitions are independently scoped; their dates need not coincide.
        foreach (['started' => 'started_at', 'released' => 'completed_at', 'voided' => 'completed_at'] as $metric => $timestamp) {
            $column = $db->escapeIdentifiers($timestamp);
            $where = "{$tenant} = ?";
            $scope = [$tenantId];
            if ($metric !== 'started') {
                $where .= " AND {$status} = ?";
                $scope[] = $metric;
            }
            $where .= " AND {$column} >= ? AND {$column} < ?";
            array_push($scope, $from, $until);
            [$sql, $values] = $this->groupedQuery($db, $days, $column, 'bmg', 'bmg_batches_' . $metric, $table, $where, $scope);
            $queries[] = $sql;
            array_push($bindings, ...$values);
        }
        return [implode(' UNION ALL ', $queries), $bindings];
    }

    /** Fixed, driver-quoted SQL fragments; all date, label, metric, status and tenant values are bound. */
    private function groupedQuery(BaseConnection $db, array $days, string $column, string $unit, string $metric,
        string $source, string $where, array $scope): array
    {
        $case = 'CASE';
        $bindings = [];
        foreach ($days as $date => [$from, $until]) {
            $case .= " WHEN {$column} >= ? AND {$column} < ? THEN ?";
            array_push($bindings, $from, $until, $date);
        }
        $case .= ' ELSE NULL END';
        $dateAlias = $db->escapeIdentifiers('date_manila');
        $unitAlias = $db->escapeIdentifiers('unit');
        $metricAlias = $db->escapeIdentifiers('metric');
        $countAlias = $db->escapeIdentifiers('aggregate_count');
        $sql = "SELECT {$case} AS {$dateAlias}, ? AS {$unitAlias}, ? AS {$metricAlias}, COUNT(*) AS {$countAlias}"
            . " FROM {$source} WHERE {$where} GROUP BY {$dateAlias}";
        array_push($bindings, $unit, $metric, ...$scope);
        return [$sql, $bindings];
    }

    private function readRows(BaseConnection $db, string $sql, array $bindings): array
    {
        $result = $db->query($sql, $bindings);
        if (!$result instanceof ResultInterface) throw new RuntimeException(self::FAILURE, 503);
        try {
            // BaseResult otherwise treats a missing native result as an empty grouped result.
            if ($result instanceof BaseResult && !is_resource($result->resultID) && !is_object($result->resultID)) {
                throw new RuntimeException(self::FAILURE, 503);
            }
            return $result->getResultArray();
        } finally {
            $result->freeResult();
        }
    }

    private function mergeGroups(array $rows, array &$counts, string $unit): void
    {
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== 4) throw new RuntimeException(self::FAILURE, 503);
            $date = $row['date_manila'] ?? null;
            $metric = $row['metric'] ?? null;
            if (!is_string($date) || !array_key_exists($date, $counts) || ($row['unit'] ?? null) !== $unit
                || !is_string($metric) || !in_array($metric, self::METRICS[$unit], true) || isset($counts[$date][$unit][$metric])) {
                throw new RuntimeException(self::FAILURE, 503);
            }
            $counts[$date][$unit][$metric] = $this->countValue($row['aggregate_count'] ?? null);
        }
    }

    private function countValue(mixed $value): int
    {
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1) {
            $maximum = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0)) {
                return (int) $value;
            }
        }
        throw new RuntimeException(self::FAILURE, 503);
    }
}

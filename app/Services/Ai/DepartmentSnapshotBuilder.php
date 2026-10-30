<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Reporting\ClinicReportInsights;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Read-only aggregate collector. Caller authorizes tenant, analysis and source permissions. */
final class DepartmentSnapshotBuilder
{
    public function __construct(
        private ?BaseConnection $db = null,
        private ?DateTimeImmutable $now = null,
        private ?int $threshold = null,
        private ?string $storageTimezone = null,
    ) {
    }

    /** Only new v2 analyses; no arbitrary dates, filters, cohorts or source writes. One SQL statement per build. */
    public function build(int $tenant, string $analysis): array
    {
        $definition = AnalysisCatalog::get($analysis);
        if ($analysis === 'clinic.activity') {
            throw new AiFailure('invalid_request', 422);
        }
        if ($tenant < 1 || ($this->threshold !== null && $this->threshold < 1)) {
            throw new AiFailure('insufficient_data', 422);
        }
        try {
            $manila = new DateTimeZone('Asia/Manila');
            // Rotation occurred_at explicitly stores Manila wall time, independent of App storage time.
            $storage = $analysis === 'bmg.rotations' ? $manila
                : new DateTimeZone($this->storageTimezone ?? config('App')->appTimezone);
            $threshold = $this->threshold ?? config('Ai')->minimumContributors;
            if (!is_int($threshold) || $threshold < 1) {
                throw new AiFailure('insufficient_data', 422);
            }
            $until = ($this->now ?? new DateTimeImmutable('now', $manila))->setTimezone($manila)
                ->modify('first day of this month')->setTime(0, 0);
            $current = $until->modify('-1 month');
            $previous = $current->modify('-1 month');
            $db = $this->db ?? Database::connect();
            $bounds = array_map(static fn (DateTimeImmutable $d): string =>
                $d->setTimezone($storage)->format('Y-m-d H:i:s'), [$previous, $current, $until]);
            $aggregates = in_array($definition['unit'], ['clinic', 'guidance'], true)
                ? $this->health($db, $tenant, $analysis, ...$bounds)
                : $this->operations($db, $tenant, $analysis, ...$bounds);
            return BroadDisclosurePolicy::release($analysis, $aggregates['current'], $aggregates['previous'],
                $current->format('Y-m'), $previous->format('Y-m'), $threshold);
        } catch (AiFailure $e) {
            throw $e;
        } catch (Throwable) {
            // No exception chaining: SQL, raw values and deployment details stay internal.
            throw new AiFailure('analytics_unavailable', 503);
        }
    }

    private function health(BaseConnection $db, int $tenant, string $analysis, string $from, string $split, string $until): array
    {
        $clinic = $analysis === 'clinic.complaints';
        $categorical = $analysis !== 'guidance.activity';
        $query = $clinic ? $db->table('clinic_encounters e')
            ->join('people p', 'p.id = e.person_id AND p.tenant_id = e.tenant_id', 'left')
            : $db->table('counselling_sessions e')
                ->join('counselling_cases c', 'c.id = e.case_id AND c.tenant_id = e.tenant_id', 'left')
                ->join('people p', 'p.id = c.person_id AND p.tenant_id = c.tenant_id', 'left');
        // No deleted_at predicate on any source: retained completed records and people count.
        $query->where('e.tenant_id', $tenant)->where('e.status', 'completed')
            ->where('e.started_at >=', $from)->where('e.started_at <', $until);
        $buckets = [];
        $bucketExpression = '';
        if ($categorical) {
            [$bucketExpression, $buckets] = $this->buckets($db, $analysis);
        }
        $select = [];
        foreach (['current' => '>=', 'previous' => '<'] as $period => $operator) {
            $condition = 'e.started_at ' . $operator . ' ' . $db->escape($split);
            $select[] = self::count($condition, $period . '_count');
            $select[] = self::count($condition, $period . '_contributors', 'p.id', true);
            $select[] = self::count($condition . ' AND p.id IS NULL', $period . '_unlinked');
            foreach ($buckets as $i => $code) {
                $matched = '(' . $bucketExpression . ') = ' . $db->escape($code);
                $notMatched = '(' . $bucketExpression . ') <> ' . $db->escape($code);
                $prefix = $period . '_b' . $i;
                $select[] = self::count($condition . ' AND ' . $matched, $prefix . '_count');
                $select[] = self::count($condition . ' AND ' . $matched, $prefix . '_contributors', 'p.id', true);
                $select[] = self::count($condition . ' AND ' . $notMatched, $prefix . '_complement', 'p.id', true);
            }
        }
        // Only fixed aliases and nonnegative aggregate integers cross SQL, including invalid source values.
        $row = $this->row($query, $select);
        $result = [];
        foreach (['current', 'previous'] as $period) {
            foreach (['count', 'contributors', 'unlinked'] as $key) {
                $result[$period][$key] = $row[$period . '_' . $key];
            }
            if ($categorical) {
                foreach ($buckets as $i => $code) {
                    $prefix = $period . '_b' . $i;
                    $result[$period]['buckets'][$code] = [
                        'count' => $row[$prefix . '_count'], 'contributors' => $row[$prefix . '_contributors'],
                        'complement_contributors' => $row[$prefix . '_complement'],
                    ];
                }
            }
        }
        return $result;
    }

    /** CASE emits constants only: raw category text never reaches PHP or a SQL result row. */
    private function buckets(BaseConnection $db, string $analysis): array
    {
        $cases = [];
        $codes = [];
        if ($analysis === 'clinic.complaints') {
            $expression = 'LOWER(TRIM(e.chief_complaint))';
            foreach (ClinicReportInsights::COMPLAINTS as $key => $_label) {
                $code = 'complaint_' . str_replace(' ', '_', $key);
                $cases[] = 'WHEN ' . $db->escape($key) . ' THEN ' . $db->escape($code);
                $codes[] = $code;
            }
            $source = 'e.chief_complaint';
        } else {
            $expression = 'c.category';
            foreach (['academic', 'career', 'behavioral', 'family', 'personal', 'crisis'] as $key) {
                $code = in_array($key, ['personal', 'crisis'], true) ? '__' . $key : 'category_' . $key;
                $cases[] = 'WHEN ' . $db->escape($key) . ' THEN ' . $db->escape($code);
                $codes[] = $code;
            }
            $source = 'c.category';
        }
        // Source collations must not fold accents or accept additional category spellings.
        // Only clinic's explicit LOWER/TRIM normalization above is approved.
        $expression = match ($db->DBDriver) {
            'MySQLi' => 'CAST(' . $expression . ' AS BINARY)',
            'SQLite3' => '(' . $expression . ' COLLATE BINARY)',
            default => $expression,
        };
        $case = 'CASE WHEN ' . $source . ' IS NULL OR TRIM(' . $source . ") = '' THEN '__missing' ELSE CASE "
            . $expression . ' ' . implode(' ', $cases) . " ELSE '__other' END END";
        return [$case, [...$codes, '__other', '__missing']];
    }

    private function operations(BaseConnection $db, int $tenant, string $analysis, string $from, string $split, string $until): array
    {
        $batches = $analysis === 'bmg.batches';
        $query = $db->table($batches ? 'bmg_batches e' : 'bmg_rotation_runs e')->where('e.tenant_id', $tenant);
        $range = static fn (string $column, string $start, string $end): string =>
            $column . ' >= ' . $db->escape($start) . ' AND ' . $column . ' < ' . $db->escape($end);
        $full = $batches ? '(' . $range('e.started_at', $from, $until) . ') OR (' . $range('e.completed_at', $from, $until) . ')'
            : $range('e.occurred_at', $from, $until);
        $query->where('(' . $full . ')', null, false);
        $valid = $batches ? "e.status IN ('idle', 'processing', 'awaiting_output', 'curing', 'released', 'voided')"
            : "e.motor_state IN ('turned_normal', 'stopped', 'failed', 'interrupted')";
        $select = [self::count('CASE WHEN ' . $valid . ' THEN 0 ELSE 1 END = 1', 'invalid_count')];
        $descriptors = AnalysisCatalog::metrics($analysis);
        foreach (['current' => [$split, $until], 'previous' => [$from, $split]] as $period => [$start, $end]) {
            foreach ($descriptors as $i => $d) {
                $condition = match ($d['metric_code']) {
                    'batches_started' => '(' . $range('e.started_at', $start, $end) . ') AND ' . $valid,
                    'batches_released' => '(' . $range('e.completed_at', $start, $end) . ") AND e.status = 'released'",
                    'batches_voided' => '(' . $range('e.completed_at', $start, $end) . ") AND e.status = 'voided'",
                    'rotation_reports' => '(' . $range('e.occurred_at', $start, $end) . ') AND ' . $valid,
                    'rotation_failed' => '(' . $range('e.occurred_at', $start, $end) . ") AND e.motor_state = 'failed'",
                    'rotation_interrupted' => '(' . $range('e.occurred_at', $start, $end) . ") AND e.motor_state = 'interrupted'",
                };
                $select[] = self::count($condition, $period . '_m' . $i);
            }
        }
        $row = $this->row($query, $select);
        if ($row['invalid_count'] !== 0) {
            throw new AiFailure('insufficient_data', 422);
        }
        $result = [];
        foreach (['current', 'previous'] as $period) {
            foreach ($descriptors as $i => $d) {
                $result[$period]['counts'][$d['metric_code']] = $row[$period . '_m' . $i];
            }
        }
        return $result;
    }

    private static function count(string $condition, string $alias, string $value = '1', bool $distinct = false): string
    {
        return 'COUNT(' . ($distinct ? 'DISTINCT ' : '') . 'CASE WHEN ' . $condition . ' THEN ' . $value . ' END) AS ' . $alias;
    }

    private function row(BaseBuilder $query, array $select): array
    {
        $row = $query->select(implode(', ', $select), false)->get()->getRowArray();
        if (!is_array($row) || count($row) !== count($select)) {
            throw new AiFailure('analytics_unavailable', 503);
        }
        foreach ($row as &$value) {
            $value = self::aggregateInteger($value);
        }
        unset($value);
        return $row;
    }

    /** COUNT can return decimal strings; never accept lossy casts or overflowing values. */
    private static function aggregateInteger(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)) {
            $max = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) <= 0)) {
                return (int) $value;
            }
        }
        throw new AiFailure('analytics_unavailable', 503);
    }
}

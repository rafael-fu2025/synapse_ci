<?php

declare(strict_types=1);

namespace App\Services\Ai;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Read-only clinic operations collector. Callers must authorize the tenant before building. */
final class ClinicSnapshotBuilder
{
    public function __construct(
        private ?BaseConnection $db = null,
        private ?DateTimeImmutable $now = null,
        private ?int $threshold = null,
        private ?string $storageTimezone = null,
    ) {
    }

    /** No selectable month, date range, cohort or dimension: only the last two complete Manila months. */
    public function build(int $tenantId): array
    {
        if ($tenantId < 1 || ($this->threshold !== null && $this->threshold < 1)) {
            throw new AiFailure('insufficient_data', 422);
        }
        try {
            $manila = new DateTimeZone('Asia/Manila');
            $storage = new DateTimeZone($this->storageTimezone ?? config('App')->appTimezone);
            $threshold = $this->threshold ?? config('Ai')->minimumContributors;
            if (!is_int($threshold) || $threshold < 1) {
                throw new AiFailure('insufficient_data', 422);
            }
            $until = ($this->now ?? new DateTimeImmutable('now', $manila))->setTimezone($manila)
                ->modify('first day of this month')->setTime(0, 0);
            $current = $until->modify('-1 month');
            $previous = $current->modify('-1 month');
            $db = $this->db ?? Database::connect();
            $aggregates = $this->aggregate($db, $tenantId,
                $previous->setTimezone($storage)->format('Y-m-d H:i:s'),
                $current->setTimezone($storage)->format('Y-m-d H:i:s'),
                $until->setTimezone($storage)->format('Y-m-d H:i:s'));
            return DisclosurePolicy::release($aggregates['current'], $aggregates['previous'],
                $current->format('Y-m'), $previous->format('Y-m'), $threshold);
        } catch (AiFailure $e) {
            throw $e;
        } catch (Throwable) {
            // Do not chain source exceptions: SQL, source values and deployment details stay internal.
            throw new AiFailure('analytics_unavailable', 503);
        }
    }

    private function aggregate(BaseConnection $db, int $tenantId, string $from, string $boundary, string $until): array
    {
        $split = $db->escape($boundary);
        $select = [];
        foreach (['current' => '>=', 'previous' => '<'] as $period => $operator) {
            $condition = 'e.started_at ' . $operator . ' ' . $split;
            $select[] = 'COUNT(CASE WHEN ' . $condition . ' THEN 1 END) AS ' . $period . '_count';
            // person_id is the contributor source (clinic schema + ClinicEncounterModel).
            // The tenant-safe link also detects NULL, orphaned and cross-tenant source IDs.
            $select[] = 'COUNT(DISTINCT CASE WHEN ' . $condition . ' THEN p.id END) AS ' . $period . '_contributors';
            $select[] = 'COUNT(CASE WHEN ' . $condition . ' AND p.id IS NULL THEN 1 END) AS ' . $period . '_unlinked';
        }
        // One statement sees both periods together. No person rows or free-text columns leave SQL.
        // Like OperationalExportService, include soft-deleted completed encounters and linked people.
        $row = $db->table('clinic_encounters e')->select(implode(', ', $select), false)
            ->join('people p', 'p.id = e.person_id AND p.tenant_id = e.tenant_id', 'left')
            ->where('e.tenant_id', $tenantId)->where('e.status', 'completed')
            ->where('e.started_at >=', $from)->where('e.started_at <', $until)
            ->get()->getRowArray();
        if (!is_array($row) || count($row) !== 6) {
            throw new AiFailure('analytics_unavailable', 503);
        }
        $aggregates = [];
        foreach (['current', 'previous'] as $period) {
            foreach (['count', 'contributors', 'unlinked'] as $key) {
                $aggregates[$period][$key] = self::aggregateInteger($row[$period . '_' . $key] ?? null);
            }
        }
        return $aggregates;
    }

    /** MySQL COUNT returns decimal strings by default; reject lossy casts and overflow. */
    private static function aggregateInteger(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)) {
            $maximum = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($maximum)
                || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0)) {
                return (int) $value;
            }
        }
        throw new AiFailure('analytics_unavailable', 503);
    }
}

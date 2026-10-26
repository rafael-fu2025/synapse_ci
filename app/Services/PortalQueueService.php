<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Scheduling\SchedulingClock;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use RuntimeException;

/** The caller's own active tickets; no request-supplied person or tenant selector. */
final class PortalQueueService
{
    public function __construct(private ?BaseConnection $db = null, private ?Closure $identity = null, private ?Closure $tenant = null)
    {
    }

    public function snapshot(): array
    {
        $actor = $this->identity !== null ? ($this->identity)() : (new AuthenticatedUser())->current();
        if ($actor === null) throw new RuntimeException('Please sign in to continue.', 401);
        $tenant = $this->tenant !== null ? ($this->tenant)() : CurrentTenant::id();
        $person = (int) ($actor['person_id'] ?? 0);
        if ($tenant < 1 || ($actor['tenant_id'] ?? null) !== $tenant || $person < 1
            || ($actor['logged_in'] ?? false) !== true
            || !PortalContext::isRolePermitted($actor['role'] ?? '', PortalContext::PORTAL_MAIN)
            || !in_array('portal.appointments.read', $actor['permissions'] ?? [], true)
            || !in_array('portal.records.read', $actor['permissions'] ?? [], true)) {
            throw new RuntimeException('Personal queue access forbidden.', 403);
        }
        $date = SchedulingClock::today();
        $db = $this->db ?? Database::connect();
        $rows = $db->table('queue_entries')
            ->select('id,ticket_no,unit,ticket_code,status,priority')->where('tenant_id', $tenant)->where('person_id', $person)
            ->where('queue_date', $date)->whereIn('unit', ['clinic', 'guidance'])
            ->whereIn('status', ['waiting', 'called', 'in_consultation', 'skipped'])
            ->orderBy('id', 'DESC')->limit(2)->get()->getResultArray();
        $tickets = [];
        $medians = [];
        $now = SchedulingClock::now();
        foreach ($rows as $row) {
            // Internal ordering keys never leave the personal projection.
            $ticket = array_intersect_key($row, array_flip(['unit', 'ticket_code', 'status', 'priority']));
            $ticket += ['position' => null, 'people_ahead' => null, 'estimated_wait_minutes' => null];
            if (in_array($row['status'], ['called', 'in_consultation'], true)) {
                $ticket['people_ahead'] = 0;
                $ticket['estimated_wait_minutes'] = 0;
            } elseif ($row['status'] === 'waiting') {
                $table = $db->prefixTable('queue_entries');
                // ELSE 0 also mirrors MySQL FIELD() for an unrecognized priority.
                $rank = "CASE priority WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 WHEN 'routine' THEN 3 ELSE 0 END";
                $ownRank = match ($row['priority']) { 'emergency' => 1, 'urgent' => 2, 'routine' => 3, default => 0 };
                $counts = $db->query("SELECT
                    COUNT(CASE WHEN status IN ('called', 'in_consultation') OR
                        (status = 'waiting' AND ({$rank} < ? OR ({$rank} = ? AND
                            (ticket_no < ? OR (ticket_no = ? AND id < ?))))) THEN 1 END) AS ahead,
                    COUNT(CASE WHEN status = 'in_consultation' THEN 1 END) AS lanes
                    FROM {$table} WHERE tenant_id = ? AND unit = ? AND queue_date = ? AND id <> ?
                        AND status IN ('waiting', 'called', 'in_consultation')",
                    [$ownRank, $ownRank, $row['ticket_no'], $row['ticket_no'], $row['id'], $tenant, $row['unit'], $date, $row['id']]
                )->getRowArray();
                $ahead = (int) $counts['ahead'];
                $ticket['people_ahead'] = $ahead;
                $ticket['position'] = $ahead + 1;
                if ($ahead === 0) {
                    $ticket['estimated_wait_minutes'] = 0;
                } else {
                    if (!array_key_exists($row['unit'], $medians)) {
                        $medians[$row['unit']] = $this->medianConsultMinutes($db, $tenant, $row['unit'], $now);
                    }
                    $median = $medians[$row['unit']];
                    // Effective lanes approximate capacity using current consultations, not staffing.
                    $ticket['estimated_wait_minutes'] = $median === null ? null
                        : (int) ceil($ahead * $median / max(1, (int) $counts['lanes']));
                }
            }
            $tickets[] = $ticket;
        }
        return ['date' => $date, 'tickets' => $tickets];
    }

    private function medianConsultMinutes(BaseConnection $db, int $tenant, string $unit, DateTimeImmutable $now): ?float
    {
        $cutoff = $now->modify('-7 days');
        // Bound candidates before validation: invalid rows do not trigger an older-history scan.
        $history = $db->table('queue_entries')->select('started_at,completed_at')
            ->where('tenant_id', $tenant)->where('unit', $unit)->where('status', 'done')
            ->where('completed_at >=', $cutoff->format('Y-m-d H:i:s'))
            ->where('completed_at <=', $now->format('Y-m-d H:i:s'))
            ->orderBy('completed_at', 'DESC')->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();
        $minutes = [];
        foreach ($history as $sample) {
            $start = $this->localTimestamp($sample['started_at']);
            $end = $this->localTimestamp($sample['completed_at']);
            if ($start === null || $end === null || $start < $cutoff || $end > $now) continue;
            $seconds = $end->getTimestamp() - $start->getTimestamp();
            if ($seconds > 0 && $seconds <= 4 * 60 * 60) $minutes[] = $seconds / 60;
        }
        $count = count($minutes);
        if ($count < 3) return null;
        sort($minutes, SORT_NUMERIC);
        $middle = intdiv($count, 2);
        return $count % 2 === 1 ? $minutes[$middle] : ($minutes[$middle - 1] + $minutes[$middle]) / 2;
    }

    private function localTimestamp(?string $value): ?DateTimeImmutable
    {
        if ($value === null) return null;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, SchedulingClock::timezone());
        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $value ? $parsed : null;
    }
}

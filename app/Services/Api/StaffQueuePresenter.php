<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Services\Scheduling\SchedulingClock;
use DateTimeImmutable;
use DateTimeZone;

/** Read-only, PII-free mobile projection; never return joined board rows directly. */
final class StaffQueuePresenter
{
    private const ACTIVE_BUCKETS = ['waiting', 'called', 'in_consultation'];

    public function present(string $unit, array $board, DateTimeImmutable $now): array
    {
        $tickets = [];
        $active = 0;
        $mix = ['routine' => 0, 'urgent' => 0, 'emergency' => 0];
        $ages = [];
        foreach ([...self::ACTIVE_BUCKETS, 'skipped'] as $bucket) {
            $tickets[$bucket] = [];
            foreach ($board[$bucket] ?? [] as $item) {
                $created = $this->parseTimestamp($item['created_at'] ?? null, new DateTimeZone('UTC'));
                $tickets[$bucket][] = [
                    'id' => (int) $item['id'],
                    'ticket_code' => (string) $item['ticket_code'],
                    'status' => (string) $item['status'],
                    'priority' => (string) $item['priority'],
                    'created_at' => $created?->format(DATE_ATOM),
                    'called_at' => $this->transition($item['called_at'] ?? null),
                    'started_at' => $this->transition($item['started_at'] ?? null),
                    'skip_until' => $this->transition($item['skip_until'] ?? null),
                ];
                if (in_array($bucket, self::ACTIVE_BUCKETS, true)) {
                    $active++;
                    if (array_key_exists($item['priority'], $mix)) {
                        $mix[$item['priority']]++;
                    }
                }
                if ($bucket === 'waiting' && $created !== null && $created <= $now) {
                    $ages[] = $now->getTimestamp() - $created->getTimestamp();
                }
            }
        }
        $samples = count($ages);
        $total = array_sum($ages);

        return [
            'unit' => $unit,
            'counts' => $board['counts'],
            'tickets' => $tickets,
            'metrics' => [
                'active' => $active,
                'priority_mix' => $mix,
                'waiting_age' => [
                    'sample_count' => $samples,
                    'total_seconds' => $total,
                    'oldest_seconds' => $samples > 0 ? max($ages) : null,
                    'average_seconds' => $samples > 0 ? $total / $samples : null,
                ],
            ],
        ];
    }

    private function transition(mixed $value): ?string
    {
        return $this->parseTimestamp($value, SchedulingClock::timezone())?->format(DATE_ATOM);
    }

    private function parseTimestamp(mixed $value, DateTimeZone $source): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        // Queue storage uses SQL datetime, not relative dates or server-local time.
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $source);
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
            return null;
        }

        return $parsed;
    }
}

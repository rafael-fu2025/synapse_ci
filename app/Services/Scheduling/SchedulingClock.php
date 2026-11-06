<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SchedulingClock
{
    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone('Asia/Manila');
    }

    public static function now(?DateTimeImmutable $instant = null): DateTimeImmutable
    {
        return $instant === null
            ? new DateTimeImmutable('now', self::timezone())
            : $instant->setTimezone(self::timezone());
    }

    public static function today(?DateTimeImmutable $instant = null): string
    {
        return self::now($instant)->format('Y-m-d');
    }

    public static function timestamp(?DateTimeImmutable $instant = null): string
    {
        return self::now($instant)->format('Y-m-d H:i:s');
    }

    /**
     * The last $count Manila business days, oldest first, ending today.
     *
     * Reports label a bucket with the Manila calendar day it covers, so every
     * dashboard on a screen shares one calendar even when the underlying
     * timestamps are stored as UTC instants.
     *
     * @return list<string> Manila dates formatted Y-m-d
     */
    public static function businessDays(int $count, ?DateTimeImmutable $instant = null): array
    {
        if ($count < 1 || $count > 31) {
            throw new InvalidArgumentException('Business day count must be between 1 and 31.');
        }
        $days = [];
        $cursor = self::now($instant);
        for ($i = 0; $i < $count; $i++) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('-1 day');
        }

        return array_reverse($days);
    }

    /**
     * The UTC instant bounds [start, end) of one Manila business day.
     *
     * Encounter, session, batch and audit timestamps are stored as UTC instants,
     * so a Manila day is a closed UTC interval rather than a calendar day. Use
     * the bounds as a range predicate: it keeps the index usable, which a
     * DATE(column) comparison cannot.
     *
     * @return array{0: string, 1: string} UTC bounds formatted Y-m-d H:i:s
     */
    public static function dayRangeUtc(string $manilaDate): array
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $manilaDate . ' 00:00:00', self::timezone());
        if ($start === false || $start->format('Y-m-d') !== $manilaDate) {
            throw new InvalidArgumentException('Business day must be a real calendar date in Y-m-d form.');
        }
        $end = $start->modify('+1 day');
        $utc = new DateTimeZone('UTC');

        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /**
     * Render a stored UTC timestamp in Manila wall-clock.
     *
     * Storage stays UTC; only the display edge converts.
     */
    public static function formatManila(string $storedUtc, string $format): string
    {
        $instant = new DateTimeImmutable($storedUtc, new DateTimeZone('UTC'));

        return $instant->setTimezone(self::timezone())->format($format);
    }

    /**
     * The UTC instant bounds of the last $count Manila business days, oldest first.
     *
     * @return array<string, array{0: string, 1: string}> Manila date => [startUtc, endUtc]
     */
    public static function businessDayRanges(int $count, ?DateTimeImmutable $instant = null): array
    {
        $ranges = [];
        foreach (self::businessDays($count, $instant) as $businessDay) {
            $ranges[$businessDay] = self::dayRangeUtc($businessDay);
        }

        return $ranges;
    }

    /**
     * The index of the business day that contains a UTC instant.
     *
     * Lets a report read one bounded range from the database and bucket the rows
     * in PHP, so the day boundaries stay in one place instead of being duplicated
     * as a SQL offset.
     *
     * @param array<string, array{0: string, 1: string}> $ranges Manila date => [startUtc, endUtc]
     */
    public static function businessDayIndex(string $instantUtc, array $ranges): ?int
    {
        $index = 0;
        foreach ($ranges as $range) {
            if ($instantUtc >= $range[0] && $instantUtc < $range[1]) {
                return $index;
            }
            $index++;
        }

        return null;
    }
}

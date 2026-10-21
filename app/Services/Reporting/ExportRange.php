<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Services\Scheduling\SchedulingClock;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Inclusive Manila calendar dates; storage predicates use an exclusive next-day bound. */
final class ExportRange
{
    public const MAX_DAYS = 31;

    private function __construct(public readonly DateTimeImmutable $start, public readonly DateTimeImmutable $end) {}

    public static function fromInput(array $input): self
    {
        $dates = [];
        foreach (['from', 'to'] as $key) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) {
                throw new RuntimeException('Provide From and To dates in YYYY-MM-DD format (Asia/Manila).', 422);
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, SchedulingClock::timezone());
            if ($date === false || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < 1970
                || (int) $date->format('Y') > 9998) {
                throw new RuntimeException('Invalid export date.', 422);
            }
            $dates[] = $date;
        }
        [$start, $end] = $dates;
        if ($end < $start || (int) $start->diff($end)->days >= self::MAX_DAYS) {
            throw new RuntimeException('Select an ordered range of at most 31 Manila calendar days.', 422);
        }
        return new self($start, $end);
    }

    public function bounds(string $storageTimezone = 'UTC'): array
    {
        $zone = new DateTimeZone($storageTimezone);
        return [$this->start->setTimezone($zone)->format('Y-m-d H:i:s'),
            $this->end->modify('+1 day')->setTimezone($zone)->format('Y-m-d H:i:s')];
    }

    public function metadata(): array
    {
        return ['from' => $this->start->format('Y-m-d'), 'to' => $this->end->format('Y-m-d'), 'timezone' => 'Asia/Manila'];
    }
}

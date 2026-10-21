<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use DateTimeImmutable;
use DateTimeZone;

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
}

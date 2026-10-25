<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NotificationTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationTimeTest extends TestCase
{
    public static function timestamps(): iterable
    {
        yield 'same day' => ['2026-10-25 12:51:05', '2026-10-25T20:51:05+08:00'];
        yield 'next day' => ['2026-10-25 20:30:00', '2026-10-26T04:30:00+08:00'];
        yield 'next year' => ['2026-12-31 23:59:59', '2027-01-01T07:59:59+08:00'];
        yield 'leap day' => ['2024-02-29 16:00:00', '2024-03-01T00:00:00+08:00'];
        yield 'unread' => [null, null];
        yield 'empty' => ['', null];
        yield 'invalid' => ['not a timestamp', null];
        yield 'invalid date' => ['2026-02-30 12:00:00', null];
    }

    #[DataProvider('timestamps')]
    public function testUtcStorageBecomesExplicitPhilippineTime(?string $stored, ?string $expected): void
    {
        $original = date_default_timezone_get();
        try {
            foreach (['UTC', 'Asia/Manila', 'America/New_York'] as $timezone) {
                date_default_timezone_set($timezone);
                self::assertSame($expected, NotificationTime::forDisplay($stored));
            }
        } finally {
            date_default_timezone_set($original);
        }
    }
}

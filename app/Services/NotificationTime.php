<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/** Notification storage is UTC; API timestamps identify Philippine display time explicitly. */
final class NotificationTime
{
    public static function forDisplay(?string $stored): ?string
    {
        if ($stored === null || $stored === '') return null;
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stored, new DateTimeZone('UTC'));
        if ($time === false || $time->format('Y-m-d H:i:s') !== $stored) return null;

        return $time->setTimezone(new DateTimeZone('Asia/Manila'))->format(DATE_ATOM);
    }
}

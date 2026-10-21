<?php

// Stored timestamps are UTC even when PHP's default timezone is overridden.
$announcementDate = static function (?string $value, string $format = 'Y-m-d H:i'): string {
    if ($value === null || $value === '') { return ''; }
    return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
        ->setTimezone(new \DateTimeZone('Asia/Manila'))->format($format);
};

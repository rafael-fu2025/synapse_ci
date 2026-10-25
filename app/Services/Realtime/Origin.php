<?php

declare(strict_types=1);

namespace App\Services\Realtime;

final class Origin
{
    public static function isValid(string $origin): bool
    {
        if (strlen($origin) > 512 || preg_match('/[\x00-\x20\x7f]/', $origin)) {
            return false;
        }
        $parts = parse_url($origin);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []) {
            return false;
        }
        $host = $parts['host'];
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false
            && preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?\z/D', $host) !== 1) {
            return false;
        }

        return ! isset($parts['port']) || ($parts['port'] >= 1 && $parts['port'] <= 65535);
    }
}

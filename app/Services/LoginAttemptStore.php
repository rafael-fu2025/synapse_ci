<?php

declare(strict_types=1);

namespace App\Services;

interface LoginAttemptStore
{
    /**
     * Consume the IP budget first, then the IP/identity budget atomically.
     * @param list<array{key: string, limit: int, seconds: int}> $buckets
     * @return int Zero when allowed, otherwise seconds until retry.
     */
    public function consume(array $buckets, int $now): int;
}

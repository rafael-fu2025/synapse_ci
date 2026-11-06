<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Canonical request hashing and UUID-v4 validation for atomic domain-row replay. */
final class TeamIdempotencyKey
{
    /** @return array{nonce:?string,hash:?string} */
    public static function optional(?string $nonce, array $request): array
    {
        if ($nonce === null) {
            return ['nonce' => null, 'hash' => null];
        }
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $nonce)) {
            throw new RuntimeException('client_nonce must be a UUID v4.', 422);
        }
        $canonical = self::canonical($request);
        return [
            'nonce' => strtolower($nonce),
            'hash' => hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    public static function assertSame(?string $stored, string $expected): void
    {
        if (!is_string($stored) || !hash_equals($stored, $expected)) {
            throw TeamConflictException::idempotency();
        }
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonical'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }
        return $value;
    }
}

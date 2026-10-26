<?php

declare(strict_types=1);

namespace App\Services\Api;

/** Per-request bearer-token context set by ApiAuthFilter; never persisted or cached between requests. */
final class ApiContext
{
    /** @var array{identity: array<string, mixed>, token_hash: string}|null */
    private static ?array $state = null;

    public static function set(array $identity, string $tokenHash): void
    {
        self::$state = ['identity' => $identity, 'token_hash' => $tokenHash];
    }

    /** @return array<string, mixed> */
    public static function identity(): array
    {
        if (self::$state === null) {
            throw new \RuntimeException('Authentication required.', 401);
        }
        return self::$state['identity'];
    }

    public static function tokenHash(): string
    {
        if (self::$state === null) {
            throw new \RuntimeException('Authentication required.', 401);
        }
        return self::$state['token_hash'];
    }

    public static function clear(): void
    {
        self::$state = null;
    }
}

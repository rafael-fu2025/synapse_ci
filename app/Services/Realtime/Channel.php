<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use InvalidArgumentException;

final class Channel
{
    public static function user(int $tenantId, int $userId): string
    {
        if ($tenantId < 1 || $userId < 1) {
            throw new InvalidArgumentException('Tenant and user IDs must be positive.');
        }

        return "private.tenant.{$tenantId}.user.{$userId}";
    }

    public static function personal(int $userId): string
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('User ID must be positive.');
        }

        return "private.user.{$userId}";
    }

    public static function loginCarousel(int $tenantId): string
    {
        if ($tenantId < 1) {
            throw new InvalidArgumentException('Tenant ID must be positive.');
        }

        return "public.tenant.{$tenantId}.login-carousel";
    }

    // Syntax validation is not authorization; only trusted callers issue grants.
    public static function isValid(mixed $channel): bool
    {
        return is_string($channel) && strlen($channel) <= 160
            && (preg_match('/\Aprivate\.[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*\z/D', $channel) === 1
                || preg_match('/\Apublic\.tenant\.[1-9][0-9]*\.login-carousel\z/D', $channel) === 1);
    }

    public static function validateList(mixed $channels): array
    {
        if (! is_array($channels) || ! array_is_list($channels) || count($channels) < 1 || count($channels) > 32) {
            throw new InvalidArgumentException('A ticket needs 1 to 32 explicit channels.');
        }
        foreach ($channels as $channel) {
            if (! self::isValid($channel)) {
                throw new InvalidArgumentException('Invalid private channel.');
            }
        }
        if (count(array_unique($channels)) !== count($channels)) {
            throw new InvalidArgumentException('Duplicate channel grants.');
        }

        return $channels;
    }
}

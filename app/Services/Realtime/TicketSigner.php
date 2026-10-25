<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use InvalidArgumentException;
use Throwable;

/** No framework, session, database, or environment dependencies. */
final class TicketSigner
{
    public function __construct(private readonly string $signingKey, private readonly int $ttl = 60)
    {
        if (strlen($signingKey) < 32 || $ttl < 1 || $ttl > 300) {
            throw new InvalidArgumentException('Realtime signing key or ticket TTL is invalid.');
        }
    }

    public function issue(array $channels, string $origin, ?int $now = null): string
    {
        Channel::validateList($channels);
        if (! Origin::isValid($origin)) {
            throw new InvalidArgumentException('An exact HTTP origin is required.');
        }
        $payload = self::encode(json_encode([
            'v' => 1, 'channels' => $channels, 'exp' => ($now ?? time()) + $this->ttl, 'origin' => $origin,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $payload . '.' . self::encode(hash_hmac('sha256', $payload, $this->signingKey, true));
    }

    /** Returns verified claims, or null for every malformed, expired, or mismatched ticket. */
    public function verify(string $ticket, string $origin, ?int $now = null): ?array
    {
        $now ??= time();
        if (strlen($ticket) > 8192 || ! Origin::isValid($origin)) {
            return null;
        }
        $parts = explode('.', $ticket);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $signature] = $parts;
        if (! hash_equals(self::encode(hash_hmac('sha256', $payload, $this->signingKey, true)), $signature)) {
            return null;
        }
        try {
            $json = self::decode($payload);
            $claims = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($claims) || count($claims) !== 4 || ($claims['v'] ?? null) !== 1
                || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
                || $claims['exp'] > $now + $this->ttl || ($claims['origin'] ?? null) !== $origin) {
                return null;
            }
            Channel::validateList($claims['channels'] ?? null);

            return $claims;
        } catch (Throwable) {
            return null;
        }
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid ticket encoding.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || self::encode($decoded) !== $value) {
            throw new InvalidArgumentException('Invalid ticket encoding.');
        }

        return $decoded;
    }
}

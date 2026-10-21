<?php

declare(strict_types=1);

namespace App\Services\Audit;

use InvalidArgumentException;
use JsonException;

/** Versioned, deterministic hashing; this is not an external trust anchor. */
final class AuditHash
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public static function version(array $event): int
    {
        $version = $event['hash_version'] ?? 1;
        if ((!is_int($version) && !is_string($version)) || !in_array((string) $version, ['1', '2'], true)) {
            throw new InvalidArgumentException('Unsupported audit hash version.');
        }
        return (int) $version;
    }

    public static function compute(array $event): string
    {
        $version = self::version($event);
        if ($version === 1) {
            // Preserve the exact pre-cutover representation, including its known omissions.
            return hash('sha256', implode('|', [
                $event['prev_hash'], $event['event_type'], $event['entity_type'], $event['entity_id'],
                (string) ($event['actor_user_id'] ?? 'system'), $event['occurred_at'],
                (string) ($event['payload_json'] ?? ''),
            ]));
        }

        // Fixed-order JSON preserves field boundaries and nulls, unlike delimiter concatenation.
        $data = [
            'format' => 'humus.audit.v2',
            'hash_version' => 2,
            'tenant_id' => (int) $event['tenant_id'],
            'prev_hash' => (string) $event['prev_hash'],
            'event_type' => (string) $event['event_type'],
            'entity_type' => (string) $event['entity_type'],
            'entity_id' => (string) $event['entity_id'],
            'actor_user_id' => $event['actor_user_id'] === null ? null : self::unsignedId($event['actor_user_id']),
            'actor_role' => $event['actor_role'] === null ? null : (string) $event['actor_role'],
            'actor_ip' => $event['actor_ip'] === null ? null : (string) $event['actor_ip'],
            'action' => (string) $event['action'],
            'occurred_at' => (string) $event['occurred_at'],
            'payload_json' => $event['payload_json'] === null ? null : (string) $event['payload_json'],
        ];
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Canonical decimal representation without a lossy integer or float conversion. */
    private static function unsignedId(mixed $value): string
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[0-9]+\z/', (string) $value)) {
            throw new InvalidArgumentException('Audit ID must be an unsigned decimal integer.');
        }
        $decimal = ltrim((string) $value, '0');
        $decimal = $decimal === '' ? '0' : $decimal;
        if (self::compareIds($decimal, '18446744073709551615') > 0) {
            throw new InvalidArgumentException('Audit ID exceeds BIGINT UNSIGNED.');
        }
        return $decimal;
    }

    private static function compareIds(string $left, string $right): int
    {
        return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
    }

    /**
     * Malformed row IDs fail closed with broken_id null, since no valid ID is available.
     * @return array{valid: bool, verified_count: int, broken_id: int|string|null}
     */
    public static function verify(iterable $events, int $tenantId): array
    {
        $expected = self::GENESIS;
        $count = 0;
        $lastVersion = 1;
        $lastId = '0';
        foreach ($events as $event) {
            $id = null;
            try {
                $id = self::unsignedId($event['id'] ?? null);
                $valid = (int) $event['tenant_id'] === $tenantId && self::compareIds($id, $lastId) > 0
                    && $event['prev_hash'] === $expected;
                $version = self::version($event);
                $valid = $valid && $version >= $lastVersion && hash_equals(self::compute($event), (string) $event['curr_hash']);
            } catch (InvalidArgumentException | JsonException $e) {
                $valid = false;
            }
            if (!$valid) {
                return [
                    'valid' => false,
                    'verified_count' => $count,
                    'broken_id' => $id !== null && self::compareIds($id, (string) PHP_INT_MAX) <= 0 ? (int) $id : $id,
                ];
            }
            $expected = $event['curr_hash'];
            $lastVersion = $version;
            $lastId = $id;
            $count++;
        }
        return ['valid' => true, 'verified_count' => $count, 'broken_id' => null];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Push;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Push;
use InvalidArgumentException;
use RuntimeException;

/**
 * The registry of device tokens for mobile push delivery.
 *
 * A device token is a routable address, not a credential: it cannot be hashed the
 * way an API bearer token is, because the delivery service needs the address
 * itself. It is therefore never returned by an endpoint and never logged.
 */
final class PushTokenService
{
    /** FCM registration tokens and APNs device tokens both fit this shape. */
    private const TOKEN_PATTERN = '/\A[A-Za-z0-9_:\-]{20,512}\z/D';

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }

    /**
     * Register or refresh a device token for the signed-in identity.
     *
     * A device token follows the account that last used the installation, so a
     * shared phone cannot keep delivering another account's notices.
     */
    public function register(array $identity, string $token, string $platform, ?string $deviceLabel, ?string $ip): void
    {
        $token = trim($token);
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new InvalidArgumentException('Invalid device token.', 422);
        }
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['android', 'ios'], true)) {
            throw new InvalidArgumentException('Invalid device platform.', 422);
        }
        [$tenant, $user] = $this->identity($identity);
        $now = gmdate('Y-m-d H:i:s');
        $db = $this->db();
        $existing = $db->table('push_tokens')->where('token', $token)->get()->getRowArray();
        if ($existing !== null) {
            $db->table('push_tokens')->where('id', (int) $existing['id'])->update([
                'tenant_id' => $tenant,
                'user_id' => $user,
                'platform' => $platform,
                'device_label' => $deviceLabel,
                'created_ip' => $ip,
                'last_used_at' => $now,
                'revoked_at' => null,
                'updated_at' => $now,
            ]);
            return;
        }
        if (!$db->table('push_tokens')->insert([
            'tenant_id' => $tenant,
            'user_id' => $user,
            'token' => $token,
            'platform' => $platform,
            'device_label' => $deviceLabel,
            'created_ip' => $ip,
            'last_used_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])) {
            throw new RuntimeException('Unable to save device token.', 503);
        }
        $this->enforceCap($tenant, $user);
    }

    /** Revoke a token that the signed-in identity registered. */
    public function revoke(array $identity, string $token): void
    {
        [$tenant, $user] = $this->identity($identity);
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('A device token is required.', 422);
        }
        $this->db()->table('push_tokens')
            ->where('tenant_id', $tenant)
            ->where('user_id', $user)
            ->where('token', $token)
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** Drop a token the delivery service reported as no longer usable. */
    public function forget(string $token): void
    {
        $this->db()->table('push_tokens')
            ->where('token', $token)
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    /**
     * The active device tokens of one identity.
     *
     * @return list<array{token: string, platform: string}>
     */
    public function activeTokens(int $tenant, int $user): array
    {
        if ($tenant < 1 || $user < 1) {
            return [];
        }
        $rows = $this->db()->table('push_tokens')
            ->select('token,platform')
            ->where('tenant_id', $tenant)
            ->where('user_id', $user)
            ->where('revoked_at', null)
            ->orderBy('last_used_at', 'DESC')
            ->get()->getResultArray();
        $tokens = [];
        foreach ($rows as $row) {
            $tokens[] = ['token' => (string) $row['token'], 'platform' => (string) $row['platform']];
        }
        return $tokens;
    }

    /** Keep the number of live tokens per user bounded, as the API token families are. */
    private function enforceCap(int $tenant, int $user): void
    {
        $cap = max(1, (int) config(Push::class)->maxActiveTokens);
        $db = $this->db();
        $ids = $db->table('push_tokens')
            ->select('id')
            ->where('tenant_id', $tenant)
            ->where('user_id', $user)
            ->where('revoked_at', null)
            ->orderBy('last_used_at', 'DESC')
            ->get()->getResultArray();
        if (count($ids) <= $cap) {
            return;
        }
        $stale = array_map(static fn (array $row): int => (int) $row['id'], array_slice($ids, $cap));
        if ($stale !== []) {
            $db->table('push_tokens')->whereIn('id', $stale)
                ->update(['revoked_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        }
    }

    /** @return array{0: int, 1: int} */
    private function identity(array $identity): array
    {
        $tenant = (int) ($identity['tenant_id'] ?? 0);
        $user = (int) ($identity['id'] ?? 0);
        if ($tenant < 1 || $user < 1) {
            throw new RuntimeException('Authentication required.', 401);
        }
        return [$tenant, $user];
    }
}

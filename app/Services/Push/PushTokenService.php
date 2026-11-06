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
    public function register(
        array $identity,
        string $token,
        string $platform,
        ?string $deviceLabel,
        ?string $ip,
        ?string $installationId = null,
        ?string $apiFamilyId = null,
        bool $teamWorkspaceCapable = false,
    ): void
    {
        $token = trim($token);
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new InvalidArgumentException('Invalid device token.', 422);
        }
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['android', 'ios'], true)) {
            throw new InvalidArgumentException('Invalid device platform.', 422);
        }
        if ($installationId !== null && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $installationId) !== 1) {
            throw new InvalidArgumentException('Invalid installation identifier.', 422);
        }
        if ($apiFamilyId !== null && preg_match('/\A[0-9a-f]{32}\z/i', $apiFamilyId) !== 1) {
            throw new InvalidArgumentException('Invalid API token family.', 422);
        }
        $capabilityFields = $this->capabilityFields();
        [$tenant, $user] = $this->identity($identity);
        $now = gmdate('Y-m-d H:i:s');
        $db = $this->db();
        $existing = $db->table('push_tokens')->where('token', $token)->get()->getRowArray();
        if ($existing !== null) {
            $update = [
                'tenant_id' => $tenant,
                'user_id' => $user,
                'platform' => $platform,
                'device_label' => $deviceLabel,
                'created_ip' => $ip,
                'last_used_at' => $now,
                'revoked_at' => null,
                'updated_at' => $now,
            ];
            if ($capabilityFields) {
                $update += [
                    'installation_id' => $installationId,
                    'api_family_id' => $apiFamilyId,
                    'team_workspace_capable' => $teamWorkspaceCapable ? 1 : 0,
                ];
            }
            $db->table('push_tokens')->where('id', (int) $existing['id'])->update($update);
            return;
        }
        $insert = [
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
        ];
        if ($capabilityFields) {
            $insert += [
                'installation_id' => $installationId,
                'api_family_id' => $apiFamilyId,
                'team_workspace_capable' => $teamWorkspaceCapable ? 1 : 0,
            ];
        }
        if (!$db->table('push_tokens')->insert($insert)) {
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
    public function forget(int $tenant, string $token): void
    {
        if ($tenant < 1) { return; }
        $this->db()->table('push_tokens')
            ->where('tenant_id', $tenant)
            ->where('token', $token)
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    /**
     * The active device tokens of one identity.
     *
     * @return list<array{token: string, platform: string}>
     */
    public function activeTokens(int $tenant, int $user, bool $requireTeamCapability = false): array
    {
        if ($tenant < 1 || $user < 1 || ($requireTeamCapability && !$this->capabilityFields())) {
            return [];
        }
        $query = $this->db()->table('push_tokens')
            ->select('token,platform')
            ->where('tenant_id', $tenant)
            ->where('user_id', $user)
            ->where('revoked_at', null);
        if ($requireTeamCapability) {
            $query->where('team_workspace_capable', 1);
        }
        $rows = $query->orderBy('last_used_at', 'DESC')->get()->getResultArray();
        $tokens = [];
        foreach ($rows as $row) {
            $tokens[] = ['token' => (string) $row['token'], 'platform' => (string) $row['platform']];
        }
        return $tokens;
    }

    public function revokeFamily(int $tenant, string $familyId): void
    {
        if ($tenant < 1 || preg_match('/\A[0-9a-f]{32}\z/i', $familyId) !== 1 || !$this->capabilityFields()) { return; }
        $now = gmdate('Y-m-d H:i:s');
        $this->db()->table('push_tokens')->where('tenant_id', $tenant)
            ->where('api_family_id', strtolower($familyId))->where('revoked_at', null)
            ->update(['revoked_at' => $now, 'updated_at' => $now]);
    }

    public function hasTeamCapableToken(int $tenant, int $user): bool
    {
        if ($tenant < 1 || $user < 1 || !$this->capabilityFields()) { return false; }
        return $this->db()->table('push_tokens')->where('tenant_id', $tenant)->where('user_id', $user)
            ->where('team_workspace_capable', 1)->where('revoked_at', null)->countAllResults() > 0;
    }

    private function capabilityFields(): bool
    {
        $db = $this->db();
        $db->resetDataCache();
        return $db->tableExists('push_tokens')
            && $db->fieldExists('installation_id', 'push_tokens')
            && $db->fieldExists('api_family_id', 'push_tokens')
            && $db->fieldExists('team_workspace_capable', 'push_tokens');
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
            $db->table('push_tokens')->where('tenant_id', $tenant)->where('user_id', $user)->whereIn('id', $stale)
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

<?php

declare(strict_types=1);

namespace App\Services\Push;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Push;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The registry of device tokens for mobile push delivery.
 *
 * A device token is a routable address, not a credential: it cannot be hashed the
 * way an API bearer token is, because the delivery service needs the address
 * itself. It is therefore never returned by an endpoint and never logged.
 */
final class PushTokenService
{
    public const SCOPE_VERSION = 1;
    public const LAN_AUDIENCE = 'lan-test';
    public const UUID_V4_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD';

    /** FCM registration tokens and APNs device tokens both fit this shape. */
    private const TOKEN_PATTERN = '/\A[A-Za-z0-9_:\-]{20,512}\z/D';
    private const CAPABILITY_FIELDS = ['installation_id', 'api_family_id', 'team_workspace_capable'];
    private const SCOPE_FIELDS = ['audience', 'registration_id', 'scope_version', 'deployment_id', 'active_lan_installation_id'];

    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?Push $configuration = null,
    ) {
    }

    private function db(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }

    private function config(): Push
    {
        return $this->configuration ?? config(Push::class);
    }

    /** Describe the server contract before a LAN client submits any device token. */
    public function capabilities(): array
    {
        if (!$this->config()->hasDeploymentId() || !$this->scopeFields()) {
            throw new RuntimeException('LAN push registration is unavailable on this deployment.', 503);
        }
        return [
            'scope_version' => self::SCOPE_VERSION,
            'deployment_id' => $this->config()->deploymentId,
            'audiences' => [self::LAN_AUDIENCE],
        ];
    }

    /**
     * Register or refresh a device token for the signed-in identity.
     *
     * LAN activations additionally bind a stable installation to its authenticated
     * tenant/user/API family. Retirement and replacement commit together, and a
     * nullable unique active-installation key also protects concurrent first registrations.
     *
     * @return array{registered: true, scope_version?: int, deployment_id?: string, registration_id?: string}
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
        ?string $audience = null,
        ?string $registrationId = null,
        ?string $deploymentId = null,
    ): array {
        $token = trim($token);
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new InvalidArgumentException('Invalid device token.', 422);
        }
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['android', 'ios'], true)) {
            throw new InvalidArgumentException('Invalid device platform.', 422);
        }
        if ($installationId !== null && preg_match(self::UUID_V4_PATTERN, $installationId) !== 1) {
            throw new InvalidArgumentException('Invalid installation identifier.', 422);
        }
        if ($apiFamilyId !== null && preg_match('/\A[0-9a-f]{32}\z/iD', $apiFamilyId) !== 1) {
            throw new InvalidArgumentException('Invalid API token family.', 422);
        }
        $scoped = $this->validateScope($audience, $registrationId);
        if (!$scoped && $deploymentId !== null) {
            throw new InvalidArgumentException('A deployment identifier requires a scoped registration.', 422);
        }
        if ($scoped && ($installationId === null || $apiFamilyId === null)) {
            throw new InvalidArgumentException('LAN push requires an installation and authenticated API token family.', 422);
        }
        [$tenant, $user] = $this->identity($identity);
        if ($scoped && !$this->config()->hasDeploymentId()) {
            throw new RuntimeException('LAN push registration is unavailable on this deployment.', 503);
        }
        if ($scoped && $deploymentId !== null && !hash_equals($this->config()->deploymentId, $deploymentId)) {
            throw new InvalidArgumentException('The push deployment identifier does not match this server.', 422);
        }
        $installationId = $installationId === null ? null : strtolower($installationId);
        $apiFamilyId = $apiFamilyId === null ? null : strtolower($apiFamilyId);
        $registrationId = $registrationId === null ? null : strtolower($registrationId);
        $fields = $this->fields();
        $capabilityFields = array_diff(self::CAPABILITY_FIELDS, $fields) === [];
        if ($scoped && !$this->scopeFields($fields)) {
            throw new RuntimeException('LAN push registration is unavailable on this deployment.', 503);
        }
        $now = gmdate('Y-m-d H:i:s');
        $db = $this->db();
        // Registration is an independent API operation, not part of a business-write transaction.
        if ($db->transDepth !== 0 || !$db->transStatus() || !$db->transBegin()) {
            throw new RuntimeException('Unable to start device registration.', 503);
        }
        try {
            $table = $db->protectIdentifiers($db->prefixTable('push_tokens'));
            $lock = $db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : '';
            $query = $db->query("SELECT * FROM {$table} WHERE token = ?{$lock}", [$token]);
            if ($query === false) {
                throw new RuntimeException('Unable to read device registration.', 503);
            }
            $existing = $query->getRowArray();
            if (!$scoped && $existing !== null && $this->hasScopeMetadata($existing)) {
                // An old/unscoped request must never strip the privacy envelope from a LAN token.
                throw new InvalidArgumentException('This device token requires a scoped registration.', 422);
            }
            if ($scoped) {
                $query = $db->query("SELECT * FROM {$table} WHERE registration_id = ?{$lock}", [$registrationId]);
                if ($query === false) {
                    throw new RuntimeException('Unable to read device activation.', 503);
                }
                foreach ($query->getResultArray() as $context) {
                    if ((int) $context['tenant_id'] !== $tenant || (int) $context['user_id'] !== $user
                        || $context['audience'] !== self::LAN_AUDIENCE
                        || $context['installation_id'] !== $installationId || $context['api_family_id'] !== $apiFamilyId
                        || $context['deployment_id'] !== $this->config()->deploymentId) {
                        throw new RuntimeException('A push activation cannot be reused in another context.', 409);
                    }
                }
                $retire = $db->table('push_tokens')->where('audience', self::LAN_AUDIENCE)
                    ->where('installation_id', $installationId)->where('revoked_at', null);
                if ($existing !== null) {
                    $retire->where('id !=', (int) $existing['id']);
                }
                if (!$retire->update(['revoked_at' => $now, 'updated_at' => $now, 'active_lan_installation_id' => null])) {
                    throw new RuntimeException('Unable to retire the previous push activation.', 503);
                }
            }
            $values = [
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
                $values += [
                    'installation_id' => $installationId,
                    'api_family_id' => $apiFamilyId,
                    'team_workspace_capable' => $teamWorkspaceCapable ? 1 : 0,
                ];
            }
            if ($scoped) {
                $values += [
                    'audience' => self::LAN_AUDIENCE,
                    'registration_id' => $registrationId,
                    'scope_version' => self::SCOPE_VERSION,
                    'deployment_id' => $this->config()->deploymentId,
                    'active_lan_installation_id' => $installationId,
                ];
            }
            if ($existing !== null) {
                if (!$db->table('push_tokens')->where('id', (int) $existing['id'])->update($values)) {
                    throw new RuntimeException('Unable to save device token.', 503);
                }
                $savedId = (int) $existing['id'];
            } else {
                if (!$db->table('push_tokens')->insert($values + ['token' => $token, 'created_at' => $now])) {
                    throw new RuntimeException('Unable to save device token.', 503);
                }
                $savedId = (int) $db->insertID();
            }
            $this->enforceCap($tenant, $user, $savedId);
            if (!$db->transStatus() || !$db->transCommit()) {
                throw new RuntimeException('Unable to commit device registration.', 503);
            }
        } catch (Throwable $error) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            $db->resetTransStatus();
            if ($error instanceof InvalidArgumentException
                || ($error instanceof RuntimeException && in_array($error->getCode(), [409, 503], true))) {
                throw $error;
            }
            // Do not expose query text or routable device tokens through the API error/log envelope.
            throw new RuntimeException('Unable to save device registration.', 503, $error);
        }
        $result = ['registered' => true];
        if ($scoped) {
            $result += [
                'scope_version' => self::SCOPE_VERSION,
                'deployment_id' => $this->config()->deploymentId,
                'registration_id' => $registrationId,
            ];
        }
        return $result;
    }

    /** Revoke only the exact current activation; an obsolete scoped revoke is an idempotent no-op. */
    public function revoke(
        array $identity,
        string $token,
        ?string $audience = null,
        ?string $registrationId = null,
        ?string $apiFamilyId = null,
    ): void {
        [$tenant, $user] = $this->identity($identity);
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('A device token is required.', 422);
        }
        $scoped = $this->validateScope($audience, $registrationId);
        if ($scoped && ($apiFamilyId === null || preg_match('/\A[0-9a-f]{32}\z/iD', $apiFamilyId) !== 1)) {
            throw new InvalidArgumentException('An authenticated API token family is required.', 422);
        }
        $fields = $this->fields();
        if ($scoped && !$this->scopeFields($fields)) {
            throw new RuntimeException('LAN push revocation is unavailable on this deployment.', 503);
        }
        $db = $this->db();
        if (!$scoped) {
            $row = $db->table('push_tokens')->where('tenant_id', $tenant)->where('user_id', $user)
                ->where('token', $token)->get()->getRowArray();
            if ($row !== null && $this->hasScopeMetadata($row)) {
                throw new InvalidArgumentException('This device token requires audience and registration_id.', 422);
            }
        }
        $query = $db->table('push_tokens')->where('tenant_id', $tenant)->where('user_id', $user)->where('token', $token);
        if ($scoped) {
            $query->where('audience', self::LAN_AUDIENCE)->where('registration_id', strtolower($registrationId))
                ->where('api_family_id', strtolower($apiFamilyId));
        } else {
            $this->onlyUnscoped($query, $fields);
        }
        if (!$query->update($this->revocation($fields))) {
            throw new RuntimeException('Unable to revoke device registration.', 503);
        }
    }

    /** Drop only the activation the delivery service reported as no longer usable. */
    public function forget(int $tenant, string $token, ?string $audience = null, ?string $registrationId = null): void
    {
        if ($tenant < 1) { return; }
        $fields = $this->fields();
        $query = $this->db()->table('push_tokens')->where('tenant_id', $tenant)->where('token', $token);
        if ($audience !== null || $registrationId !== null) {
            if ($audience !== self::LAN_AUDIENCE || $registrationId === null
                || preg_match(self::UUID_V4_PATTERN, $registrationId) !== 1 || !$this->scopeFields($fields)) {
                return;
            }
            $query->where('audience', $audience)->where('registration_id', strtolower($registrationId));
        } else {
            $this->onlyUnscoped($query, $fields);
        }
        $query->update($this->revocation($fields));
    }

    /**
     * Active addresses plus their server-owned LAN scope. Legacy rows keep their original shape.
     * Partial schemas/metadata must not silently turn a scoped device into a legacy device.
     *
     * @return list<array<string, mixed>>
     */
    public function activeTokens(int $tenant, int $user, bool $requireTeamCapability = false): array
    {
        if ($tenant < 1 || $user < 1) {
            return [];
        }
        $fields = $this->fields();
        if ($requireTeamCapability && array_diff(self::CAPABILITY_FIELDS, $fields) !== []) {
            return [];
        }
        $scopeFields = array_values(array_intersect(self::SCOPE_FIELDS, $fields));
        $bindingFields = array_values(array_intersect(['installation_id', 'api_family_id'], $fields));
        $query = $this->db()->table('push_tokens')
            ->select(implode(',', array_merge(['token', 'platform'], $scopeFields, $bindingFields)))
            ->where('tenant_id', $tenant)->where('user_id', $user)->where('revoked_at', null);
        if ($requireTeamCapability) {
            $query->where('team_workspace_capable', 1);
        }
        $rows = $query->orderBy('last_used_at', 'DESC')->get()->getResultArray();
        $scopeReady = null;
        $tokens = [];
        foreach ($rows as $row) {
            $device = ['token' => (string) $row['token'], 'platform' => (string) $row['platform']];
            if ($this->hasScopeMetadata($row)) {
                $scopeReady ??= $this->scopeFields($fields);
                if (!$scopeReady) {
                    continue;
                }
                foreach (array_merge(self::SCOPE_FIELDS, ['installation_id', 'api_family_id']) as $field) {
                    $device[$field] = $row[$field] ?? null;
                }
            }
            $tokens[] = $device;
        }
        return $tokens;
    }

    public function revokeFamily(int $tenant, string $familyId): void
    {
        if ($tenant < 1 || preg_match('/\A[0-9a-f]{32}\z/iD', $familyId) !== 1 || !$this->capabilityFields()) { return; }
        $this->db()->table('push_tokens')->where('tenant_id', $tenant)
            ->where('api_family_id', strtolower($familyId))->where('revoked_at', null)
            ->update($this->revocation($this->fields()));
    }

    public function hasTeamCapableToken(int $tenant, int $user): bool
    {
        return $this->activeTokens($tenant, $user, true) !== [];
    }

    private function validateScope(?string $audience, ?string $registrationId): bool
    {
        if ($audience === null && $registrationId === null) {
            return false;
        }
        if ($audience !== self::LAN_AUDIENCE || $registrationId === null
            || preg_match(self::UUID_V4_PATTERN, $registrationId) !== 1) {
            throw new InvalidArgumentException('A valid lan-test audience and registration_id are required.', 422);
        }
        return true;
    }

    private function fields(): array
    {
        $db = $this->db();
        $db->resetDataCache();
        return $db->tableExists('push_tokens') ? $db->getFieldNames('push_tokens') : [];
    }

    private function capabilityFields(): bool
    {
        return array_diff(self::CAPABILITY_FIELDS, $this->fields()) === [];
    }

    private function scopeFields(?array $fields = null): bool
    {
        if (array_diff(array_merge(self::CAPABILITY_FIELDS, self::SCOPE_FIELDS), $fields ?? $this->fields()) !== []) {
            return false;
        }
        $index = $this->db()->getIndexData('push_tokens')['push_tokens_lan_active_installation'] ?? null;
        return $index !== null && strtoupper($index->type) === 'UNIQUE'
            && $index->fields === ['active_lan_installation_id'];
    }

    private function hasScopeMetadata(array $row): bool
    {
        foreach (self::SCOPE_FIELDS as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                return true;
            }
        }
        return false;
    }

    /** The conditional update closes the race between an old legacy revoke and a new scoped registration. */
    private function onlyUnscoped(\CodeIgniter\Database\BaseBuilder $query, array $fields): void
    {
        foreach (array_intersect(self::SCOPE_FIELDS, $fields) as $field) {
            $query->where($field, null);
        }
    }

    private function revocation(array $fields): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $values = ['revoked_at' => $now, 'updated_at' => $now];
        if (in_array('active_lan_installation_id', $fields, true)) {
            $values['active_lan_installation_id'] = null;
        }
        return $values;
    }

    /** Keep the number of live tokens per user bounded without evicting the registration just saved. */
    private function enforceCap(int $tenant, int $user, int $savedId): void
    {
        $cap = max(1, $this->config()->maxActiveTokens);
        $db = $this->db();
        $ids = $db->table('push_tokens')->select('id')->where('tenant_id', $tenant)->where('user_id', $user)
            ->where('revoked_at', null)->where('id !=', $savedId)
            ->orderBy('last_used_at', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray();
        $stale = array_map(static fn (array $row): int => (int) $row['id'], array_slice($ids, $cap - 1));
        if ($stale !== [] && !$db->table('push_tokens')->where('tenant_id', $tenant)->where('user_id', $user)
            ->whereIn('id', $stale)->update($this->revocation($this->fields()))) {
            throw new RuntimeException('Unable to enforce device registration limit.', 503);
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

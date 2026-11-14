<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Services\Api\ApiContext;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\PortalContext;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

/** Authoritative Guidance identity, grant, and case-ownership boundary. */
final class GuidanceAccessPolicy
{
    private BaseConnection $db;
    private Closure $identitySource;

    /** @param null|callable():?array<string,mixed> $identitySource */
    public function __construct(?callable $identitySource = null, ?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->identitySource = Closure::fromCallable($identitySource ?? static function (): ?array {
            try {
                return ApiContext::identity();
            } catch (RuntimeException) {
                return (new AuthenticatedUser())->current();
            }
        });
    }

    /** Resolve the transport identity, then reload all authority from this connection. */
    public function current(
        ?string $permission = null,
        bool $lock = false,
        ?int $expectedActorId = null
    ): array {
        return $this->authorize($this->transportIdentity(), $permission, $expectedActorId, $lock);
    }

    /**
     * Match orchestration context to the authenticated transport identity, then
     * reload all authority from this connection. Explicit arrays are hints,
     * never proof of identity or authority.
     */
    public function resolve(
        ?array $identity,
        ?string $permission = null,
        ?int $expectedActorId = null,
        bool $lock = false
    ): array {
        $transport = $this->transportIdentity();
        if ($identity !== null) {
            $id = self::positiveId($identity['id'] ?? null);
            $tenantId = self::positiveId($identity['tenant_id'] ?? null);
            if ($id === null || $tenantId === null
                || $id !== self::positiveId($transport['id'] ?? null)
                || $tenantId !== self::positiveId($transport['tenant_id'] ?? null)) {
                throw new DomainException('Guidance actor impersonation forbidden.', 403);
            }
        }

        return $this->authorize($transport, $permission, $expectedActorId, $lock);
    }

    /**
     * Reload an explicit identity on this connection. Caller-provided roles and
     * permissions are ignored; only id and tenant_id select the account.
     */
    public function authorize(
        array $identity,
        ?string $permission = null,
        ?int $expectedActorId = null,
        bool $lock = false
    ): array {
        $id = self::positiveId($identity['id'] ?? null);
        $tenantId = self::positiveId($identity['tenant_id'] ?? null);
        if ($id === null || $tenantId === null) {
            throw new DomainException('An authenticated guidance account is required.', 403);
        }
        if ($tenantId !== CurrentTenant::id()) {
            throw new DomainException('Guidance tenant forbidden.', 403);
        }
        if ($expectedActorId !== null && ($expectedActorId <= 0 || $expectedActorId !== $id)) {
            throw new DomainException('Guidance actor impersonation forbidden.', 403);
        }
        if ($lock && $this->db->DBDriver === 'MySQLi' && $this->db->transDepth < 1) {
            throw new RuntimeException('Guidance authority locking requires an active transaction.', 503);
        }

        $tenants = $this->table('tenants');
        $users = $this->table('users');
        $people = $this->table('people');
        $rolePermissions = $this->table('role_permissions');
        $permissionsTable = $this->table('permissions');
        $tenant = $this->first("SELECT * FROM {$tenants} WHERE id = ? AND is_active = 1 AND deleted_at IS NULL",
            [$tenantId], $lock);
        $user = $this->first("SELECT * FROM {$users} WHERE id = ? AND tenant_id = ? AND status = 'active' AND deleted_at IS NULL",
            [$id, $tenantId], $lock);
        if ($tenant === null || $user === null
            || !PortalContext::isRolePermitted((string) ($user['role'] ?? ''), PortalContext::PORTAL_GUIDANCE)) {
            throw new DomainException('An active same-tenant guidance account is required.', 403);
        }

        $person = null;
        if (($user['person_id'] ?? null) !== null) {
            $person = $this->first("SELECT * FROM {$people} WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$user['person_id'], $tenantId], $lock);
            if ($person === null) {
                throw new DomainException('A linked active guidance account is required.', 403);
            }
        } elseif ($user['role'] !== 'superadmin') {
            throw new DomainException('A linked active guidance account is required.', 403);
        }

        $lockSql = $lock && $this->db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : '';
        $permissionRows = $this->db->query("SELECT p.code FROM {$rolePermissions} rp
            INNER JOIN {$permissionsTable} p ON p.id = rp.permission_id
            WHERE rp.role = ? ORDER BY p.code{$lockSql}", [$user['role']])->getResultArray();
        $permissions = array_column($permissionRows, 'code');
        if ($permission !== null && !in_array($permission, $permissions, true)) {
            throw new DomainException('Guidance permission forbidden.', 403);
        }

        return [
            'id' => (int) $user['id'],
            'auth_version' => (int) ($user['auth_version'] ?? 1),
            'person_id' => $person === null ? null : (int) $person['id'],
            'tenant_id' => $tenantId,
            'username' => (string) $user['username'],
            'email' => $user['email'] ?? ($person['email'] ?? null),
            'name' => $person === null ? (string) $user['username']
                : trim((string) ($person['first_name'] ?? '') . ' ' . (string) ($person['last_name'] ?? '')),
            'role' => (string) $user['role'],
            'permissions' => $permissions,
            'logged_in' => true,
        ];
    }

    /** Read oversight is confined to this read-only path. */
    public function readCase(
        array $case,
        ?array $identity = null,
        string $permission = 'guidance.cases.read',
        bool $lockActor = false
    ): array {
        $actor = $this->resolve($identity, $permission, null, $lockActor);
        $this->assertCanReadCase($case, $actor);

        return $actor;
    }

    /**
     * Enforces tenant, primary ownership, read_any oversight, or accepted consultation access
     * against an already verified actor record.
     *
     * @param array<string, mixed> $case
     * @param array<string, mixed> $actor
     */
    public function assertCanReadCase(array $case, array $actor): void
    {
        $this->assertCaseTenant($case, $actor);
        $perms = $actor['permissions'] ?? [];
        if ((int) ($case['counsellor_user_id'] ?? 0) !== (int) ($actor['id'] ?? 0)
            && !in_array('guidance.cases.read_any', $perms, true)
            && !$this->hasAcceptedConsultationAccess((int) ($actor['tenant_id'] ?? 0), (int) ($case['id'] ?? 0), (int) ($actor['id'] ?? 0))) {
            throw new DomainException('Case access forbidden.', 403);
        }
    }

    /**
     * Checks whether the actor has read-only consultation access granted via an accepted consultation transfer.
     *
     * @param array<string, mixed> $case
     * @param array<string, mixed> $actor
     */
    public function isConsultationPartner(array $case, array $actor): bool
    {
        if ((int) ($case['counsellor_user_id'] ?? 0) === (int) ($actor['id'] ?? 0)) {
            return false;
        }

        return $this->hasAcceptedConsultationAccess(
            (int) ($actor['tenant_id'] ?? $case['tenant_id'] ?? 0),
            (int) ($case['id'] ?? 0),
            (int) ($actor['id'] ?? 0)
        );
    }

    private function hasAcceptedConsultationAccess(int $tenantId, int $caseId, int $userId): bool
    {
        if ($tenantId <= 0 || $caseId <= 0 || $userId <= 0 || !$this->db->tableExists('guidance_case_transfers')) {
            return false;
        }

        return $this->db->table('guidance_case_transfers')
            ->where('tenant_id', $tenantId)
            ->where('case_id', $caseId)
            ->where('to_counsellor_user_id', $userId)
            ->where('transfer_type', 'consultation')
            ->where('status', 'accepted')
            ->countAllResults() > 0;
    }

    /** Writes always require the freshly authorized assigned owner or active supervisor. */
    public function writeCase(
        ?array $identity,
        array $case,
        string $permission,
        ?int $expectedActorId = null,
        bool $lockActor = true
    ): array {
        $actor = $this->resolve($identity, $permission, $expectedActorId, $lockActor);
        $this->assertCaseTenant($case, $actor);
        $counsellorId = (int) ($case['counsellor_user_id'] ?? 0);
        $actorId = (int) $actor['id'];
        if ($counsellorId !== $actorId) {
            $tenantId = (int) $actor['tenant_id'];
            $supTable = $this->table('guidance_supervision_assignments');
            $supervision = ($counsellorId > 0 && $this->db->tableExists('guidance_supervision_assignments')) ? $this->first(
                "SELECT * FROM {$supTable} WHERE tenant_id = ? AND associate_user_id = ? AND supervisor_user_id = ? AND status = 'active'",
                [$tenantId, $counsellorId, $actorId],
                false
            ) : null;
            $hasCosign = in_array('guidance.counseling.cosign', $actor['permissions'] ?? [], true)
                || in_array('guidance.psychosocial.cosign', $actor['permissions'] ?? [], true)
                || in_array('guidance.clinical.cosign', $actor['permissions'] ?? [], true);
            if ($supervision === null && !($hasCosign && $this->canFinalizeDirectly($actorId, $tenantId))) {
                throw new DomainException('Case mutation forbidden.', 403);
            }
        }

        return $actor;
    }

    /**
     * Checks if tier is licensed_counselor or guidance_supervisor with active standing.
     * If counselor_associate, returns false (requires co-sign).
     * Superadmin/admin role NEVER bypasses this check.
     */
    public function canFinalizeDirectly(int $userId, ?int $tenantId = null): bool
    {
        $tenantId ??= CurrentTenant::id();
        if (!$this->db->tableExists('guidance_practitioner_profiles')) {
            return false;
        }
        $table = $this->table('guidance_practitioner_profiles');
        $profile = $this->first(
            "SELECT * FROM {$table} WHERE user_id = ? AND tenant_id = ? AND is_active = 1",
            [$userId, $tenantId],
            false
        );
        if ($profile === null) {
            return false;
        }

        if (($profile['professional_standing'] ?? '') !== 'active') {
            return false;
        }

        if (!empty($profile['credential_expiry'])) {
            if (strtotime((string) $profile['credential_expiry']) < strtotime(date('Y-m-d'))) {
                return false;
            }
        }

        $tier = (string) ($profile['credential_tier'] ?? '');
        if ($tier === 'counselor_associate') {
            return false;
        }

        return in_array($tier, ['licensed_counselor', 'guidance_supervisor'], true);
    }

    /**
     * Ensures active profile has the required capability scope.
     * Throws DomainException (403) on failure.
     */
    public function assertEligibilityForService(int $userId, string $serviceCapability, ?int $tenantId = null): void
    {
        $tenantId ??= CurrentTenant::id();
        $table = $this->table('guidance_practitioner_profiles');
        $profile = $this->first(
            "SELECT * FROM {$table} WHERE user_id = ? AND tenant_id = ? AND is_active = 1",
            [$userId, $tenantId],
            false
        );
        if ($profile === null) {
            throw new DomainException("Practitioner profile not found for user {$userId}.", 403);
        }

        if (($profile['professional_standing'] ?? '') !== 'active') {
            throw new DomainException('Practitioner is not in active professional standing.', 403);
        }

        if (!empty($profile['credential_expiry']) && strtotime((string) $profile['credential_expiry']) < strtotime(date('Y-m-d'))) {
            throw new DomainException('Practitioner credentials have expired.', 403);
        }

        $scopes = json_decode((string) ($profile['service_scope_json'] ?? '[]'), true) ?: [];
        if (!in_array($serviceCapability, $scopes, true)) {
            throw new DomainException("Practitioner lacks required '{$serviceCapability}' capability scope.", 403);
        }
    }

    /**
     * Verifies practitioner standing and whether co-sign is required when finalizing notes or clinical records.
     * Associate authored records require supervisor co-sign to reach 'finalized'.
     * Superadmin/admin role NEVER bypasses professional eligibility.
     *
     * @param array<string, mixed>|null $identity
     * @return array<string, mixed>
     */
    public function assertCanFinalizeNote(?array $identity, int $authorUserId, ?int $tenantId = null): array
    {
        $actor = $this->resolveSafe($identity);
        $tenantId ??= (int) $actor['tenant_id'];
        $actorId = (int) $actor['id'];

        $pTable = $this->table('guidance_practitioner_profiles');
        $authorProfile = $this->first(
            "SELECT * FROM {$pTable} WHERE user_id = ? AND tenant_id = ?",
            [$authorUserId, $tenantId],
            false
        );

        if ($authorProfile === null) {
            throw new DomainException('Clinical record finalization requires an active practitioner profile.', 403);
        }

        if ((int) ($authorProfile['is_active'] ?? 0) !== 1 || ($authorProfile['professional_standing'] ?? '') !== 'active') {
            throw new DomainException('Author practitioner is not in active standing.', 403);
        }

        if (!empty($authorProfile['credential_expiry']) && strtotime((string) $authorProfile['credential_expiry']) < strtotime(date('Y-m-d'))) {
            throw new DomainException('Author practitioner credentials have expired.', 403);
        }

        $tier = (string) ($authorProfile['credential_tier'] ?? '');

        if ($tier === 'counselor_associate') {
            // Associate authored records require supervisor co-sign to reach 'finalized'
            if ($actorId === $authorUserId) {
                throw new DomainException("Associate authored records require supervisor co-sign to reach 'finalized'.", 403);
            }

            // Co-signing actor must have active supervisor or licensed counselor standing
            if (!$this->canFinalizeDirectly($actorId, $tenantId)) {
                throw new DomainException('Co-signing actor must have active supervisor or licensed counselor standing.', 403);
            }

            $supTable = $this->table('guidance_supervision_assignments');
            $supervision = $this->first(
                "SELECT * FROM {$supTable} WHERE tenant_id = ? AND associate_user_id = ? AND supervisor_user_id = ? AND status = 'active'",
                [$tenantId, $authorUserId, $actorId],
                false
            );

            $hasCosignPermission = in_array('guidance.counseling.cosign', $actor['permissions'] ?? [], true)
                || in_array('guidance.psychosocial.cosign', $actor['permissions'] ?? [], true)
                || in_array('guidance.clinical.cosign', $actor['permissions'] ?? [], true);

            if ($supervision === null && !$hasCosignPermission) {
                throw new DomainException('Actor is not authorized as supervisor for this associate.', 403);
            }

            return $actor;
        }

        // Licensed counselor or guidance supervisor
        if ($actorId === $authorUserId) {
            if (!$this->canFinalizeDirectly($authorUserId, $tenantId)) {
                throw new DomainException('Author lacks active licensed standing.', 403);
            }
        } else {
            if (!$this->canFinalizeDirectly($actorId, $tenantId)) {
                throw new DomainException('Actor lacks active standing to finalize record.', 403);
            }
        }

        return $actor;
    }

    /**
     * Alias / wrapper for assertCanFinalizeNote returning validation summary array.
     *
     * @param array<string, mixed>|null $identity
     * @return array<string, mixed>
     */
    public function validateNoteFinalization(?array $identity, int $authorUserId, ?int $tenantId = null): array
    {
        $actor = $this->assertCanFinalizeNote($identity, $authorUserId, $tenantId);
        $tenantId ??= (int) $actor['tenant_id'];

        return [
            'actor' => $actor,
            'can_finalize' => true,
            'requires_cosign' => !$this->canFinalizeDirectly($authorUserId, $tenantId),
        ];
    }

    /** Resolve identity safely with fallback to authorize() if transport session is not initialized. */
    private function resolveSafe(?array $identity): array
    {
        try {
            return $this->resolve($identity);
        } catch (RuntimeException $e) {
            if ($identity !== null && isset($identity['id'], $identity['tenant_id'])) {
                return $this->authorize($identity);
            }
            throw $e;
        }
    }

    private function transportIdentity(): array
    {
        $identity = ($this->identitySource)();
        if (!is_array($identity)) {
            throw new RuntimeException(AuthenticatedUser::AUTHENTICATION_MESSAGE, 401);
        }

        return $identity;
    }

    private function assertCaseTenant(array $case, array $actor): void
    {
        if ((int) ($case['tenant_id'] ?? 0) !== CurrentTenant::id()
            || (int) ($case['tenant_id'] ?? 0) !== (int) $actor['tenant_id']) {
            throw new InvalidArgumentException('Counseling case not found.', 404);
        }
    }

    /** @return array<string,mixed>|null */
    private function first(string $sql, array $params, bool $lock): ?array
    {
        if ($lock && $this->db->DBDriver === 'MySQLi') {
            $sql .= ' FOR UPDATE';
        }

        return $this->db->query($sql, $params)->getRowArray();
    }

    private function table(string $name): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable($name));
    }

    private static function positiveId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }
}

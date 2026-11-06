<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Api\ApiContext;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Authoritative Team Workspace access independent of HTTP transport.
 *
 * Browser callers use the refreshed session identity by default. Bearer API
 * callers must opt in through bearer(), which reads ApiContext. In both cases
 * only the identity's id and tenant_id select the actor; role and other grants
 * are reloaded from the database. CurrentTenant is never an authority here.
 */
final class TeamAccess
{
    public const UNITS = ['clinic', 'guidance', 'facilities'];

    private BaseConnection $db;
    private Closure $identitySource;

    /** @param null|callable():?array<string,mixed> $identitySource */
    public function __construct(?callable $identitySource = null, ?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->identitySource = Closure::fromCallable($identitySource
            ?? static fn (): ?array => (new AuthenticatedUser())->current());
    }

    public static function bearer(?BaseConnection $db = null): self
    {
        return new self(static fn (): array => ApiContext::identity(), $db);
    }

    /** @return array{actor:array<string,mixed>,members:list<array{id:int,name:string,role:string}>,canManage:bool} */
    public function context(string $unit, bool $lock = false): array
    {
        $actor = $this->actor($unit, $lock);
        return [
            'actor' => $actor,
            'members' => $this->members($unit, $actor),
            'canManage' => self::managesRole($unit, (string) $actor['role']),
        ];
    }

    /**
     * Reload the transport identity from its tenant and optionally lock its
     * tenant/user rows. Call with $lock=true only inside a write transaction.
     *
     * @return array<string,mixed>
     */
    public function actor(string $unit, bool $lock = false): array
    {
        $roles = self::rolesFor($unit);
        $actor = $this->authoritativeIdentity($lock);
        if (!in_array($actor['role'] ?? null, $roles, true)) {
            throw new RuntimeException('Department membership required.', 403);
        }
        return $actor;
    }

    /** @return list<array{id:int,name:string,role:string}> */
    public function members(string $unit, array $actor): array
    {
        $roles = self::rolesFor($unit);
        $tenantId = self::positiveId($actor['tenant_id'] ?? null);
        if ($tenantId === null) {
            throw new RuntimeException('Authentication required.', 401);
        }
        $builder = $this->db->table('users u')->select('u.id,u.username,u.role')
            ->where('u.tenant_id', $tenantId)->where('u.status', 'active')->where('u.deleted_at', null)
            ->whereIn('u.role', $roles);
        $names = $this->db->tableExists('people')
            && array_diff(['id', 'tenant_id', 'first_name', 'last_name', 'deleted_at'], $this->db->getFieldNames('people')) === []
            && $this->db->fieldExists('person_id', 'users');
        if ($names) {
            $builder->select('p.first_name,p.last_name')->join('people p',
                'p.id = u.person_id AND p.tenant_id = u.tenant_id AND p.deleted_at IS NULL', 'left');
        }
        $members = [];
        foreach ($builder->orderBy('u.id', 'ASC')->get()->getResultArray() as $row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $members[] = [
                'id' => (int) $row['id'],
                'name' => $name !== '' ? $name : (string) $row['username'],
                'role' => (string) $row['role'],
            ];
        }
        return $members;
    }

    /** @return list<string> */
    public static function rolesFor(string $unit): array
    {
        if (!in_array($unit, self::UNITS, true)) {
            throw new RuntimeException('Invalid team department.', 422);
        }
        return PortalContext::allowedRoles($unit === 'facilities' ? PortalContext::PORTAL_BMG : $unit);
    }

    public static function managesRole(string $unit, string $role): bool
    {
        self::rolesFor($unit);
        return $role === 'superadmin' || in_array($role, match ($unit) {
            'clinic' => ['clinic_admin'],
            'guidance' => ['guidance_admin', 'guidance_supervisor'],
            'facilities' => ['bmg_admin'],
        }, true);
    }

    /** @return array<string,mixed> */
    public function authoritativeIdentity(bool $lock = false): array
    {
        $source = ($this->identitySource)();
        if (!is_array($source)) {
            throw new RuntimeException('Authentication required.', 401);
        }
        $id = self::positiveId($source['id'] ?? null);
        $tenantId = self::positiveId($source['tenant_id'] ?? null);
        if ($id === null || $tenantId === null) {
            throw new RuntimeException('Authentication required.', 401);
        }

        $tenant = $this->row($this->db->table('tenants')->where('id', $tenantId)
            ->where('is_active', 1)->where('deleted_at', null), $lock);
        $user = $this->row($this->db->table('users')->where('id', $id)->where('tenant_id', $tenantId)
            ->where('status', 'active')->where('deleted_at', null), $lock);
        if ($tenant === null || $user === null) {
            throw new RuntimeException('Authentication required.', 401);
        }

        // forIdentity applies the same person/role invariants used by browser and token authentication.
        $actor = (new AuthenticatedUser())->forIdentity($id, $tenantId);
        if ($actor === null || (string) ($actor['role'] ?? '') !== (string) $user['role']) {
            throw new RuntimeException('Authentication required.', 401);
        }
        return $actor;
    }

    /** @return array<string,mixed>|null */
    private function row(BaseBuilder $builder, bool $lock): ?array
    {
        return $lock && $this->db->DBDriver === 'MySQLi'
            ? $this->db->query($builder->getCompiledSelect() . ' FOR UPDATE')->getRowArray()
            : $builder->get()->getRowArray();
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

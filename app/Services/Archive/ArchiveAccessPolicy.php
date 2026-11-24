<?php

declare(strict_types=1);

namespace App\Services\Archive;

use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use DomainException;
use RuntimeException;

/**
 * Authoritative RBAC & department-isolation boundary for Archive Management.
 * Revalidates the acting identity from the backing user store on every call.
 */
final class ArchiveAccessPolicy
{
    public const ROLES_MAIN = [
        'superadmin',
        'audit_reader',
        'clinic_admin',
        'guidance_admin',
        'guidance_supervisor',
        'bmg_admin',
    ];

    private Closure $identitySource;

    /** @param null|callable():?array<string,mixed> $identitySource */
    public function __construct(?callable $identitySource = null)
    {
        $this->identitySource = Closure::fromCallable(
            $identitySource ?? static fn (): ?array => (new AuthenticatedUser())->current()
        );
    }

    /** Require an authenticated active actor holding $permission (superadmin retains platform bypass). */
    public function requireActor(string $permission): array
    {
        $actor = ($this->identitySource)();
        if (!is_array($actor) || empty($actor['id'])) {
            throw new RuntimeException('Please sign in to continue.', 401);
        }
        if (($actor['status'] ?? 'active') !== 'active') {
            throw new RuntimeException('Account is inactive.', 403);
        }

        $role = (string) ($actor['role'] ?? '');
        if (!in_array($role, self::ROLES_MAIN, true)) {
            throw new RuntimeException('Archive governance access is not permitted for your role.', 403);
        }

        $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
        if ($tenantId < 1 || $tenantId !== CurrentTenant::id()) {
            throw new DomainException('Archive tenant access forbidden.', 403);
        }

        $permissions = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];
        if ($role !== 'superadmin' && !in_array($permission, $permissions, true)) {
            throw new RuntimeException('Permission required: ' . $permission, 403);
        }

        return $actor;
    }

    /** Derive deterministic module & unit visibility scope from actor role + grants. */
    public function allowedScope(array $actor): array
    {
        $role = (string) ($actor['role'] ?? '');
        $permissions = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];
        $isSuper = $role === 'superadmin';

        [$modules, $units, $readOnly] = match ($role) {
            'superadmin' => [
                ['clinic', 'guidance', 'facilities', 'teams', 'admin'],
                ['clinic', 'guidance', 'facilities', 'admin'],
                false,
            ],
            'audit_reader' => [
                ['clinic', 'guidance', 'facilities', 'teams', 'admin'],
                ['clinic', 'guidance', 'facilities', 'admin'],
                true,
            ],
            'clinic_admin' => [
                ['clinic', 'teams'],
                ['clinic'],
                false,
            ],
            'guidance_admin', 'guidance_supervisor' => [
                ['guidance', 'teams'],
                ['guidance'],
                false,
            ],
            'bmg_admin' => [
                ['facilities', 'teams'],
                ['facilities'],
                false,
            ],
            default => [[], [], true],
        };

        return [
            'modules'      => $modules,
            'units'        => $units,
            'read_only'    => $readOnly,
            'can_manage'   => !$readOnly && ($isSuper || in_array('archive.manage', $permissions, true)),
            'can_policies' => !$readOnly && ($isSuper || in_array('archive.policies.manage', $permissions, true)),
            'can_holds'    => !$readOnly && ($isSuper || in_array('archive.legal_hold.manage', $permissions, true)),
        ];
    }

    /** Fail closed on any cross-department record mutation, hold placement, or read. */
    public function assertModuleUnitAllowed(array $actor, string $module, ?string $unit = null): void
    {
        $scope = $this->allowedScope($actor);
        if (!in_array($module, $scope['modules'], true)) {
            throw new RuntimeException('You do not have archive access for this module.', 403);
        }

        if ($module === 'teams' && $unit !== null && !in_array($unit, $scope['units'], true)) {
            throw new RuntimeException('You do not have archive access for that department team.', 403);
        }

        if ($module !== 'teams' && $module !== 'admin' && $unit !== null && !in_array($unit, $scope['units'], true)) {
            throw new RuntimeException('You do not have archive access for that unit.', 403);
        }
    }

    /** Apply strict SQL scope clauses so cross-department rows never leave the query. */
    public function applyScopeFilter(BaseBuilder $builder, array $actor, string $alias = ''): void
    {
        $scope = $this->allowedScope($actor);
        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';

        if ($scope['modules'] === []) {
            $builder->where('1 = 0', null, false);
            return;
        }

        if (($actor['role'] ?? '') === 'superadmin' || ($actor['role'] ?? '') === 'audit_reader') {
            return;
        }

        $nonTeamModules = array_values(array_diff($scope['modules'], ['teams']));
        $builder->groupStart();
        $hasClause = false;
        if ($nonTeamModules !== []) {
            $builder->whereIn($prefix . 'module', $nonTeamModules);
            $hasClause = true;
        }
        if (in_array('teams', $scope['modules'], true) && $scope['units'] !== []) {
            $method = $hasClause ? 'orGroupStart' : 'groupStart';
            $builder->{$method}()
                ->where($prefix . 'module', 'teams')
                ->whereIn($prefix . 'unit', $scope['units'])
                ->groupEnd();
        }
        $builder->groupEnd();
    }
}

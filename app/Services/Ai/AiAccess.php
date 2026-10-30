<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\AuthenticatedUser;
use Throwable;

final class AiAccess
{
    public static function current(string $permission, string $unit = 'clinic'): array
    {
        try {
            $actor = (new AuthenticatedUser())->current();
        } catch (Throwable) {
            throw new AiFailure('authentication_required', 401);
        }

        return self::assertActor($actor, self::positiveId($actor['tenant_id'] ?? null) ?? 0, $permission, $unit);
    }

    /** The caller supplies fresh authority; this path never reads or writes a browser session. */
    public static function assertActor(?array $actor, int $tenantId, string $permission, string $unit = 'clinic'): array
    {
        if ($actor === null || ($actor['logged_in'] ?? null) !== true
            || self::positiveId($actor['id'] ?? null) === null
            || self::positiveId($actor['tenant_id'] ?? null) === null) {
            throw new AiFailure('authentication_required', 401);
        }
        if ($tenantId < 1 || self::positiveId($actor['tenant_id']) !== $tenantId) {
            throw new AiFailure('access_denied', 403);
        }

        $configuration = in_array($permission, ['ai.configuration.read', 'ai.configuration.write', 'ai.configuration.test'], true);
        $insight = in_array($permission, ['ai.insights.read', 'ai.insights.generate', 'ai.insights.review', 'ai.insights.approve'], true);
        if ($insight) { $actor = AiGrantStore::mergeActor($actor, $unit); }
        $permissions = $actor['permissions'] ?? null;
        if ((!$configuration && !$insight) || !is_array($permissions) || !array_is_list($permissions)
            || count(array_filter($permissions, 'is_string')) !== count($permissions)
            || (!in_array($permission, $permissions, true) && !($configuration && in_array('rbac.manage', $permissions, true)))
            || ($configuration && (!in_array($unit, ['main', 'clinic'], true) || ($actor['role'] ?? null) !== 'superadmin'))
            || ($insight && !in_array($actor['role'] ?? null, AiGrantStore::ROLES[$unit] ?? [], true))) {
            throw new AiFailure('access_denied', 403);
        }
        if (($insight && $permission !== 'ai.insights.read' && !in_array('ai.insights.read', $permissions, true))
            || ($permission === 'ai.insights.generate' && !in_array(match ($unit) {
                'clinic' => 'clinic.encounters.read',
                'guidance' => 'guidance.cases.read_any',
                'bmg' => 'facilities.read',
                default => '',
            }, $permissions, true))) {
            throw new AiFailure('access_denied', 403);
        }

        return $actor;
    }

    private static function positiveId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}

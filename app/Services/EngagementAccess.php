<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Api\ApiContext;
use RuntimeException;

/** Shared authority for published campus content, independent of cached session roles. */
final class EngagementAccess
{
    public static function actor(?string $permission = null, ?string $unit = null): array
    {
        // The mobile API supplies an already-validated bearer identity instead of a session.
        try {
            $actor = ApiContext::identity();
        } catch (RuntimeException) {
            $actor = (new AuthenticatedUser())->current();
        }
        if ($actor === null) { throw new RuntimeException('Please sign in to continue.', 401); }
        if ($unit !== null && (!in_array($unit, ['clinic', 'guidance'], true)
            || !PortalContext::isRolePermitted($actor['role'], $unit))) {
            throw new RuntimeException('Department access forbidden.', 403);
        }
        if ($permission !== null && $actor['role'] !== 'superadmin'
            && !in_array($permission, $actor['permissions'], true)) {
            throw new RuntimeException('You do not have permission to manage this content.', 403);
        }
        return $actor;
    }
}

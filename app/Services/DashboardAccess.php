<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * The combined-data gate for the legacy clinic and guidance dashboards.
 *
 * A dashboard renders several datasets at once, so it needs the permission for each
 * of them rather than only the permission of its headline panel. Both the route filter
 * and the queue-count service read the list from here, so the two can no longer drift.
 */
final class DashboardAccess
{
    /**
     * @return list<string>
     */
    public static function permissions(string $unit): array
    {
        return match ($unit) {
            'clinic' => ['clinic.queue.read', 'clinic.encounters.read', 'clinic.appointments.read', 'clinic.inventory.read'],
            'guidance' => ['guidance.cases.read', 'guidance.queue.read', 'referrals.read'],
            default => throw new RuntimeException('Dashboard not found.', 404),
        };
    }

    /**
     * Permissions the actor lacks for this dashboard.
     *
     * @return list<string>
     */
    public static function missing(array $actor, string $unit): array
    {
        if (($actor['role'] ?? '') === 'superadmin') {
            return [];
        }
        $granted = $actor['permissions'] ?? [];

        return array_values(array_filter(
            self::permissions($unit),
            static fn (string $permission): bool => !in_array($permission, $granted, true)
        ));
    }
}

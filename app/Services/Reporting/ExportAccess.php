<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Services\AuthenticatedUser;
use RuntimeException;

final class ExportAccess
{
    public static function actor(bool $identified = false): array
    {
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) { throw new RuntimeException('Please sign in to continue.', 401); }
        self::assertAllowed($actor, $identified);
        return $actor;
    }

    /** Actual operational units, never inferred from a client-supplied role or unit. */
    public static function allowedUnits(array $actor): array
    {
        self::assertAllowed($actor);
        return match ($actor['role']) {
            'clinic_admin' => ['clinic'],
            'guidance_admin' => ['guidance'],
            'bmg_admin' => ['bmg'],
            default => ['clinic', 'guidance', 'bmg'],
        };
    }

    public static function selectUnits(array $actor, mixed $unit): array
    {
        $allowed = self::allowedUnits($actor);
        if (!is_string($unit) || !in_array($unit, ['all', 'clinic', 'guidance', 'bmg'], true)) {
            throw new RuntimeException('Select a valid export unit: clinic, guidance, bmg, or all.', 422);
        }
        if ($unit === 'all') {
            if (count($allowed) !== 3) { throw new RuntimeException('Cross-department export access forbidden.', 403); }
            return $allowed;
        }
        if (!in_array($unit, $allowed, true)) {
            throw new RuntimeException('Export access to that department is forbidden.', 403);
        }
        return [$unit];
    }

    public static function assertAllowed(array $actor, bool $identified = false): void
    {
        $roles = $identified ? ['superadmin', 'guidance_admin', 'guidance_supervisor']
            : ['superadmin', 'audit_reader', 'report_viewer', 'clinic_admin', 'guidance_admin', 'bmg_admin'];
        $read = $identified ? 'guidance.responses.read' : 'reports.read';
        if (!in_array($actor['role'] ?? null, $roles, true)) {
            throw new RuntimeException('Export access forbidden.', 403);
        }
        // Preserve the existing explicit superadmin bypass, never a cached session grant.
        if ($actor['role'] !== 'superadmin') {
            foreach ([$read, 'reports.export'] as $permission) {
                if (!in_array($permission, $actor['permissions'] ?? [], true)) {
                    throw new RuntimeException('Export access forbidden.', 403);
                }
            }
        }
    }
}

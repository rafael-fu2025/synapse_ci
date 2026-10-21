<?php

declare(strict_types=1);

namespace App\Services;

final class CurrentTenant
{
    private static ?int $id = null;

    /**
     * Get the active tenant ID for this execution context.
     * Prioritizes session state (authenticated user), then environment variable, defaulting to 1.
     */
    public static function id(): int
    {
        if (self::$id !== null) {
            return self::$id;
        }

        // 1. Session context (if available)
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['tenant_id']) && (int) $_SESSION['tenant_id'] > 0) {
            return (int) $_SESSION['tenant_id'];
        }

        // 2. Environment override (CLI workers, cron, sandbox)
        $env = getenv('SYNAPSE_TENANT_ID');
        if ($env !== false && $env !== '') {
            return (int) $env;
        }

        // 3. Default to Foundation University (Tenant 1)
        return 1;
    }

    /**
     * Explicitly set the active tenant ID (for workers or tests).
     */
    public static function set(?int $id): void
    {
        self::$id = $id;
    }

    /**
     * Reset cached tenant ID.
     */
    public static function reset(): void
    {
        self::$id = null;
    }
}

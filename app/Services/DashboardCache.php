<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Scheduling\SchedulingClock;
use Closure;
use Throwable;

/**
 * Short-lived read-through cache for the page dashboards' aggregated datasets.
 *
 * Every key is namespaced by tenant and by the Manila business date, so a cached
 * payload can never cross a tenant boundary or spill into the next day's
 * figures. Callers append a permission discriminator to the scope whenever the
 * dataset depends on who is asking (the guidance dashboard scopes its case
 * counts to the counsellor unless the actor holds oversight).
 *
 * The live queue-count endpoint is deliberately never cached: it backs the
 * polling board and must reflect the queue in real time.
 */
final class DashboardCache
{
    public const TTL = 60;

    private const PREFIX = 'dashboard.';

    private static ?bool $enabled = null;

    /**
     * @param Closure(): mixed $producer
     */
    public static function remember(string $scope, int $ttl, Closure $producer): mixed
    {
        if (! self::enabled()) {
            return $producer();
        }

        $key = self::key($scope);
        try {
            $cached = cache()->get($key);
            if (is_array($cached) && array_key_exists('value', $cached)) {
                return $cached['value'];
            }
        } catch (Throwable $e) {
            // A cache read failure must never take a dashboard down.
        }

        $value = $producer();

        try {
            cache()->save($key, ['value' => $value], $ttl);
        } catch (Throwable $e) {
            // Neither must a write failure; the dashboard already has its answer.
        }

        return $value;
    }

    /** Explicit invalidation hook for services that mutate dashboard-scoped state. */
    public static function forget(string $scope): void
    {
        if (! self::enabled()) {
            return;
        }
        try {
            cache()->delete(self::key($scope));
        } catch (Throwable $e) {
            // Invalidation is best-effort; the entry expires on its own TTL.
        }
    }

    /**
     * The composed cache key, exposed so its namespacing can be asserted
     * without a live store.
     *
     * The separator is a dot because CI4's cache handlers reject the reserved
     * characters declared in Config\Cache ($reservedCharacters).
     */
    public static function key(string $scope): string
    {
        return self::PREFIX . CurrentTenant::id() . '.' . SchedulingClock::today() . '.' . $scope;
    }

    /**
     * Override the enabled state; null restores the environment default.
     *
     * The persistent file cache outlives a single PHPUnit process, so serving
     * it during tests would hand one fixture's payload to the next test's
     * differently seeded database.
     */
    public static function enable(?bool $enabled = true): void
    {
        self::$enabled = $enabled;
    }

    private static function enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        return ! (defined('ENVIRONMENT') && ENVIRONMENT === 'testing');
    }
}

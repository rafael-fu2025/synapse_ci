<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Services\AuthenticatedUser;
use App\Services\PortalContext;
use App\Services\Realtime\Channel;
use App\Services\Realtime\Publisher;
use Closure;
use Config\Database;
use Config\Realtime;
use Throwable;

/** Metadata-only hints to users who can currently read the department board. */
final class QueueInvalidation
{
    private const BUDGET_SECONDS = 1.0;
    private const PUBLISH_TIMEOUT = 0.25;
    private const MAX_ATTEMPTS = 4;
    private const MAX_CANDIDATES = 64;

    private readonly Closure $candidates;
    private readonly Closure $identity;
    private readonly Closure $send;
    private readonly bool $enabled;
    private readonly Closure $clock;

    public function __construct(?callable $candidates = null, ?callable $identity = null, ?callable $send = null, ?bool $enabled = null, ?callable $clock = null)
    {
        $this->enabled = $enabled ?? config(Realtime::class)->enabled;
        $this->clock = Closure::fromCallable($clock ?? static fn (): float => hrtime(true) / 1e9);
        $this->candidates = Closure::fromCallable($candidates ?? static fn (int $tenant, array $roles): array => Database::connect()
            ->table('users')->select('id')->where('tenant_id', $tenant)->where('status', 'active')
            ->whereIn('role', $roles)->where('deleted_at', null)->limit(self::MAX_CANDIDATES)->get()->getResultArray());
        $this->identity = Closure::fromCallable($identity ?? [new AuthenticatedUser(), 'forIdentity']);
        if ($send === null) {
            // Queue hints must not inherit a multi-second timeout or mutate shared config.
            $config = clone config(Realtime::class);
            $config->publishTimeout = min($config->publishTimeout, self::PUBLISH_TIMEOUT);
            $send = [new Publisher($config), 'publish'];
        }
        $this->send = Closure::fromCallable($send);
    }

    public function publish(int $tenant, string $unit, string $date): void
    {
        if (!$this->enabled || $tenant < 1 || !in_array($unit, ['clinic', 'guidance'], true)
            || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $date)) {
            return;
        }
        try {
            $deadline = ($this->clock)() + self::BUDGET_SECONDS;
            $seen = [];
            $attempts = 0;
            $checked = 0;
            foreach (($this->candidates)($tenant, PortalContext::allowedRoles($unit)) as $candidate) {
                if (++$checked > self::MAX_CANDIDATES || $attempts >= self::MAX_ATTEMPTS
                    || ($this->clock)() + self::PUBLISH_TIMEOUT > $deadline) break;
                $id = (int) ($candidate['id'] ?? 0);
                if ($id < 1 || isset($seen[$id])) continue;
                $seen[$id] = true;
                try {
                    $actor = ($this->identity)($id, $tenant);
                    if ($actor === null || ($actor['logged_in'] ?? false) !== true
                        || ($actor['id'] ?? null) !== $id || ($actor['tenant_id'] ?? null) !== $tenant
                        || !PortalContext::isRolePermitted((string) ($actor['role'] ?? ''), $unit)
                        || ($actor['role'] !== 'superadmin' && !in_array($unit . '.queue.read', $actor['permissions'] ?? [], true))) {
                        continue;
                    }
                } catch (Throwable) {
                    // One failed identity refresh is not evidence of transport failure.
                    continue;
                }
                // Reserve a full transport timeout after audience/authorization work.
                if (($this->clock)() + self::PUBLISH_TIMEOUT > $deadline) break;
                ++$attempts;
                if (($this->send)(Channel::user($tenant, $id), 'queue.invalidated', ['unit' => $unit, 'date' => $date]) === false) break;
            }
        } catch (Throwable) {
            // Stop fanout on transport exceptions. Polling reconciles failures/budget omissions.
        }
    }
}

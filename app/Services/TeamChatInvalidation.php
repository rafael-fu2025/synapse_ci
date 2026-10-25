<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Realtime\Channel;
use App\Services\Realtime\Publisher;
use Closure;
use Config\Database;
use Config\Realtime;
use Throwable;

/** Hints only; HTTP remains authoritative for chat contents and read state. */
final class TeamChatInvalidation
{
    private readonly Closure $candidates;
    private readonly Closure $identity;
    private readonly Closure $send;
    private readonly bool $enabled;
    private readonly Closure $clock;
    private const BUDGET_SECONDS = 1.0;
    private const PUBLISH_TIMEOUT = 0.25;
    private const MAX_ATTEMPTS = 4;

    public function __construct(?callable $candidates = null, ?callable $identity = null, ?callable $send = null, ?bool $enabled = null, ?callable $clock = null)
    {
        $this->enabled = $enabled ?? config(Realtime::class)->enabled;
        $this->candidates = Closure::fromCallable($candidates ?? static fn (int $tenant, array $roles): array => Database::connect()
            ->table('users')->select('id')->where('tenant_id', $tenant)->where('status', 'active')
            ->where('deleted_at', null)->whereIn('role', $roles)->get()->getResultArray());
        $this->identity = Closure::fromCallable($identity ?? [new AuthenticatedUser(), 'forIdentity']);
        if ($send === null) {
            $config = clone config(Realtime::class);
            $config->publishTimeout = self::PUBLISH_TIMEOUT;
            $send = [new Publisher($config), 'publish'];
        }
        $this->send = Closure::fromCallable($send);
        $this->clock = Closure::fromCallable($clock ?? static fn (): float => hrtime(true) / 1e9);
    }

    public function publish(int $tenant, string $unit, ?int $onlyUser = null): void
    {
        if (!$this->enabled || $tenant < 1 || !in_array($unit, ['clinic', 'guidance', 'facilities'], true)
            || ($onlyUser !== null && $onlyUser < 1)) return;
        $portal = $unit === 'facilities' ? PortalContext::PORTAL_BMG : $unit;
        try {
            $deadline = ($this->clock)() + self::BUDGET_SECONDS;
            $attempts = 0;
            $seen = [];
            $candidates = $onlyUser === null
                ? ($this->candidates)($tenant, PortalContext::allowedRoles($portal)) : [['id' => $onlyUser]];
            foreach ($candidates as $candidate) {
                if ($attempts >= self::MAX_ATTEMPTS || ($this->clock)() >= $deadline) break;
                $id = (int) ($candidate['id'] ?? 0);
                if ($id < 1 || isset($seen[$id])) continue;
                $seen[$id] = true;
                try {
                    $actor = ($this->identity)($id, $tenant);
                    // Match TeamTaskService membership and the chat routes' portal_access policy.
                    if ($actor === null || ($actor['logged_in'] ?? false) !== true
                        || ($actor['id'] ?? null) !== $id || ($actor['tenant_id'] ?? null) !== $tenant
                        || !PortalContext::isRolePermitted((string) ($actor['role'] ?? ''), $portal)) continue;
                } catch (Throwable) {
                    // An unavailable identity is not a transport failure.
                    continue;
                }
                // Reserve a full transport timeout; the budget is cooperative around DB calls.
                if (($this->clock)() + self::PUBLISH_TIMEOUT > $deadline) break;
                $attempts++;
                if (($this->send)(Channel::user($tenant, $id), 'team.chat.changed', ['unit' => $unit]) === false) break;
            }
        } catch (Throwable) {
            // Periodic HTTP reconciliation covers missed hints.
        }
    }
}

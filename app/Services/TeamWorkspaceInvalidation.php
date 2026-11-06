<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Realtime\Channel;
use App\Services\Realtime\Publisher;
use Closure;
use Config\Database;
use Config\Realtime;
use Throwable;

/** Content-free hints only; HTTP remains authoritative for task/calendar state. */
final class TeamWorkspaceInvalidation
{
    public const TASKS = 'team.tasks.changed';
    public const CALENDAR = 'team.calendar.changed';
    private const EVENTS = [self::TASKS, self::CALENDAR];
    private const BUDGET_SECONDS = 1.0;
    private const PUBLISH_TIMEOUT = 0.25;
    private const MAX_ATTEMPTS = 4;

    private readonly Closure $candidates;
    private readonly Closure $identity;
    private readonly Closure $send;
    private readonly Closure $clock;
    private readonly bool $enabled;

    public function __construct(
        ?callable $candidates = null,
        ?callable $identity = null,
        ?callable $send = null,
        ?bool $enabled = null,
        ?callable $clock = null
    ) {
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

    public function publish(int $tenant, string $unit, string $event): void
    {
        if (!$this->enabled || $tenant < 1 || !in_array($unit, TeamAccess::UNITS, true)
            || !in_array($event, self::EVENTS, true)) {
            return;
        }
        try {
            $deadline = ($this->clock)() + self::BUDGET_SECONDS;
            $attempts = 0;
            $seen = [];
            foreach (($this->candidates)($tenant, TeamAccess::rolesFor($unit)) as $candidate) {
                if ($attempts >= self::MAX_ATTEMPTS || ($this->clock)() >= $deadline) {
                    break;
                }
                $id = (int) ($candidate['id'] ?? 0);
                if ($id < 1 || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                try {
                    $actor = ($this->identity)($id, $tenant);
                    if ($actor === null || ($actor['logged_in'] ?? false) !== true
                        || (int) ($actor['id'] ?? 0) !== $id || (int) ($actor['tenant_id'] ?? 0) !== $tenant
                        || !in_array($actor['role'] ?? null, TeamAccess::rolesFor($unit), true)) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }
                if (($this->clock)() + self::PUBLISH_TIMEOUT > $deadline) {
                    break;
                }
                $attempts++;
                if (($this->send)(Channel::user($tenant, $id), $event, ['unit' => $unit]) === false) {
                    break;
                }
            }
        } catch (Throwable) {
            // Periodic HTTP reconciliation covers missed hints.
        }
    }
}

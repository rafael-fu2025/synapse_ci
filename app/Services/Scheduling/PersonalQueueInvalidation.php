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

/** A person-owned hint, never a department broadcast or a ticket payload. */
final class PersonalQueueInvalidation
{
    public const QUEUE_EVENT = 'portal.queue.changed';
    public const APPOINTMENTS_EVENT = 'portal.appointments.changed';
    private readonly Closure $candidates;
    private readonly Closure $identity;
    private readonly Closure $send;
    private readonly Closure $clock;
    private readonly bool $enabled;

    public function __construct(?callable $candidates = null, ?callable $identity = null, ?callable $send = null, ?bool $enabled = null, ?callable $clock = null)
    {
        $this->enabled = $enabled ?? config(Realtime::class)->enabled;
        $this->candidates = Closure::fromCallable($candidates ?? static fn (int $tenant, int $person): array => Database::connect()
            ->table('users')->select('id')->where('tenant_id', $tenant)->where('person_id', $person)
            ->where('status', 'active')->where('deleted_at', null)->limit(4)->get()->getResultArray());
        $this->identity = Closure::fromCallable($identity ?? [new AuthenticatedUser(), 'forIdentity']);
        if ($send === null) {
            $config = clone config(Realtime::class);
            $config->publishTimeout = min($config->publishTimeout, 0.25);
            $send = [new Publisher($config), 'publish'];
        }
        $this->send = Closure::fromCallable($send);
        $this->clock = Closure::fromCallable($clock ?? static fn (): float => hrtime(true) / 1e9);
    }

    /** Only called with metadata captured by the transaction owner after its commit. */
    public function publish(int $tenant, int $person, string $event = self::QUEUE_EVENT): void
    {
        if (!$this->enabled || $tenant < 1 || $person < 1) return;
        if (!in_array($event, [self::QUEUE_EVENT, self::APPOINTMENTS_EVENT], true)) return;
        try {
            $deadline = ($this->clock)() + 1.0;
            $seen = [];
            $checked = 0;
            foreach (($this->candidates)($tenant, $person) as $candidate) {
                if (++$checked > 4 || ($this->clock)() + 0.25 > $deadline) break;
                $id = (int) ($candidate['id'] ?? 0);
                if ($id < 1 || isset($seen[$id])) continue;
                $seen[$id] = true;
                try {
                    $actor = ($this->identity)($id, $tenant);
                    if ($actor === null || ($actor['logged_in'] ?? false) !== true
                        || ($actor['id'] ?? null) !== $id || ($actor['tenant_id'] ?? null) !== $tenant
                        || ($actor['person_id'] ?? null) !== $person
                        || !PortalContext::isRolePermitted($actor['role'] ?? '', PortalContext::PORTAL_MAIN)
                        || !in_array('portal.appointments.read', $actor['permissions'] ?? [], true)
                        || !in_array('portal.records.read', $actor['permissions'] ?? [], true)) continue;
                } catch (Throwable) {
                    continue;
                }
                if (($this->clock)() + 0.25 > $deadline) break;
                if (($this->send)(Channel::user($tenant, $id), $event, []) === false) break;
            }
        } catch (Throwable) {
            // Reconciliation covers failures; committed queue writes must still succeed.
        }
    }
}

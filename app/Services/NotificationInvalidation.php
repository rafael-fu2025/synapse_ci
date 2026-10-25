<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Realtime\Channel;
use App\Services\Realtime\Publisher;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Realtime;
use Throwable;

/** Call only after the owning transaction commits; never retain hints across transactions. */
final class NotificationInvalidation
{
    private const BUDGET_SECONDS = 1.0;
    private const MAX_ATTEMPTS = 4;
    private const PUBLISH_TIMEOUT = 0.25;

    private readonly Closure $recipients;
    private readonly Closure $permitted;
    private readonly Closure $send;
    private readonly bool $enabled;
    private readonly Closure $clock;
    private ?float $deadline = null;
    private int $attempts = 0;
    private bool $transportFailed = false;

    public function __construct(
        private readonly ?BaseConnection $db = null,
        ?callable $recipients = null,
        ?callable $permitted = null,
        ?callable $send = null,
        ?bool $enabled = null,
        ?callable $clock = null,
    ) {
        $this->enabled = $enabled ?? config(Realtime::class)->enabled;
        $this->recipients = Closure::fromCallable($recipients ?? function (int $tenant, array $ids): array {
            return ($this->db ?? Database::connect())->table('users u')->distinct()->select('u.id,u.tenant_id')
                ->join('notifications n', 'n.tenant_id=u.tenant_id AND (n.recipient_user_id=u.id OR n.recipient_person_id=u.person_id)')
                ->where('u.tenant_id', $tenant)->whereIn('n.id', $ids)->get()->getResultArray();
        });
        $this->permitted = Closure::fromCallable($permitted ?? [new NotificationService(), 'canReceiveInvalidation']);
        if ($send === null) {
            // A private timeout cap must not mutate the shared realtime configuration.
            $config = clone config(Realtime::class);
            $config->publishTimeout = min($config->publishTimeout, self::PUBLISH_TIMEOUT);
            $send = [new Publisher($config), 'publish'];
        }
        $this->send = Closure::fromCallable($send);
        $this->clock = Closure::fromCallable($clock ?? static fn (): float => hrtime(true) / 1e9);
    }

    /** IDs must come from successful writes in the transaction that just committed. */
    public function publishCommitted(int $tenant, array $notificationIds): void
    {
        if (!$this->enabled || $tenant < 1 || $this->transportFailed) return;
        try {
            $ids = array_values(array_unique(array_filter($notificationIds, static fn ($id) => is_int($id) && $id > 0)));
            $db = $this->db ?? Database::connect();
            // Nested commits are not durable. A parent must call again after its outer commit.
            if ($ids === [] || $db->transDepth !== 0 || !$db->transStatus()) return;
            // Share the budget across every tenant batch handled by this request's publisher.
            $this->deadline ??= ($this->clock)() + self::BUDGET_SECONDS;
            if (!$this->hasBudget()) return;
            $seen = [];
            foreach (($this->recipients)($tenant, $ids) as $recipient) {
                if (!$this->hasBudget()) return;
                $id = (int) ($recipient['id'] ?? 0);
                if ($id < 1 || (int) ($recipient['tenant_id'] ?? 0) !== $tenant || isset($seen[$id])) continue;
                $seen[$id] = true;
                try {
                    $permitted = ($this->permitted)($tenant, $id, $ids);
                } catch (Throwable) {
                    // One identity failure need not prevent other authorized recipients.
                    continue;
                }
                if (!$permitted) continue;
                // Reserve one capped transport attempt; authorization may have used the budget.
                if (!$this->hasBudget()) return;
                ++$this->attempts;
                try {
                    if (($this->send)(Channel::user($tenant, $id), 'notifications.changed', []) !== false) continue;
                } catch (Throwable) {
                    // A transport outage is request-wide, not a reason to retry every recipient.
                }
                $this->transportFailed = true;
                return;
            }
        } catch (Throwable) {
            // Reconciliation polling covers unavailable audience storage and transport.
        }
    }

    private function hasBudget(): bool
    {
        return $this->attempts < self::MAX_ATTEMPTS
            && ($this->clock)() + self::PUBLISH_TIMEOUT <= $this->deadline;
    }
}

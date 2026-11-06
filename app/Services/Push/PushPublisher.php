<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Services\NotificationService;
use CodeIgniter\Database\BaseConnection;
use Config\Push;
use Database;
use Throwable;

/**
 * Publishes a committed notification to the recipient's devices.
 *
 * The contract mirrors NotificationInvalidation exactly: hints are published only
 * after the owning transaction commits, every recipient is authorized against the
 * live inbox authority, one request is budget-bounded, and no transport failure
 * can ever fail the business write that produced the notification.
 */
final class PushPublisher
{
    private const BUDGET_SECONDS = 1.0;
    private const MAX_ATTEMPTS = 8;
    private const PUBLISH_TIMEOUT = 0.25;

    /** Only notices that have a mobile surface are delivered as a push. */
    private const MESSAGES = [
        'appointment_confirmed' => [
            'title' => 'Appointment confirmed',
            'body' => 'Your appointment has been confirmed. Open the app to see the details.',
        ],
        'announcement_published' => [
            'title' => 'New announcement',
            'body' => 'A campus announcement is available in your portal.',
        ],
        'survey_published' => [
            'title' => 'New survey',
            'body' => 'An identified Guidance survey is available in your portal.',
        ],
    ];

    private readonly \Closure $recipients;
    private readonly \Closure $permitted;
    private readonly \Closure $send;
    private readonly bool $enabled;
    private readonly \Closure $clock;
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
        $this->enabled = $enabled ?? config(Push::class)->isConfigured();
        $this->recipients = \Closure::fromCallable($recipients ?? function (int $tenant, array $ids): array {
            return ($this->db ?? Database::connect())->table('notifications')
                ->select('id,tenant_id,recipient_user_id,type,appointment_id,announcement_id,survey_id')
                ->where('tenant_id', $tenant)
                ->whereIn('id', $ids)
                ->get()->getResultArray();
        });
        $this->permitted = \Closure::fromCallable($permitted ?? [new NotificationService(), 'canReceiveInvalidation']);
        $this->send = \Closure::fromCallable($send ?? [new PushTransport(), 'send']);
        $this->clock = \Closure::fromCallable($clock ?? static fn (): float => hrtime(true) / 1e9);
    }

    /**
     * Publish the notifications created by a transaction that has just committed.
     *
     * @param list<int> $notificationIds
     */
    public function publishCommitted(int $tenant, array $notificationIds): void
    {
        if (!$this->enabled || $tenant < 1 || $this->transportFailed) {
            return;
        }
        try {
            $ids = array_values(array_unique(array_filter($notificationIds, static fn ($id) => is_int($id) && $id > 0)));
            $db = $this->db ?? Database::connect();
            // Nested commits are not durable. A parent must call again after its outer commit.
            if ($ids === [] || $db->transDepth !== 0 || !$db->transStatus()) {
                return;
            }
            $this->deadline ??= ($this->clock)() + self::BUDGET_SECONDS;
            if (!$this->hasBudget()) {
                return;
            }
            foreach (($this->recipients)($tenant, $ids) as $recipient) {
                if (!$this->hasBudget()) {
                    return;
                }
                $user = (int) ($recipient['recipient_user_id'] ?? 0);
                if ($user < 1 || (int) ($recipient['tenant_id'] ?? 0) !== $tenant) {
                    continue;
                }
                $message = $this->message($recipient);
                if ($message === null) {
                    // No mobile surface for this notice kind.
                    continue;
                }
                try {
                    $permitted = ($this->permitted)($tenant, $user, [$recipient['id']]);
                } catch (Throwable) {
                    continue;
                }
                if (!$permitted) {
                    continue;
                }
                if (!$this->hasBudget()) {
                    return;
                }
                ++$this->attempts;
                try {
                    if (($this->send)($tenant, $user, $message) !== false) {
                        continue;
                    }
                } catch (Throwable) {
                    // A transport outage is request-wide, not a reason to retry every recipient.
                }
                $this->transportFailed = true;
                return;
            }
        } catch (Throwable) {
            // The durable inbox row remains available through reconciliation polling.
        }
    }

    /**
     * The push payload for one notification, or null when the notice has no
     * mobile surface.
     *
     * @return array{title: string, body: string, data: array<string, string>}|null
     */
    private function message(array $recipient): ?array
    {
        $type = (string) ($recipient['type'] ?? '');
        $template = self::MESSAGES[$type] ?? null;
        if ($template === null) {
            return null;
        }
        $id = $this->targetId($type, $recipient);
        if ($id === null) {
            return null;
        }
        return $template + ['data' => ['type' => $type, 'id' => (string) $id]];
    }

    private function targetId(string $type, array $recipient): ?int
    {
        $column = match ($type) {
            'appointment_confirmed' => 'appointment_id',
            'announcement_published' => 'announcement_id',
            'survey_published' => 'survey_id',
            default => null,
        };
        if ($column === null || !array_key_exists($column, $recipient)) {
            return null;
        }
        $id = filter_var($recipient[$column], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private function hasBudget(): bool
    {
        return $this->attempts < self::MAX_ATTEMPTS
            && ($this->clock)() + self::PUBLISH_TIMEOUT <= $this->deadline;
    }
}

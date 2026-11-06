<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Services\NotificationService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Push;
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
        'team_task_assigned' => ['title' => 'Team task update', 'body' => 'A team task needs your attention. Open Team Workspace for details.'],
        'team_task_unassigned' => ['title' => 'Team task update', 'body' => 'A team task assignment changed. Open Team Workspace for details.'],
        'team_task_updated' => ['title' => 'Team task update', 'body' => 'A team task changed. Open Team Workspace for details.'],
        'team_task_status' => ['title' => 'Team task update', 'body' => 'A team task status changed. Open Team Workspace for details.'],
        'team_task_commented' => ['title' => 'Team task update', 'body' => 'A team task has a new comment. Open Team Workspace for details.'],
        'team_event_added' => ['title' => 'Team calendar update', 'body' => 'A team calendar event changed. Open Team Workspace for details.'],
        'team_event_removed' => ['title' => 'Team calendar update', 'body' => 'A team calendar event changed. Open Team Workspace for details.'],
        'team_event_updated' => ['title' => 'Team calendar update', 'body' => 'A team calendar event changed. Open Team Workspace for details.'],
        'team_event_cancelled' => ['title' => 'Team calendar update', 'body' => 'A team calendar event changed. Open Team Workspace for details.'],
        'team_chat_mentioned' => ['title' => 'Team chat mention', 'body' => 'You were mentioned in Team Workspace chat. Open the app for details.'],
    ];

    private readonly \Closure $recipients;
    private readonly \Closure $permitted;
    private readonly \Closure $send;
    private readonly \Closure $sendTeam;
    private readonly \Closure $teamCapable;
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
        ?callable $teamCapable = null,
        ?callable $sendTeam = null,
    ) {
        $this->enabled = $enabled ?? config(Push::class)->isConfigured();
        $this->recipients = \Closure::fromCallable($recipients ?? function (int $tenant, array $ids): array {
            $db = $this->db ?? Database::connect();
            $present = $db->getFieldNames('notifications');
            $optional = array_values(array_intersect([
                'appointment_id', 'announcement_id', 'survey_id',
                'team_unit', 'team_event_id', 'team_task_id', 'chat_unit', 'chat_message_id',
            ], $present));
            $select = ['n.id', 'n.tenant_id', 'COALESCE(n.recipient_user_id,u.id) AS recipient_user_id', 'n.type'];
            foreach ($optional as $field) {
                $select[] = 'n.' . $field;
            }
            return $db->table('notifications n')->distinct()->select(implode(',', $select), false)
                ->join('users u', 'u.tenant_id=n.tenant_id AND u.person_id=n.recipient_person_id AND u.status=\'active\' AND u.deleted_at IS NULL', 'left')
                ->where('n.tenant_id', $tenant)->whereIn('n.id', $ids)->get()->getResultArray();
        });
        $this->permitted = \Closure::fromCallable($permitted ?? [new NotificationService(), 'canReceiveInvalidation']);
        $transport = new PushTransport();
        $this->send = \Closure::fromCallable($send ?? [$transport, 'send']);
        $this->sendTeam = \Closure::fromCallable($sendTeam ?? ($send !== null ? $send : [$transport, 'sendTeam']));
        // Older schemas have no capability columns, so PushTokenService fails closed.
        $this->teamCapable = \Closure::fromCallable($teamCapable ?? [$transport, 'teamCapable']);
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
                $team = $this->teamType((string) ($recipient['type'] ?? ''));
                if ($team) {
                    try {
                        if (!(bool) ($this->teamCapable)($tenant, $user)) {
                            continue;
                        }
                    } catch (Throwable) {
                        continue;
                    }
                }
                if (!$this->hasBudget()) {
                    return;
                }
                ++$this->attempts;
                try {
                    $sender = $team ? $this->sendTeam : $this->send;
                    if ($sender($tenant, $user, $message) !== false) {
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
        $data = ['type' => $type, 'id' => (string) $id];
        if ($this->teamType($type)) {
            $unit = $type === 'team_chat_mentioned' ? ($recipient['chat_unit'] ?? null) : ($recipient['team_unit'] ?? null);
            if (!in_array($unit, ['clinic', 'guidance', 'facilities'], true)) {
                return null;
            }
            $data['type'] = match (true) {
                str_starts_with($type, 'team_task_') => 'team_task',
                str_starts_with($type, 'team_event_') => 'team_event',
                default => 'team_chat_message',
            };
            $data['unit'] = $unit;
        }
        return $template + ['data' => $data];
    }

    private function targetId(string $type, array $recipient): ?int
    {
        $column = match (true) {
            $type === 'appointment_confirmed' => 'appointment_id',
            $type === 'announcement_published' => 'announcement_id',
            $type === 'survey_published' => 'survey_id',
            str_starts_with($type, 'team_task_') => 'team_task_id',
            str_starts_with($type, 'team_event_') => 'team_event_id',
            $type === 'team_chat_mentioned' => 'chat_message_id',
            default => null,
        };
        if ($column === null || !array_key_exists($column, $recipient)) {
            return null;
        }
        $id = filter_var($recipient[$column], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private function teamType(string $type): bool
    {
        return str_starts_with($type, 'team_task_') || str_starts_with($type, 'team_event_')
            || $type === 'team_chat_mentioned';
    }

    private function hasBudget(): bool
    {
        return $this->attempts < self::MAX_ATTEMPTS
            && ($this->clock)() + self::PUBLISH_TIMEOUT <= $this->deadline;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Teams-only producer. Requires the coordinated notifications Teams schema extension. */
final class TeamsNotifications
{
    private array $pending = [];

    public function __construct(private readonly BaseConnection $db) {}

    /** Called by the owning service only after its independent transaction commits. */
    public function publishCommitted(): void
    {
        if ($this->db->transDepth !== 0) return;
        $pending = $this->pending;
        $this->pending = [];
        try {
            foreach ($pending as $tenant => $ids) {
                (new NotificationInvalidation($this->db))->publishCommitted((int) $tenant, $ids);
            }
        } catch (\Throwable) {
            // Durable inbox rows remain available through reconciliation polling.
        }
    }

    /** One action per recipient; membership-only changes do not disturb retained attendees. */
    public static function eventPlan(?array $before, array $after): array
    {
        $old = $before['participant_ids'] ?? [];
        $new = $after['participant_ids'];
        $plan = [];
        if ($before === null) {
            foreach ($new as $id) $plan[(int) $id] = 'team_event_added';
            return $plan;
        }
        if ($before['status'] === 'cancelled') return [];
        if ($after['status'] === 'cancelled') {
            foreach (array_unique([...$old, $before['created_by_user_id']]) as $id) $plan[(int) $id] = 'team_event_cancelled';
            return $plan;
        }
        foreach (array_diff($old, $new) as $id) $plan[(int) $id] = 'team_event_removed';
        foreach (array_diff($new, $old) as $id) $plan[(int) $id] = 'team_event_added';
        $changed = false;
        foreach (['title', 'starts_at', 'ends_at', 'location'] as $field) {
            if ($before[$field] !== $after[$field]) $changed = true;
        }
        if ($changed) {
            foreach (array_intersect($old, $new) as $id) $plan[(int) $id] = 'team_event_updated';
        }
        if ($changed || $old !== $new) $plan[(int) $before['created_by_user_id']] ??= 'team_event_updated';
        return $plan;
    }

    /** Assignment wins over other edits, avoiding multiple alerts for one save. */
    public static function taskPlan(?array $before, array $after, bool $comment = false): array
    {
        $plan = [];
        $old = $before['assignee_user_id'] ?? null;
        $new = $after['assignee_user_id'];
        if ($comment) {
            foreach ([$after['created_by_user_id'], $new] as $id) {
                if ($id !== null) $plan[(int) $id] = 'team_task_commented';
            }
            return $plan;
        }
        if ($before === null || $old !== $new) {
            if ($old !== null) $plan[(int) $old] = 'team_task_unassigned';
            if ($new !== null) $plan[(int) $new] = 'team_task_assigned';
        }
        if ($before !== null) {
            $changed = false;
            foreach (['title', 'description', 'status', 'priority', 'due_date', 'checklist', 'assignee_user_id'] as $field) {
                if ($before[$field] !== $after[$field]) $changed = true;
            }
            if ($changed) {
                foreach ([$after['created_by_user_id'], $new] as $id) {
                    if ($id !== null) $plan[(int) $id] ??= $before['status'] !== $after['status']
                        ? 'team_task_status' : 'team_task_updated';
                }
            }
        }
        return $plan;
    }

    /** Inserts only, in the business transaction. Never publishes before commit. */
    public function enqueue(array $record, array $actor, array $plan, ?int $commentId = null): void
    {
        if ($this->db->transDepth < 1 || !$this->db->transStatus()
            || (int) $record['tenant_id'] !== (int) $actor['tenant_id']) {
            throw new RuntimeException('Teams notifications require the owning transaction.', 503);
        }
        $unit = $record['unit'];
        if (!in_array($unit, ['clinic', 'guidance', 'facilities'], true)) {
            throw new RuntimeException('Invalid notification department.', 503);
        }
        $roles = PortalContext::allowedRoles($unit === 'facilities' ? 'bmg' : $unit);
        ksort($plan, SORT_NUMERIC);
        foreach ($plan as $id => $type) {
            if ((int) $id === (int) $actor['id']) continue;
            // Lock in stable order and refresh the full identity, not merely a roster snapshot.
            $query = $this->db->table('users')->where('id', $id)->where('tenant_id', $record['tenant_id']);
            $user = $this->db->DBDriver === 'MySQLi'
                ? $this->db->query($query->getCompiledSelect() . ' FOR UPDATE')->getRowArray()
                : $query->get()->getRowArray();
            if ($user === null || $user['status'] !== 'active' || $user['deleted_at'] !== null
                || !in_array($user['role'], $roles, true)
                || (new AuthenticatedUser())->forIdentity((int) $id, (int) $record['tenant_id']) === null) continue;
            $event = str_starts_with($type, 'team_event_');
            $key = ['tenant_id' => $record['tenant_id'], 'recipient_user_id' => $id, 'type' => $type,
                'team_unit' => $unit, 'team_event_id' => $event ? $record['id'] : null,
                'team_task_id' => $event ? null : $record['id'], 'team_version' => $record['version'],
                'team_comment_id' => $commentId];
            // The owning event/task version write (or comment row) serializes this key.
            if ($this->db->table('notifications')->where($key)->countAllResults() !== 0) continue;
            if (!$this->db->table('notifications')->insert($key + ['recipient_person_id' => null,
                'appointment_id' => null, 'appointment_version' => null, 'referral_id' => null,
                'announcement_id' => null, 'survey_id' => null, 'read_at' => null,
                'created_at' => gmdate('Y-m-d H:i:s')])) {
                throw new RuntimeException('Unable to save Teams notification.', 503);
            }
            $this->pending[(int) $record['tenant_id']][] = (int) $this->db->insertID();
        }
    }
}

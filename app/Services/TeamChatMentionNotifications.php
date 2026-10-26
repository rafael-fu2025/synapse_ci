<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/** Metadata-only producer, scoped to one independently committed chat send. */
final class TeamChatMentionNotifications
{
    private array $pending = [];

    public function __construct(private readonly BaseConnection $db) {}

    /** Mentions have already passed the chat's range/name/member validation. */
    public function enqueue(array $message, array $actor, array $mentions): void
    {
        $ids = array_values(array_diff(array_unique(array_column($mentions, 'user_id')), [(int) $actor['id']]));
        if ($ids === []) return;
        if ($this->db->transDepth !== 1 || !$this->db->transStatus()
            || (int) $message['tenant_id'] !== (int) $actor['tenant_id']
            || !in_array($message['unit'], ['clinic', 'guidance', 'facilities'], true)) {
            throw new RuntimeException('Chat mentions require the owning transaction.', 503);
        }
        try {
            $fields = ['chat_unit', 'chat_message_id', 'workflow_event_id', 'team_unit', 'team_event_id',
                'team_task_id', 'team_version', 'team_comment_id'];
            if (!$this->db->tableExists('notifications')
                || array_diff($fields, $this->db->getFieldNames('notifications')) !== []) {
                throw new RuntimeException('Missing chat notification schema.');
            }
            sort($ids, SORT_NUMERIC);
            $roles = PortalContext::allowedRoles($message['unit'] === 'facilities' ? 'bmg' : $message['unit']);
            foreach ($ids as $id) {
                $query = $this->db->table('users')->where('tenant_id', $message['tenant_id'])->where('id', $id);
                $user = $this->db->DBDriver === 'MySQLi'
                    ? $this->db->query($query->getCompiledSelect() . ' FOR UPDATE')->getRowArray()
                    : $query->get()->getRowArray();
                if ($user === null || $user['status'] !== 'active' || $user['deleted_at'] !== null
                    || !in_array($user['role'], $roles, true)) continue;
                // Do not use AuthenticatedUser's default connection: this writer owns its DB.
                $tenant = $this->currentIdentity($this->db->table('tenants')->where('id', $message['tenant_id'])
                    ->where('is_active', 1)->where('deleted_at', null));
                if ($tenant === null) continue;
                if ($user['person_id'] === null) {
                    if ($user['role'] !== 'superadmin') continue;
                } elseif ($this->currentIdentity($this->db->table('people')->where('id', $user['person_id'])
                    ->where('tenant_id', $message['tenant_id'])->where('deleted_at', null)) === null) {
                    continue;
                }
                if (!$this->db->table('notifications')->insert([
                    'tenant_id' => $message['tenant_id'], 'recipient_user_id' => (int) $id,
                    'type' => 'team_chat_mentioned', 'chat_unit' => $message['unit'], 'chat_message_id' => $message['id'],
                    'recipient_person_id' => null, 'appointment_id' => null, 'appointment_version' => null,
                    'referral_id' => null, 'announcement_id' => null, 'survey_id' => null, 'workflow_event_id' => null,
                    'team_unit' => null, 'team_event_id' => null, 'team_task_id' => null,
                    'team_version' => null, 'team_comment_id' => null,
                    'read_at' => null, 'created_at' => gmdate('Y-m-d H:i:s'),
                ])) {
                    throw new RuntimeException('Unable to insert chat mention.');
                }
                $this->pending[(int) $message['tenant_id']][] = (int) $this->db->insertID();
            }
        } catch (Throwable) {
            // Never expose SQL or message text, including when a partial migration rejects the type.
            throw new RuntimeException('Chat mention notifications unavailable; deploy the chat mention notification migration.', 503);
        }
    }

    private function currentIdentity(BaseBuilder $query): ?array
    {
        // Locking reads bypass MySQL's earlier roster snapshot and retain authorization
        // through commit. Shared locks do not serialize sends to unrelated recipients.
        return $this->db->DBDriver === 'MySQLi'
            ? $this->db->query($query->getCompiledSelect() . ' LOCK IN SHARE MODE')->getRowArray()
            : $query->get()->getRowArray();
    }

    /** The owner discards this instance on rollback; retries never enqueue or backfill. */
    public function publishCommitted(): void
    {
        if ($this->db->transDepth !== 0 || !$this->db->transStatus()) return;
        $pending = $this->pending;
        $this->pending = [];
        try {
            foreach ($pending as $tenant => $ids) {
                (new NotificationInvalidation($this->db))->publishCommitted((int) $tenant, $ids);
            }
        } catch (Throwable) {
            // Reconciliation polling covers transport outages after durable delivery.
        }
    }
}

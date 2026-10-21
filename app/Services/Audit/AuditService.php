<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEventModel;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

final class AuditService
{
    public const GENESIS_HASH = AuditHash::GENESIS;

    private AuditEventModel $auditModel;
    private BaseConnection $db;

    public function __construct(?AuditEventModel $auditModel = null, ?BaseConnection $db = null)
    {
        $this->db = $db ?? ($auditModel !== null ? $auditModel->db : \Config\Database::connect());
        if ($auditModel !== null && $auditModel->db !== $this->db) {
            throw new \InvalidArgumentException('Audit model and transaction must use the same connection.');
        }
        $this->auditModel = $auditModel ?? new AuditEventModel($this->db);
    }

    /**
     * Append under the shared tenant mutex, participating in a caller transaction when present.
     * Callers must roll back their transaction if this operation throws.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function log(
        string $eventType,
        string $entityType,
        string $entityId,
        string $action,
        array $payload = [],
        int|string|null $actorUserId = null,
        ?string $actorRole = null,
        ?string $actorIp = null
    ): array {
        $this->assertAvailable();
        $tenantId = CurrentTenant::id();
        // Reject encoding failures before modifying either audit or caller-owned state.
        $jsonPayload = $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        $ip = $actorIp ?? (service('request')->getIPAddress() ?: '127.0.0.1');

        return $this->transaction(function () use ($tenantId, $eventType, $entityType, $entityId, $action,
            $jsonPayload, $actorUserId, $actorRole, $ip): array {
            $this->lockTenant($tenantId);
            $this->assertAvailable();
            $auditTable = $this->db->protectIdentifiers($this->db->prefixTable('audit_events'));
            $lock = $this->db->DBDriver === 'SQLite3' ? '' : ' FOR UPDATE';

            // A locking/current read avoids stale REPEATABLE READ snapshots in caller transactions.
            $result = $this->db->query("SELECT * FROM {$auditTable} WHERE tenant_id = ? ORDER BY id DESC LIMIT 1{$lock}", [$tenantId]);
            if ($result === false) {
                throw new RuntimeException('Unable to read audit head.', 503);
            }
            $latest = $result->getRowArray();
            if ($latest !== null && !hash_equals(AuditHash::compute($latest), (string) $latest['curr_hash'])) {
                throw new RuntimeException('Audit head integrity check failed. Append refused.', 503);
            }
            $event = [
                'tenant_id' => $tenantId,
                'event_type' => $eventType,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'actor_ip' => $ip,
                'action' => $action,
                'payload_json' => $jsonPayload,
                'prev_hash' => $latest['curr_hash'] ?? self::GENESIS_HASH,
                'hash_version' => 2,
                'occurred_at' => date('Y-m-d H:i:s'),
            ];
            $event['curr_hash'] = AuditHash::compute($event);
            if (!$this->db->table('audit_events')->insert($event) || !$this->db->transStatus()) {
                throw new RuntimeException('Unable to persist audit event.', 503);
            }
            // CI's MySQLi insertID(): int cannot represent the full BIGINT UNSIGNED domain.
            $id = $this->db->DBDriver === 'MySQLi'
                ? $this->db->query('SELECT CAST(LAST_INSERT_ID() AS CHAR) AS id')->getRowArray()['id']
                : $this->db->insertID();
            $stored = $this->auditModel->scoped($tenantId)->find($id);
            if ($stored === null || !hash_equals($event['curr_hash'], (string) $stored['curr_hash'])
                || !hash_equals($event['curr_hash'], AuditHash::compute($stored))) {
                throw new RuntimeException('Stored audit event differs from its hash. Append refused.', 503);
            }
            return $stored;
        });
    }

    /**
     * Verify legacy and v2 history without modifying rows. No external head/count is assumed.
     *
     * @return array{valid: bool, verified_count: int, broken_id: int|string|null}
     */
    public function verifyChain(?int $tenantId = null): array
    {
        $tenantId ??= CurrentTenant::id();
        $events = $this->auditModel->scoped($tenantId)->orderBy('id', 'ASC')->findAll();
        return AuditHash::verify($events, $tenantId);
    }

    /**
     * Acquire first in an audited business transaction, before inserts/FK checks or row locks.
     * log() takes this same mutex; acquiring it only at the end can cause lock upgrades.
     */
    public function lockTenant(int $tenantId): void
    {
        if ($tenantId < 1 || $this->db->transDepth < 1
            || !in_array($this->db->DBDriver, ['MySQLi', 'SQLite3'], true)) {
            throw new RuntimeException('Audit tenant locking requires an active supported transaction.', 503);
        }
        $table = $this->db->protectIdentifiers($this->db->prefixTable('tenants'));
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite && !$this->db->query("UPDATE {$table} SET id = id WHERE id = ?", [$tenantId])) {
            throw new RuntimeException('Unable to lock audit tenant.', 503);
        }
        $lock = $sqlite ? '' : ' FOR UPDATE';
        $tenant = $this->db->query("SELECT id FROM {$table} WHERE id = ?{$lock}", [$tenantId]);
        if ($tenant === false || $tenant->getRowArray() === null) {
            throw new RuntimeException('Audit tenant is unavailable.', 503);
        }
    }

    private function assertAvailable(): void
    {
        clearstatcache(true, WRITEPATH . 'audit-maintenance.flag');
        if (is_file(WRITEPATH . 'audit-maintenance.flag')) {
            throw new RuntimeException('Audit writes are paused for maintenance.', 503);
        }
    }

    private function transaction(callable $operation): array
    {
        if (!in_array($this->db->DBDriver, ['MySQLi', 'SQLite3'], true)) {
            throw new RuntimeException('Audit writes require a supported transactional database.', 503);
        }
        $ownsTransaction = $this->db->transDepth === 0;
        if ($ownsTransaction) {
            $this->db->resetTransStatus();
            if (($this->db->DBDriver === 'MySQLi' && !$this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'))
                || !$this->db->transBegin()) {
                throw new RuntimeException('Unable to begin audit transaction.', 503);
            }
        } elseif (!$this->db->transStatus()) {
            throw new RuntimeException('Audit append requires a healthy caller transaction.', 503);
        }

        try {
            $result = $operation();
            if (!$this->db->transStatus() || ($ownsTransaction && !$this->db->transCommit())) {
                throw new RuntimeException('Unable to commit audit event.', 503);
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                try {
                    $this->db->transRollback();
                } finally {
                    $this->db->resetTransStatus();
                }
            }
            throw $e;
        }
    }
}

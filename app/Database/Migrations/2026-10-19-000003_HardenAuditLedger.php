<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Run with audit writers stopped: MySQL/MariaDB DDL is not transactionally reversible.
 * Retry up() after a partial installation; never repair or rehash historical rows.
 * These triggers do not protect against privileged TRUNCATE, DROP or trigger removal.
 */
final class HardenAuditLedger extends Migration
{
    private function table(): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable('audit_events'));
    }

    private function execute(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Audit ledger DDL failed; keep audit writers stopped until installation succeeds.');
        }
    }

    private function rows(string $sql, array $bindings = []): array
    {
        $result = $this->db->query($sql, $bindings);
        if ($result === false) {
            throw new RuntimeException('Audit ledger preflight failed; no automatic repairs performed.');
        }

        return $result->getResultArray();
    }

    private function assertSupportedTable(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('Audit ledger hardening requires MySQL/MariaDB with InnoDB and SIGNAL support.');
        }

        $rows = $this->rows(
            'SELECT ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable('audit_events')],
        );
        if (count($rows) !== 1 || strtoupper($rows[0]['ENGINE'] ?? '') !== 'INNODB' || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE') {
            throw new RuntimeException('Audit ledger hardening requires an existing InnoDB audit_events table.');
        }
    }

    private function hasVersionColumn(): bool
    {
        $rows = $this->rows(
            "SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'hash_version'",
            [$this->db->prefixTable('audit_events')],
        );
        if ($rows === []) {
            return false;
        }

        $column = $rows[0];
        if (strtolower($column['DATA_TYPE']) !== 'smallint'
            || stripos($column['COLUMN_TYPE'], 'unsigned') === false
            || $column['IS_NULLABLE'] !== 'NO'
            || (string) $column['COLUMN_DEFAULT'] !== '1'
            || trim($column['EXTRA']) !== '') {
            throw new RuntimeException('Unexpected audit_events.hash_version definition; refusing to modify existing metadata.');
        }

        return true;
    }

    /** Names follow the inventory migration: DBPrefix + ledger/table/operation. */
    private function triggers(): array
    {
        return [
            'update' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit history is append-only'",
            'delete' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit history is append-only'",
            'insert' => "BEGIN IF NEW.hash_version IS NULL OR NEW.hash_version <> 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='New audit events require hash_version 2'; END IF; END",
        ];
    }

    private function triggerName(string $verb): string
    {
        return $this->db->prefixTable('audit_audit_events_' . $verb);
    }

    /** Refuse name collisions rather than replacing another object's protection. */
    private function hasTrigger(string $verb, string $body): bool
    {
        $rows = $this->rows(
            'SELECT EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORIENTATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$this->triggerName($verb)],
        );
        if ($rows === []) {
            return false;
        }

        $trigger = $rows[0];
        $normalize = static fn (string $sql): string => strtolower((string) preg_replace('/\s+/', '', str_replace('`', '', trim($sql))));
        if ($trigger['EVENT_OBJECT_TABLE'] !== $this->db->prefixTable('audit_events')
            || $trigger['ACTION_TIMING'] !== 'BEFORE'
            || $trigger['EVENT_MANIPULATION'] !== strtoupper($verb)
            || $trigger['ACTION_ORIENTATION'] !== 'ROW'
            || $normalize($trigger['ACTION_STATEMENT']) !== $normalize($body)) {
            throw new RuntimeException('Unexpected audit ledger trigger definition; refusing to replace or remove it.');
        }

        return true;
    }

    public function up(): void
    {
        $this->assertSupportedTable();
        $hasColumn = $this->hasVersionColumn();
        $existing = [];
        foreach ($this->triggers() as $verb => $body) {
            $existing[$verb] = $this->hasTrigger($verb, $body);
        }

        if (!$hasColumn) {
            // DEFAULT 1 labels legacy rows without rewriting their payloads or hashes.
            $this->execute('ALTER TABLE ' . $this->table() . ' ADD hash_version SMALLINT UNSIGNED NOT NULL DEFAULT 1');
        }
        foreach ($this->triggers() as $verb => $body) {
            if (!$existing[$verb]) {
                $name = $this->db->protectIdentifiers($this->triggerName($verb));
                $this->execute('CREATE TRIGGER ' . $name . ' BEFORE ' . strtoupper($verb) . ' ON ' . $this->table() . ' FOR EACH ROW ' . $body);
            }
        }
        // No unique predecessor index: historical forks need a separate, intentional preflight.
    }

    public function down(): void
    {
        // CAUTION: rollback removes append-only enforcement. Stop writers and take a
        // verified backup first; never discard version metadata for live v2 history.
        $this->assertSupportedTable();
        $hasColumn = $this->hasVersionColumn();
        if ($hasColumn && $this->rows('SELECT 1 FROM ' . $this->table() . ' WHERE hash_version <> 1 LIMIT 1') !== []) {
            throw new RuntimeException('Refusing audit downgrade: non-v1 history exists. Restore a verified pre-cutover backup instead.');
        }

        $existing = [];
        foreach ($this->triggers() as $verb => $body) {
            $existing[$verb] = $this->hasTrigger($verb, $body);
        }
        foreach ($existing as $verb => $exists) {
            if ($exists) {
                $this->execute('DROP TRIGGER IF EXISTS ' . $this->db->protectIdentifiers($this->triggerName($verb)));
            }
        }
        if ($hasColumn) {
            $this->execute('ALTER TABLE ' . $this->table() . ' DROP COLUMN hash_version');
        }
    }
}

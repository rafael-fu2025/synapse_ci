<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Additive, restartable DDL: MySQL implicitly commits ALTER TABLE statements. */
final class RebuildReferralWorkflow extends Migration
{
    private function sql(string $sql, array $binds = [])
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($m) => $this->db->protectIdentifiers($this->db->prefixTable($m[1])), $sql);
        $result = $this->db->query($sql, $binds);
        if ($result === false) {
            throw new RuntimeException('Referral workflow migration failed.');
        }
        return $result;
    }

    private function column(string $table, string $column): ?array
    {
        return $this->sql('SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$this->db->prefixTable($table), $column])->getRowArray();
    }

    private function rejectRows(string $sql): void
    {
        if ($this->sql($sql)->getRowArray() !== null) {
            throw new RuntimeException('Referral workflow preflight failed; repair existing data before retrying.');
        }
    }

    private function index(string $table, string $name, array $columns, bool $unique, bool $apply): void
    {
        $rows = $this->sql('SELECT COLUMN_NAME, NON_UNIQUE, SUB_PART FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',
            [$this->db->prefixTable($table), $name])->getResultArray();
        if ($rows !== []) {
            if (array_column($rows, 'COLUMN_NAME') !== $columns
                || (int) $rows[0]['NON_UNIQUE'] !== (int) !$unique
                || array_filter($rows, static fn ($row) => $row['SUB_PART'] !== null) !== []) {
                throw new RuntimeException('Incompatible referral workflow index: ' . $name);
            }
        } elseif ($apply) {
            $this->sql('ALTER TABLE {' . $table . '} ADD ' . ($unique ? 'UNIQUE ' : '')
                . 'KEY ' . $name . ' (' . implode(',', $columns) . ')');
        }
    }

    private function foreignKey(string $name, string $column, string $parent, bool $apply): void
    {
        // Constraint names are schema-wide in InnoDB; honor isolated table prefixes.
        $name = $this->db->prefixTable($name);
        $rows = $this->sql('SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                r.DELETE_RULE, r.UPDATE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
            WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.CONSTRAINT_NAME=?
            ORDER BY k.ORDINAL_POSITION', [$this->db->prefixTable('notifications'), $name])->getResultArray();
        if ($rows !== []) {
            if (array_column($rows, 'COLUMN_NAME') !== ['tenant_id', $column]
                || array_column($rows, 'REFERENCED_COLUMN_NAME') !== ['tenant_id', 'id']) {
                throw new RuntimeException('Incompatible referral workflow foreign key: ' . $name);
            }
            foreach ($rows as $row) {
                if ($row['REFERENCED_TABLE_NAME'] !== $this->db->prefixTable($parent)
                    || $row['DELETE_RULE'] !== 'RESTRICT' || $row['UPDATE_RULE'] !== 'RESTRICT') {
                    throw new RuntimeException('Incompatible referral workflow foreign key: ' . $name);
                }
            }
        } elseif ($apply) {
            $this->sql('ALTER TABLE {notifications} ADD CONSTRAINT ' . $this->db->protectIdentifiers($name)
                . ' FOREIGN KEY (tenant_id,' . $column . ') REFERENCES {' . $parent . '}(tenant_id,id)'
                . ' ON DELETE RESTRICT ON UPDATE RESTRICT');
        }
    }

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('Referral workflow requires MySQL/MariaDB with InnoDB.');
        }
        foreach (['tenants', 'people', 'users', 'appointments', 'referrals', 'notifications'] as $table) {
            $row = $this->sql('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                [$this->db->prefixTable($table)])->getRowArray();
            if (strtoupper($row['ENGINE'] ?? '') !== 'INNODB') {
                throw new RuntimeException('Referral workflow requires InnoDB for ' . $table . '.');
            }
        }
        $additions = [
            ['referrals', 'target_encounter_id', 'bigint unsigned'],
            ['referrals', 'target_case_id', 'bigint unsigned'],
            ['referrals', 'request_key', 'char(64)'],
            ['notifications', 'recipient_user_id', 'bigint unsigned'],
            ['notifications', 'referral_id', 'bigint unsigned'],
        ];
        foreach ($additions as [$table, $column, $type]) {
            $existing = $this->column($table, $column);
            // MariaDB reports integer display widths; they do not change storage semantics.
            $actual = preg_replace('/bigint\(\d+\)/', 'bigint', strtolower($existing['COLUMN_TYPE'] ?? ''));
            if ($existing !== null && ($actual !== $type || $existing['IS_NULLABLE'] !== 'YES')) {
                throw new RuntimeException('Incompatible referral workflow column: ' . $column);
            }
        }
        $indexes = [
            ['users', 'notification_user_tenant', ['tenant_id', 'id'], true],
            ['referrals', 'notification_referral_tenant', ['tenant_id', 'id'], true],
            ['referrals', 'referral_request_key', ['tenant_id', 'issuer_user_id', 'request_key'], true],
            ['referrals', 'referral_source_page', ['tenant_id', 'source_module', 'status', 'id'], false],
            ['referrals', 'referral_target_page', ['tenant_id', 'target_module', 'status', 'id'], false],
            ['notifications', 'notification_referral_event', ['tenant_id', 'referral_id', 'recipient_user_id', 'type'], true],
            ['notifications', 'notification_user_inbox', ['tenant_id', 'recipient_user_id', 'read_at', 'id'], false],
        ];
        foreach ($indexes as [$table, $name, $columns, $unique]) {
            $this->index($table, $name, $columns, $unique, false);
        }
        $this->foreignKey('notification_recipient_user_fk', 'recipient_user_id', 'users', false);
        $this->foreignKey('notification_referral_fk', 'referral_id', 'referrals', false);

        $type = $this->column('notifications', 'type');
        if (!in_array(strtolower($type['COLUMN_TYPE'] ?? ''), ["enum('appointment_confirmed')", "enum('appointment_confirmed','referral_received')"], true)) {
            throw new RuntimeException('Incompatible notification event types.');
        }
        foreach (['recipient_person_id' => 'bigint unsigned', 'appointment_id' => 'bigint unsigned', 'appointment_version' => 'int unsigned'] as $column => $expected) {
            $meta = $this->column('notifications', $column);
            if (preg_replace('/(bigint|int)\(\d+\)/', '$1', strtolower($meta['COLUMN_TYPE'] ?? '')) !== $expected) {
                throw new RuntimeException('Incompatible notification column: ' . $column);
            }
        }
        // Preflight before the first ALTER: never delete or rewrite conflicting rows.
        if ($this->column('referrals', 'request_key') !== null) {
            $this->rejectRows('SELECT 1 FROM {referrals} WHERE request_key IS NOT NULL
                GROUP BY tenant_id,issuer_user_id,request_key HAVING COUNT(*)>1 LIMIT 1');
        }
        $user = $this->column('notifications', 'recipient_user_id') !== null ? 'n.recipient_user_id' : 'NULL';
        $ref = $this->column('notifications', 'referral_id') !== null ? 'n.referral_id' : 'NULL';
        $this->rejectRows("SELECT 1 FROM {notifications} n WHERE NOT (
            (n.type='appointment_confirmed' AND n.recipient_person_id IS NOT NULL AND n.appointment_id IS NOT NULL
                AND n.appointment_version IS NOT NULL AND {$user} IS NULL AND {$ref} IS NULL)
            OR (n.type='referral_received' AND {$user} IS NOT NULL AND {$ref} IS NOT NULL
                AND n.recipient_person_id IS NULL AND n.appointment_id IS NULL AND n.appointment_version IS NULL)) LIMIT 1");
        if ($user !== 'NULL') {
            $this->rejectRows('SELECT 1 FROM {notifications} n LEFT JOIN {users} u ON u.tenant_id=n.tenant_id AND u.id=n.recipient_user_id
                WHERE n.recipient_user_id IS NOT NULL AND u.id IS NULL LIMIT 1');
        }
        if ($ref !== 'NULL') {
            $this->rejectRows('SELECT 1 FROM {notifications} n LEFT JOIN {referrals} r ON r.tenant_id=n.tenant_id AND r.id=n.referral_id
                WHERE n.referral_id IS NOT NULL AND r.id IS NULL LIMIT 1');
        }
        if ($user !== 'NULL' && $ref !== 'NULL') {
            $this->rejectRows('SELECT 1 FROM {notifications} WHERE referral_id IS NOT NULL AND recipient_user_id IS NOT NULL
                GROUP BY tenant_id,referral_id,recipient_user_id,type HAVING COUNT(*)>1 LIMIT 1');
        }

        foreach ($additions as [$table, $column, $definition]) {
            if ($this->column($table, $column) === null) {
                // Deliberately no clinical foreign keys: receiving records stay module-isolated.
                $this->sql('ALTER TABLE {' . $table . '} ADD COLUMN ' . $column . ' ' . $definition . ' NULL');
            }
        }
        $this->sql("ALTER TABLE {notifications}
            MODIFY recipient_person_id BIGINT UNSIGNED NULL,
            MODIFY appointment_id BIGINT UNSIGNED NULL,
            MODIFY appointment_version INT UNSIGNED NULL,
            MODIFY type ENUM('appointment_confirmed','referral_received') NOT NULL");
        foreach ($indexes as [$table, $name, $columns, $unique]) {
            $this->index($table, $name, $columns, $unique, true);
        }
        $this->foreignKey('notification_recipient_user_fk', 'recipient_user_id', 'users', true);
        $this->foreignKey('notification_referral_fk', 'referral_id', 'referrals', true);
    }

    public function down(): void
    {
        throw new RuntimeException('Referral workflow is forward-only; notification history must be preserved.');
    }
}

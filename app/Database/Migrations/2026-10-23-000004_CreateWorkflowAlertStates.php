<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Restartable adoption, never repair/reset episode history or partially compatible storage. */
final class CreateWorkflowAlertStates extends Migration
{
    private const COLUMNS = [
        'id' => ['bigint unsigned', false, null, 'auto_increment'],
        'tenant_id' => ['int unsigned', false, null, ''],
        'event_kind' => ['varchar(64)', false, null, ''],
        'subject_id' => ['bigint unsigned', false, null, ''],
        'active' => ['tinyint', false, '0', ''],
        'episode_number' => ['bigint unsigned', false, '0', ''],
        'started_at' => ['datetime', true, null, ''],
        'resolved_at' => ['datetime', true, null, ''],
    ];

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') throw new RuntimeException('Workflow alert states require InnoDB.');
        foreach (['tenants', 'workflow_events'] as $table) $this->engine($table);
        $exists = $this->sql('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$this->db->prefixTable('workflow_alert_states')])->getRowArray() !== null;
        if (!$exists) {
            // One atomic CREATE: a crash before migration-history insertion can safely be adopted.
            $this->sql('CREATE TABLE {workflow_alert_states} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL,
                event_kind VARCHAR(64) NOT NULL,
                subject_id BIGINT UNSIGNED NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 0,
                episode_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
                started_at DATETIME NULL,
                resolved_at DATETIME NULL,
                UNIQUE KEY workflow_alert_identity (tenant_id,event_kind,subject_id),
                FOREIGN KEY (tenant_id) REFERENCES {tenants}(id) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        $this->validate();
    }

    private function sql(string $sql, array $binds = [])
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($match) => $this->db->protectIdentifiers($this->db->prefixTable($match[1])), $sql);
        $result = $this->db->query($sql, $binds);
        if ($result === false) throw new RuntimeException('Workflow alert state verification failed.');
        return $result;
    }

    private function engine(string $table): void
    {
        $row = $this->sql('SELECT ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$this->db->prefixTable($table)])->getRowArray();
        if (strtoupper($row['ENGINE'] ?? '') !== 'INNODB' || ($row['TABLE_TYPE'] ?? '') !== 'BASE TABLE') {
            throw new RuntimeException('Missing InnoDB prerequisite: ' . $table);
        }
    }

    private function validate(): void
    {
        $this->engine('workflow_alert_states');
        $table = $this->db->prefixTable('workflow_alert_states');
        $columns = $this->sql('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])->getResultArray();
        $columns = array_column($columns, null, 'COLUMN_NAME');
        if (count($columns) !== count(self::COLUMNS)) throw new RuntimeException('Incomplete or unexpected alert state columns.');
        foreach (self::COLUMNS as $name => [$type, $nullable, $default, $extra]) {
            $column = $columns[$name] ?? [];
            $actual = preg_replace('/\b(bigint|int|tinyint)\(\d+\)/', '$1', strtolower($column['COLUMN_TYPE'] ?? ''));
            $actualDefault = $column['COLUMN_DEFAULT'] ?? null;
            if ($actualDefault === 'NULL') $actualDefault = null; // MariaDB represents nullable defaults as SQL text.
            if ($actual !== $type || ($column['IS_NULLABLE'] ?? '') !== ($nullable ? 'YES' : 'NO')
                || $actualDefault !== $default || strtolower($column['EXTRA'] ?? '') !== $extra) {
                throw new RuntimeException('Incompatible alert state column: ' . $name);
            }
        }
        if (!in_array($columns['event_kind']['CHARACTER_SET_NAME'], ['utf8mb4', 'utf8mb3', 'utf8', 'ascii', 'latin1'], true)) {
            throw new RuntimeException('Incompatible alert state identifier charset.');
        }
        $indexes = $this->sql('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SUB_PART,INDEX_TYPE
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table])->getResultArray();
        $grouped = [];
        foreach ($indexes as $index) $grouped[$index['INDEX_NAME']][] = $index;
        foreach (['PRIMARY' => ['id'], 'workflow_alert_identity' => ['tenant_id', 'event_kind', 'subject_id']] as $name => $fields) {
            $rows = $grouped[$name] ?? [];
            if (array_column($rows, 'COLUMN_NAME') !== $fields || array_filter($rows, static fn ($row) =>
                (int) $row['NON_UNIQUE'] !== 0 || $row['SUB_PART'] !== null || strtoupper($row['INDEX_TYPE']) !== 'BTREE') !== []) {
                throw new RuntimeException('Incompatible alert state index: ' . $name);
            }
        }
        // Extra UNIQUE/FK constraints can silently prevent future episodes, so do not adopt them.
        foreach ($grouped as $name => $rows) {
            if (!in_array($name, ['PRIMARY', 'workflow_alert_identity'], true) && (int) $rows[0]['NON_UNIQUE'] === 0) {
                throw new RuntimeException('Unexpected unique alert state index.');
            }
        }
        $fks = $this->sql('SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
                AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
            WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', [$table])->getResultArray();
        $schema = $this->sql('SELECT DATABASE() AS name')->getRow('name');
        if (count($fks) !== 1 || $fks[0]['COLUMN_NAME'] !== 'tenant_id' || $fks[0]['REFERENCED_TABLE_SCHEMA'] !== $schema
            || $fks[0]['REFERENCED_TABLE_NAME'] !== $this->db->prefixTable('tenants') || $fks[0]['REFERENCED_COLUMN_NAME'] !== 'id'
            || $fks[0]['DELETE_RULE'] !== 'RESTRICT' || $fks[0]['UPDATE_RULE'] !== 'RESTRICT') {
            throw new RuntimeException('Incompatible alert state tenant foreign key.');
        }
        // The original schema has no CHECKs; unknown restrictions cannot be assumed compatible.
        if ($this->sql("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
            AND TABLE_NAME=? AND CONSTRAINT_TYPE='CHECK' LIMIT 1", [$table])->getRowArray() !== null) {
            throw new RuntimeException('Unexpected alert state check constraint.');
        }
        $invalid = $this->sql("SELECT 1 FROM {workflow_alert_states} s LEFT JOIN {tenants} t ON t.id=s.tenant_id
            WHERE t.id IS NULL OR NOT ((s.id>0 AND s.tenant_id>0 AND s.subject_id>0 AND s.active IN (0,1)
                AND BINARY s.event_kind IN ('inventory_low_stock','bmg_device_offline','bmg_rtc_invalid')
                AND s.episode_number>0 AND s.started_at IS NOT NULL AND s.started_at>='1000-01-01 00:00:00'
                AND MONTH(s.started_at) BETWEEN 1 AND 12
                AND DAYOFMONTH(s.started_at) BETWEEN 1 AND DAYOFMONTH(LAST_DAY(s.started_at))
                AND ((s.active=1 AND s.resolved_at IS NULL)
                    OR (s.active=0 AND s.resolved_at IS NOT NULL AND s.resolved_at>=s.started_at
                        AND MONTH(s.resolved_at) BETWEEN 1 AND 12
                        AND DAYOFMONTH(s.resolved_at) BETWEEN 1 AND DAYOFMONTH(LAST_DAY(s.resolved_at))))) IS TRUE) LIMIT 1")->getRowArray();
        if ($invalid !== null) throw new RuntimeException('Malformed alert episode history; manual investigation required.');
    }

    public function down(): void
    {
        // Resetting episode counters can collide with retained outbox occurrence keys.
        throw new RuntimeException('Workflow episode history must not be automatically removed.');
    }
}

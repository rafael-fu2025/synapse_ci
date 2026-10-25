<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Additive/restartable InnoDB DDL; never rewrite delivered notifications or read state. */
final class AddTeamsNotifications extends Migration
{
    private const OLD_TYPES = "'appointment_confirmed','referral_received','announcement_published','survey_published'";
    private const EVENT_TYPES = "'team_event_added','team_event_removed','team_event_updated','team_event_cancelled'";
    private const TASK_TYPES = "'team_task_assigned','team_task_unassigned','team_task_updated','team_task_status'";
    private const COMMENT_TYPE = "'team_task_commented'";
    private const COLUMNS = [
        'team_unit' => 'varchar(16)',
        'team_event_id' => 'bigint unsigned',
        'team_task_id' => 'bigint unsigned',
        'team_version' => 'int unsigned',
        'team_comment_id' => 'bigint unsigned',
    ];
    // NULL on legacy rows. Comments are identified by comment ID, not the task's edit version.
    private const DELIVERY = "CASE
        WHEN team_event_id IS NOT NULL THEN CONCAT('event:',team_unit,':',team_event_id,':',team_version)
        WHEN team_comment_id IS NOT NULL THEN CONCAT('comment:',team_unit,':',team_task_id,':',team_comment_id)
        WHEN team_task_id IS NOT NULL THEN CONCAT('task:',team_unit,':',team_task_id,':',team_version)
        ELSE NULL END";

    private function sql(string $sql, array $binds = [])
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($m) => $this->db->protectIdentifiers($this->db->prefixTable($m[1])), $sql);
        $result = $this->db->query($sql, $binds);
        if ($result === false) { throw new RuntimeException('Teams notification migration failed.'); }
        return $result;
    }

    private function column(string $name): ?array
    {
        return $this->sql('SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$this->db->prefixTable('notifications'), $name])->getRowArray();
    }

    private function normalized(string $sql): string
    {
        // MariaDB adds identifier quoting and expression parentheses in information_schema.
        // Preserve string literals: whitespace/case inside them changes delivery identities.
        $parts = preg_split("/('(?:''|[^'])*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => &$part) {
            if ($index % 2 === 0) { $part = preg_replace('/[\s`()]+/', '', strtolower($part)); }
        }
        unset($part);
        return implode('', $parts);
    }

    private function index(string $table, string $name, array $columns, bool $unique): bool
    {
        $rows = $this->sql('SELECT COLUMN_NAME,NON_UNIQUE,SUB_PART FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',
            [$this->db->prefixTable($table), $name])->getResultArray();
        if ($rows === []) return false;
        if (array_column($rows, 'COLUMN_NAME') !== $columns
            || array_filter($rows, static fn ($row) => (int) $row['NON_UNIQUE'] !== (int) !$unique || $row['SUB_PART'] !== null) !== []) {
            throw new RuntimeException('Incompatible Teams notification index: ' . $name);
        }
        return true;
    }

    private function foreignKey(string $name, array $local, string $parent, array $remote): bool
    {
        $rows = $this->sql('SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
            WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.CONSTRAINT_NAME=? ORDER BY k.ORDINAL_POSITION',
            [$this->db->prefixTable('notifications'), $this->db->prefixTable($name)])->getResultArray();
        if ($rows === []) return false;
        $schema = $this->sql('SELECT DATABASE() AS name')->getRow('name');
        if (array_column($rows, 'COLUMN_NAME') !== $local || array_column($rows, 'REFERENCED_COLUMN_NAME') !== $remote) {
            throw new RuntimeException('Incompatible Teams notification foreign key: ' . $name);
        }
        foreach ($rows as $row) {
            if ($row['REFERENCED_TABLE_SCHEMA'] !== $schema || $row['REFERENCED_TABLE_NAME'] !== $this->db->prefixTable($parent)
                || $row['DELETE_RULE'] !== 'RESTRICT' || $row['UPDATE_RULE'] !== 'RESTRICT') {
                throw new RuntimeException('Incompatible Teams notification foreign key: ' . $name);
            }
        }
        return true;
    }

    private function shape(): string
    {
        $events = self::EVENT_TYPES;
        $tasks = self::TASK_TYPES;
        $comment = self::COMMENT_TYPE;
        $types = $events . ',' . $tasks . ',' . $comment;
        return "((type NOT IN ({$types}) AND team_unit IS NULL AND team_event_id IS NULL
                AND team_task_id IS NULL AND team_version IS NULL AND team_comment_id IS NULL)
            OR (type IN ({$types}) AND recipient_user_id IS NOT NULL AND recipient_user_id>0
                AND recipient_person_id IS NULL AND appointment_id IS NULL AND appointment_version IS NULL
                AND referral_id IS NULL AND announcement_id IS NULL AND survey_id IS NULL
                AND team_unit IN ('clinic','guidance','facilities') AND team_version IS NOT NULL AND team_version>0
                AND ((type IN ({$events}) AND team_event_id IS NOT NULL AND team_event_id>0
                        AND team_task_id IS NULL AND team_comment_id IS NULL)
                    OR (type IN ({$tasks}) AND team_task_id IS NOT NULL AND team_task_id>0
                        AND team_event_id IS NULL AND team_comment_id IS NULL)
                    OR (type={$comment} AND team_task_id IS NOT NULL AND team_task_id>0
                        AND team_comment_id IS NOT NULL AND team_comment_id>0 AND team_event_id IS NULL)))) IS TRUE";
    }

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') { throw new RuntimeException('Teams notifications require MySQL/MariaDB with InnoDB.'); }
        foreach (['notifications', 'users', 'team_events', 'team_tasks', 'team_task_comments'] as $table) {
            $meta = $this->sql('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                [$this->db->prefixTable($table)])->getRowArray();
            if (strtoupper($meta['ENGINE'] ?? '') !== 'INNODB') { throw new RuntimeException('Missing InnoDB prerequisite: ' . $table); }
        }
        $old = 'enum(' . self::OLD_TYPES . ')';
        $new = 'enum(' . self::OLD_TYPES . ',' . self::EVENT_TYPES . ',' . self::TASK_TYPES . ',' . self::COMMENT_TYPE . ')';
        $type = $this->column('type');
        if (!in_array(strtolower($type['COLUMN_TYPE'] ?? ''), [$old, $new], true) || ($type['IS_NULLABLE'] ?? '') !== 'NO') {
            throw new RuntimeException('Incompatible notification types; Teams requires the engagement notification migration.');
        }
        $alter = [];
        $present = [];
        foreach (self::COLUMNS as $name => $expected) {
            $meta = $this->column($name);
            $present[$name] = $meta !== null;
            if ($meta === null) { $alter[] = 'ADD COLUMN ' . $name . ' ' . $expected . ' NULL DEFAULT NULL'; continue; }
            $actual = preg_replace('/\b(bigint|int)\(\d+\)/', '$1', strtolower($meta['COLUMN_TYPE']));
            if ($actual !== $expected || $meta['IS_NULLABLE'] !== 'YES' || !in_array($meta['COLUMN_DEFAULT'], [null, 'NULL'], true)
                || $meta['EXTRA'] !== '') {
                throw new RuntimeException('Incompatible Teams notification column: ' . $name);
            }
        }
        $delivery = $this->column('team_delivery_key');
        if ($delivery === null) {
            $alter[] = 'ADD COLUMN team_delivery_key VARCHAR(160) GENERATED ALWAYS AS (' . self::DELIVERY . ') STORED';
        } elseif (strtolower($delivery['COLUMN_TYPE']) !== 'varchar(160)' || $delivery['IS_NULLABLE'] !== 'YES'
            || strtoupper($delivery['EXTRA']) !== 'STORED GENERATED'
            || $this->normalized($delivery['GENERATION_EXPRESSION']) !== $this->normalized(self::DELIVERY)) {
            throw new RuntimeException('Incompatible Teams notification delivery key.');
        }
        // Existing constraints are prerequisites, not replacements for the new scope keys.
        foreach ([['team_events', 'team_event_scope'], ['team_tasks', 'team_task_scope']] as [$table, $name]) {
            if (!$this->index($table, $name, ['tenant_id', 'unit', 'id'], true)) {
                throw new RuntimeException('Missing Teams scope index: ' . $name);
            }
        }
        if (!$this->foreignKey('notification_recipient_user_fk', ['tenant_id', 'recipient_user_id'], 'users', ['tenant_id', 'id'])) {
            throw new RuntimeException('Missing tenant-safe notification recipient foreign key.');
        }
        $commentIndex = $this->index('team_task_comments', 'notification_team_comment_scope', ['tenant_id', 'unit', 'task_id', 'id'], true);
        foreach ([
            ['notification_team_delivery', ['tenant_id', 'recipient_user_id', 'team_delivery_key'], true],
            ['notification_team_event_scope', ['tenant_id', 'team_unit', 'team_event_id'], false],
            ['notification_team_task_scope', ['tenant_id', 'team_unit', 'team_task_id', 'team_comment_id'], false],
        ] as [$name, $columns, $unique]) {
            if (!$this->index('notifications', $name, $columns, $unique)) {
                $alter[] = 'ADD ' . ($unique ? 'UNIQUE ' : '') . 'KEY ' . $name . ' (' . implode(',', $columns) . ')';
            }
        }
        foreach ([
            ['notification_team_event_fk', ['tenant_id', 'team_unit', 'team_event_id'], 'team_events', ['tenant_id', 'unit', 'id']],
            ['notification_team_task_fk', ['tenant_id', 'team_unit', 'team_task_id'], 'team_tasks', ['tenant_id', 'unit', 'id']],
            ['notification_team_comment_fk', ['tenant_id', 'team_unit', 'team_task_id', 'team_comment_id'], 'team_task_comments', ['tenant_id', 'unit', 'task_id', 'id']],
        ] as [$name, $local, $parent, $remote]) {
            if (!$this->foreignKey($name, $local, $parent, $remote)) {
                $alter[] = 'ADD CONSTRAINT ' . $this->db->protectIdentifiers($this->db->prefixTable($name))
                    . ' FOREIGN KEY (' . implode(',', $local) . ') REFERENCES {' . $parent . '}(' . implode(',', $remote) . ')'
                    . ' ON DELETE RESTRICT ON UPDATE RESTRICT';
            }
        }
        $shape = $this->shape();
        $checkName = $this->db->prefixTable('notification_team_shape');
        $check = $this->sql('SELECT c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c
            JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME
            WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_NAME=? AND t.CONSTRAINT_TYPE=\'CHECK\'',
            [$this->db->prefixTable('notifications'), $checkName])->getRowArray();
        if ($check === null) { $alter[] = 'ADD CONSTRAINT ' . $this->db->protectIdentifiers($checkName) . ' CHECK (' . $shape . ')'; }
        elseif ($this->normalized($check['CHECK_CLAUSE']) !== $this->normalized($shape)) {
            throw new RuntimeException('Incompatible Teams notification shape constraint.');
        }
        // Validate existing/partially installed data before the first implicitly committed ALTER.
        $existingShape = preg_replace_callback('/\bteam_(?:unit|event_id|task_id|version|comment_id)\b/',
            static fn ($m) => $present[$m[0]] ? $m[0] : 'NULL', $shape);
        if ($this->sql('SELECT 1 FROM {notifications} WHERE NOT (' . $existingShape . ') LIMIT 1')->getRowArray() !== null) {
            throw new RuntimeException('Teams notification preflight failed; preserve and repair incompatible rows before retrying.');
        }
        if (!in_array(false, $present, true)) {
            if ($this->sql('SELECT 1 FROM {notifications} WHERE team_unit IS NOT NULL
                GROUP BY tenant_id,recipient_user_id,(' . self::DELIVERY . ') HAVING COUNT(*)>1 LIMIT 1')->getRowArray() !== null) {
                throw new RuntimeException('Duplicate Teams notifications; preserve and repair existing rows before retrying.');
            }
            foreach ([['team_event_id', 'team_events'], ['team_task_id', 'team_tasks']] as [$column, $parent]) {
                if ($this->sql('SELECT 1 FROM {notifications} n LEFT JOIN {' . $parent . '} p ON p.tenant_id=n.tenant_id
                    AND p.unit=n.team_unit AND p.id=n.' . $column . ' WHERE n.' . $column . ' IS NOT NULL AND p.id IS NULL LIMIT 1')->getRowArray() !== null) {
                    throw new RuntimeException('Teams notification scope preflight failed.');
                }
            }
            if ($this->sql('SELECT 1 FROM {notifications} n LEFT JOIN {team_task_comments} c ON c.tenant_id=n.tenant_id
                AND c.unit=n.team_unit AND c.task_id=n.team_task_id AND c.id=n.team_comment_id
                WHERE n.team_comment_id IS NOT NULL AND c.id IS NULL LIMIT 1')->getRowArray() !== null) {
                throw new RuntimeException('Teams notification comment preflight failed.');
            }
        }
        if (!$commentIndex) {
            $this->sql('ALTER TABLE {team_task_comments} ADD UNIQUE KEY notification_team_comment_scope (tenant_id,unit,task_id,id)');
        }
        if (strtolower($type['COLUMN_TYPE']) !== $new) { $alter[] = 'MODIFY type ' . $new . ' NOT NULL'; }
        if ($alter !== []) { $this->sql('ALTER TABLE {notifications} ' . implode(', ', $alter)); }
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        throw new RuntimeException('Teams notifications are forward-only; preserve delivered notices and read state.');
    }
}

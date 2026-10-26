<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Additive/restartable InnoDB DDL. No backfill, delivery rewrite or destructive down. */
final class AddTeamChatMentionNotifications extends Migration
{
    private const TYPE = 'team_chat_mentioned';

    private function sql(string $sql, array $binds = [])
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($m) => $this->db->protectIdentifiers($this->db->prefixTable($m[1])), $sql);
        $result = $this->db->query($sql, $binds);
        if ($result === false) throw new RuntimeException('Chat mention notification migration failed.');
        return $result;
    }

    private function column(string $table, string $name): ?array
    {
        return $this->sql('SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$this->db->prefixTable($table), $name])->getRowArray();
    }

    private function normalized(string $sql): string
    {
        $parts = preg_split("/('(?:''|[^'])*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => &$part) {
            if ($i % 2 === 0) $part = preg_replace('/[\s`()]+/', '', strtolower($part));
        }
        unset($part);
        return implode('', $parts);
    }

    private function index(string $table, string $name, array $columns, bool $unique): bool
    {
        $rows = $this->sql('SELECT COLUMN_NAME,NON_UNIQUE,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',
            [$this->db->prefixTable($table), $name])->getResultArray();
        if ($rows === []) return false;
        if (array_column($rows, 'COLUMN_NAME') !== $columns || array_filter($rows,
            static fn ($r) => (int) $r['NON_UNIQUE'] !== (int) !$unique || $r['SUB_PART'] !== null
                || strtoupper($r['INDEX_TYPE']) !== 'BTREE') !== []) {
            throw new RuntimeException('Incompatible chat notification index: ' . $name);
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
        if (array_column($rows, 'COLUMN_NAME') !== $local || array_column($rows, 'REFERENCED_COLUMN_NAME') !== $remote
            || array_filter($rows, fn ($r) => $r['REFERENCED_TABLE_SCHEMA'] !== $schema
                || $r['REFERENCED_TABLE_NAME'] !== $this->db->prefixTable($parent)
                || $r['DELETE_RULE'] !== 'RESTRICT' || $r['UPDATE_RULE'] !== 'RESTRICT') !== []) {
            throw new RuntimeException('Incompatible chat notification foreign key: ' . $name);
        }
        return true;
    }

    private function check(string $name): ?string
    {
        $row = $this->sql("SELECT c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c
            JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME
            WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_NAME=? AND t.CONSTRAINT_TYPE='CHECK'",
            [$this->db->prefixTable('notifications'), $this->db->prefixTable($name)])->getRowArray();
        return $row['CHECK_CLAUSE'] ?? null;
    }

    private function shape(): string
    {
        return "((type<>'team_chat_mentioned' AND chat_unit IS NULL AND chat_message_id IS NULL)
            OR (type='team_chat_mentioned' AND recipient_user_id IS NOT NULL AND recipient_user_id>0
                AND chat_unit IN ('clinic','guidance','facilities') AND chat_message_id IS NOT NULL AND chat_message_id>0
                AND recipient_person_id IS NULL AND appointment_id IS NULL AND appointment_version IS NULL
                AND referral_id IS NULL AND announcement_id IS NULL AND survey_id IS NULL AND workflow_event_id IS NULL
                AND team_unit IS NULL AND team_event_id IS NULL AND team_task_id IS NULL
                AND team_version IS NULL AND team_comment_id IS NULL)) IS TRUE";
    }

    private function rejectRows(string $sql): void
    {
        if ($this->sql($sql)->getRowArray() !== null) {
            throw new RuntimeException('Chat mention preflight failed; preserve and repair incompatible data before retrying.');
        }
    }

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') throw new RuntimeException('Chat mentions require MySQL/MariaDB with InnoDB.');
        foreach (['notifications', 'users', 'team_chat_messages'] as $table) {
            $meta = $this->sql('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                [$this->db->prefixTable($table)])->getRowArray();
            if (strtoupper($meta['ENGINE'] ?? '') !== 'INNODB') throw new RuntimeException('Missing InnoDB prerequisite: ' . $table);
        }
        foreach (['notification_team_shape', 'notification_workflow_shape'] as $name) {
            if ($this->check($name) === null) throw new RuntimeException('Missing notification prerequisite: ' . $name);
        }
        foreach (['recipient_user_id', 'recipient_person_id', 'appointment_id', 'appointment_version', 'referral_id',
            'announcement_id', 'survey_id', 'workflow_event_id', 'team_unit', 'team_event_id', 'team_task_id',
            'team_version', 'team_comment_id'] as $name) {
            if ($this->column('notifications', $name) === null) throw new RuntimeException('Missing notification prerequisite: ' . $name);
        }
        if (!$this->foreignKey('notification_recipient_user_fk', ['tenant_id', 'recipient_user_id'], 'users', ['tenant_id', 'id'])) {
            throw new RuntimeException('Missing tenant-safe recipient foreign key.');
        }
        $type = $this->column('notifications', 'type');
        $enum = $type['COLUMN_TYPE'] ?? '';
        if (!preg_match("/^enum\((?:'(?:''|[^'])*')(?:,'(?:''|[^'])*')*\)$/D", $enum)
            || $type['IS_NULLABLE'] !== 'NO' || $type['EXTRA'] !== '') {
            throw new RuntimeException('Incompatible notification type enum.');
        }
        // Append to the actual enum, preserving every existing type and its ordinal.
        $alter = [];
        if (!str_contains($enum, "'" . self::TYPE . "'")) {
            $new = substr($enum, 0, -1) . ",'" . self::TYPE . "')";
            $default = $type['COLUMN_DEFAULT'];
            // MariaDB returns quoted string defaults; MySQL returns the literal.
            if ($default !== null && str_starts_with($default, "'")) $default = str_replace("''", "'", substr($default, 1, -1));
            $alter[] = 'MODIFY COLUMN type ' . $new . ' CHARACTER SET ' . $type['CHARACTER_SET_NAME']
                . ' COLLATE ' . $type['COLLATION_NAME'] . ' NOT NULL'
                . ($default === null ? '' : ' DEFAULT ' . $this->db->escape($default));
        }
        $unit = $this->column('team_chat_messages', 'unit');
        if (($unit['COLUMN_TYPE'] ?? '') !== 'varchar(16)') throw new RuntimeException('Incompatible chat unit.');
        $present = [];
        foreach (['chat_unit' => 'varchar(16)', 'chat_message_id' => 'bigint unsigned'] as $name => $expected) {
            $meta = $this->column('notifications', $name);
            $present[$name] = $meta !== null;
            if ($meta === null) {
                $alter[] = 'ADD COLUMN ' . $name . ' ' . $expected
                    . ($name === 'chat_unit' ? ' CHARACTER SET ' . $unit['CHARACTER_SET_NAME'] . ' COLLATE ' . $unit['COLLATION_NAME'] : '')
                    . ' NULL DEFAULT NULL';
            } elseif (preg_replace('/\bbigint\(\d+\)/', 'bigint', strtolower($meta['COLUMN_TYPE'])) !== $expected
                || $meta['IS_NULLABLE'] !== 'YES' || !in_array($meta['COLUMN_DEFAULT'], [null, 'NULL'], true)
                || $meta['EXTRA'] !== '' || ($name === 'chat_unit' && $meta['COLLATION_NAME'] !== $unit['COLLATION_NAME'])) {
                throw new RuntimeException('Incompatible chat notification column: ' . $name);
            }
        }
        $scope = $this->index('team_chat_messages', 'notification_chat_message_scope', ['tenant_id', 'unit', 'id'], true);
        foreach ([
            ['notification_chat_delivery', ['tenant_id', 'recipient_user_id', 'chat_unit', 'chat_message_id'], true],
            ['notification_chat_message_scope', ['tenant_id', 'chat_unit', 'chat_message_id'], false],
        ] as [$name, $columns, $unique]) {
            if (!$this->index('notifications', $name, $columns, $unique)) {
                $alter[] = 'ADD ' . ($unique ? 'UNIQUE ' : '') . 'KEY ' . $name . ' (' . implode(',', $columns) . ')';
            }
        }
        if (!$this->foreignKey('notification_chat_message_fk', ['tenant_id', 'chat_unit', 'chat_message_id'],
            'team_chat_messages', ['tenant_id', 'unit', 'id'])) {
            $alter[] = 'ADD CONSTRAINT ' . $this->db->protectIdentifiers($this->db->prefixTable('notification_chat_message_fk'))
                . ' FOREIGN KEY (tenant_id,chat_unit,chat_message_id) REFERENCES {team_chat_messages}(tenant_id,unit,id)'
                . ' ON DELETE RESTRICT ON UPDATE RESTRICT';
        }
        $shape = $this->shape();
        $check = $this->check('notification_chat_shape');
        if ($check === null) {
            $alter[] = 'ADD CONSTRAINT ' . $this->db->protectIdentifiers($this->db->prefixTable('notification_chat_shape')) . ' CHECK (' . $shape . ')';
        } elseif ($this->normalized($check) !== $this->normalized($shape)) {
            throw new RuntimeException('Incompatible chat notification shape constraint.');
        }
        // Preflight partial installations before any implicitly committed DDL.
        $existing = preg_replace_callback('/\bchat_(?:unit|message_id)\b/',
            static fn ($m) => $present[$m[0]] ? $m[0] : 'NULL', $shape);
        $this->rejectRows('SELECT 1 FROM {notifications} WHERE NOT (' . $existing . ') LIMIT 1');
        if (!in_array(false, $present, true)) {
            $this->rejectRows('SELECT 1 FROM {notifications} WHERE chat_message_id IS NOT NULL
                GROUP BY tenant_id,recipient_user_id,chat_unit,chat_message_id HAVING COUNT(*)>1 LIMIT 1');
            $this->rejectRows('SELECT 1 FROM {notifications} n LEFT JOIN {team_chat_messages} m
                ON m.tenant_id=n.tenant_id AND m.unit=n.chat_unit AND m.id=n.chat_message_id
                WHERE n.chat_message_id IS NOT NULL AND m.id IS NULL LIMIT 1');
        }
        if (!$scope) $this->sql('ALTER TABLE {team_chat_messages} ADD UNIQUE KEY notification_chat_message_scope (tenant_id,unit,id)');
        if ($alter !== []) $this->sql('ALTER TABLE {notifications} ' . implode(', ', $alter));
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        throw new RuntimeException('Chat mention notifications are forward-only; preserve delivered notices and read state.');
    }
}

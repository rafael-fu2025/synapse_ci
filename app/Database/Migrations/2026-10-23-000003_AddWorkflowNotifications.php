<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Forward-only outbox/reference extension. Never rewrite delivery or read history. */
final class AddWorkflowNotifications extends Migration
{
    private const TYPES = "'appointment_confirmed','referral_received','announcement_published','survey_published',"
        . "'team_event_added','team_event_removed','team_event_updated','team_event_cancelled',"
        . "'team_task_assigned','team_task_unassigned','team_task_updated','team_task_status','team_task_commented'";
    private const EVENT_COLUMNS = [
        'id' => ['bigint unsigned', false, null, 'auto_increment'],
        'tenant_id' => ['int unsigned', false, null, ''],
        'unit' => ['varchar(16)', false, null, ''],
        'event_key' => ['char(64)', false, null, ''],
        'event_kind' => ['varchar(64)', false, null, ''],
        'subject_kind' => ['varchar(32)', false, null, ''],
        'subject_id' => ['bigint unsigned', false, null, ''],
        'actor_user_id' => ['bigint unsigned', true, null, ''],
        'occurrence_key' => ['varchar(96)', false, null, ''],
        'occurred_at' => ['datetime', false, null, ''],
        'created_at' => ['datetime', false, null, ''],
        'available_at' => ['datetime', false, null, ''],
        'attempts' => ['int unsigned', false, '0', ''],
        'processed_at' => ['datetime', true, null, ''],
        'last_error' => ['varchar(64)', true, null, ''],
    ];

    private function sql(string $sql, array $binds = [])
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($m) => $this->db->protectIdentifiers($this->db->prefixTable($m[1])), $sql);
        $result = $this->db->query($sql, $binds);
        if ($result === false) { throw new RuntimeException('Workflow notification migration failed.'); }
        return $result;
    }

    private function normalized(string $sql): string
    {
        $parts = preg_split("/('(?:''|[^'])*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => &$part) {
            if ($index % 2 === 0) {
                            $part = preg_replace('/\bnot\b/i', '!', $part);
                            $part = preg_replace('/\boctet_length\b/i', 'length', $part);
                            $part = preg_replace('/[\s`()]+/', '', strtolower($part));
                        }
        }
        unset($part);
        return implode('', $parts);
    }

    private function columns(string $table): array
    {
        $rows = $this->sql('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$this->db->prefixTable($table)])->getResultArray();
        return array_column($rows, null, 'COLUMN_NAME');
    }

    private function validateColumn(?array $meta, array $expected, string $name): void
    {
        [$type, $nullable, $default, $extra] = $expected;
        $actual = preg_replace('/\b(bigint|int)\(\d+\)/', '$1', strtolower($meta['COLUMN_TYPE'] ?? ''));
        $actualDefault = $meta['COLUMN_DEFAULT'] ?? null;
        if ($actualDefault === 'NULL') $actualDefault = null;
        if ($actual !== $type || ($meta['IS_NULLABLE'] ?? '') !== ($nullable ? 'YES' : 'NO')
            || $actualDefault !== $default || strtolower($meta['EXTRA'] ?? '') !== $extra) {
            throw new RuntimeException('Incompatible workflow column: ' . $name);
        }
    }

    private function index(string $table, string $name, array $columns, bool $unique): bool
    {
        $rows = $this->sql('SELECT COLUMN_NAME,NON_UNIQUE,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',
            [$this->db->prefixTable($table), $name])->getResultArray();
        if ($rows === []) return false;
        if (array_column($rows, 'COLUMN_NAME') !== $columns
            || array_filter($rows, static fn ($row) => (int) $row['NON_UNIQUE'] !== (int) !$unique
                || $row['SUB_PART'] !== null || strtoupper($row['INDEX_TYPE']) !== 'BTREE') !== []) {
            throw new RuntimeException('Incompatible workflow index: ' . $name);
        }
        return true;
    }

    private function foreignKey(string $table, string $name, array $local, string $parent, array $remote): bool
    {
        $rows = $this->sql('SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
            WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.CONSTRAINT_NAME=? ORDER BY k.ORDINAL_POSITION',
            [$this->db->prefixTable($table), $this->db->prefixTable($name)])->getResultArray();
        if ($rows === []) return false;
        $schema = $this->sql('SELECT DATABASE() AS name')->getRow('name');
        if (array_column($rows, 'COLUMN_NAME') !== $local || array_column($rows, 'REFERENCED_COLUMN_NAME') !== $remote) {
            throw new RuntimeException('Incompatible workflow foreign key: ' . $name);
        }
        foreach ($rows as $row) {
            if ($row['REFERENCED_TABLE_SCHEMA'] !== $schema || $row['REFERENCED_TABLE_NAME'] !== $this->db->prefixTable($parent)
                || $row['DELETE_RULE'] !== 'RESTRICT' || $row['UPDATE_RULE'] !== 'RESTRICT') {
                throw new RuntimeException('Incompatible workflow foreign key: ' . $name);
            }
        }
        return true;
    }

    private function check(string $table, string $name): ?string
    {
        $row = $this->sql("SELECT c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c
            JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME
            WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_NAME=? AND t.CONSTRAINT_TYPE='CHECK'",
            [$this->db->prefixTable($table), $this->db->prefixTable($name)])->getRowArray();
        return $row['CHECK_CLAUSE'] ?? null;
    }

    private function constraint(string $name): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable($name));
    }

    private function eventChecks(): array
    {
        return [
            'workflow_event_unit' => "(unit IN ('clinic','guidance','facilities')) IS TRUE",
            'workflow_event_subject' => '(subject_id>0) IS TRUE',
            // Identifiers only, never action plans, exception messages, credentials or other free text.
            'workflow_event_occurrence' => "(CHAR_LENGTH(occurrence_key)>0 AND LENGTH(occurrence_key)=CHAR_LENGTH(occurrence_key)
                AND NOT (occurrence_key REGEXP '[^A-Za-z0-9_.:-]')) IS TRUE",
            'workflow_event_error' => "(last_error IS NULL OR (CHAR_LENGTH(last_error)>0 AND LENGTH(last_error)=CHAR_LENGTH(last_error)
                AND last_error REGEXP '^[A-Z][A-Z0-9_]*$' AND NOT (last_error REGEXP '[^A-Z0-9_]'))) IS TRUE",
        ];
    }

    private function shape(): string
    {
        return "((type<>'workflow_alert' AND workflow_event_id IS NULL)
            OR (type='workflow_alert' AND workflow_event_id IS NOT NULL AND workflow_event_id>0
                AND ((recipient_user_id IS NOT NULL AND recipient_user_id>0 AND recipient_person_id IS NULL)
                    OR (recipient_person_id IS NOT NULL AND recipient_person_id>0 AND recipient_user_id IS NULL))
                AND appointment_id IS NULL AND appointment_version IS NULL AND referral_id IS NULL
                AND announcement_id IS NULL AND survey_id IS NULL AND team_unit IS NULL AND team_event_id IS NULL
                AND team_task_id IS NULL AND team_version IS NULL AND team_comment_id IS NULL)) IS TRUE";
    }

    private function rejectRows(string $sql): void
    {
        if ($this->sql($sql)->getRowArray() !== null) {
            throw new RuntimeException('Workflow preflight failed; preserve and repair incompatible data before retrying.');
        }
    }

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') { throw new RuntimeException('Workflow notifications require MySQL/MariaDB with InnoDB.'); }
        $eventsExist = false;
        foreach (['tenants', 'users', 'notifications', 'workflow_events'] as $table) {
            $row = $this->sql('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                [$this->db->prefixTable($table)])->getRowArray();
            if ($table === 'workflow_events' && $row === null) continue;
            if (strtoupper($row['ENGINE'] ?? '') !== 'INNODB') { throw new RuntimeException('Missing InnoDB prerequisite: ' . $table); }
            if ($table === 'workflow_events') $eventsExist = true;
        }
        if (!$this->index('users', 'notification_user_tenant', ['tenant_id', 'id'], true)
            || $this->check('notifications', 'notification_team_shape') === null) {
            throw new RuntimeException('Workflow notifications require the tenant user index and Teams notification schema.');
        }
        $columns = $this->columns('notifications');
        foreach (['recipient_person_id', 'recipient_user_id', 'appointment_id', 'appointment_version', 'referral_id',
            'announcement_id', 'survey_id', 'team_unit', 'team_event_id', 'team_task_id', 'team_version', 'team_comment_id'] as $column) {
            if (!isset($columns[$column])) { throw new RuntimeException('Missing notification prerequisite: ' . $column); }
        }
        $old = 'enum(' . self::TYPES . ')';
        $new = 'enum(' . self::TYPES . ",'workflow_alert')";
        if (!in_array($columns['type']['COLUMN_TYPE'] ?? '', [$old, $new], true)
            || ($columns['type']['IS_NULLABLE'] ?? '') !== 'NO') {
            throw new RuntimeException('Incompatible workflow notification types; expected the 13 Teams types.');
        }
        $referenceExists = isset($columns['workflow_event_id']);
        $notificationAlter = [];
        if ($referenceExists) {
            $this->validateColumn($columns['workflow_event_id'], ['bigint unsigned', true, null, ''], 'workflow_event_id');
        } else {
            $notificationAlter[] = 'ADD COLUMN workflow_event_id BIGINT UNSIGNED NULL DEFAULT NULL';
        }
        $eventAlter = [];
        $eventIndexes = [
            ['PRIMARY', ['id'], true],
            ['workflow_event_tenant', ['tenant_id', 'id'], true],
            ['workflow_event_identity', ['tenant_id', 'event_key'], true],
            ['workflow_event_pending', ['processed_at', 'available_at', 'id'], false],
        ];
        $eventFks = [
            ['workflow_event_tenant_fk', ['tenant_id'], 'tenants', ['id']],
            ['workflow_event_actor_fk', ['tenant_id', 'actor_user_id'], 'users', ['tenant_id', 'id']],
        ];
        if ($eventsExist) {
            $eventColumns = $this->columns('workflow_events');
            foreach (self::EVENT_COLUMNS as $name => $expected) {
                $this->validateColumn($eventColumns[$name] ?? null, $expected, 'workflow_events.' . $name);
            }
            if ($eventColumns['event_key']['CHARACTER_SET_NAME'] !== 'ascii' || $eventColumns['event_key']['COLLATION_NAME'] !== 'ascii_bin') {
                throw new RuntimeException('Incompatible workflow event key collation.');
            }
            foreach (['unit', 'occurrence_key', 'last_error'] as $name) {
                if ($eventColumns[$name]['COLLATION_NAME'] !== 'utf8mb4_bin') {
                    throw new RuntimeException('Incompatible workflow identifier collation: ' . $name);
                }
            }
            foreach ($eventIndexes as [$name, $fields, $unique]) {
                if (!$this->index('workflow_events', $name, $fields, $unique)) {
                    $eventAlter[] = $name === 'PRIMARY' ? 'ADD PRIMARY KEY (id)'
                        : 'ADD ' . ($unique ? 'UNIQUE ' : '') . 'KEY ' . $name . ' (' . implode(',', $fields) . ')';
                }
                if ($unique) $this->rejectRows('SELECT 1 FROM {workflow_events} GROUP BY ' . implode(',', $fields) . ' HAVING COUNT(*)>1 LIMIT 1');
            }
            foreach ($eventFks as [$name, $local, $parent, $remote]) {
                if (!$this->foreignKey('workflow_events', $name, $local, $parent, $remote)) {
                    $eventAlter[] = 'ADD CONSTRAINT ' . $this->constraint($name) . ' FOREIGN KEY (' . implode(',', $local)
                        . ') REFERENCES {' . $parent . '}(' . implode(',', $remote) . ') ON DELETE RESTRICT ON UPDATE RESTRICT';
                }
            }
            $this->rejectRows('SELECT 1 FROM {workflow_events} e LEFT JOIN {tenants} t ON t.id=e.tenant_id WHERE t.id IS NULL LIMIT 1');
            $this->rejectRows('SELECT 1 FROM {workflow_events} e LEFT JOIN {users} u ON u.tenant_id=e.tenant_id AND u.id=e.actor_user_id
                WHERE e.actor_user_id IS NOT NULL AND u.id IS NULL LIMIT 1');
            foreach ($this->eventChecks() as $name => $expression) {
                $this->planCheck('workflow_events', $name, $expression, $eventAlter);
                $this->rejectRows('SELECT 1 FROM {workflow_events} WHERE NOT (' . $expression . ') LIMIT 1');
            }
        }
        foreach ([
            ['notification_workflow_user_delivery', ['tenant_id', 'workflow_event_id', 'recipient_user_id']],
            ['notification_workflow_person_delivery', ['tenant_id', 'workflow_event_id', 'recipient_person_id']],
        ] as [$name, $fields]) {
            if (!$this->index('notifications', $name, $fields, true)) {
                $notificationAlter[] = 'ADD UNIQUE KEY ' . $name . ' (' . implode(',', $fields) . ')';
            }
            if ($referenceExists) {
                $this->rejectRows('SELECT 1 FROM {notifications} WHERE workflow_event_id IS NOT NULL AND ' . $fields[2]
                    . ' IS NOT NULL GROUP BY ' . implode(',', $fields) . ' HAVING COUNT(*)>1 LIMIT 1');
            }
        }
        if (!$this->foreignKey('notifications', 'notification_workflow_event_fk', ['tenant_id', 'workflow_event_id'], 'workflow_events', ['tenant_id', 'id'])) {
            $notificationAlter[] = 'ADD CONSTRAINT ' . $this->constraint('notification_workflow_event_fk')
                . ' FOREIGN KEY (tenant_id,workflow_event_id) REFERENCES {workflow_events}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT';
        }
        $shape = $this->shape();
        $this->planCheck('notifications', 'notification_workflow_shape', $shape, $notificationAlter);
        $this->rejectRows('SELECT 1 FROM {notifications} WHERE NOT ('
            . ($referenceExists ? $shape : str_replace('workflow_event_id', 'NULL', $shape)) . ') LIMIT 1');
        if ($referenceExists) {
            $this->rejectRows($eventsExist
                ? 'SELECT 1 FROM {notifications} n LEFT JOIN {workflow_events} e ON e.tenant_id=n.tenant_id AND e.id=n.workflow_event_id
                    WHERE n.workflow_event_id IS NOT NULL AND e.id IS NULL LIMIT 1'
                : 'SELECT 1 FROM {notifications} WHERE workflow_event_id IS NOT NULL LIMIT 1');
        }
        if ($columns['type']['COLUMN_TYPE'] !== $new) $notificationAlter[] = 'MODIFY type ' . $new . ' NOT NULL';

        // All schema/data preflights precede the first implicitly committed DDL statement.
        if (!$eventsExist) {
            $checks = [];
            foreach ($this->eventChecks() as $name => $expression) {
                $checks[] = 'CONSTRAINT ' . $this->constraint($name) . ' CHECK (' . $expression . ')';
            }
            // DATETIME has no timezone: producers must supply UTC occurred_at values.
            $this->sql('CREATE TABLE {workflow_events} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL,
                unit VARCHAR(16) NOT NULL,
                event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                event_kind VARCHAR(64) NOT NULL,
                subject_kind VARCHAR(32) NOT NULL,
                subject_id BIGINT UNSIGNED NOT NULL,
                actor_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
                occurrence_key VARCHAR(96) NOT NULL,
                occurred_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                processed_at DATETIME NULL DEFAULT NULL,
                last_error VARCHAR(64) NULL DEFAULT NULL,
                UNIQUE KEY workflow_event_tenant (tenant_id,id),
                UNIQUE KEY workflow_event_identity (tenant_id,event_key),
                KEY workflow_event_pending (processed_at,available_at,id),
                CONSTRAINT ' . $this->constraint('workflow_event_tenant_fk') . ' FOREIGN KEY (tenant_id) REFERENCES {tenants}(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT ' . $this->constraint('workflow_event_actor_fk') . ' FOREIGN KEY (tenant_id,actor_user_id) REFERENCES {users}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                ' . implode(', ', $checks) . '
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        } elseif ($eventAlter !== []) {
            $this->sql('ALTER TABLE {workflow_events} ' . implode(', ', $eventAlter));
        }
        if ($notificationAlter !== []) $this->sql('ALTER TABLE {notifications} ' . implode(', ', $notificationAlter));
        $this->db->resetDataCache();
    }

    private function planCheck(string $table, string $name, string $expression, array &$alter): void
    {
        $existing = $this->check($table, $name);
        if ($existing === null) {
            $alter[] = 'ADD CONSTRAINT ' . $this->constraint($name) . ' CHECK (' . $expression . ')';
        } elseif ($this->normalized($existing) !== $this->normalized($expression)) {
            throw new RuntimeException('Incompatible workflow check: ' . $name);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Workflow notifications are forward-only; preserve events, deliveries and read state.');
    }
}

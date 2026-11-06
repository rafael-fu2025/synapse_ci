<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Forward-only, restartable DDL. No historical referral delivery or read-state rewrite. */
final class AddStudentReferralNotifications extends Migration
{
    private function sql(string $sql, array $binds = [])
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($m) => $this->db->protectIdentifiers($this->db->prefixTable($m[1])), $sql);
        $result = $this->db->query($sql, $binds);
        if ($result === false) throw new RuntimeException('Student referral notification migration failed.');
        return $result;
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

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') throw new RuntimeException('Student referral notices require MySQL/MariaDB.');
        $table = $this->db->prefixTable('notifications');
        $type = $this->sql('SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$table, 'type'])->getRowArray();
        $enum = $type['COLUMN_TYPE'] ?? '';
        if (!preg_match("/^enum\((?:'(?:''|[^'])*')(?:,'(?:''|[^'])*')*\)$/D", $enum)
            || $type['IS_NULLABLE'] !== 'NO' || $type['EXTRA'] !== '' || !str_contains($enum, "'referral_received'")) {
            throw new RuntimeException('Incompatible notification type enum.');
        }
        $alter = [];
        if (!str_contains($enum, "'student_referral'")) {
            $default = $type['COLUMN_DEFAULT'];
            if ($default !== null && str_starts_with($default, "'")) $default = str_replace("''", "'", substr($default, 1, -1));
            $alter[] = 'MODIFY COLUMN type ' . substr($enum, 0, -1) . ",'student_referral') CHARACTER SET "
                . $type['CHARACTER_SET_NAME'] . ' COLLATE ' . $type['COLLATION_NAME'] . ' NOT NULL'
                . ($default === null ? '' : ' DEFAULT ' . $this->db->escape($default));
        }
        // NULL person IDs keep existing staff deliveries outside this uniqueness key.
        $columns = ['tenant_id', 'referral_id', 'recipient_person_id', 'type'];
        $index = $this->sql('SELECT COLUMN_NAME,NON_UNIQUE,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',
            [$table, 'notification_student_referral_delivery'])->getResultArray();
        if ($index === []) {
            $alter[] = 'ADD UNIQUE KEY notification_student_referral_delivery (' . implode(',', $columns) . ')';
        } elseif (array_column($index, 'COLUMN_NAME') !== $columns || array_filter($index,
            static fn ($r) => (int) $r['NON_UNIQUE'] !== 0 || $r['SUB_PART'] !== null || strtoupper($r['INDEX_TYPE']) !== 'BTREE') !== []) {
            throw new RuntimeException('Incompatible student referral delivery index.');
        }
        $shape = "(type<>'student_referral' OR (recipient_person_id IS NOT NULL AND recipient_person_id>0
            AND recipient_user_id IS NULL AND referral_id IS NOT NULL AND referral_id>0
            AND appointment_id IS NULL AND appointment_version IS NULL AND announcement_id IS NULL AND survey_id IS NULL
            AND workflow_event_id IS NULL AND team_unit IS NULL AND team_event_id IS NULL AND team_task_id IS NULL
            AND team_version IS NULL AND team_comment_id IS NULL AND chat_unit IS NULL AND chat_message_id IS NULL)) IS TRUE";
        $name = $this->db->prefixTable('notification_student_referral_shape');
        $check = $this->sql("SELECT c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c
            JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME
            WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_NAME=? AND t.CONSTRAINT_TYPE='CHECK'",
            [$table, $name])->getRowArray();
        if ($check === null) $alter[] = 'ADD CONSTRAINT ' . $this->db->protectIdentifiers($name) . ' CHECK (' . $shape . ')';
        elseif ($this->normalized($check['CHECK_CLAUSE']) !== $this->normalized($shape)) {
            throw new RuntimeException('Incompatible student referral shape constraint.');
        }
        // Check partial installations before any implicitly committed DDL. Never repair by deleting notices.
        foreach (['SELECT 1 FROM {notifications} WHERE NOT (' . $shape . ') LIMIT 1',
            'SELECT 1 FROM {notifications} WHERE referral_id IS NOT NULL AND recipient_person_id IS NOT NULL
                GROUP BY tenant_id,referral_id,recipient_person_id,type HAVING COUNT(*)>1 LIMIT 1'] as $sql) {
            if ($this->sql($sql)->getRowArray() !== null) throw new RuntimeException('Student referral preflight failed; preserve and repair existing notices.');
        }
        if ($alter !== []) $this->sql('ALTER TABLE {notifications} ' . implode(', ', $alter));
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        throw new RuntimeException('Student referral notices are forward-only; preserve delivery and read state.');
    }
}

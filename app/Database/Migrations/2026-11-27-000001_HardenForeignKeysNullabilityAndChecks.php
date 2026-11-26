<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Tier 1 Phase 1 database integrity hardening:
 * - Repairs 14 inverted ON DELETE CASCADE foreign keys caused by Forge argument ordering.
 * - Restores NOT NULL on accidentally nullable state/version/rotations columns.
 * - Adds missing UNIQUE and CHECK integrity constraints on audit_events, push_tokens, notifications, and referrals.
 */
final class HardenForeignKeysNullabilityAndChecks extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: 'SET NULL'|'RESTRICT'}> */
    private const INVERTED_FKS = [
        ['users', 'person_id', 'people', 'id', 'fk_users_person_id', 'SET NULL'],
        ['appointments', 'booked_by_user_id', 'users', 'id', 'fk_appointments_booked_by', 'SET NULL'],
        ['queue_entries', 'appointment_id', 'appointments', 'id', 'fk_queue_entries_appointment', 'SET NULL'],
        ['queue_entries', 'called_by_user_id', 'users', 'id', 'fk_queue_entries_called_by', 'SET NULL'],
        ['referrals', 'assigned_user_id', 'users', 'id', 'fk_referrals_assigned_user', 'SET NULL'],
        ['bmg_devices', 'unit_id', 'bmg_units', 'id', 'fk_bmg_devices_unit', 'SET NULL'],
        ['clinic_lab_results', 'encounter_id', 'clinic_encounters', 'id', 'fk_clinic_lab_encounter', 'SET NULL'],
        ['clinic_lab_results', 'recorded_by_user_id', 'users', 'id', 'fk_clinic_lab_recorded_by', 'RESTRICT'],
        ['clinic_encounter_addenda', 'lab_result_id', 'clinic_lab_results', 'id', 'fk_clinic_addendum_lab', 'SET NULL'],
        ['clinic_encounter_addenda', 'author_user_id', 'users', 'id', 'fk_clinic_addendum_author', 'RESTRICT'],
        ['clinic_enrollment_clearances', 'cleared_by_user_id', 'users', 'id', 'fk_clinic_clearance_cleared_by', 'SET NULL'],
        ['clinic_isolation_cases', 'encounter_id', 'clinic_encounters', 'id', 'fk_clinic_iso_encounter', 'SET NULL'],
        ['clinic_isolation_cases', 'cleared_by_user_id', 'users', 'id', 'fk_clinic_iso_cleared_by', 'SET NULL'],
        ['clinic_isolation_cases', 'reported_by_user_id', 'users', 'id', 'fk_clinic_iso_reported_by', 'RESTRICT'],
    ];

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }

        $this->repairInvertedForeignKeys();
        $this->restoreNotNullColumns();
        $this->addIntegrityUniqueAndChecks();
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        throw new RuntimeException('Tier 1 FK/nullability/check hardening is forward-only; restoring unsafe CASCADE FKs is disallowed.');
    }

    private function repairInvertedForeignKeys(): void
    {
        foreach (self::INVERTED_FKS as [$table, $column, $refTable, $refColumn, $newConstraint, $newDeleteRule]) {
            $prefixedTable = $this->db->prefixTable($table);
            $prefixedRef   = $this->db->prefixTable($refTable);
            $targetFkName  = $this->constraintName($newConstraint);

            $rows = $this->sql(
                'SELECT k.CONSTRAINT_NAME, r.DELETE_RULE
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                   ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
                  AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                  AND r.TABLE_NAME = k.TABLE_NAME
                 WHERE k.TABLE_SCHEMA = DATABASE()
                   AND k.TABLE_NAME = ?
                   AND k.COLUMN_NAME = ?
                   AND k.REFERENCED_TABLE_NAME = ?
                   AND k.REFERENCED_COLUMN_NAME = ?
                   AND k.ORDINAL_POSITION = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM information_schema.KEY_COLUMN_USAGE k2
                       WHERE k2.TABLE_SCHEMA = k.TABLE_SCHEMA
                         AND k2.TABLE_NAME = k.TABLE_NAME
                         AND k2.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                         AND k2.ORDINAL_POSITION > 1
                   )',
                [$prefixedTable, $column, $prefixedRef, $refColumn]
            )->getResultArray();

            $hasSafeRule = false;
            $dropNames   = [];
            foreach ($rows as $row) {
                if (strtoupper((string) $row['DELETE_RULE']) === $newDeleteRule) {
                    $hasSafeRule = true;
                } else {
                    $dropNames[] = (string) $row['CONSTRAINT_NAME'];
                }
            }

            if ($dropNames === [] && $hasSafeRule) {
                continue;
            }

            $parts = [];
            foreach ($dropNames as $dropName) {
                $parts[] = 'DROP FOREIGN KEY ' . $this->db->protectIdentifiers($dropName);
            }
            if (! $hasSafeRule) {
                // Avoid colliding if a prior constraint with the same name had the wrong rule.
                $fkNameToUse = in_array($targetFkName, $dropNames, true) || ! $this->constraintExists($table, $targetFkName)
                    ? $targetFkName
                    : $this->constraintName($newConstraint . '_v2');

                $parts[] = 'ADD CONSTRAINT ' . $this->db->protectIdentifiers($fkNameToUse)
                    . ' FOREIGN KEY (' . $this->db->protectIdentifiers($column) . ')'
                    . ' REFERENCES {' . $refTable . '}(' . $this->db->protectIdentifiers($refColumn) . ')'
                    . ' ON DELETE ' . $newDeleteRule
                    . ' ON UPDATE RESTRICT';
            }

            if ($parts !== []) {
                $this->sql('ALTER TABLE {' . $table . '} ' . implode(', ', $parts));
            }
        }
    }

    private function restoreNotNullColumns(): void
    {
        // appointments.status & appointments.version
        $this->sql("UPDATE {appointments} SET status = 'scheduled' WHERE status IS NULL");
        $this->sql('UPDATE {appointments} SET version = 1 WHERE version IS NULL');

        $apptParts = [];
        if ($this->isColumnNullable('appointments', 'status')) {
            $apptParts[] = "MODIFY COLUMN status ENUM('requested','scheduled','confirmed','checked_in','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled'";
        }
        if ($this->isColumnNullable('appointments', 'version')) {
            $apptParts[] = 'MODIFY COLUMN version INT UNSIGNED NOT NULL DEFAULT 1';
        }
        if ($apptParts !== []) {
            $this->sql('ALTER TABLE {appointments} ' . implode(', ', $apptParts));
        }

        // bmg_rotation_schedules.rotations & bmg_rotation_runs.rotations
        $this->sql('UPDATE {bmg_rotation_schedules} SET rotations = 7 WHERE rotations IS NULL');
        if ($this->isColumnNullable('bmg_rotation_schedules', 'rotations')) {
            $this->sql('ALTER TABLE {bmg_rotation_schedules} MODIFY COLUMN rotations INT UNSIGNED NOT NULL DEFAULT 7');
        }

        $this->sql('UPDATE {bmg_rotation_runs} SET rotations = 0 WHERE rotations IS NULL');
        if ($this->isColumnNullable('bmg_rotation_runs', 'rotations')) {
            $this->sql('ALTER TABLE {bmg_rotation_runs} MODIFY COLUMN rotations INT UNSIGNED NOT NULL DEFAULT 0');
        }
    }

    private function addIntegrityUniqueAndChecks(): void
    {
        // 1. audit_events: unique chain predecessor per tenant prevents chain forks
        if (! $this->uniqueIndexExists('audit_events', ['tenant_id', 'prev_hash'])) {
            $dup = $this->sql('SELECT tenant_id, prev_hash FROM {audit_events} GROUP BY tenant_id, prev_hash HAVING COUNT(*) > 1 LIMIT 1')->getRowArray();
            if ($dup !== null) {
                throw new RuntimeException('Preflight STOP: duplicate (tenant_id, prev_hash) in audit_events; repair hash chain before applying unique index.');
            }
            $this->sql('ALTER TABLE {audit_events} ADD UNIQUE KEY uq_audit_tenant_prev_hash (tenant_id, prev_hash)');
        }

        // 2. push_tokens: active_lan_installation_id must stay synchronized with installation_id and revocation state
        $pushCheckSql = '(active_lan_installation_id IS NULL OR (active_lan_installation_id = installation_id AND revoked_at IS NULL)) IS TRUE';
        $this->ensureCheckConstraint(
            'push_tokens',
            'chk_push_lan_active_sync',
            $pushCheckSql,
            'Preflight STOP: push_tokens has rows where active_lan_installation_id disagrees with installation_id/revoked_at.'
        );

        // 3. notifications: mutual-exclusivity shape CHECK for engagement types (permits legacy NULL-recipient rows)
        $notifCheckSql = "((type <> 'announcement_published' OR (recipient_user_id IS NULL AND survey_id IS NULL AND appointment_id IS NULL AND appointment_version IS NULL AND referral_id IS NULL AND workflow_event_id IS NULL AND team_unit IS NULL AND team_event_id IS NULL AND team_task_id IS NULL AND team_version IS NULL AND team_comment_id IS NULL AND chat_unit IS NULL AND chat_message_id IS NULL)) AND (type <> 'survey_published' OR (recipient_user_id IS NULL AND announcement_id IS NULL AND appointment_id IS NULL AND appointment_version IS NULL AND referral_id IS NULL AND workflow_event_id IS NULL AND team_unit IS NULL AND team_event_id IS NULL AND team_task_id IS NULL AND team_version IS NULL AND team_comment_id IS NULL AND chat_unit IS NULL AND chat_message_id IS NULL))) IS TRUE";
        $this->ensureCheckConstraint(
            'notifications',
            'chk_notif_engagement_shape',
            $notifCheckSql,
            'Preflight STOP: notifications contains malformed announcement_published or survey_published rows.'
        );

        // 4. referrals: cross-module distinctness and exclusive target encounter/case arc
        $this->ensureCheckConstraint(
            'referrals',
            'chk_ref_distinct_modules',
            '(source_module <> target_module) IS TRUE',
            'Preflight STOP: referrals contains rows with source_module = target_module.'
        );
        $this->ensureCheckConstraint(
            'referrals',
            'chk_ref_target_arc',
            '(NOT (target_encounter_id IS NOT NULL AND target_case_id IS NOT NULL)) IS TRUE',
            'Preflight STOP: referrals contains rows setting both target_encounter_id and target_case_id.'
        );
    }

    private function ensureCheckConstraint(string $table, string $baseName, string $expr, string $failMsg): void
    {
        $prefixedTable = $this->db->prefixTable($table);
        $checkName     = $this->constraintName($baseName);

        $existing = $this->sql(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = 'CHECK'",
            [$prefixedTable, $checkName]
        )->getRowArray();

        if ($existing !== null) {
            return;
        }

        $bad = $this->sql('SELECT 1 FROM {' . $table . '} WHERE NOT (' . $expr . ') LIMIT 1')->getRowArray();
        if ($bad !== null) {
            throw new RuntimeException($failMsg);
        }

        $this->sql('ALTER TABLE {' . $table . '} ADD CONSTRAINT ' . $this->db->protectIdentifiers($checkName) . ' CHECK (' . $expr . ')');
    }

    private function isColumnNullable(string $table, string $column): bool
    {
        $row = $this->sql(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column]
        )->getRowArray();

        return $row !== null && strtoupper((string) ($row['IS_NULLABLE'] ?? '')) === 'YES';
    }

    private function constraintExists(string $table, string $name): bool
    {
        return $this->sql(
            'SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$this->db->prefixTable($table), $name]
        )->getRowArray() !== null;
    }

    /** @param list<string> $columns */
    private function uniqueIndexExists(string $table, array $columns): bool
    {
        $rows = $this->sql(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->db->prefixTable($table)]
        )->getResultArray();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['INDEX_NAME']][] = $row;
        }

        foreach ($grouped as $indexRows) {
            if ((int) $indexRows[0]['NON_UNIQUE'] !== 0) {
                continue;
            }
            if (array_slice(array_column($indexRows, 'COLUMN_NAME'), 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    private function constraintName(string $name): string
    {
        $prefix    = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $this->db->DBPrefix);
        $candidate = $prefix . $name;

        if (strlen($candidate) <= 64) {
            return $candidate;
        }

        $suffixLen = min(40, strlen($name));
        return substr($prefix, 0, 15) . '_' . substr(hash('sha256', $prefix . $name), 0, 8) . '_' . substr($name, -$suffixLen);
    }

    private function sql(string $sql, array $binds = [])
    {
        $sql = preg_replace_callback(
            '/\{([a-z0-9_]+)\}/',
            fn (array $m): string => $this->db->protectIdentifiers($this->db->prefixTable($m[1])),
            $sql
        );

        $result = $this->db->query($sql, $binds);
        if ($result === false) {
            throw new RuntimeException('HardenForeignKeysNullabilityAndChecks query failed.');
        }

        return $result;
    }
}

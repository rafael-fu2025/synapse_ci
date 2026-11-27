<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Tier 2 Phase 4 1NF normalization of operational multi-valued attributes into child/junction tables:
 * - Group A (drop legacy JSON columns after backfill):
 *   1. bmg_rotation_schedules.times_json -> bmg_rotation_schedule_slots
 *   2. clinic_enrollment_clearances.required_labs_json -> clinic_enrollment_clearance_labs
 *   3. team_chat_messages.task_ids -> team_chat_message_tasks
 *   4. team_chat_messages.mentions -> team_chat_message_mentions
 * - Group B (expand + backfill; keep legacy JSON columns for direct-SQL test compatibility):
 *   5. guidance_activation_gate_signoffs
 *   6. guidance_practitioner_scopes
 *   7. guidance_service_evaluation_ratings
 *   8. announcement_target_year_levels & announcement_target_categories
 *   9. survey_target_year_levels & survey_target_categories
 */
final class NormalizeOperational1nfChildTables extends Migration
{
    private const ALLOWED_STUDENT_CATEGORIES = ['new', 'continuing', 'transferee', 'graduating'];

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }

        $this->normalizeBmgScheduleSlots();
        $this->normalizeClinicClearanceLabs();
        $this->normalizeTeamChatTasksAndMentions();
        $this->normalizeGuidanceActivationGateSignoffs();
        $this->normalizeGuidancePractitionerScopes();
        $this->normalizeGuidanceEvaluationRatings();
        $this->normalizeAudienceTargets('announcements', 'announcement_id', 'announcement_target_year_levels', 'announcement_target_categories');
        $this->normalizeAudienceTargets('surveys', 'survey_id', 'survey_target_year_levels', 'survey_target_categories');

        $this->db->resetDataCache();
    }

    public function down(): void
    {
        throw new RuntimeException('Tier 2 Phase 4 1NF normalization is forward-only.');
    }

    private function normalizeBmgScheduleSlots(): void
    {
        if (! $this->db->tableExists('bmg_rotation_schedules')) {
            return;
        }

        $this->ensureUniqueKey('bmg_rotation_schedules', 'uq_brs_tenant_id', ['tenant_id', 'id']);

        if (! $this->db->tableExists('bmg_rotation_schedule_slots')) {
            $this->sql(
                'CREATE TABLE {bmg_rotation_schedule_slots} (
                    tenant_id INT(10) UNSIGNED NOT NULL,
                    schedule_id BIGINT(20) UNSIGNED NOT NULL,
                    slot_order SMALLINT(5) UNSIGNED NOT NULL,
                    run_time CHAR(5) NOT NULL,
                    PRIMARY KEY (tenant_id, schedule_id, slot_order),
                    UNIQUE KEY uq_brss_schedule_time (tenant_id, schedule_id, run_time),
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_brss_schedule')) . '
                        FOREIGN KEY (tenant_id, schedule_id) REFERENCES {bmg_rotation_schedules}(tenant_id, id)
                        ON DELETE CASCADE ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        if ($this->db->fieldExists('times_json', 'bmg_rotation_schedules')) {
            $rows = $this->db->table('bmg_rotation_schedules')
                ->select('id, tenant_id, times_json')
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $times = json_decode((string) ($row['times_json'] ?? '[]'), true);
                if (! is_array($times)) {
                    continue;
                }
                $order = 0;
                $seen  = [];
                foreach ($times as $time) {
                    if (! is_string($time) || $time === '' || isset($seen[$time])) {
                        continue;
                    }
                    $seen[$time] = true;
                    $this->sql(
                        'INSERT IGNORE INTO {bmg_rotation_schedule_slots} (tenant_id, schedule_id, slot_order, run_time)
                         VALUES (?, ?, ?, ?)',
                        [(int) $row['tenant_id'], (int) $row['id'], $order++, substr($time, 0, 5)]
                    );
                }
            }
            $this->sql('ALTER TABLE {bmg_rotation_schedules} DROP COLUMN ' . $this->db->protectIdentifiers('times_json'));
        }
    }

    private function normalizeClinicClearanceLabs(): void
    {
        if (! $this->db->tableExists('clinic_enrollment_clearances')) {
            return;
        }

        $this->ensureUniqueKey('clinic_enrollment_clearances', 'uq_cec_tenant_id', ['tenant_id', 'id']);

        if (! $this->db->tableExists('clinic_enrollment_clearance_labs')) {
            $this->sql(
                'CREATE TABLE {clinic_enrollment_clearance_labs} (
                    tenant_id INT(10) UNSIGNED NOT NULL,
                    clearance_id BIGINT(20) UNSIGNED NOT NULL,
                    lab_key VARCHAR(48) NOT NULL,
                    is_completed TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (tenant_id, clearance_id, lab_key),
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_cecl_clearance')) . '
                        FOREIGN KEY (tenant_id, clearance_id) REFERENCES {clinic_enrollment_clearances}(tenant_id, id)
                        ON DELETE CASCADE ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        if ($this->db->fieldExists('required_labs_json', 'clinic_enrollment_clearances')) {
            $rows = $this->db->table('clinic_enrollment_clearances')
                ->select('id, tenant_id, required_labs_json')
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $decoded = json_decode((string) ($row['required_labs_json'] ?? '{}'), true);
                if (! is_array($decoded)) {
                    continue;
                }
                foreach ($decoded as $key => $val) {
                    if (is_int($key) && is_string($val)) {
                        $labKey = trim($val);
                        $done   = 1;
                    } elseif (is_string($key)) {
                        $labKey = trim($key);
                        $done   = ! empty($val) ? 1 : 0;
                    } else {
                        continue;
                    }
                    if ($labKey === '') {
                        continue;
                    }
                    $this->sql(
                        'INSERT IGNORE INTO {clinic_enrollment_clearance_labs} (tenant_id, clearance_id, lab_key, is_completed)
                         VALUES (?, ?, ?, ?)',
                        [(int) $row['tenant_id'], (int) $row['id'], substr($labKey, 0, 48), $done]
                    );
                }
            }
            $this->sql('ALTER TABLE {clinic_enrollment_clearances} DROP COLUMN ' . $this->db->protectIdentifiers('required_labs_json'));
        }
    }

    private function normalizeTeamChatTasksAndMentions(): void
    {
        if (! $this->db->tableExists('team_chat_messages')) {
            return;
        }

        $this->ensureUniqueKey('team_chat_messages', 'notification_chat_message_scope', ['tenant_id', 'unit', 'id']);
        if ($this->db->tableExists('team_tasks')) {
            $this->ensureUniqueKey('team_tasks', 'uq_team_tasks_scope_id', ['tenant_id', 'unit', 'id']);
        }

        if (! $this->db->tableExists('team_chat_message_tasks')) {
            $this->sql(
                'CREATE TABLE {team_chat_message_tasks} (
                    tenant_id INT(10) UNSIGNED NOT NULL,
                    unit VARCHAR(16) NOT NULL,
                    message_id BIGINT(20) UNSIGNED NOT NULL,
                    task_id BIGINT(20) UNSIGNED NOT NULL,
                    position SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (tenant_id, unit, message_id, task_id),
                    KEY idx_tcmt_order (tenant_id, unit, message_id, position),
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_tcmt_message')) . '
                        FOREIGN KEY (tenant_id, unit, message_id) REFERENCES {team_chat_messages}(tenant_id, unit, id)
                        ON DELETE CASCADE ON UPDATE RESTRICT,
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_tcmt_task')) . '
                        FOREIGN KEY (tenant_id, unit, task_id) REFERENCES {team_tasks}(tenant_id, unit, id)
                        ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        if (! $this->db->tableExists('team_chat_message_mentions')) {
            $this->sql(
                'CREATE TABLE {team_chat_message_mentions} (
                    tenant_id INT(10) UNSIGNED NOT NULL,
                    unit VARCHAR(16) NOT NULL,
                    message_id BIGINT(20) UNSIGNED NOT NULL,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    start_offset SMALLINT(5) UNSIGNED NOT NULL,
                    length SMALLINT(5) UNSIGNED NOT NULL,
                    PRIMARY KEY (tenant_id, unit, message_id, start_offset),
                    KEY idx_tcmm_user (tenant_id, user_id, message_id),
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_tcmm_message')) . '
                        FOREIGN KEY (tenant_id, unit, message_id) REFERENCES {team_chat_messages}(tenant_id, unit, id)
                        ON DELETE CASCADE ON UPDATE RESTRICT,
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_tcmm_user')) . '
                        FOREIGN KEY (tenant_id, user_id) REFERENCES {users}(tenant_id, id)
                        ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $hasTaskIdsCol  = $this->db->fieldExists('task_ids', 'team_chat_messages');
        $hasMentionsCol = $this->db->fieldExists('mentions', 'team_chat_messages');
        if ($hasTaskIdsCol || $hasMentionsCol) {
            $select = 'id, tenant_id, unit, task_id'
                . ($hasTaskIdsCol ? ', task_ids' : '')
                . ($hasMentionsCol ? ', mentions' : '');
            $rows = $this->db->table('team_chat_messages')->select($select)->get()->getResultArray();

            foreach ($rows as $row) {
                $tenantId  = (int) $row['tenant_id'];
                $unit      = (string) $row['unit'];
                $messageId = (int) $row['id'];

                if ($hasTaskIdsCol) {
                    $ids = ($row['task_ids'] ?? null) !== null
                        ? json_decode((string) $row['task_ids'], true)
                        : ($row['task_id'] !== null ? [(int) $row['task_id']] : []);
                    if (is_array($ids)) {
                        $pos = 0;
                        foreach ($ids as $tid) {
                            $tidInt = (int) $tid;
                            if ($tidInt <= 0) {
                                continue;
                            }
                            $exists = $this->sql(
                                'SELECT 1 FROM {team_tasks} WHERE tenant_id = ? AND unit = ? AND id = ? LIMIT 1',
                                [$tenantId, $unit, $tidInt]
                            )->getRowArray();
                            if ($exists === null) {
                                continue;
                            }
                            $this->sql(
                                'INSERT IGNORE INTO {team_chat_message_tasks} (tenant_id, unit, message_id, task_id, position)
                                 VALUES (?, ?, ?, ?, ?)',
                                [$tenantId, $unit, $messageId, $tidInt, $pos++]
                            );
                        }
                    }
                }

                if ($hasMentionsCol) {
                    $mentions = json_decode((string) ($row['mentions'] ?? '[]'), true);
                    if (is_array($mentions)) {
                        foreach ($mentions as $m) {
                            if (! is_array($m)) {
                                continue;
                            }
                            $uid = (int) ($m['user_id'] ?? 0);
                            if ($uid <= 0) {
                                continue;
                            }
                            $exists = $this->sql(
                                'SELECT 1 FROM {users} WHERE tenant_id = ? AND id = ? LIMIT 1',
                                [$tenantId, $uid]
                            )->getRowArray();
                            if ($exists === null) {
                                continue;
                            }
                            $this->sql(
                                'INSERT IGNORE INTO {team_chat_message_mentions} (tenant_id, unit, message_id, user_id, start_offset, length)
                                 VALUES (?, ?, ?, ?, ?, ?)',
                                [$tenantId, $unit, $messageId, $uid, max(0, (int) ($m['start'] ?? 0)), max(1, (int) ($m['length'] ?? 1))]
                            );
                        }
                    }
                }
            }

            $drops = [];
            if ($hasTaskIdsCol) {
                $drops[] = 'DROP COLUMN ' . $this->db->protectIdentifiers('task_ids');
            }
            if ($hasMentionsCol) {
                $drops[] = 'DROP COLUMN ' . $this->db->protectIdentifiers('mentions');
            }
            if ($drops !== []) {
                $this->sql('ALTER TABLE {team_chat_messages} ' . implode(', ', $drops));
            }
        }
    }

    private function normalizeGuidanceActivationGateSignoffs(): void
    {
        if (! $this->db->tableExists('guidance_activation_gates')) {
            return;
        }

        $this->ensureUniqueKey('guidance_activation_gates', 'uq_gag_tenant_id', ['tenant_id', 'id']);

        if (! $this->db->tableExists('guidance_activation_gate_signoffs')) {
            $this->sql(
                'CREATE TABLE {guidance_activation_gate_signoffs} (
                    tenant_id INT(10) UNSIGNED NOT NULL,
                    gate_id BIGINT(20) UNSIGNED NOT NULL,
                    authority_role VARCHAR(32) NOT NULL,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    notes TEXT DEFAULT NULL,
                    signed_at DATETIME NOT NULL,
                    PRIMARY KEY (tenant_id, gate_id, authority_role),
                    KEY idx_gags_user (tenant_id, user_id),
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_gags_gate')) . '
                        FOREIGN KEY (tenant_id, gate_id) REFERENCES {guidance_activation_gates}(tenant_id, id)
                        ON DELETE CASCADE ON UPDATE RESTRICT,
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_gags_user')) . '
                        FOREIGN KEY (tenant_id, user_id) REFERENCES {users}(tenant_id, id)
                        ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $rows = $this->db->table('guidance_activation_gates')
            ->select('id, tenant_id, recorded_signoffs_json, created_at')
            ->get()->getResultArray();
        foreach ($rows as $row) {
            $signoffs = json_decode((string) ($row['recorded_signoffs_json'] ?? '{}'), true);
            if (! is_array($signoffs)) {
                continue;
            }
            foreach ($signoffs as $roleKey => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $role   = trim((string) ($entry['authority_role'] ?? $roleKey));
                $userId = (int) ($entry['user_id'] ?? 0);
                if ($role === '' || $userId <= 0) {
                    continue;
                }
                $userExists = $this->sql(
                    'SELECT 1 FROM {users} WHERE tenant_id = ? AND id = ? LIMIT 1',
                    [(int) $row['tenant_id'], $userId]
                )->getRowArray();
                if ($userExists === null) {
                    continue;
                }
                $signedAt = trim((string) ($entry['signed_at'] ?? $row['created_at'] ?? gmdate('Y-m-d H:i:s')));
                $this->sql(
                    'INSERT IGNORE INTO {guidance_activation_gate_signoffs}
                     (tenant_id, gate_id, authority_role, user_id, notes, signed_at)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [(int) $row['tenant_id'], (int) $row['id'], substr($role, 0, 32), $userId, $entry['notes'] ?? null, $signedAt]
                );
            }
        }
    }

    private function normalizeGuidancePractitionerScopes(): void
    {
        if (! $this->db->tableExists('guidance_practitioner_profiles')) {
            return;
        }

        $this->ensureUniqueKey('guidance_practitioner_profiles', 'uq_gpp_tenant_id', ['tenant_id', 'id']);

        if (! $this->db->tableExists('guidance_practitioner_scopes')) {
            $this->sql(
                'CREATE TABLE {guidance_practitioner_scopes} (
                    tenant_id INT(10) UNSIGNED NOT NULL,
                    profile_id BIGINT(20) UNSIGNED NOT NULL,
                    scope_code VARCHAR(64) NOT NULL,
                    PRIMARY KEY (tenant_id, profile_id, scope_code),
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_gps_profile')) . '
                        FOREIGN KEY (tenant_id, profile_id) REFERENCES {guidance_practitioner_profiles}(tenant_id, id)
                        ON DELETE CASCADE ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $rows = $this->db->table('guidance_practitioner_profiles')
            ->select('id, tenant_id, service_scope_json')
            ->get()->getResultArray();
        foreach ($rows as $row) {
            $scopes = json_decode((string) ($row['service_scope_json'] ?? '[]'), true);
            if (! is_array($scopes)) {
                continue;
            }
            foreach ($scopes as $scope) {
                if (! is_string($scope) || trim($scope) === '') {
                    continue;
                }
                $this->sql(
                    'INSERT IGNORE INTO {guidance_practitioner_scopes} (tenant_id, profile_id, scope_code) VALUES (?, ?, ?)',
                    [(int) $row['tenant_id'], (int) $row['id'], substr(trim($scope), 0, 64)]
                );
            }
        }
    }

    private function normalizeGuidanceEvaluationRatings(): void
    {
        if (! $this->db->tableExists('guidance_service_evaluations')) {
            return;
        }

        $this->ensureUniqueKey('guidance_service_evaluations', 'uq_gse_tenant_id', ['tenant_id', 'id']);

        if (! $this->db->tableExists('guidance_service_evaluation_ratings')) {
            $this->sql(
                'CREATE TABLE {guidance_service_evaluation_ratings} (
                    tenant_id INT(10) UNSIGNED NOT NULL,
                    evaluation_id BIGINT(20) UNSIGNED NOT NULL,
                    dimension_code VARCHAR(64) NOT NULL,
                    score TINYINT(3) UNSIGNED NOT NULL,
                    PRIMARY KEY (tenant_id, evaluation_id, dimension_code),
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_gser_eval')) . '
                        FOREIGN KEY (tenant_id, evaluation_id) REFERENCES {guidance_service_evaluations}(tenant_id, id)
                        ON DELETE CASCADE ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $rows = $this->db->table('guidance_service_evaluations')
            ->select('id, tenant_id, ratings_json')
            ->get()->getResultArray();
        foreach ($rows as $row) {
            $ratings = json_decode((string) ($row['ratings_json'] ?? '{}'), true);
            if (! is_array($ratings)) {
                continue;
            }
            foreach ($ratings as $dim => $score) {
                if (! is_string($dim) || trim($dim) === '' || ! is_numeric($score)) {
                    continue;
                }
                $this->sql(
                    'INSERT IGNORE INTO {guidance_service_evaluation_ratings}
                     (tenant_id, evaluation_id, dimension_code, score) VALUES (?, ?, ?, ?)',
                    [(int) $row['tenant_id'], (int) $row['id'], substr(trim($dim), 0, 64), max(0, min(255, (int) $score))]
                );
            }
        }
    }

    private function normalizeAudienceTargets(
        string $parentTable,
        string $fkColumn,
        string $yearTable,
        string $categoryTable
    ): void {
        if (! $this->db->tableExists($parentTable)) {
            return;
        }

        $this->ensureUniqueKey($parentTable, 'uq_' . $parentTable . '_tenant_id', ['tenant_id', 'id']);

        if (! $this->db->tableExists($yearTable)) {
            $this->sql(
                'CREATE TABLE {' . $yearTable . '} (
                    tenant_id INT(10) UNSIGNED NOT NULL,
                    ' . $this->db->protectIdentifiers($fkColumn) . ' BIGINT(20) UNSIGNED NOT NULL,
                    year_level TINYINT(3) UNSIGNED NOT NULL,
                    PRIMARY KEY (tenant_id, ' . $this->db->protectIdentifiers($fkColumn) . ', year_level),
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_' . $yearTable . '_parent')) . '
                        FOREIGN KEY (tenant_id, ' . $this->db->protectIdentifiers($fkColumn) . ') REFERENCES {' . $parentTable . '}(tenant_id, id)
                        ON DELETE CASCADE ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        if (! $this->db->tableExists($categoryTable)) {
            $this->sql(
                'CREATE TABLE {' . $categoryTable . '} (
                    tenant_id INT(10) UNSIGNED NOT NULL,
                    ' . $this->db->protectIdentifiers($fkColumn) . " BIGINT(20) UNSIGNED NOT NULL,
                    student_category ENUM('new','continuing','transferee','graduating') NOT NULL,
                    PRIMARY KEY (tenant_id, " . $this->db->protectIdentifiers($fkColumn) . ', student_category),
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_' . $categoryTable . '_parent')) . '
                        FOREIGN KEY (tenant_id, ' . $this->db->protectIdentifiers($fkColumn) . ') REFERENCES {' . $parentTable . '}(tenant_id, id)
                        ON DELETE CASCADE ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $hasYearCol = $this->db->fieldExists('target_year_levels', $parentTable);
        $hasCatCol  = $this->db->fieldExists('target_student_categories', $parentTable);
        if (! $hasYearCol && ! $hasCatCol) {
            return;
        }

        $select = 'id, tenant_id'
            . ($hasYearCol ? ', target_year_levels' : '')
            . ($hasCatCol ? ', target_student_categories' : '');
        $rows = $this->db->table($parentTable)->select($select)->get()->getResultArray();
        foreach ($rows as $row) {
            $tenantId = (int) $row['tenant_id'];
            $parentId = (int) $row['id'];
            if ($hasYearCol && ($row['target_year_levels'] ?? null) !== null) {
                $years = json_decode((string) $row['target_year_levels'], true);
                if (is_array($years)) {
                    foreach ($years as $yr) {
                        $yrInt = (int) $yr;
                        if ($yrInt >= 1 && $yrInt <= 6) {
                            $this->sql(
                                'INSERT IGNORE INTO {' . $yearTable . '} (tenant_id, ' . $this->db->protectIdentifiers($fkColumn) . ', year_level) VALUES (?, ?, ?)',
                                [$tenantId, $parentId, $yrInt]
                            );
                        }
                    }
                }
            }
            if ($hasCatCol && ($row['target_student_categories'] ?? null) !== null) {
                $cats = json_decode((string) $row['target_student_categories'], true);
                if (is_array($cats)) {
                    foreach ($cats as $cat) {
                        if (is_string($cat) && in_array($cat, self::ALLOWED_STUDENT_CATEGORIES, true)) {
                            $this->sql(
                                'INSERT IGNORE INTO {' . $categoryTable . '} (tenant_id, ' . $this->db->protectIdentifiers($fkColumn) . ', student_category) VALUES (?, ?, ?)',
                                [$tenantId, $parentId, $cat]
                            );
                        }
                    }
                }
            }
        }
    }

    /** @param list<string> $cols */
    private function ensureUniqueKey(string $table, string $indexName, array $cols): void
    {
        $row = $this->sql(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND NON_UNIQUE = 0
             GROUP BY INDEX_NAME
             HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = ?',
            [$this->db->prefixTable($table), implode(',', $cols)]
        )->getRowArray();

        if ($row !== null) {
            return;
        }

        $colSql = implode(', ', array_map(fn (string $c): string => $this->db->protectIdentifiers($c), $cols));
        $this->sql('ALTER TABLE {' . $table . '} ADD UNIQUE KEY ' . $this->db->protectIdentifiers($indexName) . ' (' . $colSql . ')');
    }

    private function constraintName(string $base): string
    {
        $prefix = $this->db->getPrefix();
        $name   = $prefix !== '' ? $prefix . $base : $base;

        return strlen($name) <= 64 ? $name : substr($name, 0, 64);
    }

    /** @param list<mixed> $binds */
    private function sql(string $sql, array $binds = [])
    {
        $resolved = preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            fn (array $m): string => $this->db->protectIdentifiers($this->db->prefixTable($m[1])),
            $sql
        );

        return $binds === [] ? $this->db->query($resolved) : $this->db->query($resolved, $binds);
    }
}

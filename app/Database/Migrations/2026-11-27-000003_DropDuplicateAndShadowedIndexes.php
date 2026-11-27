<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Tier 1 Phase 7 index & FK hygiene:
 * - Drops duplicate UNIQUE(tenant_id, id) indexes on people and users (preserving inv_person_tenant and notification_user_tenant).
 * - Drops strictly shadowed secondary indexes across team_task_comments, team_chat_messages, counselling_sessions,
 *   counselling_notes, guidance_episodes, guidance_appointments, and guidance_call_slips.
 * - Drops legacy single-column FKs on clinic_encounters and clinic_dispensations superseded by composite tenant-scoped FKs.
 */
final class DropDuplicateAndShadowedIndexes extends Migration
{
    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }

        // 1. Duplicate UNIQUE(tenant_id, id) on people (keep inv_person_tenant)
        if ($this->indexExists('people', 'inv_person_tenant')) {
            foreach (['ds_person_tenant', 'announcement_people_tenant', 'survey_people_tenant'] as $idx) {
                $this->dropIndexIfExists('people', $idx);
            }
        }

        // 2. Duplicate UNIQUE(tenant_id, id) on users (keep notification_user_tenant)
        if ($this->indexExists('users', 'notification_user_tenant')) {
            foreach (['inv_user_tenant', 'ds_user_tenant', 'announcement_users_tenant', 'survey_users_tenant'] as $idx) {
                $this->dropIndexIfExists('users', $idx);
            }
        }

        // 3. Strictly shadowed secondary indexes
        if ($this->indexExists('team_task_comments', 'notification_team_comment_scope')) {
            $this->dropIndexIfExists('team_task_comments', 'team_task_comments_page');
        }
        if ($this->indexExists('team_chat_messages', 'notification_chat_message_scope')) {
            $this->dropIndexIfExists('team_chat_messages', 'team_chat_page');
        }
        if ($this->indexExists('counselling_sessions', 'uniq_guidance_session_number')) {
            $this->dropIndexIfExists('counselling_sessions', 'idx_session_case');
        }
        if ($this->indexExists('counselling_notes', 'uniq_guidance_note_version')) {
            $this->dropIndexIfExists('counselling_notes', 'idx_notes_session_ver');
        }

        // 4. Redundant single-column indexes on Guidance Kernel tables (covered by idx_* composite tenant indexes)
        if ($this->indexExists('guidance_episodes', 'idx_gepisode_person')) {
            foreach (['tenant_id', 'person_id', 'intake_referral_id', 'case_id', 'primary_counselor_user_id', 'status'] as $idx) {
                $this->dropIndexIfExists('guidance_episodes', $idx);
            }
        }
        if ($this->indexExists('guidance_appointments', 'idx_gappointment_person')) {
            foreach (['tenant_id', 'episode_id', 'person_id', 'counselor_user_id', 'appointment_number', 'status', 'scheduled_date'] as $idx) {
                $this->dropIndexIfExists('guidance_appointments', $idx);
            }
        }
        if ($this->indexExists('guidance_call_slips', 'idx_gcallslip_person')) {
            foreach (['tenant_id', 'episode_id', 'person_id', 'issuing_counselor_user_id', 'call_slip_number', 'attendance_status', 'call_date'] as $idx) {
                $this->dropIndexIfExists('guidance_call_slips', $idx);
            }
        }

        // 5. Redundant legacy single-column FKs superseded by composite FKs in 2026-10-15-000002
        if ($this->fkExists('clinic_encounters', 'inv_enc_actor')) {
            $this->dropSingleColFks('clinic_encounters', 'attending_user_id', 'users', 'id');
        }
        if ($this->fkExists('clinic_dispensations', 'inv_disp_batch')) {
            $this->dropSingleColFks('clinic_dispensations', 'medicine_id', 'clinic_medicines', 'id');
            $this->dropSingleColFks('clinic_dispensations', 'batch_id', 'clinic_medicine_batches', 'id');
        }
        if ($this->fkExists('clinic_dispensations', 'inv_disp_actor')) {
            $this->dropSingleColFks('clinic_dispensations', 'dispensed_by_user_id', 'users', 'id');
        }

        $this->db->resetDataCache();
    }

    public function down(): void
    {
        throw new RuntimeException('Tier 1 index/FK cleanup is forward-only.');
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName)) {
            return;
        }

        $this->sql('ALTER TABLE {' . $table . '} DROP INDEX ' . $this->db->protectIdentifiers($indexName));
    }

    private function dropSingleColFks(string $table, string $column, string $refTable, string $refColumn): void
    {
        $rows = $this->sql(
            'SELECT k.CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE k
             WHERE k.CONSTRAINT_SCHEMA = DATABASE()
               AND k.TABLE_NAME = ?
               AND k.COLUMN_NAME = ?
               AND k.REFERENCED_TABLE_NAME = ?
               AND k.REFERENCED_COLUMN_NAME = ?
               AND k.ORDINAL_POSITION = 1
               AND NOT EXISTS (
                   SELECT 1 FROM information_schema.KEY_COLUMN_USAGE k2
                   WHERE k2.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                     AND k2.TABLE_NAME = k.TABLE_NAME
                     AND k2.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                     AND k2.ORDINAL_POSITION > 1
               )',
            [$this->db->prefixTable($table), $column, $this->db->prefixTable($refTable), $refColumn]
        )->getResultArray();

        foreach ($rows as $row) {
            $name = (string) $row['CONSTRAINT_NAME'];
            $this->sql('ALTER TABLE {' . $table . '} DROP FOREIGN KEY ' . $this->db->protectIdentifiers($name));
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return $this->sql(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$this->db->prefixTable($table), $indexName]
        )->getRowArray() !== null;
    }

    private function fkExists(string $table, string $fkName): bool
    {
        return $this->sql(
            'SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [$this->db->prefixTable($table), $fkName]
        )->getRowArray() !== null;
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
            throw new RuntimeException('DropDuplicateAndShadowedIndexes query failed.');
        }

        return $result;
    }
}

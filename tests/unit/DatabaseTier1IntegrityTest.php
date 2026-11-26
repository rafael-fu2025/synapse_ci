<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\FreshDatabaseTestCase;

/**
 * Run with python -I tests/_support/run-fresh-database-tests.py tests/unit/DatabaseTier1IntegrityTest.php.
 */
final class DatabaseTier1IntegrityTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    public function testInvertedForeignKeysAreRepairedToSafeDeleteRules(): void
    {
        $expected = [
            ['users', 'person_id', 'SET NULL'],
            ['appointments', 'booked_by_user_id', 'SET NULL'],
            ['queue_entries', 'appointment_id', 'SET NULL'],
            ['queue_entries', 'called_by_user_id', 'SET NULL'],
            ['referrals', 'assigned_user_id', 'SET NULL'],
            ['bmg_devices', 'unit_id', 'SET NULL'],
            ['clinic_lab_results', 'encounter_id', 'SET NULL'],
            ['clinic_lab_results', 'recorded_by_user_id', 'RESTRICT'],
            ['clinic_encounter_addenda', 'lab_result_id', 'SET NULL'],
            ['clinic_encounter_addenda', 'author_user_id', 'RESTRICT'],
            ['clinic_enrollment_clearances', 'cleared_by_user_id', 'SET NULL'],
            ['clinic_isolation_cases', 'encounter_id', 'SET NULL'],
            ['clinic_isolation_cases', 'cleared_by_user_id', 'SET NULL'],
            ['clinic_isolation_cases', 'reported_by_user_id', 'RESTRICT'],
        ];

        foreach ($expected as [$table, $column, $deleteRule]) {
            $row = $this->db->query(
                'SELECT r.DELETE_RULE
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                   ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
                  AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                  AND r.TABLE_NAME = k.TABLE_NAME
                 WHERE k.TABLE_SCHEMA = DATABASE()
                   AND k.TABLE_NAME = ?
                   AND k.COLUMN_NAME = ?
                   AND k.REFERENCED_TABLE_NAME IS NOT NULL',
                [$table, $column]
            )->getRowArray();

            self::assertNotNull($row, "Missing FK on {$table}.{$column}");
            self::assertSame($deleteRule, strtoupper((string) $row['DELETE_RULE']), "Unexpected DELETE_RULE on {$table}.{$column}");
        }
    }

    public function testRestoredNotNullAndAlignedColumnWidths(): void
    {
        foreach ([
            ['appointments', 'status'],
            ['appointments', 'version'],
            ['bmg_rotation_schedules', 'rotations'],
            ['bmg_rotation_runs', 'rotations'],
        ] as [$table, $col]) {
            $meta = $this->db->query(
                'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $col]
            )->getRowArray();
            self::assertSame('NO', strtoupper((string) ($meta['IS_NULLABLE'] ?? '')), "{$table}.{$col} must be NOT NULL");
        }

        foreach (['ai_releases', 'ai_snapshots', 'ai_jobs', 'ai_dispatch_attempts', 'ai_insights', 'ai_reviews', 'ai_department_access', 'ai_storage_audit'] as $aiTable) {
            $meta = $this->db->query(
                'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$aiTable, 'tenant_id']
            )->getRowArray();
            $normalized = preg_replace('/\b(bigint|int)\(\d+\)/', '$1', strtolower((string) ($meta['COLUMN_TYPE'] ?? '')));
            self::assertSame('int unsigned', $normalized, "{$aiTable}.tenant_id must match tenants.id INT UNSIGNED");
        }

        foreach ([
            ['guidance_appointments', 'queue_ticket_id'],
            ['guidance_mi_assignments', 'assigned_by_user_id'],
            ['guidance_mi_submissions', 'attested_by_user_id'],
            ['guidance_evaluation_tokens', 'provider_user_id'],
            ['guidance_service_evaluations', 'provider_user_id'],
            ['guidance_service_evaluations', 'moderated_by_user_id'],
        ] as [$table, $col]) {
            $meta = $this->db->query(
                'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $col]
            )->getRowArray();
            $normalized = preg_replace('/\b(bigint|int)\(\d+\)/', '$1', strtolower((string) ($meta['COLUMN_TYPE'] ?? '')));
            self::assertSame('bigint unsigned', $normalized, "{$table}.{$col} must be BIGINT UNSIGNED");
        }
    }

    public function testPreviouslyFkLessTablesHaveForeignKeysAndDuplicateIndexesDropped(): void
    {
        $tables = [
            'guidance_form_definitions', 'guidance_form_definition_revisions', 'guidance_privacy_notices',
            'guidance_retention_policies', 'guidance_consent_receipts', 'guidance_form_instances',
            'guidance_form_drafts', 'guidance_form_revisions', 'guidance_form_attestations',
            'guidance_document_jobs', 'guidance_document_issuances', 'guidance_legal_holds',
            'guidance_practitioner_profiles', 'guidance_supervision_assignments', 'guidance_case_assignments',
            'guidance_case_transfers', 'guidance_intake_referrals', 'guidance_safety_assessments',
            'guidance_safety_escalations', 'guidance_episodes', 'guidance_appointments', 'guidance_call_slips',
            'guidance_mi_assignments', 'guidance_mi_submissions', 'guidance_evaluation_tokens',
            'guidance_service_evaluations', 'guidance_activation_gates',
            'ai_releases', 'ai_snapshots', 'ai_jobs', 'ai_dispatch_attempts', 'ai_insights',
            'ai_reviews', 'ai_department_access', 'ai_storage_audit',
            'archive_retention_policies', 'archive_records', 'archive_legal_holds',
            'student_intern_placements', 'daily_ticket_counters',
        ];

        foreach ($tables as $table) {
            $cnt = $this->db->query(
                'SELECT COUNT(DISTINCT CONSTRAINT_NAME) AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$table]
            )->getRowArray();
            self::assertGreaterThan(0, (int) ($cnt['c'] ?? 0), "{$table} must have at least one FK constraint");
        }

        // Duplicate/shadowed index checks
        self::assertTrue($this->hasIndex('people', 'inv_person_tenant'));
        self::assertFalse($this->hasIndex('people', 'ds_person_tenant'));
        self::assertTrue($this->hasIndex('users', 'notification_user_tenant'));
        self::assertFalse($this->hasIndex('users', 'inv_user_tenant'));
        self::assertFalse($this->hasIndex('team_task_comments', 'team_task_comments_page'));
        self::assertFalse($this->hasIndex('team_chat_messages', 'team_chat_page'));
        self::assertFalse($this->hasIndex('counselling_sessions', 'idx_session_case'));
        self::assertFalse($this->hasIndex('counselling_notes', 'idx_notes_session_ver'));
        self::assertFalse($this->hasIndex('guidance_episodes', 'person_id'));
        self::assertFalse($this->hasIndex('guidance_appointments', 'status'));
        self::assertFalse($this->hasIndex('guidance_call_slips', 'call_date'));
    }

    public function testArchiveActiveUniquenessAndAuditPrevHashUniquenessRejectDuplicates(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('tenants')->insert([
            'slug' => 'tier1_test_' . bin2hex(random_bytes(6)),
            'name' => 'Disposable Tier 1 Test Tenant',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $tenantId = (int) $this->db->insertID();

        // Active archive uniqueness rejects duplicate active archive, allows second row once restored
        $this->db->table('archive_records')->insert([
            'tenant_id' => $tenantId, 'module' => 'clinic', 'entity_type' => 'clinic_medicine',
            'entity_id' => 9991, 'record_ref' => 'TEST-9991', 'state' => 'archived',
            'reason' => 'Test archive 1', 'archived_by_user_id' => null,
            'archived_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $dupFailed = false;
        try {
            $this->db->table('archive_records')->insert([
                'tenant_id' => $tenantId, 'module' => 'clinic', 'entity_type' => 'clinic_medicine',
                'entity_id' => 9991, 'record_ref' => 'TEST-9991', 'state' => 'archived',
                'reason' => 'Duplicate active archive', 'archived_by_user_id' => null,
                'archived_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (DatabaseException) {
            $dupFailed = true;
        }
        self::assertTrue($dupFailed, 'uq_archive_active_entity must reject duplicate active archived row.');

        $this->db->table('archive_records')
            ->where('tenant_id', $tenantId)
            ->where('entity_type', 'clinic_medicine')
            ->where('entity_id', 9991)
            ->update(['state' => 'restored', 'restored_at' => $now, 'updated_at' => $now]);

        self::assertTrue($this->db->table('archive_records')->insert([
            'tenant_id' => $tenantId, 'module' => 'clinic', 'entity_type' => 'clinic_medicine',
            'entity_id' => 9991, 'record_ref' => 'TEST-9991', 'state' => 'archived',
            'reason' => 'Re-archived after restore', 'archived_by_user_id' => null,
            'archived_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]));

        // Audit chain fork uniqueness rejects duplicate (tenant_id, prev_hash)
        $prev = str_repeat('a', 64);
        $this->db->table('audit_events')->insert([
            'tenant_id' => $tenantId, 'event_type' => 'test.fork1', 'entity_type' => 'test', 'entity_id' => '1',
            'actor_ip' => '127.0.0.1', 'action' => 'create', 'prev_hash' => $prev,
            'curr_hash' => str_repeat('b', 64), 'hash_version' => 2, 'occurred_at' => $now,
        ]);

        $forkFailed = false;
        try {
            $this->db->table('audit_events')->insert([
                'tenant_id' => $tenantId, 'event_type' => 'test.fork2', 'entity_type' => 'test', 'entity_id' => '2',
                'actor_ip' => '127.0.0.1', 'action' => 'create', 'prev_hash' => $prev,
                'curr_hash' => str_repeat('c', 64), 'hash_version' => 2, 'occurred_at' => $now,
            ]);
        } catch (DatabaseException) {
            $forkFailed = true;
        }
        self::assertTrue($forkFailed, 'uq_audit_tenant_prev_hash must reject duplicate (tenant_id, prev_hash).');
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $indexName]
        )->getRowArray() !== null;
    }
}

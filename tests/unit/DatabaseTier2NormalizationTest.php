<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\FreshDatabaseTestCase;

/**
 * Run with python -I tests/_support/run-fresh-database-tests.py tests/unit/DatabaseTier2NormalizationTest.php.
 */
final class DatabaseTier2NormalizationTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    public function testPhase3SupersededGovernanceTablesRetiredAndPolicyFkRemapped(): void
    {
        self::assertFalse($this->db->tableExists('guidance_legal_holds'));
        self::assertFalse($this->db->tableExists('guidance_retention_policies'));
        self::assertFalse($this->db->tableExists('bmg_device_runs'));

        $fk = $this->db->query(
            "SELECT REFERENCED_TABLE_NAME,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS cols,
                    GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION) AS ref_cols
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'guidance_form_instances'
               AND REFERENCED_TABLE_NAME = 'archive_retention_policies'
             GROUP BY CONSTRAINT_NAME"
        )->getRowArray();

        self::assertNotNull($fk, 'guidance_form_instances.retention_policy_id must reference archive_retention_policies');
        self::assertSame('tenant_id,retention_policy_id', $fk['cols']);
        self::assertSame('tenant_id,id', $fk['ref_cols']);
    }

    public function testPhase5Composite3nfForeignKeysAndGeneratedColumnsAndTokenFamilies(): void
    {
        $expectedFks = [
            ['guidance_episodes', 'tenant_id,case_id,person_id', 'counselling_cases', 'tenant_id,id,person_id'],
            ['guidance_episodes', 'tenant_id,intake_referral_id,person_id', 'guidance_intake_referrals', 'tenant_id,id,subject_person_id'],
            ['guidance_appointments', 'tenant_id,episode_id,person_id', 'guidance_episodes', 'tenant_id,id,person_id'],
            ['guidance_call_slips', 'tenant_id,episode_id,person_id', 'guidance_episodes', 'tenant_id,id,person_id'],
            ['guidance_mi_submissions', 'tenant_id,assignment_id,person_id', 'guidance_mi_assignments', 'tenant_id,id,person_id'],
            ['guidance_safety_assessments', 'tenant_id,case_id,person_id', 'counselling_cases', 'tenant_id,id,person_id'],
            ['guidance_safety_assessments', 'tenant_id,intake_referral_id,person_id', 'guidance_intake_referrals', 'tenant_id,id,subject_person_id'],
            ['guidance_form_instances', 'tenant_id,case_id,person_id', 'counselling_cases', 'tenant_id,id,person_id'],
            ['guidance_form_instances', 'tenant_id,episode_id,person_id', 'guidance_episodes', 'tenant_id,id,person_id'],
            ['guidance_form_instances', 'tenant_id,session_id,case_id', 'counselling_sessions', 'tenant_id,id,case_id'],
            ['guidance_form_instances', 'tenant_id,appointment_id,person_id', 'guidance_appointments', 'tenant_id,id,person_id'],
            ['guidance_form_instances', 'tenant_id,intake_id,person_id', 'guidance_intake_referrals', 'tenant_id,id,subject_person_id'],
            ['clinic_dispensations', 'tenant_id,batch_id,medicine_id', 'clinic_medicine_batches', 'tenant_id,id,medicine_id'],
            ['clinic_encounter_addenda', 'tenant_id,lab_result_id,encounter_id', 'clinic_lab_results', 'tenant_id,id,encounter_id'],
            ['clinic_lab_results', 'tenant_id,encounter_id,person_id', 'clinic_encounters', 'tenant_id,id,person_id'],
            ['clinic_isolation_cases', 'tenant_id,encounter_id,person_id', 'clinic_encounters', 'tenant_id,id,person_id'],
            ['queue_entries', 'tenant_id,appointment_id,person_id', 'appointments', 'tenant_id,id,person_id'],
            ['bmg_rotation_commands', 'tenant_id,device_id,unit_id', 'bmg_devices', 'tenant_id,id,unit_id'],
            ['bmg_rotation_runs', 'tenant_id,device_id,unit_id', 'bmg_devices', 'tenant_id,id,unit_id'],
            ['api_tokens', 'tenant_id,family_id', 'api_token_families', 'tenant_id,family_id'],
            ['push_tokens', 'tenant_id,api_family_id', 'api_token_families', 'tenant_id,family_id'],
        ];

        foreach ($expectedFks as [$child, $cols, $parent, $refCols]) {
            $rows = $this->db->query(
                'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS cols,
                        GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION) AS ref_cols
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND REFERENCED_TABLE_NAME = ?
                 GROUP BY CONSTRAINT_NAME',
                [$child, $parent]
            )->getResultArray();

            $matched = false;
            foreach ($rows as $r) {
                if ((string) $r['cols'] === $cols && (string) $r['ref_cols'] === $refCols) {
                    $matched = true;
                    break;
                }
            }
            self::assertTrue($matched, "Missing composite 3NF FK {$child}({$cols}) -> {$parent}({$refCols})");
        }

        foreach ([
            ['clinic_vitals', 'bmi'],
            ['clinic_isolation_cases', 'isolation_end_date'],
        ] as [$table, $column]) {
            $meta = $this->db->query(
                'SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            )->getRowArray();
            self::assertNotNull($meta);
            self::assertStringContainsString('STORED GENERATED', strtoupper((string) $meta['EXTRA']), "{$table}.{$column} must be STORED GENERATED");
        }

        // Functional behavioral check on generated columns, composite FK drift rejection & api_token_families
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('tenants')->insert([
            'slug' => 'tier2_test_' . bin2hex(random_bytes(6)),
            'name' => 'Disposable Tier 2 Test Tenant',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $tenantId = (int) $this->db->insertID();

        $this->db->table('people')->insert([
            'tenant_id' => $tenantId, 'kind' => 'student', 'mis_id' => 'T2-S1-' . bin2hex(random_bytes(3)),
            'first_name' => 'Test', 'last_name' => 'One', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $personA = (int) $this->db->insertID();

        $this->db->table('people')->insert([
            'tenant_id' => $tenantId, 'kind' => 'student', 'mis_id' => 'T2-S2-' . bin2hex(random_bytes(3)),
            'first_name' => 'Test', 'last_name' => 'Two', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $personB = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'tenant_id' => $tenantId, 'person_id' => $personA,
            'username' => 't2_user_' . bin2hex(random_bytes(4)), 'role' => 'clinic_nurse',
            'status' => 'active', 'auth_version' => 1, 'password_hash' => 'x', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $userId = (int) $this->db->insertID();

        // Computed BMI
        $this->db->table('clinic_encounters')->insert([
            'tenant_id' => $tenantId, 'person_id' => $personA, 'attending_user_id' => $userId,
            'encoded_by_user_id' => $userId, 'status' => 'in_progress', 'chief_complaint' => 'Checkup',
            'started_at' => $now, 'visited_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $encounterId = (int) $this->db->insertID();

        $this->db->table('clinic_vitals')->insert([
            'tenant_id' => $tenantId, 'encounter_id' => $encounterId,
            'weight_kg' => '70.00', 'height_cm' => '175.00',
            'recorded_by_user_id' => $userId, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $vitals = $this->db->table('clinic_vitals')->where('encounter_id', $encounterId)->get()->getRowArray();
        self::assertSame('22.86', (string) $vitals['bmi']);

        // Inclusive stored isolation_end_date
        $this->db->table('clinic_isolation_cases')->insert([
            'tenant_id' => $tenantId, 'person_id' => $personA, 'encounter_id' => $encounterId,
            'condition_code' => 'influenza', 'isolation_start_date' => '2026-11-27',
            'isolation_days' => 7, 'clearance_status' => 'active_isolation',
            'reported_by_user_id' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $iso = $this->db->table('clinic_isolation_cases')->where('encounter_id', $encounterId)->get()->getRowArray();
        self::assertSame('2026-12-03', (string) $iso['isolation_end_date']);

        // Reject cross-person lab_result tied to personA's encounter under personB
        $labMismatchRejected = false;
        try {
            $this->db->table('clinic_lab_results')->insert([
                'tenant_id' => $tenantId, 'person_id' => $personB, 'encounter_id' => $encounterId,
                'school_year' => '2026-2027', 'context_type' => 'encounter', 'test_name' => 'CBC',
                'result_status' => 'pending', 'requested_at' => $now, 'recorded_by_user_id' => $userId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (DatabaseException) {
            $labMismatchRejected = true;
        }
        self::assertTrue($labMismatchRejected, 'Composite 3NF FK fk_clr_enc_person must reject cross-person encounter link.');

        // Reject orphan api_tokens family_id
        $tokenFamilyRejected = false;
        try {
            $this->db->table('api_tokens')->insert([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'auth_version' => 1,
                'token_hash' => hash('sha256', 'orphan_t_' . bin2hex(random_bytes(8))),
                'refresh_hash' => hash('sha256', 'orphan_r_' . bin2hex(random_bytes(8))),
                'family_id' => bin2hex(random_bytes(16)),
                'access_expires_at' => $now, 'refresh_expires_at' => $now, 'created_at' => $now,
            ]);
        } catch (DatabaseException) {
            $tokenFamilyRejected = true;
        }
        self::assertTrue($tokenFamilyRejected, 'fk_api_tokens_family must require an existing api_token_families row.');
    }

    public function testPhase4ChildTablesExistAndGroupAColumnsDropped(): void
    {
        $expectedTables = [
            'bmg_rotation_schedule_slots',
            'clinic_enrollment_clearance_labs',
            'team_chat_message_tasks',
            'team_chat_message_mentions',
            'guidance_activation_gate_signoffs',
            'guidance_practitioner_scopes',
            'guidance_service_evaluation_ratings',
            'announcement_target_year_levels',
            'announcement_target_categories',
            'survey_target_year_levels',
            'survey_target_categories',
        ];
        foreach ($expectedTables as $table) {
            self::assertTrue($this->db->tableExists($table), "Missing normalized child table {$table}");
        }

        // Group A dropped JSON columns
        self::assertFalse($this->db->fieldExists('times_json', 'bmg_rotation_schedules'));
        self::assertFalse($this->db->fieldExists('required_labs_json', 'clinic_enrollment_clearances'));
        self::assertFalse($this->db->fieldExists('task_ids', 'team_chat_messages'));
        self::assertFalse($this->db->fieldExists('mentions', 'team_chat_messages'));

        // Group B backward-compatible JSON columns retained
        self::assertTrue($this->db->fieldExists('recorded_signoffs_json', 'guidance_activation_gates'));
        self::assertTrue($this->db->fieldExists('service_scope_json', 'guidance_practitioner_profiles'));
        self::assertTrue($this->db->fieldExists('ratings_json', 'guidance_service_evaluations'));
        self::assertTrue($this->db->fieldExists('target_year_levels', 'announcements'));
        self::assertTrue($this->db->fieldExists('target_year_levels', 'surveys'));

        // Round-trip EngagementAudience::syncTargets on an announcement
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('tenants')->insert([
            'slug' => 'tier2_p4_' . bin2hex(random_bytes(6)),
            'name' => 'Disposable Tier 2 Phase 4 Tenant',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $tenantId = (int) $this->db->insertID();
        $this->db->table('users')->insert([
            'tenant_id' => $tenantId, 'username' => 'p4_user_' . bin2hex(random_bytes(4)),
            'role' => 'clinic_admin', 'status' => 'active', 'auth_version' => 1,
            'password_hash' => 'x', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $userId = (int) $this->db->insertID();

        $this->db->table('announcements')->insert([
            'tenant_id' => $tenantId, 'unit' => 'clinic', 'title' => 'T2 Target', 'body' => 'Body',
            'audience' => 'students', 'status' => 'draft', 'created_by' => $userId,
            'target_year_levels' => '[1,3]', 'target_student_categories' => '["new","transferee"]',
            'created_at' => $now, 'updated_at' => $now, 'version' => 1,
        ]);
        $annId = (int) $this->db->insertID();

        \App\Services\EngagementAudience::syncTargets($this->db, 'announcements', $tenantId, $annId, [
            'target_year_levels' => '[1,3]',
            'target_student_categories' => '["new","transferee"]',
        ]);

        $yrs = $this->db->table('announcement_target_year_levels')
            ->where('tenant_id', $tenantId)->where('announcement_id', $annId)
            ->orderBy('year_level', 'ASC')->get()->getResultArray();
        self::assertSame([1, 3], array_map(static fn (array $r): int => (int) $r['year_level'], $yrs));

        $cats = $this->db->table('announcement_target_categories')
            ->where('tenant_id', $tenantId)->where('announcement_id', $annId)
            ->orderBy('student_category', 'ASC')->get()->getResultArray();
        self::assertSame(['new', 'transferee'], array_map(static fn (array $r): string => (string) $r['student_category'], $cats));
    }
}

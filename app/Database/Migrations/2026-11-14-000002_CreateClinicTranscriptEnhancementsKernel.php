<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Schema kernel for University Clinic transcript enhancements:
 * - Actual visit timestamps & structured complaints on clinic_encounters
 * - Narrative medical history fields on health_profiles
 * - Post-visit lab results & signed addenda for closed encounters
 * - School-year enrollment/annual health clearances
 * - Communicable disease / isolation calendar cases
 * - Pinned urgent advisory flag on announcements
 */
final class CreateClinicTranscriptEnhancementsKernel extends Migration
{
    public function up(): void
    {
        $this->extendEncounters();
        $this->extendHealthProfiles();
        $this->extendAnnouncements();
        $this->createLabResultsTable();
        $this->createEncounterAddendaTable();
        $this->createEnrollmentClearancesTable();
        $this->createIsolationCasesTable();
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_isolation_cases', true);
        $this->forge->dropTable('clinic_enrollment_clearances', true);
        $this->forge->dropTable('clinic_encounter_addenda', true);
        $this->forge->dropTable('clinic_lab_results', true);

        $this->dropColumns('announcements', ['is_pinned_advisory']);
        $this->dropColumns('health_profiles', [
            'family_history', 'immunization_notes', 'surgical_history', 'medical_history_notes',
        ]);
        $this->dropColumns('clinic_encounters', [
            'encoded_by_user_id', 'complaint_qualifier', 'complaint_category', 'visited_at',
        ]);
        $this->db->resetDataCache();
    }

    private function extendEncounters(): void
    {
        if (!$this->db->tableExists('clinic_encounters')) {
            return;
        }
        $table = $this->db->prefixTable('clinic_encounters');
        $columns = [
            'visited_at'          => 'DATETIME NULL AFTER started_at',
            'complaint_category'  => 'VARCHAR(64) NULL AFTER chief_complaint',
            'complaint_qualifier' => 'VARCHAR(120) NULL AFTER complaint_category',
            'encoded_by_user_id'  => 'BIGINT UNSIGNED NULL AFTER attending_user_id',
        ];
        foreach ($columns as $column => $definition) {
            if (!$this->db->fieldExists($column, 'clinic_encounters')) {
                $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
        $this->db->query("UPDATE `{$table}` SET `visited_at` = `started_at` WHERE `visited_at` IS NULL");
    }

    private function extendHealthProfiles(): void
    {
        if (!$this->db->tableExists('health_profiles')) {
            return;
        }
        $table = $this->db->prefixTable('health_profiles');
        $columns = [
            'medical_history_notes' => 'TEXT NULL AFTER chronic_conditions',
            'surgical_history'      => 'TEXT NULL AFTER medical_history_notes',
            'immunization_notes'    => 'TEXT NULL AFTER surgical_history',
            'family_history'        => 'TEXT NULL AFTER immunization_notes',
        ];
        foreach ($columns as $column => $definition) {
            if (!$this->db->fieldExists($column, 'health_profiles')) {
                $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }

    private function extendAnnouncements(): void
    {
        if (!$this->db->tableExists('announcements')) {
            return;
        }
        $table = $this->db->prefixTable('announcements');
        if (!$this->db->fieldExists('is_pinned_advisory', 'announcements')) {
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `is_pinned_advisory` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");
        }
    }

    private function createLabResultsTable(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
            'person_id'           => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'encounter_id'        => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'school_year'         => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
            'context_type'        => ['type' => 'ENUM', 'constraint' => ['encounter_followup', 'enrollment_annual', 'other'], 'null' => false, 'default' => 'encounter_followup'],
            'test_name'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'result_status'       => ['type' => 'ENUM', 'constraint' => ['pending', 'normal', 'abnormal', 'inconclusive'], 'null' => false, 'default' => 'pending'],
            'result_summary'      => ['type' => 'TEXT', 'null' => true],
            'clinical_notes'      => ['type' => 'TEXT', 'null' => true],
            'requested_at'        => ['type' => 'DATETIME', 'null' => true],
            'submitted_at'        => ['type' => 'DATETIME', 'null' => true],
            'recorded_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at'          => ['type' => 'DATETIME', 'null' => false],
            'updated_at'          => ['type' => 'DATETIME', 'null' => false],
            'deleted_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'person_id', 'school_year'], false, false, 'idx_clinic_lab_person_sy');
        $this->forge->addKey(['tenant_id', 'encounter_id'], false, false, 'idx_clinic_lab_encounter');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('person_id', 'people', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('encounter_id', 'clinic_encounters', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('recorded_by_user_id', 'users', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('clinic_lab_results', true, ['ENGINE' => 'InnoDB']);
    }

    private function createEncounterAddendaTable(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
            'encounter_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'addendum_type'  => ['type' => 'ENUM', 'constraint' => ['lab_followup', 'clinical_note', 'time_correction'], 'null' => false, 'default' => 'clinical_note'],
            'note'           => ['type' => 'TEXT', 'null' => false],
            'lab_result_id'  => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'author_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at'     => ['type' => 'DATETIME', 'null' => false],
            'updated_at'     => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'encounter_id'], false, false, 'idx_clinic_addendum_enc');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('encounter_id', 'clinic_encounters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('lab_result_id', 'clinic_lab_results', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('author_user_id', 'users', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('clinic_encounter_addenda', true, ['ENGINE' => 'InnoDB']);
    }

    private function createEnrollmentClearancesTable(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
            'person_id'          => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'school_year'        => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
            'semester'           => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => false, 'default' => '1st Semester'],
            'applicant_category' => ['type' => 'ENUM', 'constraint' => ['freshman', 'transferee', 'returning', 'employee'], 'null' => false, 'default' => 'freshman'],
            'required_labs_json' => ['type' => 'TEXT', 'null' => true],
            'clearance_status'   => ['type' => 'ENUM', 'constraint' => ['pending', 'cleared', 'hold_followup'], 'null' => false, 'default' => 'pending'],
            'remarks'            => ['type' => 'TEXT', 'null' => true],
            'cleared_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'cleared_at'         => ['type' => 'DATETIME', 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => false],
            'updated_at'         => ['type' => 'DATETIME', 'null' => false],
            'deleted_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'person_id', 'school_year', 'semester'], 'uniq_clinic_clearance_term');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('person_id', 'people', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('cleared_by_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('clinic_enrollment_clearances', true, ['ENGINE' => 'InnoDB']);
    }

    private function createIsolationCasesTable(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'            => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
            'person_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'encounter_id'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'condition_code'       => ['type' => 'ENUM', 'constraint' => ['chickenpox', 'measles', 'dengue', 'covid19', 'influenza', 'ptb', 'mumps', 'other'], 'null' => false],
            'condition_other'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'isolation_start_date' => ['type' => 'DATE', 'null' => false],
            'isolation_days'       => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => false, 'default' => 7],
            'isolation_end_date'   => ['type' => 'DATE', 'null' => false],
            'clearance_status'     => ['type' => 'ENUM', 'constraint' => ['active_isolation', 'cleared', 'hospitalized'], 'null' => false, 'default' => 'active_isolation'],
            'cleared_at'           => ['type' => 'DATETIME', 'null' => true],
            'cleared_by_user_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'notes'                => ['type' => 'TEXT', 'null' => true],
            'reported_by_user_id'  => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at'           => ['type' => 'DATETIME', 'null' => false],
            'updated_at'           => ['type' => 'DATETIME', 'null' => false],
            'deleted_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'isolation_start_date', 'isolation_end_date'], false, false, 'idx_clinic_iso_dates');
        $this->forge->addKey(['tenant_id', 'condition_code', 'clearance_status'], false, false, 'idx_clinic_iso_cond');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('person_id', 'people', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('encounter_id', 'clinic_encounters', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('cleared_by_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('reported_by_user_id', 'users', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('clinic_isolation_cases', true, ['ENGINE' => 'InnoDB']);
    }

    /** @param array<int, string> $columns */
    private function dropColumns(string $tableName, array $columns): void
    {
        if (!$this->db->tableExists($tableName)) {
            return;
        }
        $table = $this->db->prefixTable($tableName);
        foreach ($columns as $column) {
            if ($this->db->fieldExists($column, $tableName)) {
                $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            }
        }
    }
}


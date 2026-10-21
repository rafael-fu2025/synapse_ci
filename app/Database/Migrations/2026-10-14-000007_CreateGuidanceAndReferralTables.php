<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGuidanceAndReferralTables extends Migration
{
    public function up(): void
    {
        // 1. Counselling Cases (Holistic ongoing guidance container per student)
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'counsellor_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'case_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'comment'    => 'e.g. GC-2026-001',
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
                'comment'    => 'e.g. Academic Anxiety AY2026-2027',
            ],
            'category' => [
                'type'       => 'ENUM',
                'constraint' => ['academic', 'personal', 'career', 'crisis', 'behavioral', 'family'],
                'null'       => false,
                'default'    => 'personal',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['open', 'active', 'monitoring', 'closed'],
                'null'       => false,
                'default'    => 'open',
            ],
            'opened_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'closed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'summary_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'case_number'], 'uniq_tenant_case_num');
        $this->forge->addKey(['tenant_id', 'person_id', 'status'], false, false, 'idx_case_patient_status');
        $this->forge->addKey(['tenant_id', 'counsellor_user_id', 'status'], false, false, 'idx_case_counsellor');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('person_id', 'people', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('counsellor_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('counselling_cases', true, ['ENGINE' => 'InnoDB']);

        // 2. Counselling Sessions (Individual consultations held under a case)
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'case_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'appointment_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'queue_entry_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'session_number' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'session_type' => [
                'type'       => 'ENUM',
                'constraint' => ['intake', 'regular', 'crisis', 'follow_up', 'termination'],
                'null'       => false,
                'default'    => 'regular',
            ],
            'counsellor_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['scheduled', 'in_progress', 'completed', 'cancelled'],
                'null'       => false,
                'default'    => 'in_progress',
            ],
            'started_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'ended_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'case_id', 'session_number'], false, false, 'idx_session_case');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('case_id', 'counselling_cases', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('counsellor_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('counselling_sessions', true, ['ENGINE' => 'InnoDB']);

        // 3. Counselling Notes (Encrypted with AES-256-GCM, tag-verified, versioned)
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'session_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'version' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'supersedes_note_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'is_draft' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
            ],
            'notes_cipher' => [
                'type'       => 'VARBINARY',
                'constraint' => 8192,
                'null'       => false,
            ],
            'notes_nonce' => [
                'type'       => 'BINARY',
                'constraint' => 12,
                'null'       => false,
            ],
            'notes_tag' => [
                'type'       => 'BINARY',
                'constraint' => 16,
                'null'       => false,
            ],
            'notes_key_version' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'created_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'session_id', 'version'], false, false, 'idx_notes_session_ver');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('session_id', 'counselling_sessions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('counselling_notes', true, ['ENGINE' => 'InnoDB']);

        // 4. Guidance Follow-ups (Scheduled check-ins and commitments)
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'case_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'session_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'due_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'action_plan' => [
                'type' => 'TEXT',
                'null' => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'completed', 'missed'],
                'null'       => false,
                'default'    => 'pending',
            ],
            'completed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'case_id', 'due_date'], false, false, 'idx_followup_case_due');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('case_id', 'counselling_cases', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('guidance_followups', true, ['ENGINE' => 'InnoDB']);

        // 5. Referrals (The bidirectional Bridge between Clinic and Guidance)
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'referral_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'comment'    => 'e.g. REF-2026-001',
            ],
            'patient_person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'source_module' => [
                'type'       => 'ENUM',
                'constraint' => ['clinic', 'guidance'],
                'null'       => false,
            ],
            'target_module' => [
                'type'       => 'ENUM',
                'constraint' => ['clinic', 'guidance'],
                'null'       => false,
            ],
            'source_encounter_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'issuer_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'assigned_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'priority' => [
                'type'       => 'ENUM',
                'constraint' => ['routine', 'urgent', 'crisis'],
                'null'       => false,
                'default'    => 'routine',
            ],
            'reason_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'reason_description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['submitted', 'acknowledged', 'under_review', 'converted_to_case', 'closed'],
                'null'       => false,
                'default'    => 'submitted',
            ],
            'acknowledged_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'closed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'referral_code'], 'uniq_tenant_ref_code');
        $this->forge->addKey(['tenant_id', 'target_module', 'status', 'priority'], false, false, 'idx_ref_inbox');
        $this->forge->addKey(['tenant_id', 'patient_person_id', 'status'], false, false, 'idx_ref_patient');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('patient_person_id', 'people', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('issuer_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('assigned_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('referrals', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('referrals', true);
        $this->forge->dropTable('guidance_followups', true);
        $this->forge->dropTable('counselling_notes', true);
        $this->forge->dropTable('counselling_sessions', true);
        $this->forge->dropTable('counselling_cases', true);
    }
}

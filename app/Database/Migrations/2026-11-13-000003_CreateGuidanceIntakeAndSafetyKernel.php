<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGuidanceIntakeAndSafetyKernel extends Migration
{
    public function up(): void
    {
        // 1. Guidance Intake Referrals
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
            'referral_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'source_type' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'employee_teacher',
                    'self',
                    'clinic_link',
                    'staff_on_behalf',
                    'faculty',
                    'staff',
                    'peer',
                    'guardian',
                    'clinic_bridge',
                    'external',
                ],
                'null'       => false,
            ],
            'clinic_referral_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'subject_person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'referrer_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'referrer_person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'referrer_name_snapshot' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'referrer_designation_snapshot' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => false,
            ],
            'referrer_department_snapshot' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => false,
            ],
            'referrer_contact_snapshot' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'priority_hint' => [
                'type'       => 'ENUM',
                'constraint' => ['routine', 'urgent', 'crisis'],
                'null'       => false,
                'default'    => 'routine',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'draft',
                    'submitted',
                    'received',
                    'triaged',
                    'accepted',
                    'declined',
                    'transferred',
                    'case_linked',
                    'closed',
                    'withdrawn',
                    'external_handoff',
                ],
                'null'       => false,
                'default'    => 'draft',
            ],
            'concern_catalog_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'default'    => '1.0.0',
            ],
            'selections_cipher' => [
                'type' => 'LONGBLOB',
                'null' => false,
            ],
            'selections_nonce' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => false,
            ],
            'selections_tag' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => false,
            ],
            'selections_key_version' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'narrative_cipher' => [
                'type' => 'LONGBLOB',
                'null' => false,
            ],
            'narrative_nonce' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => false,
            ],
            'narrative_tag' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => false,
            ],
            'narrative_key_version' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'prior_actions_cipher' => [
                'type' => 'LONGBLOB',
                'null' => true,
            ],
            'prior_actions_nonce' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => true,
            ],
            'prior_actions_tag' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => true,
            ],
            'prior_actions_key_version' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'assigned_counsellor_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'linked_case_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'triage_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'decline_reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'external_facility' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'submitted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'received_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'triaged_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'responded_at' => [
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
        $this->forge->addUniqueKey(['tenant_id', 'referral_number'], 'uq_gintake_ref_num');
        $this->forge->addKey(['tenant_id', 'subject_person_id'], false, false, 'idx_gintake_subject');
        $this->forge->addKey(['tenant_id', 'status'], false, false, 'idx_gintake_status');
        $this->forge->addKey(['tenant_id', 'referrer_user_id'], false, false, 'idx_gintake_referrer');
        $this->forge->createTable('guidance_intake_referrals', true, ['ENGINE' => 'InnoDB']);

        // 2. Guidance Safety Assessments
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
            'intake_referral_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'case_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'assessor_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'risk_level' => [
                'type'       => 'ENUM',
                'constraint' => ['low', 'moderate', 'high', 'imminent_danger'],
                'null'       => false,
            ],
            'self_harm_risk' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
            ],
            'harm_to_others_risk' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
            ],
            'indicators_cipher' => [
                'type' => 'LONGBLOB',
                'null' => false,
            ],
            'indicators_nonce' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => false,
            ],
            'indicators_tag' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => false,
            ],
            'indicators_key_version' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'immediate_actions_taken' => [
                'type' => 'TEXT',
                'null' => false,
            ],
            'safety_plan_cipher' => [
                'type' => 'LONGBLOB',
                'null' => true,
            ],
            'safety_plan_nonce' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => true,
            ],
            'safety_plan_tag' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => true,
            ],
            'safety_plan_key_version' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'assessed_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'intake_referral_id'], false, false, 'idx_gsafety_intake');
        $this->forge->addKey(['tenant_id', 'person_id'], false, false, 'idx_gsafety_person');
        $this->forge->createTable('guidance_safety_assessments', true, ['ENGINE' => 'InnoDB']);

        // 3. Guidance Safety Escalations
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
            'safety_assessment_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'escalated_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'escalation_channel' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'internal_crisis_team',
                    'dean_student_affairs',
                    'university_physician',
                    'external_emergency',
                    'guardian_contact',
                ],
                'null'       => false,
            ],
            'recipient_agency_or_person' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'summary_notes' => [
                'type' => 'TEXT',
                'null' => false,
            ],
            'acknowledged_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'acknowledged_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'occurred_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'safety_assessment_id'], false, false, 'idx_gescalation_assessment');
        $this->forge->createTable('guidance_safety_escalations', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('guidance_safety_escalations', true);
        $this->forge->dropTable('guidance_safety_assessments', true);
        $this->forge->dropTable('guidance_intake_referrals', true);
    }
}


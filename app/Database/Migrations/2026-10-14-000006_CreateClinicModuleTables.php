<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateClinicModuleTables extends Migration
{
    public function up(): void
    {
        // 1. Clinic Encounters (Visit container: Triage -> Care -> Outcome)
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
            'attending_user_id' => [
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
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['in_progress', 'completed', 'referred', 'cancelled'],
                'null'       => false,
                'default'    => 'in_progress',
            ],
            'chief_complaint' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'assessment_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'disposition' => [
                'type'       => 'ENUM',
                'constraint' => ['discharged', 'admitted', 'referred_to_guidance', 'sent_home', 'referred_to_hospital'],
                'null'       => true,
            ],
            'disposition_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'follow_up_date' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'started_at' => [
                'type' => 'DATETIME',
                'null' => false,
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
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'person_id', 'status'], false, false, 'idx_enc_patient_status');
        $this->forge->addKey(['tenant_id', 'attending_user_id', 'status'], false, false, 'idx_enc_attending');
        $this->forge->addKey(['tenant_id', 'started_at'], false, false, 'idx_enc_started');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('person_id', 'people', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('attending_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('clinic_encounters', true, ['ENGINE' => 'InnoDB']);

        // 2. Clinic Vitals (Recorded per encounter)
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
            'encounter_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'bp_systolic' => [
                'type'     => 'SMALLINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'bp_diastolic' => [
                'type'     => 'SMALLINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'pulse_bpm' => [
                'type'     => 'SMALLINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'temp_c' => [
                'type'       => 'DECIMAL',
                'constraint' => '4,2',
                'null'       => true,
            ],
            'spo2_pct' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'weight_kg' => [
                'type'       => 'DECIMAL',
                'constraint' => '6,2',
                'null'       => true,
            ],
            'height_cm' => [
                'type'       => 'DECIMAL',
                'constraint' => '6,2',
                'null'       => true,
            ],
            'bmi' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
            ],
            'recorded_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'recorded_at' => [
                'type' => 'DATETIME',
                'null' => false,
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
        $this->forge->addUniqueKey(['tenant_id', 'encounter_id'], 'uniq_encounter_vitals');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('encounter_id', 'clinic_encounters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('recorded_by_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('clinic_vitals', true, ['ENGINE' => 'InnoDB']);

        // 3. Clinic Treatments (Diagnosis, clinical plan, care notes)
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
            'encounter_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'diagnosis' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'treatment_plan' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'services_rendered' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'e.g. Wound dressing, nebulization, ice pack',
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
        $this->forge->addUniqueKey(['tenant_id', 'encounter_id'], 'uniq_encounter_treatment');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('encounter_id', 'clinic_encounters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('clinic_treatments', true, ['ENGINE' => 'InnoDB']);

        // 4. Clinic Medicines (Medicines & medical supplies catalogue)
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => false,
                'comment'    => 'e.g. Biogesic',
            ],
            'generic_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,
                'comment'    => 'e.g. Paracetamol',
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
                'default'    => 'Analgesic',
            ],
            'dosage_form' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => false,
                'default'    => 'Tablet',
                'comment'    => 'Tablet, Capsule, Syrup, Ointment',
            ],
            'strength' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'comment'    => '500mg, 250mg/5mL',
            ],
            'unit_of_measure' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
                'default'    => 'pcs',
            ],
            'minimum_stock_level' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 50,
            ],
            'total_stock' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 0,
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
        $this->forge->addKey(['tenant_id', 'name'], false, false, 'idx_med_name');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('clinic_medicines', true, ['ENGINE' => 'InnoDB']);

        // 5. Medicine Batches (Supports FEFO: First-Expired, First-Out allocation)
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
            'medicine_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'batch_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => false,
            ],
            'expiration_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'initial_quantity' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'remaining_quantity' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
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
        $this->forge->addKey(['tenant_id', 'medicine_id', 'expiration_date'], false, false, 'idx_batch_fefo');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('medicine_id', 'clinic_medicines', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('clinic_medicine_batches', true, ['ENGINE' => 'InnoDB']);

        // 6. Clinic Dispensations (Logged during encounter care)
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
            'encounter_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'medicine_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'batch_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'quantity' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'instructions' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'comment'    => 'e.g. 1 tab after meals TID for 3 days',
            ],
            'dispensed_by_user_id' => [
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
        $this->forge->addKey(['tenant_id', 'encounter_id'], false, false, 'idx_disp_encounter');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('encounter_id', 'clinic_encounters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('medicine_id', 'clinic_medicines', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('batch_id', 'clinic_medicine_batches', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('dispensed_by_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('clinic_dispensations', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_dispensations', true);
        $this->forge->dropTable('clinic_medicine_batches', true);
        $this->forge->dropTable('clinic_medicines', true);
        $this->forge->dropTable('clinic_treatments', true);
        $this->forge->dropTable('clinic_vitals', true);
        $this->forge->dropTable('clinic_encounters', true);
    }
}

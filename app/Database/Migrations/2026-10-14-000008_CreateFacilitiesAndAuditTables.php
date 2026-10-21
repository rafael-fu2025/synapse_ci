<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateFacilitiesAndAuditTables extends Migration
{
    public function up(): void
    {
        // 1. BMG Composting Units (Mechanized tumbler drums)
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
            'unit_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'comment'    => 'e.g. drum-01, drum-02',
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
                'comment'    => 'Tumbler Drum #1',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'maintenance', 'offline'],
                'null'       => false,
                'default'    => 'active',
            ],
            'drum_capacity_kg' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'null'       => false,
                'default'    => 500.00,
            ],
            'default_category' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => false,
                'default'    => 'cafeteria_food_waste',
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
        $this->forge->addUniqueKey(['tenant_id', 'unit_code'], 'uniq_tenant_unit_code');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('bmg_units', true, ['ENGINE' => 'InnoDB']);

        // 2. BMG Batches (Aerobic composting batch cycle)
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
            'unit_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'batch_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'comment'    => 'e.g. BATCH-2026-001',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['idle', 'processing', 'awaiting_output', 'curing', 'released', 'voided'],
                'null'       => false,
                'default'    => 'processing',
            ],
            'total_input_weight_kg' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'null'       => false,
            ],
            'output_weight_kg' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'null'       => true,
            ],
            'quality_grade' => [
                'type'       => 'ENUM',
                'constraint' => ['grade_a', 'grade_b', 'unclassified'],
                'null'       => false,
                'default'    => 'unclassified',
            ],
            'started_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'completed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'release_notes' => [
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
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'batch_code'], 'uniq_tenant_batch_code');
        $this->forge->addKey(['tenant_id', 'unit_id', 'status'], false, false, 'idx_batch_unit_status');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('unit_id', 'bmg_units', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('bmg_batches', true, ['ENGINE' => 'InnoDB']);

        // 3. BMG Process Logs (Daily staff temperature, moisture, and observations)
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
            'batch_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'recorded_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'temperature_c' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => false,
                'comment'    => 'Must reach >= 55°C for EPA 503 pathogen reduction',
            ],
            'moisture_pct' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
            ],
            'oxygen_pct' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
            ],
            'ph_level' => [
                'type'       => 'DECIMAL',
                'constraint' => '4,2',
                'null'       => true,
            ],
            'recorded_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'batch_id', 'recorded_at'], false, false, 'idx_log_batch_time');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('batch_id', 'bmg_batches', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('recorded_by_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('bmg_process_logs', true, ['ENGINE' => 'InnoDB']);

        // 4. BMG Devices (ESP32-S3 microcontroller hardware identities)
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
            'unit_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'Linked tumbler drum unit',
            ],
            'chip_mac' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'comment'    => 'ESP32 MAC address (e.g. b8:1f:3f:d7:ec:18)',
            ],
            'device_token_hash' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => false,
                'comment'    => 'SHA-256 hash of device authentication token',
            ],
            'firmware_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'default'    => 'v1.0.0',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'revoked'],
                'null'       => false,
                'default'    => 'active',
            ],
            'last_seen_at' => [
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
        $this->forge->addUniqueKey(['tenant_id', 'chip_mac'], 'uniq_tenant_chip_mac');
        $this->forge->addUniqueKey('device_token_hash');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('unit_id', 'bmg_units', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('bmg_devices', true, ['ENGINE' => 'InnoDB']);

        // 5. BMG Device Runs (Ingested motor turn session events from ESP32)
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
            'device_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'unit_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'batch_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'run_uid' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
                'comment'    => 'Idempotent UUID from controller flash replay buffer',
            ],
            'motor_state' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'default'    => 'turned_normal',
            ],
            'duration_seconds' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 60,
            ],
            'occurred_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'run_uid'], 'uniq_tenant_run_uid');
        $this->forge->addKey(['tenant_id', 'unit_id', 'occurred_at'], false, false, 'idx_runs_unit_time');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('device_id', 'bmg_devices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('unit_id', 'bmg_units', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('bmg_device_runs', true, ['ENGINE' => 'InnoDB']);

        // 6. Audit Events (Append-only SHA-256 hash-chained immutable audit ledger)
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
            'event_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
                'comment'    => 'e.g. encounter.created, batch.released, notes.finalized',
            ],
            'entity_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'entity_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'actor_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'actor_role' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'actor_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => false,
                'default'    => '127.0.0.1',
            ],
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
            ],
            'payload_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'prev_hash' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => false,
                'comment'    => 'SHA-256 hash of previous row; genesis is 64 zeros',
            ],
            'curr_hash' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => false,
                'comment'    => 'SHA-256(prev_hash . event_type . entity_id . actor . payload . time)',
            ],
            'occurred_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'occurred_at'], false, false, 'idx_audit_tenant_time');
        $this->forge->addKey(['tenant_id', 'entity_type', 'entity_id'], false, false, 'idx_audit_entity');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('audit_events', true, ['ENGINE' => 'InnoDB']);

        // 7. Seed initial drum units (drum-01, drum-02) if not already present
        if ($this->db->table('bmg_units')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $this->db->table('bmg_units')->insertBatch([
                [
                    'id'               => 1,
                    'tenant_id'        => 1,
                    'unit_code'        => 'drum-01',
                    'name'             => 'Aerobic Compost Tumbler #1',
                    'status'           => 'active',
                    'drum_capacity_kg' => 500.00,
                    'default_category' => 'cafeteria_food_waste',
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ],
                [
                    'id'               => 2,
                    'tenant_id'        => 1,
                    'unit_code'        => 'drum-02',
                    'name'             => 'Aerobic Compost Tumbler #2',
                    'status'           => 'active',
                    'drum_capacity_kg' => 500.00,
                    'default_category' => 'agricultural_manure',
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ],
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('audit_events', true);
        $this->forge->dropTable('bmg_device_runs', true);
        $this->forge->dropTable('bmg_devices', true);
        $this->forge->dropTable('bmg_process_logs', true);
        $this->forge->dropTable('bmg_batches', true);
        $this->forge->dropTable('bmg_units', true);
    }
}

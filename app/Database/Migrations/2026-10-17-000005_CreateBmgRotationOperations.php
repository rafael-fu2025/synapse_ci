<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Rotation-only storage; legacy process logs and device runs remain untouched. */
final class CreateBmgRotationOperations extends Migration
{
    public function up(): void
    {
        $options = $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : [];
        $this->forge->addColumn('bmg_devices', [
            'reported_firmware_version' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'rtc_valid' => ['type' => 'BOOLEAN', 'null' => true],
            'applied_schedule_version' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'revoked_at' => ['type' => 'DATETIME', 'null' => true],
            // Unknown for pre-existing devices; registration establishes a new safe cutoff.
            'rotation_bound_at' => ['type' => 'DATETIME', 'null' => true],
            'firmware_reported_at' => ['type' => 'DATETIME', 'null' => true],
            'rtc_reported_at' => ['type' => 'DATETIME', 'null' => true],
            'schedule_reported_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addField([
            'id' => $this->id(),
            'tenant_id' => $this->integer(),
            'unit_id' => $this->integer(),
            'version' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'enabled' => ['type' => 'BOOLEAN', 'default' => false],
            'times_json' => ['type' => 'TEXT'],
            'duration_seconds' => ['type' => 'INT', 'unsigned' => true, 'default' => 60],
            'pwm' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'unit_id'], 'uniq_rotation_schedule_unit');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('unit_id', 'bmg_units', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('bmg_rotation_schedules', false, $options);

        $this->forge->addField([
            'id' => $this->id(),
            'tenant_id' => $this->integer(),
            'unit_id' => $this->integer(),
            'device_id' => $this->integer(),
            'command' => ['type' => 'VARCHAR', 'constraint' => 16],
            'duration_seconds' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'pwm' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'pending'],
            'ack_status' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'ack_message' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'expires_at' => ['type' => 'DATETIME'],
            'acknowledged_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'device_id', 'status'], false, false, 'idx_rotation_commands_pending');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('unit_id', 'bmg_units', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('device_id', 'bmg_devices', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('bmg_rotation_commands', false, $options);

        $this->forge->addField([
            'id' => $this->id(),
            'tenant_id' => $this->integer(),
            'unit_id' => $this->integer(),
            'device_id' => $this->integer(),
            'run_uid' => ['type' => 'VARCHAR', 'constraint' => 64],
            'motor_state' => ['type' => 'VARCHAR', 'constraint' => 32],
            'duration_seconds' => ['type' => 'INT', 'unsigned' => true],
            'occurred_at' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'device_id', 'run_uid'], 'uniq_rotation_device_run');
        $this->forge->addKey(['tenant_id', 'unit_id', 'occurred_at'], false, false, 'idx_rotation_runs_unit');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('unit_id', 'bmg_units', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('device_id', 'bmg_devices', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('bmg_rotation_runs', false, $options);
    }

    public function down(): void
    {
        $this->forge->dropTable('bmg_rotation_runs');
        $this->forge->dropTable('bmg_rotation_commands');
        $this->forge->dropTable('bmg_rotation_schedules');
        $this->forge->dropColumn('bmg_devices', ['reported_firmware_version', 'rtc_valid', 'applied_schedule_version', 'revoked_at',
                    'rotation_bound_at', 'firmware_reported_at', 'rtc_reported_at', 'schedule_reported_at']);
    }

    private function integer(): array
    {
        return ['type' => 'INT', 'unsigned' => true];
    }

    private function id(): array
    {
        return ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true];
    }
}

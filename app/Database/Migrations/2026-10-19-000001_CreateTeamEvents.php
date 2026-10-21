<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Additive, portable schema: no changes to users or scheduling tables. */
final class CreateTeamEvents extends Migration
{
    public function up(): void
    {
        $id = ['type' => 'BIGINT', 'unsigned' => true];
        $tenant = ['type' => 'INT', 'unsigned' => true];
        $unit = ['type' => 'VARCHAR', 'constraint' => 16];
        $time = ['type' => 'DATETIME'];
        $attributes = $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : [];
        $this->forge->addField([
            'id' => $id + ['auto_increment' => true],
            'tenant_id' => $tenant,
            'unit' => $unit,
            'title' => ['type' => 'VARCHAR', 'constraint' => 160],
            'description' => ['type' => 'TEXT'],
            'location' => ['type' => 'VARCHAR', 'constraint' => 160],
            'starts_at' => $time,
            'ends_at' => $time,
            'created_by_user_id' => $id,
            'status' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'scheduled'],
            'version' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'created_at' => $time,
            'updated_at' => $time,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'unit', 'id'], 'team_event_scope');
        $this->forge->addKey(['tenant_id', 'unit', 'status', 'starts_at'], false, false, 'team_event_start');
        $this->forge->addKey(['tenant_id', 'unit', 'ends_at'], false, false, 'team_event_end');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('team_events', false, $attributes);

        $this->forge->addField([
            'tenant_id' => $tenant,
            'unit' => $unit,
            'event_id' => $id,
            'user_id' => $id,
        ]);
        $this->forge->addPrimaryKey(['tenant_id', 'unit', 'event_id', 'user_id']);
        $this->forge->addKey(['tenant_id', 'unit', 'user_id', 'event_id'], false, false, 'team_event_attendee');
        $this->forge->addForeignKey(['tenant_id', 'unit', 'event_id'], 'team_events', ['tenant_id', 'unit', 'id'],
            'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('team_event_participants', false, $attributes);
    }

    public function down(): void
    {
        $this->forge->dropTable('team_event_participants', true);
        $this->forge->dropTable('team_events', true);
    }
}

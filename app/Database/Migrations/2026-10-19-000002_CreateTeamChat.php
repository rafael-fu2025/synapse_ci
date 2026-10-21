<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateTeamChat extends Migration
{
    public function up(): void
    {
        $id = ['type' => 'BIGINT', 'unsigned' => true];
        $tenant = ['type' => 'INT', 'unsigned' => true];
        $unit = ['type' => 'VARCHAR', 'constraint' => 16];
        $attributes = $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : [];
        $this->forge->addField([
            'id' => $id + ['auto_increment' => true],
            'tenant_id' => $tenant,
            'unit' => $unit,
            'sender_user_id' => $id,
            'client_nonce' => ['type' => 'VARCHAR', 'constraint' => 36],
            'body' => ['type' => 'TEXT'],
            'task_id' => $id + ['null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'unit', 'sender_user_id', 'client_nonce'], 'team_chat_nonce');
        $this->forge->addKey(['tenant_id', 'unit', 'id'], false, false, 'team_chat_page');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('sender_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey(['tenant_id', 'unit', 'task_id'], 'team_tasks', ['tenant_id', 'unit', 'id'],
            'RESTRICT', 'RESTRICT');
        $this->forge->createTable('team_chat_messages', false, $attributes);

        $this->forge->addField([
            'tenant_id' => $tenant,
            'unit' => $unit,
            'user_id' => $id,
            'last_read_message_id' => $id + ['default' => 0],
        ]);
        $this->forge->addPrimaryKey(['tenant_id', 'unit', 'user_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('team_chat_reads', false, $attributes);
    }

    public function down(): void
    {
        $this->forge->dropTable('team_chat_reads', true);
        $this->forge->dropTable('team_chat_messages', true);
    }
}

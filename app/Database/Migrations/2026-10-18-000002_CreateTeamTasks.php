<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Additive Forge-only schema; no alterations to users, tenants or legacy module tables. */
final class CreateTeamTasks extends Migration
{
    public function up(): void
    {
        $id = ['type' => 'BIGINT', 'unsigned' => true];
        $tenant = ['type' => 'INT', 'unsigned' => true];
        $unit = ['type' => 'VARCHAR', 'constraint' => 16];
        $time = ['type' => 'DATETIME'];
        $this->forge->addField([
            'id' => $id + ['auto_increment' => true],
            'tenant_id' => $tenant,
            'unit' => $unit,
            'title' => ['type' => 'VARCHAR', 'constraint' => 160],
            'description' => ['type' => 'TEXT'],
            'checklist' => ['type' => 'TEXT'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'todo'],
            'priority' => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'normal'],
            'assignee_user_id' => $id + ['null' => true],
            'due_date' => ['type' => 'DATE', 'null' => true],
            'created_by_user_id' => $id,
            'version' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'created_at' => $time,
            'updated_at' => $time,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'unit', 'id'], 'team_task_scope');
        $this->forge->addKey(['tenant_id', 'unit', 'status', 'id'], false, false, 'team_task_status');
        $this->forge->addKey(['tenant_id', 'unit', 'assignee_user_id', 'id'], false, false, 'team_task_assignee');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('assignee_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $attributes = $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : [];
        $this->forge->createTable('team_tasks', false, $attributes);

        foreach (['team_task_comments', 'team_task_activity'] as $table) {
            $fields = [
                'id' => $id + ['auto_increment' => true],
                'tenant_id' => $tenant,
                'unit' => $unit,
                'task_id' => $id,
                'actor_user_id' => $id,
                'created_at' => $time,
            ];
            if ($table === 'team_task_comments') {
                $fields['body'] = ['type' => 'TEXT'];
            } else {
                // Deliberately no free-form metadata: never copy descriptions or comment bodies.
                $fields['event'] = ['type' => 'VARCHAR', 'constraint' => 24];
                $fields['old_status'] = ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true];
                $fields['new_status'] = ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true];
            }
            $this->forge->addField($fields);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['tenant_id', 'unit', 'task_id', 'id'], false, false, $table . '_page');
            $this->forge->addForeignKey(['tenant_id', 'unit', 'task_id'], 'team_tasks', ['tenant_id', 'unit', 'id'],
                'RESTRICT', 'RESTRICT');
            $this->forge->addForeignKey('actor_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
            $this->forge->createTable($table, false, $attributes);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('team_task_activity', true);
        $this->forge->dropTable('team_task_comments', true);
        $this->forge->dropTable('team_tasks', true);
    }
}

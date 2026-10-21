<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateUsersAndRbacTables extends Migration
{
    public function up(): void
    {
        // 1. Users table (Auth & Accounts)
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
                'null'     => true,
                'comment'  => 'Linked canonical person (student/employee/staff)',
            ],
            'username' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
                'comment'    => 'Student ID, Employee ID, or email',
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,
            ],
            'password_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'comment'    => 'NULL for MIS-authenticated users; hashed for local superadmin/break-glass',
            ],
            'role' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => false,
                'default'    => 'student',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'suspended', 'locked'],
                'null'       => false,
                'default'    => 'active',
            ],
            'last_login_at' => [
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
        $this->forge->addUniqueKey(['tenant_id', 'username'], 'uniq_tenant_username');
        $this->forge->addUniqueKey(['tenant_id', 'person_id'], 'uniq_tenant_user_person');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('person_id', 'people', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('users', true, ['ENGINE' => 'InnoDB']);

        // 2. Permissions table
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'code' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => false,
            ],
            'module' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('permissions', true, ['ENGINE' => 'InnoDB']);

        // 3. Role Permissions mapping
        $this->forge->addField([
            'role' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => false,
            ],
            'permission_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
        ]);

        $this->forge->addPrimaryKey(['role', 'permission_id']);
        $this->forge->addForeignKey('permission_id', 'permissions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('role_permissions', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('role_permissions', true);
        $this->forge->dropTable('permissions', true);
        $this->forge->dropTable('users', true);
    }
}

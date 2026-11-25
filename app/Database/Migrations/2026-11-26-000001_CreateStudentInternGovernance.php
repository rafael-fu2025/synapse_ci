<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateStudentInternGovernance extends Migration
{
    public const INTERN_PERMISSIONS = [
        [
            'code'   => 'interns.manage',
            'name'   => 'Manage student intern placements and supervision',
            'module' => 'admin',
        ],
    ];

    public const INTERN_MANAGEMENT_ROLES = [
        'superadmin',
        'clinic_admin',
        'guidance_admin',
        'bmg_admin',
    ];

    public const INTERN_ROLE_GRANTS = [
        'guidance_intern' => [
            'guidance.queue.read',
            'guidance.queue.manage',
            'guidance.intake.process',
            'guidance.cases.read',
            'guidance.psychosocial.author',
            'guidance.clinical.author',
            'guidance.schedule.read',
            'clinic.patients.read',
            'referrals.read',
        ],
        'bmg_intern' => [
            'facilities.read',
            'facilities.manage',
        ],
    ];

    public function up(): void
    {
        $isMySql = $this->db->DBDriver === 'MySQLi';
        $pkField = $isMySql
            ? ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true]
            : ['type' => 'INTEGER', 'auto_increment' => true];
        $attributes = $isMySql ? ['ENGINE' => 'InnoDB'] : [];

        if (!$this->db->tableExists('student_intern_placements')) {
            $this->forge->addField([
                'id'                 => $pkField,
                'tenant_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
                'user_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'person_id'          => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'unit'               => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false],
                'intern_role'        => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
                'supervisor_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'status'             => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'scheduled'],
                'starts_at'          => ['type' => 'DATETIME', 'null' => false],
                'ends_at'            => ['type' => 'DATETIME', 'null' => false],
                'academic_term'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'assignment_reason'  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => false],
                'activated_at'       => ['type' => 'DATETIME', 'null' => true],
                'ended_at'           => ['type' => 'DATETIME', 'null' => true],
                'revoked_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'revoke_reason'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'updated_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'version'            => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
                'created_at'         => ['type' => 'DATETIME', 'null' => true],
                'updated_at'         => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['tenant_id', 'status', 'starts_at', 'ends_at'], false, false, 'idx_intern_tenant_status_window');
            $this->forge->addKey(['tenant_id', 'user_id', 'status'], false, false, 'idx_intern_tenant_user_status');
            $this->forge->addKey(['tenant_id', 'unit', 'status'], false, false, 'idx_intern_tenant_unit_status');
            $this->forge->addKey(['tenant_id', 'supervisor_user_id', 'status'], false, false, 'idx_intern_tenant_supervisor');
            $this->forge->createTable('student_intern_placements', true, $attributes);
        }

        $this->db->resetDataCache();
        $this->seedPermissions();
    }

    public function down(): void
    {
        if ($this->db->tableExists('role_permissions')) {
            $this->db->table('role_permissions')->whereIn('role', array_keys(self::INTERN_ROLE_GRANTS))->delete();

            if ($this->db->tableExists('permissions')) {
                $codes = array_column(self::INTERN_PERMISSIONS, 'code');
                $permRows = $this->db->table('permissions')->whereIn('code', $codes)->get()->getResultArray();
                $permIds = array_map(static fn (array $r): int => (int) $r['id'], $permRows);
                if ($permIds !== []) {
                    $this->db->table('role_permissions')->whereIn('permission_id', $permIds)->delete();
                    $this->db->table('permissions')->whereIn('id', $permIds)->delete();
                }
            }
        }

        $this->forge->dropTable('student_intern_placements', true);
    }

    private function seedPermissions(): void
    {
        if (!$this->db->tableExists('permissions') || !$this->db->tableExists('role_permissions')) {
            return;
        }

        foreach (self::INTERN_PERMISSIONS as $perm) {
            $existing = $this->db->table('permissions')->where('code', $perm['code'])->get()->getRowArray();
            if ($existing === null) {
                $this->db->table('permissions')->insert($perm);
                $permId = (int) $this->db->insertID();
            } else {
                $permId = (int) $existing['id'];
            }

            foreach (self::INTERN_MANAGEMENT_ROLES as $role) {
                $key = ['role' => $role, 'permission_id' => $permId];
                if ($this->db->table('role_permissions')->where($key)->countAllResults() === 0) {
                    $this->db->table('role_permissions')->insert($key);
                }
            }
        }

        foreach (self::INTERN_ROLE_GRANTS as $role => $codes) {
            $permRows = $this->db->table('permissions')->whereIn('code', $codes)->get()->getResultArray();
            foreach ($permRows as $permRow) {
                $key = ['role' => $role, 'permission_id' => (int) $permRow['id']];
                if ($this->db->table('role_permissions')->where($key)->countAllResults() === 0) {
                    $this->db->table('role_permissions')->insert($key);
                }
            }
        }
    }
}

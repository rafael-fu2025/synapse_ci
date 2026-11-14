<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGuidanceMultipleIntelligenceKernel extends Migration
{
    public function up(): void
    {
        // 1. Table: guidance_mi_assignments
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
            'assigned_by_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['assigned', 'in_progress', 'completed', 'cancelled'],
                'default'    => 'assigned',
                'null'       => false,
            ],
            'academic_term' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'academic_year' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
            ],
            'assigned_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'due_at' => [
                'type' => 'DATETIME',
                'null' => true,
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
        $this->forge->addKey(['tenant_id', 'person_id'], false, false, 'idx_gmi_assign_person');
        $this->forge->addKey(['tenant_id', 'status'], false, false, 'idx_gmi_assign_status');
        $this->forge->addKey(['tenant_id', 'assigned_by_user_id'], false, false, 'idx_gmi_assign_assigned_by');
        $this->forge->createTable('guidance_mi_assignments', true, ['ENGINE' => 'InnoDB']);

        // 2. Table: guidance_mi_submissions
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
            'assignment_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['draft', 'finalized'],
                'default'    => 'draft',
                'null'       => false,
            ],
            'draft_responses_cipher' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'scores_json' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'domain_scores_json' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'attested_by_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'attested_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'attestation_statement_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'snapshot_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'form_instance_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
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
        $this->forge->addUniqueKey(['tenant_id', 'assignment_id'], 'uq_gmi_submissions_assignment');
        $this->forge->addKey(['tenant_id', 'person_id'], false, false, 'idx_gmi_sub_person');
        $this->forge->addKey(['tenant_id', 'status'], false, false, 'idx_gmi_sub_status');
        $this->forge->createTable('guidance_mi_submissions', true, ['ENGINE' => 'InnoDB']);

        // 3. Add assessment permissions
        $permissions = [
            'guidance.assessments.assign' => ['guidance', 'Assign multiple intelligence and guidance assessments', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.assessments.take'   => ['guidance', 'Take assigned guidance assessments', ['student', 'employee', 'counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.assessments.view'   => ['guidance', 'View multiple intelligence assessment results', ['student', 'employee', 'counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
        ];

        foreach ($permissions as $code => [$module, $name, $roles]) {
            $row = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
            if ($row === null) {
                $this->db->table('permissions')->insert(['code' => $code, 'name' => $name, 'module' => $module]);
                $id = (int) $this->db->insertID();
            } else {
                $id = (int) $row['id'];
            }
            foreach ($roles as $role) {
                $key = ['role' => $role, 'permission_id' => $id];
                if ($this->db->table('role_permissions')->where($key)->countAllResults() === 0) {
                    $this->db->table('role_permissions')->insert($key);
                }
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('guidance_mi_submissions', true);
        $this->forge->dropTable('guidance_mi_assignments', true);

        $codes = [
            'guidance.assessments.assign',
            'guidance.assessments.take',
            'guidance.assessments.view',
        ];
        $permRows = $this->db->table('permissions')->whereIn('code', $codes)->get()->getResultArray();
        $permIds = array_column($permRows, 'id');
        if (!empty($permIds)) {
            $this->db->table('role_permissions')->whereIn('permission_id', $permIds)->delete();
            $this->db->table('permissions')->whereIn('id', $permIds)->delete();
        }
    }
}

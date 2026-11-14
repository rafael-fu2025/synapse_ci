<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGuidanceServiceEvaluationsKernel extends Migration
{
    public function up(): void
    {
        // 1. Table: guidance_evaluation_tokens
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
            'episode_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'session_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'evaluation_type' => [
                'type'       => 'ENUM',
                'constraint' => ['psychosocial_support_evaluation', 'counseling_evaluation'],
                'null'       => false,
            ],
            'provider_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'eligibility_hmac' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'token_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['issued', 'redeemed', 'expired'],
                'default'    => 'issued',
                'null'       => false,
            ],
            'issued_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'redeemed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'expires_at' => [
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
        $this->forge->addUniqueKey(['tenant_id', 'eligibility_hmac'], 'uq_geval_token_elig');
        $this->forge->addUniqueKey(['tenant_id', 'token_hash'], 'uq_geval_token_hash');
        $this->forge->addKey(['tenant_id', 'provider_user_id', 'status'], false, false, 'idx_geval_token_provider');
        $this->forge->createTable('guidance_evaluation_tokens', true, ['ENGINE' => 'InnoDB']);

        // 2. Table: guidance_service_evaluations
        // Strictly unlinkable: NO person_id, NO student_id, NO session_id, NO episode_id, NO token_id
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
            'evaluation_type' => [
                'type'       => 'ENUM',
                'constraint' => ['psychosocial_support_evaluation', 'counseling_evaluation'],
                'null'       => false,
            ],
            'form_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
            ],
            'provider_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
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
            'visit_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
            ],
            'ratings_json' => [
                'type' => 'JSON',
                'null' => false,
            ],
            'boolean_response' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
            ],
            'comments_cipher' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'comment_moderation_status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending_review', 'approved', 'redacted'],
                'default'    => 'pending_review',
                'null'       => false,
            ],
            'moderated_by_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'moderated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'submitted_date' => [
                'type' => 'DATE',
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
        $this->forge->addKey(['tenant_id', 'provider_user_id', 'evaluation_type'], false, false, 'idx_geval_provider_type');
        $this->forge->addKey(['tenant_id', 'comment_moderation_status'], false, false, 'idx_geval_mod_status');
        $this->forge->addKey(['tenant_id', 'submitted_date'], false, false, 'idx_geval_submitted_date');
        $this->forge->createTable('guidance_service_evaluations', true, ['ENGINE' => 'InnoDB']);

        // 3. Seed evaluation permissions and role mappings
        $permissions = [
            'guidance.evaluations.submit'          => ['guidance', 'Submit pseudonymous service evaluations', ['student', 'employee', 'counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.evaluations.view_aggregates' => ['guidance', 'View threshold-safe aggregate service evaluations', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.evaluations.moderate'        => ['guidance', 'Moderate service evaluation qualitative comments', ['guidance_supervisor', 'guidance_admin', 'superadmin']],
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
        $this->forge->dropTable('guidance_service_evaluations', true);
        $this->forge->dropTable('guidance_evaluation_tokens', true);

        $codes = [
            'guidance.evaluations.submit',
            'guidance.evaluations.view_aggregates',
            'guidance.evaluations.moderate',
        ];
        $permRows = $this->db->table('permissions')->whereIn('code', $codes)->get()->getResultArray();
        $permIds = array_column($permRows, 'id');
        if (!empty($permIds)) {
            $this->db->table('role_permissions')->whereIn('permission_id', $permIds)->delete();
            $this->db->table('permissions')->whereIn('id', $permIds)->delete();
        }
    }
}


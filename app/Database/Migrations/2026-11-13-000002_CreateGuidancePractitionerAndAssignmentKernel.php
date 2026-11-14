<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGuidancePractitionerAndAssignmentKernel extends Migration
{
    public function up(): void
    {
        // 1. Guidance Practitioner Profiles
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
            ],
            'user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'credential_tier' => [
                'type'       => 'ENUM',
                'constraint' => ['counselor_associate', 'licensed_counselor', 'guidance_supervisor', 'qualified_assessor'],
                'null'       => false,
            ],
            'license_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'credential_expiry' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'professional_standing' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'suspended', 'lapsed'],
                'null'       => false,
                'default'    => 'active',
            ],
            'service_scope_json' => [
                'type' => 'LONGTEXT',
                'null' => false,
            ],
            'is_active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
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
        $this->forge->addUniqueKey(['tenant_id', 'user_id'], 'uq_gpractitioner_user');
        $this->forge->addKey(['tenant_id', 'person_id'], false, false, 'idx_gpractitioner_person');
        $this->forge->createTable('guidance_practitioner_profiles', true, ['ENGINE' => 'InnoDB']);

        // 2. Guidance Supervision Assignments
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
            'associate_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'supervisor_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'completed', 'revoked'],
                'null'       => false,
                'default'    => 'active',
            ],
            'co_sign_required' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
            ],
            'starts_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'ends_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'associate_user_id'], false, false, 'idx_gsupervision_associate');
        $this->forge->addKey(['tenant_id', 'supervisor_user_id'], false, false, 'idx_gsupervision_supervisor');
        $this->forge->createTable('guidance_supervision_assignments', true, ['ENGINE' => 'InnoDB']);

        // 3. Guidance Case Assignments
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
            'case_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'counsellor_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'assigned_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'assignment_type' => [
                'type'       => 'ENUM',
                'constraint' => ['initial', 'transfer_accepted', 'manager_reassigned', 'legacy_import'],
                'null'       => false,
            ],
            'reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'assigned_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'relinquished_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'case_id'], false, false, 'idx_gcase_assignment_case');
        $this->forge->addKey(['tenant_id', 'counsellor_user_id'], false, false, 'idx_gcase_assignment_counsellor');
        $this->forge->createTable('guidance_case_assignments', true, ['ENGINE' => 'InnoDB']);

        // 4. Guidance Case Transfers
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
            'case_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'from_counsellor_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'to_counsellor_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'transfer_type' => [
                'type'       => 'ENUM',
                'constraint' => ['consultation', 'full_transfer'],
                'null'       => false,
            ],
            'reason' => [
                'type' => 'TEXT',
                'null' => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'accepted', 'declined', 'cancelled'],
                'null'       => false,
                'default'    => 'pending',
            ],
            'requested_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'responded_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'response_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'case_id'], false, false, 'idx_gcase_transfer_case');
        $this->forge->addKey(['tenant_id', 'from_counsellor_user_id'], false, false, 'idx_gcase_transfer_from');
        $this->forge->addKey(['tenant_id', 'to_counsellor_user_id'], false, false, 'idx_gcase_transfer_to');
        $this->forge->createTable('guidance_case_transfers', true, ['ENGINE' => 'InnoDB']);

        // 5. Additive Permissions and Role Mappings
        $permissions = [
            'guidance.intake.process' => ['guidance', 'Process guidance intakes', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.safety.triage' => ['guidance', 'Triage safety and crisis concerns', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.cases.transfer' => ['guidance', 'Initiate and manage case transfers', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.cases.reassign' => ['guidance', 'Reassign counseling cases', ['guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.psychosocial.author' => ['guidance', 'Author psychosocial assessment notes', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.psychosocial.cosign' => ['guidance', 'Co-sign psychosocial assessment notes', ['guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.counseling.author' => ['guidance', 'Author counseling session notes', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.counseling.cosign' => ['guidance', 'Co-sign counseling session notes', ['guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.call_slips.issue' => ['guidance', 'Issue guidance call slips', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.assessments.administer' => ['guidance', 'Administer guidance assessments', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.assessments.interpret' => ['guidance', 'Interpret psychological and guidance assessments', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.evaluations.view_aggregate' => ['guidance', 'View aggregate evaluation summaries', ['guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.forms.manage' => ['guidance', 'Manage guidance form definitions and templates', ['guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.documents.print' => ['guidance', 'Generate and print guidance documents', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.disclosures.govern' => ['guidance', 'Govern confidential records and disclosures', ['guidance_supervisor', 'guidance_admin', 'superadmin']],
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
        $this->forge->dropTable('guidance_case_transfers', true);
        $this->forge->dropTable('guidance_case_assignments', true);
        $this->forge->dropTable('guidance_supervision_assignments', true);
        $this->forge->dropTable('guidance_practitioner_profiles', true);

        $codes = [
            'guidance.intake.process', 'guidance.safety.triage', 'guidance.cases.transfer', 'guidance.cases.reassign',
            'guidance.psychosocial.author', 'guidance.psychosocial.cosign', 'guidance.counseling.author', 'guidance.counseling.cosign',
            'guidance.call_slips.issue', 'guidance.assessments.administer', 'guidance.assessments.interpret',
            'guidance.evaluations.view_aggregate', 'guidance.forms.manage', 'guidance.documents.print', 'guidance.disclosures.govern',
        ];
        $rows = $this->db->table('permissions')->whereIn('code', $codes)->get()->getResultArray();
        if ($rows !== []) {
            $ids = array_column($rows, 'id');
            $this->db->table('role_permissions')->whereIn('permission_id', $ids)->delete();
            $this->db->table('permissions')->whereIn('id', $ids)->delete();
        }
    }
}


<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGuidanceClinicalFormsKernel extends Migration
{
    public function up(): void
    {
        // 1. Extend guidance_form_instances with clinical co-sign and episode linkages
        if ($this->db->tableExists('guidance_form_instances')) {
            $fieldsToAdd = [];
            $existingFields = $this->db->getFieldNames('guidance_form_instances');

            if (!in_array('episode_id', $existingFields, true)) {
                $fieldsToAdd['episode_id'] = [
                    'type'     => 'BIGINT',
                    'unsigned' => true,
                    'null'     => true,
                    'after'    => 'case_id',
                ];
            }
            if (!in_array('assigned_supervisor_user_id', $existingFields, true)) {
                $fieldsToAdd['assigned_supervisor_user_id'] = [
                    'type'     => 'BIGINT',
                    'unsigned' => true,
                    'null'     => true,
                    'after'    => 'finalized_by_user_id',
                ];
            }
            if (!in_array('submitted_for_cosign_at', $existingFields, true)) {
                $fieldsToAdd['submitted_for_cosign_at'] = [
                    'type' => 'DATETIME',
                    'null' => true,
                    'after' => 'assigned_supervisor_user_id',
                ];
            }
            if (!in_array('submission_notes', $existingFields, true)) {
                $fieldsToAdd['submission_notes'] = [
                    'type' => 'TEXT',
                    'null' => true,
                    'after' => 'submitted_for_cosign_at',
                ];
            }
            if (!in_array('supervisor_notes', $existingFields, true)) {
                $fieldsToAdd['supervisor_notes'] = [
                    'type' => 'TEXT',
                    'null' => true,
                    'after' => 'submission_notes',
                ];
            }

            if (!empty($fieldsToAdd)) {
                $this->forge->addColumn('guidance_form_instances', $fieldsToAdd);
            }

            // Add index for episode lookup if not already indexed
            $table = $this->db->protectIdentifiers($this->db->prefixTable('guidance_form_instances'));
            $indexCheck = $this->db->query("SHOW INDEX FROM {$table} WHERE Key_name = 'idx_gform_inst_episode'")->getResultArray();
            if (empty($indexCheck)) {
                $this->db->query("ALTER TABLE {$table} ADD INDEX idx_gform_inst_episode (tenant_id, episode_id)");
            }
        }

        // 2. Clinical permissions and role mappings
        $permissions = [
            'guidance.clinical.author' => ['guidance', 'Author clinical controlled forms', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.clinical.cosign' => ['guidance', 'Co-sign clinical controlled forms', ['guidance_supervisor', 'guidance_admin', 'superadmin']],
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
        if ($this->db->tableExists('guidance_form_instances')) {
            $table = $this->db->protectIdentifiers($this->db->prefixTable('guidance_form_instances'));
            $indexCheck = $this->db->query("SHOW INDEX FROM {$table} WHERE Key_name = 'idx_gform_inst_episode'")->getResultArray();
            if (!empty($indexCheck)) {
                $this->db->query("ALTER TABLE {$table} DROP INDEX idx_gform_inst_episode");
            }

            $columnsToDrop = [
                'supervisor_notes',
                'submission_notes',
                'submitted_for_cosign_at',
                'assigned_supervisor_user_id',
                'episode_id',
            ];
            foreach ($columnsToDrop as $col) {
                if ($this->db->fieldExists($col, 'guidance_form_instances')) {
                    $this->forge->dropColumn('guidance_form_instances', $col);
                }
            }
        }

        $codes = [
            'guidance.clinical.author',
            'guidance.clinical.cosign',
        ];
        $rows = $this->db->table('permissions')->whereIn('code', $codes)->get()->getResultArray();
        if ($rows !== []) {
            $ids = array_column($rows, 'id');
            $this->db->table('role_permissions')->whereIn('permission_id', $ids)->delete();
            $this->db->table('permissions')->whereIn('id', $ids)->delete();
        }
    }
}

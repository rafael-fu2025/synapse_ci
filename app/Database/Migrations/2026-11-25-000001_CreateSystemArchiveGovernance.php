<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateSystemArchiveGovernance extends Migration
{
    public const ARCHIVE_PERMISSIONS = [
        ['code' => 'archive.read', 'name' => 'View scoped archive center, retention policies, and legal holds', 'module' => 'admin'],
        ['code' => 'archive.manage', 'name' => 'Archive and restore permitted records in scope', 'module' => 'admin'],
        ['code' => 'archive.policies.manage', 'name' => 'Configure institutional archive retention policies', 'module' => 'admin'],
        ['code' => 'archive.legal_hold.manage', 'name' => 'Place and lift archive legal holds', 'module' => 'admin'],
    ];

    public const ARCHIVE_ROLE_GRANTS = [
        'superadmin'          => ['archive.read', 'archive.manage', 'archive.policies.manage', 'archive.legal_hold.manage'],
        'clinic_admin'        => ['archive.read', 'archive.manage'],
        'guidance_admin'      => ['archive.read', 'archive.manage', 'archive.legal_hold.manage'],
        'guidance_supervisor' => ['archive.read', 'archive.manage'],
        'bmg_admin'           => ['archive.read', 'archive.manage'],
        'audit_reader'        => ['archive.read'],
    ];

    private const SOFT_DELETE_TABLES = [
        'surveys',
        'team_tasks',
        'team_events',
        'carousel_slides',
        'guidance_form_instances',
    ];

    public function up(): void
    {
        $isMySql = $this->db->DBDriver === 'MySQLi';
        $pkField = $isMySql
            ? ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true]
            : ['type' => 'INTEGER', 'auto_increment' => true];
        $attributes = $isMySql ? ['ENGINE' => 'InnoDB'] : [];

        // 1. archive_retention_policies
        if (!$this->db->tableExists('archive_retention_policies')) {
            $this->forge->addField([
                'id'                        => $pkField,
                'tenant_id'                 => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
                'policy_code'               => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'module'                    => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
                'entity_type'               => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false, 'default' => '*'],
                'title'                     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
                'duration_months'           => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 60],
                'legal_basis'               => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
                'auto_archive_after_months' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'purge_enabled'             => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
                'is_active'                 => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
                'created_by_user_id'        => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'updated_by_user_id'        => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'created_at'                => ['type' => 'DATETIME', 'null' => false],
                'updated_at'                => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey(['tenant_id', 'policy_code'], 'uq_archive_retention_code');
            $this->forge->addKey(['tenant_id', 'module', 'entity_type', 'is_active'], false, false, 'idx_archive_retention_scope');
            $this->forge->createTable('archive_retention_policies', true, $attributes);
        }

        // 2. archive_records
        if (!$this->db->tableExists('archive_records')) {
            $this->forge->addField([
                'id'                  => $pkField,
                'tenant_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
                'module'              => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
                'entity_type'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'entity_id'           => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'record_ref'          => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
                'unit'                => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'state'               => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => false, 'default' => 'archived'],
                'reason'              => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => false],
                'archived_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false, 'default' => 0],
                'archived_at'         => ['type' => 'DATETIME', 'null' => false],
                'restored_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'restored_at'         => ['type' => 'DATETIME', 'null' => true],
                'restore_reason'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'retention_policy_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'retention_due_at'    => ['type' => 'DATETIME', 'null' => true],
                'metadata_json'       => ['type' => 'TEXT', 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => false],
                'updated_at'          => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['tenant_id', 'state', 'archived_at'], false, false, 'idx_archive_tenant_state_at');
            $this->forge->addKey(['tenant_id', 'entity_type', 'entity_id', 'state'], false, false, 'idx_archive_tenant_entity');
            $this->forge->addKey(['tenant_id', 'module', 'state', 'archived_at'], false, false, 'idx_archive_tenant_module');
            $this->forge->addKey(['tenant_id', 'state', 'retention_due_at'], false, false, 'idx_archive_retention_due');
            $this->forge->createTable('archive_records', true, $attributes);
        }

        // 3. archive_legal_holds
        if (!$this->db->tableExists('archive_legal_holds')) {
            $this->forge->addField([
                'id'                => $pkField,
                'tenant_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
                'module'            => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
                'entity_type'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'entity_id'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'record_ref'        => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
                'reason'            => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => false],
                'reference_code'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'placed_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'placed_at'         => ['type' => 'DATETIME', 'null' => false],
                'lifted_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'lifted_at'         => ['type' => 'DATETIME', 'null' => true],
                'lift_reason'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'is_active'         => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
                'created_at'        => ['type' => 'DATETIME', 'null' => false],
                'updated_at'        => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['tenant_id', 'entity_type', 'entity_id', 'is_active'], false, false, 'idx_archive_hold_entity');
            $this->forge->addKey(['tenant_id', 'is_active', 'placed_at'], false, false, 'idx_archive_hold_active');
            $this->forge->createTable('archive_legal_holds', true, $attributes);
        }

        // 4. Add nullable deleted_at columns safely to operational/form tables
        foreach (self::SOFT_DELETE_TABLES as $table) {
            if ($this->db->tableExists($table) && !$this->db->fieldExists('deleted_at', $table)) {
                $this->forge->addColumn($table, [
                    'deleted_at' => ['type' => 'DATETIME', 'null' => true],
                ]);
            }
        }
        $this->db->resetDataCache();

        $now = gmdate('Y-m-d H:i:s');
        $this->seedDefaultRetentionPolicies($now);
        $this->seedArchivePermissions();
        $this->backfillLegacyArchives($now);
    }

    public function down(): void
    {
        if ($this->db->tableExists('permissions') && $this->db->tableExists('role_permissions')) {
            $codes = array_column(self::ARCHIVE_PERMISSIONS, 'code');
            $permRows = $this->db->table('permissions')->whereIn('code', $codes)->get()->getResultArray();
            $permIds = array_map(static fn (array $r): int => (int) $r['id'], $permRows);
            if ($permIds !== []) {
                $this->db->table('role_permissions')->whereIn('permission_id', $permIds)->delete();
                $this->db->table('permissions')->whereIn('id', $permIds)->delete();
            }
        }

        foreach (self::SOFT_DELETE_TABLES as $table) {
            if ($this->db->tableExists($table) && $this->db->fieldExists('deleted_at', $table)) {
                $this->forge->dropColumn($table, 'deleted_at');
            }
        }
        $this->db->resetDataCache();

        $this->forge->dropTable('archive_legal_holds', true);
        $this->forge->dropTable('archive_records', true);
        $this->forge->dropTable('archive_retention_policies', true);
    }

    private function seedDefaultRetentionPolicies(string $now): void
    {
        if (!$this->db->tableExists('archive_retention_policies')) {
            return;
        }

        $tenantIds = [1];
        if ($this->db->tableExists('tenants')) {
            $rows = $this->db->table('tenants')->select('id')->get()->getResultArray();
            if ($rows !== []) {
                $tenantIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            }
        }

        $defaults = [
            [
                'policy_code'               => 'RET-CLINIC-001',
                'module'                    => 'clinic',
                'entity_type'               => '*',
                'title'                     => 'Clinic Records & Health Clearances Retention',
                'duration_months'           => 60,
                'legal_basis'               => 'DOH & Data Privacy Act Clinical Record Retention',
                'auto_archive_after_months' => 12,
            ],
            [
                'policy_code'               => 'RET-GUIDANCE-001',
                'module'                    => 'guidance',
                'entity_type'               => '*',
                'title'                     => 'Guidance & Counseling Records Retention',
                'duration_months'           => 60,
                'legal_basis'               => 'RA 9258 & Data Privacy Act Counseling Record Retention',
                'auto_archive_after_months' => 12,
            ],
            [
                'policy_code'               => 'RET-BMG-001',
                'module'                    => 'facilities',
                'entity_type'               => '*',
                'title'                     => 'BMG Drum & Composting Hardware Lifecycle Retention',
                'duration_months'           => 36,
                'legal_basis'               => 'Institutional Facilities & Equipment Lifecycle Policy',
                'auto_archive_after_months' => 6,
            ],
            [
                'policy_code'               => 'RET-CONTENT-001',
                'module'                    => 'guidance',
                'entity_type'               => 'survey',
                'title'                     => 'Campus Surveys & Assessment Instruments Retention',
                'duration_months'           => 24,
                'legal_basis'               => 'Institutional Campus Survey & Assessment Retention',
                'auto_archive_after_months' => 6,
            ],
            [
                'policy_code'               => 'RET-OPS-001',
                'module'                    => 'all',
                'entity_type'               => '*',
                'title'                     => 'General Operational Archive Default Policy',
                'duration_months'           => 12,
                'legal_basis'               => 'Institutional Operational Records Retention Standard',
                'auto_archive_after_months' => 6,
            ],
        ];

        foreach ($tenantIds as $tenantId) {
            foreach ($defaults as $policy) {
                $exists = $this->db->table('archive_retention_policies')
                    ->where('tenant_id', $tenantId)
                    ->where('policy_code', $policy['policy_code'])
                    ->countAllResults();
                if ($exists > 0) {
                    continue;
                }
                $this->db->table('archive_retention_policies')->insert($policy + [
                    'tenant_id'          => $tenantId,
                    'purge_enabled'      => 0,
                    'is_active'          => 1,
                    'created_by_user_id' => null,
                    'updated_by_user_id' => null,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);
            }
        }
    }

    private function seedArchivePermissions(): void
    {
        if (!$this->db->tableExists('permissions') || !$this->db->tableExists('role_permissions')) {
            return;
        }

        $permMap = [];
        foreach (self::ARCHIVE_PERMISSIONS as $perm) {
            $existing = $this->db->table('permissions')->where('code', $perm['code'])->get()->getRowArray();
            if ($existing !== null) {
                $permMap[$perm['code']] = (int) $existing['id'];
            } else {
                $this->db->table('permissions')->insert($perm);
                $permMap[$perm['code']] = (int) $this->db->insertID();
            }
        }

        foreach (self::ARCHIVE_ROLE_GRANTS as $role => $codes) {
            foreach ($codes as $code) {
                if (!isset($permMap[$code])) {
                    continue;
                }
                $key = ['role' => $role, 'permission_id' => $permMap[$code]];
                if ($this->db->table('role_permissions')->where($key)->countAllResults() === 0) {
                    $this->db->table('role_permissions')->insert($key);
                }
            }
        }
    }

    private function backfillLegacyArchives(string $now): void
    {
        if (!$this->db->tableExists('archive_records')) {
            return;
        }

        $policyMap = [];
        if ($this->db->tableExists('archive_retention_policies')) {
            foreach ($this->db->table('archive_retention_policies')->where('is_active', 1)->get()->getResultArray() as $p) {
                $policyMap[(int) $p['tenant_id'] . '|' . $p['module'] . '|' . $p['entity_type']] = $p;
            }
        }

        $resolvePolicy = static function (int $tenantId, string $module, string $entityType) use ($policyMap): ?array {
            return $policyMap[$tenantId . '|' . $module . '|' . $entityType]
                ?? $policyMap[$tenantId . '|' . $module . '|*']
                ?? $policyMap[$tenantId . '|all|*']
                ?? null;
        };

        $insertBackfill = function (int $tenantId, string $module, string $entityType, int $entityId, string $ref, ?string $unit, int $actorId, string $archivedAt, array $meta) use ($now, $resolvePolicy): void {
            $exists = $this->db->table('archive_records')
                ->where('tenant_id', $tenantId)
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('state', 'archived')
                ->countAllResults();
            if ($exists > 0) {
                return;
            }

            $policy = $resolvePolicy($tenantId, $module, $entityType);
            $dueAt = null;
            if ($policy !== null && (int) ($policy['duration_months'] ?? 0) > 0) {
                $ts = strtotime($archivedAt . ' +' . (int) $policy['duration_months'] . ' months');
                $dueAt = $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : null;
            }

            $this->db->table('archive_records')->insert([
                'tenant_id'           => $tenantId,
                'module'              => $module,
                'entity_type'         => $entityType,
                'entity_id'           => $entityId,
                'record_ref'          => mb_substr($ref !== '' ? $ref : ($entityType . ' #' . $entityId), 0, 128),
                'unit'                => $unit,
                'state'               => 'archived',
                'reason'              => 'Archived prior to unified Archive Center migration',
                'archived_by_user_id' => $actorId,
                'archived_at'         => $archivedAt,
                'restored_by_user_id' => null,
                'restored_at'         => null,
                'restore_reason'      => null,
                'retention_policy_id' => $policy['id'] ?? null,
                'retention_due_at'    => $dueAt,
                'metadata_json'       => json_encode($meta + ['backfilled' => true], JSON_UNESCAPED_SLASHES),
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        };

        // Backfill archived announcements
        if ($this->db->tableExists('announcements')) {
            $rows = $this->db->table('announcements')->where('status', 'archived')->get()->getResultArray();
            foreach ($rows as $row) {
                $unit = in_array($row['unit'] ?? '', ['clinic', 'guidance'], true) ? (string) $row['unit'] : 'clinic';
                $archivedAt = (string) ($row['updated_at'] ?? $row['created_at'] ?? $now);
                $insertBackfill(
                    (int) $row['tenant_id'],
                    $unit,
                    'announcement',
                    (int) $row['id'],
                    trim((string) ($row['title'] ?? '')),
                    $unit,
                    (int) ($row['created_by'] ?? 0),
                    $archivedAt,
                    ['audience' => $row['audience'] ?? null]
                );
            }
        }

        // Backfill archived question bank entries
        if ($this->db->tableExists('survey_question_bank')) {
            $rows = $this->db->table('survey_question_bank')->where('status', 'archived')->get()->getResultArray();
            foreach ($rows as $row) {
                $archivedAt = (string) ($row['updated_at'] ?? $row['created_at'] ?? $now);
                $insertBackfill(
                    (int) $row['tenant_id'],
                    'guidance',
                    'question_bank',
                    (int) $row['id'],
                    'Question Bank #' . (int) $row['id'],
                    'guidance',
                    (int) ($row['created_by'] ?? 0),
                    $archivedAt,
                    ['revision' => (int) ($row['current_revision'] ?? 1)]
                );
            }
        }

        // Backfill archived clinic medicines
        if ($this->db->tableExists('clinic_medicines') && $this->db->fieldExists('is_active', 'clinic_medicines')) {
            $rows = $this->db->table('clinic_medicines')->where('is_active', 0)->get()->getResultArray();
            foreach ($rows as $row) {
                $archivedAt = (string) ($row['updated_at'] ?? $row['created_at'] ?? $now);
                $ref = trim((string) ($row['name'] ?? ''));
                $strength = trim((string) ($row['strength'] ?? ''));
                if ($strength !== '') {
                    $ref .= ' (' . $strength . ')';
                }
                $insertBackfill(
                    (int) $row['tenant_id'],
                    'clinic',
                    'clinic_medicine',
                    (int) $row['id'],
                    $ref,
                    'clinic',
                    0,
                    $archivedAt,
                    ['category' => $row['category'] ?? null]
                );
            }
        }

        // Backfill archived BMG units
        if ($this->db->tableExists('bmg_units') && $this->db->fieldExists('deleted_at', 'bmg_units')) {
            $rows = $this->db->table('bmg_units')->where('deleted_at !=', null)->get()->getResultArray();
            foreach ($rows as $row) {
                $archivedAt = (string) ($row['deleted_at'] ?? $row['updated_at'] ?? $now);
                $ref = trim((string) ($row['unit_code'] ?? '') . ' · ' . (string) ($row['name'] ?? ''), ' ·');
                $insertBackfill(
                    (int) $row['tenant_id'],
                    'facilities',
                    'bmg_unit',
                    (int) $row['id'],
                    $ref,
                    'facilities',
                    0,
                    $archivedAt,
                    ['unit_code' => $row['unit_code'] ?? null]
                );
            }
        }
    }
}

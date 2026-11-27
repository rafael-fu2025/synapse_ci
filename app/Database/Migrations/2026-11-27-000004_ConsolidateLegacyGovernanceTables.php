<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Tier 2 Phase 3 consolidation:
 * - Migrates any legacy guidance_legal_holds and guidance_retention_policies rows into archive_* tables.
 * - Remaps guidance_form_instances.retention_policy_id FK to archive_retention_policies(tenant_id, id).
 * - Drops superseded guidance_legal_holds, guidance_retention_policies, and empty bmg_device_runs tables.
 */
final class ConsolidateLegacyGovernanceTables extends Migration
{
    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }

        $this->consolidateGuidanceLegalHolds();
        $this->consolidateGuidanceRetentionPolicies();
        $this->dropEmptyBmgDeviceRuns();
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        throw new RuntimeException('Tier 2 governance consolidation is forward-only; recreating retired tables is disallowed.');
    }

    private function consolidateGuidanceLegalHolds(): void
    {
        if ($this->db->tableExists('guidance_legal_holds') && $this->db->tableExists('archive_legal_holds')) {
            $this->sql(
                "INSERT INTO {archive_legal_holds}
                 (tenant_id, module, entity_type, entity_id, record_ref, reason, reference_code,
                  placed_by_user_id, placed_at, lifted_by_user_id, lifted_at, lift_reason, is_active, created_at, updated_at)
                 SELECT g.tenant_id, 'guidance', g.entity_type, g.entity_id,
                        CONCAT('guidance:', g.entity_type, ':', g.entity_id),
                        LEFT(g.reason, 1000), NULL,
                        g.placed_by_user_id, g.placed_at, g.lifted_by_user_id, g.lifted_at, NULL,
                        g.is_active, g.placed_at, COALESCE(g.lifted_at, g.placed_at)
                 FROM {guidance_legal_holds} g
                 WHERE NOT EXISTS (
                     SELECT 1 FROM {archive_legal_holds} a
                     WHERE a.tenant_id = g.tenant_id
                       AND a.module = 'guidance'
                       AND a.entity_type = g.entity_type
                       AND a.entity_id = g.entity_id
                       AND a.placed_at = g.placed_at
                 )"
            );
        }

        if ($this->db->tableExists('guidance_legal_holds')) {
            $this->sql('DROP TABLE IF EXISTS {guidance_legal_holds}');
        }
    }

    private function consolidateGuidanceRetentionPolicies(): void
    {
        $hasGuidancePolicies = $this->db->tableExists('guidance_retention_policies');
        $hasArchivePolicies  = $this->db->tableExists('archive_retention_policies');

        if ($hasGuidancePolicies && $hasArchivePolicies) {
            $this->sql(
                "INSERT INTO {archive_retention_policies}
                 (tenant_id, policy_code, module, entity_type, title, duration_months, legal_basis,
                  auto_archive_after_months, purge_enabled, is_active, created_by_user_id, updated_by_user_id, created_at, updated_at)
                 SELECT g.tenant_id, g.policy_code, 'guidance', '*', g.title, g.duration_months, g.legal_basis,
                        NULL, g.purge_enabled, g.is_active, NULL, NULL, g.created_at, g.created_at
                 FROM {guidance_retention_policies} g
                 WHERE NOT EXISTS (
                     SELECT 1 FROM {archive_retention_policies} a
                     WHERE a.tenant_id = g.tenant_id
                       AND a.policy_code = g.policy_code
                 )"
            );

            if ($this->db->tableExists('guidance_form_instances')) {
                $this->sql(
                    'UPDATE {guidance_form_instances} gfi
                     JOIN {guidance_retention_policies} grp
                       ON grp.tenant_id = gfi.tenant_id AND grp.id = gfi.retention_policy_id
                     JOIN {archive_retention_policies} arp
                       ON arp.tenant_id = grp.tenant_id AND arp.policy_code = grp.policy_code
                     SET gfi.retention_policy_id = arp.id
                     WHERE gfi.retention_policy_id IS NOT NULL'
                );
            }
        }

        if ($this->db->tableExists('guidance_form_instances')) {
            $this->dropFksReferencing('guidance_form_instances', 'guidance_retention_policies');

            if ($hasArchivePolicies) {
                $this->ensureUniqueKey('archive_retention_policies', 'uq_archive_retention_policies_tenant_id', ['tenant_id', 'id']);
                if (! $this->hasCompositeFkReferencing('guidance_form_instances', ['tenant_id', 'retention_policy_id'], 'archive_retention_policies', ['tenant_id', 'id'])) {
                    $this->ensureIndex('guidance_form_instances', 'fk_gfi_ret_policy', ['tenant_id', 'retention_policy_id']);
                    $fkName = $this->constraintName('fk_gfi_ret_policy');
                    if ($this->constraintExists('guidance_form_instances', $fkName)) {
                        $fkName = $this->constraintName('fk_gfi_archive_ret_policy');
                    }
                    $this->sql(
                        'ALTER TABLE {guidance_form_instances} ADD CONSTRAINT ' . $this->db->protectIdentifiers($fkName)
                        . ' FOREIGN KEY (tenant_id, retention_policy_id) REFERENCES {archive_retention_policies}(tenant_id, id)'
                        . ' ON DELETE RESTRICT ON UPDATE RESTRICT'
                    );
                }
            }
        }

        if ($hasGuidancePolicies) {
            $this->sql('DROP TABLE IF EXISTS {guidance_retention_policies}');
        }
    }

    private function dropEmptyBmgDeviceRuns(): void
    {
        if (! $this->db->tableExists('bmg_device_runs')) {
            return;
        }
        $count = (int) ($this->sql('SELECT COUNT(*) AS cnt FROM {bmg_device_runs}')->getRowArray()['cnt'] ?? 0);
        if ($count > 0) {
            throw new RuntimeException('Cannot drop legacy bmg_device_runs table while ' . $count . ' historical rows remain.');
        }
        $this->sql('DROP TABLE IF EXISTS {bmg_device_runs}');
    }

    private function dropFksReferencing(string $table, string $refTable): void
    {
        $rows = $this->sql(
            'SELECT DISTINCT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND REFERENCED_TABLE_NAME = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($refTable)]
        )->getResultArray();

        foreach ($rows as $row) {
            $name = (string) $row['CONSTRAINT_NAME'];
            $this->sql('ALTER TABLE {' . $table . '} DROP FOREIGN KEY ' . $this->db->protectIdentifiers($name));
        }
    }

    /** @param list<string> $cols @param list<string> $refCols */
    private function hasCompositeFkReferencing(string $table, array $cols, string $refTable, array $refCols): bool
    {
        $rows = $this->sql(
            'SELECT CONSTRAINT_NAME,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS cols,
                    GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION) AS ref_cols
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND REFERENCED_TABLE_NAME = ?
             GROUP BY CONSTRAINT_NAME',
            [$this->db->prefixTable($table), $this->db->prefixTable($refTable)]
        )->getResultArray();

        $expectedCols    = implode(',', $cols);
        $expectedRefCols = implode(',', $refCols);
        foreach ($rows as $row) {
            if ((string) $row['cols'] === $expectedCols && (string) $row['ref_cols'] === $expectedRefCols) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $cols */
    private function ensureUniqueKey(string $table, string $indexName, array $cols): void
    {
        $row = $this->sql(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND NON_UNIQUE = 0
             GROUP BY INDEX_NAME
             HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = ?',
            [$this->db->prefixTable($table), implode(',', $cols)]
        )->getRowArray();

        if ($row !== null) {
            return;
        }

        $colSql = implode(', ', array_map(fn (string $c): string => $this->db->protectIdentifiers($c), $cols));
        $this->sql('ALTER TABLE {' . $table . '} ADD UNIQUE KEY ' . $this->db->protectIdentifiers($indexName) . ' (' . $colSql . ')');
    }

    /** @param list<string> $cols */
    private function ensureIndex(string $table, string $indexName, array $cols): void
    {
        $row = $this->sql(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            [$this->db->prefixTable($table), $indexName]
        )->getRowArray();

        if ($row !== null) {
            return;
        }

        $colSql = implode(', ', array_map(fn (string $c): string => $this->db->protectIdentifiers($c), $cols));
        $this->sql('ALTER TABLE {' . $table . '} ADD KEY ' . $this->db->protectIdentifiers($indexName) . ' (' . $colSql . ')');
    }

    private function constraintExists(string $table, string $name): bool
    {
        return $this->sql(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?',
            [$this->db->prefixTable($table), $name]
        )->getRowArray() !== null;
    }

    private function constraintName(string $base): string
    {
        $prefix = $this->db->getPrefix();
        $name   = $prefix !== '' ? $prefix . $base : $base;

        return strlen($name) <= 64 ? $name : substr($name, 0, 64);
    }

    /** @param list<mixed> $binds */
    private function sql(string $sql, array $binds = [])
    {
        $resolved = preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            fn (array $m): string => $this->db->protectIdentifiers($this->db->prefixTable($m[1])),
            $sql
        );

        return $binds === [] ? $this->db->query($resolved) : $this->db->query($resolved, $binds);
    }
}

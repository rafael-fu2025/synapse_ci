<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Tier 2 Phase 5 3NF & BCNF structural hardening:
 * - Adds composite (tenant_id, parent_id, owner/scope) unique keys and FKs to prevent transitive drift.
 * - Converts clinic_vitals.bmi and clinic_isolation_cases.isolation_end_date to STORED generated columns.
 * - Extracts api_token_families from api_tokens / push_tokens (BCNF family lineage header).
 */
final class EnforceComposite3nfGuardsAndDerivedColumns extends Migration
{
    /** @var list<array{0: string, 1: string, 2: list<string>}> */
    private const PARENT_UNIQUE_KEYS = [
        ['counselling_cases', 'uq_ccases_tenant_id_person', ['tenant_id', 'id', 'person_id']],
        ['guidance_intake_referrals', 'uq_gir_tenant_id_subj', ['tenant_id', 'id', 'subject_person_id']],
        ['guidance_episodes', 'uq_gep_tenant_id_person', ['tenant_id', 'id', 'person_id']],
        ['guidance_mi_assignments', 'uq_gmia_tenant_id_person', ['tenant_id', 'id', 'person_id']],
        ['guidance_appointments', 'uq_gapp_tenant_id_person', ['tenant_id', 'id', 'person_id']],
        ['counselling_sessions', 'uq_csess_tenant_id_case', ['tenant_id', 'id', 'case_id']],
        ['clinic_medicine_batches', 'uq_cmb_tenant_id_med', ['tenant_id', 'id', 'medicine_id']],
        ['clinic_lab_results', 'uq_clr_tenant_id_enc', ['tenant_id', 'id', 'encounter_id']],
        ['clinic_encounters', 'uq_cenc_tenant_id_person', ['tenant_id', 'id', 'person_id']],
        ['appointments', 'uq_appt_tenant_id_person', ['tenant_id', 'id', 'person_id']],
        ['bmg_devices', 'uq_bmg_dev_tenant_id_unit', ['tenant_id', 'id', 'unit_id']],
    ];

    /** @var list<array{0: string, 1: list<string>, 2: string, 3: list<string>, 4: string}> */
    private const COMPOSITE_FKS = [
        ['guidance_episodes', ['tenant_id', 'case_id', 'person_id'], 'counselling_cases', ['tenant_id', 'id', 'person_id'], 'fk_gep_case_person'],
        ['guidance_episodes', ['tenant_id', 'intake_referral_id', 'person_id'], 'guidance_intake_referrals', ['tenant_id', 'id', 'subject_person_id'], 'fk_gep_intake_person'],
        ['guidance_appointments', ['tenant_id', 'episode_id', 'person_id'], 'guidance_episodes', ['tenant_id', 'id', 'person_id'], 'fk_gapp_episode_person'],
        ['guidance_call_slips', ['tenant_id', 'episode_id', 'person_id'], 'guidance_episodes', ['tenant_id', 'id', 'person_id'], 'fk_gcs_episode_person'],
        ['guidance_mi_submissions', ['tenant_id', 'assignment_id', 'person_id'], 'guidance_mi_assignments', ['tenant_id', 'id', 'person_id'], 'fk_gmis_assign_person'],
        ['guidance_safety_assessments', ['tenant_id', 'case_id', 'person_id'], 'counselling_cases', ['tenant_id', 'id', 'person_id'], 'fk_gsa_case_person'],
        ['guidance_safety_assessments', ['tenant_id', 'intake_referral_id', 'person_id'], 'guidance_intake_referrals', ['tenant_id', 'id', 'subject_person_id'], 'fk_gsa_intake_person'],
        ['guidance_form_instances', ['tenant_id', 'case_id', 'person_id'], 'counselling_cases', ['tenant_id', 'id', 'person_id'], 'fk_gfi_case_person'],
        ['guidance_form_instances', ['tenant_id', 'episode_id', 'person_id'], 'guidance_episodes', ['tenant_id', 'id', 'person_id'], 'fk_gfi_episode_person'],
        ['guidance_form_instances', ['tenant_id', 'session_id', 'case_id'], 'counselling_sessions', ['tenant_id', 'id', 'case_id'], 'fk_gfi_session_case'],
        ['guidance_form_instances', ['tenant_id', 'appointment_id', 'person_id'], 'guidance_appointments', ['tenant_id', 'id', 'person_id'], 'fk_gfi_appt_person'],
        ['guidance_form_instances', ['tenant_id', 'intake_id', 'person_id'], 'guidance_intake_referrals', ['tenant_id', 'id', 'subject_person_id'], 'fk_gfi_intake_person'],
        ['clinic_dispensations', ['tenant_id', 'batch_id', 'medicine_id'], 'clinic_medicine_batches', ['tenant_id', 'id', 'medicine_id'], 'fk_cdisp_batch_med'],
        ['clinic_encounter_addenda', ['tenant_id', 'lab_result_id', 'encounter_id'], 'clinic_lab_results', ['tenant_id', 'id', 'encounter_id'], 'fk_cea_lab_enc'],
        ['clinic_lab_results', ['tenant_id', 'encounter_id', 'person_id'], 'clinic_encounters', ['tenant_id', 'id', 'person_id'], 'fk_clr_enc_person'],
        ['clinic_isolation_cases', ['tenant_id', 'encounter_id', 'person_id'], 'clinic_encounters', ['tenant_id', 'id', 'person_id'], 'fk_cic_enc_person'],
        ['queue_entries', ['tenant_id', 'appointment_id', 'person_id'], 'appointments', ['tenant_id', 'id', 'person_id'], 'fk_qe_appt_person'],
        ['bmg_rotation_commands', ['tenant_id', 'device_id', 'unit_id'], 'bmg_devices', ['tenant_id', 'id', 'unit_id'], 'fk_brc_dev_unit'],
        ['bmg_rotation_runs', ['tenant_id', 'device_id', 'unit_id'], 'bmg_devices', ['tenant_id', 'id', 'unit_id'], 'fk_brr_dev_unit'],
    ];

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }

        $this->ensureParentUniqueKeys();
        $this->addComposite3nfForeignKeys();
        $this->convertDerivedColumnsToStoredGenerated();
        $this->extractApiTokenFamilies();
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        throw new RuntimeException('Tier 2 Phase 5 3NF hardening is forward-only.');
    }

    private function ensureParentUniqueKeys(): void
    {
        foreach (self::PARENT_UNIQUE_KEYS as [$table, $indexName, $cols]) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            if ($this->findUniqueKey($table, $cols) !== null) {
                continue;
            }
            $colSql = implode(', ', array_map(fn (string $c): string => $this->db->protectIdentifiers($c), $cols));
            $this->sql('ALTER TABLE {' . $table . '} ADD UNIQUE KEY ' . $this->db->protectIdentifiers($indexName) . ' (' . $colSql . ')');
        }
    }

    private function addComposite3nfForeignKeys(): void
    {
        foreach (self::COMPOSITE_FKS as [$childTable, $childCols, $parentTable, $parentCols, $fkBase]) {
            if (! $this->db->tableExists($childTable) || ! $this->db->tableExists($parentTable)) {
                continue;
            }
            if ($this->hasCompositeFkReferencing($childTable, $childCols, $parentTable, $parentCols)) {
                continue;
            }

            $join   = [];
            $notNull = [];
            foreach ($childCols as $i => $cCol) {
                $pCol      = $parentCols[$i];
                $join[]    = 'c.' . $this->db->protectIdentifiers($cCol) . ' = p.' . $this->db->protectIdentifiers($pCol);
                $notNull[] = 'c.' . $this->db->protectIdentifiers($cCol) . ' IS NOT NULL';
            }
            $pFirst = $this->db->protectIdentifiers($parentCols[0]);
            $mismatches = (int) ($this->sql(
                'SELECT COUNT(*) AS cnt FROM {' . $childTable . '} c LEFT JOIN {' . $parentTable . '} p ON '
                . implode(' AND ', $join) . ' WHERE ' . implode(' AND ', $notNull) . ' AND p.' . $pFirst . ' IS NULL'
            )->getRowArray()['cnt'] ?? 0);

            if ($mismatches > 0) {
                throw new RuntimeException(sprintf(
                    'Cannot enforce composite 3NF FK %s (%s -> %s): %d mismatched rows exist.',
                    $fkBase,
                    $childTable,
                    $parentTable,
                    $mismatches
                ));
            }

            $idxName = $this->constraintName($fkBase);
            $this->ensureIndex($childTable, $idxName, $childCols);
            $fkName = $this->constraintExists($childTable, $idxName)
                ? $this->constraintName($fkBase . '_fk')
                : $idxName;

            $childSql  = implode(', ', array_map(fn (string $c): string => $this->db->protectIdentifiers($c), $childCols));
            $parentSql = implode(', ', array_map(fn (string $c): string => $this->db->protectIdentifiers($c), $parentCols));

            $this->sql(
                'ALTER TABLE {' . $childTable . '} ADD CONSTRAINT ' . $this->db->protectIdentifiers($fkName)
                . ' FOREIGN KEY (' . $childSql . ') REFERENCES {' . $parentTable . '}(' . $parentSql . ')'
                . ' ON DELETE RESTRICT ON UPDATE RESTRICT'
            );
        }
    }

    private function convertDerivedColumnsToStoredGenerated(): void
    {
        if ($this->db->tableExists('clinic_vitals')) {
            $meta = $this->columnMeta('clinic_vitals', 'bmi');
            if ($meta !== null && stripos((string) ($meta['EXTRA'] ?? ''), 'STORED GENERATED') === false) {
                $this->sql(
                    'ALTER TABLE {clinic_vitals} MODIFY COLUMN ' . $this->db->protectIdentifiers('bmi')
                    . ' DECIMAL(5,2) GENERATED ALWAYS AS ('
                    . 'CASE WHEN weight_kg IS NOT NULL AND height_cm IS NOT NULL AND height_cm > 0 '
                    . 'THEN ROUND(weight_kg / POW(height_cm / 100.0, 2), 2) ELSE NULL END'
                    . ') STORED'
                );
            }
        }

        if ($this->db->tableExists('clinic_isolation_cases')) {
            $meta = $this->columnMeta('clinic_isolation_cases', 'isolation_end_date');
            if ($meta !== null && stripos((string) ($meta['EXTRA'] ?? ''), 'STORED GENERATED') === false) {
                $hasIdx = $this->hasIndexName('clinic_isolation_cases', 'idx_clinic_iso_dates');
                if ($hasIdx) {
                    $this->sql('ALTER TABLE {clinic_isolation_cases} DROP INDEX ' . $this->db->protectIdentifiers('idx_clinic_iso_dates'));
                }
                $this->sql(
                    'ALTER TABLE {clinic_isolation_cases} MODIFY COLUMN ' . $this->db->protectIdentifiers('isolation_end_date')
                    . ' DATE GENERATED ALWAYS AS ('
                    . 'DATE_ADD(isolation_start_date, INTERVAL (CAST(isolation_days AS SIGNED) - 1) DAY)'
                    . ') STORED'
                );
                if ($hasIdx && ! $this->hasIndexName('clinic_isolation_cases', 'idx_clinic_iso_dates')) {
                    $this->sql(
                        'ALTER TABLE {clinic_isolation_cases} ADD KEY ' . $this->db->protectIdentifiers('idx_clinic_iso_dates')
                        . ' (tenant_id, isolation_start_date, isolation_end_date)'
                    );
                }
            }
        }
    }

    private function extractApiTokenFamilies(): void
    {
        if (! $this->db->tableExists('api_tokens')) {
            return;
        }

        if (! $this->db->tableExists('api_token_families')) {
            $this->sql(
                'CREATE TABLE {api_token_families} (
                    tenant_id INT(10) UNSIGNED NOT NULL,
                    family_id CHAR(32) NOT NULL,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    device_label VARCHAR(120) DEFAULT NULL,
                    created_ip VARCHAR(45) DEFAULT NULL,
                    revoked_at DATETIME DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME DEFAULT NULL,
                    PRIMARY KEY (tenant_id, family_id),
                    KEY idx_atf_user (tenant_id, user_id, revoked_at),
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_atf_tenant')) . ' FOREIGN KEY (tenant_id) REFERENCES {tenants}(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_atf_user')) . ' FOREIGN KEY (tenant_id, user_id) REFERENCES {users}(tenant_id, id) ON DELETE CASCADE ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $this->sql(
            'INSERT IGNORE INTO {api_token_families}
             (tenant_id, family_id, user_id, device_label, created_ip, revoked_at, created_at, updated_at)
             SELECT a.tenant_id,
                    a.family_id,
                    MIN(a.user_id) AS user_id,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.device_label ORDER BY a.id ASC SEPARATOR \'\\n\'), \'\\n\', 1) AS device_label,
                    SUBSTRING_INDEX(GROUP_CONCAT(a.created_ip ORDER BY a.id ASC SEPARATOR \'\\n\'), \'\\n\', 1) AS created_ip,
                    CASE WHEN SUM(CASE WHEN a.revoked_at IS NULL THEN 1 ELSE 0 END) = 0 THEN MAX(a.revoked_at) ELSE NULL END AS revoked_at,
                    MIN(a.created_at) AS created_at,
                    MAX(COALESCE(a.updated_at, a.created_at)) AS updated_at
             FROM {api_tokens} a
             GROUP BY a.tenant_id, a.family_id'
        );

        if (! $this->hasCompositeFkReferencing('api_tokens', ['tenant_id', 'family_id'], 'api_token_families', ['tenant_id', 'family_id'])) {
            $this->ensureIndex('api_tokens', 'idx_api_tokens_tenant_family', ['tenant_id', 'family_id']);
            $this->sql(
                'ALTER TABLE {api_tokens} ADD CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_api_tokens_family'))
                . ' FOREIGN KEY (tenant_id, family_id) REFERENCES {api_token_families}(tenant_id, family_id)'
                . ' ON DELETE RESTRICT ON UPDATE RESTRICT'
            );
        }

        if ($this->db->tableExists('push_tokens')
            && ! $this->hasCompositeFkReferencing('push_tokens', ['tenant_id', 'api_family_id'], 'api_token_families', ['tenant_id', 'family_id'])) {
            $orphans = (int) ($this->sql(
                'SELECT COUNT(*) AS cnt FROM {push_tokens} p
                 LEFT JOIN {api_token_families} f ON f.tenant_id = p.tenant_id AND f.family_id = p.api_family_id
                 WHERE p.api_family_id IS NOT NULL AND f.family_id IS NULL'
            )->getRowArray()['cnt'] ?? 0);
            if ($orphans > 0) {
                $this->sql(
                    'UPDATE {push_tokens} p
                     LEFT JOIN {api_token_families} f ON f.tenant_id = p.tenant_id AND f.family_id = p.api_family_id
                     SET p.api_family_id = NULL
                     WHERE p.api_family_id IS NOT NULL AND f.family_id IS NULL'
                );
            }
            $this->ensureIndex('push_tokens', 'idx_push_tokens_tenant_family', ['tenant_id', 'api_family_id']);
            $this->sql(
                'ALTER TABLE {push_tokens} ADD CONSTRAINT ' . $this->db->protectIdentifiers($this->constraintName('fk_push_tokens_family'))
                . ' FOREIGN KEY (tenant_id, api_family_id) REFERENCES {api_token_families}(tenant_id, family_id)'
                . ' ON DELETE RESTRICT ON UPDATE RESTRICT'
            );
        }
    }

    /** @param list<string> $cols */
    private function findUniqueKey(string $table, array $cols): ?string
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

        return $row === null ? null : (string) $row['INDEX_NAME'];
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
    private function ensureIndex(string $table, string $indexName, array $cols): void
    {
        if ($this->hasIndexName($table, $indexName)) {
            return;
        }
        $colSql = implode(', ', array_map(fn (string $c): string => $this->db->protectIdentifiers($c), $cols));
        $this->sql('ALTER TABLE {' . $table . '} ADD KEY ' . $this->db->protectIdentifiers($indexName) . ' (' . $colSql . ')');
    }

    private function hasIndexName(string $table, string $indexName): bool
    {
        return $this->sql(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1',
            [$this->db->prefixTable($table), $indexName]
        )->getRowArray() !== null;
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

    /** @return array<string, mixed>|null */
    private function columnMeta(string $table, string $column): ?array
    {
        return $this->sql(
            'SELECT COLUMN_TYPE, IS_NULLABLE, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column]
        )->getRowArray();
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

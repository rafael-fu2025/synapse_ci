<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Run with scheduling writes stopped: MariaDB/MySQL DDL commits implicitly. */
final class AddDepartmentStaffAssignments extends Migration
{
    private function sql(string $sql, array $bindings = []): mixed
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($m) => $this->db->protectIdentifiers($this->db->prefixTable($m[1])), $sql);
        $result = $this->db->query($sql, $bindings);
        if ($result === false) {
            throw new RuntimeException('Department staff migration failed: ' . $this->db->error()['message']);
        }
        return $result;
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)])->getRowArray() !== null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        // Do not use CI's cached field list across auto-committing DDL/reruns.
        return $this->db->query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column])->getRowArray() !== null;
    }

    private function hasIndex(string $table, string $name): bool
    {
        return $this->db->query('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->prefixTable($table), $name])->getRowArray() !== null;
    }

    private function addConstraint(string $table, string $name, string $definition): void
    {
        $name = $this->db->prefixTable($name);
        $exists = $this->db->query('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$this->db->prefixTable($table), $name])->getRowArray();
        if ($exists === null) {
            $this->sql('ALTER TABLE {' . $table . '} ADD CONSTRAINT ' . $this->db->protectIdentifiers($name) . ' ' . $definition);
        }
    }

    /** Build the complete identity/unit plan before the first nontransactional DDL. */
    private function preflight(): array
    {
        $providers = [];
        foreach (['appointments', 'provider_availability', 'availability_exceptions'] as $table) {
            foreach ($this->db->table($table)->where('provider_user_id IS NOT NULL', null, false)->get()->getResultArray() as $row) {
                $key = $row['tenant_id'] . ':' . $row['provider_user_id'];
                $providers[$key] ??= ['tenant_id' => $row['tenant_id'], 'user_id' => $row['provider_user_id'], 'units' => []];
                if ($table !== 'availability_exceptions') {
                    if (!in_array($row['unit'], ['clinic', 'guidance'], true)) {
                        throw new RuntimeException("Department staff preflight STOP: invalid unit in {$table} id {$row['id']}; repair before rerunning.");
                    }
                    $providers[$key]['units'][$row['unit']] = true;
                }
            }
        }

        foreach ($providers as &$provider) {
            $user = $this->db->table('users')->where('id', $provider['user_id'])->get()->getRowArray();
            $person = $user === null || $user['person_id'] === null ? null
                : $this->db->table('people')->where('id', $user['person_id'])->get()->getRowArray();
            if ($user === null || (int) $user['tenant_id'] !== (int) $provider['tenant_id']
                || $person === null || (int) $person['tenant_id'] !== (int) $provider['tenant_id'] || $person['kind'] !== 'employee') {
                throw new RuntimeException("Department staff preflight STOP: tenant {$provider['tenant_id']} provider user {$provider['user_id']} needs an existing same-tenant employee person link. Repair the identity/reference explicitly; no users, roles or people were changed.");
            }
            $provider['person_id'] = $person['id'];
            $provider['status'] = $user['deleted_at'] !== null || $person['deleted_at'] !== null || $user['status'] !== 'active' ? 'inactive' : 'active';
            if ($this->hasTable('department_staff')) {
                foreach ($this->db->table('department_staff')->where('tenant_id', $provider['tenant_id'])->where('person_id', $person['id'])->get()->getResultArray() as $assignment) {
                    $provider['units'][$assignment['unit']] = true;
                }
            }
            if ($provider['units'] === []) {
                $unit = match ($user['role']) {
                    'clinic_staff' => 'clinic',
                    'counsellor' => 'guidance',
                    default => null,
                };
                if ($unit === null) {
                    throw new RuntimeException("Department staff preflight STOP: exception-only tenant {$provider['tenant_id']} provider user {$provider['user_id']} has ambiguous role '{$user['role']}'. Establish an explicit clinic/guidance assignment before rerunning; no DDL performed.");
                }
                $provider['units'][$unit] = true;
            }
            ksort($provider['units']);
        }
        unset($provider);

        // Also validate partially migrated and new userless rows before resuming DDL.
        if ($this->hasTable('department_staff')) {
            $bad = $this->sql("SELECT s.id FROM {department_staff} s LEFT JOIN {people} p ON p.id=s.person_id AND p.tenant_id=s.tenant_id WHERE p.id IS NULL OR p.kind <> 'employee' LIMIT 1")->getRowArray();
            if ($bad !== null) {
                throw new RuntimeException('Department staff preflight STOP: invalid employee identity for assignment ' . $bad['id']);
            }
            foreach (['appointments', 'provider_availability', 'availability_exceptions'] as $table) {
                if (!$this->hasColumn($table, 'staff_assignment_id')) {
                    continue;
                }
                $unit = $table === 'availability_exceptions' ? '' : ' AND s.unit=r.unit';
                $bad = $this->sql('SELECT r.id FROM {' . $table . '} r LEFT JOIN {department_staff} s ON s.id=r.staff_assignment_id AND s.tenant_id=r.tenant_id' . $unit
                    . ' LEFT JOIN {users} u ON u.id=r.provider_user_id AND u.tenant_id=r.tenant_id WHERE r.staff_assignment_id IS NOT NULL AND (s.id IS NULL OR (r.provider_user_id IS NOT NULL AND (u.id IS NULL OR u.person_id IS NULL OR u.person_id<>s.person_id))) LIMIT 1')->getRowArray();
                if ($bad !== null) {
                    throw new RuntimeException("Department staff preflight STOP: inconsistent {$table} assignment at id {$bad['id']}; repair before rerunning.");
                }
            }
        }
        if ($this->hasColumn('availability_exceptions', 'staff_assignment_id')) {
            $duplicate = $this->sql('SELECT MIN(id) AS id FROM {availability_exceptions} WHERE staff_assignment_id IS NOT NULL GROUP BY tenant_id,staff_assignment_id,exception_date HAVING COUNT(*) > 1 LIMIT 1')->getRowArray();
            if ($duplicate !== null) {
                throw new RuntimeException('Department staff preflight STOP: duplicate assignment/date exceptions at id ' . $duplicate['id'] . '; reconcile explicitly before rerunning.');
            }
        }
        return $providers;
    }

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('Department staff migration requires MySQL/MariaDB with InnoDB.');
        }
        foreach (['tenants', 'people', 'users', 'appointments', 'provider_availability', 'availability_exceptions', 'department_staff'] as $table) {
            if ($table === 'department_staff' && !$this->hasTable($table)) {
                continue;
            }
            $engine = $this->db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$this->db->prefixTable($table)])->getRowArray();
            if (strtoupper($engine['ENGINE'] ?? '') !== 'INNODB') {
                throw new RuntimeException('Department staff preflight STOP: InnoDB required for ' . $table . '; no DDL performed.');
            }
        }
        $providers = $this->preflight();

        if (!$this->hasIndex('people', 'ds_person_tenant')) {
            $this->sql('ALTER TABLE {people} ADD UNIQUE KEY ds_person_tenant (tenant_id,id)');
        }
        if (!$this->hasIndex('users', 'ds_user_tenant')) {
            $this->sql('ALTER TABLE {users} ADD UNIQUE KEY ds_user_tenant (tenant_id,id)');
        }
        $this->sql("CREATE TABLE IF NOT EXISTS {department_staff} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            unit ENUM('clinic','guidance') NOT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            enrolled_by_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_department_staff_person (tenant_id,person_id,unit),
            UNIQUE KEY ds_tenant_unit_id (tenant_id,unit,id),
            UNIQUE KEY ds_tenant_id (tenant_id,id),
            FOREIGN KEY (tenant_id) REFERENCES {tenants}(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            FOREIGN KEY (tenant_id,person_id) REFERENCES {people}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            FOREIGN KEY (tenant_id,enrolled_by_user_id) REFERENCES {users}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB");
        foreach (['appointments', 'provider_availability', 'availability_exceptions'] as $table) {
            if (!$this->hasColumn($table, 'staff_assignment_id')) {
                $this->sql('ALTER TABLE {' . $table . '} ADD staff_assignment_id BIGINT UNSIGNED NULL');
            }
            $this->sql('ALTER TABLE {' . $table . '} MODIFY provider_user_id BIGINT UNSIGNED NULL');
        }
        // The old unique key supports the tenant FK as well as legacy lookup.
        // Both single-column FKs need surviving leading indexes before it is dropped.
        if (!$this->hasIndex('availability_exceptions', 'ds_exception_tenant')) {
            $this->sql('ALTER TABLE {availability_exceptions} ADD KEY ds_exception_tenant (tenant_id)');
        }
        if (!$this->hasIndex('availability_exceptions', 'ds_exception_legacy_provider')) {
            $this->sql('ALTER TABLE {availability_exceptions} ADD KEY ds_exception_legacy_provider (provider_user_id)');
        }
        if ($this->hasIndex('availability_exceptions', 'uniq_provider_exception_date')) {
            $this->sql('ALTER TABLE {availability_exceptions} DROP INDEX uniq_provider_exception_date');
        }
        if (!$this->hasIndex('availability_exceptions', 'uniq_staff_exception_date')) {
            $this->sql('ALTER TABLE {availability_exceptions} ADD UNIQUE KEY uniq_staff_exception_date (tenant_id,staff_assignment_id,exception_date)');
        }

        if (!$this->db->transBegin()) {
            throw new RuntimeException('Department staff backfill could not start its transaction.');
        }
        try {
            $now = date('Y-m-d H:i:s');
            foreach ($providers as $provider) {
                $assignmentIds = [];
                foreach (array_keys($provider['units']) as $unit) {
                    $assignment = $this->db->table('department_staff')->where('tenant_id', $provider['tenant_id'])
                        ->where('person_id', $provider['person_id'])->where('unit', $unit)->get()->getRowArray();
                    if ($assignment === null) {
                        $this->sql('INSERT INTO {department_staff} (tenant_id,person_id,unit,status,created_at,updated_at) VALUES (?,?,?,?,?,?)',
                            [$provider['tenant_id'], $provider['person_id'], $unit, $provider['status'], $now, $now]);
                        $assignmentIds[] = (int) $this->db->insertID();
                    } else {
                        $assignmentIds[] = (int) $assignment['id'];
                        if ($provider['status'] === 'inactive' && $assignment['status'] !== 'inactive') {
                            $this->sql("UPDATE {department_staff} SET status='inactive' WHERE id=?", [$assignment['id']]);
                        }
                    }
                    $id = $assignmentIds[count($assignmentIds) - 1];
                    foreach (['appointments', 'provider_availability'] as $table) {
                        $this->sql('UPDATE {' . $table . '} SET staff_assignment_id=? WHERE tenant_id=? AND provider_user_id=? AND unit=? AND staff_assignment_id IS NULL',
                            [$id, $provider['tenant_id'], $provider['user_id'], $unit]);
                    }
                }
                $this->backfillExceptions($provider, $assignmentIds);
            }
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Department staff backfill transaction failed; rerun after resolving the database error.');
            }
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        foreach (['appointments', 'provider_availability'] as $table) {
            $this->addConstraint($table, 'ds_' . $table . '_assignment',
                'FOREIGN KEY (tenant_id,unit,staff_assignment_id) REFERENCES {department_staff}(tenant_id,unit,id) ON DELETE RESTRICT ON UPDATE RESTRICT');
        }
        $this->addConstraint('availability_exceptions', 'ds_exception_assignment',
            'FOREIGN KEY (tenant_id,staff_assignment_id) REFERENCES {department_staff}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT');
    }

    private function backfillExceptions(array $provider, array $assignmentIds): void
    {
        $rows = $this->db->table('availability_exceptions')->where('tenant_id', $provider['tenant_id'])
            ->where('provider_user_id', $provider['user_id'])->orderBy('id', 'ASC')->get()->getResultArray();
        $dates = [];
        foreach ($rows as $row) {
            $dates[$row['exception_date']][] = $row;
        }
        foreach ($dates as $rowsForDate) {
            $original = $rowsForDate[0];
            $mapped = [];
            $unmapped = [];
            foreach ($rowsForDate as $row) {
                if ($row['staff_assignment_id'] === null) {
                    $unmapped[] = $row;
                } else {
                    $mapped[(int) $row['staff_assignment_id']] = true;
                }
            }
            foreach ($assignmentIds as $assignmentId) {
                if (isset($mapped[$assignmentId])) {
                    continue;
                }
                if ($unmapped !== []) {
                    $row = array_shift($unmapped);
                    $this->sql('UPDATE {availability_exceptions} SET staff_assignment_id=? WHERE id=?', [$assignmentId, $row['id']]);
                } else {
                    // Copy every original field except the PK; never regenerate historical timestamps.
                    $clone = $original;
                    unset($clone['id']);
                    $clone['staff_assignment_id'] = $assignmentId;
                    if (!$this->db->table('availability_exceptions')->insert($clone)) {
                        throw new RuntimeException('Could not preserve exception ' . $original['id'] . ' for assignment ' . $assignmentId);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Department staff assignments are forward-only: rollback would lose userless schedules and multi-unit exception history. Restore a verified pre-migration backup instead.');
    }
}

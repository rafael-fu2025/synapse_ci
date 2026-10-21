<?php

declare(strict_types=1);

// Operator must stop ALL writers and rehearse this backup's restore on an isolated server first.
// Usage: php writable/staff-assignment-development-migrate.php --apply --backup=...json --restore-rehearsed --writes-stopped
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['apply', 'verify-only', 'evidence:', 'backup:', 'restore-rehearsed', 'writes-stopped']);
$verifyOnly = isset($options['verify-only']);
if ((!isset($options['apply']) && !$verifyOnly) || (isset($options['apply']) && $verifyOnly)
    || ($verifyOnly && !is_string($options['evidence'] ?? null))
    || !isset($options['backup'], $options['restore-rehearsed'], $options['writes-stopped'])
    || !is_string($options['backup'])) {
    fwrite(STDERR, "Required: --apply --backup=<verified JSON under writable/backups> --restore-rehearsed --writes-stopped\n");
    exit(1);
}

$stage = 'backup verification';
$db = null;
$locked = false;
$flag = __DIR__ . '/scheduling-maintenance.flag';
$lockName = 'humus_db:staff-assignment-deployment';
try {
    $root = realpath(__DIR__ . '/backups');
    $inside = static function (string $path) use ($root): string {
        $real = realpath($path);
        if ($root === false || $real === false || !is_file($real)
            || !str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $root) . '/')) {
            throw new RuntimeException('Backup path outside restricted directory.');
        }
        return $real;
    };
    $metadataPath = $inside($options['backup']);
    if (strtolower(pathinfo($metadataPath, PATHINFO_EXTENSION)) !== 'json') {
        throw new RuntimeException('Backup metadata must be JSON.');
    }
    $backup = json_decode(file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
    if (($backup['database'] ?? null) !== 'humus_db' || ($backup['native_dump_completed'] ?? null) !== true
        || !is_int($backup['bytes'] ?? null) || $backup['bytes'] <= 0
        || !is_string($backup['sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $backup['sha256'])
        || !is_string($backup['path'] ?? null)) {
        throw new RuntimeException('Incomplete backup metadata.');
    }
    $dump = $inside($backup['path']);
    if (!preg_match('/\Ahumus_db_before_staff_assignments_.+\.sql\z/D', basename($dump))
        || substr($metadataPath, 0, -5) !== substr($dump, 0, -4)
        || filesize($dump) !== $backup['bytes'] || !hash_equals($backup['sha256'], hash_file('sha256', $dump))) {
        throw new RuntimeException('Backup identity, bytes or digest mismatch.');
    }
    $handle = fopen($dump, 'rb');
    if ($handle === false) { throw new RuntimeException('Backup unreadable.'); }
    try {
        if (fseek($handle, max(0, $backup['bytes'] - 2048)) !== 0
            || !str_contains(stream_get_contents($handle), '-- Dump completed on')) {
            throw new RuntimeException('Native dump completion marker missing.');
        }
    } finally { fclose($handle); }

    $stage = 'development connection guard';
    define('ENVIRONMENT', 'development');
    define('FCPATH', dirname(__DIR__) . '/public/');
    require dirname(__DIR__) . '/app/Config/Paths.php';
    $paths = new \Config\Paths();
    require $paths->systemDirectory . '/Boot.php';
    \CodeIgniter\Boot::bootConsole($paths);
    $config = config('Database');
    $c = $config->default;
    $port = (int) ($c['port'] ?? 0);
    $connectionGuards = [
        'development_environment' => env('CI_ENVIRONMENT') === 'development',
        'default_group' => $config->defaultGroup === 'default',
        'expected_database' => ($c['database'] ?? '') === 'humus_db',
        'mysql_driver' => ($c['DBDriver'] ?? '') === 'MySQLi',
        'loopback_host' => in_array($c['hostname'] ?? '', ['localhost', '127.0.0.1', '::1'], true),
        'valid_main_port' => $port > 0 && $port <= 65535 && $port !== 33307,
        'no_dsn_or_failover' => empty($c['DSN']) && empty($c['failover']),
        'no_prefix_or_persistent_connection' => empty($c['DBPrefix']) && empty($c['pConnect']),
    ];
    if (in_array(false, $connectionGuards, true)) {
        throw new RuntimeException('Expected explicit local development default connection.');
    }
    $config->default['DBDebug'] = false;
    $db = \Config\Database::connect('default');
    $query = static function (string $sql, array $bindings = []) use ($db) {
        $result = $db->query($sql, $bindings);
        if ($result === false) { throw new RuntimeException('Database operation failed.'); }
        return $result;
    };
    $identity = $query('SELECT DATABASE() AS name, @@port AS port')->getRowArray();
    if ($identity['name'] !== 'humus_db' || (int) $identity['port'] !== $port || (int) $identity['port'] === 33307) {
        throw new RuntimeException('Actual database/server port mismatch.');
    }
    $query('SET SESSION lock_wait_timeout = 5');
    $query('SET SESSION innodb_lock_wait_timeout = 5');
    $stage = 'exclusive deployment lock';
    if ((int) $query('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])->getRow()->acquired !== 1) {
        throw new RuntimeException('Another deployment holds the lock.');
    }
    $locked = true;
    // Never adopt/remove a stale flag automatically, even when history says the migration ran.
    if ($verifyOnly && !is_file($flag)) { throw new RuntimeException('Verification requires the retained maintenance flag.'); }
    $handle = $verifyOnly ? null : @fopen($flag, 'x');
    if (!$verifyOnly && $handle === false) { throw new RuntimeException('Maintenance flag exists or cannot be created; manual review required.'); }

    if (!$verifyOnly) try {
        $message = "Scheduling maintenance. If deployment failed, this flag is intentionally retained. Review snapshots and migration state before manually clearing; never auto-rollback.\n";
        if (fwrite($handle, $message) !== strlen($message) || !fflush($handle)) {
            throw new RuntimeException('Maintenance flag write failed.');
        }
    } finally { fclose($handle); }

    $stage = 'active transaction preflight';
    $assertIdle = static function () use ($query): void {
        // PROCESS visibility is required: otherwise an empty result cannot prove quiescence.
        $grants = $query('SHOW GRANTS FOR CURRENT_USER()')->getResultArray();
        $process = false;
        foreach ($grants as $grant) {
            if (preg_match('/GRANT (?:ALL PRIVILEGES|[^\n]*\bPROCESS\b[^\n]*) ON \*\.\* TO /i', implode(' ', $grant))) { $process = true; }
        }
        if (!$process) { throw new RuntimeException('Global PROCESS visibility required.'); }
        // Conservatively reject every other InnoDB transaction, including cross-database/idle ones.
        $n = (int) $query('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id <> CONNECTION_ID()')->getRow()->n;
        if ($n !== 0) { throw new RuntimeException('Other InnoDB transactions are active; refusing to wait.'); }
    };
    $assertIdle();
    $migrationConfig = clone config('Migrations');
    // Our session advisory lock replaces CI's optional lock-table DDL.
    if (property_exists($migrationConfig, 'lock')) { $migrationConfig->lock = false; }
    $historyTable = $migrationConfig->table;
    if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $historyTable) || !$db->tableExists($historyTable)) {
        throw new RuntimeException('Existing migration history required.');
    }
    $class = 'App\\Database\\Migrations\\AddDepartmentStaffAssignments';
    $targetRecorded = false;
    foreach ($db->table($historyTable)->get()->getResultArray() as $row) {
        if (preg_replace('/[^0-9]/', '', $row['version']) === '20261016000001' || $row['class'] === $class) {
            $targetRecorded = true;
            if (!$verifyOnly) { throw new RuntimeException('Target migration already recorded; manual review required.'); }
        }
    }

    if ($verifyOnly && !$targetRecorded) { throw new RuntimeException('Target migration must be recorded before verification.'); }
    $stage = 'original row fingerprints';
    $columns = [];
    foreach (array_unique(['appointments', 'provider_availability', 'availability_exceptions', 'people', 'users',
        'employee_profiles', 'student_profiles', 'role_permissions', 'audit_events', 'audit_logs', $historyTable]) as $table) {
        if (!$db->tableExists($table)) { continue; }
        $fields = array_column($query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table])->getResultArray(), 'COLUMN_NAME');
        // Only the newly introduced scheduling reference may change on original rows.
        if (in_array($table, ['appointments', 'provider_availability', 'availability_exceptions'], true)) {
            $fields = array_values(array_diff($fields, ['staff_assignment_id']));
        }
        if (!in_array('id', $fields, true) && $table !== 'role_permissions') {
            throw new RuntimeException('Fingerprint table requires original ID.');
        }
        $columns[$table] = $fields;
    }
    $snapshot = static function () use ($columns, $db): array {
        $out = [];
        foreach ($columns as $table => $fields) {
            $out[$table] = ['columns' => $fields, 'rows' => [], 'clone_payloads' => []];
            $offset = 0;
            do {
                if ($offset >= 200000) { throw new RuntimeException('Snapshot safety bound exceeded; use a reviewed larger-table procedure.'); }
                $builder = $db->table($table)->select($fields);
                foreach (in_array('id', $fields, true) ? ['id'] : ['role', 'permission_id'] as $key) {
                    $builder->orderBy($key);
                }
                $result = $builder->get(500, $offset);
                if ($result === false) { throw new RuntimeException('Fingerprint read failed.'); }
                $rows = $result->getResultArray();
                foreach ($rows as $row) {
                    $last = $row['id'] ?? hash('sha256', serialize([$row['role'], $row['permission_id']]));
                    $out[$table]['rows'][$last] = hash('sha256', serialize($row));
                    if ($table === 'availability_exceptions') {
                        unset($row['id']);
                        $out[$table]['clone_payloads'][$last] = hash('sha256', serialize($row));
                    }
                }
                $offset += count($rows);
            } while (count($rows) === 500);
        }
        return $out;
    };
    if ($verifyOnly) {
        $snapshotPath = $inside($options['evidence']);
        if (!preg_match('/\Astaff_assignment_fingerprints_[a-zA-Z0-9_]+\.json\z/D', basename($snapshotPath))) {
            throw new RuntimeException('Invalid fingerprint evidence path.');
        }
        $evidence = json_decode(file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
        $before = $evidence['before'] ?? null;
        if (!is_array($before) || array_keys($before) !== array_keys($columns)) { throw new RuntimeException('Original evidence table set mismatch.'); }
        foreach ($before as $table => $original) {
            if (($original['columns'] ?? null) !== $columns[$table] || !is_array($original['rows'] ?? null)) {
                throw new RuntimeException('Original evidence schema mismatch.');
            }
        }
    } else {
        $before = $snapshot();
        $snapshotPath = __DIR__ . '/backups/staff_assignment_fingerprints_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
    }
    $save = static function (array $data) use ($snapshotPath): void {
        if (file_put_contents($snapshotPath, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Fingerprint evidence write failed.');
        }
    };
    if (!$verifyOnly) { $save(['status' => 'before', 'backup_sha256' => $backup['sha256'], 'before' => $before]); }
    $assertIdle();

    $stage = 'exact migration apply (maintenance retained on any failure)';
    // Do not use force(): it can run down(). Block latest()'s automatic regress path too.
    $runner = new class($migrationConfig, $db) extends \CodeIgniter\Database\MigrationRunner {
        public function findMigrations(): array
        {
            $migration = $this->migrationFromFile(APPPATH . 'Database/Migrations/2026-10-16-000001_AddDepartmentStaffAssignments.php', 'App');
            if (!$migration || $migration->version !== '2026-10-16-000001'
                || $migration->class !== 'App\\Database\\Migrations\\AddDepartmentStaffAssignments') {
                throw new RuntimeException('Exact migration identity mismatch.');
            }
            return [$migration->uid => $migration];
        }
        public function regress(int $targetBatch = 0, ?string $group = null)
        {
            throw new RuntimeException('Automatic rollback is prohibited.');
        }
    };
    if (!$verifyOnly && !$runner->latest('default')) { throw new RuntimeException('Migration did not complete.'); }
    // SQL DDL bypasses CI Forge cache invalidation; never verify against pre-DDL table names.
    $db->resetDataCache();

    $stage = 'post-DDL preservation verification';
    $after = $snapshot();
    $save(['status' => 'after_unverified', 'before' => $before, 'after' => $after]);
    $counts = [];
    foreach ($before as $table => $original) {
        $current = $after[$table];
        foreach ($original['rows'] as $id => $hash) {
            if (($current['rows'][$id] ?? null) !== $hash) { throw new RuntimeException('Original row fingerprint changed.'); }
        }
        $extra = array_diff_key($current['rows'], $original['rows']);
        if ($table === 'availability_exceptions') {
            $payloads = array_fill_keys(array_values($original['clone_payloads']), true);
            foreach ($extra as $id => $hash) {
                if (!isset($payloads[$current['clone_payloads'][$id]])) { throw new RuntimeException('Non-clone exception inserted.'); }
            }
        } elseif ($table === $historyTable) {
            if (count($extra) !== 1) { throw new RuntimeException('Expected exactly one new history record.'); }
            $record = $db->table($historyTable)->where('id', array_key_first($extra))->get()->getRowArray();
            if ($record['version'] !== '2026-10-16-000001' || $record['class'] !== $class
                || $record['namespace'] !== 'App' || $record['group'] !== 'default') {
                throw new RuntimeException('Wrong migration recorded.');
            }
        } elseif ($extra !== []) { throw new RuntimeException('Unexpected original-table insertions.'); }
        $counts[$table] = ['before' => count($original['rows']), 'after' => count($current['rows']), 'added' => count($extra)];
    }
    foreach (['appointments', 'provider_availability', 'availability_exceptions'] as $table) {
        if ($query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='staff_assignment_id'", [$table])->getRowArray() === null) {
            throw new RuntimeException('Assignment column missing.');
        }
        if ((int) $query('SELECT COUNT(*) AS n FROM `' . $table . '` WHERE provider_user_id IS NOT NULL AND staff_assignment_id IS NULL')->getRow()->n !== 0) {
            throw new RuntimeException('Legacy provider backfill incomplete.');
        }
    }
    if (!$db->tableExists('department_staff')) { throw new RuntimeException('Assignment table missing.'); }
    $save(['status' => 'verified', 'before' => $before, 'after' => $after]);
    if (!unlink($flag)) { throw new RuntimeException('Verified, but maintenance flag removal failed.'); }

    echo json_encode(['status' => 'verified', 'counts' => $counts, 'maintenance' => false,
        'restore_rehearsal' => 'operator_attested', 'automatic_rollback' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    // Best-effort read-only evidence for partial DDL/backfill failures; never repair or roll back here.
    if (isset($before, $snapshot, $save)) {
        try { $save(['status' => 'failed_unverified', 'before' => $before, 'after' => $snapshot()]); }
        catch (Throwable) { /* Keep the already-written before evidence and maintenance flag. */ }
    }
    // Database exceptions and migration preflight messages can contain personal data or SQL values.
    fwrite(STDERR, json_encode(['status' => 'failed', 'stage' => $stage, 'exception_type' => get_class($e), 'runtime_error' => $e instanceof \Error ? $e->getMessage() : null,
            'failed_connection_guards' => isset($connectionGuards) ? array_keys(array_filter($connectionGuards, static fn (bool $passed): bool => !$passed)) : [],
            'maintenance_retained' => is_file($flag),
        'action' => 'Stop writers. Review local fingerprint evidence and migration state; repair explicitly. No automatic rollback. Clear a retained scheduling-maintenance.flag manually only after verification.'], JSON_THROW_ON_ERROR) . "\n");

    $failed = true;
} finally {
    if ($locked && $db !== null) {
        try { $db->query('SELECT RELEASE_LOCK(?)', [$lockName]); } catch (Throwable) { /* Closing releases the session lock. */ }
    }
    if ($db !== null) { $db->close(); }
}
if (isset($failed)) { exit(1); }

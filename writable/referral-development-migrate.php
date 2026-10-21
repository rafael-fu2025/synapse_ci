<?php

declare(strict_types=1);

// Operator must stop ALL writers and rehearse this backup's restore on an isolated server first.
// Backup separately: php writable/inventory-development-backup.php referrals
// Usage: php writable/referral-development-migrate.php --apply --backup=...json --restore-rehearsed --writes-stopped
// Recovery verification: replace --apply with --verify-only --evidence=...json; never reapplies DDL.
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
$lockName = 'humus_db:referral-deployment';
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
    if (!preg_match('/\Ahumus_db_before_referrals_.+\.sql\z/D', basename($dump))
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
    $class = 'App\\Database\\Migrations\\RebuildReferralWorkflow';
    $targetRecorded = false;
    foreach ($db->table($historyTable)->get()->getResultArray() as $row) {
        if (preg_replace('/[^0-9]/', '', $row['version']) === '20261016000003' || $row['class'] === $class) {
            $targetRecorded = true;
            if (!$verifyOnly) { throw new RuntimeException('Target migration already recorded; manual review required.'); }
        }
    }

    if ($verifyOnly && !$targetRecorded) { throw new RuntimeException('Target migration must be recorded before verification.'); }
    if (!$db->tableExists('notifications') || !$db->tableExists('referrals')) {
        throw new RuntimeException('Existing notifications and referrals are required.');
    }
    $stage = 'original row fingerprints';
    $columns = [];
    foreach (array_unique(['appointments', 'provider_availability', 'availability_exceptions', 'people', 'users',
        'employee_profiles', 'student_profiles', 'tenants', 'permissions', 'role_permissions', 'department_staff',
        'referrals', 'notifications', 'clinic_encounters', 'counselling_cases', 'audit_events', 'audit_logs', $historyTable]) as $table) {
        if (!$db->tableExists($table)) { continue; }
        $fields = array_column($query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table])->getResultArray(), 'COLUMN_NAME');
        // Notifications DDL must preserve every column of every original row.
        if (!in_array('id', $fields, true) && $table !== 'role_permissions') {
            throw new RuntimeException('Fingerprint table requires original ID.');
        }
        $columns[$table] = $fields;
    }
    $snapshot = static function () use (&$columns, $db): array {
        $out = [];
        foreach ($columns as $table => $fields) {
            $out[$table] = ['columns' => $fields, 'rows' => []];
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

                }
                $offset += count($rows);
            } while (count($rows) === 500);
        }
        return $out;
    };
    if ($verifyOnly) {
        $snapshotPath = $inside($options['evidence']);
        if (!preg_match('/\Areferral_fingerprints_[a-zA-Z0-9_]+\.json\z/D', basename($snapshotPath))) {
            throw new RuntimeException('Invalid fingerprint evidence path.');
        }
        $evidence = json_decode(file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
        if (($evidence['backup_sha256'] ?? null) !== $backup['sha256']) {
            throw new RuntimeException('Fingerprint evidence belongs to a different backup.');
        }
        $before = $evidence['before'] ?? null;
        if (!is_array($before) || array_keys($before) !== array_keys($columns)) { throw new RuntimeException('Original evidence table set mismatch.'); }
        foreach ($before as $table => $original) {
            $allowedAdditions = match ($table) {
                'referrals' => ['target_encounter_id', 'target_case_id', 'request_key'],
                'notifications' => ['recipient_user_id', 'referral_id'],
                default => [],
            };
            if (!is_array($original['columns'] ?? null) || !is_array($original['rows'] ?? null)
                || array_diff($original['columns'], $columns[$table]) !== []
                || array_diff($columns[$table], $original['columns'], $allowedAdditions) !== []) {
                throw new RuntimeException('Original evidence schema mismatch.');
            }
            $columns[$table] = $original['columns'];
        }
    } else {
        $before = $snapshot();
        $snapshotPath = __DIR__ . '/backups/referral_fingerprints_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
    }
    $save = static function (array $data) use ($snapshotPath, $backup): void {
        $data['backup_sha256'] = $backup['sha256'];
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
            $migration = $this->migrationFromFile(APPPATH . 'Database/Migrations/2026-10-16-000003_RebuildReferralWorkflow.php', 'App');
            if (!$migration || $migration->version !== '2026-10-16-000003'
                || $migration->class !== 'App\\Database\\Migrations\\RebuildReferralWorkflow') {
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
        if ($table === $historyTable) {
            if (count($extra) !== 1) { throw new RuntimeException('Expected exactly one new history record.'); }
            $record = $db->table($historyTable)->where('id', array_key_first($extra))->get()->getRowArray();
            if ($record['version'] !== '2026-10-16-000003' || $record['class'] !== $class
                || $record['namespace'] !== 'App' || $record['group'] !== 'default') {
                throw new RuntimeException('Wrong migration recorded.');
            }
        } elseif ($extra !== []) { throw new RuntimeException('Unexpected original-table insertions.'); }
        $counts[$table] = ['before' => count($original['rows']), 'after' => count($current['rows']), 'added' => count($extra)];
    }
    $stage = 'referral workflow schema verification';
    if (!$db->tableExists('notifications')) { throw new RuntimeException('Notifications table missing.'); }
    $engine = $query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications'")->getRowArray();
    if (strtoupper($engine['ENGINE'] ?? '') !== 'INNODB') { throw new RuntimeException('Notifications must use InnoDB.'); }
    $expectedColumns = [
        'id' => ['bigint unsigned', 'NO'],
        'tenant_id' => ['int unsigned', 'NO'],
        'recipient_person_id' => ['bigint unsigned', 'YES'],
        'appointment_id' => ['bigint unsigned', 'YES'],
        'appointment_version' => ['int unsigned', 'YES'],
        'type' => ["enum('appointment_confirmed','referral_received')", 'NO'],
        'read_at' => ['datetime', 'YES'],
        'created_at' => ['datetime', 'NO'],
        'recipient_user_id' => ['bigint unsigned', 'YES'],
        'referral_id' => ['bigint unsigned', 'YES'],
    ];
    $actualColumns = [];
    foreach ($query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' ORDER BY ORDINAL_POSITION")->getResultArray() as $column) {
        // MariaDB reports integer display widths; MySQL 8 omits them.
        $type = preg_replace('/^(bigint|int)\([0-9]+\)/', '$1', strtolower($column['COLUMN_TYPE']));
        $actualColumns[$column['COLUMN_NAME']] = [$type, $column['IS_NULLABLE']];
        if ($column['COLUMN_NAME'] === 'id' && !str_contains(strtolower($column['EXTRA']), 'auto_increment')) {
            throw new RuntimeException('Notification ID must auto-increment.');
        }
    }
    if ($actualColumns !== $expectedColumns) { throw new RuntimeException('Notifications column schema mismatch.'); }
    $checkIndex = static function (string $table, string $name, array $fields, bool $unique) use ($query): void {
        $rows = $query('SELECT COLUMN_NAME,NON_UNIQUE,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX', [$table, $name])->getResultArray();
        if (array_column($rows, 'COLUMN_NAME') !== $fields) { throw new RuntimeException('Notification index columns mismatch.'); }
        foreach ($rows as $row) {
            if ((int) $row['NON_UNIQUE'] !== ($unique ? 0 : 1) || $row['SUB_PART'] !== null) {
                throw new RuntimeException('Notification index definition mismatch.');
            }
        }
    };
    $checkIndex('notifications', 'PRIMARY', ['id'], true);
    $checkIndex('notifications', 'notification_confirmation', ['tenant_id', 'appointment_id', 'type', 'appointment_version'], true);
    $checkIndex('notifications', 'notification_inbox', ['tenant_id', 'recipient_person_id', 'read_at', 'id'], false);
    $checkIndex('appointments', 'notification_appointment_tenant', ['tenant_id', 'id'], true);
    $checkIndex('referrals', 'referral_request_key', ['tenant_id', 'issuer_user_id', 'request_key'], true);
    $checkIndex('referrals', 'notification_referral_tenant', ['tenant_id', 'id'], true);
    $checkIndex('referrals', 'referral_source_page', ['tenant_id', 'source_module', 'status', 'id'], false);
    $checkIndex('referrals', 'referral_target_page', ['tenant_id', 'target_module', 'status', 'id'], false);
    $checkIndex('notifications', 'notification_referral_event', ['tenant_id', 'referral_id', 'recipient_user_id', 'type'], true);
    $checkIndex('notifications', 'notification_user_inbox', ['tenant_id', 'recipient_user_id', 'read_at', 'id'], false);
    $foreignKeys = [];
    foreach ($query("SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='notifications' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION")->getResultArray() as $fk) {
        if ($fk['REFERENCED_TABLE_SCHEMA'] !== 'humus_db' || $fk['DELETE_RULE'] !== 'RESTRICT' || $fk['UPDATE_RULE'] !== 'RESTRICT') {
            throw new RuntimeException('Notification foreign key rules mismatch.');
        }
        $foreignKeys[$fk['CONSTRAINT_NAME']][] = $fk['COLUMN_NAME'] . ':' . $fk['REFERENCED_TABLE_NAME'] . '.' . $fk['REFERENCED_COLUMN_NAME'];
    }
    $actualKeys = array_map(static fn (array $parts): string => implode(',', $parts), array_values($foreignKeys));
    $expectedKeys = ['tenant_id:tenants.id', 'tenant_id:people.tenant_id,recipient_person_id:people.id',
        'tenant_id:appointments.tenant_id,appointment_id:appointments.id',
                'tenant_id:users.tenant_id,recipient_user_id:users.id', 'tenant_id:referrals.tenant_id,referral_id:referrals.id'];
    sort($actualKeys);
    sort($expectedKeys);
    if ($actualKeys !== $expectedKeys) { throw new RuntimeException('Notification foreign key columns mismatch.'); }
    foreach (['target_encounter_id' => 'bigint unsigned', 'target_case_id' => 'bigint unsigned', 'request_key' => 'char(64)'] as $column => $type) {
        $meta = $query("SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='referrals' AND COLUMN_NAME=?", [$column])->getRowArray();
        if (!$meta || preg_replace('/bigint\\([0-9]+\\)/', 'bigint', strtolower($meta['COLUMN_TYPE'])) !== $type || $meta['IS_NULLABLE'] !== 'YES') {
            throw new RuntimeException('Referral workflow column schema mismatch.');
        }
    }
    if ((int) $query('SELECT COUNT(*) AS n FROM referrals WHERE target_encounter_id IS NOT NULL OR target_case_id IS NOT NULL OR request_key IS NOT NULL')->getRow()->n !== 0
        || (int) $query('SELECT COUNT(*) AS n FROM notifications WHERE recipient_user_id IS NOT NULL OR referral_id IS NOT NULL')->getRow()->n !== 0) {
        throw new RuntimeException('Unexpected referral workflow backfill.');
    }
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

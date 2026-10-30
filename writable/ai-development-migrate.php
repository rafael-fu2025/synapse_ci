<?php

declare(strict_types=1);

// CLI-only local operator runner: one reviewed additive migration, never all pending migrations.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$mode = $argv[1] ?? '--inspect';
if (!in_array($mode, ['--inspect', '--apply'], true) || count($argv) > 2) {
    fwrite(STDERR, "Use --inspect or --apply.\n"); exit(1);
}
$stage = 'bootstrap';
$locked = false;
$db = null;
$probeDb = null;
$probeName = null;
$probeOwned = false;
$evidencePath = null;
$evidence = ['status' => 'preflight'];
$exitCode = 0;
try {
    define('ENVIRONMENT', 'development');
    define('FCPATH', dirname(__DIR__) . '/public/');
    require dirname(__DIR__) . '/app/Config/Paths.php';
    $paths = new \Config\Paths();
    require $paths->systemDirectory . '/Boot.php';
    \CodeIgniter\Boot::bootConsole($paths);
    $configuration = config('Database');
    $c = $configuration->default;
    if (env('CI_ENVIRONMENT') !== 'development' || $configuration->defaultGroup !== 'default'
        || ($c['database'] ?? '') !== 'humus_db' || ($c['DBDriver'] ?? '') !== 'MySQLi'
        || !in_array($c['hostname'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
        || !empty($c['DSN']) || !empty($c['failover']) || !empty($c['DBPrefix']) || !empty($c['pConnect'])) {
        throw new RuntimeException('target_not_approved');
    }
    $configuration->default['DBDebug'] = false;
    $db = \Config\Database::connect('default');
    $query = static function (string $sql, array $binds = []) use ($db) {
        $result = $db->query($sql, $binds);
        if ($result === false) { throw new RuntimeException('database_operation_failed'); }
        return $result;
    };
    $identity = $query('SELECT DATABASE() AS name, @@port AS port, VERSION() AS version')->getRowArray();
    if ($identity['name'] !== 'humus_db' || (int) $identity['port'] !== (int) $c['port']) { throw new RuntimeException('target_identity_mismatch'); }
    if (config('Ai')->enabled !== false) { throw new RuntimeException('ai_must_remain_disabled'); }
    $migrationConfig = clone config('Migrations');
    $history = $migrationConfig->table;
    if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $history) || !$db->tableExists($history)) { throw new RuntimeException('history_missing'); }
    $version = '2026-10-28-000001';
    $class = 'App\\Database\\Migrations\\CreateAiInsights';
    $file = APPPATH . 'Database/Migrations/' . $version . '_CreateAiInsights.php';
    $aiTables = ['ai_releases', 'ai_snapshots', 'ai_jobs', 'ai_dispatch_attempts', 'ai_insights', 'ai_reviews'];
    $historyRows = $db->table($history)->orderBy('id')->get()->getResultArray();
    $matching = array_values(array_filter($historyRows, static fn (array $row): bool => $row['class'] === $class
        || preg_replace('/\D/', '', $row['version']) === '20261028000001'));
    $present = array_values(array_filter($aiTables, static fn (string $table): bool => $db->tableExists($table)));
    $dumpExecutable = 'C:/Users/udtoh_lmtzs7k/xampp/mysql/bin/mysqldump.exe';
    $safeStatus = ['database' => $identity['name'], 'driver' => 'MySQLi', 'server_version' => $identity['version'],
        'migration' => $version, 'recorded' => count($matching), 'existing_ai_tables' => $present,
        'native_backup_available' => is_file($dumpExecutable), 'ai_enabled' => false];
    if ($mode === '--inspect') { echo json_encode($safeStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n"; exit(0); }
    $stage = 'preflight';
    if ($matching !== []) { throw new RuntimeException('migration_already_recorded_verify_only'); }
    if ($present !== []) { throw new RuntimeException('partial_ai_schema_requires_review'); }
    if (!is_file($file) || !is_file($dumpExecutable) || !is_dir(__DIR__ . '/backups')) { throw new RuntimeException('deployment_prerequisite_missing'); }
    foreach (glob(__DIR__ . '/*maintenance.flag') ?: [] as $flag) { throw new RuntimeException('existing_maintenance_window'); }
    if ((int) $query('SELECT GET_LOCK(?,0) AS acquired', ['humus_db:ai-insights-migration'])->getRow('acquired') !== 1) { throw new RuntimeException('deployment_busy'); }
    $locked = true;
    $query('SET SESSION lock_wait_timeout=5');
    $query('SET SESSION innodb_lock_wait_timeout=5');
    $stem = __DIR__ . '/backups/ai_deployment_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $evidencePath = $stem . '.json';
    $evidence = $safeStatus + ['status' => 'preflight', 'migration_sha256' => hash_file('sha256', $file),
        'permissions_changed' => false, 'other_migrations_applied' => false];
    $save = static function () use (&$evidence, $evidencePath): void {
        if (file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('evidence_write_failed');
        }
        @chmod($evidencePath, 0600);
    };
    $save();
    $stage = 'disposable_mysql_rehearsal';
    $probeName = 'humus_ai_probe_' . bin2hex(random_bytes(8));
    $evidence['rehearsal_schema'] = $probeName;
    $save();
    $query('CREATE DATABASE `' . $probeName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $probeOwned = true;
    $probeConfig = array_replace($c, ['database' => $probeName, 'DBDebug' => false, 'strictOn' => true]);
    $probeDb = \Config\Database::connect($probeConfig, false);
    if ($probeDb->query('SELECT DATABASE() AS name')->getRow('name') !== $probeName) { throw new RuntimeException('rehearsal_identity_mismatch'); }
    require_once $file;
    (new $class(\Config\Database::forge($probeDb)))->up();
    $cipher = new \App\Services\Ai\PayloadCipher(base64_encode(random_bytes(32)));
    $probeStore = new \App\Services\Ai\InsightStore($probeDb, $cipher, null, 30);
    if (!$probeStore->ready()) { throw new RuntimeException('rehearsal_schema_invalid'); }
    $payload = \App\Services\Ai\DisclosurePolicy::release(['count' => 240, 'contributors' => 20, 'unlinked' => 0],
        ['count' => 200, 'contributors' => 20, 'unlinked' => 0], '2026-09', '2026-08');
    $snapshot = $probeStore->saveSnapshot(1, 'clinic', $payload);
    $job = $probeStore->enqueue(1, 'clinic', $snapshot['id'], 1, str_repeat('a', 64), 'synthetic/model@revision', 2);
    $claim = $probeStore->claim(1, 'clinic', str_repeat('a', 64));
    if ($claim === null || $claim['id'] !== $job['id']) { throw new RuntimeException('rehearsal_claim_failed'); }
    $probeStore->assertLease(1, 'clinic', $job['id'], $claim['lease_token'], 10);
    $probeStore->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 2, 10);
    // Synthetic local persistence only. No HTTP/model call occurs during rehearsal.
    $probeStore->complete(1, 'clinic', $job['id'], ['summary' => 'Synthetic schema rehearsal only.'], $claim['lease_token']);
    $insight = $probeStore->listInsights(1, 'clinic')[0];
    $probeStore->review(1, 'clinic', $insight['id'], 1, 1, 'accept');
    $probeStore->review(1, 'clinic', $insight['id'], 1, 2, 'approve');
    if ($probeStore->getInsight(1, 'clinic', $insight['id'])['status'] !== 'approved') { throw new RuntimeException('rehearsal_review_failed'); }
    $probeDb->close(); $probeDb = null;
    $query('DROP DATABASE `' . $probeName . '`'); $probeOwned = false;
    $evidence['disposable_mysql_rehearsal'] = 'passed';
    $save();

    $stage = 'native_backup';
    $backupOutput = $stem . '.backup-output.json';
    $process = proc_open([PHP_BINARY, __DIR__ . '/inventory-development-backup.php', 'ai_insights'],
        [0 => ['file', 'NUL', 'r'], 1 => ['file', $backupOutput, 'w'], 2 => ['file', $stem . '.backup.stderr', 'w']],
        $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('backup_launch_failed'); }
    $deadline = microtime(true) + 85;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) { break; }
        if (microtime(true) >= $deadline) { proc_terminate($process, 9); throw new RuntimeException('backup_timeout'); }
        usleep(100000);
    } while (true);
    $exit = $status['exitcode'];
    $closed = proc_close($process);
    if ($exit === -1) { $exit = $closed; }
    if ($exit !== 0) { throw new RuntimeException('backup_failed_review_private_log'); }
    $backup = json_decode(file_get_contents($backupOutput), true, 32, JSON_THROW_ON_ERROR);
    $backupRoot = str_replace('\\', '/', realpath(__DIR__ . '/backups')) . '/';
    $dump = realpath($backup['path'] ?? '');
    if ($dump === false || !str_starts_with(str_replace('\\', '/', $dump), $backupRoot)
        || ($backup['database'] ?? '') !== 'humus_db' || ($backup['native_dump_completed'] ?? false) !== true
        || filesize($dump) !== ($backup['bytes'] ?? null) || !hash_equals($backup['sha256'] ?? '', hash_file('sha256', $dump))) {
        throw new RuntimeException('backup_verification_failed');
    }
    $evidence['backup'] = $backup;
    $save();

    $stage = 'existing_schema_baseline';
    $schema = static function () use ($query): array {
        $out = [];
        $tables = $query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->getResultArray();
        foreach ($tables as $row) {
            $table = $row['TABLE_NAME'];
            $definition = array_values($query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->getRowArray())[1];
            // Concurrent legitimate inserts may change only AUTO_INCREMENT; do not call that a schema rewrite.
            $out[$table] = hash('sha256', preg_replace('/\bAUTO_INCREMENT=[0-9]+\s*/', '', $definition));
        }
        return $out;
    };
    $beforeSchema = $schema();
    $beforeHistory = $db->table($history)->orderBy('id')->get()->getResultArray();
    $evidence['existing_schema_before'] = $beforeSchema;
    $evidence['status'] = 'backed_up'; $save();
    $stage = 'exact_additive_migration';
    if (hash_file('sha256', $file) !== $evidence['migration_sha256']) { throw new RuntimeException('migration_changed_after_review'); }
    $migrationConfig->lock = false;
    $runner = new class($migrationConfig, $db) extends \CodeIgniter\Database\MigrationRunner {
        public function findMigrations(): array {
            $migration = $this->migrationFromFile(APPPATH . 'Database/Migrations/2026-10-28-000001_CreateAiInsights.php', 'App');
            if (!$migration || $migration->version !== '2026-10-28-000001' || $migration->class !== 'App\\Database\\Migrations\\CreateAiInsights') {
                throw new RuntimeException('migration_identity_invalid');
            }
            return [$migration->uid => $migration];
        }
        public function regress(int $targetBatch = 0, ?string $group = null) { throw new RuntimeException('automatic_rollback_prohibited'); }
    };
    if (!$runner->latest('default')) { throw new RuntimeException('target_migration_failed'); }
    $db->resetDataCache();
    $stage = 'verification';
    $afterSchema = $schema();
    foreach ($beforeSchema as $table => $fingerprint) {
        if (($afterSchema[$table] ?? null) !== $fingerprint) { throw new RuntimeException('existing_schema_changed'); }
    }
    if (array_values(array_diff(array_keys($afterSchema), array_keys($beforeSchema))) !== ['ai_dispatch_attempts', 'ai_insights', 'ai_jobs', 'ai_releases', 'ai_reviews', 'ai_snapshots']) {
        throw new RuntimeException('unexpected_schema_additions');
    }
    $afterHistory = $db->table($history)->orderBy('id')->get()->getResultArray();
    if (count($afterHistory) !== count($beforeHistory) + 1 || array_slice($afterHistory, 0, count($beforeHistory)) !== $beforeHistory) {
        throw new RuntimeException('history_preservation_failed');
    }
    $record = $afterHistory[count($afterHistory) - 1];
    if ($record['class'] !== $class || $record['version'] !== $version || $record['group'] !== 'default') { throw new RuntimeException('history_target_mismatch'); }
    foreach ($aiTables as $table) {
        if (!$db->tableExists($table) || $db->table($table)->countAllResults() !== 0) { throw new RuntimeException('ai_table_verification_failed'); }
    }
    $store = new \App\Services\Ai\InsightStore($db, new \App\Services\Ai\PayloadCipher(base64_encode(random_bytes(32))), null, 30);
    if (!$store->ready()) { throw new RuntimeException('schema_contract_invalid'); }
    if (config('Ai')->enabled !== false) { throw new RuntimeException('ai_activation_changed'); }
    $evidence['status'] = 'verified';
    $evidence['batch'] = (int) $record['batch'];
    $evidence['existing_tables_schema_preserved'] = count($beforeSchema);
    $evidence['created_tables'] = $aiTables;
    $evidence['schema_contract_verified'] = true;
    $evidence['existing_schema_after'] = $afterSchema;
    $save();
    echo json_encode(['status' => 'verified', 'database' => 'humus_db', 'migration' => $version, 'batch' => (int) $record['batch'],
        'created_tables' => $aiTables, 'existing_table_definitions_preserved' => count($beforeSchema),
        'disposable_mysql_rehearsal' => 'passed', 'native_backup' => str_replace('\\', '/', $dump),
        'backup_restore_tested' => false, 'evidence' => str_replace('\\', '/', $evidencePath),
        'ai_enabled' => false, 'permissions_changed' => false, 'other_migrations_applied' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $error) {
    // Driver exception messages/traces can contain SQL, credentials or source data. Keep them private.
    $evidence['status'] = 'failed'; $evidence['stage'] = $stage; $evidence['error_type'] = get_class($error);
    if ($evidencePath !== null) { @file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
    fwrite(STDERR, 'AI migration stopped safely at ' . $stage . '. No automatic rollback or provider activation was attempted.' . "\n");
    $exitCode = 1;
} finally {
    try {
        if ($probeDb !== null) { $probeDb->close(); }
        if ($probeOwned && $db !== null && is_string($probeName) && preg_match('/\Ahumus_ai_probe_[a-f0-9]{16}\z/D', $probeName)) {
            if ($db->query('DROP DATABASE `' . $probeName . '`') === false) { throw new RuntimeException('rehearsal_cleanup_failed'); }
        }
        if ($locked && $db !== null) { $db->query('SELECT RELEASE_LOCK(?)', ['humus_db:ai-insights-migration']); }
    } catch (Throwable) {
        fwrite(STDERR, "Deployment cleanup requires review of the restricted evidence.\n");
        $exitCode = 1;
    }
}
exit($exitCode);

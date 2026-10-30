<?php

declare(strict_types=1);

// Operator-only: never discover pending migrations or activate AI.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$mode = $argv[1] ?? '--inspect';
if (!in_array($mode, ['--inspect', '--rehearse', '--apply'], true) || count($argv) > 2) {
    fwrite(STDERR, "Use --inspect, --rehearse or --apply.\n"); exit(1);
}
ini_set('display_errors', '0');
final class AiScopeStop extends RuntimeException {}
function scopeRequire(bool $condition, string $reason): void
{
    if (!$condition) { throw new AiScopeStop($reason); }
}
function scopeQuery($db, string $sql, array $binds = [])
{
    $result = $db->query($sql, $binds);
    scopeRequire($result !== false, 'database_operation_failed');
    return $result;
}
function scopeIdentifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}
function scopeSchema($db): array
{
    $out = [];
    foreach (scopeQuery($db, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->getResultArray() as $row) {
        $definition = array_values(scopeQuery($db, 'SHOW CREATE TABLE ' . scopeIdentifier($row['TABLE_NAME']))->getRowArray())[1];
        $out[$row['TABLE_NAME']] = hash('sha256', preg_replace('/\bAUTO_INCREMENT=[0-9]+\s*/', '', $definition));
    }
    return $out;
}
function scopeRows($db, array $tables, array $snapshotColumns): array
{
    $out = [];
    foreach ($tables as $table) {
        $columns = $table === 'ai_snapshots' ? implode(',', array_map('scopeIdentifier', $snapshotColumns)) : '*';
        $rows = scopeQuery($db, 'SELECT ' . $columns . ' FROM ' . scopeIdentifier($table) . ' ORDER BY id')->getResultArray();
        $out[$table] = ['count' => count($rows), 'sha256' => hash('sha256', serialize($rows))];
    }
    return $out;
}
function scopeStore($db): \App\Services\Ai\InsightStore
{
    // Metadata inspection needs no application encryption key or payload decryption.
    return new \App\Services\Ai\InsightStore($db,
        new \App\Services\Ai\PayloadCipher(base64_encode(random_bytes(32))), null, 30);
}
function scopeProcess(array $command, string $stdout, string $stderr, int $seconds): void
{
    $process = proc_open($command, [0 => ['file', 'NUL', 'r'], 1 => ['file', $stdout, 'w'],
        2 => ['file', $stderr, 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    scopeRequire(is_resource($process), 'subprocess_launch_failed');
    $deadline = microtime(true) + $seconds;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) { break; }
        if (microtime(true) >= $deadline) {
            proc_terminate($process, 9); proc_close($process);
            throw new AiScopeStop('subprocess_timeout');
        }
        usleep(100000);
    } while (true);
    $closed = proc_close($process);
    scopeRequire(($status['exitcode'] === -1 ? $closed : $status['exitcode']) === 0, 'subprocess_failed_review_private_log');
}
function scopePrivateBackupRoot(): void
{
    // chmod alone does not establish privacy on Windows. Fail closed on the actual DACL.
    scopeRequire(PHP_OS_FAMILY === 'Windows', 'windows_private_backup_acl_check_required');
    $script = '$ErrorActionPreference="Stop"; $sid=[System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value; '
        . '$a=Get-Acl -LiteralPath "writable/backups"; if (!$a.AreAccessRulesProtected) {exit 2}; '
        . 'foreach ($r in $a.Access) {$s=$r.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value; '
        . 'if ($r.AccessControlType -eq "Allow" -and $s -notin @($sid,"S-1-5-18","S-1-5-32-544")) {exit 3}}; exit 0';
    $process = proc_open(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script],
        [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, dirname(__DIR__));
    scopeRequire(is_resource($process), 'backup_acl_check_unavailable');
    scopeRequire(proc_close($process) === 0, 'backup_directory_not_private');
}
$stage = 'bootstrap';
$db = $probe = null;
$probeName = null;
$probeOwned = false;
$locks = [];
$evidencePath = null;
$evidence = ['status' => 'preflight', 'live_apply_attempted' => false, 'live_applied' => false,
    'other_migrations_applied' => false, 'role_grants_changed' => false, 'provider_calls' => 0, 'settings_changed' => false];
$exit = 0;
$save = static function () use (&$evidence, &$evidencePath): void {
    if ($evidencePath !== null) {
        scopeRequire(file_put_contents($evidencePath, json_encode($evidence,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'evidence_write_failed');
    }
};
try {
    define('ENVIRONMENT', 'development');
    define('FCPATH', dirname(__DIR__) . '/public/');
    require dirname(__DIR__) . '/app/Config/Paths.php';
    $paths = new \Config\Paths();
    require $paths->systemDirectory . '/Boot.php';
    \CodeIgniter\Boot::bootConsole($paths);
    ini_set('display_errors', '0');
    $configuration = config('Database');
    $c = $configuration->default;
    scopeRequire(env('CI_ENVIRONMENT') === 'development' && $configuration->defaultGroup === 'default'
        && ($c['database'] ?? '') === 'humus_db' && ($c['DBDriver'] ?? '') === 'MySQLi'
        && in_array($c['hostname'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
        && empty($c['DSN']) && empty($c['failover']) && empty($c['DBPrefix']) && empty($c['pConnect']), 'target_not_approved');
    $configuration->default['DBDebug'] = false;
    $db = \Config\Database::connect('default');
    $identity = scopeQuery($db, 'SELECT DATABASE() AS name, @@port AS port, VERSION() AS version')->getRowArray();
    scopeRequire($identity['name'] === 'humus_db' && (int) $identity['port'] === (int) $c['port']
        && str_contains($identity['version'], 'MariaDB'), 'target_identity_mismatch');
    scopeRequire(config('Ai')->enabled === false, 'ai_must_remain_disabled');
    $version = '2026-10-29-000001';
    $class = 'App\\Database\\Migrations\\AddAiAnalysisScope';
    $file = APPPATH . 'Database/Migrations/' . $version . '_AddAiAnalysisScope.php';
    $reviewedHash = 'f4bcad6cfea583bc161dd8dced79ecb15053e66c6c602f6735672f8c86f36c7e';
    scopeRequire(is_file($file) && hash_equals($reviewedHash, hash_file('sha256', $file)), 'migration_changed_since_review');
    $migrationConfig = clone config('Migrations');
    $history = $migrationConfig->table;
    scopeRequire((bool) preg_match('/\A[A-Za-z0-9_]+\z/D', $history) && $db->tableExists($history), 'history_missing');
    $beforeHistory = $db->table($history)->orderBy('id')->get()->getResultArray();
    $matching = static fn (string $name, string $digits): array => array_values(array_filter($beforeHistory,
        static fn (array $row): bool => $row['class'] === $name || preg_replace('/\D/', '', $row['version']) === $digits));
    $foundation = $matching('App\\Database\\Migrations\\CreateAiInsights', '20261028000001');
    scopeRequire(count($foundation) === 1 && (int) $foundation[0]['batch'] === 33
        && $foundation[0]['group'] === 'default' && $foundation[0]['namespace'] === 'App', 'foundation_history_not_expected_batch33');
    $recorded = $matching($class, '20261029000001');
    $aiTables = ['ai_releases', 'ai_snapshots', 'ai_jobs', 'ai_dispatch_attempts', 'ai_insights', 'ai_reviews'];
    foreach ($aiTables as $table) { scopeRequire($db->tableExists($table), 'foundation_table_missing'); }
    $snapshotColumns = $db->getFieldNames('ai_snapshots');
    $store = scopeStore($db);
    $safe = ['database' => 'humus_db', 'server_version' => $identity['version'], 'migration' => $version,
        'foundation_batch' => 33, 'recorded' => count($recorded), 'store_ready' => $store->ready(),
        'supports_analysis_scope' => $store->supportsAnalysisScope(),
        'department_access_exists' => $db->tableExists('ai_department_access'), 'ai_enabled' => false];
    if ($mode === '--inspect') {
        echo json_encode($safe + ['ai_rows' => array_map(static fn ($v) => $v['count'], scopeRows($db, $aiTables, $snapshotColumns))], JSON_PRETTY_PRINT), "\n";
    } else {
        $stage = 'preflight';
        scopeRequire($recorded === [], 'migration_already_recorded_inspect_only');
        scopeRequire($store->ready() && !$store->supportsAnalysisScope()
            && !in_array('analysis_code', $snapshotColumns, true) && !$db->tableExists('ai_department_access'), 'partial_or_incompatible_scope_requires_review');
        scopeRequire((glob(__DIR__ . '/*maintenance.flag') ?: []) === [], 'existing_maintenance_window');
        if ($db->tableExists($history . '_lock')) {
            scopeRequire($db->table($history . '_lock')->countAllResults() === 0, 'framework_migration_lock_busy');
        }
        foreach (['humus_db:ai-insights-migration', 'humus_db:ai-analysis-scope-migration'] as $lock) {
            scopeRequire((int) scopeQuery($db, 'SELECT GET_LOCK(?,0) AS acquired', [$lock])->getRow('acquired') === 1, 'deployment_busy');
            $locks[] = $lock;
        }
        scopeQuery($db, 'SET SESSION lock_wait_timeout=5');
        scopeQuery($db, 'SET SESSION innodb_lock_wait_timeout=5');
        scopePrivateBackupRoot();
        $directory = __DIR__ . '/backups/ai_analysis_scope_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
        scopeRequire(mkdir($directory, 0700), 'private_evidence_directory_failed');
        $evidencePath = $directory . '/evidence.json';
        $evidence += $safe + ['migration_sha256' => $reviewedHash, 'mode' => $mode, 'backup_directory_acl_verified' => true];
        $beforeSchema = scopeSchema($db);
        $beforeRows = scopeRows($db, $aiTables, $snapshotColumns);
        $evidence['existing_schema_before'] = $beforeSchema;
        $evidence['ai_rows_before'] = $beforeRows;
        $evidence['old_history_count'] = count($beforeHistory);
        $evidence['old_history_sha256'] = hash('sha256', serialize($beforeHistory));
        $save();
        $stage = 'native_backup';
        scopeProcess([PHP_BINARY, __DIR__ . '/inventory-development-backup.php', 'ai_analysis_scope'],
            $directory . '/backup-output.json', $directory . '/backup.stderr', 85);
        $backup = json_decode(file_get_contents($directory . '/backup-output.json'), true, 32, JSON_THROW_ON_ERROR);
        $dump = realpath($backup['path'] ?? '');
        $root = str_replace('\\', '/', realpath(__DIR__ . '/backups')) . '/';
        scopeRequire($dump !== false && str_starts_with(str_replace('\\', '/', $dump), $root)
            && ($backup['database'] ?? '') === 'humus_db' && ($backup['native_dump_completed'] ?? false) === true
            && filesize($dump) === ($backup['bytes'] ?? null) && hash_equals($backup['sha256'] ?? '', hash_file('sha256', $dump)), 'backup_verification_failed');
        $evidence['backup'] = $backup;
        $save();

        $stage = 'disposable_mariadb_rehearsal';
        $probeName = 'humus_ai_scope_probe_' . bin2hex(random_bytes(8));
        $evidence['rehearsal_schema'] = $probeName;
        $save();
        $collation = scopeQuery($db, 'SELECT DEFAULT_CHARACTER_SET_NAME AS cs, DEFAULT_COLLATION_NAME AS co FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()')->getRowArray();
        scopeRequire((bool) preg_match('/\A[a-z0-9_]+\z/D', $collation['cs'] . $collation['co']), 'database_collation_invalid');
        scopeQuery($db, 'CREATE DATABASE ' . scopeIdentifier($probeName) . ' CHARACTER SET ' . $collation['cs'] . ' COLLATE ' . $collation['co']);
        $probeOwned = true;
        $probe = \Config\Database::connect(array_replace($c, ['database' => $probeName, 'DBDebug' => false, 'strictOn' => true]), false);
        scopeRequire(scopeQuery($probe, 'SELECT DATABASE() AS name')->getRow('name') === $probeName, 'rehearsal_identity_mismatch');
        // Clone installed definitions/data. Never re-run CreateAiInsights, even for rehearsal.
        foreach (array_merge($aiTables, [$history]) as $table) {
            $definition = array_values(scopeQuery($db, 'SHOW CREATE TABLE ' . scopeIdentifier($table))->getRowArray())[1];
            scopeQuery($probe, $definition);
            scopeQuery($probe, 'INSERT INTO ' . scopeIdentifier($table) . ' SELECT * FROM humus_db.' . scopeIdentifier($table));
        }
        scopeRequire(scopeRows($probe, $aiTables, $snapshotColumns) === $beforeRows, 'rehearsal_clone_changed_rows');
        scopeRequire($probe->table($history)->orderBy('id')->get()->getResultArray() === $beforeHistory, 'rehearsal_clone_changed_history');
        // Incoming FK and ciphertext survival are checked even when production AI tables are empty.
        $cipher = new \App\Services\Ai\PayloadCipher(base64_encode(random_bytes(32)));
        $synthetic = new \App\Services\Ai\InsightStore($probe, $cipher, static fn (): int => strtotime('2026-10-29 12:00:00 UTC'), 30);
        $payload = \App\Services\Ai\DisclosurePolicy::release(['count' => 240, 'contributors' => 20, 'unlinked' => 0],
            ['count' => 200, 'contributors' => 20, 'unlinked' => 0], '2026-09', '2026-08');
        $tenant = 900000000000000000;
        scopeRequire($probe->table('ai_snapshots')->where('tenant_id', $tenant)->countAllResults() === 0, 'synthetic_tenant_collision');
        $snapshot = $synthetic->saveSnapshot($tenant, 'clinic', $payload);
        $job = $synthetic->enqueue($tenant, 'clinic', $snapshot['id'], 1, str_repeat('a', 64), 'synthetic/model', 2);
        $claim = $synthetic->claim($tenant, 'clinic', str_repeat('a', 64));
        scopeRequire($claim !== null && $claim['id'] === $job['id'], 'synthetic_claim_failed');
        $synthetic->reserveDispatch($tenant, 'clinic', $job['id'], $claim['lease_token'], 2, 10);
        $synthetic->complete($tenant, 'clinic', $job['id'], ['summary' => 'Synthetic local migration rehearsal.'], $claim['lease_token']);
        $insight = $synthetic->listInsights($tenant, 'clinic')[0];
        $synthetic->review($tenant, 'clinic', $insight['id'], 1, 1, 'accept');
        scopeQuery($probe, 'CREATE TABLE scope_probe_reference (id BIGINT PRIMARY KEY, snapshot_id BIGINT UNSIGNED NOT NULL, FOREIGN KEY (snapshot_id) REFERENCES ai_snapshots(id)) ENGINE=InnoDB');
        scopeQuery($probe, 'INSERT INTO scope_probe_reference VALUES (1, ?)', [$snapshot['id']]);
        $probeRows = scopeRows($probe, $aiTables, $snapshotColumns);
        require_once $file;
        (new $class(\Config\Database::forge($probe)))->up();
        $probe->resetDataCache();
        scopeRequire(scopeStore($probe)->ready() && scopeStore($probe)->supportsAnalysisScope(), 'rehearsal_store_contract_invalid');
        scopeRequire(scopeRows($probe, $aiTables, $snapshotColumns) === $probeRows, 'rehearsal_ai_rows_changed');
        scopeRequire($probe->table($history)->orderBy('id')->get()->getResultArray() === $beforeHistory, 'rehearsal_old_history_changed');
        scopeRequire((int) scopeQuery($probe, "SELECT COUNT(*) AS n FROM ai_snapshots WHERE analysis_code <> 'clinic.activity'")->getRow('n') === 0, 'rehearsal_legacy_backfill_invalid');
        scopeRequire($synthetic->saveSnapshot($tenant, 'clinic', $payload)['id'] === $snapshot['id'], 'rehearsal_legacy_snapshot_changed');
        scopeRequire($synthetic->getInsight($tenant, 'clinic', $insight['id'])['status'] === 'accepted', 'rehearsal_ciphertext_or_review_invalid');
        // Exercise the expanded unique key without relying on unrelated v2 application validation.
        $copy = $probe->table('ai_snapshots')->where('id', $snapshot['id'])->get()->getRowArray();
        unset($copy['id']);
        $copy['analysis_code'] = 'clinic.complaints';
        scopeRequire($probe->table('ai_snapshots')->insert($copy), 'rehearsal_second_analysis_failed');
        scopeRequire($probe->table('ai_snapshots')->ignore(true)->insert($copy), 'rehearsal_duplicate_query_failed');
        scopeRequire($probe->affectedRows() === 0, 'rehearsal_scoped_duplicate_not_rejected');
        scopeRequire($probe->table('ai_department_access')->countAllResults() === 0, 'rehearsal_access_seeded');
        scopeRequire(count($probe->getForeignKeyData('scope_probe_reference')) === 1, 'rehearsal_foreign_key_missing');
        $expectedSchema = scopeSchema($probe);
        (new $class(\Config\Database::forge($probe)))->up();
        scopeRequire(scopeSchema($probe) === $expectedSchema, 'rehearsal_retry_changed_schema');
        $evidence['disposable_mariadb_rehearsal'] = 'passed';
        $probe->close(); $probe = null;
        scopeQuery($db, 'DROP DATABASE ' . scopeIdentifier($probeName)); $probeOwned = false;
        $evidence['rehearsal_schema_removed'] = true;
        $save();

        $stage = 'live_baseline_recheck';
        scopeRequire(scopeSchema($db) === $beforeSchema && scopeRows($db, $aiTables, $snapshotColumns) === $beforeRows
            && $db->table($history)->orderBy('id')->get()->getResultArray() === $beforeHistory, 'live_baseline_changed_stop_for_review');
        scopeRequire(hash_equals($reviewedHash, hash_file('sha256', $file)), 'migration_changed_after_rehearsal');
        if ($mode === '--apply') {
            $stage = 'exact_scope_migration';
            $evidence['live_apply_attempted'] = true; $save();
            $migrationConfig->lock = false;
            $runner = new class($migrationConfig, $db) extends \CodeIgniter\Database\MigrationRunner {
                public function findMigrations(): array
                {
                    $m = $this->migrationFromFile(APPPATH . 'Database/Migrations/2026-10-29-000001_AddAiAnalysisScope.php', 'App');
                    scopeRequire($m !== null && $m->version === '2026-10-29-000001'
                        && $m->class === 'App\\Database\\Migrations\\AddAiAnalysisScope', 'migration_identity_invalid');
                    return [$m->uid => $m];
                }
                public function regress(int $targetBatch = 0, ?string $group = null)
                {
                    throw new AiScopeStop('automatic_rollback_prohibited');
                }
            };
            scopeRequire($runner->latest('default') === true, 'target_migration_failed');
            $evidence['live_applied'] = true;
            $db->resetDataCache();
            $stage = 'live_verification';
            $afterSchema = scopeSchema($db);
            foreach ($beforeSchema as $table => $hash) {
                scopeRequire(($afterSchema[$table] ?? null) === ($table === 'ai_snapshots' ? $expectedSchema[$table] : $hash), 'existing_definition_changed_unexpectedly');
            }
            scopeRequire(array_values(array_diff(array_keys($afterSchema), array_keys($beforeSchema))) === ['ai_department_access']
                && $afterSchema['ai_department_access'] === $expectedSchema['ai_department_access'], 'unexpected_schema_additions');
            scopeRequire(scopeRows($db, $aiTables, $snapshotColumns) === $beforeRows, 'old_ai_rows_changed');
            $afterHistory = $db->table($history)->orderBy('id')->get()->getResultArray();
            scopeRequire(count($afterHistory) === count($beforeHistory) + 1
                && array_slice($afterHistory, 0, count($beforeHistory)) === $beforeHistory, 'old_history_changed');
            $record = $afterHistory[count($afterHistory) - 1];
            scopeRequire($record['class'] === $class && $record['version'] === $version && $record['group'] === 'default'
                && $record['namespace'] === 'App' && (int) $record['batch'] === max(array_column($beforeHistory, 'batch')) + 1, 'new_history_mismatch');
            scopeRequire(scopeStore($db)->ready() && scopeStore($db)->supportsAnalysisScope(), 'live_store_contract_invalid');
            scopeRequire((int) scopeQuery($db, "SELECT COUNT(*) AS n FROM ai_snapshots WHERE analysis_code <> 'clinic.activity'")->getRow('n') === 0, 'live_legacy_backfill_invalid');
            scopeRequire($db->table('ai_department_access')->countAllResults() === 0, 'live_access_not_empty');
            scopeRequire(config('Ai')->enabled === false, 'ai_activation_changed');
            $evidence['batch'] = (int) $record['batch'];
            $evidence['existing_schema_after'] = $afterSchema;
            $evidence['ai_rows_after'] = scopeRows($db, $aiTables, $snapshotColumns);
            $evidence['unrelated_table_definitions_preserved'] = count($beforeSchema) - 1;
            $evidence['old_history_preserved'] = true;
            $evidence['old_ai_rows_preserved'] = true;
            $evidence['store_ready'] = true;
            $evidence['supports_analysis_scope'] = true;
        }
        $evidence['status'] = $mode === '--apply' ? 'verified' : 'rehearsed_not_applied';
        $save();
        echo json_encode(array_intersect_key($evidence, array_flip(['status', 'database', 'migration', 'batch',
            'foundation_batch', 'live_apply_attempted', 'live_applied', 'disposable_mariadb_rehearsal', 'rehearsal_schema_removed',
            'unrelated_table_definitions_preserved', 'old_history_preserved', 'old_ai_rows_preserved', 'store_ready',
            'supports_analysis_scope', 'ai_enabled', 'other_migrations_applied', 'role_grants_changed', 'provider_calls', 'settings_changed']))
            + ['backup_path' => str_replace('\\', '/', $dump), 'backup_sha256' => $backup['sha256'],
                'backup_restore_tested' => false, 'evidence_path' => str_replace('\\', '/', $evidencePath)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    }
} catch (Throwable $error) {
    // Never print driver exception messages, SQL, traces, credentials or row contents.
    $evidence['status'] = 'failed'; $evidence['stage'] = $stage;
    $evidence['blocker'] = $error instanceof AiScopeStop ? $error->getMessage() : 'unexpected_' . get_class($error);
    $evidence['error_type'] = get_class($error);
    try { $save(); } catch (Throwable) {}
    fwrite(STDERR, json_encode(['status' => 'stopped', 'stage' => $stage, 'blocker' => $evidence['blocker'],
        'live_apply_attempted' => $evidence['live_apply_attempted'], 'live_applied' => $evidence['live_applied'],
        'evidence_path' => $evidencePath], JSON_UNESCAPED_SLASHES) . "\n");
    $exit = 1;
} finally {
    try {
        if ($probe !== null) { $probe->close(); }
        if ($probeOwned && $db !== null && is_string($probeName)
            && preg_match('/\Ahumus_ai_scope_probe_[a-f0-9]{16}\z/D', $probeName)) {
            scopeQuery($db, 'DROP DATABASE ' . scopeIdentifier($probeName));
            $evidence['rehearsal_schema_removed'] = true;
            $save();
        }
        foreach (array_reverse($locks) as $lock) { scopeQuery($db, 'SELECT RELEASE_LOCK(?)', [$lock]); }
    } catch (Throwable) {
        fwrite(STDERR, "Cleanup requires review of private evidence; no rollback attempted.\n"); $exit = 1;
    }
}
exit($exit);

<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Reuse only read-only fingerprint utilities; never invoke engagement deployment.
if (!defined('SYNAPSE_ENGAGEMENT_LIBRARY_ONLY')) { define('SYNAPSE_ENGAGEMENT_LIBRARY_ONLY', true); }
require_once __DIR__ . '/engagement-development-migrate.php';

function audienceColumns(): array
{
    require_once dirname(__DIR__) . '/app/Database/Migrations/2026-10-17-000004_AddStudentAudienceTargeting.php';
    return \App\Database\Migrations\AddStudentAudienceTargeting::columns();
}

function audienceTargets(): array
{
    return ['2026-10-17-000004' => 'AddStudentAudienceTargeting'];
}

function audienceSnapshot($db, ?array $original = null): array { return engagementSnapshot($db, $original); }

function audiencePreflight($db): void
{
    foreach (engagementTargets() as $version => $class) {
        if ($db->table(config('Migrations')->table)->where(['version' => $version, 'class' => 'App\\Database\\Migrations\\' . $class, 'namespace' => 'App', 'group' => 'default'])->countAllResults() !== 1) {
            throw new RuntimeException('Exact engagement prerequisites required.');
        }
    }
    foreach ($db->table(config('Migrations')->table)->get()->getResultArray() as $row) {
        if (preg_replace('/[^0-9]/', '', $row['version']) === '20261017000004' || $row['class'] === 'App\Database\Migrations\AddStudentAudienceTargeting') {
            throw new RuntimeException('Audience migration already recorded; review explicitly.');
        }
    }
    foreach (audienceColumns() as $table => $columns) {
        foreach ($columns as $column => $type) {
            if ($db->fieldExists($column, $table)) { throw new RuntimeException('Partial audience DDL requires explicit recovery, not guarded apply.'); }
        }
    }
}

function audienceApply($db): void
{
    $config = clone config('Migrations');
    if (property_exists($config, 'lock')) { $config->lock = false; }
    $runner = new class($config, $db) extends \CodeIgniter\Database\MigrationRunner {
        public function findMigrations(): array
        {
            $out = [];
            foreach (audienceTargets() as $version => $class) {
                $m = $this->migrationFromFile(APPPATH . 'Database/Migrations/' . $version . '_' . $class . '.php', 'App');
                if (!$m || $m->version !== $version || $m->class !== 'App\\Database\\Migrations\\' . $class
                    || $m->namespace !== 'App') { throw new RuntimeException('Exact audience migration identity mismatch.'); }
                $out[$m->uid] = $m;
            }
            return $out;
        }
        public function regress(int $targetBatch = 0, ?string $group = null)
        {
            throw new RuntimeException('Automatic rollback is prohibited.');
        }
    };
    if (count($runner->findMigrations()) !== 1 || !$runner->latest('default')) {
        throw new RuntimeException('Exact audience migrations did not complete.');
    }
    $db->resetDataCache();
}

function audienceVerify($db, array $before): array
{
    if (engagementTables($db) !== array_keys($before)) { throw new RuntimeException('Audience table set changed.'); }
    $after = audienceSnapshot($db, $before);
    $history = config('Migrations')->table;
    $rows = $db->table($history)->where(['version' => '2026-10-17-000004', 'class' => 'App\Database\Migrations\AddStudentAudienceTargeting', 'namespace' => 'App', 'group' => 'default'])->get()->getResultArray();
    if (count($rows) !== 1) { throw new RuntimeException('Exactly one audience history record required.'); }
    $historyHash = hash('sha256', serialize($rows[0]));
    $additions = audienceColumns();
    $proof = [];
    foreach ($before as $table => $original) {
        $current = $after[$table];
        if ($original['indexes'] !== $current['indexes'] || $original['foreign_keys'] !== $current['foreign_keys']) { throw new RuntimeException('Original keys changed: ' . $table); }
        $oldSchema = array_column($original['schema'], null, 'COLUMN_NAME');
        $newSchema = array_column($current['schema'], null, 'COLUMN_NAME');
        foreach ($oldSchema as $name => $definition) {
            if (($newSchema[$name] ?? null) !== $definition) { throw new RuntimeException('Original column changed: ' . $table . '.' . $name); }
        }
        $extraColumns = array_diff(array_keys($newSchema), array_keys($oldSchema));
        $expectedColumns = array_keys($additions[$table] ?? []);
        if (array_diff($extraColumns, $expectedColumns) || array_diff($expectedColumns, $extraColumns)) { throw new RuntimeException('Unexpected audience columns.'); }
        $expectedHashes = $original['hashes'];
        if ($table === $history) { $expectedHashes[$historyHash] = ($expectedHashes[$historyHash] ?? 0) + 1; ksort($expectedHashes); }
        if ($current['hashes'] !== $expectedHashes) { throw new RuntimeException('Original row fingerprints changed: ' . $table); }
        $proof[$table] = ['rows_before' => array_sum($original['hashes']), 'rows_after' => array_sum($current['hashes']), 'original_sha256' => hash('sha256', serialize($original['hashes']))];
    }
    foreach ($additions as $table => $columns) {
        foreach ($columns as $column => $type) {
            $meta = array_column($after[$table]['schema'], null, 'COLUMN_NAME')[$column];
            if (preg_replace('/tinyint\(\d+\)/', 'tinyint', strtolower($meta['COLUMN_TYPE'])) !== $type
                || $meta['IS_NULLABLE'] !== 'YES' || !in_array($meta['COLUMN_DEFAULT'], [null, 'NULL'], true) || $meta['EXTRA'] !== ''
                || $db->table($table)->where($column . ' IS NOT NULL', null, false)->countAllResults() !== 0) { throw new RuntimeException('Audience fields must be nullable and unbackfilled.'); }
        }
    }
    $check = $db->query("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='student_profiles' AND CONSTRAINT_NAME='student_audience_graduating_check'")->getRowArray();
    if ($check === null || preg_replace('/[\s`()]+/', '', strtolower($check['CHECK_CLAUSE'])) !== 'is_graduatingisnulloris_graduatingin0,1') {
        throw new RuntimeException('Graduating boolean check missing or incompatible.');
    }
    return $proof;
}

if (defined('SYNAPSE_AUDIENCE_LIBRARY_ONLY') && SYNAPSE_AUDIENCE_LIBRARY_ONLY === true) { return; }

// Stop ALL writers, create a fresh backup and rehearse that exact SHA before apply.
// php writable/inventory-development-backup.php audience
// python writable/isolated-inventory-harness.py --validate-audience --backup-stem=writable/backups/<stem>
// php writable/audience-development-migrate.php --apply --backup=<stem>.json --restore-rehearsed --writes-stopped
// Recovery: --verify-only --evidence=<audience_fingerprints_*.json>, never reapply partial DDL.
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
$lockName = 'humus_db:audience-deployment';
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
    if (!preg_match('/\Ahumus_db_before_audience_.+\.sql\z/D', basename($dump))
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

    $stage = 'SHA-bound restore rehearsal evidence';
    $rehearsalVerified = false;
    // Dedicated reports survive later focused/security test runs replacing the ownership report.
    foreach (glob(__DIR__ . '/isolated-inventory-db/audience-restore-result-*.json') ?: [] as $rehearsalPath) {
        $rehearsal = json_decode(file_get_contents($rehearsalPath), true, 512, JSON_THROW_ON_ERROR);
        $stages = $rehearsal['stages'] ?? [];
        if (($rehearsal['success'] ?? false) === true && ($rehearsal['graceful_shutdown'] ?? false) === true
            && ($rehearsal['job_closed'] ?? false) === true && ($rehearsal['owned_children_exited'] ?? false) === true
            && array_column($stages, 'name') === ['preflight_ok', 'initialized', 'isolated_server_identity_verified', 'restore_verified', 'audience_restore_migration_passed']
            && ($stages[0]['backup_sha256'] ?? null) === $backup['sha256']
            && ($stages[4]['backup_sha256'] ?? null) === $backup['sha256']) {
            $rehearsalVerified = true;
            break;
        }
    }
    if (!$rehearsalVerified) { throw new RuntimeException('Successful clean audience restore rehearsal for this exact backup SHA required.'); }

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


    $stage = 'original fingerprints';
    if ($verifyOnly) {
        $snapshotPath = $inside($options['evidence']);
        if (!preg_match('/\Aaudience_fingerprints_[a-zA-Z0-9_]+\.json\z/D', basename($snapshotPath))) {
            throw new RuntimeException('Invalid audience fingerprint evidence path.');
        }
        $evidence = json_decode(file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
        if (($evidence['backup_sha256'] ?? null) !== $backup['sha256'] || !is_array($evidence['before'] ?? null)) {
            throw new RuntimeException('Original fingerprint evidence required for this backup SHA.');
        }
        $before = $evidence['before'];
    } else {
        audiencePreflight($db);
        $before = audienceSnapshot($db);
        $snapshotPath = __DIR__ . '/backups/audience_fingerprints_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
    }
    $save = static function (array $data) use ($snapshotPath, $backup): void {
        $data['backup_sha256'] = $backup['sha256'];
        if (file_put_contents($snapshotPath, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Fingerprint evidence write failed.');
        }
    };
    if (!$verifyOnly) {
        $save(['status' => 'before', 'before' => $before]);
        $assertIdle();
        $stage = 'exact audience migrations (forward only)';
        audienceApply($db);
    }
    $stage = 'audience preservation verification';
    $counts = audienceVerify($db, $before);
    $save(['status' => 'verified', 'before' => $before, 'after' => audienceSnapshot($db, $before), 'counts' => $counts]);
    if (!unlink($flag)) { throw new RuntimeException('Verified, but maintenance flag removal failed.'); }
    echo json_encode(['status' => 'verified', 'counts' => $counts, 'evidence' => $snapshotPath,
        'backup_sha256' => $backup['sha256'], 'history' => 'preserved+1', 'new_nullable_columns' => 6,
        'notifications_created' => 0, 'published_records_created' => 0, 'maintenance' => false,
        'restore_rehearsal' => 'sha256_verified', 'automatic_rollback' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    // Best-effort read-only evidence for partial DDL/backfill failures; never repair or roll back here.
    if (isset($before, $save)) {
        try { $save(['status' => 'failed_unverified', 'before' => $before, 'after' => audienceSnapshot($db, $before)]); }
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

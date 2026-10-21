<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('SYNAPSE_ENGAGEMENT_LIBRARY_ONLY')) { define('SYNAPSE_ENGAGEMENT_LIBRARY_ONLY', true); }
require_once __DIR__ . '/engagement-development-migrate.php';

function bmgSourceHashes(): array
{
    $out = [];
    foreach (array_merge(['writable/bmg-development-migrate.php', 'writable/bmg-development-rehearse.php', 'writable/bmg-development-rehearse.py'], array_map(static fn ($v, $c) => 'app/Database/Migrations/' . $v . '_' . $c . '.php', array_keys(bmgTargets()), array_values(bmgTargets()))) as $path) {
        $out[$path] = hash_file('sha256', dirname(__DIR__) . '/' . $path);
    }
    return $out;
}
function bmgTargets(): array
{
    return ['2026-10-17-000005' => 'CreateBmgRotationOperations', '2026-10-17-000006' => 'AddBmgRotationPermissions'];
}
function bmgTables(): array { return ['bmg_rotation_schedules', 'bmg_rotation_commands', 'bmg_rotation_runs']; }
function bmgColumns(): array
{
    return ['reported_firmware_version' => 'varchar(32)', 'rtc_valid' => 'tinyint', 'applied_schedule_version' => 'int unsigned',
        'revoked_at' => 'datetime', 'rotation_bound_at' => 'datetime', 'firmware_reported_at' => 'datetime',
        'rtc_reported_at' => 'datetime', 'schedule_reported_at' => 'datetime'];
}
function bmgCatalog(): array
{
    require_once dirname(__DIR__) . '/app/Database/Migrations/2026-10-17-000006_AddBmgRotationPermissions.php';
    return \App\Database\Migrations\AddBmgRotationPermissions::catalog();
}
function bmgSnapshot($db, ?array $original = null): array
{
    $out = engagementSnapshot($db, $original);
    foreach ($out as $table => &$data) {
        // Include charset, collation, comments, position and generated-column metadata too.
        $data['full_schema'] = $db->query('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table])->getResultArray();
        foreach ($data['full_schema'] as &$column) { unset($column['TABLE_SCHEMA'], $column['TABLE_CATALOG']); }
        unset($column);
    }
    return $out;
}
function bmgPreflight($db): void
{
    foreach (bmgTables() as $table) {
        if ($db->tableExists($table)) { throw new RuntimeException('Partial rotation schema; explicit recovery required.'); }
    }
    foreach (bmgColumns() as $column => $type) {
        if ($db->fieldExists($column, 'bmg_devices')) { throw new RuntimeException('Partial device schema; explicit recovery required.'); }
    }
    $runner = new \CodeIgniter\Database\MigrationRunner(config('Migrations'), $db);
    $files = array_filter($runner->findMigrations(), static fn ($m) => $m->namespace === 'App');
    $history = $db->table(config('Migrations')->table)->get()->getResultArray();
    // The native backup includes nine historical tests-group entries. Validate their
    // exact identities independently; they do not satisfy default-group prerequisites.
    $identities = [];
    foreach ($files as $m) { $identities[$m->version] = $m->class; }
    $seen = [];
    foreach ($history as $row) {
        $version = $row['version'];
        $group = $row['group'];
        if ($row['namespace'] !== 'App' || ($identities[$version] ?? null) !== $row['class']
            || !in_array($group, ['default', 'tests'], true)
            || ($group === 'tests' && !preg_match('/\A2026-10-14-00000[1-9]\z/D', $version))
            || isset($seen[$group][$version]) || isset(bmgTargets()[$version])) {
            throw new RuntimeException('Suspicious migration history.');
        }
        $seen[$group][$version] = true;
    }
    if (count($seen['tests'] ?? []) !== 9) { throw new RuntimeException('Exact legacy tests-group history required.'); }
    $pending = [];
    foreach ($files as $m) {
        $matches = array_filter($history, static fn ($r) => $r['group'] === 'default'
            && (preg_replace('/[^0-9]/', '', $r['version']) === str_replace('-', '', $m->version) || $r['class'] === $m->class));
        if (!$matches) { $pending[$m->version] = substr($m->class, strlen('App\\Database\\Migrations\\')); continue; }
        $row = array_values($matches)[0];
        if (count($matches) !== 1 || $row['version'] !== $m->version || $row['class'] !== $m->class || $row['namespace'] !== 'App' || $row['group'] !== 'default') {
            throw new RuntimeException('Suspicious migration history.');
        }
    }
    ksort($pending);
    if ($pending !== bmgTargets() || count($history) !== count($files) - 2 + 9) { throw new RuntimeException('Only the exact two BMG migrations may be pending.'); }
    foreach (bmgCatalog() as $code => $name) {
        $rows = $db->table('permissions')->where('code', $code)->get()->getResultArray();
        if (count($rows) > 1 || ($rows && ($rows[0]['name'] !== $name || $rows[0]['module'] !== 'facilities'))) {
            throw new RuntimeException('Incompatible permission catalog.');
        }
    }
}
function bmgApply($db): void
{
    $config = clone config('Migrations');
    if (property_exists($config, 'lock')) { $config->lock = false; }
    $runner = new class($config, $db) extends \CodeIgniter\Database\MigrationRunner {
        public function findMigrations(): array
        {
            $out = [];
            foreach (bmgTargets() as $version => $class) {
                $m = $this->migrationFromFile(APPPATH . 'Database/Migrations/' . $version . '_' . $class . '.php', 'App');
                if (!$m || $m->version !== $version || $m->class !== 'App\\Database\\Migrations\\' . $class || $m->namespace !== 'App') {
                    throw new RuntimeException('Exact BMG migration identity mismatch.');
                }
                $out[$m->uid] = $m;
            }
            return $out;
        }
        public function regress(int $targetBatch = 0, ?string $group = null) { throw new RuntimeException('Automatic rollback prohibited.'); }
    };
    if (!$runner->latest('default')) { throw new RuntimeException('BMG migrations failed.'); }
    $db->resetDataCache();
}
function bmgVerify($db, array $before): array
{
    $tables = engagementTables($db);
    if (array_diff(array_keys($before), $tables) || array_diff($tables, array_keys($before), bmgTables()) || array_diff(bmgTables(), $tables)) {
        throw new RuntimeException('Unexpected table set.');
    }
    $after = bmgSnapshot($db, $before);
    $allowed = [];
    $history = config('Migrations')->table;
    foreach (bmgTargets() as $version => $class) {
        $rows = $db->table($history)->where(['version' => $version, 'class' => 'App\\Database\\Migrations\\' . $class, 'namespace' => 'App', 'group' => 'default'])->get()->getResultArray();
        if (count($rows) !== 1) { throw new RuntimeException('Exact BMG history required.'); }
        $allowed[$history][hash('sha256', serialize($rows[0]))] = 1;
    }
    foreach (bmgCatalog() as $code => $name) {
        $rows = $db->table('permissions')->where('code', $code)->get()->getResultArray();
        if (count($rows) !== 1 || $rows[0]['module'] !== 'facilities' || $rows[0]['name'] !== $name) { throw new RuntimeException('Wrong permission metadata.'); }
        $allowed['permissions'][hash('sha256', serialize($rows[0]))] = 1;
        foreach ($code === 'facilities.configure' ? ['superadmin', 'bmg_admin'] : [] as $role) {
            $rows = $db->table('role_permissions')->where(['role' => $role, 'permission_id' => $rows[0]['id']])->get()->getResultArray();
            if (count($rows) !== 1) { throw new RuntimeException('Missing configure grant.'); }
            $allowed['role_permissions'][hash('sha256', serialize($rows[0]))] = 1;
            // Keep the permission identity independent of the grant row's shape.
            $rows = $db->table('permissions')->where('code', $code)->get()->getResultArray();
        }
    }
    $proof = [];
    foreach ($before as $table => $original) {
        $current = $after[$table];
        foreach (['indexes', 'foreign_keys'] as $kind) {
            if ($original[$kind] !== $current[$kind]) { throw new RuntimeException('Original keys changed.'); }
        }
        $oldSchema = array_column($original['full_schema'], null, 'COLUMN_NAME');
        $newSchema = array_column($current['full_schema'], null, 'COLUMN_NAME');
        foreach ($oldSchema as $name => $definition) {
            if (($newSchema[$name] ?? null) !== $definition) { throw new RuntimeException('Original column metadata changed.'); }
        }
        $extra = array_keys(array_diff_key($newSchema, $oldSchema));
        $expected = $table === 'bmg_devices' ? array_keys(bmgColumns()) : [];
        if (array_diff($extra, $expected) || array_diff($expected, $extra)) { throw new RuntimeException('Unexpected columns.'); }
        $extra = $current['hashes'];
        foreach ($original['hashes'] as $hash => $count) {
            if (($extra[$hash] ?? 0) < $count) { throw new RuntimeException('Original row fingerprint changed.'); }
            $extra[$hash] -= $count;
            if (!$extra[$hash]) { unset($extra[$hash]); }
        }
        foreach ($extra as $hash => $count) {
            if (($allowed[$table][$hash] ?? 0) !== $count) { throw new RuntimeException('Unexpected row addition.'); }
        }
        if ($table === $history && array_sum($extra) !== 2) { throw new RuntimeException('History must be preserved +2.'); }
        $proof[$table] = ['rows_before' => array_sum($original['hashes']), 'rows_after' => array_sum($current['hashes']), 'added' => array_sum($extra), 'original_sha256' => hash('sha256', serialize($original['hashes']))];
    }
    foreach (bmgColumns() as $column => $type) {
        $meta = array_column($after['bmg_devices']['full_schema'], null, 'COLUMN_NAME')[$column];
        $actualType = preg_replace('/(tinyint|int)\(\d+\)/', '$1', strtolower($meta['COLUMN_TYPE']));
        if ($actualType !== $type || $meta['IS_NULLABLE'] !== 'YES' || !in_array($meta['COLUMN_DEFAULT'], [null, 'NULL'], true)
            || $db->table('bmg_devices')->where($column . ' IS NOT NULL', null, false)->countAllResults() !== 0) {
            throw new RuntimeException('New device fields must be nullable and unbackfilled.');
        }
    }
    foreach (bmgTables() as $table) {
        if ($db->table($table)->countAllResults() !== 0 || strtoupper((string) $db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])->getRow('ENGINE')) !== 'INNODB') {
            throw new RuntimeException('Rotation storage must be empty InnoDB.');
        }
    }
    return $proof;
}

if (defined('SYNAPSE_BMG_LIBRARY_ONLY') && SYNAPSE_BMG_LIBRARY_ONLY === true) { return; }

// Stop ALL writers, create a fresh backup and rehearse that exact SHA before apply.
// php writable/inventory-development-backup.php bmg
// python writable/bmg-development-rehearse.py --backup-stem=writable/backups/<stem>
// Only after parent review and explicit approval, with facilities/device API maintenance gates installed:
// php writable/bmg-development-migrate.php --apply --backup=<stem>.json --restore-rehearsed --writes-stopped --parent-approved
// Recovery: --verify-only --evidence=<bmg_fingerprints_*.json>, never reapply partial DDL.
$options = getopt('', ['apply', 'verify-only', 'evidence:', 'backup:', 'restore-rehearsed', 'writes-stopped', 'parent-approved']);
$verifyOnly = isset($options['verify-only']);
if ((!isset($options['apply']) && !$verifyOnly) || (isset($options['apply']) && $verifyOnly)
    || ($verifyOnly && !is_string($options['evidence'] ?? null))
    || !isset($options['backup'], $options['restore-rehearsed'], $options['writes-stopped'], $options['parent-approved'])
    || !is_string($options['backup'])) {
    fwrite(STDERR, "Required: --apply --backup=<verified JSON under writable/backups> --restore-rehearsed --writes-stopped --parent-approved\n");
    exit(1);
}

$stage = 'backup verification';
$db = null;
$locked = false;
$flag = __DIR__ . '/bmg-maintenance.flag';
$lockName = 'humus_db:bmg-deployment';
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
    if (!preg_match('/\Ahumus_db_before_bmg_.+\.sql\z/D', basename($dump))
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
    foreach (glob(__DIR__ . '/isolated-inventory-db/bmg-restore-result-*.json') ?: [] as $rehearsalPath) {
        $rehearsal = json_decode(file_get_contents($rehearsalPath), true, 512, JSON_THROW_ON_ERROR);
        $stages = $rehearsal['stages'] ?? [];
        if (($rehearsal['success'] ?? false) === true && ($rehearsal['graceful_shutdown'] ?? false) === true
            && ($rehearsal['job_closed'] ?? false) === true && ($rehearsal['owned_children_exited'] ?? false) === true
            && ($rehearsal['backup_sha256'] ?? null) === $backup['sha256']
            && ($rehearsal['migration_passed'] ?? false) === true
            && ($rehearsal['restore_verified'] ?? false) === true
            && ($rehearsal['schema'] ?? null) === 'humus_bmg_development_rehearsal'
            && ($rehearsal['port'] ?? null) === 33307
                        && ($rehearsal['sources_sha256'] ?? null) === bmgSourceHashes()) {
            $rehearsalVerified = true;
            break;
        }
    }
    if (!$rehearsalVerified) { throw new RuntimeException('Successful clean bmg restore rehearsal for this exact backup SHA required.'); }

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
    foreach (glob(__DIR__ . '/*-maintenance.flag') ?: [] as $otherFlag) {
        if (basename($otherFlag) !== 'bmg-maintenance.flag') { throw new RuntimeException('Another maintenance operation is active.'); }
    }
    // Never adopt/remove a stale flag automatically, even when history says the migration ran.
    if ($verifyOnly && !is_file($flag)) { throw new RuntimeException('Verification requires the retained maintenance flag.'); }
    $handle = $verifyOnly ? null : @fopen($flag, 'x');
    if (!$verifyOnly && $handle === false) { throw new RuntimeException('Maintenance flag exists or cannot be created; manual review required.'); }

    if (!$verifyOnly) try {
        $message = "BMG maintenance. If deployment failed, this flag is intentionally retained. Review snapshots and migration state before manually clearing; never auto-rollback.\n";
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
        if (!preg_match('/\Abmg_fingerprints_[a-zA-Z0-9_]+\.json\z/D', basename($snapshotPath))) {
            throw new RuntimeException('Invalid bmg fingerprint evidence path.');
        }
        $evidence = json_decode(file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
        if (($evidence['backup_sha256'] ?? null) !== $backup['sha256'] || !is_array($evidence['before'] ?? null)) {
            throw new RuntimeException('Original fingerprint evidence required for this backup SHA.');
        }
        $before = $evidence['before'];
    } else {
        bmgPreflight($db);
        $before = bmgSnapshot($db);
        if (($rehearsal['before_sha256'] ?? null) !== hash('sha256', json_encode($before, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Main schema/data drifted from the exact restored backup; take a new backup and rehearse again.');
        }
        $snapshotPath = __DIR__ . '/backups/bmg_fingerprints_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
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
        $stage = 'exact bmg migrations (forward only)';
        bmgApply($db);
    }
    $stage = 'bmg preservation verification';
    $assertIdle();
        $counts = bmgVerify($db, $before);
        $assertIdle();
    $save(['status' => 'verified', 'before' => $before, 'after' => bmgSnapshot($db, $before), 'counts' => $counts]);
    if (!unlink($flag)) { throw new RuntimeException('Verified, but maintenance flag removal failed.'); }
    echo json_encode(['status' => 'verified', 'counts' => $counts, 'evidence' => $snapshotPath,
        'backup_sha256' => $backup['sha256'], 'history' => 'preserved+2', 'new_nullable_columns' => 8,
        'notifications_created' => 0, 'published_records_created' => 0, 'maintenance' => false,
        'restore_rehearsal' => 'sha256_verified', 'automatic_rollback' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    // Best-effort read-only evidence for partial DDL/backfill failures; never repair or roll back here.
    if (isset($before, $save)) {
        try { $save(['status' => 'failed_unverified', 'before' => $before, 'after' => bmgSnapshot($db, $before)]); }
        catch (Throwable) { /* Keep the already-written before evidence and maintenance flag. */ }
    }
    // Database exceptions and migration preflight messages can contain personal data or SQL values.
    fwrite(STDERR, json_encode(['status' => 'failed', 'stage' => $stage, 'exception_type' => get_class($e), 'runtime_error' => $e instanceof \Error ? $e->getMessage() : null,
            'failed_connection_guards' => isset($connectionGuards) ? array_keys(array_filter($connectionGuards, static fn (bool $passed): bool => !$passed)) : [],
            'maintenance_retained' => is_file($flag),
        'action' => 'Stop writers. Review local fingerprint evidence and migration state; repair explicitly. No automatic rollback. Clear a retained bmg-maintenance.flag manually only after verification.'], JSON_THROW_ON_ERROR) . "\n");

    $failed = true;
} finally {
    if ($locked && $db !== null) {
        try { $db->query('SELECT RELEASE_LOCK(?)', [$lockName]); } catch (Throwable) { /* Closing releases the session lock. */ }
    }
    if ($db !== null) { $db->close(); }
}
if (isset($failed)) { exit(1); }

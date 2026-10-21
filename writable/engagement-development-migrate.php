<?php

declare(strict_types=1);

// Shared, read-only verification is also used by the disposable restore bootstrap.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function engagementTargets(): array
{
    return ['2026-10-17-000001' => 'CreateAnnouncements', '2026-10-17-000002' => 'CreateSurveys',
        '2026-10-17-000003' => 'AddEngagementNotifications'];
}

function engagementGrants(): array
{
    return ['clinic.announcements.manage' => ['clinic_admin', 'superadmin'],
        'guidance.announcements.manage' => ['guidance_admin', 'guidance_supervisor', 'superadmin'],
        'guidance.surveys.manage' => ['guidance_admin', 'guidance_supervisor', 'superadmin'],
        'guidance.responses.read' => ['guidance_admin', 'guidance_supervisor', 'superadmin']];
}

function engagementTables($db): array
{
    return array_column($db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->getResultArray(), 'TABLE_NAME');
}

function engagementSnapshot($db, ?array $original = null): array
{
    $out = [];
    foreach ($original === null ? engagementTables($db) : array_keys($original) as $table) {
        $fields = $db->query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table])->getResultArray();
        if (!$fields) { throw new RuntimeException('Original table missing: ' . $table); }
        $columns = $original[$table]['columns'] ?? array_column($fields, 'COLUMN_NAME');
        $hashes = [];
        $offset = 0;
        do {
            if ($offset >= 200000) { throw new RuntimeException('Snapshot safety bound exceeded.'); }
            $builder = $db->table($table)->select(implode(',', array_map($db->protectIdentifiers(...), $columns)), false);
            // A stable total ordering is needed for paginated fingerprints, including composite-key tables.
            foreach ($columns as $column) { $builder->orderBy($column); }
            $result = $builder->get(500, $offset);
            if ($result === false) { throw new RuntimeException('Fingerprint query failed.'); }
            $rows = $result->getResultArray();
            foreach ($rows as $row) {
                $hash = hash('sha256', serialize($row));
                $hashes[$hash] = ($hashes[$hash] ?? 0) + 1;
            }
            $offset += count($rows);
        } while (count($rows) === 500);
        ksort($hashes);
        $indexes = $db->query('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table])->getResultArray();
        $keys = $db->query('SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', [$table])->getResultArray();
        $out[$table] = ['columns' => $columns, 'schema' => $fields, 'indexes' => $indexes, 'foreign_keys' => $keys, 'hashes' => $hashes];
    }
    return $out;
}

function engagementPreflight($db): void
{
    $tables = engagementTables($db);
    if (array_intersect(['announcements', 'announcements_reads', 'surveys', 'survey_responses'], $tables)) {
        throw new RuntimeException('Expected a backup before all engagement migrations; never adopt partial DDL.');
    }
    foreach (['users', 'people', 'permissions', 'role_permissions', 'notifications', config('Migrations')->table] as $table) {
        if (!in_array($table, $tables, true)) { throw new RuntimeException('Missing prerequisite: ' . $table); }
    }
    foreach ($db->table(config('Migrations')->table)->get()->getResultArray() as $row) {
        foreach (engagementTargets() as $version => $class) {
            if (preg_replace('/[^0-9]/', '', $row['version']) === str_replace('-', '', $version)
                || $row['class'] === 'App\\Database\\Migrations\\' . $class) {
                throw new RuntimeException('Engagement migration already recorded; manual review required.');
            }
        }
    }
}

function engagementApply($db): void
{
    $config = clone config('Migrations');
    if (property_exists($config, 'lock')) { $config->lock = false; }
    $runner = new class($config, $db) extends \CodeIgniter\Database\MigrationRunner {
        public function findMigrations(): array
        {
            $out = [];
            foreach (engagementTargets() as $version => $class) {
                $m = $this->migrationFromFile(APPPATH . 'Database/Migrations/' . $version . '_' . $class . '.php', 'App');
                if (!$m || $m->version !== $version || $m->class !== 'App\\Database\\Migrations\\' . $class
                    || $m->namespace !== 'App') { throw new RuntimeException('Exact engagement migration identity mismatch.'); }
                $out[$m->uid] = $m;
            }
            return $out;
        }
        public function regress(int $targetBatch = 0, ?string $group = null)
        {
            throw new RuntimeException('Automatic rollback is prohibited.');
        }
    };
    if (count($runner->findMigrations()) !== 3 || !$runner->latest('default')) {
        throw new RuntimeException('Exact engagement migrations did not complete.');
    }
    $db->resetDataCache();
}

function engagementVerify($db, array $before): array
{
    $newTables = ['announcements', 'announcements_reads', 'surveys', 'survey_responses'];
    $tables = engagementTables($db);
    if (array_diff(array_keys($before), $tables) || array_diff($tables, array_keys($before), $newTables)
        || array_diff($newTables, $tables)) { throw new RuntimeException('Unexpected engagement table set.'); }
    $after = engagementSnapshot($db, $before);
    $history = config('Migrations')->table;
    $grants = engagementGrants();
    $allowed = ['permissions' => [], 'role_permissions' => [], $history => []];
    foreach ($db->table('permissions')->get()->getResultArray() as $row) {
        if (!isset($grants[$row['code']])) { continue; }
        if ($row['module'] !== explode('.', $row['code'])[0]) { throw new RuntimeException('Wrong engagement permission module.'); }
        $allowed['permissions'][hash('sha256', serialize($row))] = 1;
        foreach ($grants[$row['code']] as $role) {
            $grant = $db->table('role_permissions')->where('role', $role)->where('permission_id', $row['id'])->get()->getRowArray();
            if (!$grant) { throw new RuntimeException('Missing expected engagement grant.'); }
            $allowed['role_permissions'][hash('sha256', serialize($grant))] = 1;
        }
        unset($grants[$row['code']]);
    }
    if ($grants) { throw new RuntimeException('Missing engagement permission catalog entries.'); }
    $recorded = [];
    foreach ($db->table($history)->get()->getResultArray() as $row) {
        $class = engagementTargets()[$row['version']] ?? null;
        if ($class === null) { continue; }
        if ($row['class'] !== 'App\\Database\\Migrations\\' . $class || $row['namespace'] !== 'App'
            || $row['group'] !== 'default' || isset($recorded[$row['version']])) {
            throw new RuntimeException('Wrong engagement migration history.');
        }
        $recorded[$row['version']] = true;
        $allowed[$history][hash('sha256', serialize($row))] = 1;
    }
    if (count($recorded) !== 3) { throw new RuntimeException('Expected exactly three engagement history records.'); }
    $proof = [];
    foreach ($before as $table => $original) {
        $current = $after[$table];
        foreach (['indexes', 'foreign_keys'] as $kind) {
            foreach ($original[$kind] as $definition) {
                if (!in_array($definition, $current[$kind], true)) { throw new RuntimeException('Original ' . $kind . ' changed: ' . $table); }
            }
        }
        $oldSchema = array_column($original['schema'], null, 'COLUMN_NAME');
        $newSchema = array_column($current['schema'], null, 'COLUMN_NAME');
        foreach ($oldSchema as $name => $column) {
            $actual = $newSchema[$name] ?? null;
            if ($table === 'notifications' && $name === 'type' && $actual !== null) {
                $oldType = $column['COLUMN_TYPE'];
                $expected = substr($oldType, 0, -1) . ",'announcement_published','survey_published')";
                if ($actual['COLUMN_TYPE'] !== $expected) { throw new RuntimeException('Notification enum must be strictly additive.'); }
                $actual['COLUMN_TYPE'] = $oldType;
            }
            if ($actual !== $column) { throw new RuntimeException('Original column definition changed: ' . $table . '.' . $name); }
        }
        $additions = array_diff(array_keys($newSchema), array_keys($oldSchema));
        $expected = $table === 'notifications' ? ['announcement_id', 'survey_id'] : [];
        if (array_diff($additions, $expected) || array_diff($expected, $additions)) {
            throw new RuntimeException('Unexpected original-table columns: ' . $table);
        }
        $extra = $current['hashes'];
        foreach ($original['hashes'] as $hash => $count) {
            if (($extra[$hash] ?? 0) < $count) { throw new RuntimeException('Original row fingerprint changed: ' . $table); }
            $extra[$hash] -= $count;
            if ($extra[$hash] === 0) { unset($extra[$hash]); }
        }
        foreach ($extra as $hash => $count) {
            if (($allowed[$table][$hash] ?? 0) !== $count) { throw new RuntimeException('Unexpected insertion: ' . $table); }
        }
        if ($table === $history && array_sum($extra) !== 3) { throw new RuntimeException('History must be preserved +3.'); }
        $proof[$table] = ['rows_before' => array_sum($original['hashes']), 'rows_after' => array_sum($current['hashes']),
            'added' => array_sum($extra), 'original_sha256' => hash('sha256', serialize($original['hashes']))];
    }
    foreach ($newTables as $table) {
        if ($db->table($table)->countAllResults() !== 0) { throw new RuntimeException('Engagement tables must initially be empty: ' . $table); }
        $engine = $db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])->getRow('ENGINE');
        if (strtoupper($engine ?? '') !== 'INNODB') { throw new RuntimeException('Engagement tables require InnoDB.'); }
    }
    $expectedIndexes = [
        'announcements' => [['tenant_id', 'id'], ['tenant_id', 'unit', 'id'], ['tenant_id', 'status', 'id']],
        'announcements_reads' => [['tenant_id', 'announcement_id', 'person_id'], ['tenant_id', 'person_id', 'announcement_id']],
        'surveys' => [['tenant_id', 'id'], ['tenant_id', 'status', 'id']],
        'survey_responses' => [['tenant_id', 'survey_id', 'person_id'], ['tenant_id', 'survey_id', 'id']],
    ];
    foreach ($expectedIndexes as $table => $definitions) {
        $actual = [];
        foreach ($db->query('SELECT INDEX_NAME,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table])->getResultArray() as $part) {
            if ($part['SUB_PART'] !== null) { throw new RuntimeException('Unexpected prefix index on engagement table.'); }
            $actual[$part['INDEX_NAME']][] = $part['COLUMN_NAME'];
        }
        foreach ($definitions as $definition) {
            if (!in_array($definition, $actual, true)) { throw new RuntimeException('Missing engagement listing/identity index: ' . $table); }
        }
    }
    foreach (['announcement_id' => 'announcements', 'survey_id' => 'surveys'] as $column => $parent) {
        $meta = $db->query("SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME=?", [$column])->getRowArray();
        if (!$meta || preg_replace('/bigint\([0-9]+\)/', 'bigint', strtolower($meta['COLUMN_TYPE'])) !== 'bigint unsigned'
            || $meta['IS_NULLABLE'] !== 'YES' || !in_array($meta['COLUMN_DEFAULT'], [null, 'NULL'], true)
            || $db->table('notifications')->where($column . ' IS NOT NULL', null, false)->countAllResults() !== 0) {
            throw new RuntimeException('Expected nullable unpopulated notification reference.');
        }
        $notificationIndexes = [];
        foreach ($db->query("SELECT INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' ORDER BY INDEX_NAME,SEQ_IN_INDEX")->getResultArray() as $part) {
            $notificationIndexes[$part['INDEX_NAME']][] = $part['COLUMN_NAME'];
        }
        if (!array_filter($notificationIndexes, static fn($parts) => array_slice($parts, 0, 2) === ['tenant_id', $column])) {
            throw new RuntimeException('Missing engagement notification reference index.');
        }
        $keys = $db->query("SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='notifications' AND k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION", [$parent])->getResultArray();
        if (array_column($keys, 'COLUMN_NAME') !== ['tenant_id', $column]
            || array_column($keys, 'REFERENCED_COLUMN_NAME') !== ['tenant_id', 'id']) { throw new RuntimeException('Missing tenant-bound engagement notification FK.'); }
        foreach ($keys as $key) {
            if ($key['DELETE_RULE'] !== 'RESTRICT' || $key['UPDATE_RULE'] !== 'RESTRICT') { throw new RuntimeException('Unsafe engagement FK rule.'); }
        }
    }
    foreach (['users', 'people'] as $table) {
        foreach (['announcement', 'survey'] as $kind) {
            $index = $db->query('SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX', [$table, $kind . '_' . $table . '_tenant'])->getResultArray();
            if (array_column($index, 'COLUMN_NAME') !== ['tenant_id', 'id'] || array_map('intval', array_column($index, 'NON_UNIQUE')) !== [0, 0]) {
                throw new RuntimeException('Missing engagement parent index.');
            }
        }
    }
    return $proof;
}

if (defined('SYNAPSE_ENGAGEMENT_LIBRARY_ONLY') && SYNAPSE_ENGAGEMENT_LIBRARY_ONLY === true) { return; }

// Stop ALL writers, create a fresh backup and rehearse that exact SHA before apply.
// php writable/inventory-development-backup.php engagement
// python writable/isolated-inventory-harness.py --validate-engagement --backup-stem=writable/backups/<stem>
// php writable/engagement-development-migrate.php --apply --backup=<stem>.json --restore-rehearsed --writes-stopped
// Recovery: --verify-only --evidence=<engagement_fingerprints_*.json>, never reapply partial DDL.
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
$lockName = 'humus_db:engagement-deployment';
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
    if (!preg_match('/\Ahumus_db_before_engagement_.+\.sql\z/D', basename($dump))
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
    foreach (glob(__DIR__ . '/isolated-inventory-db/engagement-restore-result-*.json') ?: [] as $rehearsalPath) {
        $rehearsal = json_decode(file_get_contents($rehearsalPath), true, 512, JSON_THROW_ON_ERROR);
        $stages = $rehearsal['stages'] ?? [];
        if (($rehearsal['success'] ?? false) === true && ($rehearsal['graceful_shutdown'] ?? false) === true
            && ($rehearsal['job_closed'] ?? false) === true && ($rehearsal['owned_children_exited'] ?? false) === true
            && array_column($stages, 'name') === ['preflight_ok', 'initialized', 'isolated_server_identity_verified', 'restore_verified', 'engagement_restore_migration_passed']
            && ($stages[0]['backup_sha256'] ?? null) === $backup['sha256']
            && ($stages[4]['backup_sha256'] ?? null) === $backup['sha256']) {
            $rehearsalVerified = true;
            break;
        }
    }
    if (!$rehearsalVerified) { throw new RuntimeException('Successful clean engagement restore rehearsal for this exact backup SHA required.'); }

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
        if (!preg_match('/\Aengagement_fingerprints_[a-zA-Z0-9_]+\.json\z/D', basename($snapshotPath))) {
            throw new RuntimeException('Invalid engagement fingerprint evidence path.');
        }
        $evidence = json_decode(file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
        if (($evidence['backup_sha256'] ?? null) !== $backup['sha256'] || !is_array($evidence['before'] ?? null)) {
            throw new RuntimeException('Original fingerprint evidence required for this backup SHA.');
        }
        $before = $evidence['before'];
    } else {
        engagementPreflight($db);
        $before = engagementSnapshot($db);
        $snapshotPath = __DIR__ . '/backups/engagement_fingerprints_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
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
        $stage = 'exact engagement migrations (forward only)';
        engagementApply($db);
    }
    $stage = 'engagement preservation verification';
    $counts = engagementVerify($db, $before);
    $save(['status' => 'verified', 'before' => $before, 'after' => engagementSnapshot($db, $before), 'counts' => $counts]);
    if (!unlink($flag)) { throw new RuntimeException('Verified, but maintenance flag removal failed.'); }
    echo json_encode(['status' => 'verified', 'counts' => $counts, 'evidence' => $snapshotPath,
        'backup_sha256' => $backup['sha256'], 'history' => 'preserved+3', 'new_empty_tables' => 4,
        'notifications_created' => 0, 'published_records_created' => 0, 'maintenance' => false,
        'restore_rehearsal' => 'sha256_verified', 'automatic_rollback' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    // Best-effort read-only evidence for partial DDL/backfill failures; never repair or roll back here.
    if (isset($before, $save)) {
        try { $save(['status' => 'failed_unverified', 'before' => $before, 'after' => engagementSnapshot($db, $before)]); }
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

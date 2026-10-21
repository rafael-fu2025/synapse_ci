<?php
// Disposable-instance bootstrap. Never use outside the bounded local harness.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { throw new RuntimeException('CLI harness required'); }
$mode = getenv('SYNAPSE_ISOLATED_MODE');
$restoreStaff = getenv('SYNAPSE_RESTORE_STAFF') === '1';
$restoreNotifications = getenv('SYNAPSE_RESTORE_NOTIFICATIONS') === '1';
$restoreReferrals = getenv('SYNAPSE_RESTORE_REFERRALS') === '1';
$restoreAudience = getenv('SYNAPSE_RESTORE_AUDIENCE') === '1';
$restoreEngagement = getenv('SYNAPSE_RESTORE_ENGAGEMENT') === '1';
if (($restoreStaff || $restoreNotifications || $restoreReferrals || $restoreEngagement || $restoreAudience) && $mode !== 'restore') { throw new RuntimeException('Migration rehearsal requires restore mode'); }
if ((int)$restoreStaff + (int)$restoreNotifications + (int)$restoreReferrals + (int)$restoreEngagement + (int)$restoreAudience > 1) { throw new RuntimeException('Choose only one migration rehearsal'); }
putenv('FUMIS_ENABLED=false');
$_ENV['FUMIS_ENABLED'] = $_SERVER['FUMIS_ENABLED'] = 'false';
if (!in_array($mode, ['restore', 'tests'], true)) { throw new RuntimeException('Harness mode required'); }
$schema = $mode === 'restore' ? 'humus_inventory_restore_test' : 'humus_inventory_service_test';
$values = ['CI_ENVIRONMENT' => 'testing'];
foreach (['default', 'tests'] as $group) {
    $environmentSchema = $mode === 'tests' && $group === 'default' ? 'humus_inventory_unused_test' : $schema;
    foreach (['DSN'=>'', 'hostname'=>'127.0.0.1', 'port'=>'33307', 'username'=>'root', 'password'=>'', 'database'=>$environmentSchema, 'DBDriver'=>'MySQLi', 'DBPrefix'=>'', 'pConnect'=>'false'] as $key=>$value) {
        $values['database.' . $group . '.' . $key] = $value;
    }
}
foreach ($values as $key=>$value) { putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value; }
require dirname(__DIR__) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$config = config('Database');
foreach (['default', 'tests'] as $group) {
    $config->$group = array_replace($config->$group, ['DSN'=>'', 'hostname'=>'127.0.0.1', 'port'=>33307, 'username'=>'root', 'password'=>'', 'database'=>$schema, 'DBDriver'=>'MySQLi', 'DBPrefix'=>'', 'pConnect'=>false, 'failover'=>[], 'strictOn'=>true]);
}
if ($mode === 'tests') { $config->default['database'] = 'humus_inventory_unused_test'; }
$config->defaultGroup = 'tests';
$db = \Config\Database::connect('tests');
$id = $db->query('SELECT @@datadir AS dir, @@port AS port, DATABASE() AS name')->getRowArray();
$expected = str_replace('\\', '/', __DIR__ . '/isolated-inventory-db');
if (strcasecmp(rtrim(str_replace('\\','/', $id['dir']), '/'), $expected) !== 0 || (int)$id['port'] !== 33307 || $id['name'] !== $schema) {
    throw new RuntimeException('Isolated instance identity mismatch');
}
$db->query('SET SESSION innodb_lock_wait_timeout=10');
if ($mode === 'restore' && $restoreAudience) {
    define('SYNAPSE_AUDIENCE_LIBRARY_ONLY', true);
    require __DIR__ . '/audience-development-migrate.php';
    audiencePreflight($db);
    $before = audienceSnapshot($db);
    audienceApply($db);
    $proof = audienceVerify($db, $before);
    // Re-entry must accept its own metadata without changing rows or history.
    (new \App\Database\Migrations\AddStudentAudienceTargeting(\Config\Database::forge('tests')))->up();
    if (audienceVerify($db, $before) !== $proof) { throw new RuntimeException('Audience restart changed preservation proof.'); }
    echo 'AUDIENCE_RESTORE_MIGRATION_OK ' . json_encode([
        'migration' => 'App2026-10-17-000004', 'history' => 'preserved+1',
        'original_tables' => count($proof), 'counts' => $proof,
        'fingerprints_sha256' => hash('sha256', serialize($proof)),
        'new_nullable_columns' => 6, 'all_new_fields_null' => true,
        'permissions_and_grants' => 'unchanged', 'notifications_created' => 0,
        'published_records_created' => 0, 'historical_backfill' => false, 'restart_verified' => true,
    ], JSON_THROW_ON_ERROR) . "
";
} elseif ($mode === 'restore' && $restoreEngagement) {
    define('SYNAPSE_ENGAGEMENT_LIBRARY_ONLY', true);
    require __DIR__ . '/engagement-development-migrate.php';
    engagementPreflight($db);
    $before = engagementSnapshot($db);
    engagementApply($db);
    $proof = engagementVerify($db, $before);
    echo 'ENGAGEMENT_RESTORE_MIGRATION_OK ' . json_encode([
        'migrations' => array_map(static fn($version) => 'App' . $version, array_keys(engagementTargets())),
        'history' => 'preserved+3', 'original_tables' => count($proof),
        'fingerprints_sha256' => hash('sha256', serialize($proof)), 'counts' => $proof,
        'permissions' => 'additive_expected_catalog_and_default_grants_only', 'new_empty_tables' => 4,
        'notifications_created' => 0, 'published_records_created' => 0, 'historical_backfill' => false,
    ], JSON_THROW_ON_ERROR) . "\n";
} elseif ($mode === 'restore' && $restoreReferrals) {
    final class IsolatedReferralsRunner extends \CodeIgniter\Database\MigrationRunner {
        public function findMigrations(): array {
            return array_filter(parent::findMigrations(), static fn($m) => $m->namespace === 'App' && $m->version === '2026-10-16-000003');
        }
    }
    if (!(new \App\Services\Clinic\InventoryService($db))->isInstalled()) {
        throw new RuntimeException('Referrals restore requires installed current inventory');
    }
    $historyTable = config('Migrations')->table;
    $historyBefore = $db->table($historyTable)->orderBy('id')->get()->getResultArray();
    foreach (['2026-10-16-000001' => 'AddDepartmentStaffAssignments', '2026-10-16-000002' => 'CreateNotifications'] as $version => $class) {
        $installed = array_values(array_filter($historyBefore, static fn($row) => $row['namespace'] === 'App' && $row['version'] === $version));
        if (count($installed) !== 1 || $installed[0]['class'] !== 'App\\Database\\Migrations\\' . $class) {
            throw new RuntimeException('Referrals restore requires prerequisite migration ' . $version);
        }
    }
    foreach ($historyBefore as $row) {
        if ($row['namespace'] === 'App' && $row['version'] === '2026-10-16-000003') {
            throw new RuntimeException('Expected backup before referral workflow migration');
        }
    }
    $tableNames = static fn(): array => array_column($db->query('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->getResultArray(), 'name');
    $tablesBefore = $tableNames();
    if (array_diff(['department_staff', 'notifications', 'referrals'], $tablesBefore) !== []) {
        throw new RuntimeException('Referrals restore requires staff, notifications and legacy referrals tables');
    }
    $fingerprint = static fn(array $row): string => hash('sha256', serialize($row));
    $snapshots = [];
    // Freeze every original column/ID, including notification read state and legacy
    // referral fields. Only additive nullable columns and one history row may appear.
    foreach ($tablesBefore as $table) {
        if ($table === $historyTable) { continue; }
        $columns = array_column($db->query('SHOW COLUMNS FROM ' . $db->protectIdentifiers($table))->getResultArray(), 'Field');
        $hashes = array_map($fingerprint, $db->table($table)->get()->getResultArray());
        sort($hashes, SORT_STRING);
        $snapshots[$table] = ['columns' => $columns, 'hashes' => $hashes];
    }
    $notificationCount = count($snapshots['notifications']['hashes']);
    $runner = new IsolatedReferralsRunner(config('Migrations'), $db);
    $runner->setNamespace('App');
    if (count($runner->findMigrations()) !== 1 || !$runner->latest()) {
        throw new RuntimeException('Referral workflow migration did not complete');
    }
    $historyAfter = $db->table($historyTable)->orderBy('id')->get()->getResultArray();
    $newHistory = array_values(array_filter($historyAfter, static fn($row) => $row['namespace'] === 'App' && $row['version'] === '2026-10-16-000003'));
    $oldHistory = array_values(array_filter($historyAfter, static fn($row) => !($row['namespace'] === 'App' && $row['version'] === '2026-10-16-000003')));
    if ($oldHistory !== $historyBefore || count($newHistory) !== 1
        || $newHistory[0]['class'] !== 'App\\Database\\Migrations\\RebuildReferralWorkflow') {
        throw new RuntimeException('Referral workflow history mismatch or unrelated migration ran');
    }
    $tablesAfter = $tableNames();
    if (array_diff($tablesBefore, $tablesAfter) !== []
        || array_diff($tablesAfter, $tablesBefore, [$historyTable . '_lock']) !== []) {
        throw new RuntimeException('Unexpected referrals restore table set');
    }
    $proof = [];
    foreach ($snapshots as $table => $snapshot) {
        $rows = $db->table($table)->select(implode(',', array_map($db->protectIdentifiers(...), $snapshot['columns'])), false)->get()->getResultArray();
        $hashes = array_map($fingerprint, $rows);
        sort($hashes, SORT_STRING);
        if ($hashes !== $snapshot['hashes']) { throw new RuntimeException('Original column/ID fingerprints changed in ' . $table); }
        $proof[$table] = ['rows' => count($hashes), 'sha256' => $fingerprint($hashes)];
    }
    foreach (['referrals' => ['target_encounter_id', 'target_case_id', 'request_key'],
        'notifications' => ['recipient_user_id', 'referral_id']] as $table => $columns) {
        foreach ($columns as $column) {
            $meta = $db->query('SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
                [$table, $column])->getRowArray();
            if ($meta === null || $meta['IS_NULLABLE'] !== 'YES' || $meta['COLUMN_DEFAULT'] !== null && strtoupper($meta['COLUMN_DEFAULT']) !== 'NULL') {
                throw new RuntimeException('Expected nullable referral workflow column with NULL default: ' . $table . '.' . $column);
            }
            if ($db->table($table)->where($column . ' IS NOT NULL', null, false)->countAllResults() !== 0) {
                throw new RuntimeException('Historical referral workflow column backfilled: ' . $table . '.' . $column);
            }
        }
    }
    if ($db->table('notifications')->countAllResults() !== $notificationCount) {
        throw new RuntimeException('Original notification count changed');
    }
    if (!(new \App\Services\Clinic\InventoryService($db))->isInstalled()) { throw new RuntimeException('Inventory readiness changed'); }
    echo 'REFERRALS_RESTORE_MIGRATION_OK ' . json_encode(['migration' => 'App2026-10-16-000003',
        'staff_notifications_migrations' => 'present', 'history' => 'preserved+1', 'original_tables' => count($proof),
        'fingerprints_sha256' => $fingerprint($proof), 'users_roles' => 'unchanged', 'notifications' => $notificationCount,
        'notification_read_state' => 'unchanged', 'legacy_referral_fields' => 'unchanged',
        'new_nullable_columns' => 5, 'historical_backfill' => false], JSON_THROW_ON_ERROR) . "\n";
} elseif ($mode === 'restore' && $restoreNotifications) {
    final class IsolatedNotificationsRunner extends \CodeIgniter\Database\MigrationRunner {
        public function findMigrations(): array {
            return array_filter(parent::findMigrations(), static fn($m) => $m->namespace === 'App' && $m->version === '2026-10-16-000002');
        }
    }
    if (!(new \App\Services\Clinic\InventoryService($db))->isInstalled()) {
        throw new RuntimeException('Notifications restore requires installed current inventory');
    }
    $historyTable = config('Migrations')->table;
    $historyBefore = $db->table($historyTable)->orderBy('id')->get()->getResultArray();
    $staffHistory = array_values(array_filter($historyBefore, static fn($row) => $row['namespace'] === 'App' && $row['version'] === '2026-10-16-000001'));
    if (count($staffHistory) !== 1 || $staffHistory[0]['class'] !== 'App\\Database\\Migrations\\AddDepartmentStaffAssignments') {
        throw new RuntimeException('Notifications restore requires staff migration history');
    }
    foreach ($historyBefore as $row) {
        if ($row['namespace'] === 'App' && $row['version'] === '2026-10-16-000002') {
            throw new RuntimeException('Expected backup before notifications migration');
        }
    }
    $tableNames = static fn(): array => array_column($db->query('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->getResultArray(), 'name');
    $tablesBefore = $tableNames();
    if (!in_array('department_staff', $tablesBefore, true) || in_array('notifications', $tablesBefore, true)) {
        throw new RuntimeException('Expected staff schema without notifications table');
    }
    $fingerprint = static fn(array $row): string => hash('sha256', serialize($row));
    $snapshots = [];
    // No historical backfill is allowed: freeze all original rows, including staff,
    // accounts, roles, schedules, audit data and inventory readiness markers.
    foreach ($tablesBefore as $table) {
        if ($table === $historyTable) { continue; }
        $columns = array_column($db->query('SHOW COLUMNS FROM ' . $db->protectIdentifiers($table))->getResultArray(), 'Field');
        $hashes = array_map($fingerprint, $db->table($table)->get()->getResultArray());
        sort($hashes, SORT_STRING);
        $snapshots[$table] = ['columns' => $columns, 'hashes' => $hashes];
    }
    $runner = new IsolatedNotificationsRunner(config('Migrations'), $db);
    $runner->setNamespace('App');
    if (count($runner->findMigrations()) !== 1 || !$runner->latest()) {
        throw new RuntimeException('Notifications migration did not complete');
    }
    $historyAfter = $db->table($historyTable)->orderBy('id')->get()->getResultArray();
    $newHistory = array_values(array_filter($historyAfter, static fn($row) => $row['namespace'] === 'App' && $row['version'] === '2026-10-16-000002'));
    $oldHistory = array_values(array_filter($historyAfter, static fn($row) => !($row['namespace'] === 'App' && $row['version'] === '2026-10-16-000002')));
    if ($oldHistory !== $historyBefore || count($newHistory) !== 1
        || $newHistory[0]['class'] !== 'App\\Database\\Migrations\\CreateNotifications') {
        throw new RuntimeException('Notifications migration history mismatch or unrelated migration ran');
    }
    $tablesAfter = $tableNames();
    if (array_diff($tablesBefore, $tablesAfter) !== []
        || array_diff($tablesAfter, $tablesBefore, ['notifications', $historyTable . '_lock']) !== []
        || !in_array('notifications', $tablesAfter, true)) {
        throw new RuntimeException('Unexpected notifications restore table set');
    }
    $proof = [];
    foreach ($snapshots as $table => $snapshot) {
        $rows = $db->table($table)->select(implode(',', array_map($db->protectIdentifiers(...), $snapshot['columns'])), false)->get()->getResultArray();
        $hashes = array_map($fingerprint, $rows);
        sort($hashes, SORT_STRING);
        if ($hashes !== $snapshot['hashes']) { throw new RuntimeException('Original column/ID fingerprints changed in ' . $table); }
        $proof[$table] = ['rows' => count($hashes), 'sha256' => $fingerprint($hashes)];
    }
    if ($db->table('notifications')->countAllResults() !== 0) {
        throw new RuntimeException('Notifications must be initially empty; historical backfill detected');
    }
    if (!(new \App\Services\Clinic\InventoryService($db))->isInstalled()) { throw new RuntimeException('Inventory readiness changed'); }
    echo 'NOTIFICATIONS_RESTORE_MIGRATION_OK ' . json_encode(['migration' => 'App2026-10-16-000002',
        'staff_migration' => 'present', 'history' => 'preserved+1', 'original_tables' => count($proof),
        'fingerprints_sha256' => $fingerprint($proof), 'users_roles' => 'unchanged', 'notifications' => 0,
        'historical_backfill' => false], JSON_THROW_ON_ERROR) . "\n";
} elseif ($mode === 'restore' && $restoreStaff) {
    final class IsolatedStaffRunner extends \CodeIgniter\Database\MigrationRunner {
        public function findMigrations(): array {
            return array_filter(parent::findMigrations(), static fn($m) => $m->namespace === 'App' && $m->version === '2026-10-16-000001');
        }
    }
    $service = new \App\Services\Clinic\InventoryService($db);
    if (!$service->isInstalled()) { throw new RuntimeException('Staff restore requires installed current inventory'); }
    $historyTable = config('Migrations')->table;
    $historyBefore = $db->table($historyTable)->orderBy('id')->get()->getResultArray();
    foreach ($historyBefore as $row) {
        if ($row['namespace'] === 'App' && $row['version'] === '2026-10-16-000001') {
            throw new RuntimeException('Expected backup before staff migration');
        }
    }
    $fingerprint = static fn(array $row): string => hash('sha256', serialize($row));
    $snapshots = [];
    $exceptionTemplates = [];
    $scheduleTables = ['appointments', 'provider_availability', 'availability_exceptions'];
    // Freeze every existing data table, including identity, audit and inventory markers.
    // Only new exception IDs may be added; all original columns/IDs remain immutable.
    foreach ($db->listTables() as $table) {
        if (in_array($table, ['department_staff', $historyTable, $historyTable . '_lock'], true)) { continue; }
        $columns = $db->query('SHOW COLUMNS FROM ' . $db->protectIdentifiers($table))->getResultArray();
        $columns = array_column($columns, 'Field');
        $rows = $db->table($table)->get()->getResultArray();
        $hashes = [];
        foreach ($rows as $row) {
            $hashes[] = $fingerprint($row);
            if ($table === 'availability_exceptions') {
                unset($row['id']);
                $exceptionTemplates[$fingerprint($row)] = true;
            }
        }
        sort($hashes, SORT_STRING);
        $snapshots[$table] = ['columns' => $columns, 'hashes' => $hashes];
    }
    foreach ($scheduleTables as $table) {
        if (!isset($snapshots[$table])) { throw new RuntimeException('Missing legacy scheduling table'); }
    }
    $originalExceptions = $db->table('availability_exceptions')->get()->getResultArray();
    $originalExceptionIds = array_fill_keys(array_column($originalExceptions, 'id'), true);
    $runner = new IsolatedStaffRunner(config('Migrations'), $db);
    $runner->setNamespace('App');
    if (count($runner->findMigrations()) !== 1 || !$runner->latest()) { throw new RuntimeException('Staff migration did not complete'); }
    $historyAfter = $db->table($historyTable)->orderBy('id')->get()->getResultArray();
    $newHistory = array_values(array_filter($historyAfter, static fn($row) => $row['namespace'] === 'App' && $row['version'] === '2026-10-16-000001'));
    $oldHistory = array_values(array_filter($historyAfter, static fn($row) => !($row['namespace'] === 'App' && $row['version'] === '2026-10-16-000001')));
    if ($oldHistory !== $historyBefore || count($newHistory) !== 1
        || $newHistory[0]['class'] !== 'App\\Database\\Migrations\\AddDepartmentStaffAssignments') {
        throw new RuntimeException('Staff migration history mismatch or unrelated migration ran');
    }
    $proof = [];
    foreach ($snapshots as $table => $snapshot) {
        $rows = $db->table($table)->select(implode(',', array_map($db->protectIdentifiers(...), $snapshot['columns'])), false)->get()->getResultArray();
        $hashes = [];
        foreach ($rows as $row) {
            if ($table === 'availability_exceptions' && !isset($originalExceptionIds[$row['id']])) {
                unset($row['id']);
                if (!isset($exceptionTemplates[$fingerprint($row)])) { throw new RuntimeException('Exception clone changed original fields'); }
                continue;
            }
            $hashes[] = $fingerprint($row);
        }
        sort($hashes, SORT_STRING);
        if ($hashes !== $snapshot['hashes']) { throw new RuntimeException('Original column/ID fingerprints changed in ' . $table); }
        $proof[$table] = ['rows' => count($hashes), 'sha256' => $fingerprint($hashes)];
    }
    $legacyLinks = 0;
    foreach ($scheduleTables as $table) {
        $unit = $table === 'availability_exceptions' ? '' : ' AND s.unit=r.unit';
        $bad = $db->query('SELECT r.id FROM ' . $db->protectIdentifiers($table) . ' r'
            . ' LEFT JOIN users u ON u.id=r.provider_user_id AND u.tenant_id=r.tenant_id'
            . ' LEFT JOIN department_staff s ON s.id=r.staff_assignment_id AND s.tenant_id=r.tenant_id' . $unit
            . " LEFT JOIN people p ON p.id=s.person_id AND p.tenant_id=s.tenant_id"
            . " WHERE r.provider_user_id IS NOT NULL AND (u.id IS NULL OR s.id IS NULL OR u.person_id IS NULL OR u.person_id<>s.person_id OR p.id IS NULL OR p.kind<>'employee') LIMIT 1")->getRowArray();
        if ($bad !== null) { throw new RuntimeException('Unmapped or inconsistent legacy assignment in ' . $table); }
        $legacyLinks += $db->table($table)->where('provider_user_id IS NOT NULL', null, false)->countAllResults();
    }
    // Every original exception must survive for every unit assigned to its legacy provider.
    foreach ($originalExceptions as $exception) {
        if ($exception['provider_user_id'] === null) { continue; }
        $assignments = $db->query('SELECT s.id FROM department_staff s JOIN users u ON u.person_id=s.person_id AND u.tenant_id=s.tenant_id WHERE u.id=? AND u.tenant_id=?',
            [$exception['provider_user_id'], $exception['tenant_id']])->getResultArray();
        if ($assignments === []) { throw new RuntimeException('Exception provider has no assignments'); }
        foreach ($assignments as $assignment) {
            $matches = $db->table('availability_exceptions')->where('tenant_id', $exception['tenant_id'])
                ->where('provider_user_id', $exception['provider_user_id'])->where('staff_assignment_id', $assignment['id'])
                ->where('exception_date', $exception['exception_date'])->countAllResults();
            if ($matches !== 1) { throw new RuntimeException('Exception assignment coverage mismatch'); }
        }
    }
    if (!(new \App\Services\Clinic\InventoryService($db))->isInstalled()) { throw new RuntimeException('Inventory readiness changed'); }
    echo 'STAFF_RESTORE_MIGRATION_OK ' . json_encode(['migration' => 'App2026-10-16-000001',
        'history' => 'preserved+1', 'original_tables' => count($proof), 'fingerprints_sha256' => $fingerprint($proof),
        'legacy_links' => $legacyLinks, 'users_roles' => 'unchanged', 'exception_unit_coverage' => true], JSON_THROW_ON_ERROR) . "\n";
} elseif ($mode === 'restore') {
    final class IsolatedInventoryRunner extends \CodeIgniter\Database\MigrationRunner {
        public function findMigrations(): array {
            return array_filter(parent::findMigrations(), static fn($m) => $m->namespace === 'App' && $m->version === '2026-10-15-000002');
        }
    }
    $service = new \App\Services\Clinic\InventoryService($db);
    if ($service->isInstalled()) { throw new RuntimeException('Expected pre-inventory backup'); }
    $runner = new IsolatedInventoryRunner(config('Migrations'), $db);
    $runner->setNamespace('App');
    if (count($runner->findMigrations()) !== 1 || !$runner->latest()) { throw new RuntimeException('Inventory migration did not complete'); }
    if (!(new \App\Services\Clinic\InventoryService($db))->isInstalled()) { throw new RuntimeException('Readiness marker/schema verification failed'); }
    $triggers = $db->query("SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'inv_%'")->getRow()->n;
    if ((int)$triggers !== 6) { throw new RuntimeException('Expected six append-only triggers'); }
    \App\Services\CurrentTenant::set(1);
    (new \App\Services\Clinic\InventoryService($db))->listMedicines();
    echo "RESTORE_MIGRATION_OK readiness=true triggers=6 inventory_list_query=ok\n";
}

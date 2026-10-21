<?php

declare(strict_types=1);

// Bootstrap only for the job-owned disposable MariaDB rehearsal; never uses .env credentials.
if (PHP_SAPI !== 'cli' || getenv('SYNAPSE_BMG_ISOLATED') !== '1') {
    throw new RuntimeException('Run through writable/bmg-isolated-validation.py only.');
}
$schema = 'humus_bmg_isolated_test';
$connection = ['DSN' => '', 'hostname' => '127.0.0.1', 'port' => 33307, 'username' => 'root',
    'password' => '', 'database' => $schema, 'DBDriver' => 'MySQLi', 'DBPrefix' => '',
    'pConnect' => false, 'failover' => [], 'strictOn' => true, 'DBDebug' => true];
foreach (['CI_ENVIRONMENT' => 'testing', 'FUMIS_ENABLED' => 'false', 'BMG_HARDWARE_ENABLED' => 'false'] as $key => $value) {
    putenv($key . '=' . $value); $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['default', 'tests'] as $group) {
    foreach ($connection as $key => $value) {
        if (is_array($value)) { continue; }
        $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        putenv('database.' . $group . '.' . $key . '=' . $value);
        $_ENV['database.' . $group . '.' . $key] = $_SERVER['database.' . $group . '.' . $key] = $value;
    }
}
require dirname(__DIR__) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$config = config('Database');
foreach (['default', 'tests'] as $group) { $config->$group = array_replace($config->$group, $connection); }
$config->defaultGroup = 'tests';
$db = \Config\Database::connect('tests');
$id = $db->query('SELECT @@datadir AS dir, @@port AS port, DATABASE() AS name, VERSION() AS version')->getRowArray();
$expected = str_replace('\\', '/', __DIR__ . '/isolated-inventory-db');
if (strcasecmp(rtrim(str_replace('\\', '/', $id['dir']), '/'), $expected) !== 0
    || (int) $id['port'] !== 33307 || $id['name'] !== $schema || !str_contains($id['version'], 'MariaDB')) {
    throw new RuntimeException('Disposable MariaDB identity mismatch; refusing writes.');
}
$db->query('SET SESSION innodb_lock_wait_timeout=10');
if (getenv('SYNAPSE_BMG_MIGRATE') !== '1') { return; }

final class BmgOnlyMigrationRunner extends \CodeIgniter\Database\MigrationRunner
{
    public function findMigrations(): array
    {
        return array_filter(parent::findMigrations(), static fn ($m) => $m->namespace === 'App' && $m->version === '2026-10-17-000005');
    }
}
$tables = $db->listTables();
if (in_array('bmg_rotation_schedules', $tables, true)) { throw new RuntimeException('Expected pristine restored schema.'); }
$snapshots = [];
foreach ($tables as $table) {
    $columns = $db->getFieldNames($table);
    $rows = array_map(static fn ($row) => hash('sha256', serialize($row)), $db->table($table)->get()->getResultArray());
    sort($rows);
    $snapshots[$table] = ['columns' => $columns, 'rows' => $rows];
}
$runner = new BmgOnlyMigrationRunner(config('Migrations'), $db);
$runner->setNamespace('App');
if (count($runner->findMigrations()) !== 1 || !$runner->latest()) { throw new RuntimeException('Exact BMG migration failed.'); }
$history = config('Migrations')->table;
$proof = [];
foreach ($snapshots as $table => $snapshot) {
    $builder = $db->table($table)->select(implode(',', array_map($db->protectIdentifiers(...), $snapshot['columns'])), false);
    if ($table === $history) { $builder->where('version !=', '2026-10-17-000005'); }
    $rows = array_map(static fn ($row) => hash('sha256', serialize($row)), $builder->get()->getResultArray());
    sort($rows);
    if ($rows !== $snapshot['rows']) { throw new RuntimeException('Original rows changed: ' . $table); }
    $proof[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', serialize($rows))];
}
$newHistory = $db->table($history)->where('version', '2026-10-17-000005')->get()->getResultArray();
if (count($newHistory) !== 1 || $newHistory[0]['class'] !== 'App\\Database\\Migrations\\CreateBmgRotationOperations') {
    throw new RuntimeException('Exact migration history mismatch.');
}
$newTables = ['bmg_rotation_schedules', 'bmg_rotation_commands', 'bmg_rotation_runs'];
foreach ($newTables as $table) {
    if ($db->table($table)->countAllResults() !== 0) { throw new RuntimeException('Migration created operational data.'); }
    echo 'SQL_EVIDENCE ' . $table . ' ' . json_encode($db->query('SHOW CREATE TABLE ' . $db->protectIdentifiers($table))->getRowArray(), JSON_THROW_ON_ERROR) . "\n";
}
// Forge DDL does not invalidate this connection's cached field list.
$deviceColumns = array_column($db->query('SHOW COLUMNS FROM bmg_devices')->getResultArray(), 'Field');
$added = array_values(array_diff($deviceColumns, $snapshots['bmg_devices']['columns']));
if (count($added) !== 8) { throw new RuntimeException('Expected eight nullable telemetry/binding columns.'); }
foreach ($added as $column) {
    $definition = $db->query('SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['bmg_devices', $column])->getRowArray();
    if ($definition === null || $definition['IS_NULLABLE'] !== 'YES' || !in_array($definition['COLUMN_DEFAULT'], [null, 'NULL'], true)) {
        throw new RuntimeException('New device field is not nullable/default NULL: ' . $column);
    }
    if ($db->table('bmg_devices')->where($column . ' IS NOT NULL', null, false)->countAllResults() !== 0) {
        throw new RuntimeException('Legacy device telemetry was backfilled.');
    }
}
$unexpected = array_diff($db->listTables(), $tables, $newTables, [$history . '_lock']);
if ($unexpected !== []) { throw new RuntimeException('Unrelated tables created.'); }
echo 'SQL_EVIDENCE bmg_devices ' . json_encode($db->query('SHOW CREATE TABLE bmg_devices')->getRowArray(), JSON_THROW_ON_ERROR) . "\n";
echo 'BMG_RESTORE_MIGRATION_OK ' . json_encode(['identity' => $id, 'migration' => $newHistory[0],
    'original_tables' => $proof, 'new_empty_tables' => $newTables, 'nullable_columns' => $added,
    'permissions_and_grants' => 'unchanged', 'seeding' => false, 'hardware_enabled' => false], JSON_THROW_ON_ERROR) . "\n";

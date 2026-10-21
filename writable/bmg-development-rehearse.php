<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('SYNAPSE_BMG_REHEARSE') !== '1') { throw new RuntimeException('Owned rehearsal only.'); }
$schema = 'humus_bmg_development_rehearsal';
$connection = ['DSN' => '', 'hostname' => '127.0.0.1', 'port' => 33307, 'username' => 'root',
    'password' => '', 'database' => $schema, 'DBDriver' => 'MySQLi', 'DBPrefix' => '',
    'pConnect' => false, 'failover' => [], 'strictOn' => true, 'DBDebug' => false];
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
$config->defaultGroup = 'default';
$db = \Config\Database::connect('default');
$id = $db->query('SELECT @@datadir AS dir, @@port AS port, DATABASE() AS name, VERSION() AS version')->getRowArray();
if (strcasecmp(rtrim(str_replace('\\', '/', $id['dir']), '/'), str_replace('\\', '/', __DIR__ . '/isolated-inventory-db')) !== 0
    || (int) $id['port'] !== 33307 || $id['name'] !== $schema || !str_contains($id['version'], 'MariaDB')) {
    throw new RuntimeException('Owned disposable identity mismatch.');
}
$db->query('SET SESSION lock_wait_timeout=5');
$db->query('SET SESSION innodb_lock_wait_timeout=5');
define('SYNAPSE_BMG_LIBRARY_ONLY', true);
require __DIR__ . '/bmg-development-migrate.php';
bmgPreflight($db);
$before = bmgSnapshot($db);
$beforeHash = hash('sha256', json_encode($before, JSON_THROW_ON_ERROR));
bmgApply($db);
$proof = bmgVerify($db, $before);
$after = bmgSnapshot($db);
// Re-running the permission migration must be a strict no-op, even for timestamps and grants.
$migration = new \App\Database\Migrations\AddBmgRotationPermissions(\Config\Database::forge('default'));
$migration->up();
if ($after !== bmgSnapshot($db)) { throw new RuntimeException('Permission migration is not idempotent.'); }
try { $migration->down(); throw new LogicException('Rollback unexpectedly allowed.'); }
catch (RuntimeException $e) { /* Forward-only refusal is expected. */ }
$deviceOrdinals = [];
foreach ($after['bmg_devices']['full_schema'] as $column) {
    $deviceOrdinals[$column['COLUMN_NAME']] = (int) $column['ORDINAL_POSITION'];
}
$columnProof = ['tables' => count($before), 'original_columns' => array_sum(array_map(static fn ($table) => count($table['full_schema']), $before)),
    'full_metadata_preserved' => true, 'ordinal_positions_preserved' => true,
    'normalization_only' => ['TABLE_SCHEMA', 'TABLE_CATALOG'], 'device_column_ordinals_after' => $deviceOrdinals];
echo 'BMG_DEVELOPMENT_REHEARSAL_OK ' . json_encode(['before_sha256' => $beforeHash, 'preservation' => $proof,
    'permission_idempotency' => true, 'down_refused' => true, 'hardware_operations' => 0, 'column_preservation' => $columnProof], JSON_THROW_ON_ERROR) . "\n";

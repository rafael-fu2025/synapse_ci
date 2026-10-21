<?php

declare(strict_types=1);

// Refuse standalone PHPUnit use before the framework can load .env or connect.
$root = dirname(__DIR__, 2);
$directory = getenv('QUEUE_ISOLATED_DATADIR');
$port = getenv('QUEUE_ISOLATED_PORT');
$schema = getenv('QUEUE_ISOLATED_SCHEMA');
$normalize = static fn (string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
if (PHP_SAPI !== 'cli' || !is_string($directory) || !is_string($port) || !is_string($schema)
    || !ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535
    || !preg_match('/^queue_isolated_[0-9a-f]{16}$/D', $schema)
    || realpath($directory) === false
    || $normalize(dirname($directory, 2)) !== $normalize((string) realpath($root . '/writable'))
    || !preg_match('/^queue-isolated-[a-z0-9_]+$/D', basename(dirname($directory)))
    || basename($directory) !== 'data') {
    throw new RuntimeException('Fresh queue harness identity required');
}

$fields = [
    'DSN' => '', 'hostname' => '127.0.0.1', 'port' => (int) $port,
    'username' => 'root', 'password' => '', 'database' => $schema,
    'DBDriver' => 'MySQLi', 'DBPrefix' => '', 'pConnect' => false,
    'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci',
    'swapPre' => '', 'encrypt' => false, 'compress' => false,
    'strictOn' => true, 'failover' => [],
];
$values = ['CI_ENVIRONMENT' => 'testing', 'FUMIS_ENABLED' => 'false', 'database.defaultGroup' => 'tests'];
foreach (['default', 'tests'] as $group) {
    foreach ($fields as $key => $value) {
        $values['database.' . $group . '.' . $key] = is_array($value) ? '[]'
            : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
    }
}
foreach ($values as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

// Raw, read-only identity probe before booting any application code.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$probe = mysqli_init();
$probe->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
$probe->real_connect('127.0.0.1', 'root', '', $schema, (int) $port);
$id = $probe->query('SELECT @@datadir AS dir, @@port AS port, DATABASE() AS name')->fetch_assoc();
if ($normalize($id['dir']) !== $normalize($directory) || (int) $id['port'] !== (int) $port || $id['name'] !== $schema) {
    throw new RuntimeException('Queue server identity mismatch; refusing framework boot');
}
if ((int) $probe->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetch_row()[0] !== 0) {
    throw new RuntimeException('Queue test requires an empty synthetic schema');
}
$probe->close();

require $root . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

// Preserve the process values even if dotenv populated empty entries internally.
foreach ($values as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$config = config('Database');
foreach (['default', 'tests'] as $group) {
    $config->$group = array_replace($config->$group, $fields);
}
$config->defaultGroup = 'tests';
foreach (['default', 'tests'] as $group) {
    $db = \Config\Database::connect($group);
    $id = $db->query('SELECT @@datadir AS dir, @@port AS port, DATABASE() AS name')->getRowArray();
    if ($normalize($id['dir']) !== $normalize($directory) || (int) $id['port'] !== (int) $port || $id['name'] !== $schema) {
        throw new RuntimeException('Framework queue connection identity mismatch');
    }
    $db->query('SET SESSION innodb_lock_wait_timeout=5');
}
// Tests own only synthetic fixture DDL/data; no migrations, seeds or restores here.

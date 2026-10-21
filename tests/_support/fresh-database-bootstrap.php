<?php

declare(strict_types=1);

// Refuse to boot unless the runner created an empty, explicitly named fresh schema.
$root = dirname(__DIR__, 2);
$schema = getenv('FRESH_DATABASE_SCHEMA');
$port = getenv('FRESH_DATABASE_PORT') ?: '3306';
if (PHP_SAPI !== 'cli' || !is_string($schema) || !preg_match('/^humus_fresh_[0-9a-f]{16}$/D', $schema)
    || !ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535) {
    throw new RuntimeException('Fresh database bootstrap requires FRESH_DATABASE_SCHEMA and FRESH_DATABASE_PORT.');
}

$fields = [
    'DSN' => '', 'hostname' => '127.0.0.1', 'port' => (int) $port,
    'username' => 'root', 'password' => '', 'database' => $schema,
    'DBDriver' => 'MySQLi', 'DBPrefix' => '', 'pConnect' => false,
    'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci',
    'swapPre' => '', 'encrypt' => false, 'compress' => false,
    'strictOn' => true, 'failover' => [],
];
// Synthetic keys are used only with this empty, disposable schema, never real notes.
$values = [
    'CI_ENVIRONMENT' => 'testing', 'FUMIS_ENABLED' => 'false', 'FUMIS_FAKE_ENABLED' => 'false',
    'database.defaultGroup' => 'tests', 'COUNSELLING_KEY' => str_repeat('ab', 32),
    'COUNSELLING_KEY_VERSION' => '1', 'SURVEY_KEY' => str_repeat('cd', 32), 'SURVEY_KEY_VERSION' => '1',
];
foreach (['default', 'tests'] as $group) {
    foreach ($fields as $key => $value) {
        $values['database.' . $group . '.' . $key] = is_array($value) ? '[]'
            : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
    }
}

// Verify identity and emptiness with raw mysqli before framework configuration is loaded.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$probe = mysqli_init();
$probe->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
$probe->real_connect('127.0.0.1', 'root', '', $schema, (int) $port);
$id = $probe->query('SELECT DATABASE() AS name')->fetch_assoc();
if ($id['name'] !== $schema) {
    throw new RuntimeException('Fresh database identity mismatch; refusing framework boot.');
}
if ((int) $probe->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetch_row()[0] !== 0) {
    throw new RuntimeException('Fresh database schema is not empty; refusing framework boot.');
}
$probe->close();

foreach ($values as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__ . '/fresh-framework-bootstrap.php';

// Reapply after framework boot as defense against inherited configuration.
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
    $actual = $db->query('SELECT DATABASE() AS name')->getRowArray();
    if ($actual['name'] !== $schema) {
        throw new RuntimeException('Fresh framework connection identity mismatch; refusing tests.');
    }
    $db->query('SET SESSION innodb_lock_wait_timeout=5');
}
define('HUMUS_FRESH_DATABASE_VERIFIED', $schema);

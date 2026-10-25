<?php

declare(strict_types=1);

// No dotenv, configured database, network connection, or hardware is used here.
if (PHP_SAPI !== 'cli' || getenv('BMG_ISOLATED_TESTS') !== '1') {
    throw new RuntimeException('Set BMG_ISOLATED_TESTS=1 to run disposable BMG fixtures.');
}
define('BMG_ISOLATED_TESTS', true);
$values = [
    'CI_ENVIRONMENT' => 'testing', 'BMG_HARDWARE_ENABLED' => 'false',
    'FUMIS_ENABLED' => 'false', 'database.defaultGroup' => 'tests',
];
foreach (['default', 'tests'] as $group) {
    foreach (['DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
        'hostname' => '', 'username' => '', 'password' => '', 'pConnect' => 'false', 'foreignKeys' => 'true'] as $key => $value) {
        $values['database.' . $group . '.' . $key] = $value;
    }
}
foreach ($values as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__ . '/fresh-framework-bootstrap.php';
$config = config('Database');
foreach (['default', 'tests'] as $group) {
    if ($config->$group['DBDriver'] !== 'SQLite3' || $config->$group['database'] !== ':memory:') {
        throw new RuntimeException('BMG tests require disposable SQLite memory connections only.');
    }
}
$config->defaultGroup = 'tests';

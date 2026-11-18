<?php

declare(strict_types=1);

// No dotenv, migrations, persistent database, or running application's writable files.
$runtime = __DIR__ . '/guidance-read-runtime';
foreach (['', '/cache', '/logs', '/session', '/debugbar'] as $directory) {
    if (!is_dir($runtime . $directory) && !mkdir($runtime . $directory, 0700, true)) {
        throw new RuntimeException('Cannot initialize guidance read test runtime.');
    }
}
define('WRITEPATH', $runtime . DIRECTORY_SEPARATOR);
$database = ['DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
    'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
    'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s']];
$values = ['CI_ENVIRONMENT' => 'testing', 'FUMIS_ENABLED' => 'false',
    'COUNSELLING_KEY_VERSION' => '1', 'COUNSELLING_KEY' => str_repeat('ab', 32),
    'database.defaultGroup' => 'tests'];
foreach (['default', 'tests'] as $group) {
    foreach ($database as $key => $value) {
        if (is_array($value)) continue;
        $values['database.' . $group . '.' . $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
foreach ($values as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__ . '/fresh-framework-bootstrap.php';
$config = config('Database');
$config->defaultGroup = 'tests';
$config->default = $config->tests = $database;
$connection = \Config\Database::connect($database, false);
(new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))
    ->setValue(null, ['tests' => $connection, 'default' => $connection]);

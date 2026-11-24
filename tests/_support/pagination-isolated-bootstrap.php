<?php

declare(strict_types=1);

// All pagination fixtures run without dotenv, persistent data, external AI or hardware.
if (PHP_SAPI !== 'cli' || getenv('PAGINATION_ISOLATED_TESTS') !== '1' || defined('WRITEPATH')) {
    throw new RuntimeException('Set PAGINATION_ISOLATED_TESTS=1 before loading pagination fixtures.');
}
define('PAGINATION_ISOLATED_TESTS', true);
$runtime = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'humus-pagination-' . bin2hex(random_bytes(12));
if (!mkdir($runtime, 0700)) {
    throw new RuntimeException('Unable to create pagination fixture storage.');
}
define('WRITEPATH', $runtime . DIRECTORY_SEPARATOR);
register_shutdown_function(static function () use ($runtime): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($runtime);
});
foreach (['cache', 'logs', 'session', 'debugbar', 'uploads'] as $directory) {
    if (!mkdir(WRITEPATH . $directory, 0700)) {
        throw new RuntimeException('Unable to initialize pagination fixture storage.');
    }
}
$database = [
    'DSN' => '', 'hostname' => '', 'username' => '', 'password' => '',
    'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
    'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
    'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
];
$values = [
    'CI_ENVIRONMENT' => 'testing', 'app.baseURL' => 'http://127.0.0.1:8976/',
    'database.defaultGroup' => 'tests', 'FUMIS_ENABLED' => 'false',
    'BMG_HARDWARE_ENABLED' => 'false', 'AI_ENABLED' => 'false',
    'COUNSELLING_KEY_VERSION' => '1', 'COUNSELLING_KEY' => str_repeat('ab', 32),
];
foreach (['default', 'tests'] as $group) {
    foreach ($database as $key => $value) {
        if (is_array($value)) continue;
        $values['database.' . $group . '.' . $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
    $values['database.' . $group . '.pConnect'] = 'false';
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
$connection->initialize();
(new ReflectionProperty(\Config\Database::class, 'instances'))
    ->setValue(null, ['tests' => $connection, 'default' => $connection]);
register_shutdown_function(static function () use ($connection): void { $connection->close(); });

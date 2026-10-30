<?php

declare(strict_types=1);

// Wrap the no-dotenv bootstrap before any application code can reach deployment settings.
if (defined('WRITEPATH')) {
    throw new RuntimeException('AI runtime isolation must be installed before framework boot.');
}
$runtimeDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'humus-ai-runtime-' . bin2hex(random_bytes(12));
if (!mkdir($runtimeDirectory, 0700)) {
    throw new RuntimeException('Unable to create isolated AI runtime storage.');
}
define('WRITEPATH', $runtimeDirectory . DIRECTORY_SEPARATOR);
register_shutdown_function(static function () use ($runtimeDirectory): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtimeDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($runtimeDirectory);
});
foreach (['cache', 'logs', 'session', 'debugbar'] as $name) {
    if (!mkdir(WRITEPATH . $name, 0700)) {
        throw new RuntimeException('Unable to initialize isolated AI runtime storage.');
    }
}
require __DIR__ . '/fresh-framework-bootstrap.php';

// Fresh grant checks must never fall through to a configured application database.
$runtimeDatabase = [
    'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'ai_runtime_bootstrap_',
    'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
    'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
];
config('Database')->tests = $runtimeDatabase;
$runtimeConnection = \Config\Database::connect($runtimeDatabase, false);
(new ReflectionProperty(\Config\Database::class, 'instances'))->setValue(null, ['tests' => $runtimeConnection]);
register_shutdown_function(static function () use ($runtimeConnection): void { $runtimeConnection->close(); });

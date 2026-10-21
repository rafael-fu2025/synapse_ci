<?php

declare(strict_types=1);

// Temporary CLI-only backup helper; never echo connection credentials.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$config = config('Database')->default;
if (($config['database'] ?? '') !== 'humus_db' || ($config['DBDriver'] ?? '') !== 'MySQLi'
    || !empty($config['DSN']) || !empty($config['failover'])
    || !in_array($config['hostname'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Refusing backup: expected the explicit local humus_db connection.');
}
$directory = __DIR__ . '/backups';
if (!is_dir($directory)) {
    throw new RuntimeException('The restricted backup directory must already exist.');
}
$dump = 'C:/Users/udtoh_lmtzs7k/xampp/mysql/bin/mysqldump.exe';
if (!is_file($dump)) {
    throw new RuntimeException('The verified native dump executable is unavailable.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
$db->real_connect($config['hostname'], $config['username'], $config['password'], 'humus_db', (int) ($config['port'] ?? 3306));
$db->set_charset('utf8mb4');
$identity = $db->query('SELECT DATABASE() AS name, VERSION() AS version')->fetch_assoc();
if ($identity['name'] !== 'humus_db') {
    throw new RuntimeException('Actual database mismatch.');
}
$tables = $db->query("SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
foreach ($tables as $table) {
    if ($table['TABLE_TYPE'] === 'BASE TABLE' && strtoupper($table['ENGINE']) !== 'INNODB') {
        throw new RuntimeException('Single-transaction backup cannot promise consistency for non-InnoDB table: ' . $table['TABLE_NAME']);
    }
}
$db->close();
$label = $argv[1] ?? 'inventory';
if (!preg_match('/\A[a-z_]{1,40}\z/D', $label)) { throw new RuntimeException('Invalid backup label.'); }
$stem = $directory . '/humus_db_before_' . $label . '_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
$partial = $stem . '.partial.sql';
$final = $stem . '.sql';
$optionFile = $stem . '.temporary-client.cnf';
$log = $stem . '.log';
$quote = static function (string $value): string {
    if (str_contains($value, "\0")) {
        throw new RuntimeException('Invalid connection option.');
    }
    return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value) . '"';
};
$options = "[client]\n";
foreach (['host' => 'hostname', 'user' => 'username', 'password' => 'password'] as $option => $field) {
    $options .= $option . '=' . $quote((string) $config[$field]) . "\n";
}
$options .= 'port=' . (int) ($config['port'] ?? 3306) . "\n";
if (file_put_contents($optionFile, $options, LOCK_EX) === false) {
    throw new RuntimeException('Could not create temporary client options.');
}
try {
    $process = proc_open([$dump, '--defaults-extra-file=' . $optionFile, '--protocol=tcp',
        '--single-transaction', '--quick', '--skip-lock-tables', '--hex-blob', '--routines', '--events', '--triggers',
        '--default-character-set=utf8mb4', '--result-file=' . $partial, 'humus_db'],
        [0 => ['file', 'NUL', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the backup process.');
    }
    $deadline = microtime(true) + 75;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('Backup timed out; partial file retained, not marked successful.');
        }
        usleep(100000);
    } while (true);
    $exit = $status['exitcode'];
    $close = proc_close($process);
    if ($exit === -1) {
        $exit = $close;
    }
    if ($exit !== 0 || !is_file($partial) || filesize($partial) === 0) {
        throw new RuntimeException('Native dump failed; exit=' . $exit . '; inspect the local backup log. No complete backup claimed.');
    }
    $handle = fopen($partial, 'rb');
    fseek($handle, max(0, filesize($partial) - 2048));
    $trailer = stream_get_contents($handle);
    fclose($handle);
    if (!str_contains($trailer, '-- Dump completed on')) {
        throw new RuntimeException('Dump completion marker missing; partial file retained.');
    }
    if (!rename($partial, $final)) {
        throw new RuntimeException('Could not finalize the completed dump.');
    }
    $report = ['database' => $identity['name'], 'server_version' => $identity['version'],
        'path' => $final, 'bytes' => filesize($final), 'sha256' => hash_file('sha256', $final),
        'tables_and_views' => count($tables), 'native_dump_completed' => true,
        'restore_tested' => false, 'database_changes' => false];
    file_put_contents($stem . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} finally {
    if (is_file($optionFile)) {
        unlink($optionFile);
    }
}

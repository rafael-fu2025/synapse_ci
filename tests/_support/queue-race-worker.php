<?php

declare(strict_types=1);

/** Shared fail-closed guards; including this file does not bootstrap or connect. */
final class QueueIsolatedSafety
{
    public static function normalize(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }

    public static function environment(): array
    {
        $directory = getenv('QUEUE_ISOLATED_DATADIR');
        $port = getenv('QUEUE_ISOLATED_PORT');
        $schema = getenv('QUEUE_ISOLATED_SCHEMA');
        $root = dirname(__DIR__, 2);
        if (PHP_SAPI !== 'cli' || !is_string($directory) || !is_string($port) || !is_string($schema)
            || !ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535
            || !preg_match('/^queue_isolated_[0-9a-f]{16}$/D', $schema)
            || realpath($directory) === false
            || self::normalize(dirname($directory, 2)) !== self::normalize((string) realpath($root . '/writable'))
            || !preg_match('/^queue-isolated-[a-z0-9_]+$/D', basename(dirname($directory)))
            || basename($directory) !== 'data') {
            throw new RuntimeException('Queue isolated harness environment required; no connection attempted.');
        }
        return ['directory' => $directory, 'port' => (int) $port, 'schema' => $schema];
    }

    public static function configuration(): void
    {
        $identity = self::environment();
        $config = config('Database');
        if ($config->defaultGroup !== 'tests') {
            throw new RuntimeException('Isolated default DB group was not bootstrapped.');
        }
        foreach (['default', 'tests'] as $group) {
            $db = $config->$group;
            $expected = ['hostname' => '127.0.0.1', 'database' => $identity['schema'],
                'DBDriver' => 'MySQLi', 'username' => 'root', 'password' => '', 'DSN' => '', 'DBPrefix' => ''];
            foreach ($expected as $field => $value) {
                if (($db[$field] ?? null) !== $value) {
                    throw new RuntimeException("Non-isolated DB configuration: {$group}.{$field}; no connection attempted.");
                }
            }
            if ((int) ($db['port'] ?? 0) !== $identity['port'] || !empty($db['pConnect']) || !empty($db['failover'])) {
                throw new RuntimeException("Non-isolated DB configuration: {$group} port/pConnect/failover; no connection attempted.");
            }
        }
    }

    public static function connection(\CodeIgniter\Database\BaseConnection $db): int
    {
        $expected = self::environment();
        // This is deliberately the first query on every newly obtained connection.
        $actual = $db->query('SELECT @@datadir AS dir, @@port AS port, DATABASE() AS name, CONNECTION_ID() AS connection_id')->getRowArray();
        if (self::normalize($actual['dir']) !== self::normalize($expected['directory'])
            || (int) $actual['port'] !== $expected['port'] || $actual['name'] !== $expected['schema']) {
            throw new RuntimeException('Queue server identity mismatch; refusing fixture/service writes.');
        }
        return (int) $actual['connection_id'];
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

try {
    // Dotenv can populate empty inherited settings and shadow getenv via $_ENV.
    // Freeze only the harness configuration, before boot, just as its bootstrap does.
    $identity = QueueIsolatedSafety::environment();
    $fields = [
        'DSN' => '', 'hostname' => '127.0.0.1', 'port' => $identity['port'],
        'username' => 'root', 'password' => '', 'database' => $identity['schema'],
        'DBDriver' => 'MySQLi', 'DBPrefix' => '', 'pConnect' => false,
        'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre' => '', 'encrypt' => false, 'compress' => false,
        'strictOn' => true, 'failover' => [],
    ];
    $values = ['database.defaultGroup' => 'tests', 'CI_ENVIRONMENT' => 'testing', 'FUMIS_ENABLED' => 'false'];
    foreach (['default', 'tests'] as $group) {
        foreach ($fields as $key => $value) {
            $values['database.' . $group . '.' . $key] = is_array($value) ? '[]'
                : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }
    }
    foreach ($values as $key => $value) {
        if (getenv($key) !== $value) {
            throw new RuntimeException("Missing or unsafe inherited harness setting: {$key}; no connection attempted.");
        }
    }
    require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
    foreach ($values as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $config = config('Database');
    foreach (['default', 'tests'] as $group) {
        $config->$group = array_replace($config->$group, $fields);
    }
    $config->defaultGroup = 'tests';
    QueueIsolatedSafety::configuration();
    $db = \Config\Database::connect();
    $connectionId = QueueIsolatedSafety::connection($db);
    $db->query('SET SESSION innodb_lock_wait_timeout=5');
    \App\Services\CurrentTenant::set(1);
    stream_set_timeout(STDIN, 10);
    echo json_encode(['ready' => $connectionId], JSON_THROW_ON_ERROR) . "\n";
    flush();
    $job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (($job['operation'] ?? null) !== 'callNext' || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $job['date'] ?? '')) {
        throw new RuntimeException('Invalid race job.');
    }
    $result = (new \App\Services\Scheduling\QueueService(null, null, $db))->callNext('clinic', 7, $job['date']);
    echo json_encode(['id' => $result === null ? null : (int) $result['id']], JSON_THROW_ON_ERROR) . "\n";
    $db->close();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}

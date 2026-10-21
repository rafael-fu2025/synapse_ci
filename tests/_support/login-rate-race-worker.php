<?php

declare(strict_types=1);

try {
    $root = dirname(__DIR__, 2);
    $schema = getenv('FRESH_DATABASE_SCHEMA');
    $port = getenv('FRESH_DATABASE_PORT');
    if (PHP_SAPI !== 'cli' || !is_string($schema) || !preg_match('/^humus_fresh_[0-9a-f]{16}$/D', $schema)
        || $port !== '3306') {
        throw new RuntimeException('Fresh schema runner required; no connection attempted.');
    }
    // Read-only identity check before framework boot; never trust the deployment .env.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $probe = mysqli_init();
    $probe->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    $probe->real_connect('127.0.0.1', 'root', '', $schema, (int) $port);
    if ($probe->query('SELECT DATABASE()')->fetch_row()[0] !== $schema) {
        throw new RuntimeException('Fresh worker schema identity mismatch.');
    }
    $probe->close();
    require $root . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
    $fields = ['DSN' => '', 'hostname' => '127.0.0.1', 'port' => (int) $port,
        'username' => 'root', 'password' => '', 'database' => $schema, 'DBDriver' => 'MySQLi',
        'DBPrefix' => '', 'pConnect' => false, 'failover' => [], 'strictOn' => true];
    $config = config('Database');
    foreach (['default', 'tests'] as $group) {
        $config->$group = array_replace($config->$group, $fields);
    }
    $config->defaultGroup = 'tests';
    $db = \Config\Database::connect();
    $identity = $db->query('SELECT DATABASE() AS name, CONNECTION_ID() AS id')->getRowArray();
    if ($identity['name'] !== $schema) {
        throw new RuntimeException('Fresh worker connection identity mismatch.');
    }
    $db->query('SET SESSION innodb_lock_wait_timeout=5');
    echo json_encode(['ready' => (int) $identity['id']], JSON_THROW_ON_ERROR) . "\n";
    flush();
    stream_set_timeout(STDIN, 10);
    $job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($job['buckets'] ?? null) || !is_int($job['now'] ?? null)) {
        throw new RuntimeException('Invalid synthetic login limit job.');
    }
    $wait = (new \App\Services\DatabaseLoginAttemptStore($db))->consume($job['buckets'], $job['now']);
    echo json_encode(['wait' => $wait], JSON_THROW_ON_ERROR) . "\n";
    flush();
    $db->close();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}

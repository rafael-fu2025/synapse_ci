<?php

declare(strict_types=1);

// Independent test-only process. The parent verifies and supplies a disposable schema.
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

$job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$config = $job['database'];
if (($config['DBDriver'] ?? null) !== 'MySQLi'
    || !preg_match('/^humus_fresh_[0-9a-f]{16}$/D', (string) ($config['database'] ?? ''))
    || ($config['hostname'] ?? null) !== '127.0.0.1' || !empty($config['pConnect'])
    || !empty($config['DSN']) || !empty($config['failover'])) {
    throw new RuntimeException('Refresh race worker requires the verified disposable MySQL schema.');
}
config('Database')->tests = $config;
config('Database')->default = $config;
config('Database')->defaultGroup = 'tests';
$db = \Config\Database::connect($config, false);
$actual = $db->query('SELECT DATABASE() AS name')->getRowArray()['name'] ?? null;
if ($actual !== $config['database']) {
    throw new RuntimeException('Refresh race worker database identity mismatch.');
}

echo "READY\n";
flush();
fgets(STDIN);

try {
    $result = (new \App\Services\Api\TokenService(
        tokens: new \App\Models\ApiTokenModel($db),
        users: new \App\Models\UserModel($db),
        db: $db,
    ))->rotate($job['refresh_token']);
    echo json_encode([
        'status' => $result === null ? 'replayed' : 'rotated',
        'token' => $result['token'] ?? null,
    ], JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    echo json_encode(['status' => 'error'], JSON_THROW_ON_ERROR) . "\n";
}

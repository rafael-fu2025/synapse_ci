<?php

declare(strict_types=1);

require_once __DIR__ . '/queue-race-worker.php';

try {
    $identity = QueueIsolatedSafety::environment();
    $fields = [
        'DSN' => '', 'hostname' => '127.0.0.1', 'port' => $identity['port'],
        'username' => 'root', 'password' => '', 'database' => $identity['schema'],
        'DBDriver' => 'MySQLi', 'DBPrefix' => '', 'pConnect' => false,
        'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre' => '', 'encrypt' => false, 'compress' => false, 'strictOn' => true, 'failover' => [],
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
            throw new RuntimeException('Unsafe inherited guidance setting: ' . $key);
        }
    }
    require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
    foreach ($values + ['COUNSELLING_KEY_VERSION' => '1'] as $key => $value) {
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
    $connection = QueueIsolatedSafety::connection($db);
    $db->query('SET SESSION innodb_lock_wait_timeout=5');
    \App\Services\CurrentTenant::set(1);
    // Synthetic, public fixture key: deliberately independent of application secrets.
    $service = new \App\Services\Guidance\GuidanceCaseService(
        encryptionService: new \App\Services\Crypto\EncryptionService(str_repeat('ab', 32)), db: $db
    );
    stream_set_timeout(STDIN, 10);
    echo json_encode(['ready' => $connection], JSON_THROW_ON_ERROR) . "\n";
    flush();
    $job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (!is_int($job['id'] ?? null) || $job['id'] < 1
        || !in_array($job['operation'] ?? null, ['open', 'draft', 'final'], true)
        || !in_array($job['label'] ?? null, ['worker-a', 'worker-b'], true)) {
        throw new RuntimeException('Invalid guidance race job.');
    }
    $row = match ($job['operation']) {
        'open' => $service->openSession($job['id'], 7),
        'draft' => $service->saveDraftNotes($job['id'], $job['label'], 7),
        'final' => $service->finalizeSessionNotes($job['id'], $job['label'], 7),
    };
    // Never JSON-encode raw binary ciphertext.
    echo json_encode(['id' => (int) $row['id']], JSON_THROW_ON_ERROR) . "\n";
    flush();
    $db->close();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}

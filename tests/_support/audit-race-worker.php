<?php

declare(strict_types=1);

require_once __DIR__ . '/queue-race-worker.php';

try {
    // Do not use the parent bootstrap: its empty-schema check precedes fixture creation.
    $identity = QueueIsolatedSafety::environment();
    $mode = $argv[1] ?? 'append';
    if (!in_array($mode, ['append', 'announcement', 'survey'], true)) {
        throw new RuntimeException('Invalid audit worker mode.');
    }
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
            throw new RuntimeException("Unsafe inherited harness setting: {$key}; no connection attempted.");
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
    if ($mode !== 'append') {
        // A real, unique session in the disposable artifact directory, never the app session store.
        $sessionConfig = config('Session');
        $sessionConfig->driver = \CodeIgniter\Session\Handlers\FileHandler::class;
        $sessionConfig->savePath = dirname($identity['directory']) . '/audit-session-' . $connectionId;
        if (!mkdir($sessionConfig->savePath, 0700)) {
            throw new RuntimeException('Could not create isolated session directory.');
        }
        $session = \Config\Services::session($sessionConfig, false);
        $handler = new \CodeIgniter\Session\Handlers\FileHandler($sessionConfig, '127.0.0.1');
        $handler->setLogger(service('logger'));
        session_set_save_handler($handler, true);
        session_name('audit_isolated');
        if (!session_start() || session_id() === '') {
            throw new RuntimeException('Could not start isolated authoritative session.');
        }
        \Config\Services::injectMock('session', $session);
    }
    stream_set_timeout(STDIN, 10);
    echo json_encode(['ready' => $connectionId], JSON_THROW_ON_ERROR) . "\n";
    flush();
    $job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (($job['operation'] ?? null) !== 'append' || !in_array($job['tenant'] ?? null, [1, 2], true)
        || !in_array($job['label'] ?? null, ['worker-a', 'worker-b'], true)) {
        throw new RuntimeException('Invalid audit race job.');
    }
    \App\Services\CurrentTenant::set($job['tenant']);
    $append = static function () use ($db, $job): array {
        return (new \App\Services\Audit\AuditService(null, $db))->log(
            'isolation.race', 'synthetic', $job['label'], 'create', ['worker' => $job['label']],
            session_status() === PHP_SESSION_ACTIVE ? 7 : null, null, '127.0.0.1'
        );
    };
    if ($mode === 'append') {
        $event = $append();
    } else {
        // Cached identity deliberately lacks real authority: the wrapper must refresh it from DB.
        session()->set([
            'tenant_id' => $job['tenant'],
            'user' => ['id' => 7, 'tenant_id' => $job['tenant'], 'logged_in' => true,
                'role' => 'student', 'permissions' => ['cached.fake']],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(),
                'version' => 3, 'session_id' => session_id()],
        ]);
        $service = $mode === 'announcement' ? new \App\Services\AnnouncementService()
            : new \App\Services\Guidance\SurveyService();
        $transaction = new ReflectionMethod($service, 'transaction');
        $transaction->setAccessible(true);
        $event = $transaction->invoke($service, static function () use ($db, $job, $mode, $append, $identity, $connectionId): array {
            $actor = session('user');
            if ($db->transDepth !== 1 || ($actor['role'] ?? null) !== 'superadmin'
                || ($actor['person_id'] ?? null) !== null || ($actor['auth_version'] ?? null) !== 3
                || ($actor['permissions'] ?? null) !== ['isolation.authoritative']) {
                throw new RuntimeException('Wrapper did not resolve the authoritative actor before its callback.');
            }
            // A file marker avoids unreliable nonblocking anonymous-pipe reads on Windows.
            if (file_put_contents(dirname($identity['directory']) . '/audit-callback-' . $connectionId, $mode) === false) {
                throw new RuntimeException('Could not mark synthetic callback entry.');
            }
            echo json_encode(['callback_entered' => $mode, 'actor' => $actor['id']], JSON_THROW_ON_ERROR) . "\n";
            flush();
            if (!$db->table('audit_isolation_business')->insert([
                'tenant_id' => $job['tenant'], 'label' => $job['label'], 'wrapper' => $mode,
            ])) {
                throw new RuntimeException('Synthetic business FK insert failed.');
            }
            return $append();
        });
        session()->destroy();
    }
    echo json_encode(['event' => $event], JSON_THROW_ON_ERROR) . "\n";
    flush();
    $db->close();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}

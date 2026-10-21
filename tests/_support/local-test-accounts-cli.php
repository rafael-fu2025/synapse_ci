<?php

declare(strict_types=1);

// Child process only: actual immutable runtime environment, no injection into command.
$environment = $argv[1] ?? '';
$scenario = $argv[2] ?? '';
if (!in_array($environment, ['development', 'testing', 'production'], true)) {
    exit(2);
}
define('ENVIRONMENT', $environment);
// Present only command arguments to CodeIgniter's real CLI option parser.
$_SERVER['argv'] = array_merge([$argv[0], 'synapse:test-accounts'], array_slice($argv, 3));
require __DIR__ . '/local-test-accounts-bootstrap.php';
\Config\Services::injectMock('logger', new \Psr\Log\NullLogger());

// File-backed SQLite lets the service use its own guarded, unshared connection.
$file = tempnam(__DIR__, 'local-accounts-');
if ($file === false) {
    exit(2);
}
register_shutdown_function(static function () use ($file): void {
    foreach (\Config\Database::getConnections() as $connection) {
        $connection->close();
    }
    if (is_file($file)) {
        unlink($file);
    }
});
$config = config('Database');
$config->default['database'] = $config->tests['database'] = $file;
$db = \Config\Database::connect();
\Tests\Support\LocalTestAccountsSchema::create($db);
if ($scenario === 'collision') {
    $db->table('users')->insert(['tenant_id' => 1, 'username' => 'test.employee', 'role' => 'superadmin',
        'created_at' => '2020-01-01', 'updated_at' => '2020-01-01']);
}
if (str_starts_with($scenario, 'reset')) {
    (new \App\Services\LocalTestAccounts($db, 'development'))->run(true);
    if ($scenario === 'reset-collision') {
        $db->table('users')->where('username', 'test.employee')->update(['role' => 'superadmin']);
    }
    if ($scenario === 'reset-missing') {
        $personId = $db->table('users')->where('username', 'test.employee')->get()->getRowArray()['person_id'];
        $db->table('users')->where('person_id', $personId)->delete();
        $db->table('employee_profiles')->where('person_id', $personId)->delete();
        $db->table('people')->where('id', $personId)->delete();
    }
}
$beforeReset = $db->table('users')->get()->getResultArray();
// Exercise Spark's actual dispatch, including Console's associative option forwarding.
$console = new \CodeIgniter\CLI\Console();
$exit = $console->run();
$count = $db->table('users')->countAllResults();
echo "\nFixture count: " . $count . "\n";
if ($scenario === 'rerun' && $exit === EXIT_SUCCESS) {
    echo "--- RERUN ---\n";
    $exit = $console->run();
}
if (str_starts_with($scenario, 'reset')) {
    $afterReset = $db->table('users')->get()->getResultArray();
    if ($exit === EXIT_SUCCESS) {
        $verified = count(array_filter($afterReset, static fn (array $user): bool =>
            password_verify('password123', $user['password_hash']) && (int) $user['auth_version'] === 2));
        echo 'Verified reset count: ' . $verified . "\n";
    } else {
        echo 'Reset unchanged: ' . ($beforeReset === $afterReset ? 'yes' : 'no') . "\n";
    }
}
exit($exit);

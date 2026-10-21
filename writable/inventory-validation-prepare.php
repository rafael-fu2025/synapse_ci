<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$config = config('Database');
$name = $_ENV['database.tests.database'] ?? '';
if (ENVIRONMENT !== 'testing' || $config->defaultGroup !== 'tests'
    || $config->tests['database'] !== $name || !str_ends_with($name, '_test')
    || in_array($name, ['humus_db', 'humus_db_test', $config->default['database']], true)
    || $config->tests['DBDriver'] !== 'MySQLi' || !empty($config->tests['DSN']) || !empty($config->tests['failover'])) {
    throw new RuntimeException('Disposable database configuration verification failed.');
}
$adminConfig = $config->tests;
$adminConfig['database'] = '';
$admin = \Config\Database::connect($adminConfig, false);
$exists = $admin->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name])->getRowArray();
if ($exists !== null) {
    echo "REFUSED: schema already exists: {$name}; choose a fresh numbered _test name.\n";
    exit(3);
}
$admin->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$admin->close();
\Tests\Support\InventoryTestDatabase::verify();
$db = \Config\Database::connect('tests');
$actual = $db->query('SELECT DATABASE() AS name, VERSION() AS version')->getRowArray();
if ($actual['name'] !== $name || \Config\Database::connect()->getDatabase() !== $name) {
    throw new RuntimeException('Actual connection mismatch.');
}
echo 'VERIFIED environment=' . ENVIRONMENT . ' group=' . $config->defaultGroup . ' configured=' . $name . ' actual=' . $actual['name'] . ' version=' . $actual['version'] . "\n";
$migrations = service('migrations');
$migrations->setNamespace('App')->setGroup('tests');
if (!$migrations->latest()) {
    throw new RuntimeException('App migrations failed.');
}
echo "MIGRATED App only\n";
$now = date('Y-m-d H:i:s');
$db->table('users')->insert(['id'=>1, 'tenant_id'=>1, 'person_id'=>null, 'username'=>'legacy_test_actor', 'role'=>'superadmin', 'status'=>'active', 'created_at'=>$now, 'updated_at'=>$now]);
echo "BASELINE users.id=1 legacy_test_actor active superadmin person_id=NULL; no seeders run\n";
echo 'Tables=' . count($db->listTables()) . ' triggers=' . $db->query('SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', [$name])->getRow()->n . "\n";

<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
try {
    \Tests\Support\InventoryTestDatabase::verify();
    $config = config('Database');
    $db = \Config\Database::connect('tests');
    $row = $db->query('SELECT DATABASE() AS name, VERSION() AS version')->getRowArray();
    echo json_encode(['environment'=>ENVIRONMENT, 'group'=>$config->defaultGroup] + $row) . "\n";
    echo 'Tables: ' . implode(', ', $db->listTables()) . "\n";
    foreach ($db->table('migrations')->select('version, class, namespace, group')->get()->getResultArray() as $migration) {
        echo json_encode($migration) . "\n";
    }
} catch (\Throwable $e) {
    echo 'INSPECTION FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}

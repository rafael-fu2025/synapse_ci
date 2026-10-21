<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

$job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
\Tests\Support\InventoryTestDatabase::verify($job['database']);
\App\Services\CurrentTenant::set($job['tenant_id']);
$db = \Config\Database::connect('tests');
$db->query('SET SESSION innodb_lock_wait_timeout=10');
echo "READY\n";
flush();
fgets(STDIN);

try {
    $service = new \App\Services\Clinic\InventoryService($db);
    $result = match ($job['operation']) {
        'catalog' => $service->saveMedicine(null, $job['data'], $job['actor_id'], $job['key']),
        'receive' => $service->receive($job['medicine_id'], $job['data'], $job['actor_id'], $job['key']),
        'dispense' => $service->dispense($job['encounter_id'], $job['medicine_id'], $job['quantity'], 'Race', $job['actor_id'], $job['key']),
        'complete' => (new \App\Services\Clinic\EncounterService())->completeEncounter($job['encounter_id'], 'discharged'),
        'reverse' => $service->reverse($job['operation_id'], 'restore_unissued', 'Never issued', $job['actor_id'], $job['key']),
    };
    echo json_encode(['code'=>200, 'result'=>$result], JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable $e) {
    echo json_encode(['code'=>$e->getCode(), 'message'=>$e->getMessage()], JSON_THROW_ON_ERROR) . "\n";
}

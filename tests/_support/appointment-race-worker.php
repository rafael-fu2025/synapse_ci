<?php

declare(strict_types=1);

// Independent test-only process: framework bootstrap forces ENVIRONMENT=testing.
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

$job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
config('Database')->tests = $job['database'];
\App\Services\CurrentTenant::set(1);
echo "READY\n";
flush();
fgets(STDIN);

try {
    $result = match ($job['operation']) {
        'confirm' => (new \App\Services\Scheduling\AppointmentService())->confirmAppointment('clinic', $job['id'], $job['data'], $job['actor']),
        'checkin' => (new \App\Services\Scheduling\QueueService())->enqueueAppointment($job['id'], 'clinic', $job['actor']),
        'book' => (new \App\Services\Scheduling\AppointmentService())->bookAppointment($job['data']),
        'availability' => (new \App\Services\Scheduling\AppointmentService())->saveAvailability('clinic', $job['data'], $job['actor']),
        'cancel' => ['id' => (int) (new \App\Services\Scheduling\AppointmentService())->cancelAppointment($job['id'], 'Concurrent cancellation', 'clinic', $job['actor'])],
    };
    echo json_encode(['code' => 200, 'id' => (int) $result['id']], JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable $e) {
    echo json_encode(['code' => $e->getCode(), 'message' => $e->getMessage()], JSON_THROW_ON_ERROR) . "\n";
}

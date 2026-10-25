<?php

declare(strict_types=1);

// Render only fixture data; never query or mutate an operational queue.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
helper(['url', 'form']);
session()->set('user', ['role' => 'superadmin']);
$unit = $argv[1] ?? 'clinic';
if (!in_array($unit, ['clinic', 'guidance'], true)) {
    throw new InvalidArgumentException('Unknown queue unit');
}
$summary = array_fill_keys(['waiting', 'called', 'in_consultation', 'done', 'skipped'], []);
$summary['counts'] = array_fill_keys(['waiting', 'called', 'in_consultation', 'done', 'skipped', 'total'], 0);
echo view('queue/board_partial', ['unit' => $unit, 'summary' => $summary]);

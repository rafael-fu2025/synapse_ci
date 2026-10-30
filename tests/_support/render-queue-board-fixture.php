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
if (($argv[2] ?? '') === 'no-show-action') {
    echo view('queue/no_show_action', ['unit' => $unit, 'item' => [
        'id' => 71,
        'queue_date' => \App\Services\Scheduling\SchedulingClock::today(),
        'started_at' => null,
        'completed_at' => null,
    ]]);
    exit;
}
$summary = array_fill_keys(['waiting', 'called', 'in_consultation', 'done', 'skipped'], []);
$summary['counts'] = array_fill_keys(['waiting', 'called', 'in_consultation', 'done', 'skipped', 'total'], 0);
$queueDate = '2026-10-27';
if (($argv[2] ?? '') === 'history-fixture') {
    $count = min(80, max(0, (int) ($argv[3] ?? 0)));
    $summary['no_show'] = [];
    for ($i = 1; $i <= $count; $i++) {
        $summary['no_show'][] = [
            'ticket_code' => sprintf('TEST-%03d', $i),
            'last_name' => $i === 1 ? '<img src=x onerror=alert(1)>' : str_repeat('LongName', 10),
            'first_name' => 'Fixture Patient ' . $i,
        ];
    }
    $summary['counts']['total'] = $count;
    echo json_encode([
        'board' => view('queue/board_partial', compact('unit', 'summary', 'queueDate')),
        'history' => view('queue/no_show_history', ['noShows' => $summary['no_show'], 'queueDate' => $queueDate]),
    ], JSON_THROW_ON_ERROR);
    exit;
}
echo view('queue/board_partial', compact('unit', 'summary', 'queueDate'));

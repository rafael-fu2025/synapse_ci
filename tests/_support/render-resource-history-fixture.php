<?php

declare(strict_types=1);

// CLI-only synthetic arrays with the guarded no-dotenv, private SQLite runtime.
// No controllers, database records, writes, device APIs or external services run.
if (PHP_SAPI !== 'cli' || getenv('PAGINATION_ISOLATED_TESTS') !== '1') { exit(1); }
$fixture = json_decode(stream_get_contents(STDIN, 16384), true, 16, JSON_THROW_ON_ERROR);
$origin = $fixture['origin'] ?? '';
$fixturePath = $fixture['path'] ?? '/';
if (!is_string($origin) || !preg_match('~\Ahttp://127\.0\.0\.1:[1-9][0-9]{0,4}\z~D', $origin)
    || !is_string($fixturePath) || strlen($fixturePath) > 4096) { exit(2); }
$pathname = parse_url($fixturePath, PHP_URL_PATH);
if (!in_array($pathname, ['/', '/clinic/teams/11', '/facilities/unit/11', '/clinic/inventory/11'], true)) { exit(3); }
require __DIR__ . '/pagination-isolated-bootstrap.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
$config = config('App');
$config->baseURL = $origin . '/';
$config->indexPage = '';
parse_str(parse_url($fixturePath, PHP_URL_QUERY) ?? '', $query);
$request = new \CodeIgniter\HTTP\IncomingRequest($config, new \CodeIgniter\HTTP\SiteURI($config, $fixturePath), null, new \CodeIgniter\HTTP\UserAgent());
$request->setGlobal('get', $query);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);

/** Only the app shell is replaced; actual resource views, modal layout and partials render normally. */
final class ResourceHistoryFixtureView extends \CodeIgniter\View\View
{
    public function render(string $view, ?array $options = null, ?bool $saveData = null): string
    {
        if ($view === 'layouts/app') { return $this->shell(); }
        if (!in_array($view, ['teams/show', 'facilities/unit_detail', 'inventory/show',
            'layouts/modal_form', 'partials/alerts', 'partials/table_pagination'], true)) {
            throw new RuntimeException('Unexpected fixture view dependency: ' . $view);
        }
        return parent::render($view, $options, $saveData);
    }

    public function shell(): string
    {
        $origin = $GLOBALS['origin'];
        $layout = file_get_contents(APPPATH . 'Views/layouts/app.php');
        if (!preg_match('~(<div class="modal fade" id="synapseConfirmModal".*?)(?=\s*<!-- Vendor JS Files -->)~s', $layout, $confirmation)) {
            throw new RuntimeException('Actual confirmation markup was not found.');
        }
        $title = esc($this->getData()['title'] ?? 'Synthetic resource history');
        $styles = $this->renderSection('styles');
        $actions = $this->renderSection('page_actions');
        $content = $this->renderSection('content');
        $scripts = $this->renderSection('scripts');
        $assets = '';
        foreach (['csrf', 'confirm', 'refresh-regions', 'toast', 'form-modal'] as $name) {
            $assets .= '<script src="' . esc($origin . '/assets/js/' . $name . '.js', 'attr') . '"></script>';
        }
        return '<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title><link rel="icon" href="data:,">'
            . '<meta name="csrf-name" content="' . esc(csrf_token()) . '"><meta name="csrf-token" content="' . esc(csrf_hash()) . '">'
            . '<meta name="csrf-header" content="' . esc(csrf_header()) . '"><meta name="synapse-refresh-regions-enabled" content="1">'
            . '<meta name="synapse-refresh-region-owners" content="facilities.unit.11 inventory.detail.11 teams.tasks.clinic">'
            . '<link rel="stylesheet" href="' . esc($origin) . '/assets/vendor/bootstrap/css/bootstrap.min.css">'
            . '<link rel="stylesheet" href="' . esc($origin) . '/assets/vendor/bootstrap-icons/bootstrap-icons.css">'
            . '<link rel="stylesheet" href="' . esc($origin) . '/assets/css/style.css">' . $styles
            . '<style>#main.main{margin:0;padding:20px;min-height:100vh}#sidebar{display:none}.fixture-head{display:flex;gap:16px;align-items:center;justify-content:space-between;margin-bottom:20px}</style>'
            . '</head><body class="app-shell" data-navigation-scope="synthetic-resource-history">'
            . '<aside id="sidebar"><nav class="sidebar-nav"><a class="nav-link" href="' . esc($origin) . '/">Synthetic fixture</a></nav></aside>'
            . '<main id="main" class="main"><header class="fixture-head"><h1 class="h4">' . $title . '</h1>' . $actions . '</header>'
            . '<p class="small text-muted">SYNTHETIC TEST ONLY — no operational records or hardware.</p>' . $content . '</main>'
            . $confirmation[1] . '<script src="' . esc($origin) . '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>'
            . $assets . $scripts . '</body></html>';
    }
}
$renderer = new ResourceHistoryFixtureView(config('View'), APPPATH . 'Views', null, false);
\Config\Services::injectMock('renderer', $renderer);

$history = static function (string $pageName, string $sizeName, int $default, int $total) use ($query): array {
    $page = \App\Libraries\TablePage::forList($query, $pageName, $sizeName, $default);
    return $page->metadata($total, $total);
};
$ids = static fn (array $meta): array => $meta['total'] === 0 ? []
    : range($meta['total'] - $meta['from'] + 1, $meta['total'] - $meta['to'] + 1);
$stamp = '2026-11-01 00:00:00';

if ($pathname === '/') {
    $renderer->setData(['title' => 'Resource history browser fixture']);
    $renderer->section('content');
    print '<a class="btn btn-primary" href="' . esc($origin . '/clinic/teams/11?comments_per_page=20&activity_per_page=20') . '" data-app-modal-open>Open task history</a>';
    $renderer->endSection();
    print $renderer->shell();
} elseif ($pathname === '/clinic/teams/11') {
    $commentsPagination = $history('comments_page', 'comments_per_page', 50, 121);
    $activityPagination = $history('activity_page', 'activity_per_page', 50, 111);
    $memberNames = [1 => 'History reader'];
    foreach (range(1, 111) as $id) { $memberNames[1000 + $id] = 'Activity actor ' . $id; }
    $task = ['id' => 11, 'assignee_user_id' => 1, 'created_by_user_id' => 1, 'canEdit' => true, 'version' => 1,
        'title' => 'Actual task history fixture', 'description' => '', 'checklist' => [], 'due_date' => null,
        'status' => 'todo', 'priority' => 'normal', 'comments_total' => 121, 'activity_total' => 111,
        'comments_limit' => $commentsPagination['per_page'], 'activity_limit' => $activityPagination['per_page'],
        'commentsPagination' => $commentsPagination, 'activityPagination' => $activityPagination,
        'comments' => array_map(static fn (int $id): array => ['id' => $id, 'actor_user_id' => 1, 'body' => 'Comment ' . $id, 'created_at' => $stamp], $ids($commentsPagination)),
        'activity' => array_map(static fn (int $id): array => ['id' => $id, 'actor_user_id' => 1000 + $id, 'event' => 'updated', 'old_status' => null, 'new_status' => null, 'created_at' => $stamp], $ids($activityPagination))];
    print view('teams/show', ['title' => 'Team task #11', 'unit' => 'clinic', 'task' => $task,
        'baseUrl' => $origin . '/clinic/teams', 'cancelUrl' => $origin . '/', 'memberNames' => $memberNames,
        'statuses' => ['todo' => 'To do', 'in_progress' => 'In progress', 'blocked' => 'Blocked', 'done' => 'Done'],
        'priorities' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High']], ['saveData' => true]);
} elseif ($pathname === '/facilities/unit/11') {
    $commandsPagination = $history('commands_page', 'commands_per_page', 20, 65);
    $runsPagination = $history('runs_page', 'runs_per_page', 20, 76);
    $batchesPagination = $history('batches_page', 'batches_per_page', 50, 111);
    $revision = max(0, min(9, (int) ($fixture['refreshRevision'] ?? 0)));
    print view('facilities/unit_detail', [
        'title' => 'Actual drum history fixture',
        'unit' => ['id' => 11, 'unit_code' => 'DRUM-11', 'name' => 'Fixture drum' . ($revision ? ' · Refreshed ' . $revision : ''), 'status' => 'active', 'drum_capacity_kg' => '80.00'],
        'schedule' => ['version' => 4, 'enabled' => false, 'times' => [], 'rotations' => 7, 'pwm' => null],
        'device' => null, 'canManage' => true, 'canConfigure' => false, 'canHardware' => false,
        'canUsb' => false, 'hardwareEnabled' => false, 'hardware_enabled' => false,
        'activeBatch' => ['id' => 500, 'batch_code' => 'ACTIVE-500', 'status' => 'processing', 'total_input_weight_kg' => '10.00', 'started_at' => $stamp, 'notes' => 'Current synthetic batch'],
        'commandsPagination' => $commandsPagination, 'runsPagination' => $runsPagination, 'batchesPagination' => $batchesPagination,
        'commands' => array_map(static fn (int $id): array => ['created_at' => $stamp, 'command' => 'stop', 'status' => 'acknowledged', 'expires_at' => $stamp, 'ack_status' => 'completed', 'ack_message' => 'Command ' . $id], $ids($commandsPagination)),
        'runs' => array_map(static fn (int $id): array => ['occurred_at' => $stamp, 'motor_state' => 'Report ' . $id, 'rotations' => 7, 'created_at' => $stamp], $ids($runsPagination)),
        'pastBatches' => array_map(static fn (int $id): array => ['id' => $id, 'batch_code' => 'PAST-' . $id, 'status' => 'released', 'total_input_weight_kg' => '9.00', 'started_at' => $stamp, 'completed_at' => $stamp, 'notes' => 'Synthetic batch ' . $id], $ids($batchesPagination)),
    ], ['saveData' => true]);
} else {
    $movementPagination = $history('page', 'per_page', 100, 141);
    $operationsPagination = $history('operations_page', 'operations_per_page', 100, 231);
    print view('inventory/show', [
        'title' => 'Actual inventory history fixture', 'canManage' => false, 'canWrite' => false,
        'medicine' => ['id' => 11, 'is_active' => 1, 'name' => 'Fixture medicine', 'unit_of_measure' => 'tablet',
            'minimum_stock_level' => 2, 'usable_stock' => 10, 'total_stock' => 10, 'expired_stock' => 0,
            'quarantined_stock' => 0, 'near_expiry_stock' => 0],
        'batches' => [], 'movementPagination' => $movementPagination, 'operationsPagination' => $operationsPagination,
        'operations' => array_map(static fn (int $id): array => ['id' => $id, 'type' => 'receipt', 'created_at' => $stamp,
            'supplier' => 'Synthetic supplier', 'reference' => 'Reference ' . $id, 'encounter_id' => null,
            'reason' => 'Operation ' . $id, 'actor_id' => 1, 'actor_name' => 'History reader', 'reversal_mode' => null,
            'reversal_of' => null, 'reversed_by_operation_id' => null, 'correction_mode' => null], $ids($operationsPagination)),
        'movements' => array_map(static fn (int $id): array => ['id' => $id, 'delta' => 0, 'created_at' => $stamp,
            'batch_id' => 31, 'actor_id' => null, 'reason' => 'Movement ' . $id, 'operation_id' => null, 'original_movement_id' => null], $ids($movementPagination)),
    ], ['saveData' => true]);
}

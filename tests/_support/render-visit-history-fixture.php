<?php

declare(strict_types=1);

// CLI-only: render the production view/layout, never dispatch a controller or query SQL.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$app = config('App');
$app->baseURL = $fixture['origin'] . '/';
$app->indexPage = '';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $fixture['path']), null, new \CodeIgniter\HTTP\UserAgent());
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
$sessionConfig = config('Session');
$session = new \CodeIgniter\Test\Mock\MockSession(new \CodeIgniter\Session\Handlers\FileHandler($sessionConfig, '127.0.0.1'), $sessionConfig);
$_SESSION = [];
\Config\Services::injectMock('session', $session);
// A linked person enables personal navigation; tenant zero prevents profile-photo lookup.
$session->set(['tenant_id' => 0, 'user' => [
    'id' => 1, 'tenant_id' => 0, 'person_id' => 1, 'name' => 'Visit Fixture',
    'username' => 'fixture', 'role' => 'student', 'logged_in' => true,
    'permissions' => ['portal.records.read'],
]]);
helper(['url', 'form']);
parse_str(parse_url($fixture['path'], PHP_URL_QUERY) ?? '', $query);
$service = $query['service'] ?? 'all';
if (!in_array($service, ['all', 'clinic', 'guidance'], true)) {
    throw new InvalidArgumentException('Unknown fixture service');
}
$scenario = $fixture['scenario'] ?? 'normal';
if (!in_array($scenario, ['normal', 'empty', 'clinic-only', 'timestamps'], true)) {
    throw new InvalidArgumentException('Unknown fixture scenario');
}
// Only the patient-safe projection is fabricated. All HTML and behavior are production-owned.
$rows = [];
for ($i = 0; $i < 30 && $scenario !== 'empty'; $i++) {
    $unit = $scenario !== 'clinic-only' && $i % 10 === 9 ? 'guidance' : 'clinic';
    $day = 30 - $i;
    $rows[] = [
        'unit' => $unit,
        'started_at' => sprintf('2026-09-%02d 00:15:00', $day),
        'completed_at' => sprintf('2026-09-%02d 00:45:00', $day),
        'staff_name' => $i === 0 ? '<img src=x onerror="window.visitHistoryXss=1"> Dr. A & B <script>window.visitHistoryXss=1</script>' : 'Staff ' . ($i + 1),
        'attendance' => $i % 2 === 0 ? 'walk_in' : 'appointment',
        'summary' => $unit === 'clinic' ? 'Completed clinic visit' : 'Completed guidance session',
        'status' => 'completed',
    ];
}
if ($scenario === 'timestamps') {
    $rows[1]['started_at'] = null;
    $rows[1]['completed_at'] = '';
    $rows[2]['started_at'] = '2026-02-30 00:15:00';
    $rows[2]['completed_at'] = 'not a timestamp';
}
$rows = array_values(array_filter($rows, static fn(array $row): bool => $service === 'all' || $row['unit'] === $service));
$total = count($rows);
$pages = max(1, (int) ceil($total / 25));
$page = min(max(1, (int) ($query['page'] ?? 1)), $pages);
$offset = ($page - 1) * 25;
$visits = array_slice($rows, $offset, 25);
echo view('portal/visit_history', [
    'title' => 'My Visit History', 'pageTitle' => 'My Visit History',
    'baseUrl' => site_url('portal/visits'),
    'breadcrumbs' => [['label' => 'My Health Portal', 'url' => site_url('portal')], ['label' => 'My Visit History', 'url' => '']],
    'visits' => $visits, 'filters' => ['service' => $service],
    'pagination' => ['page' => $page, 'pages' => $pages, 'per_page' => 25, 'total' => $total,
        'from' => $total > 0 ? $offset + 1 : 0, 'to' => $offset + count($visits)],
]);
if (session_status() !== PHP_SESSION_NONE) {
    throw new RuntimeException('Visit fixture unexpectedly started a native session');
}

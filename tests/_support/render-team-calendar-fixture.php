<?php

declare(strict_types=1);

// CLI-only: real templates/layout/assets, deterministic values, no database access.
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
parse_str(parse_url($fixture['path'], PHP_URL_QUERY) ?? '', $_GET);
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $fixture['path']), null, new \CodeIgniter\HTTP\UserAgent());
$request->setGlobal('get', $_GET);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
session()->set(['tenant_id' => 1, 'user' => ['id' => 1, 'name' => 'Calendar Fixture', 'username' => 'fixture', 'role' => 'superadmin', 'logged_in' => true]]);
$path = parse_url($fixture['path'], PHP_URL_PATH);
$unit = explode('/', trim($path, '/'))[0];
if (!in_array($unit, ['clinic', 'guidance', 'facilities'], true)) {
    throw new InvalidArgumentException('Unknown calendar fixture unit');
}
$base = site_url($unit . '/teams/calendar');
$payload = '<img src=x onerror="window.calendarXss=1">';
$members = [['id' => 1, 'name' => 'Fixture Organizer', 'role' => 'superadmin'],
    ['id' => 2, 'name' => 'Fixture Participant', 'role' => 'superadmin']];
$event = ['id' => 41, 'tenant_id' => 1, 'unit' => $unit, 'title' => 'Fixture event ' . $payload,
    'description' => 'Stored description ' . $payload, 'location' => 'Meeting room ' . $payload,
    'starts_at' => '2026-10-01 01:15:00', 'ends_at' => '2026-10-02 16:00:00',
    'status' => 'scheduled', 'version' => 3, 'created_by_user_id' => 1,
    'participant_ids' => [2], 'participants' => [$members[1]], 'canEdit' => ($_GET['readonly'] ?? '') !== '1'];
$zone = new DateTimeZone('Asia/Manila');
$startLocal = (new DateTimeImmutable($event['starts_at'], new DateTimeZone('UTC')))->setTimezone($zone);
$endLocal = (new DateTimeImmutable($event['ends_at'], new DateTimeZone('UTC')))->setTimezone($zone);
$data = ['unit' => $unit, 'baseUrl' => $base, 'teamsUrl' => site_url($unit . '/teams'), 'cancelUrl' => $base,
    'members' => $members, 'memberNames' => array_column($members, 'name', 'id'),
    'actor' => ['id' => 1, 'tenant_id' => 1, 'role' => 'superadmin'], 'canManage' => true];
if (str_ends_with($path, '/create') || str_ends_with($path, '/edit')) {
    $edit = str_ends_with($path, '/edit');
    $date = $_GET['date'] ?? '2026-10-01';
    $view = 'teams/event_form';
    $data += ['title' => $edit ? 'Edit team event #41' : 'New team event', 'event' => $edit ? $event : null,
        'startsValue' => $edit ? $startLocal->format('Y-m-d\TH:i') : $date . 'T09:00',
        'endsValue' => $edit ? $endLocal->format('Y-m-d\TH:i') : $date . 'T10:00',
        'formAction' => $base . ($edit ? '/events/41/update' : '/events/store'), 'formModalPage' => true];
} elseif (str_ends_with($path, '/41')) {
    $view = 'teams/event_show';
    $data += ['title' => 'Team event #41', 'event' => $event, 'startLocal' => $startLocal,
        'endLocal' => $endLocal, 'formModalPage' => true];
} else {
    $view = 'teams/calendar';
    $mode = $_GET['view'] ?? 'month';
    $date = $_GET['date'] ?? '2026-10-01';
    $anchor = new DateTimeImmutable($date, $zone);
    if ($mode === 'month') {
        $first = $anchor->modify('first day of this month');
        $start = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
        $last = $anchor->modify('last day of this month');
        $end = $last->modify('+' . (8 - (int) $last->format('N')) . ' days');
        $prev = $first->modify('-1 month');
        $next = $first->modify('+1 month');
    } else {
        $start = $mode === 'week' ? $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days') : $anchor;
        $end = $start->modify($mode === 'week' ? '+7 days' : '+30 days');
        $prev = $anchor->modify($mode === 'week' ? '-7 days' : '-30 days');
        $next = $anchor->modify($mode === 'week' ? '+7 days' : '+30 days');
    }
    $days = [];
    for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
        $items = [];
        if ($startLocal < $day->modify('+1 day') && $endLocal > $day) {
            $items[] = ['type' => 'event', 'id' => 41, 'title' => $event['title'], 'status' => 'scheduled',
                'label' => $day->format('Y-m-d') === '2026-10-01' ? '9:15 AM' : 'Continues', 'url' => $base . '/events/41'];
        }
        if ($day->format('Y-m-d') === '2026-10-01') {
            $items[] = ['type' => 'task', 'id' => 31, 'title' => 'Fixture task deadline', 'status' => 'todo',
                'label' => 'Due', 'url' => site_url($unit . '/teams/31')];
        }
        $days[$day->format('Y-m-d')] = ['date' => $day, 'items' => $items];
    }
    $data += ['title' => ucfirst($unit) . ' Team Calendar', 'view' => $mode, 'date' => $date,
        'mine' => $_GET['mine'] ?? '', 'status' => $_GET['status'] ?? 'scheduled',
        'startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d'),
        'prevDate' => $prev->format('Y-m-d'), 'nextDate' => $next->format('Y-m-d'),
        'today' => '2026-10-01', 'days' => $days, 'totalEvents' => 1, 'totalTasks' => 1,
        'truncated' => false, 'formModalPage' => false];
}
echo view($view, $data);

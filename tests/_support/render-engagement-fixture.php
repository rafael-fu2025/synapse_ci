<?php
// CLI-only actual NiceAdmin views; never construct models or contact DB/MIS.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
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
$actor = ['id' => 1, 'name' => 'Fixture Admin', 'username' => 'fixture', 'role' => 'superadmin', 'logged_in' => true];
session()->set(['user' => $actor, 'tenant_id' => 1]);
$path = parse_url($fixture['path'], PHP_URL_PATH);
$portal = str_starts_with($path, '/portal/');
if ($portal) {
    $actor['role'] = ($_GET['profile'] ?? '') === 'employee' ? 'employee' : 'student';
    $actor['person_id'] = 1;
    $actor['name'] = 'Fixture ' . ucfirst($actor['role']);
    session()->set('user', $actor);
}
$unit = str_starts_with($path, '/clinic/') ? 'clinic' : 'guidance';
$kind = str_contains($path, '/announcements') ? 'announcements' : 'surveys';
$baseUrl = site_url(($portal ? 'portal' : $unit) . '/' . $kind);
$pagination = ['page' => 1, 'per_page' => 25, 'filtered' => 3, 'total' => 3, 'pages' => 1];
$data = compact('actor', 'unit', 'baseUrl', 'pagination') + ['title' => ucfirst($kind), 'cancelUrl' => $baseUrl, 'formModalPage' => true];
$literal = '<img src=x onerror="window.fixtureXss=1"> & campus support';
if ($kind === 'announcements') {
    $notice = ['id' => 1, 'unit' => $unit, 'title' => 'Campus notice', 'body' => $literal . "\n" . str_repeat('LongText', 45), 'audience' => 'students', 'target_year_levels' => '[1,6]', 'target_student_categories' => '["new","transferee","graduating"]', 'status' => 'draft', 'version' => 7, 'starts_at' => null, 'ends_at' => null, 'published_at' => null, 'read_at' => null];
    $notice = array_replace($notice, $fixture['savedTarget'] ?? []);
    if (preg_match('~/(create|1/edit)$~', $path)) {
        $edit = str_ends_with($path, '/edit');
        echo view('announcements/form', $data + ['announcement' => $edit ? $notice : null, 'formAction' => $baseUrl . ($edit ? '/1/update' : '/store')]);
    } elseif ($portal && str_ends_with($path, '/1')) {
        $notice['published_at'] = '2026-10-16 08:00:00';
        $notice['read_at'] = isset($_GET['read']) ? '2026-10-16 09:00:00' : null;
        echo view('announcements/show', $data + ['announcement' => $notice]);
    } else {
        $items = [];
        foreach (['draft', 'published', 'archived'] as $i => $status) {
            $row = $notice;
            $row['id'] = $i + 1; $row['title'] = ucfirst($status) . ' notice'; $row['status'] = $status;
            $row['published_at'] = $i ? '2026-10-16 08:00:00' : null;
            $items[] = $row;
        }
        if ($portal) {
            $items = [array_replace($notice, ['id' => 1, 'title' => 'Campus notice', 'unit' => 'clinic', 'published_at' => '2026-10-16 08:00:00', 'status' => 'published', 'excerpt' => $literal]),
                array_replace($notice, ['id' => 2, 'title' => 'Guidance support hours', 'published_at' => '2026-10-17 08:00:00', 'status' => 'published', 'excerpt' => 'Find support and plan your next visit.', 'read_at' => '2026-10-18 01:00:00'])];
            $filters = \App\Services\PortalContent::filters($_GET, ['all', 'unread', 'read'], ['clinic', 'guidance']);
            $items = array_values(array_filter($items, static fn ($item) => ($filters['unit'] === 'all' || $item['unit'] === $filters['unit']) && ($filters['q'] === '' || stripos($item['title'] . $item['excerpt'], $filters['q']) !== false)));
            $counts = ['all' => count($items), 'unread' => count(array_filter($items, static fn ($item) => $item['read_at'] === null)), 'read' => count(array_filter($items, static fn ($item) => $item['read_at'] !== null))];
            $items = array_values(array_filter($items, static fn ($item) => $filters['status'] === 'all' || ($filters['status'] === 'unread' ? $item['read_at'] === null : $item['read_at'] !== null)));
            $data['filters'] = $filters; $data['counts'] = $counts;
            $data['pagination']['total'] = $data['pagination']['filtered'] = count($items);
        }
        echo view($portal ? 'announcements/portal' : 'announcements/index', $data + ['announcements' => isset($_GET['empty']) ? [] : $items]);
    }
} else {
    $survey = ['id' => 1, 'title' => 'Campus wellbeing', 'description' => $literal, 'audience' => 'students', 'target_year_levels' => '[1,6]', 'target_student_categories' => '["new","transferee","graduating"]', 'status' => 'draft', 'version' => 4, 'starts_at' => '', 'ends_at' => '', 'submitted' => isset($_GET['submitted']), 'questions' => [
        ['type' => 'text', 'label' => 'Your feedback', 'required' => true, 'options' => []],
        ['type' => 'single', 'label' => 'Preferred time', 'required' => true, 'options' => ['Morning', 'Afternoon']],
        ['type' => 'multiple', 'label' => 'Support topics', 'required' => true, 'options' => ['Study', 'Wellbeing', 'Career']],
        ['type' => 'rating', 'label' => 'Overall rating', 'required' => true, 'options' => []],
    ]];
    $survey = array_replace($survey, $fixture['savedTarget'] ?? []);
    if (preg_match('~/(create|1/edit)$~', $path)) {
        $edit = str_ends_with($path, '/edit');
        echo view('surveys/form', $data + ['survey' => $edit ? $survey : null, 'formAction' => $baseUrl . ($edit ? '/1/update' : '/store')]);
    } elseif ($portal && str_ends_with($path, '/1')) {
        echo view('surveys/take', $data + compact('survey'));
    } else {
        $items = [];
        foreach (['draft', 'published', 'closed'] as $i => $status) {
            $row = $survey; $row['id'] = $i + 1; $row['status'] = $status; $row['title'] = ucfirst($status) . ' survey'; $items[] = $row;
        }
        if ($portal) {
            $items = [array_replace($survey, ['id' => 1, 'title' => 'Campus wellbeing', 'status' => 'published', 'closed' => false, 'ends_at' => '2030-10-31 04:00:00']),
                array_replace($survey, ['id' => 2, 'title' => 'Student services feedback', 'status' => 'published', 'submitted' => true, 'closed' => false]),
                array_replace($survey, ['id' => 3, 'title' => 'Previous campus survey', 'status' => 'closed', 'submitted' => false, 'closed' => true])];
            $filters = \App\Services\PortalContent::filters($_GET, ['all', 'available', 'submitted', 'closed']);
            $items = array_values(array_filter($items, static fn ($item) => $filters['q'] === '' || stripos($item['title'] . $item['description'], $filters['q']) !== false));
            $counts = ['all' => count($items), 'available' => count(array_filter($items, static fn ($item) => !$item['closed'] && !$item['submitted'])), 'submitted' => count(array_filter($items, static fn ($item) => $item['submitted'])), 'closed' => count(array_filter($items, static fn ($item) => $item['closed']))];
            $items = array_values(array_filter($items, static fn ($item) => match ($filters['status']) { 'available' => !$item['closed'] && !$item['submitted'], 'submitted' => (bool) $item['submitted'], 'closed' => (bool) $item['closed'], default => true }));
            $data += ['hasProfile' => true, 'nowUtc' => '2026-10-20 00:00:00']; $data['filters'] = $filters; $data['counts'] = $counts;
            $data['pagination']['total'] = $data['pagination']['filtered'] = count($items);
        }
        echo view($portal ? 'surveys/portal_index' : 'surveys/index', $data + ['items' => isset($_GET['empty']) ? [] : $items]);
    }
}

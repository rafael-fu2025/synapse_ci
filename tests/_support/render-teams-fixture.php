<?php

declare(strict_types=1);

// CLI-only, actual views and assets, fixture values only. No models, services or SQL.
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
session()->set(['tenant_id' => 1, 'user' => ['id' => 1, 'name' => 'Teams Fixture', 'username' => 'fixture', 'role' => 'superadmin', 'logged_in' => true]]);
$unit = explode('/', trim(parse_url($fixture['path'], PHP_URL_PATH), '/'))[0];
if (!in_array($unit, ['clinic', 'guidance', 'facilities'], true)) {
    throw new InvalidArgumentException('Unknown Teams fixture unit');
}
$base = site_url($unit . '/teams');
$payload = '<img src=x onerror="window.teamXss=1">';
$task = ['id' => 31, 'tenant_id' => 1, 'unit' => $unit, 'title' => 'Fixture task ' . $payload,
    'description' => 'Stored description ' . $payload, 'status' => 'todo', 'priority' => 'high',
    'assignee_user_id' => 1, 'created_by_user_id' => 1, 'due_date' => '2028-02-29', 'version' => 3,
    'checklist' => [['text' => 'Stored item ' . $payload, 'done' => true], ['text' => 'Second item', 'done' => false]],
    'canEdit' => true, 'comments_total' => 1, 'comments_limit' => 50, 'activity_total' => 0, 'activity_limit' => 50,
    'comments' => [['actor_user_id' => 1, 'body' => 'Comment ' . $payload, 'created_at' => '2026-10-18 00:00:00']], 'activity' => []];
$data = ['unit' => $unit, 'baseUrl' => $base, 'cancelUrl' => $base,
    'statuses' => ['todo' => 'To do', 'in_progress' => 'In progress', 'blocked' => 'Blocked', 'done' => 'Done'],
    'priorities' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'],
    'members' => [['id' => 1, 'name' => 'Fixture Member', 'role' => 'superadmin']], 'memberNames' => [1 => 'Fixture Member'],
    'actor' => ['id' => 1, 'tenant_id' => 1, 'role' => 'superadmin'], 'canManage' => true];
$path = parse_url($fixture['path'], PHP_URL_PATH);
if (str_ends_with($path, '/create') || str_ends_with($path, '/edit')) {
    $edit = str_ends_with($path, '/edit');
    $view = 'teams/form';
    $data += ['title' => $edit ? 'Edit team task #31' : 'New team task', 'task' => $edit ? $task : null,
        'formAction' => $base . ($edit ? '/31/update' : '/store'), 'formModalPage' => true];
} elseif (str_ends_with($path, '/31')) {
    $view = 'teams/show';
    $data += ['title' => 'Team task #31', 'task' => $task, 'formModalPage' => true];
} else {
    $view = 'teams/index';
    $data += ['title' => ucfirst($unit) . ' Teams', 'tasks' => [$task], 'mode' => $_GET['view'] ?? 'board',
        'filters' => ['mine' => $_GET['mine'] ?? '', 'q' => $_GET['q'] ?? '', 'status' => '', 'priority' => ''],
        'total' => 1, 'page' => 1, 'pages' => 1, 'limit' => 100, 'formModalPage' => false];
}
echo view($view, $data);

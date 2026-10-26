<?php

declare(strict_types=1);

// CLI-only renderer: production chat view and complete layout, no database access.
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
helper(['url', 'form']);
session()->set(['tenant_id' => 1, 'user' => ['id' => 1, 'name' => 'Chat Fixture', 'username' => 'fixture', 'role' => 'superadmin', 'logged_in' => true]]);
$unit = explode('/', trim(parse_url($fixture['path'], PHP_URL_PATH), '/'))[0];
if (!in_array($unit, ['clinic', 'guidance', 'facilities'], true)) {
    throw new InvalidArgumentException('Unknown chat fixture unit');
}
echo view('teams/chat', [
    'title' => ucfirst($unit) . ' Team Chat', 'unit' => $unit,
    'baseUrl' => site_url($unit . '/teams/chat'), 'teamsUrl' => site_url($unit . '/teams'),
    'actor' => ['id' => 1, 'tenant_id' => 1, 'role' => 'superadmin'], 'canManage' => true,
    'members' => [['id' => 1, 'name' => 'Chat Fixture'], ['id' => 2, 'name' => 'Other member']],
    'history' => $fixture['history'], 'afterMode' => $fixture['afterMode'] ?? false,
    'browsingHistory' => $fixture['browsingHistory'] ?? false,
    'targetMessageId' => $fixture['targetMessageId'] ?? null,
]);

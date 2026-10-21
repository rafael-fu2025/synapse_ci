<?php

// CLI-only: render the real application shell without a controller, MIS or database.
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
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, '/notification-fixture'), null, new \CodeIgniter\HTTP\UserAgent());
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
session()->set('user', ['id' => 1, 'person_id' => 11, 'name' => 'Browser Fixture',
    'username' => 'browser-fixture', 'identifier' => 'FIXTURE', 'role' => 'student', 'logged_in' => true]);
echo view('layouts/app', ['title' => 'Notification browser fixture', 'formModalPage' => true]);

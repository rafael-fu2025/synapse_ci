<?php

// CLI-only partial renderer: no controllers, models, database or upload persistence.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
config(\Config\Realtime::class)->enabled = false;
$app = config('App');
$app->baseURL = $fixture['origin'] . '/';
$app->indexPage = '';
$edit = $fixture['edit'] === true;
$path = $edit ? '/admin/carousel/23/edit' : '/admin/carousel/create';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $path;
$_GET = [];
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $path), null, new \CodeIgniter\HTTP\UserAgent());
$request->setGlobal('get', $_GET);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
session()->set(['user' => ['id' => 1, 'name' => 'Fixture Admin', 'username' => 'fixture-admin',
    'role' => 'superadmin', 'logged_in' => true], 'tenant_id' => 1]);
$slide = $edit ? ['id' => 23, 'title' => 'Existing slide', 'subtitle' => '',
    'light_image' => 'fuel-bg.jpg', 'dark_image' => 'fuel-bg.jpg',
    'fit_mode' => 'cover', 'is_active' => 1, 'sort_order' => 1] : null;
echo view('admin/carousel/_form_scripts', ['slide' => $slide]);

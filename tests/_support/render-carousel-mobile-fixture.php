<?php

declare(strict_types=1);

// CLI-only synthetic data rendered through the production view and shell; no model or DB.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
// The fixture has no realtime service; leave all production shell markup/assets intact.
config(\Config\Realtime::class)->enabled = false;
$app = config('App');
$app->baseURL = $fixture['origin'] . '/';
$app->indexPage = '';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, '/admin/carousel'), null, new \CodeIgniter\HTTP\UserAgent());
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
session()->set(['tenant_id' => 0, 'user' => ['id' => 0, 'name' => 'Synthetic Admin', 'username' => 'fixture', 'role' => 'superadmin']]);
$slides = [];
foreach ([17, 42, 93] as $index => $id) {
    $slides[] = [
        'id' => $id,
        'title' => $index === 0 ? 'A long carousel title for campus wellbeing with every word readable on a small phone' : ($index === 1 ? str_repeat('LongUnbrokenTitle', 5) : ''),
        'subtitle' => $index === 2 ? '' : 'A complete subtitle with helpful information for students and staff — ' . str_repeat('unbroken', 12),
        'light_image' => 'assets/img/favicon.png',
        'dark_image' => $index === 1 ? '' : 'assets/img/favicon.png',
        'fit_mode' => $index === 1 ? 'contain' : 'cover',
        'is_active' => $index === 1 ? 0 : 1,
    ];
}
if (!empty($fixture['empty'])) {
    $slides = [];
}
echo view('admin/carousel/index', ['title' => 'Carousel Slides', 'slides' => $slides]);

<?php
// CLI-only presentation fixture: real USB view and application layout, no hardware or SQL.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
// Keep presentation fixtures isolated from local realtime configuration.
config(\Config\Realtime::class)->enabled = false;
$app = config('App');
$app->baseURL = $fixture['origin'] . ($fixture['basePath'] ?? '') . '/';
$app->indexPage = $fixture['indexPage'] ?? '';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $fixture['path'];
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $fixture['path']), null, new \CodeIgniter\HTTP\UserAgent());
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
session()->set(['user' => ['id' => 1, 'name' => 'USB Fixture', 'username' => 'usb-fixture', 'role' => 'superadmin', 'logged_in' => true], 'tenant_id' => 1]);
$html = view('facilities/usb', [
    'title' => 'USB fixture', 'pageTitle' => 'USB fixture',
    'detail' => ['unit' => ['id' => 11, 'name' => 'North drum', 'unit_code' => 'DRUM-11'],
        'schedule' => ['version' => 3, 'enabled' => false], 'device' => ['chip_mac' => 'AA:BB:CC:DD:EE:FF']],
    'flashEnabled' => !str_contains($fixture['path'], 'gate-off'),
    'releases' => str_contains($fixture['path'], 'no-firmware') ? [] : [['id' => 'approved-v1', 'name' => 'Approved release', 'chip_family' => 'ESP32-S3', 'flash_size' => '16MB']],
]);

echo $html;

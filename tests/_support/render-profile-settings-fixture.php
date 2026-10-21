<?php
// CLI-only presentation fixture: no SQL, account writes, or photo persistence.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$app = config('App'); $app->baseURL = $fixture['origin'] . '/'; $app->indexPage = '';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
parse_str(parse_url($fixture['path'], PHP_URL_QUERY) ?? '', $_GET);
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $fixture['path']), null, new \CodeIgniter\HTTP\UserAgent());
$request->setGlobal('get', $_GET);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
$kind = ($_GET['kind'] ?? '') === 'employee' ? 'employee' : 'student';
$actor = ['id' => 1, 'person_id' => 999999999, 'tenant_id' => 999999999, 'name' => 'Synthetic ' . ucfirst($kind), 'role' => $kind,
    'email' => 'fixture@example.test', 'permissions' => [], 'logged_in' => true];
session()->set(['user' => $actor, 'tenant_id' => $actor['tenant_id']]);
$person = ['id' => $actor['person_id'], 'kind' => $kind, 'mis_id' => 'FIXTURE-123', 'first_name' => 'Synthetic', 'middle_name' => '',
    'last_name' => ucfirst($kind), 'email' => 'fixture@example.test'];
$actor['avatar_initials'] = \App\Services\ProfileAvatar::initials($person);
session()->set('user', $actor);
$hasPhoto = isset($_GET['uploaded']) || isset($_GET['saved']);
if (isset($_GET['uploaded'])) session()->setFlashdata('success', 'Profile picture updated. Only the compressed image was stored.');
if (parse_url($fixture['path'], PHP_URL_PATH) !== '/portal/settings') {
    echo view('announcements/portal', ['title' => 'Announcements', 'baseUrl' => site_url('portal/announcements'),
        'announcements' => [], 'filters' => ['q' => '', 'unit' => 'all', 'status' => 'all'],
        'counts' => ['all' => 0, 'unread' => 0, 'read' => 0],
        'pagination' => ['page' => 1, 'pages' => 1, 'filtered' => 0, 'total' => 0, 'per_page' => 25]]);
} else {
    echo view('portal/settings', ['title' => 'Profile & Settings', 'actor' => $actor, 'person' => $person,
        'cancelUrl' => site_url('portal'), 'formModalPage' => true, 'modalSize' => '',
        'defaultAvatar' => \App\Services\ProfileAvatar::dataUri($person),
        'photoUrl' => $hasPhoto ? site_url('portal/settings/photo') : \App\Services\ProfileAvatar::dataUri($person), 'hasPhoto' => $hasPhoto]);
}

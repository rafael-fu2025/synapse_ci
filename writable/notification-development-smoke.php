<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . '/public/');
require dirname(__DIR__) . '/app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootConsole($paths);
$c = config('Database')->default;
if (env('CI_ENVIRONMENT') !== 'development' || ($c['database'] ?? '') !== 'humus_db'
    || !in_array($c['hostname'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
    || !empty($c['DSN']) || !empty($c['failover']) || !empty($c['DBPrefix'])
    || ($c['DBDriver'] ?? '') !== 'MySQLi' || (int) ($c['port'] ?? 0) === 33307) {
    throw new RuntimeException('Explicit local development database required.');
}
config('Database')->defaultGroup = 'default';
$db = \Config\Database::connect('default');
if ($db->query('SELECT DATABASE() AS name')->getRow()->name !== 'humus_db') { throw new RuntimeException('Database mismatch.'); }
$db->query('START TRANSACTION READ ONLY');
try {
    if ($db->table(config('Migrations')->table)->where('version', '2026-10-16-000002')
        ->where('namespace', 'App')->where('group', 'default')->countAllResults() !== 1) {
        throw new RuntimeException('Notification migration is not installed.');
    }
    $admin = $db->table('users')->select('id,tenant_id')->where('role', 'superadmin')
        ->where('status', 'active')->where('deleted_at', null)->get(1)->getRowArray();
    if (!$admin) { throw new RuntimeException('Existing administrator required.'); }
    session()->set(['tenant_id' => (int) $admin['tenant_id'], 'user' => [
        'id' => (int) $admin['id'], 'tenant_id' => (int) $admin['tenant_id'], 'logged_in' => true,
    ]]);
    if (!(new \App\Services\AuthenticatedUser())->current()) { throw new RuntimeException('Invalid administrator.'); }
    helper(['url', 'form']);
    $inbox = (new \App\Services\NotificationService())->ownInbox();
    $app = config('App');
    $request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, 'notifications'), null, new \CodeIgniter\HTTP\UserAgent());
    $request->setMethod('GET');
    \Config\Services::injectMock('request', $request);
    \Config\Services::injectMock('uri', $request->getUri());
    $controller = new \App\Controllers\NotificationController();
    $controller->initController($request, service('response'), service('logger'));
    $response = $controller->index();
    if ($response->getStatusCode() !== 200 || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')) {
        throw new RuntimeException('Notification response check failed.');
    }
    \Config\Services::resetSingle('renderer');
    $html = view('portal/index', ['title' => 'Read-only verification', 'chart' => [], 'activeTicket' => null]);
    if (!str_contains($html, 'id="notificationBell"') || !str_contains($html, 'id="my-appointments"')
        || !str_contains($html, 'assets/js/notifications.js')) { throw new RuntimeException('Portal/header rendering failed.'); }
    echo json_encode(['status' => 'passed', 'migration_installed' => true, 'notification_response' => 200,
        'private_no_store' => true, 'personal_inbox_items' => count($inbox['items']),
        'portal_and_bell_rendered' => true, 'database_writes_performed' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    $db->query('ROLLBACK');
    \App\Services\AuthenticatedUser::clear();
    if (session_status() === PHP_SESSION_ACTIVE) { session()->destroy(); }
}

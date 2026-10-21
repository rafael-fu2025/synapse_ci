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
    if (is_file(WRITEPATH . 'scheduling-maintenance.flag')) { throw new RuntimeException('Maintenance is still active.'); }
    if ($db->table(config('Migrations')->table)->where('version', '2026-10-16-000003')->where('namespace', 'App')
        ->where('group', 'default')->countAllResults() !== 1) { throw new RuntimeException('Referral migration is not installed.'); }
    $admin = $db->table('users')->select('id,tenant_id')->where('role', 'superadmin')->where('status', 'active')->where('deleted_at', null)->get(1)->getRowArray();
    if (!$admin) { throw new RuntimeException('Existing administrator required.'); }
    session()->set(['tenant_id' => (int) $admin['tenant_id'], 'user' => [
        'id' => (int) $admin['id'], 'tenant_id' => (int) $admin['tenant_id'], 'logged_in' => true,
    ]]);
    if (!(new \App\Services\AuthenticatedUser())->current()) { throw new RuntimeException('Invalid administrator.'); }
    helper(['url', 'form']);
    $checks = [];
    foreach (['clinic', 'guidance'] as $unit) {
        foreach (['incoming', 'sent', 'history', 'create'] as $tab) {
            \Config\Services::resetSingle('renderer');
            \Config\Services::resetSingle('response');
            $app = config('App');
            $path = $unit . '/referrals' . ($tab === 'create' ? '/create' : '');
            $query = $tab === 'create' ? [] : ['tab' => $tab];
            $request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $path), null, new \CodeIgniter\HTTP\UserAgent());
            $request->setMethod('GET')->setGlobal('get', $query);
            \Config\Services::injectMock('request', $request);
            \Config\Services::injectMock('uri', $request->getUri());
            $controller = new \App\Controllers\ReferralController();
            $response = service('response');
            $controller->initController($request, $response, service('logger'));
            $result = $tab === 'create' ? $controller->create($unit) : $controller->index($unit);
            $body = is_string($result) ? $result : $result->getBody();
            $status = is_string($result) ? $response->getStatusCode() : $result->getStatusCode();
            $marker = $tab === 'create' ? 'name="request_key"' : 'data-table-pagination';
            if ($status !== 200 || !str_contains($body, $marker)) { throw new RuntimeException('Referral page rendering failed.'); }
            if ($tab === 'create' && (str_contains($body, 'name="source_module"') || str_contains($body, 'name="target_module"'))) {
                throw new RuntimeException('Manual routing fields must not be present.');
            }
            $checks[$unit . '_' . $tab] = 200;
        }
    }
    $inbox = (new \App\Services\NotificationService())->ownInbox();
    echo json_encode(['status' => 'passed', 'migration_installed' => true, 'render_checks' => $checks,
        'personal_notification_items' => count($inbox['items']), 'database_writes_performed' => false,
        'referrals_created' => 0, 'clinical_records_created' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    $db->query('ROLLBACK');
    \App\Services\AuthenticatedUser::clear();
    if (session_status() === PHP_SESSION_ACTIVE) { session()->destroy(); }
}

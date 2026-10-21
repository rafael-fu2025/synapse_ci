<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . '/public/');
require dirname(__DIR__) . '/app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootConsole($paths);
if (env('CI_ENVIRONMENT') !== 'development') { throw new RuntimeException('Development environment required.'); }
$config = config('Database');
if ($config->default['database'] !== 'humus_db' || !in_array($config->default['hostname'], ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Expected explicit local development database.');
}
$config->defaultGroup = 'default';
$db = \Config\Database::connect('default');
if ($db->query('SELECT DATABASE() AS name')->getRow()->name !== 'humus_db') {
    throw new RuntimeException('Database identity mismatch.');
}
\App\Services\CurrentTenant::set(1);
$inventory = new \App\Services\Clinic\InventoryService($db);
if (!$inventory->isInstalled()) { throw new RuntimeException('Inventory migration is not installed.'); }
$actor = $db->table('users')->select('id, role')->where('tenant_id', 1)->where('role', 'superadmin')->where('status', 'active')->where('deleted_at', null)->get()->getRowArray();
if (!$actor) { throw new RuntimeException('No existing active platform administrator for read-only rendering verification.'); }
$medicine = $db->table('clinic_medicines')->select('id')->where('tenant_id', 1)->orderBy('id')->get(1)->getRowArray();
$checks = [['index', [], 'clinic/inventory', 'Medicine Inventory'], ['create', [], 'clinic/inventory/create', 'idempotency_key']];
if ($medicine) {
    $checks[] = ['show', [(int) $medicine['id']], 'clinic/inventory/' . $medicine['id'], 'Stock movements'];
    $checks[] = ['receiveForm', [(int) $medicine['id']], 'clinic/inventory/' . $medicine['id'] . '/receive', 'expiration_date'];
}
$results = [];
helper(['url', 'form']);
session()->set(['tenant_id' => 1, 'user' => ['id' => (int) $actor['id'], 'tenant_id' => 1, 'role' => $actor['role'], 'name' => 'Read-only verification', 'logged_in' => true]]);
try {
    foreach ($checks as [$method, $args, $path, $marker]) {
        \Config\Services::resetSingle('renderer');
        \Config\Services::resetSingle('response');
        $app = config('App');
        $_SERVER['HTTP_HOST'] = 'localhost:8080';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $path), null, new \CodeIgniter\HTTP\UserAgent());
        $request->setGlobal('get', []);
        \Config\Services::injectMock('request', $request);
        \Config\Services::injectMock('uri', $request->getUri());
        $response = service('response');
        $controller = new \App\Controllers\ClinicInventoryController();
        $controller->initController($request, $response, service('logger'));
        $result = $controller->$method(...$args);
        $body = is_string($result) ? $result : $result->getBody();
        $status = is_string($result) ? $response->getStatusCode() : $result->getStatusCode();
        if ($status !== 200 || !str_contains($body, $marker) || str_contains($body, 'Inventory request failed.')) {
            throw new RuntimeException('Read-only rendering failed for ' . $method . '; status=' . $status);
        }
        $results[$method] = ['status' => $status, 'rendered' => true];
    }
    $drift = $db->query('SELECT b.id FROM clinic_medicine_batches b LEFT JOIN stock_movements s ON s.batch_id=b.id AND s.medicine_id=b.medicine_id AND s.tenant_id=b.tenant_id GROUP BY b.id,b.remaining_quantity HAVING b.remaining_quantity<>COALESCE(SUM(s.delta),0)')->getResultArray();
    if ($drift !== []) { throw new RuntimeException('Per-batch ledger balance discrepancy.'); }
    $triggers = (int) $db->query("SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'inv_%'")->getRow()->n;
    if ($triggers !== 6) { throw new RuntimeException('Expected six inventory history triggers.'); }
    echo json_encode(['database' => 'humus_db', 'inventory_installed' => true, 'read_only_controller_checks' => $results,
        'batch_ledger_discrepancies' => count($drift), 'append_only_triggers' => $triggers, 'stock_mutations_performed' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    session()->remove(['user', 'tenant_id']);
    if (session_status() === PHP_SESSION_ACTIVE) { session()->destroy(); }
    \App\Services\CurrentTenant::reset();
}

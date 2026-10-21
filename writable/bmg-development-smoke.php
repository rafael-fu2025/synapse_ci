<?php

declare(strict_types=1);

// Controller/full-layout GET smoke only; no fixtures, hardware calls or account creation.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['superadmin-id:', 'hardware-gate-only']);
$gateOnly = isset($options['hardware-gate-only']);
$id = filter_var($options['superadmin-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$gateOnly && !$id) { fwrite(STDERR, "Required: --superadmin-id=<explicit existing real identity>\n"); exit(1); }
define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . '/public/');
require dirname(__DIR__) . '/app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootConsole($paths);
$c = config('Database')->default;
$port = (int) ($c['port'] ?? 0);
if (env('CI_ENVIRONMENT') !== 'development' || config('Database')->defaultGroup !== 'default'
    || ($c['database'] ?? '') !== 'humus_db' || ($c['DBDriver'] ?? '') !== 'MySQLi'
    || !in_array($c['hostname'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
    || !empty($c['DSN']) || !empty($c['failover']) || !empty($c['DBPrefix']) || !empty($c['pConnect'])
    || $port < 1 || $port > 65535 || $port === 33307) { throw new RuntimeException('Explicit local development identity required.'); }
$db = \Config\Database::connect('default');
$identity = $db->query('SELECT DATABASE() AS name, @@port AS port')->getRowArray();
if ($identity['name'] !== 'humus_db' || (int) $identity['port'] !== $port) { throw new RuntimeException('Actual database/server mismatch.'); }
$db->query('START TRANSACTION READ ONLY');
try {
    // Use the exact boolean interpretation used by the controller and rotation service.
    $hardwareEnabled = in_array(env('BMG_HARDWARE_ENABLED', false), [true, 'true'], true);
    if ($gateOnly) {
        echo json_encode(['status' => $hardwareEnabled ? 'failed' : 'passed', 'hardware_enabled' => $hardwareEnabled,
            'scope' => 'hardware_gate_only; no page renders or session identity', 'database' => $identity['name'],
            'database_transaction' => 'READ ONLY', 'database_writes_performed' => false, 'hardware_operations' => 0],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if ($hardwareEnabled) { throw new RuntimeException('Main hardware gate must remain disabled.'); }
        return;
    }
    if ($hardwareEnabled) { throw new RuntimeException('Main hardware gate must remain disabled.'); }
    if (is_file(__DIR__ . '/bmg-maintenance.flag')) { throw new RuntimeException('BMG maintenance remains active.'); }
    define('SYNAPSE_BMG_LIBRARY_ONLY', true);
    require __DIR__ . '/bmg-development-migrate.php';
    foreach (bmgTargets() as $version => $class) {
        if ($db->table(config('Migrations')->table)->where(['version' => $version, 'class' => 'App\\Database\\Migrations\\' . $class, 'namespace' => 'App', 'group' => 'default'])->countAllResults() !== 1) {
            throw new RuntimeException('Exact BMG migrations required before smoke.');
        }
    }
    $user = $db->table('users')->select('id,tenant_id')->where(['id' => $id, 'role' => 'superadmin', 'status' => 'active', 'deleted_at' => null])->get()->getRowArray();
    if (!$user) { throw new RuntimeException('Explicit existing active superadmin required; no accounts created.'); }
    helper(['url', 'form']);
    \App\Services\AuthenticatedUser::clear();
    session()->set(['tenant_id' => (int) $user['tenant_id'], 'user' => ['id' => (int) $user['id'], 'tenant_id' => (int) $user['tenant_id'], 'logged_in' => true]]);
    $actor = (new \App\Services\AuthenticatedUser())->current();
    if (!$actor || $actor['role'] !== 'superadmin' || (int) $actor['id'] !== $id) { throw new RuntimeException('Database-refreshed actor mismatch.'); }
    $render = static function (string $path, string $method, array $args, string $marker, string $controllerClass = \App\Controllers\FacilitiesController::class): array {
        \Config\Services::resetSingle('renderer');
        \Config\Services::resetSingle('response');
        $app = config('App');
        $request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $path), null, new \CodeIgniter\HTTP\UserAgent());
        $request->setMethod('GET')->setGlobal('get', []);
        \Config\Services::injectMock('request', $request);
        \Config\Services::injectMock('uri', $request->getUri());
        $controller = new $controllerClass();
        $response = service('response');
        $controller->initController($request, $response, service('logger'));
        $result = $controller->$method(...$args);
        $body = is_string($result) ? $result : $result->getBody();
        $response = is_string($result) ? $response : $result;
        if ($response->getStatusCode() !== 200 || !str_contains(strtolower($body), '<html') || !str_contains($body, $marker)
            || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')) { throw new RuntimeException('Full-page read-only render failed.'); }
        return ['status' => 200, 'full_page' => true, 'private_no_store' => true];
    };
    $checks = ['GET /facilities' => $render('facilities', 'index', [], 'Drum Rotation')];
    $unit = $db->table('bmg_units')->select('id')->where('tenant_id', $user['tenant_id'])->orderBy('id')->get(1)->getRowArray();
    $checks['GET /facilities/unit/{existing}'] = $unit
        ? $render('facilities/unit/' . (int) $unit['id'], 'unitView', [(int) $unit['id']], 'Drum Operations')
        : ['status' => 'skipped', 'reason' => 'No existing unit for the selected identity; no fixtures created.'];
    $checks['GET /facilities/unit/{existing}/usb'] = $unit
        ? $render('facilities/unit/' . (int) $unit['id'] . '/usb', 'index', [(int) $unit['id']], 'data-esptool-url', \App\Controllers\BmgUsbController::class)
        : ['status' => 'skipped', 'reason' => 'No existing unit; no fixtures created.'];
    echo json_encode(['status' => $unit ? 'passed' : 'passed_with_skips', 'render_checks' => $checks,
        'actor' => 'explicit_existing_database_refreshed_superadmin', 'database_transaction' => 'READ ONLY',
        'database_writes_performed' => false, 'hardware_operations' => 0, 'hardware_enabled' => $hardwareEnabled,
                'usb_flash_enabled' => in_array(env('BMG_USB_FLASH_ENABLED', false), [true, 'true'], true),
        'scope' => 'controller_and_full_layout; not HTTP route/filter coverage'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    $db->query('ROLLBACK');
    if (!$gateOnly) {
        \App\Services\AuthenticatedUser::clear();
        if (session_status() === PHP_SESSION_ACTIVE) { session()->destroy(); }
    }
    $db->close();
}

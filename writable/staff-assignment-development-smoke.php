<?php

declare(strict_types=1);

// No enrollment, account creation, schedule changes, migrations or seeds.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$stage = 'development connection guard';
$db = null;
$sessionStarted = false;
$transaction = false;
$failed = false;
try {
    define('ENVIRONMENT', 'development');
    define('FCPATH', dirname(__DIR__) . '/public/');
    require dirname(__DIR__) . '/app/Config/Paths.php';
    $paths = new \Config\Paths();
    require $paths->systemDirectory . '/Boot.php';
    \CodeIgniter\Boot::bootConsole($paths);
    $config = config('Database');
    $c = $config->default;
    $port = (int) ($c['port'] ?? 0);
    if (env('CI_ENVIRONMENT') !== 'development' || $config->defaultGroup !== 'default'
        || ($c['database'] ?? '') !== 'humus_db' || ($c['DBDriver'] ?? '') !== 'MySQLi'
        || !in_array($c['hostname'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
        || $port < 1 || $port > 65535 || $port === 33307 || !empty($c['DSN']) || !empty($c['failover'])
        || !empty($c['DBPrefix']) || !empty($c['pConnect'])) {
        throw new RuntimeException('Explicit local development default connection required.');
    }
    if (is_file(WRITEPATH . 'scheduling-maintenance.flag')) {
        throw new RuntimeException('Maintenance is active; finish manual verification first.');
    }
    $config->default['DBDebug'] = false;
    $db = \Config\Database::connect('default');
    $query = static function (string $sql, array $bindings = []) use ($db) {
        $result = $db->query($sql, $bindings);
        if ($result === false) { throw new RuntimeException('Read-only query failed.'); }
        return $result;
    };
    $identity = $query('SELECT DATABASE() AS name, @@port AS port')->getRowArray();
    if ($identity['name'] !== 'humus_db' || (int) $identity['port'] !== $port || (int) $identity['port'] === 33307) {
        throw new RuntimeException('Actual database/server port mismatch.');
    }
    // Enforce read-only behavior for shared default-connection models/controllers as well.
    $query('SET SESSION lock_wait_timeout = 5');
    $query('SET SESSION innodb_lock_wait_timeout = 5');
    $query('SET SESSION TRANSACTION READ ONLY');
    $query('START TRANSACTION READ ONLY');
    $transaction = true;
    $stage = 'installed migration check';
    $history = $db->table(config('Migrations')->table)->where('version', '2026-10-16-000001')
        ->where('namespace', 'App')->where('group', 'default')
        ->where('class', 'App\\Database\\Migrations\\AddDepartmentStaffAssignments')->countAllResults();
    if ($history !== 1 || !$db->tableExists('department_staff')) {
        throw new RuntimeException('Expected exact installed migration.');
    }
    $stage = 'existing administrator refresh';
    helper(['url', 'form']);
    $sessionStarted = true;
    $auth = new \App\Services\AuthenticatedUser();
    $actor = null;
    $candidates = $db->table('users')->select('id, tenant_id')->where('role', 'superadmin')
        ->where('status', 'active')->where('deleted_at', null)->orderBy('id')->get()->getResultArray();
    foreach ($candidates as $candidate) {
        // Real tenant identity, never assume tenant 1 or trust a synthetic permission array.
        session()->set(['tenant_id' => (int) $candidate['tenant_id'], 'user' => [
            'id' => (int) $candidate['id'], 'tenant_id' => (int) $candidate['tenant_id'], 'logged_in' => true,
        ]]);
        $actor = $auth->current();
        if ($actor !== null && $actor['role'] === 'superadmin') { break; }
    }
    if ($actor === null || $actor['role'] !== 'superadmin') { throw new RuntimeException('No valid existing administrator.'); }

    $service = new \App\Services\Scheduling\AppointmentService();
    $counts = [];
    $pages = [];
    foreach (['clinic', 'guidance'] as $unit) {
        $stage = $unit . ' read-only service checks';
        $roster = $service->departmentStaff($unit, $actor);
        $counts[$unit] = [
            'department_staff' => count($roster),
            'eligible' => count(array_filter($roster, static fn (array $row): bool => $row['eligible'])),
            'can_enroll' => count(array_filter($roster, static fn (array $row): bool => $row['can_enroll'])),
            'availability_windows' => count($service->getAvailability($unit, $actor)),
            'providers' => count($service->providers($unit)),
        ];
        foreach ([['index', '', 'Appointments Calendar'], ['create', '/create', 'appointmentRequest'],
            ['availability', '/availability', 'data-department-staff']] as [$method, $suffix, $marker]) {
            $stage = $unit . ' ' . $method . ' rendering';
            \Config\Services::resetSingle('renderer');
            \Config\Services::resetSingle('response');
            $app = config('App');
            $_SERVER['HTTP_HOST'] = 'localhost:8080';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET = [];
            $request = new \CodeIgniter\HTTP\IncomingRequest($app,
                new \CodeIgniter\HTTP\SiteURI($app, $unit . '/appointments' . $suffix), null, new \CodeIgniter\HTTP\UserAgent());
            $request->setGlobal('get', []);
            \Config\Services::injectMock('request', $request);
            \Config\Services::injectMock('uri', $request->getUri());
            $response = service('response');
            $controller = new \App\Controllers\AppointmentController();
            $controller->initController($request, $response, service('logger'));
            $result = $controller->$method($unit);
            $body = is_string($result) ? $result : $result->getBody();
            $status = is_string($result) ? $response->getStatusCode() : $result->getStatusCode();
            if ($status !== 200 || !str_contains($body, $marker)) { throw new RuntimeException('Page render failed.'); }
            $pages[$unit][$method] = ['status' => $status, 'rendered' => true];
        }
    }
    echo json_encode(['status' => 'passed', 'counts' => $counts, 'pages' => $pages,
        'database_read_only' => true, 'enrollments_performed' => 0, 'schedule_changes' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    // Do not print rendered bodies, user identities, credentials or SQL exception values.
    fwrite(STDERR, json_encode(['status' => 'failed', 'stage' => $stage], JSON_THROW_ON_ERROR) . "\n");
    $failed = true;
} finally {
    if ($transaction) {
        try { $db->query('ROLLBACK'); } catch (Throwable) { /* Closing ends the read-only transaction. */ }
    }
    if ($sessionStarted) {
        \App\Services\AuthenticatedUser::clear();
        if (session_status() === PHP_SESSION_ACTIVE) { session()->destroy(); }
    }
    if ($db !== null) { $db->close(); }
}
if ($failed) { exit(1); }

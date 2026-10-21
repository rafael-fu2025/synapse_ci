<?php

declare(strict_types=1);

// Read-only full-page controller/view smoke, not a replacement for route/filter tests.
// No show/read/submit/publish endpoints, fixture inserts or account changes are used.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . '/public/');
require dirname(__DIR__) . '/app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootConsole($paths);
$surveyKey = env('SURVEY_KEY');
$surveyKeyPresent = is_string($surveyKey) && trim($surveyKey) !== '';
unset($surveyKey);
$c = config('Database')->default;
$port = (int) ($c['port'] ?? 0);
if (env('CI_ENVIRONMENT') !== 'development' || ($c['database'] ?? '') !== 'humus_db'
    || !in_array($c['hostname'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
    || !empty($c['DSN']) || !empty($c['failover']) || !empty($c['DBPrefix']) || !empty($c['pConnect'])
    || ($c['DBDriver'] ?? '') !== 'MySQLi' || $port < 1 || $port > 65535 || $port === 33307) {
    throw new RuntimeException('Explicit local development database required.');
}
config('Database')->defaultGroup = 'default';
$db = \Config\Database::connect('default');
$identity = $db->query('SELECT DATABASE() AS name, @@port AS port')->getRowArray();
if ($identity['name'] !== 'humus_db' || (int) $identity['port'] !== $port) { throw new RuntimeException('Database/server mismatch.'); }
$db->query('START TRANSACTION READ ONLY');
try {
    if (is_file(WRITEPATH . 'scheduling-maintenance.flag')) { throw new RuntimeException('Maintenance is still active.'); }
    define('SYNAPSE_AUDIENCE_LIBRARY_ONLY', true);
    require __DIR__ . '/audience-development-migrate.php';
    foreach (audienceTargets() as $version => $class) {
        if ($db->table(config('Migrations')->table)->where('version', $version)->where('namespace', 'App')
            ->where('group', 'default')->where('class', 'App\\Database\\Migrations\\' . $class)->countAllResults() !== 1) {
            throw new RuntimeException('Exact audience migration must be installed before smoke.');
        }
    }
    helper(['url', 'form']);
    $activate = static function (string $role) use ($db): bool {
        \App\Services\AuthenticatedUser::clear();
        $candidates = $db->table('users')->select('id,tenant_id')->where('role', $role)->where('status', 'active')
            ->where('deleted_at', null)->orderBy('id')->get()->getResultArray();
        foreach ($candidates as $candidate) {
            session()->set(['tenant_id' => (int) $candidate['tenant_id'], 'user' => [
                'id' => (int) $candidate['id'], 'tenant_id' => (int) $candidate['tenant_id'], 'logged_in' => true,
            ]]);
            $actor = (new \App\Services\AuthenticatedUser())->current();
            if ($actor !== null && $actor['role'] === $role && ($role !== 'student' || !empty($actor['person_id']))) { return true; }
        }
        \App\Services\AuthenticatedUser::clear();
        return false;
    };
    $render = static function (string $path, string $class, string $method, array $args, string $marker): array {
        \Config\Services::resetSingle('renderer');
        \Config\Services::resetSingle('response');
        $app = config('App');
        $request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $path), null, new \CodeIgniter\HTTP\UserAgent());
        $request->setMethod('GET')->setGlobal('get', []);
        \Config\Services::injectMock('request', $request);
        \Config\Services::injectMock('uri', $request->getUri());
        if (!(new \App\Services\AuthenticatedUser())->current()) { throw new RuntimeException('Actor refresh failed.'); }
        $controller = new $class();
        $response = service('response');
        $controller->initController($request, $response, service('logger'));
        $result = $controller->$method(...$args);
        $body = is_string($result) ? $result : $result->getBody();
        $status = is_string($result) ? $response->getStatusCode() : $result->getStatusCode();
        $headers = is_string($result) ? $response : $result;
        if ($status !== 200 || !str_contains(strtolower($body), '<html') || !str_contains($body, $marker)
            || !str_contains($headers->getHeaderLine('Cache-Control'), 'no-store')) {
            throw new RuntimeException('Full-page read-only render failed: ' . $path);
        }
        return ['status' => $status, 'full_page' => true, 'private_no_store' => true];
    };
    if (!$activate('superadmin')) { throw new RuntimeException('Existing valid superadmin required; smoke never creates accounts.'); }
    $actors = ['superadmin'];
    $checks = [];
    foreach (['clinic', 'guidance'] as $unit) {
        $checks[$unit . '/announcements'] = $render($unit . '/announcements', \App\Controllers\AnnouncementController::class, 'index', [$unit], 'aria-label="Announcement pages"');
        $checks[$unit . '/announcements/create'] = $render($unit . '/announcements/create', \App\Controllers\AnnouncementController::class, 'create', [$unit], 'data-modal-submit');
    }
    $checks['guidance/surveys'] = $render('guidance/surveys', \App\Controllers\SurveyController::class, 'index', [], 'data-table-pagination');
    $checks['guidance/surveys/create'] = $render('guidance/surveys/create', \App\Controllers\SurveyController::class, 'create', [], 'data-modal-submit');
    $checks['portal/announcements'] = $render('portal/announcements', \App\Controllers\AnnouncementController::class, 'portalIndex', [], 'aria-label="Announcement pages"');
    $checks['portal/announcements']['actor'] = 'superadmin';
    $hasStudent = $activate('student');
    if ($hasStudent) {
        $actors[] = 'student';
        $checks['portal/announcements (student)'] = $render('portal/announcements', \App\Controllers\AnnouncementController::class, 'portalIndex', [], 'aria-label="Announcement pages"');
        $checks['portal/surveys'] = $render('portal/surveys', \App\Controllers\PortalSurveyController::class, 'index', [], 'data-table-pagination');
        $checks['portal/surveys']['actor'] = 'student';
    } else {
        $checks['portal/surveys'] = ['status' => 'skipped', 'reason' => 'No existing valid student; smoke never creates accounts.'];
    }
    $stats = [];
    foreach (audienceColumns() as $table => $columns) {
        $stats[$table]['rows'] = $db->table($table)->countAllResults();
        foreach ($columns as $column => $type) {
            $meta = $db->query('SELECT IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $column])->getRowArray();
            if (!$meta || $meta['IS_NULLABLE'] !== 'YES' || !in_array($meta['COLUMN_DEFAULT'], [null, 'NULL'], true)) { throw new RuntimeException('Nullable audience metadata missing.'); }
            $stats[$table][$column] = ['null' => $db->table($table)->where($column, null)->countAllResults(),
                'known' => $db->table($table)->where($column . ' IS NOT NULL', null, false)->countAllResults()];
        }
    }
    $stats['student_profiles']['year_level_unknown'] = $db->table('student_profiles')->where('year_level', null)->countAllResults();
    echo json_encode(['column_counts_only' => $stats, 'status' => $hasStudent ? 'passed' : 'passed_with_skips', 'exact_migrations_installed' => true, 'render_checks' => $checks,
        'actors' => ['database_refreshed_existing_roles' => $actors],
        'student_portal_coverage' => $hasStudent ? ['status' => 'passed'] : ['status' => 'skipped', 'reason' => 'No existing valid student.'],
        'survey_key_present' => $surveyKeyPresent, 'database_transaction' => 'READ ONLY',
        'database_writes_performed' => false, 'read_markers_created' => 0, 'responses_submitted' => 0,
        'notifications_created' => 0, 'published_records_created' => 0,
        'scope' => 'controller_and_full_layout; route/filter coverage belongs to isolated tests'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    $db->query('ROLLBACK');
    \App\Services\AuthenticatedUser::clear();
    if (session_status() === PHP_SESSION_ACTIVE) { session()->destroy(); }
}

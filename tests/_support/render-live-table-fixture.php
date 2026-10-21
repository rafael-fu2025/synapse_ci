<?php

// CLI presentation fixture only: no controllers, models, database or application writes.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new \ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
$app = config('App');
$app->baseURL = $fixture['origin'] . '/';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $fixture['path'];
parse_str(parse_url($fixture['path'], PHP_URL_QUERY) ?? '', $_GET);
// Exercise real site_url() canonicalization without rewriting rendered markup.
$app->indexPage = ($_GET['index'] ?? '') === '1' || str_starts_with(parse_url($fixture['path'], PHP_URL_PATH), '/index.php/')
    ? 'index.php' : '';
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $fixture['path']), null, new \CodeIgniter\HTTP\UserAgent());
$request->setGlobal('get', $_GET);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
$profile = $fixture['profile'] ?? 'manager';
if (!in_array($profile, ['manager', 'staff', 'reader'], true)) throw new \InvalidArgumentException('Unknown profile');
session()->set(['user' => ['id' => 1, 'name' => 'Live Table Fixture', 'username' => 'live-table-fixture',
    'role' => $profile === 'manager' ? 'superadmin' : 'clinic_staff', 'logged_in' => true], 'tenant_id' => 1]);
$literal = '\"><img src=x onerror="window.liveTableXss=1"><script>window.liveTableXss=1</script>&';
$rows = [];
for ($id = 1; $id <= 63; $id++) {
    $name = sprintf('record%02d', $id);
    $active = $id % 5 !== 0;
    $rows[] = ['id' => $id, 'mis_id' => sprintf('FIX-%03d', $id),
        'first_name' => $id === 63 ? $literal : $name, 'last_name' => 'Fixture', 'middle_name' => '',
        'email' => $name . '@example.invalid', 'kind' => ['student', 'employee', 'guest'][($id - 1) % 3],
        'program_or_dept' => 'Fixture department', 'gender' => 'female', 'blood_type' => 'O+',
        'name' => $id === 63 ? $literal : $name, 'generic_name' => $id === 63 ? $literal : 'Generic ' . $name,
        'category' => 'Fixture', 'dosage_form' => 'tablet', 'strength' => '500 mg', 'unit_of_measure' => 'tablet',
        'minimum_stock_level' => 12, 'is_active' => (int) $active, 'version' => 1,
        'usable_stock' => $id % 2 === 0 ? 10 : 20, 'total_stock' => 30,
        'expired_stock' => $id % 7 === 0 ? 2 : 0, 'quarantined_stock' => $id % 9 === 0 ? 3 : 0,
        'near_expiry_stock' => $id % 4 === 0 ? 4 : 0, 'low_stock' => $active && $id % 2 === 0];
}
$patient = $fixture['kind'] === 'patients';
$baseUrl = site_url($patient ? '/clinic/patients' : '/clinic/inventory');
$data = ['title' => 'Live Table Fixture', 'pageTitle' => $patient ? 'Patient Registry' : 'Medicine Inventory',
    'baseUrl' => $baseUrl, 'canManage' => $profile === 'manager', 'canWrite' => $profile !== 'reader',
    'canStartVisit' => $profile !== 'reader'];
if ($fixture['kind'] === 'receive') {
    $id = (int) $fixture['id'];
    $data['title'] = 'Receive Medicine';
    $data += ['formModalPage' => true, 'medicine' => $rows[$id - 1],
        'formAction' => site_url('/clinic/inventory/' . $id . '/receive'), 'cancelUrl' => $baseUrl,
        'idempotencyKey' => 'live-table-fixture-receive-key'];
    echo view('inventory/receive', $data);
    exit;
}
if (!in_array($fixture['kind'], ['patients', 'inventory'], true)) throw new \InvalidArgumentException('Unknown table');
// Mock only the query response. This literal substring match is NOT a SQL/security test.
$q = trim((string) ($_GET['q'] ?? ''));
$filterName = $patient ? 'kind' : 'status';
$allowed = $patient ? ['all', 'student', 'employee', 'guest'] : ['all', 'active', 'archived', 'low_stock'];
$filter = in_array($_GET[$filterName] ?? 'all', $allowed, true) ? ($_GET[$filterName] ?? 'all') : 'all';
$perPage = in_array((int) ($_GET['per_page'] ?? 25), [25, 50, 100], true) ? (int) ($_GET['per_page'] ?? 25) : 25;
$filtered = array_values(array_filter($rows, static function (array $row) use ($patient, $q, $filter): bool {
    $text = $patient ? $row['mis_id'] . ' ' . $row['first_name'] . ' ' . $row['last_name'] : $row['name'] . ' ' . $row['generic_name'];
    if ($q !== '' && stripos($text, $q) === false) return false;
    return $patient ? ($filter === 'all' || $row['kind'] === $filter) : match ($filter) {
        'active' => $row['is_active'] === 1, 'archived' => $row['is_active'] === 0,
        'low_stock' => $row['low_stock'], default => true,
    };
}));
$count = count($filtered);
$pages = max(1, (int) ceil($count / $perPage));
$page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
$counts = ['low' => 0, 'expired' => 0, 'quarantined' => 0, 'near' => 0];
foreach ($filtered as $row) {
    $counts['low'] += (int) $row['low_stock'];
    $counts['expired'] += (int) ($row['expired_stock'] > 0);
    $counts['quarantined'] += (int) ($row['quarantined_stock'] > 0);
    $counts['near'] += (int) ($row['near_expiry_stock'] > 0);
}
$data += ['filters' => ['q' => $q, $filterName => $filter, 'per_page' => $perPage], 'counts' => $counts,
    'pagination' => ['page' => $page, 'per_page' => $perPage, 'filtered' => $count, 'total' => count($rows),
        'pages' => $pages, 'from' => $count === 0 ? 0 : ($page - 1) * $perPage + 1, 'to' => min($page * $perPage, $count)],
    $patient ? 'patients' : 'medicines' => array_slice($filtered, ($page - 1) * $perPage, $perPage)];
$view = $patient ? 'clinic/patients' : 'inventory';
if (($_GET['fragment'] ?? '') === '1') {
    echo '<div data-table-content>' . view($view . '/_results', $data) . '</div>';
} else {
    echo view($view . '/index', $data);
}

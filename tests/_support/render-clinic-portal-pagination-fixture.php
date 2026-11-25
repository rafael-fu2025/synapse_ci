<?php

declare(strict_types=1);

// Disposable actual-view renderer: no dotenv, persistent database or external services.
if (PHP_SAPI !== 'cli') exit(1);
$input = json_decode(stream_get_contents(STDIN, 32768), true, 32, JSON_THROW_ON_ERROR);
$origin = $input['origin'] ?? '';
$path = $input['path'] ?? '';
if (!is_string($origin) || !preg_match('~\Ahttp://127\.0\.0\.1:[1-9][0-9]{0,4}\z~D', $origin)
    || !is_string($path) || !preg_match('~\A/(clinic/patients/88|portal/surveys|portal/call-slips)(?:\?[^\r\n#]*)?\z~D', $path)) exit(2);
require __DIR__ . '/pagination-isolated-bootstrap.php';
$app = clone config('App');
$app->baseURL = $origin . '/'; $app->indexPage = '';
config('App')->baseURL = $app->baseURL; config('App')->indexPage = '';
parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $query);
$uri = new \CodeIgniter\HTTP\SiteURI($app, ltrim(strtok($path, '?'), '/'));
$request = new \CodeIgniter\HTTP\IncomingRequest($app, $uri, null, new \CodeIgniter\HTTP\UserAgent());
$request->setMethod('GET')->setGlobal('get', $query);
\Config\Services::injectMock('request', $request); \Config\Services::injectMock('uri', $uri);
helper(['url', 'form']);
\App\Services\AuthenticatedUser::clear(); session()->remove('user');
$theme = ($input['theme'] ?? '') === 'dark' ? 'dark' : 'light';
$renderer = new class(config('View'), APPPATH . 'Views') extends \CodeIgniter\View\View {
    public function render(string $view, ?array $options = null, ?bool $saveData = null): string {
        if ($view !== 'layouts/app') return parent::render($view, $options, $saveData);
        $origin = $GLOBALS['origin']; $theme = $GLOBALS['theme'];
        $scripts = $this->renderSection('scripts');
        return '<!doctype html><html lang="en" data-theme="' . $theme . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Synthetic record pagination</title><link rel="stylesheet" href="' . $origin . '/assets/vendor/bootstrap/css/bootstrap.min.css">'
            . '<link rel="stylesheet" href="' . $origin . '/assets/vendor/bootstrap-icons/bootstrap-icons.css">'
            . '<link rel="stylesheet" href="' . $origin . '/assets/css/style.css">' . $this->renderSection('styles') . '</head>'
            . '<body class="app-shell" data-navigation-scope="synthetic:pagination" data-sidebar-collapsed="false">'
            . '<header id="header" class="header"><strong>SYNTHETIC TEST ONLY</strong></header><aside id="sidebar" class="sidebar"><nav class="sidebar-nav"><span>Invented records · read-only testing</span></nav></aside>'
            . '<main id="main" class="main"><h1 class="h5">Synthetic record pagination</h1>' . $this->renderSection('page_actions') . $this->renderSection('content') . '</main>'
            . '<div id="page-styles"></div><div id="page-scripts">' . $scripts . '</div>'
            . '<script src="' . $origin . '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>'
            . '<script src="' . $origin . '/assets/js/custom-dropdown.js"></script><script src="' . $origin . '/assets/js/page-lifecycle.js"></script>'
            . '<script src="' . $origin . '/assets/js/live-filters.js"></script><script src="' . $origin . '/assets/js/navigation.js"></script></body></html>';
    }
};
\Config\Services::injectMock('renderer', $renderer);
$page = static function (string $name, int $total, string $sizeName = 'per_page', int $default = 25) use ($query): array {
    return \App\Libraries\TablePage::forList($query, $name, $sizeName, $default)->metadata($total, $total);
};
$rows = static function (array $meta, callable $make): array {
    $items = [];
    for ($i = $meta['from']; $i > 0 && $i <= $meta['to']; ++$i) $items[] = $make($i);
    return $items;
};
$empty = ($query['empty'] ?? '') === '1';
$pathOnly = parse_url($path, PHP_URL_PATH);
if ($pathOnly === '/clinic/patients/88') {
    $total = $empty ? 0 : 61;
    $print = ($query['print'] ?? '') === '1';
    $metas = [];
    foreach (['visits', 'labs', 'clearances', 'isolation'] as $kind) $metas[$kind] = $page($kind . '_page', $total);
    $display = $print ? ['from' => $total ? 1 : 0, 'to' => $total] : null;
    $chart = [
        'person' => ['id' => 88, 'kind' => 'student', 'mis_id' => 'SYN-88', 'first_name' => 'Synthetic', 'last_name' => 'Patient', 'middle_name' => '', 'email' => 'synthetic@example.test', 'gender' => 'unspecified'],
        'health_profile' => [], 'student_profile' => null, 'employee_profile' => null,
        'vitals_history' => [], 'treatment_history' => [], 'dispensation_history' => [],
        'visit_summary' => ['total_visits' => $total, 'current_sy_visits' => $total, 'current_school_year' => '2026-2027', 'last_visit_at' => '2026-11-01 09:00:00', 'top_complaints' => []],
        'open_visit' => $empty ? null : ['id' => 90061, 'status' => 'in_progress'],
        'encounters' => $rows($display ?? $metas['visits'], static fn ($i) => ['id' => 90000 + $i, 'status' => 'completed', 'chief_complaint' => 'Synthetic visit ' . $i, 'started_at' => '2026-11-01 09:00:00', 'visited_at' => null, 'attending_name' => 'Synthetic clinician', 'disposition' => 'discharged']),
        'lab_results' => $rows($display ?? $metas['labs'], static fn ($i) => ['id' => 91000 + $i, 'test_name' => 'Synthetic lab ' . $i, 'result_status' => 'normal', 'school_year' => '2026-2027', 'context_type' => 'other', 'result_summary' => 'Synthetic sample only', 'created_at' => '2026-11-01 09:00:00']),
        'enrollment_clearances' => $rows($display ?? $metas['clearances'], static fn ($i) => ['id' => 92000 + $i, 'school_year' => 'SYN-' . $i, 'semester' => 'Annual', 'applicant_category' => 'freshman', 'required_labs_json' => '{}', 'clearance_status' => 'cleared']),
        'isolation_cases' => $rows($display ?? $metas['isolation'], static fn ($i) => ['id' => 93000 + $i, 'condition_code' => 'other', 'clearance_status' => 'cleared', 'isolation_start_date' => '2026-10-01', 'isolation_end_date' => '2026-10-05']),
    ];
    if (!$print) $chart['history_pagination'] = $metas;
    echo view('clinic/patients/chart', ['chart' => $chart, 'chartBaseUrl' => $origin . '/clinic/patients/88', 'chartTab' => $query['tab'] ?? 'tab-visits', 'printChart' => $print,
        'canStartVisit' => false, 'canWriteLabs' => false, 'canWriteClearance' => false, 'canWriteHealth' => true,
        'currentSchoolYear' => '2026-2027', 'schoolYearOptions' => ['2026-2027']], ['saveData' => true]);
} elseif ($pathOnly === '/portal/surveys') {
    $miTotal = $empty ? 0 : 52;
    $genTotal = $empty ? 0 : 61;
    $combinedTotal = $miTotal + $genTotal;
    $meta = $page('page', $combinedTotal);
    $offset = ($meta['page'] - 1) * $meta['per_page'];
    $miLimit = $offset < $miTotal ? min($meta['per_page'], $miTotal - $offset) : 0;
    $genLimit = $meta['per_page'] - $miLimit;
    $genOffset = max(0, $offset - $miTotal);

    $mi = [];
    for ($i = $offset + 1; $i <= min($miTotal, $offset + $miLimit); $i++) {
        $mi[] = ['id' => 95000 + $i, 'status' => 'assigned', 'academic_term' => 'SYN-MI-' . $i, 'academic_year' => '2026-2027', 'due_at' => null, 'draft_count' => 0];
    }
    $items = [];
    for ($i = $genOffset + 1; $i <= min($genTotal, $genOffset + $genLimit); $i++) {
        $items[] = ['id' => 94000 + $i, 'title' => 'Synthetic general survey ' . $i, 'description' => 'Invented survey for pagination tests.', 'submitted' => false, 'closed' => false, 'external_url' => '', 'ends_at' => null];
    }
    echo view('surveys/portal_index', [
        'baseUrl' => $origin . '/portal/surveys', 'items' => $items, 'miAssignments' => $mi, 'pagination' => $meta, 'miGateEnabled' => false, 'hasProfile' => true,
        'filters' => ['q' => $query['q'] ?? '', 'status' => $query['status'] ?? 'all', 'page' => $meta['page'], 'per_page' => $meta['per_page']],
        'counts' => ['all' => $combinedTotal, 'available' => $combinedTotal, 'submitted' => 0, 'closed' => 0],
    ], ['saveData' => true]);
} else {
    $meta = $page('page', $empty ? 0 : 521, 'per_page', 20);
    $items = $rows($meta, static fn ($i) => ['id' => 96000 + $i, 'call_slip_number' => 'SYN-SLIP-' . $i, 'attendance_status' => 'attended', 'call_date' => '2026-11-01', 'call_time' => '09:00:00', 'purpose' => 'routine_interview']);
    echo view('portal/call_slips', ['baseUrl' => $origin . '/portal/call-slips', 'callSlips' => $items, 'filter' => $query['status'] ?? 'all', 'filters' => ['status' => $query['status'] ?? 'all'], 'pagination' => $meta,
        'counts' => ['all' => $meta['total'], 'pending' => 0, 'acknowledged' => 0, 'history' => $meta['total']]], ['saveData' => true]);
}

<?php

declare(strict_types=1);

// CLI-only, disposable actual-view fixture. No dotenv, controllers, migrations,
// application database, external services, or mutation endpoints are used.
if (PHP_SAPI !== 'cli' || getenv('PAGINATION_ISOLATED_TESTS') !== '1') {
    throw new RuntimeException('Guidance registry fixtures require isolated CLI execution.');
}
$raw = stream_get_contents(STDIN, 8193);
if (strlen($raw) > 8192) {
    throw new InvalidArgumentException('Synthetic registry input exceeds its bound.');
}
$input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
if (!is_array($input) || array_diff(array_keys($input), ['origin', 'path', 'theme']) !== []) {
    throw new InvalidArgumentException('Unexpected synthetic registry input.');
}
$origin = $input['origin'] ?? '';
$fixturePath = $input['path'] ?? '';
$theme = $input['theme'] ?? 'light';
if (!is_string($origin) || !preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~D', $origin, $port)
    || (int) $port[1] > 65535 || !is_string($fixturePath)
    || !preg_match('~\A/guidance/[a-z/-]+(?:\?[^\r\n#]*)?\z~D', $fixturePath)
    || !in_array($theme, ['light', 'dark'], true)) {
    throw new InvalidArgumentException('Only named synthetic loopback registry pages are allowed.');
}
$registries = [
    '/guidance/intake' => ['view' => 'guidance/intake/index', 'title' => 'Guidance Intake Queue', 'pages' => ['intake_page', 'incoming_page']],
    '/guidance/episodes' => ['view' => 'guidance/episodes/index', 'title' => 'Guidance Episodes', 'pages' => ['page']],
    '/guidance/clinical/cosign-queue' => ['view' => 'guidance/clinical/cosign_queue', 'title' => 'Supervisor Co-Sign Queue', 'pages' => ['page']],
    '/guidance/governance/practitioners' => ['view' => 'guidance/governance/practitioners', 'title' => 'Guidance Practitioner Credentials & Supervision', 'pages' => ['profiles_page', 'supervision_page']],
    '/guidance/call-slips' => ['view' => 'guidance/call_slips/index', 'title' => 'Guidance Call Slips', 'pages' => ['page']],
];
$pathOnly = parse_url($fixturePath, PHP_URL_PATH);
$registry = $registries[$pathOnly] ?? null;
if ($registry === null) {
    throw new InvalidArgumentException('Unknown synthetic registry.');
}
parse_str(parse_url($fixturePath, PHP_URL_QUERY) ?? '', $query);
$allowedQuery = [...$registry['pages'], 'per_page'];
if ($pathOnly === '/guidance/intake') $allowedQuery = [...$allowedQuery, 'status', 'priority_hint'];
if (in_array($pathOnly, ['/guidance/episodes', '/guidance/call-slips'], true)) $allowedQuery[] = 'status';
if (array_diff(array_keys($query), $allowedQuery) !== []) {
    throw new InvalidArgumentException('Unexpected synthetic GET field.');
}
foreach ($query as $name => $value) {
    if (!is_string($value) || strlen($value) > 64) throw new InvalidArgumentException('Invalid synthetic GET value.');
    if (in_array($name, $registry['pages'], true) && (!preg_match('/\A[1-9][0-9]{0,3}\z/D', $value) || (int) $value > 1000)) {
        throw new InvalidArgumentException('Invalid synthetic page cursor.');
    }
}
$perPage = (int) ($query['per_page'] ?? 25);
if (!in_array($perPage, [20, 25, 50, 100], true)) throw new InvalidArgumentException('Invalid synthetic page size.');
$intakeStatuses = ['draft', 'submitted', 'received', 'triaged', 'accepted', 'declined', 'case_linked', 'withdrawn', 'external_handoff'];
$episodeStatuses = ['open', 'active', 'closed', 'transferred'];
$slipStatuses = ['pending', 'acknowledged', 'attended', 'missed', 'cancelled'];
$status = $query['status'] ?? '';
$validStatuses = match ($pathOnly) {
    '/guidance/intake' => $intakeStatuses,
    '/guidance/episodes' => $episodeStatuses,
    '/guidance/call-slips' => $slipStatuses,
    default => [],
};
if ($status !== '' && !in_array($status, $validStatuses, true)) throw new InvalidArgumentException('Invalid synthetic status.');
$priority = $query['priority_hint'] ?? '';
if ($priority !== '' && !in_array($priority, ['routine', 'urgent', 'crisis'], true)) throw new InvalidArgumentException('Invalid synthetic priority.');

require __DIR__ . '/pagination-isolated-bootstrap.php';
$app = clone config('App');
$app->baseURL = $origin . '/';
$app->indexPage = '';
config('App')->baseURL = $app->baseURL;
config('App')->indexPage = '';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port[1];
$_SERVER['REQUEST_URI'] = $fixturePath;
$_SERVER['QUERY_STRING'] = parse_url($fixturePath, PHP_URL_QUERY) ?? '';
$uri = new \CodeIgniter\HTTP\SiteURI($app, ltrim($pathOnly, '/'));
$uri->setQuery($_SERVER['QUERY_STRING']);
$request = new \CodeIgniter\HTTP\IncomingRequest($app, $uri, null, new \CodeIgniter\HTTP\UserAgent());
$request->setMethod('GET')->setGlobal('get', $query);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $uri);
helper(['url', 'form']);
\App\Services\AuthenticatedUser::clear();
$actor = ['id' => 93999, 'tenant_id' => 93999, 'username' => 'synthetic_registry_actor',
    'name' => 'Synthetic Registry Reviewer', 'role' => 'guidance_supervisor', 'permissions' => [], 'logged_in' => true];
session()->set(['tenant_id' => 93999, 'user' => $actor]);

$renderer = new class(config('View'), APPPATH . 'Views') extends \CodeIgniter\View\View {
    public function render(string $view, ?array $options = null, ?bool $saveData = null): string
    {
        if ($view !== 'layouts/app') {
            if (!in_array($view, ['guidance/intake/index', 'guidance/episodes/index', 'guidance/clinical/cosign_queue',
                'guidance/governance/practitioners', 'guidance/call_slips/index', 'partials/table_pagination'], true)) {
                throw new RuntimeException('Unexpected actual-view dependency: ' . $view);
            }
            return parent::render($view, $options, $saveData);
        }
        // Override only the app layout; all five views, sections, partials and
        // controls render through CI. Preserve the real shared confirmation.
        $layout = file_get_contents(APPPATH . 'Views/layouts/app.php');
        if (!preg_match('~(<div class="modal fade" id="synapseConfirmModal".*?)(?=\s*<!-- Vendor JS Files -->)~s', $layout, $confirmation)) {
            throw new RuntimeException('Actual shared confirmation markup was not found.');
        }
        $origin = $GLOBALS['origin'];
        $title = esc($GLOBALS['registry']['title']);
        $navigation = '';
        foreach ($GLOBALS['registries'] as $pathname => $entry) {
            $selected = $GLOBALS['pathOnly'] === $pathname;
            $navigation .= '<li class="nav-item"><a class="nav-link' . ($selected ? ' active' : '') . '"'
                . ($selected ? ' aria-current="page"' : '') . ' href="' . esc($origin . $pathname, 'attr') . '">'
                . '<span class="nav-icon"><i class="bi bi-journal" aria-hidden="true"></i></span><span class="nav-text">'
                . esc($entry['title']) . '</span></a></li>';
        }
        $assets = '';
        foreach (['custom-dropdown', 'datepicker', 'datetimepicker', 'date-range-picker', 'loading', 'form-loading',
            'csrf', 'toast', 'confirm', 'person-lookup-popover', 'live-filters', 'page-styles', 'form-modal',
            'responsive-tables', 'page-lifecycle', 'navigation', 'refresh-regions', 'background-actions'] as $name) {
            $assets .= '<script src="' . esc($origin . '/assets/js/' . $name . '.js', 'attr') . '"></script>';
        }
        return '<!doctype html><html lang="en" data-theme="' . $GLOBALS['theme'] . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="icon" href="data:,"><title>' . $title . ' — SYNTHETIC TEST ONLY</title>'
            . '<meta name="csrf-name" content="' . esc(csrf_token(), 'attr') . '">'
            . '<meta name="csrf-token" content="' . esc(csrf_hash(), 'attr') . '">'
            . '<meta name="csrf-header" content="' . esc(csrf_header(), 'attr') . '">'
            . '<meta name="synapse-refresh-regions-enabled" content="0">'
            . '<meta name="synapse-refresh-region-owners" content="">'
            . '<link rel="stylesheet" href="' . $origin . '/assets/vendor/bootstrap/css/bootstrap.min.css">'
            . '<link rel="stylesheet" href="' . $origin . '/assets/vendor/bootstrap-icons/bootstrap-icons.css">'
            . '<link rel="stylesheet" href="' . $origin . '/assets/css/style.css">'
            . '<script defer src="' . $origin . '/assets/vendor/alpine/alpine.min.js"></script>'
            . '<template id="page-styles" data-page-style-owner="head"></template>' . $this->renderSection('styles')
            . '<template data-page-styles-end></template></head>'
            . '<body class="app-shell" hx-boost="false" data-navigation-scope="synthetic-guidance-registry-ui" data-user-id="93999" data-tenant-id="93999">'
            . '<header id="header" class="header"><div class="header-left"><strong class="header-context">SYNTHETIC TEST ONLY</strong></div></header>'
            . '<aside id="sidebar" class="sidebar"><div class="sidebar-brand-row"><div class="sidebar-brand"><span class="sidebar-brand-copy"><strong>SYNTHETIC</strong><span>Read-only UI fixtures</span></span></div></div>'
            . '<nav class="sidebar-nav"><ul class="nav-menu">' . $navigation . '</ul></nav></aside>'
            . '<main id="main" class="main"><div class="main-content"><div class="pagetitle d-flex justify-content-between align-items-center mb-3">'
            . '<div class="page-title-copy"><h1 class="h3 mb-0">' . $title . '</h1></div>'
            . '<div class="page-actions">' . $this->renderSection('page_actions') . '</div></div>'
            . '<section class="section">' . $this->renderSection('content') . '</section></div></main>'
            . $confirmation[1]
            . '<script src="' . $origin . '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>' . $assets
            . '<div id="page-scripts" hidden>' . $this->renderSection('scripts') . '</div>'
            . '<div class="navigation-feedback"><div id="navigation-status" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>'
            . '<button id="navigation-retry" class="btn btn-sm btn-primary" type="button" hidden>Retry navigation</button>'
            . '<a id="navigation-fallback" class="btn btn-sm btn-outline-secondary" data-navigation="false" hidden>Open the full page</a></div></body></html>';
    }
};
\Config\Services::injectMock('renderer', $renderer);

// Pure GET fixture formulas; intentionally not controller/query/backend tests.
$page = static function (string $parameter, int $total, int $filtered) use ($query, $perPage): array {
    $pages = max(1, (int) ceil($filtered / $perPage));
    $current = min((int) ($query[$parameter] ?? 1), $pages);
    return ['page' => $current, 'pages' => $pages, 'per_page' => $perPage, 'total' => $total, 'filtered' => $filtered,
        'from' => $filtered === 0 ? 0 : ($current - 1) * $perPage + 1, 'to' => min($current * $perPage, $filtered)];
};
$all = static function (int $count, callable $make): array { return array_map($make, range(1, $count)); };
$slice = static fn (array $rows, array $meta): array => array_slice($rows, ($meta['page'] - 1) * $meta['per_page'], $meta['per_page']);
$qualifiedCounselors = [
    ['user_id' => 93999, 'first_name' => 'Synthetic', 'last_name' => 'Supervisor', 'username' => 'synthetic_registry_actor', 'credential_tier' => 'guidance_supervisor'],
    ['user_id' => 93802, 'first_name' => 'Synthetic', 'last_name' => 'Counselor', 'username' => 'synthetic_counselor', 'credential_tier' => 'licensed_counselor'],
];
$data = ['title' => $registry['title'], 'pageTitle' => $registry['title'], 'actor' => $actor];

if ($pathOnly === '/guidance/intake') {
    $referrals = $all(61, static fn (int $i): array => [
        'id' => 93200 + $i, 'referral_number' => 'SYN-REF-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        'subject_person_id' => 93100 + $i, 'first_name' => 'Synthetic Client ' . $i, 'last_name' => 'Fixture',
        'mis_id' => 'SYN-CLIENT-' . $i, 'kind' => 'student', 'source_type' => 'employee_teacher',
        'referrer_name_snapshot' => 'Synthetic Adviser', 'referrer_department_snapshot' => 'Synthetic advising',
        'priority_hint' => $i % 3 === 0 ? 'urgent' : 'routine', 'status' => $i % 2 === 1 ? 'submitted' : 'received',
        'assigned_counsellor_username' => 'synthetic_counselor', 'linked_case_id' => null,
        'submitted_at' => '2030-01-02 09:00:00', 'created_at' => '2030-01-02 08:30:00',
    ]);
    $filtered = array_values(array_filter($referrals, static fn (array $row): bool =>
        ($status === '' || $row['status'] === $status) && ($priority === '' || $row['priority_hint'] === $priority)));
    $incoming = $all(37, static fn (int $i): array => [
        'id' => 93400 + $i, 'referral_code' => 'SYN-CLINIC-' . $i, 'first_name' => 'Synthetic Patient ' . $i,
        'last_name' => 'Fixture', 'mis_id' => 'SYN-PATIENT-' . $i, 'reason_code' => 'stress_followup',
        'priority' => 'routine', 'issuer_name' => 'Synthetic Clinician', 'bridged_intake_id' => null, 'bridged_referral_number' => null,
    ]);
    $intakeMeta = $page('intake_page', count($referrals), count($filtered));
    $incomingMeta = $page('incoming_page', count($incoming), count($incoming));
    $data += ['referrals' => $slice($filtered, $intakeMeta), 'clinicReferrals' => $slice($incoming, $incomingMeta),
        'intakePagination' => $intakeMeta, 'incomingPagination' => $incomingMeta,
        'statuses' => $intakeStatuses, 'priorityHints' => ['routine', 'urgent', 'crisis'], 'statusFilter' => $status,
        'priorityFilter' => $priority, 'canonicalGateEnabled' => false,
        'concernCatalog' => ['checklist' => ['academic_deficiency' => 'Academic Deficiency', 'emotional_distress' => 'Emotional Distress', 'others' => 'Others']]];
} elseif ($pathOnly === '/guidance/episodes') {
    $episodes = $all(61, static fn (int $i): array => [
        'id' => 93500 + $i, 'person_id' => 93100 + $i, 'first_name' => 'Synthetic Client ' . $i, 'last_name' => 'Fixture',
        'mis_id' => 'SYN-CLIENT-' . $i, 'kind' => 'student', 'counselor_first_name' => 'Synthetic',
        'counselor_last_name' => 'Counselor', 'counselor_username' => 'synthetic_counselor',
        'case_id' => null, 'case_number' => null, 'case_title' => null,
        'status' => $episodeStatuses[($i - 1) % count($episodeStatuses)], 'opened_at' => '2030-01-02 09:00:00',
    ]);
    $statusCounts = ['' => count($episodes)];
    foreach ($episodeStatuses as $key) $statusCounts[$key] = count(array_filter($episodes, static fn (array $row): bool => $row['status'] === $key));
    $filtered = array_values(array_filter($episodes, static fn (array $row): bool => $status === '' || $row['status'] === $status));
    $meta = $page('page', count($episodes), count($filtered));
    $data += ['episodes' => $slice($filtered, $meta), 'status' => $status, 'statusCounts' => $statusCounts,
        'pagination' => $meta, 'qualifiedCounselors' => $qualifiedCounselors];
} elseif ($pathOnly === '/guidance/clinical/cosign-queue') {
    $forms = $all(61, static fn (int $i): array => [
        'id' => 93600 + $i, 'form_code' => 'SYN-PIF-008', 'form_key' => 'psychosocial_support_interview',
        'form_title' => 'Psychosocial Support Interview Form', 'instance_number' => 'SYN-PIF-' . $i,
        'person_id' => 93100 + $i, 'client_first_name' => 'Synthetic Client ' . $i, 'client_last_name' => 'Fixture',
        'client_mis_id' => 'SYN-CLIENT-' . $i, 'case_id' => null, 'author_first_name' => 'Synthetic',
        'author_last_name' => 'Associate', 'author_username' => 'synthetic_associate', 'created_by_user_id' => 93801,
        'supervisor_first_name' => 'Synthetic', 'supervisor_last_name' => 'Supervisor',
        'supervisor_username' => 'synthetic_registry_actor', 'assigned_supervisor_user_id' => $i % 2 === 1 ? 93999 : 93802,
        'submitted_for_cosign_at' => '2030-01-02 09:00:00', 'submission_notes' => 'Synthetic review observations only.',
    ]);
    $meta = $page('page', count($forms), count($forms));
    $data += ['pendingForms' => $slice($forms, $meta), 'pagination' => $meta, 'pendingCount' => count($forms),
        'assignedCount' => 31, 'practitionerProfile' => ['credential_tier' => 'guidance_supervisor'], 'canCoSign' => true, 'hasOversight' => true];
} elseif ($pathOnly === '/guidance/governance/practitioners') {
    $profiles = $all(61, static fn (int $i): array => [
        'id' => 93700 + $i, 'user_id' => 93700 + $i, 'username' => 'synthetic_practitioner_' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
        'user_role' => 'counsellor', 'first_name' => 'Synthetic Practitioner ' . $i, 'last_name' => 'Fixture',
        'mis_id' => 'SYN-STAFF-' . $i, 'credential_tier' => 'licensed_counselor', 'license_number' => 'SYN-LIC-' . $i,
        'credential_expiry' => '2035-12-31', 'is_active' => 1, 'professional_standing' => 'active', 'service_scope' => ['intake', 'counseling'],
    ]);
    $supervisions = $all(37, static fn (int $i): array => [
        'id' => 93850 + $i, 'associate_user_id' => 93700 + $i, 'associate_first_name' => 'Synthetic Associate ' . $i,
        'associate_last_name' => 'Fixture', 'associate_username' => 'synthetic_associate_' . $i,
        'supervisor_user_id' => 93999, 'supervisor_first_name' => 'Synthetic', 'supervisor_last_name' => 'Supervisor',
        'supervisor_username' => 'synthetic_registry_actor', 'co_sign_required' => 1, 'starts_at' => '2030-01-02 09:00:00', 'status' => 'active',
    ]);
    $profileMeta = $page('profiles_page', count($profiles), count($profiles));
    $supervisionMeta = $page('supervision_page', count($supervisions), count($supervisions));
    $data += ['profiles' => $slice($profiles, $profileMeta), 'profilesPagination' => $profileMeta,
        'supervisions' => $slice($supervisions, $supervisionMeta), 'supervisionPagination' => $supervisionMeta,
        'guidanceUsers' => [
            ['id' => 93801, 'username' => 'synthetic_associate', 'role' => 'counsellor', 'first_name' => 'Synthetic', 'last_name' => 'Associate'],
            ['id' => 93999, 'username' => 'synthetic_registry_actor', 'role' => 'guidance_supervisor', 'first_name' => 'Synthetic', 'last_name' => 'Supervisor'],
        ], 'tiers' => ['counselor_associate', 'licensed_counselor', 'guidance_supervisor', 'qualified_assessor'],
        'availableScopes' => ['intake' => 'Intake & Referral Processing', 'counseling' => 'Counseling Sessions & Notes', 'safety_triage' => 'Clinical Safety Triage & Escalation'],
        'canManagePractitioners' => true, 'canReassign' => true];
} else {
    $purposes = ['personal_counseling', 'career_counseling', 'routine_interview', 'follow_up', 'academic_counseling', 'others'];
    $slips = $all(61, static fn (int $i): array => [
        'id' => 93300 + $i, 'call_slip_number' => 'SYN-SLIP-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        'form_instance_number' => 'SYN-GCS-' . $i, 'episode_id' => null, 'person_id' => 93100 + $i,
        'first_name' => 'Synthetic Client ' . $i, 'last_name' => 'Fixture', 'mis_id' => 'SYN-CLIENT-' . $i,
        'program' => 'Synthetic program', 'year_level' => '2', 'parent_guardian_delivery' => 0,
        'call_date' => '2030-01-02', 'call_time' => '09:00:00', 'purpose' => $purposes[($i - 1) % count($purposes)],
        'purpose_other' => null, 'attendance_status' => $slipStatuses[($i - 1) % count($slipStatuses)], 'acknowledged_at' => null,
    ]);
    $filtered = array_values(array_filter($slips, static fn (array $row): bool => $status === '' || $row['attendance_status'] === $status));
    $meta = $page('page', count($slips), count($filtered));
    $data += ['callSlips' => $slice($filtered, $meta), 'pagination' => $meta, 'status' => $status,
        'validStatuses' => $slipStatuses, 'validPurposes' => $purposes, 'prefillPerson' => null, 'prefillEpisodeId' => null];
}
echo view($registry['view'], $data, ['saveData' => true]);

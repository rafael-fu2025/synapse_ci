<?php

declare(strict_types=1);

// CLI-only presentation fixture: synthetic data, no dotenv or application database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require __DIR__ . '/guidance-read-bootstrap.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);

$app = config('App');
$app->baseURL = rtrim((string) $input['origin'], '/') . '/';
$app->indexPage = '';
$app->appTimezone = 'UTC';
$path = (string) ($input['path'] ?? '/guidance/clients');
$_SERVER['HTTP_HOST'] = parse_url($app->baseURL, PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $path;
parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $query);
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $path), null, new \CodeIgniter\HTTP\UserAgent());
$request->setGlobal('get', $query);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
$permissions = [
    'guidance.cases.read', 'guidance.cases.read_any', 'guidance.queue.read', 'guidance.schedule.read',
    'guidance.assessments.view', 'guidance.responses.read', 'guidance.intake.process', 'guidance.safety.triage',
    'guidance.clinical.author', 'guidance.clinical.cosign', 'guidance.call_slips.issue', 'guidance.documents.print', 'referrals.read',
];
$actor = ['id' => 1, 'person_id' => 101, 'tenant_id' => 1, 'name' => 'Synthetic Guidance Staff',
    'username' => 'guidance.fixture', 'email' => 'guidance.fixture@example.invalid',
    'role' => 'guidance_supervisor', 'permissions' => $permissions, 'logged_in' => true];
session()->set(['tenant_id' => 1, 'user' => $actor]);
config(\Config\RefreshRegions::class)->enabled = true;
config(\Config\RefreshRegions::class)->owners = ['guidance.clients', 'guidance.client.1'];
config(\Config\Realtime::class)->enabled = false;

$route = parse_url($path, PHP_URL_PATH);
if ($route === '/guidance/clients') {
    $page = \App\Libraries\TablePage::fromQuery($query, ['all'], 'Guidance');
    $rows = [];
    for ($id = 1; $id <= 64; $id++) {
        $rows[] = ['id' => $id, 'kind' => $id % 2 ? 'student' : 'employee', 'mis_id' => sprintf('SYNTH-%03d', $id),
            'first_name' => sprintf('Client%02d', $id), 'middle_name' => '', 'last_name' => 'Synthetic',
            'program' => $id % 2 ? 'BS Psychology' : null, 'year_level' => $id % 2 ? 3 : null,
            'department' => $id % 2 ? null : 'Campus Services'];
    }
    $filtered = array_values(array_filter($rows, static function (array $row) use ($page): bool {
        return ($page->kind === 'all' || $page->kind === $row['kind'])
            && ($page->q === '' || stripos($row['mis_id'] . ' ' . $row['last_name'] . ' ' . $row['first_name'], $page->q) !== false);
    }));
    $pagination = $page->metadata(count($rows), count($filtered));
    $data = [
        'title' => 'Guidance Clients & Records', 'pageTitle' => 'Guidance Clients & Records',
        'clients' => array_slice($filtered, ($pagination['page'] - 1) * $page->perPage, $page->perPage),
        'pagination' => $pagination, 'filters' => ['q' => $page->q, 'kind' => $page->kind, 'per_page' => $page->perPage],
        'baseUrl' => site_url('/guidance/clients'),
        'breadcrumbs' => [['label' => 'Guidance', 'url' => site_url('/guidance')], ['label' => 'Clients & Records']],
    ];
    echo $page->fragment ? '<div data-table-content>' . view('guidance/clients/_results', $data) . '</div>'
        : view('guidance/clients/index', $data);
    exit;
}

if (!preg_match('~^/guidance/(?:clients/(\d+)|students/(\d+)/cumulative)$~', (string) $route, $match)) {
    echo '<!doctype html><title>Synthetic workspace</title><p>Authorized synthetic workspace entry.</p><a href="' . esc(site_url('/guidance/clients')) . '">Clients &amp; Records</a>';
    exit;
}
$personId = (int) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? 1));
$activeTab = $query['tab'] ?? 'overview';
$person = ['id' => $personId, 'tenant_id' => 1, 'kind' => $personId % 2 ? 'student' : 'employee',
    'mis_id' => sprintf('SYNTH-%03d', $personId), 'first_name' => sprintf('Client%02d', $personId), 'last_name' => 'Synthetic', 'middle_name' => '',
    'date_of_birth' => '2004-05-10', 'gender' => 'unspecified', 'email' => 'client' . $personId . '@example.invalid',
    'phone' => 'Synthetic contact', 'address' => 'Synthetic campus address, not a real client'];
$can = array_fill_keys(['queue', 'assessments', 'surveys', 'intake', 'referrals', 'safety', 'schedule', 'callSlips', 'forms', 'documents'], true);
$history = array_fill_keys(array_keys(\App\Services\Guidance\GuidanceCumulativeService::PAGE_PARAMS), []);
$history['authorizedCases'] = [['id' => 11, 'case_number' => 'SYNTH-CASE-011', 'title' => 'Campus adjustment and wellbeing support',
    'category' => 'personal', 'status' => 'active', 'opened_at' => '2026-11-01 01:00:00', 'closed_at' => null,
    'counsellor_user_id' => 1, 'handler_name' => 'Synthetic Guidance Staff', 'handler_tier' => 'Licensed Counselor',
    'service_mode' => 'Counseling', 'is_consultation' => false]];
$history['episodes'] = [['id' => 21, 'status' => 'active', 'opened_at' => '2026-11-01 01:00:00', 'closed_at' => null, 'handler_name' => 'Synthetic Guidance Staff']];
$history['sessions'] = [['id' => 31, 'case_id' => 11, 'case_number' => 'SYNTH-CASE-011', 'session_number' => 2,
    'session_type' => 'regular', 'status' => 'completed', 'started_at' => '2026-11-12 01:00:00', 'ended_at' => '2026-11-12 02:00:00', 'counsellor_user_id' => 1]];
$history['followups'] = [['id' => 41, 'case_id' => 11, 'case_number' => 'SYNTH-CASE-011', 'due_date' => '2026-11-20', 'status' => 'pending', 'completed_at' => null]];
$history['miAttempts'] = [['id' => 51, 'assignment_id' => 52, 'status' => 'finalized', 'academic_term' => 'First semester', 'academic_year' => '2026–2027',
    'attested_at' => '2026-11-10 02:00:00', 'created_at' => '2026-11-10 01:00:00',
    'domain_scores' => ['linguistic' => 31, 'logical_mathematical' => 28, 'spatial' => 24, 'bodily_kinesthetic' => 21, 'musical' => 26, 'interpersonal' => 34, 'intrapersonal' => 30, 'naturalist' => 22]]];
$history['pendingAssessments'] = [['id' => 53, 'academic_term' => 'Second semester', 'academic_year' => '2026–2027', 'assigned_at' => '2026-11-15 01:00:00', 'due_at' => '2026-11-30 01:00:00', 'status' => 'assigned']];
$history['surveys'] = [['response_id' => 61, 'survey_id' => 62, 'title' => 'Synthetic student needs survey', 'submitted_at' => '2026-11-09 01:00:00', 'interviewed_at' => '2026-11-10 01:00:00', 'interviewer_name' => 'Synthetic Guidance Staff']];
$history['intakes'] = [['id' => 71, 'referral_number' => 'SYNTH-INTAKE-071', 'source_type' => 'self', 'status' => 'accepted', 'submitted_at' => '2026-11-01 01:00:00', 'created_at' => '2026-11-01 01:00:00']];
$history['bridgeReferrals'] = [['id' => 72, 'referral_code' => 'SYNTH-REF-072', 'source_module' => 'guidance', 'target_module' => 'clinic', 'status' => 'acknowledged', 'created_at' => '2026-11-01 01:00:00']];
$history['transfers'] = [['id' => 73, 'case_id' => 11, 'case_number' => 'SYNTH-CASE-011', 'from_counsellor_user_id' => 1, 'to_counsellor_user_id' => 2, 'transfer_type' => 'consultation', 'status' => 'accepted', 'requested_at' => '2026-11-05 01:00:00']];
$history['assignments'] = [['id' => 74, 'case_id' => 11, 'case_number' => 'SYNTH-CASE-011', 'counsellor_user_id' => 1, 'assignment_type' => 'primary', 'assigned_at' => '2026-11-01 01:00:00', 'relinquished_at' => null]];
$history['safetyReviews'] = [['id' => 75, 'case_id' => 11, 'case_number' => 'SYNTH-CASE-011', 'assessor_user_id' => 1, 'assessed_at' => '2026-11-01 01:00:00']];
$history['forms'] = [['id' => 81, 'case_id' => 11, 'instance_number' => 'SYNTH-FORM-081', 'canonical_title' => 'Counseling Contact Sheet',
    'internal_key' => \App\Services\Guidance\GuidanceClinicalFormService::FORM_COUNSELING_CONTACT, 'form_code' => 'F-OGC-GCS-CCF-009-00',
    'status' => 'pending_cosign', 'privacy_notice_id' => 1, 'created_by_user_id' => 1, 'assigned_supervisor_user_id' => 2,
    'created_at' => '2026-11-12 01:00:00', 'finalized_at' => null, 'attestations' => [['signer_role' => 'client', 'attested_at' => '2026-11-12 01:00:00']]]];
$history['sharedAppointments'] = [['id' => 91, 'provider_user_id' => 1, 'starts_at' => '2026-11-20 01:00:00', 'status' => 'confirmed', 'type' => 'follow_up']];
$history['guidanceAppointments'] = [['id' => 92, 'counselor_user_id' => 1, 'appointment_number' => 'SYNTH-APT-092', 'scheduled_date' => '2026-11-20', 'scheduled_time' => '09:00:00', 'status' => 'confirmed']];
$history['callSlips'] = [['id' => 93, 'issuing_counselor_user_id' => 1, 'call_slip_number' => 'SYNTH-CALL-093', 'call_date' => '2026-11-20', 'call_time' => '09:00:00', 'attendance_status' => 'acknowledged']];
$totals = array_map('count', $history);
$historyPagination = [];
$recordQuery = ['tab' => $activeTab, 'per_page' => 25];
foreach (\App\Services\Guidance\GuidanceCumulativeService::PAGE_PARAMS as $key => $param) {
    $page = \App\Libraries\TablePage::forList($query, $param);
    $historyPagination[$key] = $page->metadata($totals[$key], $totals[$key]);
    $recordQuery['per_page'] = $page->perPage;
}
$metrics = ['cases_total' => 1, 'cases_active' => 1, 'cases_closed' => 0, 'episodes_total' => 1,
    'sessions_total' => 1, 'followups_pending' => 1, 'queue_visits_completed' => 3, 'queue_visits_total' => 3,
    'mi_attempts_total' => 1, 'mi_pending_total' => 1, 'surveys_completed' => 1, 'intake_referrals_total' => 1,
    'forms_total' => 1, 'shared_appointments_total' => 1, 'guidance_appointments' => 1, 'call_slips_issued' => 1];
echo view('guidance/students/cumulative', $history + [
    'title' => 'Guidance Record', 'pageTitle' => 'Guidance Record', 'person' => $person, 'actor' => $actor,
    'studentProfile' => $person['kind'] === 'student' ? ['program' => 'BS Psychology', 'year_level' => 3, 'section' => 'A', 'department' => 'Arts & Sciences'] : null,
    'employeeProfile' => $person['kind'] === 'employee' ? ['department' => 'Campus Services', 'position' => 'Staff'] : null,
    'activeTab' => $activeTab, 'can' => $can, 'totals' => $totals, 'metrics' => $metrics,
    'historyPagination' => $historyPagination, 'recordQuery' => $recordQuery,
    'practitionerDirectory' => [1 => ['name' => 'Synthetic Guidance Staff'], 2 => ['name' => 'Synthetic Supervisor']],
    'baseUrl' => site_url('/guidance/clients/' . $personId),
    'breadcrumbs' => [['label' => 'Guidance', 'url' => site_url('/guidance')], ['label' => 'Clients & Records', 'url' => site_url('/guidance/clients')], ['label' => 'Synthetic Client']],
]);

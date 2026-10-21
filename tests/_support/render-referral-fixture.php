<?php
// CLI-only actual CI views. No models, database, or MIS calls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$app = config('App');
$app->baseURL = $fixture['origin'] . '/';
$app->indexPage = '';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
parse_str(parse_url($fixture['path'], PHP_URL_QUERY) ?? '', $_GET);
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $fixture['path']), null, new \CodeIgniter\HTTP\UserAgent());
$request->setGlobal('get', $_GET);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
$actor = ['id' => 1, 'name' => 'Fixture Admin', 'username' => 'fixture', 'role' => 'superadmin', 'logged_in' => true];
session()->set(['user' => $actor, 'tenant_id' => 1]);
$path = parse_url($fixture['path'], PHP_URL_PATH);
if ($path === '/referrals') { echo view('referrals/workstation', ['title' => 'Choose workstation']); exit; }
$unit = str_starts_with($path, '/clinic/') ? 'clinic' : 'guidance';
$target = $unit === 'clinic' ? 'guidance' : 'clinic';
$baseUrl = site_url('/' . $unit . '/referrals');
$patient = ['id' => 11, 'first_name' => 'Ada', 'last_name' => 'Student', 'mis_id' => 'S001', 'kind' => 'student'];
// Match the public static destination map without constructing a DB-backed service.
$reasons = $target === 'guidance'
    ? ['anxiety_stress' => 'Stress support', 'academic_distress' => 'Academic support', 'grief_trauma' => 'Grief or difficult experience support', 'behavioral_concern' => 'Behavioral support', 'personal_support' => 'Personal support', 'career_guidance' => 'Career guidance', 'family_support' => 'Family support', 'other' => 'Other support']
    : ['medical_assessment' => 'Medical assessment', 'medical_followup' => 'Medical follow-up', 'physical_symptoms' => 'Physical symptoms', 'health_advice' => 'Health advice', 'other' => 'Other health concern'];
$data = ['unit' => $unit, 'actor' => $actor, 'cancelUrl' => $baseUrl, 'formModalPage' => true];
if (str_ends_with($path, '/create')) {
    echo view('referrals/create', $data + ['title' => 'Refer to ' . ucfirst($target), 'targetModule' => $target,
        'patient' => isset($_GET['prefill']) ? $patient : null, 'sourceEncounterId' => isset($_GET['source']) ? 71 : null,
        'reasonOptions' => $reasons, 'requestKey' => str_repeat('b', 64), 'formAction' => $baseUrl . '/store']);
    exit;
}
if (str_ends_with($path, '/handoff')) {
    $options = isset($_GET['empty']) ? [] : [['id' => 81, 'started_at' => '2026-10-16 09:00:00', 'case_number' => 'CASE-81', 'title' => 'Existing support']];
    echo view('referrals/handoff', $data + ['title' => 'Continue referral handoff', 'patient' => $patient,
        'referral' => ['referral_code' => 'REF-2'], 'encounters' => $options, 'cases' => $options, 'formAction' => $baseUrl . '/2/handoff']);
    exit;
}
$tab = in_array($_GET['tab'] ?? '', ['incoming', 'sent', 'history'], true) ? $_GET['tab'] : 'incoming';
$referrals = [];
foreach (['submitted', 'acknowledged', 'under_review', 'converted_to_case', 'closed'] as $i => $status) {
    if (($tab === 'history') !== ($status === 'closed')) continue;
    $referrals[] = $patient + ['referral_code' => 'REF-' . ($i + 1), 'created_at' => '2026-10-16 08:00:00',
        'source_module' => $tab === 'sent' ? $unit : $target, 'target_module' => $tab === 'sent' ? $target : $unit,
        'priority' => 'routine', 'reason_code' => 'other', 'reason_description' => 'Shared summary only',
        'status' => $status, 'issuer_name' => 'Sending Staff', 'has_target' => $status === 'converted_to_case',
        'target_encounter_id' => $status === 'converted_to_case' && $unit === 'clinic' ? 81 : null,
        'target_case_id' => $status === 'converted_to_case' && $unit === 'guidance' ? 81 : null, 'can_open_target' => true];
    $referrals[array_key_last($referrals)]['id'] = $i + 1;
}
$filters = ['q' => $_GET['q'] ?? '', 'per_page' => (int) ($_GET['per_page'] ?? 25)];
$page = max(1, min(3, (int) ($_GET['page'] ?? 1)));
$data = ['title' => ucfirst($unit) . ' Referrals', 'unit' => $unit, 'actor' => $actor, 'tab' => $tab,
    'baseUrl' => $baseUrl, 'filters' => $filters, 'referrals' => $referrals,
    'counts' => ['incoming' => 75, 'sent' => 75, 'history' => 75],
    'pagination' => ['page' => $page, 'per_page' => $filters['per_page'], 'filtered' => 75, 'total' => 75, 'pages' => 3]];
echo view(isset($_GET['fragment']) ? 'referrals/_results' : 'referrals/index', $data);

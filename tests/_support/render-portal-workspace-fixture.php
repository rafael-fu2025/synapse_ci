<?php

declare(strict_types=1);

// CLI-only real Portal views; all transport/persistence is owned by the synthetic browser receiver.
// No deployment dotenv, real API calls, migrations, or DB records are used.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require __DIR__ . '/ai-runtime-bootstrap.php';
config(\Config\Realtime::class)->enabled = false;
$app = config('App');
$app->baseURL = $fixture['origin'] . '/';
$app->indexPage = '';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
parse_str(parse_url($fixture['path'], PHP_URL_QUERY) ?? '', $_GET);
$_COOKIE = [config('Security')->cookieName => $fixture['csrfHash']];
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $fixture['path']), null, new \CodeIgniter\HTTP\UserAgent());
$request->setGlobal('get', $_GET);
$request->setGlobal('cookie', $_COOKIE);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
$kind = ($fixture['kind'] ?? '') === 'employee' ? 'employee' : 'student';
$permissions = $fixture['permissions'] ?? ['portal.records.read', 'portal.appointments.read', 'portal.surveys.submit'];
$person = ['id' => 999999999, 'kind' => $kind, 'mis_id' => 'FIXTURE-123', 'first_name' => 'Synthetic', 'middle_name' => '',
    'last_name' => ucfirst($kind), 'email' => 'fixture@example.test'];
$actor = ['id' => 1, 'person_id' => $person['id'], 'tenant_id' => 999999999, 'name' => 'Synthetic ' . ucfirst($kind),
    'role' => $kind, 'email' => $person['email'], 'permissions' => $permissions, 'logged_in' => true,
    'avatar_initials' => \App\Services\ProfileAvatar::initials($person)];
session()->set(['user' => $actor, 'tenant_id' => $actor['tenant_id']]);
$path = parse_url($fixture['path'], PHP_URL_PATH);
$data = ['actor' => $actor, 'person' => $person, 'breadcrumbs' => [['label' => 'My Health Portal', 'url' => site_url('portal')]],
    'baseUrl' => site_url('portal/surveys')];
$health = array_replace(['blood_type' => 'O+', 'allergies' => '', 'emergency_contact_name' => 'Maria Santos',
    'emergency_contact_relationship' => 'Mother', 'emergency_contact_phone' => '0917-123-4567'], $fixture['contact'] ?? []);
$survey = ['id' => 1, 'title' => 'Campus wellbeing', 'description' => 'Synthetic identified survey', 'external_url' => '',
    'submitted' => (bool) ($fixture['submitted'] ?? false), 'closed' => false, 'status' => 'published', 'ends_at' => '2030-12-31 04:00:00',
    'questions' => [
        ['type' => 'single', 'label' => 'Preferred support', 'required' => true, 'options' => ['Study', 'Wellbeing'], 'allow_other' => true],
    ]];
if ($path === '/portal') {
    echo view('portal/index', $data + ['title' => 'My Health Portal', 'pageTitle' => ucfirst($kind) . ' Health Portal',
        'chart' => ['person' => $person, 'health_profile' => $health,
            'student_profile' => $kind === 'student' ? ['program' => 'BSIT', 'year_level' => 3, 'section' => 'A', 'department' => 'CCS'] : null,
            'employee_profile' => $kind === 'employee' ? ['department' => 'CCS', 'position' => 'Instructor', 'employment_status' => 'Full-time'] : null,
            'encounters' => [], 'vitals_history' => [], 'treatment_history' => [], 'dispensation_history' => []],
        'activeTickets' => [], 'appointments' => [], 'filters' => ['service' => 'all', 'status' => 'all', 'sort' => 'upcoming', 'per_page' => 25]]);
} elseif ($path === '/portal/settings') {
    $hasPhoto = (bool) ($fixture['hasPhoto'] ?? true);
    echo view('portal/settings', $data + ['title' => 'Profile & Settings', 'health' => $health,
        'defaultAvatar' => \App\Services\ProfileAvatar::dataUri($person), 'hasPhoto' => $hasPhoto,
        'photoUrl' => $hasPhoto ? site_url('portal/settings/photo') : \App\Services\ProfileAvatar::dataUri($person)]);
} elseif ($path === '/portal/surveys/1') {
    echo view('surveys/take', $data + ['title' => $survey['title'], 'survey' => $survey]);
} elseif ($path === '/portal/surveys') {
    $filters = \App\Services\PortalContent::filters($_GET, ['all', 'available', 'submitted', 'closed']);
    echo view('surveys/portal_index', $data + ['title' => 'My Surveys', 'items' => [$survey], 'hasProfile' => true,
        'filters' => $filters, 'nowUtc' => '2026-11-16 00:00:00',
        'counts' => ['all' => 1, 'available' => $survey['submitted'] ? 0 : 1, 'submitted' => $survey['submitted'] ? 1 : 0, 'closed' => 0],
        'pagination' => ['page' => 1, 'pages' => 1, 'filtered' => 1, 'total' => 1, 'per_page' => 25]]);
} elseif ($path === '/portal/appointments/clinic/create') {
    echo view('appointments/create', ['title' => 'Request Clinic Visit', 'formModalPage' => true, 'unit' => 'clinic',
        'bookingMode' => 'personal', 'person' => $person, 'formAction' => site_url('portal/appointments/clinic/store'), 'cancelUrl' => site_url('portal')]);
} else {
    echo view('announcements/portal', $data + ['title' => 'Announcements', 'baseUrl' => site_url('portal/announcements'),
        'announcements' => [], 'filters' => ['q' => '', 'unit' => 'all', 'status' => 'all'],
        'counts' => ['all' => 0, 'unread' => 0, 'read' => 0],
        'pagination' => ['page' => 1, 'pages' => 1, 'filtered' => 0, 'total' => 0, 'per_page' => 25]]);
}

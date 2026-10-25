<?php

// CLI-only view fixture: actual CI renderer and templates, no database queries.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
// Realtime ticket renewal is unrelated to form saves and requires its own backend.
config(\Config\Realtime::class)->enabled = false;
$app = config('App');
$app->baseURL = $fixture['origin'] . ($fixture['basePath'] ?? '/');
$app->indexPage = $fixture['indexPage'] ?? '';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
parse_str(parse_url($fixture['path'], PHP_URL_QUERY) ?? '', $_GET);
if (!empty($fixture['fragment'])) $_GET['fragment'] = '1';
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $fixture['path']), null, new \CodeIgniter\HTTP\UserAgent());
$request->setGlobal('get', $_GET);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
session()->set(['user' => ['id' => 1, 'name' => 'Fixture Admin', 'username' => 'fixture-admin', 'role' => 'superadmin', 'logged_in' => true], 'tenant_id' => 1]);
$slide = ['id' => 23, 'title' => 'Existing Slide', 'subtitle' => 'Stored subtitle',
    'light_image' => 'assets/img/brand/fuel-bg.jpg', 'dark_image' => null,
    'fit_mode' => 'cover', 'is_active' => 1, 'sort_order' => 1];
$kind = $fixture['kind'];
$person = ['id' => 11, 'first_name' => 'Ada', 'last_name' => 'Student', 'mis_id' => 'S001', 'kind' => 'student'];
$appointmentFor = static fn (string $unit): array => array_merge($person, [
    'id' => 31, 'unit' => $unit, 'person_id' => 11, 'provider_user_id' => null, 'provider_name' => null,
    'local_date' => date('Y-m-d'), 'starts_at' => date('Y-m-d') . ' 09:00:00',
    'ends_at' => date('Y-m-d') . ' 09:30:00', 'status' => 'requested', 'version' => 1,
    'type' => $unit === 'clinic' ? 'routine' : 'initial', 'reason' => 'Fixture appointment request',
    'created_at' => date('Y-m-d H:i:s'),
]);
if ($kind === 'portal' || str_starts_with($kind, 'personal-')) {
    session()->set('user', ['id' => 1, 'person_id' => 11, 'name' => 'Ada Student', 'username' => 'ada',
        'identifier' => 'S001', 'role' => 'student', 'logged_in' => true]);
}
$data = ['formModalPage' => true];
switch ($kind) {
    case 'clinic':
    case 'guidance':
    case 'personal-clinic':
    case 'personal-guidance':
        $view = 'appointments/create';
        $personal = str_starts_with($kind, 'personal-');
        $unit = $personal ? substr($kind, strlen('personal-')) : $kind;
        $base = $personal ? '/portal/appointments/' . $unit : '/' . $unit . '/appointments';
        $data += ['title' => 'Book New ' . ucfirst($unit) . ' Appointment', 'unit' => $unit,
            'bookingMode' => $personal ? 'personal' : 'staff', 'person' => $personal ? $person : null,
            'formAction' => site_url($base . '/store'), 'cancelUrl' => site_url($personal ? '/portal' : $base)];
        break;
    case 'clinic-confirm':
    case 'guidance-confirm':
        $view = 'appointments/confirm';
        $unit = explode('-', $kind)[0];
        $base = '/' . $unit . '/appointments';
        $data += ['title' => 'Confirm ' . ucfirst($unit) . ' Appointment', 'unit' => $unit,
            'appointment' => $appointmentFor($unit), 'formAction' => site_url($base . '/31/confirm'),
            'cancelUrl' => site_url($base),
            'providers' => [['id' => 7, 'name' => 'Provider Fixture', 'position' => $unit === 'clinic' ? 'Clinic Staff' : 'Counsellor']]];
        break;
    case 'clinic-availability':
    case 'guidance-availability':
        $view = 'appointments/availability';
        $unit = explode('-', $kind)[0];
        $base = '/' . $unit . '/appointments';
        unset($data['formModalPage']);
        $data += ['title' => ucfirst($unit) . ' Provider Availability',
            'pageTitle' => ucfirst($unit) . ' Provider Availability', 'unit' => $unit, 'exceptions' => [],
            'formAction' => site_url($base . '/availability'), 'cancelUrl' => site_url($base),
            'templateAction' => site_url($base . '/availability/template'),
            'enrollmentAction' => site_url($base . '/availability/enroll'),
            'departmentStaff' => [
                ['person_id' => 73, 'name' => 'New MIS Employee', 'department' => $unit === 'clinic' ? 'Health Services' : 'Guidance',
                    'position' => 'New department employee', 'staff_assignment_id' => null, 'eligible' => false, 'can_enroll' => true, 'status' => 'Not enrolled for scheduling'],
                ['person_id' => 70, 'name' => 'Provider Fixture', 'department' => $unit === 'clinic' ? 'Health Services' : 'Guidance',
                    'position' => 'Stored <position>', 'staff_assignment_id' => 7, 'eligible' => true, 'can_enroll' => false, 'status' => 'Schedule configured'],
                ['person_id' => 72, 'name' => 'New Staff Fixture', 'department' => $unit === 'clinic' ? 'Health Services' : 'Guidance',
                    'position' => 'New appointment handler', 'staff_assignment_id' => 8, 'eligible' => true, 'can_enroll' => false, 'status' => 'Ready to schedule'],
                ['person_id' => 71, 'name' => 'Pending Employee', 'department' => $unit === 'clinic' ? 'Health Services' : 'Guidance',
                    'position' => 'Assistant', 'staff_assignment_id' => null, 'eligible' => false, 'can_enroll' => true, 'status' => 'Not enrolled for scheduling'],
            ],
            'providers' => [['id' => 7, 'name' => 'Provider Fixture', 'position' => $unit === 'clinic' ? 'Clinic Staff' : 'Counsellor']],
            'availability' => [['id' => 41, 'staff_assignment_id' => 7, 'provider_user_id' => null, 'provider_name' => 'Provider Fixture',
                'day_of_week' => 1, 'start_time' => '08:00:00', 'end_time' => '17:00:00',
                'slot_duration_minutes' => 30, 'is_active' => 1]]];
        $today = new \DateTimeImmutable(\App\Services\Scheduling\SchedulingClock::today());
        $exceptionRow = static fn (int $id, int $assignmentId, string $date, string $name, string $reason): array => [
            'id' => $id, 'tenant_id' => 1, 'staff_assignment_id' => $assignmentId, 'provider_user_id' => null,
            'exception_date' => $date, 'is_unavailable' => 1, 'custom_start_time' => null, 'custom_end_time' => null,
            'reason' => $reason, 'provider_name' => $name,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ];
        // All rows belong to this authorized unit and tenant; assignment 9 is no longer eligible.
        $data['exceptions'] = [
            $exceptionRow(61, 7, $today->modify('+14 days')->format('Y-m-d'), 'Provider Fixture', 'Future leave fixture'),
            $exceptionRow(62, 7, $today->modify('-1 day')->format('Y-m-d'), 'Provider Fixture', 'Past leave fixture'),
            $exceptionRow(63, 9, $today->modify('+15 days')->format('Y-m-d'), 'Inactive Provider Fixture', 'Inactive enrollment fixture'),
        ];
        $data['workspaceContext'] = \App\Services\Scheduling\AvailabilityWorkspaceContext::fromGet($_GET, $data['providers']);
        $data['returnQuery'] = $fixture['returnQuery'] ?? \App\Services\Scheduling\AvailabilityWorkspaceContext::query($data['workspaceContext']);
        break;
    case 'referral':
        $view = 'referrals/create';
        $data += ['title' => 'Refer to Guidance', 'unit' => 'clinic', 'targetModule' => 'guidance',
                    'reasonOptions' => ['anxiety_stress' => 'Stress support', 'academic_distress' => 'Academic support', 'other' => 'Other support'],
                    'requestKey' => str_repeat('a', 64), 'formAction' => site_url('/clinic/referrals/store'),
                    'cancelUrl' => site_url('/clinic/referrals')];
        break;
    case 'add':
    case 'edit':
        $view = 'admin/carousel/' . ($kind === 'add' ? 'create' : 'edit');
        $data += ['title' => $kind === 'add' ? 'Add Carousel Slide' : 'Edit Carousel Slide',
            'cancelUrl' => site_url('/admin/carousel'), 'slide' => $kind === 'add' ? null : $slide];
        break;
    case 'manager':
        $view = 'admin/carousel/index';
        $data = ['title' => 'Login Carousel', 'slides' => [$slide], 'slide' => null, 'formModalPage' => false];
        break;
    case 'guidance-new-case':
        $view = 'guidance/cases/create';
        $data += ['title' => 'Open New Case', 'cancelUrl' => site_url('/guidance/cases')];
        break;
    case 'guidance-case-list':
        $view = 'guidance/cases/index';
        $pager = \Config\Services::pager();
        $pager->store('default', 1, 25, 0);
        $data = ['title' => 'Counseling Cases', 'cases' => [], 'pager' => $pager,
            'status' => '', 'category' => '', 'formModalPage' => false];
        break;
    case 'clinic-list':
    case 'guidance-list':
        $view = 'appointments/index';
        $unit = explode('-', $kind)[0];
        $data = ['title' => 'Appointments', 'unit' => $unit, 'date' => date('Y-m-d'),
                    'requests' => [$appointmentFor($unit)], 'appointments' => [], 'formModalPage' => false];
        break;
    case 'referral-list':
        $view = 'referrals/index';
        $unit = str_starts_with($fixture['path'], '/guidance/') ? 'guidance' : 'clinic';
                $data = ['title' => 'Referrals', 'referrals' => [], 'unit' => $unit, 'tab' => 'incoming',
                    'actor' => ['role' => 'superadmin'], 'counts' => ['incoming' => 0, 'sent' => 0, 'history' => 0],
                    'pagination' => ['page' => 1, 'per_page' => 25, 'filtered' => 0, 'total' => 0, 'pages' => 1],
                    'filters' => ['q' => '', 'per_page' => 25], 'baseUrl' => site_url('/' . $unit . '/referrals'), 'formModalPage' => false];
                break;
            case 'referral-chooser':
                $view = 'referrals/workstation';
                $data = ['title' => 'Choose workstation', 'formModalPage' => false];
        break;
    case 'portal':
        $view = 'portal/index';
        $data = ['title' => 'My Health Portal', 'chart' => ['person' => $person, 'appointments' => []],
                    'activeTicket' => null, 'formModalPage' => false];
        break;
    default:
        throw new \InvalidArgumentException('Unknown fixture view');
}
echo view($view, $data);

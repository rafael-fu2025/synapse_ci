<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AppointmentController;
use App\Controllers\PatientLookupController;
use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Filters\WebAccessFilter;
use App\Services\AuthenticatedUser;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use ReflectionProperty;

/** Isolated access regressions and remaining audit diagnostics; no application migrations. */
final class ClinicalAccessAuditTest extends CIUnitTestCase
{
    private $fixture;
    private array $savedConnections = [];
    private array $oldKey = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', ENVIRONMENT);
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->savedConnections = $instances->getValue();
        $this->fixture = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        // Redirect every default model/service connection before any application SQL.
        $instances->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        self::assertSame($this->fixture, Database::connect());
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, mis_id TEXT, first_name TEXT, middle_name TEXT, last_name TEXT, email TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, is_teaching INTEGER, department TEXT, employment_status TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, status TEXT, deleted_at TEXT',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, status TEXT, deleted_at TEXT',
            'guidance_followups' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER',
            'clinic_encounters' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, attending_user_id INTEGER, status TEXT, deleted_at TEXT',
            'department_staff' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, unit TEXT, status TEXT',
            'provider_availability' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, staff_assignment_id INTEGER, unit TEXT, day_of_week INTEGER, start_time TEXT, end_time TEXT, slot_duration_minutes INTEGER, is_active INTEGER',
            'availability_exceptions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, staff_assignment_id INTEGER, exception_date TEXT, is_unavailable INTEGER, custom_start_time TEXT, custom_end_time TEXT',
            'appointments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, staff_assignment_id INTEGER, provider_user_id INTEGER, status TEXT, starts_at TEXT, ends_at TEXT, deleted_at TEXT',

        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
        (new PermissionsAndRolesSeeder(config('Database'), $this->fixture))->run();
        foreach ([1, 2] as $tenant) {
            $this->fixture->table('tenants')->insert(['id' => $tenant, 'is_active' => 1]);
        }
        foreach ([[1, 1], [2, 1], [3, 2]] as [$id, $tenant]) {
            $this->fixture->table('people')->insert(['id' => $id, 'tenant_id' => $tenant, 'kind' => 'employee',
                'mis_id' => 'AUDIT-' . $id, 'first_name' => 'Synthetic', 'last_name' => 'Person']);
        }
        foreach ([[1, 1, 1, 'counsellor'], [2, 1, 2, 'counsellor'], [3, 2, 3, 'counsellor']] as [$id, $tenant, $person, $role]) {
            $this->fixture->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $person,
                'username' => 'audit-' . $id, 'role' => $role, 'status' => 'active']);
        }
        foreach ([[11, 1, 1, 1], [12, 1, 2, 2], [13, 2, 3, 3]] as [$id, $tenant, $person, $owner]) {
            $this->fixture->table('counselling_cases')->insert(['id' => $id, 'tenant_id' => $tenant,
                'person_id' => $person, 'counsellor_user_id' => $owner, 'status' => 'open']);
            $this->fixture->table('counselling_sessions')->insert(['id' => $id, 'tenant_id' => $tenant,
                'case_id' => $id, 'status' => 'in_progress']);
            $this->fixture->table('guidance_followups')->insert(['id' => $id, 'tenant_id' => $tenant, 'case_id' => $id]);
            $this->fixture->table('clinic_encounters')->insert(['id' => $id, 'tenant_id' => $tenant,
                'person_id' => $person, 'attending_user_id' => $owner, 'status' => 'in_progress']);
        }
        foreach (['COUNSELLING_KEY' => str_repeat('cd', 32), 'COUNSELLING_KEY_VERSION' => '1'] as $name => $value) {
            $this->oldKey[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name . '=' . $value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $this->signIn('counsellor');
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        foreach ($this->oldKey as $name => [$env, $local, $server]) {
            putenv($env === false ? $name : $name . '=' . $env);
            unset($_ENV[$name], $_SERVER[$name]);
            if ($local !== null) $_ENV[$name] = $local;
            if ($server !== null) $_SERVER[$name] = $server;
        }
        foreach (['request', 'response'] as $service) Services::resetSingle($service);
        if ($this->fixture !== null) {
            $this->fixture->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->savedConnections);
        }
        parent::tearDown();
    }

    private function signIn(string $role): void
    {
        $this->fixture->table('users')->where('id', 1)->update(['role' => $role]);
        session()->set(['tenant_id' => 1, 'user' => ['id' => 1, 'tenant_id' => 1, 'logged_in' => true],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
    }

    private function request(string $path, string $method = 'GET', array $get = [], array $post = [])
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod($method);
        $request->getUri()->setPath('/' . $path);
        $request->setHeader('Accept', 'application/json');
        $request->setGlobal('get', $get);
        $request->setGlobal('post', $post);
        Services::injectMock('request', $request);
        Services::injectMock('response', new \CodeIgniter\HTTP\Response(config('App')));
        return $request;
    }

    private function siteRequest(string $baseURL, string $indexPage, string $routePath): \CodeIgniter\HTTP\IncomingRequest
    {
        $app = clone config('App');
        $app->baseURL = $baseURL;
        $app->indexPage = $indexPage;
        // SiteURI takes a route-relative path and constructs the real installation URL.
        $uri = new \CodeIgniter\HTTP\SiteURI($app, $routePath);
        self::assertSame($baseURL . ($indexPage === '' ? '' : $indexPage . '/') . $routePath, (string) $uri);
        self::assertSame($routePath, $uri->getRoutePath());
        $request = new \CodeIgniter\HTTP\IncomingRequest($app, $uri, null, new \CodeIgniter\HTTP\UserAgent());
        $request->setMethod('GET');
        $request->setHeader('Accept', 'application/json');
        $request->setGlobal('get', []);
        $request->setGlobal('post', []);
        Services::injectMock('request', $request);
        Services::injectMock('response', new \CodeIgniter\HTTP\Response($app));
        return $request;
    }

    public function testSiteUriInstallationBasePathPreservesAssignmentAndRevokedPatientGrant(): void
    {
        foreach (['', 'index.php'] as $indexPage) {
            foreach (['http://localhost/', 'http://localhost/campus/'] as $baseURL) {
                $this->signIn('counsellor');
                $request = $this->siteRequest($baseURL, $indexPage, 'guidance/cases/12');
                self::assertSame('guidance/cases/12', $request->getUri()->getRoutePath());
                self::assertSame(parse_url((string) $request->getUri(), PHP_URL_PATH), $request->getUri()->getPath());
                self::assertNull((new \App\Filters\PortalAccessFilter())->before($request, ['guidance']));
                self::assertSame(403, (new WebAccessFilter())->before($request)?->getStatusCode());

                $this->signIn('clinic_staff');
                $request = $this->siteRequest($baseURL, $indexPage, 'clinic/patients/2');
                self::assertNull((new \App\Filters\PortalAccessFilter())->before($request, ['clinic']));
                // Remove only the actual seeded grant; never broaden the fixture's role grants.
                $this->revoke('clinic_staff', 'clinic.patients.read');
                self::assertSame(403, (new WebAccessFilter())->before($request)?->getStatusCode());
            }
        }
    }

    public function testSubdirectoryCasePhotoRejectsUnassignedPersonAtFilterAndController(): void
    {
        // Keep the photo path synthetic and assert absence before the controller can read it.
        $personId = 2147483001;
        $patient = ['id' => $personId, 'tenant_id' => 1, 'kind' => 'employee', 'mis_id' => 'AUDIT-PHOTO-ONLY',
            'first_name' => 'Unauthorized', 'last_name' => 'Synthetic'];
        self::assertFalse(is_file((new \App\Services\ProfilePhotoService())->path(1, $personId)));
        $this->fixture->table('people')->insert($patient);
        $this->fixture->table('counselling_cases')->where('id', 12)->update(['person_id' => $personId]);
        $this->fixture->query('ALTER TABLE counselling_sessions ADD COLUMN counsellor_user_id INTEGER');
        $this->fixture->query('ALTER TABLE counselling_sessions ADD COLUMN session_number INTEGER');
        $this->fixture->query('ALTER TABLE guidance_followups ADD COLUMN due_date TEXT');
        $this->signIn('counsellor');

        $root = $this->siteRequest('http://localhost/', 'index.php', 'guidance/cases/12/photo');
        self::assertSame(403, (new WebAccessFilter())->before($root)->getStatusCode());
        $request = $this->siteRequest('http://localhost/campus/', 'index.php', 'guidance/cases/12/photo');
        self::assertSame('/campus/index.php/guidance/cases/12/photo', $request->getUri()->getPath());
        self::assertNull((new \App\Filters\PortalAccessFilter())->before($request, ['guidance']));
        self::assertSame(403, (new WebAccessFilter())->before($request)->getStatusCode());
        $actor = (new AuthenticatedUser())->current();
        self::assertSame(1, $actor['id']);
        self::assertNotContains('guidance.cases.read_any', $actor['permissions']);
        $service = new \App\Services\Guidance\GuidanceCaseService(db: $this->fixture);
        $this->expectDenied(fn () => $service->getCaseDetails(12), 403);
        $controller = new \App\Controllers\GuidanceController();
        (new ReflectionProperty($controller, 'caseService'))->setValue($controller, $service);
        $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
        $this->expectDenied(fn () => $controller->casePhoto(12), 403);
        // Filters and controller are invoked explicitly, not through the entire HTTP dispatcher.
    }

    private function gate(string $path, string $method = 'GET', array $post = []): ?int
    {
        $response = (new WebAccessFilter())->before($this->request($path, $method, [], $post));
        return $response?->getStatusCode();
    }

    private function revoke(string $role, string $code): void
    {
        $permission = $this->fixture->table('permissions')->where('code', $code)->get()->getRowArray();
        self::assertNotNull($permission);
        $this->fixture->table('role_permissions')->where('role', $role)->where('permission_id', $permission['id'])->delete();
    }

    public function testGuidanceAssignmentAndTenantBoundariesAtHttpRecordFilter(): void
    {
        foreach (['cases/%d', 'cases/%d/photo', 'sessions/%d/read', 'sessions/%d/history'] as $suffix) {
            self::assertNull($this->gate('guidance/' . sprintf($suffix, 11)));
            self::assertSame(403, $this->gate('guidance/' . sprintf($suffix, 12)));
            self::assertSame(404, $this->gate('guidance/' . sprintf($suffix, 13)));
        }
        foreach (['cases/%d/session', 'cases/%d/followups', 'cases/%d/close', 'sessions/%d/notes', 'followups/%d/complete'] as $suffix) {
            self::assertNull($this->gate('guidance/' . sprintf($suffix, 11), 'POST'));
            self::assertSame(403, $this->gate('guidance/' . sprintf($suffix, 12), 'POST'));
            self::assertSame(404, $this->gate('guidance/' . sprintf($suffix, 13), 'POST'));
        }
        foreach (['guidance_admin', 'guidance_supervisor', 'superadmin'] as $role) {
            $this->signIn($role);
            self::assertNull($this->gate('guidance/cases/12'));
            self::assertNull($this->gate('guidance/sessions/12/notes', 'POST'));
            self::assertSame(404, $this->gate('guidance/cases/13'));
        }
    }

    public function testDepartmentRolesAndRevokedGrantsAreNotReplacedByBodyIds(): void
    {
        foreach (['student', 'employee', 'bmg_admin', 'facilities_op', 'audit_reader', 'report_viewer', 'clinic_staff'] as $role) {
            $this->signIn($role);
            self::assertSame(403, $this->gate('guidance/cases/11'));
            self::assertSame(403, $this->gate('guidance/sessions/11/notes', 'POST', ['case_id' => 12, 'tenant_id' => 2]));
        }
        $this->signIn('counsellor');
        $this->revoke('counsellor', 'guidance.cases.write');
        self::assertSame(403, $this->gate('guidance/sessions/11/notes', 'POST'));
        self::assertNull($this->gate('guidance/cases/11/session', 'POST'));
        self::assertSame(404, $this->gate('guidance/cases/store', 'POST', ['person_id' => 3]));
        self::assertSame(422, $this->gate('guidance/cases/store', 'POST', ['person_id' => ['1']]));
    }

    public function testClinicSharingIsDepartmentWideButNotCrossTenant(): void
    {
        foreach (['clinic_staff', 'clinic_admin', 'superadmin'] as $role) {
            $this->signIn($role);
            self::assertNull($this->gate('clinic/encounters/12'));
            self::assertNull($this->gate('clinic/encounters/12/assessment', 'POST'));
            self::assertSame(404, $this->gate('clinic/encounters/13'));
        }
        $this->signIn('counsellor');
        self::assertSame(403, $this->gate('clinic/patients/1'));
        self::assertSame(403, $this->gate('clinic/encounters/11'));
    }

    public function testLookupUsesRealRoleGrantsAndExcludesForeignAndDeletedPeople(): void
    {
        foreach (['student', 'employee', 'bmg_admin', 'audit_reader', 'report_viewer'] as $role) {
            $this->signIn($role);
            $request = $this->request('api/patients/search', get: ['q' => 'Synthetic']);
            $controller = new PatientLookupController();
            $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
            self::assertSame(403, $controller->search()->getStatusCode());
        }
        $this->signIn('counsellor');
        $this->fixture->table('people')->where('id', 2)->update(['deleted_at' => '2026-01-01']);
        $request = $this->request('api/patients/search', get: ['q' => 'Synthetic']);
        $controller = new PatientLookupController();
        $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
        $response = $controller->search();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([1], array_map('intval', array_column(json_decode($response->getBody(), true)['results'], 'id')));
    }

    public function testReferralFreshAuthorityRejectsForgedRoleRoutingAndRevokedGrants(): void
    {
        $service = new \App\Services\Referrals\ReferralService();
        foreach (['student', 'employee', 'bmg_admin', 'audit_reader', 'report_viewer'] as $role) {
            $this->signIn($role);
            $this->expectDenied(fn () => $service->authorize(['id' => 1, 'tenant_id' => 1, 'role' => 'superadmin', 'permissions' => ['*']], 'referrals.read'), 403);
        }
        $this->signIn('counsellor');
        $actor = (new AuthenticatedUser())->current();
        self::assertSame('guidance', $service->context($actor, 'guidance'));
        $this->expectDenied(fn () => $service->context($actor, 'clinic'), 403);
        $this->expectDenied(fn () => $service->authorize(array_replace($actor, ['tenant_id' => 2]), 'referrals.read'), 403);
        $this->revoke('counsellor', 'referrals.review');
        $this->expectDenied(fn () => $service->authorize($actor, 'referrals.review'), 403);
    }

    private function expectDenied(callable $operation, int $status): void
    {
        try {
            $operation();
        } catch (\RuntimeException|\DomainException $error) {
            self::assertSame($status, $error->getCode());
            return;
        }
        self::fail('Expected authorization rejection.');
    }

    public function testReferralSourcePrefillAndCreationRequireEncounterRead(): void
    {
        $this->signIn('clinic_staff');
        $this->revoke('clinic_staff', 'clinic.encounters.read');
        $request = $this->request('clinic/referrals/create', get: ['source_encounter_id' => '12', 'person_id' => '2']);
        $controller = new \App\Controllers\ReferralController();
        $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
        self::assertSame(403, $controller->create('clinic')->getStatusCode());
        $data = ['source_encounter_id' => '12', 'patient_person_id' => '2',
            'request_key' => str_repeat('a', 64), 'priority' => 'routine',
            'reason_code' => 'academic_distress', 'reason_description' => 'Synthetic attachment authorization check'];
        $request = $this->request('clinic/referrals/store', 'POST', post: $data);
        $controller = new \App\Controllers\ReferralController();
        $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
        self::assertSame(403, $controller->store('clinic')->getStatusCode());
        $actor = (new AuthenticatedUser())->current();
        $service = new \App\Services\Referrals\ReferralService();
        self::assertSame('clinic_staff', $service->authorize($actor, 'referrals.create', 'clinic')['role']);
        $this->expectDenied(fn () => $service->createReferral($data + ['issuer_user_id' => $actor['id']], $actor), 403);
        // Rejection precedes the MySQL-only creation transaction.
    }

    public function testSlotsRejectNonDepartmentRolesAndOtherDepartmentRequests(): void
    {
        foreach (['student', 'employee', 'bmg_admin', 'facilities_op', 'audit_reader', 'report_viewer', 'counsellor'] as $role) {
            $this->signIn($role);
            $request = $this->request('api/appointments/slots', get: ['unit' => 'clinic', 'staff_assignment_id' => '21', 'date' => '2099-01-05']);
            $controller = new AppointmentController();
            $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
            self::assertSame(403, $controller->slots()->getStatusCode());
        }
    }

    public function testSlotsApiRejectsOccupancyReadAfterSchedulingGrantRevocation(): void
    {
        foreach (['clinic_staff' => 'clinic', 'counsellor' => 'guidance'] as $role => $unit) {
            $this->signIn($role);
            $permission = $unit === 'clinic' ? 'clinic.appointments.read' : 'guidance.schedule.read';
            $this->revoke($role, $permission);
            $this->revoke($role, $unit === 'clinic' ? 'clinic.appointments.write' : 'guidance.schedule.manage');
            self::assertSame(403, $this->gate($unit . '/appointments'));
            $this->fixture->table('employee_profiles')->emptyTable();
            $this->fixture->table('department_staff')->emptyTable();
            $this->fixture->table('provider_availability')->emptyTable();
            $this->fixture->table('appointments')->emptyTable();
            $this->fixture->table('employee_profiles')->insert(['tenant_id' => 1, 'person_id' => 2,
                'department' => $unit === 'clinic' ? 'Health Services' : 'Guidance', 'employment_status' => 'active']);
            $this->fixture->table('department_staff')->insert(['id' => 21, 'tenant_id' => 1, 'person_id' => 2, 'unit' => $unit, 'status' => 'active']);
            $date = '2099-01-05';
            $this->fixture->table('provider_availability')->insert(['tenant_id' => 1, 'staff_assignment_id' => 21,
                'unit' => $unit, 'day_of_week' => (int) (new \DateTimeImmutable($date))->format('w'),
                'start_time' => '09:00', 'end_time' => '10:00', 'slot_duration_minutes' => 30, 'is_active' => 1]);
            $this->fixture->table('appointments')->insert(['tenant_id' => 1, 'person_id' => 1, 'staff_assignment_id' => 21,
                'status' => 'confirmed', 'starts_at' => $date . ' 09:00:00', 'ends_at' => $date . ' 09:30:00']);
            $request = $this->request('api/appointments/slots', get: ['unit' => $unit, 'staff_assignment_id' => '21', 'date' => $date]);
            self::assertNull((new WebAccessFilter())->before($request));
            $controller = new AppointmentController();
            $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
            $response = $controller->slots();
            self::assertSame(403, $response->getStatusCode(), $response->getBody());
            self::assertSame([], json_decode($response->getBody(), true)['slots']);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DashboardController;
use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Filters\WebAccessFilter;
use App\Filters\AuthFilter;
use App\Services\AuthenticatedUser;
use App\Services\EngagementAccess;
use App\Services\PortalContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Actual routes/filters and the production grants, using only private SQLite fixtures. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BackendRoleAuthorizationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'rbac_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ];
        $this->db = Database::connect();
        $this->assertSame(':memory:', $this->db->database);
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT',
            'employee_profiles' => 'person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, status TEXT, deleted_at TEXT',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, status TEXT, deleted_at TEXT',
            'guidance_followups' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, status TEXT, deleted_at TEXT',
            'queue_entries' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, unit TEXT, person_id INTEGER, queue_date TEXT, status TEXT',
            'appointments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, unit TEXT, person_id INTEGER, status TEXT, version INTEGER, deleted_at TEXT',
            'queue_entries' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, unit TEXT, person_id INTEGER, queue_date TEXT, status TEXT',
        ] as $table => $columns) {
            $this->db->query('CREATE TABLE rbac_' . $table . ' (' . $columns . ')');
        }
        $this->db->table('tenants')->insert(['id' => 1, 'is_active' => 1]);
        foreach (PortalContext::allowedRoles('main') as $offset => $role) {
            $id = $offset + 1;
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => 1, 'first_name' => 'Fixture', 'last_name' => $role]);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => 1, 'person_id' => $id,
                'username' => $role, 'role' => $role, 'status' => 'active']);
        }
        (new PermissionsAndRolesSeeder(config('Database')))->run();
        helper(['form', 'url']);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    public static function roles(): iterable
    {
        foreach (['superadmin', 'clinic_admin', 'clinic_staff', 'guidance_admin', 'guidance_supervisor',
            'counsellor', 'bmg_admin', 'facilities_op', 'audit_reader', 'report_viewer', 'student', 'employee'] as $role) {
            yield $role => [$role];
        }
    }

    private function identity(string $role): array
    {
        $row = $this->db->table('users')->where('username', $role)->get()->getRowArray();
        return ['tenant_id' => 1, 'user' => ['id' => (int) $row['id'], 'tenant_id' => 1, 'logged_in' => true,
            'role' => 'superadmin', 'permissions' => ['*', 'audit.read', 'rbac.manage'], 'password' => 'forged'],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]];
    }

    private function requestAs(string $role, string $method, string $path)
    {
        Services::resetSingle('renderer');
        Services::resetSingle('response');
        Services::resetSingle('redirectresponse');
        $data = [];
        if ($method === 'POST') {
            $hash = service('security')->generateHash();
            service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
            Services::resetSingle('security');
            $data[config('Security')->tokenName] = $hash;
        }
        return $this->withSession($this->identity($role))->withHeaders(['Accept' => 'application/json'])
            ->call($method, $path, $data);
    }

    private function boundary(string $role, string $method, string $path)
    {
        Services::resetSingle('response');
        session()->set($this->identity($role));
        $request = service('request');
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        $request->getUri()->setPath('/' . $path);
        return (new WebAccessFilter())->before($request);
    }

    /** Guarantee a different server date without changing the production clock. */
    private function withDifferentServerDay(callable $check): void
    {
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('Etc/GMT+12');
            if (date('Y-m-d') === \App\Services\Scheduling\SchedulingClock::today()) date_default_timezone_set('Pacific/Kiritimati');
            $this->assertNotSame(date('Y-m-d'), \App\Services\Scheduling\SchedulingClock::today());
            $check();
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testChartHandoffUsesManilaDateAndStillRejectsOldOrCompletedTickets(): void
    {

        $today = \App\Services\Scheduling\SchedulingClock::today();
        foreach (['clinic', 'guidance'] as $offset => $unit) {
            $this->db->table('queue_entries')->insert(['id' => $offset + 1, 'tenant_id' => 1, 'person_id' => 1, 'unit' => $unit, 'queue_date' => $today, 'status' => 'called']);
        }
        $this->withDifferentServerDay(function () use ($today): void {
            foreach (['clinic' => 'clinic_staff', 'guidance' => 'counsellor'] as $unit => $role) {
                $path = $unit . '/' . ($unit === 'clinic' ? 'encounters' : 'cases') . '/start-for-ticket/' . ($unit === 'clinic' ? 1 : 2);
                $this->assertNull($this->boundary($role, 'POST', $path));
                $this->db->table('queue_entries')->where('unit', $unit)->update(['queue_date' => date('Y-m-d')]);
                $this->assertSame(409, $this->boundary($role, 'POST', $path)->getStatusCode());
                $this->db->table('queue_entries')->where('unit', $unit)->update(['queue_date' => $today, 'status' => 'done']);
                $this->assertSame(409, $this->boundary($role, 'POST', $path)->getStatusCode());
            }
        });
    }

    public function testCheckInControlUsesTheSameManilaDayAsTheServerCheckInService(): void
    {
        session()->set($this->identity('clinic_admin'));
        (new AuthenticatedUser())->current();
        $this->withDifferentServerDay(function (): void {
            $today = \App\Services\Scheduling\SchedulingClock::today();
            $appointment = ['id' => 91, 'status' => 'confirmed', 'staff_assignment_id' => 7, 'local_date' => $today,
                'starts_at' => $today . ' 09:00:00', 'first_name' => 'Fixture', 'last_name' => 'Patient', 'mis_id' => 'TEST', 'type' => 'routine', 'version' => 1];
            foreach (['clinic', 'guidance'] as $unit) {
                Services::resetSingle('renderer');
                $data = ['title' => 'Appointments', 'unit' => $unit, 'date' => $today, 'requests' => [], 'appointments' => [$appointment],
                                    'pendingPagination' => \App\Libraries\TablePage::forList([])->metadata(0, 0),
                                    'scheduledPagination' => \App\Libraries\TablePage::forList([])->metadata(1, 1)];
                $this->assertStringContainsString('Check-in to Queue', view('appointments/index', $data));
                Services::resetSingle('renderer');
                $data['appointments'][0]['local_date'] = date('Y-m-d');
                $this->assertStringNotContainsString('Check-in to Queue', view('appointments/index', $data));
            }
        });
    }

    private function installedBoundary(string $role, string $method, string $route, string $baseURL, string $indexPage)
    {
        session()->set($this->identity($role));
        $app = clone config('App');
        $app->baseURL = $baseURL;
        $app->indexPage = $indexPage;
        $uri = new \CodeIgniter\HTTP\SiteURI($app, $route);
        $this->assertSame($route, $uri->getRoutePath());
        $request = new \CodeIgniter\HTTP\IncomingRequest($app, $uri, null, new \CodeIgniter\HTTP\UserAgent());
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        Services::injectMock('request', $request);
        Services::injectMock('response', new \CodeIgniter\HTTP\Response($app));
        return (new WebAccessFilter())->before($request);
    }

    public function testInstallationPathsPreserveRecordAssignmentAndTenantDenials(): void
    {
        $owner = $this->db->table('users')->where('username', 'counsellor')->get()->getRowArray();
        foreach ([[501, 1, (int) $owner['id']], [502, 1, 999], [503, 2, (int) $owner['id']]] as [$id, $tenant, $counsellor]) {
            $this->db->table('counselling_cases')->insert(['id' => $id, 'tenant_id' => $tenant,
                'person_id' => (int) $owner['person_id'], 'counsellor_user_id' => $counsellor, 'status' => 'open']);
        }
        foreach (['http://localhost/', 'http://localhost/campus/', 'http://localhost/campus/public/'] as $baseURL) {
            foreach (['', 'index.php', 'front.php'] as $indexPage) {
                $this->assertNull($this->installedBoundary('counsellor', 'GET', 'guidance/cases/501', $baseURL, $indexPage));
                $this->assertSame(403, $this->installedBoundary('counsellor', 'GET', 'guidance/cases/502', $baseURL, $indexPage)->getStatusCode());
                $this->assertSame(403, $this->installedBoundary('counsellor', 'POST', 'guidance/cases/502/close', $baseURL, $indexPage)->getStatusCode());
                $this->assertSame(404, $this->installedBoundary('counsellor', 'GET', 'guidance/cases/503', $baseURL, $indexPage)->getStatusCode());
                $this->assertNull($this->installedBoundary('guidance_supervisor', 'GET', 'guidance/cases/502', $baseURL, $indexPage));
            }
        }
    }

    public function testInstallationPathsPreserveFreshReadAndWriteGrantRevocation(): void
    {
        $this->revoke('clinic_staff', 'clinic.queue.read');
        $this->revoke('clinic_staff', 'clinic.queue.manage');
        foreach (['http://localhost/', 'http://localhost/campus/', 'http://localhost/campus/public/'] as $baseURL) {
            foreach (['', 'index.php', 'front.php'] as $indexPage) {
                foreach (['GET' => 'clinic/queue', 'POST' => 'clinic/queue/call-next'] as $method => $route) {
                    $this->assertSame(403, $this->installedBoundary('clinic_staff', $method, $route, $baseURL, $indexPage)->getStatusCode());
                    $this->assertNull($this->installedBoundary('clinic_admin', $method, $route, $baseURL, $indexPage));
                    $this->assertSame(403, $this->installedBoundary('student', $method, $route, $baseURL, $indexPage)->getStatusCode());
                }
            }
        }
    }

    private function revoke(string $role, string $code): void
    {
        $permission = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
        $this->assertNotNull($permission);
        $this->db->table('role_permissions')->where('role', $role)->where('permission_id', $permission['id'])->delete();
    }

    #[DataProvider('roles')]
    public function testAllRolesDenyCrossDepartmentHttpReadsAndWrites(string $role): void
    {
        foreach (['clinic' => 'clinic', 'guidance' => 'guidance', 'facilities' => 'bmg'] as $prefix => $portal) {
            if (PortalContext::isRolePermitted($role, $portal)) continue;
            foreach (['GET' => $prefix . '/queue', 'POST' => $prefix . '/queue/call-next'] as $method => $path) {
                if ($prefix === 'facilities') $path = $method === 'GET' ? 'facilities' : 'facilities/units/store';
                $result = $this->requestAs($role, $method, $path);
                $result->assertStatus(403);
                $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
                $this->assertSame($role, session('user')['role']);
                $this->assertArrayNotHasKey('password', session('user'));
            }
        }
        // Superadmin has no forbidden department; explicitly preserve its bypass.
        if ($role === 'superadmin') {
            foreach (['clinic/queue', 'guidance/queue', 'facilities'] as $path) {
                foreach (['GET', 'POST'] as $method) $this->assertNull($this->boundary($role, $method, $path));
            }
        }
    }

    #[DataProvider('roles')]
    public function testAllRolesUseSeededGrantsAndMandatoryDepartmentMembership(string $role): void
    {
        foreach (['clinic' => 'clinic', 'guidance' => 'guidance', 'facilities' => 'bmg'] as $prefix => $portal) {
            foreach (['GET' => 'read', 'POST' => 'manage'] as $method => $action) {
                $path = $prefix === 'facilities' ? 'facilities' : $prefix . '/queue';
                $permission = $prefix === 'facilities' ? 'facilities.' . $action : $prefix . '.queue.' . $action;
                $result = $this->boundary($role, $method, $path);
                if (PortalContext::isRolePermitted($role, $portal)) {
                    $this->assertNull($result, $role . ' ' . $permission);
                    if ($role !== 'superadmin') {
                        $this->assertContains($permission, session('user')['permissions']);
                        $this->revoke($role, $permission);
                        $this->assertSame(403, $this->boundary($role, $method, $path)->getStatusCode());
                    }
                } else {
                    $this->assertSame(403, $result->getStatusCode());
                }
            }
        }
        // Guidance's seeded patient-read grant must not open the clinic department.
        if (str_starts_with($role, 'guidance_') || $role === 'counsellor') {
            $this->assertContains('clinic.patients.read', session('user')['permissions']);
            $this->requestAs($role, 'GET', 'clinic/patients')->assertStatus(403);
        }
    }

    public function testPersonalPortalAndAppointmentCapabilitiesAreFreshPermissionGated(): void
    {
        foreach (['portal', 'portal/appointments', 'portal/records'] as $path) {
            $this->assertNull($this->boundary('clinic_staff', 'GET', $path));
            $this->assertNull($this->boundary('student', 'GET', $path));
            $this->assertNull($this->boundary('employee', 'GET', $path));
        }
        foreach (['portal/appointments/clinic/create', 'portal/appointments/guidance/create'] as $path) {
            $this->assertNull($this->boundary('employee', 'GET', $path));
        }
        $this->assertNull($this->boundary('clinic_staff', 'GET', 'portal/appointments/clinic/create'));
        $this->revoke('employee', 'portal.appointments.create');
        $this->assertSame(403, $this->boundary('employee', 'GET', 'portal/appointments/clinic/create')->getStatusCode());
        $this->assertSame(403, $this->boundary('clinic_staff', 'GET', 'portal/appointments/clinic/create')->getStatusCode());
        $this->revoke('employee', 'portal.records.read');
        $this->assertSame(403, $this->boundary('employee', 'GET', 'portal/records')->getStatusCode());
        $this->assertSame(403, $this->boundary('clinic_staff', 'GET', 'portal/records')->getStatusCode());
    }

    public function testEmployeeReferralMigrationRemovesOnlyUnsupportedMapping(): void
    {
        $permission = $this->db->table('permissions')->where('code', 'referrals.create')->get()->getRowArray();
        $this->assertNotNull($permission);
        $this->db->table('role_permissions')->insert(['role' => 'employee', 'permission_id' => $permission['id']]);
        $this->db->table('role_permissions')->insert(['role' => 'clinic_staff', 'permission_id' => $permission['id']]);
        require_once APPPATH . 'Database/Migrations/2026-10-19-000009_RetireEmployeeReferralGrant.php';
        (new \App\Database\Migrations\RetireEmployeeReferralGrant(Database::forge($this->db)))->up();
        $this->assertSame(0, $this->db->table('role_permissions')->where('role', 'employee')
            ->where('permission_id', $permission['id'])->countAllResults());
        $this->assertSame(2, $this->db->table('role_permissions')->where('role', 'clinic_staff')
            ->where('permission_id', $permission['id'])->countAllResults());
    }

    public function testUnsupportedEmployeeReferralGrantIsNotSeeded(): void
    {
        $employeePermission = $this->db->table('permissions')->where('code', 'referrals.create')->get()->getRowArray();
        $this->assertNotNull($employeePermission);
        $this->assertSame(0, $this->db->table('role_permissions')->where('role', 'employee')
            ->where('permission_id', $employeePermission['id'])->countAllResults());
    }

    public function testReportsExportRequiresFreshReadExportAndMainPortalGrants(): void
    {
        $this->assertNull($this->boundary('report_viewer', 'GET', 'reports/export'));
        $this->assertNull($this->boundary('clinic_admin', 'POST', 'reports/export/operational'));
        $this->revoke('report_viewer', 'reports.export');
        $this->assertSame(403, $this->boundary('report_viewer', 'GET', 'reports/export')->getStatusCode());
        (new PermissionsAndRolesSeeder(config('Database')))->run();
        $this->revoke('report_viewer', 'reports.read');
        $this->assertSame(403, $this->boundary('report_viewer', 'GET', 'reports/export')->getStatusCode());
        $this->assertSame('main', PortalContext::routePortal('reports/export'));
    }

    public function testGuidanceQueuePickerRequiresCreateAndActiveSameTenantTicket(): void
    {
        $ticket = ['id' => 31, 'tenant_id' => 1, 'person_id' => 1, 'unit' => 'guidance',
            'queue_date' => \App\Services\Scheduling\SchedulingClock::today(), 'status' => 'called'];
        $this->db->table('queue_entries')->insert($ticket);
        $this->assertNull($this->boundary('counsellor', 'GET', 'guidance/cases/choose-for-ticket/31'));
        $this->revoke('counsellor', 'guidance.cases.create');
        $this->assertSame(403, $this->boundary('counsellor', 'GET', 'guidance/cases/choose-for-ticket/31')->getStatusCode());
    }

    public function testRevokedDashboardDatasetPermissionsCannotBeBypassedViaHomeAliases(): void
    {
        foreach (['clinic_staff' => ['clinic', 'clinic.queue.read', 'clinic.encounters.read', 'clinic.appointments.read', 'clinic.inventory.read'],
            'counsellor' => ['guidance', 'guidance.cases.read', 'guidance.queue.read', 'referrals.read']] as $role => $spec) {
            $unit = array_shift($spec);
            foreach ($spec as $permission) {
                $this->assertNull($this->boundary($role, 'GET', $unit));
                $this->revoke($role, $permission);
                foreach ([$unit, $unit . '/dashboard'] as $path) {
                    $result = $this->requestAs($role, 'GET', $path);
                    $result->assertStatus(403);
                    $this->assertStringContainsString($permission, $result->getBody());
                }
                (new PermissionsAndRolesSeeder(config('Database')))->run();
            }
        }
    }

    public function testOpeningSessionRequiresCreateNotMerelyWriteAndStillChecksOwnership(): void
    {
        $owner = (int) $this->identity('counsellor')['user']['id'];
        $this->db->table('counselling_cases')->insert(['id' => 1, 'tenant_id' => 1, 'person_id' => $owner,
            'counsellor_user_id' => $owner, 'status' => 'open']);
        $this->assertNull($this->boundary('counsellor', 'POST', 'guidance/cases/1/session'));
        $this->revoke('counsellor', 'guidance.cases.write');
        $this->assertNull($this->boundary('counsellor', 'POST', 'guidance/cases/1/session'));
        (new PermissionsAndRolesSeeder(config('Database')))->run();
        $this->revoke('counsellor', 'guidance.cases.create');
        $result = $this->requestAs('counsellor', 'POST', 'guidance/cases/1/session');
        $result->assertStatus(403);
        $this->assertStringContainsString('guidance.cases.create', $result->getBody());
        $this->assertSame(0, $this->db->table('counselling_sessions')->countAllResults());
    }

    public function testOtherCounselorsCaseSessionAndFollowupAreForbiddenWithOversightPreserved(): void
    {
        $owner = (int) $this->identity('guidance_admin')['user']['id'];
        $this->db->table('counselling_cases')->insert(['id' => 1, 'tenant_id' => 1, 'person_id' => $owner,
            'counsellor_user_id' => $owner, 'status' => 'open']);
        $this->db->table('counselling_sessions')->insert(['id' => 1, 'tenant_id' => 1, 'case_id' => 1, 'status' => 'in_progress']);
        $this->db->table('guidance_followups')->insert(['id' => 1, 'tenant_id' => 1, 'case_id' => 1]);
        foreach (['GET' => ['guidance/cases/1', 'guidance/sessions/1/read'],
            'POST' => ['guidance/cases/1/session', 'guidance/cases/1/followups', 'guidance/sessions/1/notes',
                'guidance/sessions/1/close', 'guidance/followups/1/complete']] as $method => $paths) {
            foreach ($paths as $path) {
                $result = $this->requestAs('counsellor', $method, $path);
                $result->assertStatus(403);
                $this->assertStringContainsString('another counselor', $result->getBody());
                foreach (['superadmin', 'guidance_admin', 'guidance_supervisor'] as $role) {
                    $this->assertNull($this->boundary($role, $method, $path));
                }
            }
        }
        $this->db->table('counselling_cases')->where('id', 1)->update(['tenant_id' => 2]);
        $this->requestAs('superadmin', 'GET', 'guidance/cases/1')->assertStatus(404);
    }

    public function testChangedRoleSuspensionAndAuthVersionIgnoreStaleSessionPrivileges(): void
    {
        $id = (int) $this->identity('clinic_staff')['user']['id'];
        $this->db->table('users')->where('id', $id)->update(['role' => 'student']);
        $this->requestAs('clinic_staff', 'GET', 'clinic/queue')->assertStatus(403);
        $this->assertSame('student', session('user')['role']);
        $this->db->table('users')->where('id', $id)->update(['status' => 'suspended']);
        $this->requestAs('clinic_staff', 'GET', 'clinic/queue')->assertStatus(401);
        $this->assertNull(session('user'));
        $this->db->table('users')->where('id', $id)->update(['status' => 'active', 'auth_version' => 2]);
        $this->requestAs('clinic_staff', 'POST', 'clinic/queue/call-next')->assertStatus(401);
        $this->assertNull(session('user'));
    }

    public function testReportsDoNotQueryAuditWithoutAuditGrant(): void
    {
        // Deliberately no audit_events table: the real helper must return before querying it.
        $method = new \ReflectionMethod(DashboardController::class, 'auditSummary');
        foreach (['report_viewer', 'clinic_staff', 'counsellor', 'bmg_admin'] as $role) {
            session()->set($this->identity($role));
            $actor = (new AuthenticatedUser())->current();
            $this->assertSame(['canReadAudit' => false, 'totalAuditEvents' => null, 'recentAudit' => []],
                $method->invoke(new DashboardController(), $this->db, $actor, 1));
        }
        $this->requestAs('report_viewer', 'GET', 'admin')->assertStatus(403);
        $this->requestAs('student', 'GET', 'admin/dashboard')->assertStatus(403);
    }

    #[DataProvider('roles')]
    public function testGovernanceGrantMatrixAndCommissioningException(string $role): void
    {
        session()->set($this->identity($role));
        $actor = (new AuthenticatedUser())->current();
        $reports = in_array($role, ['superadmin', 'clinic_admin', 'clinic_staff', 'guidance_admin',
            'guidance_supervisor', 'counsellor', 'bmg_admin', 'audit_reader', 'report_viewer'], true);
        $audit = in_array($role, ['superadmin', 'audit_reader'], true);
        $this->assertSame($reports, in_array('reports.read', $actor['permissions'], true));
        $this->assertSame($audit, in_array('audit.read', $actor['permissions'], true));
        $this->assertNotContains('facilities.hardware.manage', $actor['permissions']);
        $request = service('request');
        $request->setMethod('GET');
        $request->setHeader('Accept', 'application/json');
        foreach (['reports.read' => $reports, 'audit.read' => $audit] as $permission => $allowed) {
            $response = (new AuthFilter())->before($request, [$permission]);
            if ($allowed) $this->assertNull($response);
            else $this->assertSame(403, $response->getStatusCode());
        }
        if (!$audit) {
            $this->requestAs($role, 'GET', 'admin/audit')->assertStatus(403);
            $this->requestAs($role, 'POST', 'admin/audit/verify')->assertStatus(403);
        }
    }

    public function testTeamsDoNotAcquireDashboardOrClinicalGrantRequirements(): void
    {
        foreach (['clinic_staff' => 'clinic', 'counsellor' => 'guidance', 'facilities_op' => 'facilities'] as $role => $unit) {
            $this->db->table('role_permissions')->where('role', $role)->delete();
            foreach (['GET' => ['/teams', '/teams/chat/history', '/teams/calendar'],
                'POST' => ['/teams/store', '/teams/chat/send', '/teams/calendar/events/store']] as $method => $suffixes) {
                foreach ($suffixes as $suffix) $this->assertNull($this->boundary($role, $method, $unit . $suffix));
            }
        }
    }

    public function testEngagementRequiresBothDepartmentAndSpecificGrant(): void
    {
        foreach (['student', 'employee', 'clinic_staff', 'report_viewer'] as $role) {
            session()->set($this->identity($role));
            try {
                EngagementAccess::actor('guidance.surveys.manage', 'guidance');
                $this->fail('Unexpected publishing access for ' . $role);
            } catch (\RuntimeException $e) {
                $this->assertSame(403, $e->getCode());
            }
        }
        session()->set($this->identity('guidance_admin'));
        $this->assertSame('guidance_admin', EngagementAccess::actor('guidance.surveys.manage', 'guidance')['role']);
        session()->set($this->identity('counsellor'));
        $this->expectExceptionCode(403);
        EngagementAccess::actor('guidance.responses.read', 'guidance');
    }
}

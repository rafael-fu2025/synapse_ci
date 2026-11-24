<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Migrations\CreateSystemArchiveGovernance;
use App\Filters\WebAccessFilter;
use App\Models\AuditEventModel;
use App\Services\Archive\ArchiveAccessPolicy;
use App\Services\Archive\ArchiveService;
use App\Services\Archive\LegalHoldService;
use App\Services\Archive\RetentionPolicyService;
use App\Services\Audit\AuditService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

require_once APPPATH . 'Database/Migrations/2026-11-25-000001_CreateSystemArchiveGovernance.php';

/**
 * RBAC, department-scope isolation, WebAccessFilter HTTP boundaries,
 * and sidebar visibility tests using isolated SQLite :memory: fixtures.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ArchiveAccessControlTest extends CIUnitTestCase
{
    protected $db;

    /** @var array<string, array<string, mixed>> */
    private array $actors = [];

    protected function setUp(): void
    {
        parent::setUp();

        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = [
            'DSN'        => '',
            'hostname'   => '',
            'username'   => '',
            'password'   => '',
            'database'   => ':memory:',
            'DBDriver'   => 'SQLite3',
            'DBPrefix'   => '',
            'pConnect'   => false,
            'DBDebug'    => true,
            'charset'    => 'utf8',
            'DBCollat'   => 'utf8_general_ci',
            'swapPre'    => '',
            'encrypt'    => false,
            'compress'   => false,
            'strictOn'   => false,
            'failover'   => [],
            'port'       => 0,
            'foreignKeys' => true,
            'busyTimeout' => 1000,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ];

        $this->db = Database::connect();
        $this->db->initialize();
        CurrentTenant::set(1);

        $this->installSchemaAndFixtures();
        (new CreateSystemArchiveGovernance(Database::forge($this->db)))->up();
        $this->grantExtraSeededPermissions();
        $this->loadActorDirectory();
    }

    protected function tearDown(): void
    {
        try {
            Services::resetSingle('uri');
            Services::resetSingle('request');
            Services::resetSingle('response');
            session()->destroy();
            $this->db->close();
            CurrentTenant::reset();
        } finally {
            parent::tearDown();
        }
    }

    public function testDepartmentScopeAndPermissionMatrixAcrossRoles(): void
    {
        // Clinic Admin: scoped strictly to clinic + clinic teams; forbidden on holds/policies/guidance/facilities/admin.
        $clinicServices = $this->servicesFor('clinic_admin');
        $clinicList = $clinicServices['archive']->listing();
        $clinicModules = array_unique(array_column($clinicList['items'], 'module'));
        $this->assertEqualsCanonicalizing(['clinic', 'teams'], $clinicModules);
        foreach ($clinicList['items'] as $item) {
            if ($item['module'] === 'teams') {
                $this->assertSame('clinic', $item['unit']);
            }
        }
        $eligibleClinicTypes = array_keys($clinicServices['archive']->eligibleListing()['allowedTypes']);
        $this->assertContains('clinic_encounter', $eligibleClinicTypes);
        $this->assertNotContains('counselling_case', $eligibleClinicTypes);
        $this->assertNotContains('bmg_unit', $eligibleClinicTypes);

        $this->assertForbidden(fn () => $clinicServices['archive']->archive('counselling_case', 1, 'Cross-dept test'));
        $this->assertForbidden(fn () => $clinicServices['archive']->archive('bmg_unit', 1, 'Cross-dept test'));
        $this->assertForbidden(fn () => $clinicServices['archive']->archive('carousel_slide', 1, 'Cross-dept test'));
        $this->assertForbidden(fn () => $clinicServices['archive']->archive('team_task', 2, 'Cross-unit guidance task'));
        $this->assertForbidden(fn () => $clinicServices['holds']->placeHold('clinic_encounter', 1, 'Missing hold perm'));
        $this->assertForbidden(fn () => $clinicServices['retention']->savePolicy(null, [
            'policy_code' => 'RET-NO-01', 'module' => 'clinic', 'entity_type' => '*',
            'title' => 'Blocked', 'duration_months' => 12, 'legal_basis' => 'Test',
        ]));

        // Guidance Admin: scoped to guidance + guidance teams; holds allowed on guidance, denied on clinic/policies.
        $guidanceAdminServices = $this->servicesFor('guidance_admin');
        $hold = $guidanceAdminServices['holds']->placeHold('counselling_case', 1, 'Guidance litigation hold', 'GH-1');
        $this->assertSame('guidance', $hold['module']);
        $guidanceAdminServices['holds']->liftHold((int) $hold['id'], 'Guidance hold cleared');
        $this->assertForbidden(fn () => $guidanceAdminServices['holds']->placeHold('clinic_encounter', 1, 'Cross-dept hold'));
        $this->assertForbidden(fn () => $guidanceAdminServices['archive']->archive('clinic_encounter', 1, 'Cross-dept test'));
        $this->assertForbidden(fn () => $guidanceAdminServices['retention']->savePolicy(null, [
            'policy_code' => 'RET-NO-02', 'module' => 'guidance', 'entity_type' => '*',
            'title' => 'Blocked', 'duration_months' => 12, 'legal_basis' => 'Test',
        ]));

        // Guidance Supervisor: can archive/restore guidance records, forbidden on holds and policies.
        $supervisorServices = $this->servicesFor('guidance_supervisor');
        $archivedCase = $supervisorServices['archive']->archive('counselling_case', 1, 'Supervisor closure archive');
        $this->assertSame('archived', $archivedCase['state']);
        $restoredCase = $supervisorServices['archive']->restore('counselling_case', 1, 'Re-opened by supervisor');
        $this->assertSame('restored', $restoredCase['state']);
        $this->assertForbidden(fn () => $supervisorServices['holds']->placeHold('counselling_case', 1, 'Supervisor hold blocked'));
        $this->assertForbidden(fn () => $supervisorServices['retention']->savePolicy(null, [
            'policy_code' => 'RET-NO-03', 'module' => 'guidance', 'entity_type' => '*',
            'title' => 'Blocked', 'duration_months' => 12, 'legal_basis' => 'Test',
        ]));

        // BMG Admin: scoped to facilities + facilities teams; forbidden on clinic/guidance/admin/holds/policies.
        $bmgServices = $this->servicesFor('bmg_admin');
        $archivedDrum = $bmgServices['archive']->archive('bmg_unit', 1, 'Drum decommissioned');
        $this->assertSame('archived', $archivedDrum['state']);
        $restoredDrum = $bmgServices['archive']->restore('bmg_unit', 1, 'Drum recommissioned');
        $this->assertSame('restored', $restoredDrum['state']);
        $this->assertForbidden(fn () => $bmgServices['archive']->archive('clinic_encounter', 1, 'Cross-dept test'));
        $this->assertForbidden(fn () => $bmgServices['archive']->archive('team_task', 1, 'Cross-unit clinic task'));
        $this->assertForbidden(fn () => $bmgServices['holds']->placeHold('bmg_unit', 1, 'No hold grant'));
        $this->assertForbidden(fn () => $bmgServices['retention']->savePolicy(null, [
            'policy_code' => 'RET-NO-04', 'module' => 'facilities', 'entity_type' => '*',
            'title' => 'Blocked', 'duration_months' => 12, 'legal_basis' => 'Test',
        ]));

        // Audit Reader: read-only governance across all modules; all mutating paths return 403.
        $readerServices = $this->servicesFor('audit_reader');
        $readerList = $readerServices['archive']->listing();
        $this->assertGreaterThanOrEqual(4, count($readerList['items']));
        $this->assertNotEmpty($readerServices['retention']->listPolicies($this->actors['audit_reader']));
        $this->assertArrayHasKey('items', $readerServices['holds']->listHolds($this->actors['audit_reader']));
        $this->assertForbidden(fn () => $readerServices['archive']->archive('clinic_encounter', 1, 'Read-only denied'));
        $this->assertForbidden(fn () => $readerServices['archive']->restore('clinic_encounter', 99, 'Read-only denied'));
        $this->assertForbidden(fn () => $readerServices['holds']->placeHold('clinic_encounter', 1, 'Read-only denied'));
        $this->assertForbidden(fn () => $readerServices['retention']->savePolicy(null, [
            'policy_code' => 'RET-NO-05', 'module' => 'all', 'entity_type' => '*',
            'title' => 'Blocked', 'duration_months' => 12, 'legal_basis' => 'Test',
        ]));

        // Non-governance roles cannot read or access Archive governance at all.
        foreach (['clinic_staff', 'counsellor', 'facilities_op', 'student', 'employee'] as $deniedRole) {
            $services = $this->servicesFor($deniedRole);
            $this->assertForbidden(fn () => $services['policy']->requireActor('archive.read'), $deniedRole);
        }
    }

    public function testWebAccessFilterEnforcesArchivePermissionsOnCenterAndInlineRoutes(): void
    {
        $this->assertSame(403, $this->filterRequest('student', 'GET', 'admin/archive')?->getStatusCode());
        $this->assertSame(403, $this->filterRequest('clinic_staff', 'GET', 'admin/archive')?->getStatusCode());
        $this->assertSame(403, $this->filterRequest('counsellor', 'GET', 'admin/archive')?->getStatusCode());
        $this->assertSame(403, $this->filterRequest('facilities_op', 'GET', 'admin/archive')?->getStatusCode());

        $this->assertNull($this->filterRequest('audit_reader', 'GET', 'admin/archive'));
        $this->assertSame(403, $this->filterRequest('audit_reader', 'POST', 'admin/archive/records/archive')?->getStatusCode());

        $this->assertNull($this->filterRequest('clinic_admin', 'GET', 'admin/archive'));
        $this->assertNull($this->filterRequest('clinic_admin', 'POST', 'admin/archive/records/archive'));
        $this->assertSame(403, $this->filterRequest('clinic_admin', 'POST', 'admin/archive/policies/save')?->getStatusCode());
        $this->assertSame(403, $this->filterRequest('clinic_admin', 'POST', 'admin/archive/holds/place')?->getStatusCode());

        $this->assertSame(403, $this->filterRequest('clinic_staff', 'POST', 'clinic/encounters/1/archive')?->getStatusCode());
        $this->assertSame(403, $this->filterRequest('clinic_staff', 'POST', 'clinic/isolation/1/archive')?->getStatusCode());
        $this->assertSame(403, $this->filterRequest('clinic_staff', 'POST', 'clinic/teams/1/archive')?->getStatusCode());
        $this->assertNull($this->filterRequest('clinic_admin', 'POST', 'clinic/encounters/1/archive'));
        $this->assertNull($this->filterRequest('clinic_admin', 'POST', 'clinic/isolation/1/archive'));
        $this->assertNull($this->filterRequest('clinic_admin', 'POST', 'clinic/teams/1/archive'));

        $this->assertSame(403, $this->filterRequest('counsellor', 'POST', 'guidance/cases/1/archive')?->getStatusCode());
        $this->assertSame(403, $this->filterRequest('counsellor', 'POST', 'guidance/episodes/1/archive')?->getStatusCode());
        $this->assertNull($this->filterRequest('guidance_admin', 'POST', 'guidance/cases/1/archive'));
        $this->assertNull($this->filterRequest('guidance_admin', 'POST', 'guidance/episodes/1/archive'));

        $this->assertNull($this->filterRequest('superadmin', 'POST', 'admin/archive/policies/save'));
        $this->assertNull($this->filterRequest('superadmin', 'POST', 'admin/archive/holds/place'));
    }

    public function testSidebarShowsArchiveCenterLinkOnlyToRolesHoldingArchiveRead(): void
    {
        $app = config('App');
        $app->baseURL = 'http://localhost/';
        Services::injectMock('uri', new SiteURI($app, 'clinic'));

        foreach (['superadmin', 'clinic_admin', 'guidance_admin', 'guidance_supervisor', 'bmg_admin', 'audit_reader'] as $role) {
            session()->set([
                'tenant_id' => 1,
                'user'      => $this->actors[$role] + ['logged_in' => true, 'name' => ucfirst($role), 'person_kind' => 'employee'],
            ]);
            Services::resetSingle('renderer');
            $html = view('partials/sidebar');
            $this->assertStringContainsString('/admin/archive', $html, "{$role} must see Archive Management sidebar link");
        }

        foreach (['clinic_staff', 'counsellor', 'facilities_op', 'student', 'employee'] as $role) {
            session()->set([
                'tenant_id' => 1,
                'user'      => $this->actors[$role] + ['logged_in' => true, 'name' => ucfirst($role), 'person_kind' => $role === 'student' ? 'student' : 'employee'],
            ]);
            Services::resetSingle('renderer');
            $html = view('partials/sidebar');
            $this->assertStringNotContainsString('/admin/archive', $html, "{$role} must NOT see Archive Management sidebar link");
        }
    }

    public function testLayoutLoadsArchiveActionsBeforeBackgroundActions(): void
    {
        $layout = file_get_contents(APPPATH . 'Views/layouts/app.php');
        $this->assertIsString($layout);

        $confirmPos = strpos($layout, "assets/js/confirm.js");
        $archivePos = strpos($layout, "assets/js/archive-actions.js");
        $bgPos      = strpos($layout, "assets/js/background-actions.js");

        $this->assertNotFalse($confirmPos);
        $this->assertNotFalse($archivePos);
        $this->assertNotFalse($bgPos);
        $this->assertGreaterThan($confirmPos, $archivePos, 'archive-actions.js must load after confirm.js');
        $this->assertLessThan($bgPos, $archivePos, 'archive-actions.js must load before background-actions.js');
    }

    public function testClinicIsolationArchiveAndRestoreReturnJsonForAsyncToastClients(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        // Row #1 in setUp is cleared (archivable); insert #2 as active_isolation (must return 409).
        $this->db->table('clinic_isolation_cases')->insert([
            'id' => 2, 'tenant_id' => 1, 'person_id' => 10, 'condition_code' => 'measles',
            'clearance_status' => 'active_isolation', 'isolation_start_date' => '2026-11-20',
            'isolation_end_date' => '2026-11-27', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $actor = $this->actors['clinic_admin'];
        $nowTs = time();
        $sessionData = [
            'tenant_id' => 1,
            'user'      => [
                'id'           => $actor['id'],
                'tenant_id'    => 1,
                'auth_version' => 1,
                'role'         => 'clinic_admin',
                'logged_in'    => true,
            ],
            '_auth'     => [
                'started_at'   => $nowTs,
                'last_seen_at' => $nowTs,
                'version'      => 1,
                'session_id'   => session_id(),
            ],
        ];
        session()->set($sessionData);

        $dispatch = function (string $path) use ($sessionData): Response {
            $app = clone config('App');
            $app->baseURL = 'http://localhost/';
            $app->indexPage = '';
            $uri = new SiteURI($app, $path);
            $request = new IncomingRequest($app, $uri, null, new UserAgent());
            $request->setMethod('POST');
            $request->setHeader('Accept', 'application/json');
            $request->setHeader('X-Requested-With', 'XMLHttpRequest');
            $request->setHeader('X-Synapse-Feedback', 'toast');
            $response = new Response($app);
            Services::injectMock('request', $request);
            Services::injectMock('response', $response);
            session()->set($sessionData);

            $controller = new \App\Controllers\ClinicIsolationController();
            $controller->initController($request, $response, Services::logger());
            $id = (int) basename(dirname($path));
            $result = str_ends_with($path, '/restore') ? $controller->restore($id) : $controller->archive($id);
            $this->assertInstanceOf(Response::class, $result);
            return $result;
        };

        $archiveRes = $dispatch('clinic/isolation/1/archive');
        $this->assertSame(200, $archiveRes->getStatusCode());
        $archiveJson = json_decode($archiveRes->getBody(), true);
        $this->assertTrue($archiveJson['success'] ?? false);
        $this->assertArrayHasKey('csrf', $archiveJson);

        $restoreRes = $dispatch('clinic/isolation/1/restore');
        $this->assertSame(200, $restoreRes->getStatusCode());
        $restoreJson = json_decode($restoreRes->getBody(), true);
        $this->assertTrue($restoreJson['success'] ?? false);

        $conflictRes = $dispatch('clinic/isolation/2/archive');
        $this->assertSame(409, $conflictRes->getStatusCode());
        $conflictJson = json_decode($conflictRes->getBody(), true);
        $this->assertFalse($conflictJson['success'] ?? true);
    }

    private function filterRequest(string $role, string $method, string $route): ?Response
    {
        $actor = $this->actors[$role];
        $nowTs = time();
        session()->set([
            'tenant_id' => 1,
            'user'      => [
                'id'           => $actor['id'],
                'tenant_id'    => 1,
                'auth_version' => 1,
                'role'         => $role,
                'logged_in'    => true,
            ],
            '_auth'     => [
                'started_at'   => $nowTs,
                'last_seen_at' => $nowTs,
                'version'      => 1,
                'session_id'   => session_id(),
            ],
        ]);
        $app = clone config('App');
        $app->baseURL = 'http://localhost/';
        $app->indexPage = '';
        $uri = new SiteURI($app, $route);
        $request = new IncomingRequest($app, $uri, null, new UserAgent());
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response($app));

        return (new WebAccessFilter())->before($request);
    }

    /** @return array{policy:ArchiveAccessPolicy,retention:RetentionPolicyService,holds:LegalHoldService,archive:ArchiveService} */
    private function servicesFor(string $role): array
    {
        $actor = $this->actors[$role];
        $policy = new ArchiveAccessPolicy(static fn (): array => $actor);
        $audit = new AuditService(new AuditEventModel($this->db), db: $this->db);
        $retention = new RetentionPolicyService($this->db, $audit, $policy);
        $holds = new LegalHoldService($this->db, $audit, $policy);
        $archive = new ArchiveService($this->db, $audit, $policy, $retention, $holds);

        return compact('policy', 'retention', 'holds', 'archive');
    }

    private function assertForbidden(callable $fn, string $context = ''): void
    {
        try {
            $fn();
            $this->fail('Expected HTTP 403 exception' . ($context !== '' ? " ({$context})" : ''));
        } catch (RuntimeException $e) {
            $this->assertSame(403, $e->getCode(), ($context !== '' ? "{$context}: " : '') . $e->getMessage());
        }
    }

    private function installSchemaAndFixtures(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $past = gmdate('Y-m-d H:i:s', time() - 86400);

        $tables = [
            'CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER DEFAULT 1, deleted_at TEXT NULL)',
            'CREATE TABLE people (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, kind TEXT NOT NULL, first_name TEXT NOT NULL, last_name TEXT NOT NULL, mis_id TEXT NULL, email TEXT NULL, deleted_at TEXT NULL)',
            'CREATE TABLE employee_profiles (person_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, is_teaching INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, person_id INTEGER NULL, username TEXT NOT NULL, email TEXT NULL, role TEXT NOT NULL, status TEXT NOT NULL, auth_version INTEGER NOT NULL DEFAULT 1, deleted_at TEXT NULL)',
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL, name TEXT NULL, module TEXT NULL)',
            'CREATE TABLE role_permissions (role TEXT NOT NULL, permission_id INTEGER NOT NULL)',
            'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, event_type TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, actor_user_id INTEGER NULL, actor_role TEXT NULL, actor_ip TEXT NULL, action TEXT NOT NULL, payload_json TEXT NULL, prev_hash TEXT NOT NULL, curr_hash TEXT NOT NULL, hash_version INTEGER NOT NULL DEFAULT 2, occurred_at TEXT NOT NULL)',
            'CREATE TABLE announcements (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, title TEXT NOT NULL, audience TEXT NULL, status TEXT NOT NULL, published_at TEXT NULL, version INTEGER NOT NULL DEFAULT 1, created_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE surveys (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, title TEXT NOT NULL, audience TEXT NULL, status TEXT NOT NULL, published_version INTEGER NULL, version INTEGER NOT NULL DEFAULT 1, created_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE survey_question_bank (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, status TEXT NOT NULL, current_revision INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, created_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE clinic_medicines (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, name TEXT NOT NULL, strength TEXT NULL, category TEXT NULL, dosage_form TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE clinic_encounters (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, person_id INTEGER NOT NULL, attending_user_id INTEGER NULL, encoded_by_user_id INTEGER NULL, status TEXT NOT NULL, visited_at TEXT NULL, deleted_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE clinic_isolation_cases (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, person_id INTEGER NOT NULL, condition_code TEXT NOT NULL, clearance_status TEXT NOT NULL, isolation_start_date TEXT NOT NULL, isolation_end_date TEXT NOT NULL, deleted_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE clinic_enrollment_clearances (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, person_id INTEGER NOT NULL, school_year TEXT NULL, semester TEXT NULL, clearance_status TEXT NULL, deleted_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE counselling_cases (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, person_id INTEGER NOT NULL, counsellor_user_id INTEGER NOT NULL, case_number TEXT NOT NULL, category TEXT NULL, status TEXT NOT NULL, title TEXT NULL, deleted_at TEXT NULL, opened_at TEXT NULL, closed_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE guidance_episodes (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, person_id INTEGER NOT NULL, primary_counselor_user_id INTEGER NULL, status TEXT NOT NULL, deleted_at TEXT NULL, opened_at TEXT NULL, closed_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE guidance_form_instances (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, instance_number TEXT NULL, person_id INTEGER NOT NULL, status TEXT NOT NULL, finalized_at TEXT NULL, version INTEGER NOT NULL DEFAULT 1, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE guidance_case_transfers (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, case_id INTEGER NOT NULL, to_counsellor_user_id INTEGER NOT NULL, transfer_type TEXT NOT NULL, status TEXT NOT NULL)',
            'CREATE TABLE bmg_units (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit_code TEXT NOT NULL, name TEXT NOT NULL, status TEXT NOT NULL, deleted_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE bmg_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit_id INTEGER NOT NULL, status TEXT NOT NULL)',
            'CREATE TABLE bmg_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit_id INTEGER NULL, status TEXT NOT NULL, revoked_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE bmg_rotation_schedules (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit_id INTEGER NOT NULL, enabled INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, updated_by_user_id INTEGER NULL, updated_at TEXT NULL)',
            'CREATE TABLE bmg_rotation_commands (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit_id INTEGER NOT NULL, status TEXT NOT NULL, cancelled_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE team_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, title TEXT NOT NULL, status TEXT NOT NULL, priority TEXT NULL, version INTEGER NOT NULL DEFAULT 1, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE team_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, title TEXT NOT NULL, status TEXT NOT NULL, starts_at TEXT NULL, ends_at TEXT NULL, version INTEGER NOT NULL DEFAULT 1, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE carousel_slides (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, title TEXT NOT NULL, is_default INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 0, fit_mode TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
        ];
        foreach ($tables as $sql) {
            $this->db->query($sql);
        }

        $this->db->table('tenants')->insert(['id' => 1, 'is_active' => 1, 'deleted_at' => null]);

        $roles = [
            1  => 'superadmin',
            2  => 'clinic_admin',
            3  => 'guidance_admin',
            4  => 'guidance_supervisor',
            5  => 'bmg_admin',
            6  => 'audit_reader',
            7  => 'clinic_staff',
            8  => 'counsellor',
            9  => 'facilities_op',
            10 => 'student',
            11 => 'employee',
        ];
        foreach ($roles as $id => $role) {
            $kind = $role === 'student' ? 'student' : 'employee';
            $this->db->table('people')->insert([
                'id' => $id, 'tenant_id' => 1, 'kind' => $kind, 'first_name' => 'Test',
                'last_name' => ucfirst($role), 'mis_id' => 'MIS-' . $id, 'email' => "{$role}@example.edu",
            ]);
            $this->db->table('users')->insert([
                'id' => $id, 'tenant_id' => 1, 'person_id' => $role === 'superadmin' ? null : $id,
                'username' => $role, 'email' => "{$role}@example.edu", 'role' => $role,
                'status' => 'active', 'auth_version' => 1,
            ]);
        }

        // Pre-archived legacy items so ArchiveAccessControlTest can verify listing visibility per role.
        $this->db->table('announcements')->insertBatch([
            ['id' => 1, 'tenant_id' => 1, 'unit' => 'clinic', 'title' => 'Clinic Archived Notice', 'audience' => 'all', 'status' => 'archived', 'version' => 1, 'created_by' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'tenant_id' => 1, 'unit' => 'guidance', 'title' => 'Guidance Archived Notice', 'audience' => 'all', 'status' => 'archived', 'version' => 1, 'created_by' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->db->table('clinic_medicines')->insert([
            'id' => 1, 'tenant_id' => 1, 'name' => 'Ibuprofen', 'strength' => '200mg', 'is_active' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('clinic_encounters')->insert([
            'id' => 1, 'tenant_id' => 1, 'person_id' => 10, 'status' => 'completed', 'visited_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('clinic_isolation_cases')->insert([
            'id' => 1, 'tenant_id' => 1, 'person_id' => 10, 'condition_code' => 'dengue',
            'clearance_status' => 'cleared', 'isolation_start_date' => '2026-10-01', 'isolation_end_date' => '2026-10-06',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('counselling_cases')->insert([
            'id' => 1, 'tenant_id' => 1, 'person_id' => 10, 'counsellor_user_id' => 3,
            'case_number' => 'GC-2026-101', 'category' => 'personal', 'status' => 'closed',
            'title' => 'Closed Guidance Case', 'opened_at' => $past, 'closed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('guidance_episodes')->insert([
            'id' => 1, 'tenant_id' => 1, 'person_id' => 10, 'primary_counselor_user_id' => 3,
            'status' => 'closed', 'opened_at' => $past, 'closed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('bmg_units')->insert([
            'id' => 1, 'tenant_id' => 1, 'unit_code' => 'DRUM-A1', 'name' => 'Alpha Drum',
            'status' => 'online', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('team_tasks')->insertBatch([
            ['id' => 1, 'tenant_id' => 1, 'unit' => 'clinic', 'title' => 'Clinic Done Task', 'status' => 'done', 'priority' => 'normal', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'tenant_id' => 1, 'unit' => 'guidance', 'title' => 'Guidance Done Task', 'status' => 'done', 'priority' => 'normal', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->db->table('carousel_slides')->insert([
            'id' => 1, 'tenant_id' => 1, 'title' => 'Custom Slide', 'is_default' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function grantExtraSeededPermissions(): void
    {
        // Grant facilities.configure for bmg_admin + backfill a clinic_team archive record for listing filter assertion.
        $this->db->table('permissions')->insert(['code' => 'facilities.configure', 'name' => 'Configure BMG drums', 'module' => 'facilities']);
        $facConfigId = (int) $this->db->insertID();
        $this->db->table('role_permissions')->insertBatch([
            ['role' => 'bmg_admin', 'permission_id' => $facConfigId],
            ['role' => 'superadmin', 'permission_id' => $facConfigId],
        ]);

        // Seed one archived clinic team_task record for listing isolation test (id=99 doesn't collide with active test tasks).
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('team_tasks')->insert([
            'id' => 99, 'tenant_id' => 1, 'unit' => 'clinic', 'title' => 'Clinic Archived Task',
            'status' => 'done', 'priority' => 'normal', 'version' => 1, 'deleted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('archive_records')->insert([
            'tenant_id' => 1, 'module' => 'teams', 'entity_type' => 'team_task', 'entity_id' => 99,
            'record_ref' => 'Clinic Archived Task', 'unit' => 'clinic', 'state' => 'archived',
            'reason' => 'Completed last month', 'archived_by_user_id' => 2, 'archived_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function loadActorDirectory(): void
    {
        $rows = $this->db->table('users')->where('tenant_id', 1)->get()->getResultArray();
        foreach ($rows as $row) {
            $permRows = $this->db->table('role_permissions rp')
                ->select('p.code')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role', $row['role'])
                ->get()
                ->getResultArray();
            $this->actors[$row['role']] = [
                'id'          => (int) $row['id'],
                'tenant_id'   => 1,
                'role'        => (string) $row['role'],
                'status'      => 'active',
                'username'    => (string) $row['username'],
                'permissions' => array_column($permRows, 'code'),
            ];
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\CreateStudentInternGovernance;
use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\PortalContext;
use App\Services\Scheduling\SchedulingClock;
use App\Services\StudentInternService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use ReflectionProperty;
use RuntimeException;

/** Isolated SQLite unit tests for Student Intern RBAC, portal routing, and placement validation. */
final class StudentInternPolicyTest extends CIUnitTestCase
{
    private BaseConnection $sqliteDb;
    private array $connections;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('SQLite3 is required.');
        }

        $this->sqliteDb = Database::connect([
            'DBDriver'   => 'SQLite3',
            'database'   => ':memory:',
            'DBPrefix'   => 'sipu_',
            'DBDebug'    => true,
            'foreignKeys' => true,
            'busyTimeout' => 1000,
        ], false);

        $prop = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $prop->getValue();
        $prop->setValue(null, ['tests' => $this->sqliteDb]);

        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT',
            'employee_profiles' => 'person_id INTEGER, tenant_id INTEGER, department TEXT, employment_status TEXT, is_teaching INTEGER',
            'student_profiles' => 'person_id INTEGER, tenant_id INTEGER, program TEXT, year_level TEXT, department TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, password_hash TEXT, last_login_at TEXT, updated_at TEXT, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'audit_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER, occurred_at TEXT',
            'student_intern_placements' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, person_id INTEGER, unit TEXT, intern_role TEXT, supervisor_user_id INTEGER, status TEXT, starts_at TEXT, ends_at TEXT, academic_term TEXT, assignment_reason TEXT, activated_at TEXT, ended_at TEXT, revoked_by_user_id INTEGER, revoke_reason TEXT, created_by_user_id INTEGER, updated_by_user_id INTEGER, version INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT',
        ] as $table => $cols) {
            $this->sqliteDb->query('CREATE TABLE sipu_' . $table . ' (' . $cols . ')');
        }

        $this->sqliteDb->table('tenants')->insert(['id' => 1, 'is_active' => 1]);
        $this->sqliteDb->table('people')->insertBatch([
            ['id' => 1, 'tenant_id' => 1, 'kind' => 'employee', 'first_name' => 'Clinic', 'last_name' => 'Admin', 'mis_id' => 'EMP-CA'],
            ['id' => 2, 'tenant_id' => 1, 'kind' => 'employee', 'first_name' => 'Clinic', 'last_name' => 'Nurse', 'mis_id' => 'EMP-CN'],
            ['id' => 3, 'tenant_id' => 1, 'kind' => 'employee', 'first_name' => 'Guidance', 'last_name' => 'Counselor', 'mis_id' => 'EMP-GC'],
            ['id' => 4, 'tenant_id' => 1, 'kind' => 'student', 'first_name' => 'Juan', 'last_name' => 'Intern', 'mis_id' => 'STU-1001'],
        ]);
        $this->sqliteDb->table('employee_profiles')->insertBatch([
            ['person_id' => 1, 'tenant_id' => 1, 'department' => 'Health Services', 'employment_status' => 'active'],
            ['person_id' => 2, 'tenant_id' => 1, 'department' => 'Health Services', 'employment_status' => 'active'],
            ['person_id' => 3, 'tenant_id' => 1, 'department' => 'Guidance', 'employment_status' => 'active'],
        ]);
        $this->sqliteDb->table('users')->insertBatch([
            ['id' => 1, 'tenant_id' => 1, 'person_id' => 1, 'username' => 'clinic.admin', 'role' => 'clinic_admin', 'status' => 'active'],
            ['id' => 2, 'tenant_id' => 1, 'person_id' => 2, 'username' => 'clinic.nurse', 'role' => 'clinic_staff', 'status' => 'active'],
            ['id' => 3, 'tenant_id' => 1, 'person_id' => 3, 'username' => 'guidance.counsellor', 'role' => 'counsellor', 'status' => 'active'],
            ['id' => 4, 'tenant_id' => 1, 'person_id' => 4, 'username' => 'juan.intern', 'role' => 'student', 'status' => 'active'],
        ]);

        (new PermissionsAndRolesSeeder(config('Database')))->run();
    }

    protected function tearDown(): void
    {
        if (isset($this->sqliteDb)) {
            AuthenticatedUser::clear();
            $this->sqliteDb->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        }
        parent::tearDown();
    }

    private function signInUser(int $userId): void
    {
        CurrentTenant::set(1);
        session()->set([
            'tenant_id' => 1,
            'user'      => ['id' => $userId, 'tenant_id' => 1, 'logged_in' => true, 'role' => 'student', 'permissions' => []],
            '_auth'     => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()],
        ]);
    }

    public function testPortalContextMapsAllThreeInternRolesCleanly(): void
    {
        $this->assertSame('clinic', PortalContext::preferredPortal('clinic_intern'));
        $this->assertSame('guidance', PortalContext::preferredPortal('guidance_intern'));
        $this->assertSame('bmg', PortalContext::preferredPortal('bmg_intern'));

        $this->assertTrue(PortalContext::isRolePermitted('clinic_intern', PortalContext::PORTAL_CLINIC));
        $this->assertFalse(PortalContext::isRolePermitted('clinic_intern', PortalContext::PORTAL_GUIDANCE));
        $this->assertFalse(PortalContext::isRolePermitted('clinic_intern', PortalContext::PORTAL_BMG));

        $this->assertTrue(PortalContext::isRolePermitted('guidance_intern', PortalContext::PORTAL_GUIDANCE));
        $this->assertFalse(PortalContext::isRolePermitted('guidance_intern', PortalContext::PORTAL_CLINIC));
        $this->assertFalse(PortalContext::isRolePermitted('guidance_intern', PortalContext::PORTAL_BMG));

        $this->assertTrue(PortalContext::isRolePermitted('bmg_intern', PortalContext::PORTAL_BMG));
        $this->assertFalse(PortalContext::isRolePermitted('bmg_intern', PortalContext::PORTAL_CLINIC));
        $this->assertFalse(PortalContext::isRolePermitted('bmg_intern', PortalContext::PORTAL_GUIDANCE));

        foreach (['clinic_intern', 'guidance_intern', 'bmg_intern'] as $role) {
            $this->assertTrue(PortalContext::isRolePermitted($role, PortalContext::PORTAL_MAIN));
        }
    }

    public function testInternPermissionsRetainSelfServiceWithoutPrivilegeEscalation(): void
    {
        $models = new UserModel($this->sqliteDb);

        $clinic = $models->getPermissionsForRole('clinic_intern');
        $this->assertContains('clinic.encounters.read', $clinic);
        $this->assertContains('portal.records.read', $clinic);
        $this->assertContains('guidance.assessments.take', $clinic);
        $this->assertContains('guidance.evaluations.submit', $clinic);
        $this->assertNotContains('clinic.encounters.seal', $clinic);
        $this->assertNotContains('clinic.inventory.write', $clinic);
        $this->assertNotContains('guidance.assessments.view', $clinic);

        $guidance = $models->getPermissionsForRole('guidance_intern');
        $this->assertContains('guidance.intake.process', $guidance);
        $this->assertContains('guidance.clinical.author', $guidance);
        $this->assertContains('guidance.cases.read', $guidance);
        $this->assertContains('portal.records.read', $guidance);
        $this->assertContains('guidance.evaluations.submit', $guidance);
        $this->assertNotContains('guidance.cases.read_any', $guidance);
        $this->assertNotContains('guidance.cases.create', $guidance);
        $this->assertNotContains('guidance.clinical.cosign', $guidance);
        $this->assertNotContains('guidance.surveys.manage', $guidance);
        $this->assertNotContains('guidance.assessments.view', $guidance);

        $bmg = $models->getPermissionsForRole('bmg_intern');
        $this->assertContains('facilities.read', $bmg);
        $this->assertContains('facilities.manage', $bmg);
        $this->assertContains('portal.records.read', $bmg);
        $this->assertNotContains('facilities.configure', $bmg);
        $this->assertNotContains('facilities.hardware.manage', $bmg);
    }

    public function testCreatePlacementValidationRejectsInvalidTargetsWindowsAndConflicts(): void
    {
        $this->signInUser(1);
        $service = new StudentInternService($this->sqliteDb);
        $now = SchedulingClock::now();
        $validStart = $now->modify('-1 hour')->format('Y-m-d H:i');
        $validEnd = $now->modify('+30 days')->format('Y-m-d H:i');

        // 1. Non-student employee cannot be placed as intern
        try {
            $service->createPlacement([
                'user_id' => 2,
                'unit' => 'clinic',
                'supervisor_user_id' => 1,
                'starts_at' => $validStart,
                'ends_at' => $validEnd,
                'reason' => 'Invalid employee placement',
            ]);
            $this->fail('Expected employee target to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
        }

        // 2. Guidance supervisor cannot supervise a Clinic intern
        try {
            $service->createPlacement([
                'user_id' => 4,
                'unit' => 'clinic',
                'supervisor_user_id' => 3,
                'starts_at' => $validStart,
                'ends_at' => $validEnd,
                'reason' => 'Cross-unit supervisor check',
            ]);
            $this->fail('Expected wrong-unit supervisor to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
        }

        // 3. Past end date or duration > 366 days rejected
        foreach ([
            [$now->modify('-5 days')->format('Y-m-d H:i'), $now->modify('-1 day')->format('Y-m-d H:i')],
            [$validStart, $now->modify('+400 days')->format('Y-m-d H:i')],
        ] as [$badStart, $badEnd]) {
            try {
                $service->createPlacement([
                    'user_id' => 4,
                    'unit' => 'clinic',
                    'supervisor_user_id' => 2,
                    'starts_at' => $badStart,
                    'ends_at' => $badEnd,
                    'reason' => 'Invalid window check',
                ]);
                $this->fail('Expected invalid placement window to be rejected.');
            } catch (RuntimeException $e) {
                $this->assertSame(422, $e->getCode());
            }
        }

        // 4. Valid creation succeeds, then duplicate open placement fails with 409, stale version fails with 409
        $created = $service->createPlacement([
            'user_id' => 4,
            'unit' => 'clinic',
            'supervisor_user_id' => 2,
            'starts_at' => $validStart,
            'ends_at' => $validEnd,
            'academic_term' => 'AY 2026-2027 1st Sem',
            'reason' => 'Approved BSN practicum rotation',
        ]);
        $this->assertSame('active', $created['status']);

        try {
            $service->createPlacement([
                'user_id' => 4,
                'unit' => 'clinic',
                'supervisor_user_id' => 2,
                'starts_at' => $validStart,
                'ends_at' => $validEnd,
                'reason' => 'Duplicate attempt',
            ]);
            $this->fail('Expected duplicate placement conflict.');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }

        try {
            $service->updatePlacement((int) $created['id'], [
                'version' => '999',
                'supervisor_user_id' => 2,
                'ends_at' => $now->modify('+45 days')->format('Y-m-d H:i'),
                'reason' => 'Stale version update',
            ]);
            $this->fail('Expected version conflict.');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }

        // Verify CreateStudentInternGovernance migration constant matches seeder grants
        require_once APPPATH . 'Database/Migrations/2026-11-26-000001_CreateStudentInternGovernance.php';
        $this->assertContains('interns.manage', array_column(CreateStudentInternGovernance::INTERN_PERMISSIONS, 'code'));
    }
}

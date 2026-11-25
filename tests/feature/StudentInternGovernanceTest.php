<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AuthController;
use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\Audit\AuditService;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Facilities\BmgService;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidancePractitionerService;
use App\Services\Scheduling\SchedulingClock;
use App\Services\StudentInternService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StudentInternGovernanceTest extends CIUnitTestCase
{
    use FeatureTestTrait {
        setupRequest as private setupBaseRequest;
    }

    private string $httpHost = 'localhost';

    protected function setupRequest(string $method, ?string $path = null): IncomingRequest
    {
        $request = $this->setupBaseRequest($method, $path);
        $request->setGlobal('server', ['HTTP_HOST' => $this->httpHost . ':8080']);
        $request->getUri()->setHost($this->httpHost);
        $request->getUri()->setPort(8080);
        return $request;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = [
            'DBDriver'    => 'SQLite3',
            'database'    => ':memory:',
            'DBPrefix'    => 'sig_',
            'DBDebug'     => true,
            'foreignKeys' => true,
            'busyTimeout' => 1000,
        ];
        $this->db = Database::connect();
        $this->assertSame(':memory:', $this->db->database);

        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, first_name TEXT, middle_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, mis_synced_at TEXT, mis_payload_hash TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INTEGER, tenant_id INTEGER, department TEXT, position TEXT, employment_status TEXT, is_teaching INTEGER, created_at TEXT, updated_at TEXT',
            'student_profiles' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INTEGER, tenant_id INTEGER, program TEXT, year_level TEXT, section TEXT, department TEXT, enrollment_type TEXT, is_graduating INTEGER, created_at TEXT, updated_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, password_hash TEXT, last_login_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'audit_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER, occurred_at TEXT',
            'api_tokens' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, auth_version INTEGER, token_hash TEXT, refresh_hash TEXT, family_id TEXT, device_label TEXT, created_ip TEXT, access_expires_at TEXT, refresh_expires_at TEXT, last_used_at TEXT, rotated_at TEXT, revoked_at TEXT',
            'student_intern_placements' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, person_id INTEGER, unit TEXT, intern_role TEXT, supervisor_user_id INTEGER, status TEXT, starts_at TEXT, ends_at TEXT, academic_term TEXT, assignment_reason TEXT, activated_at TEXT, ended_at TEXT, revoked_by_user_id INTEGER, revoke_reason TEXT, created_by_user_id INTEGER, updated_by_user_id INTEGER, version INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT',
            'guidance_practitioner_profiles' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, person_id INTEGER, user_id INTEGER, credential_tier TEXT, license_number TEXT, credential_expiry TEXT, professional_standing TEXT, service_scope_json TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT',
            'guidance_supervision_assignments' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, associate_user_id INTEGER, supervisor_user_id INTEGER, status TEXT, co_sign_required INTEGER, starts_at TEXT, ends_at TEXT, created_at TEXT',
            'guidance_case_transfers' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, case_id INTEGER, to_counsellor_user_id INTEGER, transfer_type TEXT, status TEXT',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, case_number TEXT, category TEXT, status TEXT, opened_at TEXT, deleted_at TEXT',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, status TEXT, deleted_at TEXT',
            'clinic_encounters' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, status TEXT, deleted_at TEXT',
            'queue_entries' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, unit TEXT, person_id INTEGER, queue_date TEXT, status TEXT',
            'appointments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, unit TEXT, person_id INTEGER, status TEXT, version INTEGER, deleted_at TEXT',
            'referrals' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, patient_person_id INTEGER, target_module TEXT, status TEXT, deleted_at TEXT',
            'bmg_units' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_code TEXT, name TEXT, status TEXT, drum_capacity_kg TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT',
            'bmg_batches' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, unit_id INTEGER, batch_code TEXT, total_input_weight_kg TEXT, output_weight_kg TEXT, quality_grade TEXT, status TEXT, notes TEXT, release_notes TEXT, started_at TEXT, completed_at TEXT, created_at TEXT, updated_at TEXT',
        ] as $table => $cols) {
            $this->db->query('CREATE TABLE sig_' . $table . ' (' . $cols . ')');
        }

        $this->db->table('tenants')->insertBatch([
            ['id' => 1, 'is_active' => 1],
            ['id' => 2, 'is_active' => 1],
        ]);

        // Users:
        // 1: superadmin, 2: clinic_admin, 3: guidance_admin, 4: bmg_admin,
        // 5: clinic_staff (supervisor), 6: counsellor (supervisor), 7: facilities_op (supervisor),
        // 8: student A (Clinic candidate), 9: student B (Guidance candidate), 10: student C (BMG candidate),
        // 11: student D (patient/client for self-access contrast), 12: student E (Tenant 2)
        $people = [
            [1, 1, 'employee', 'Super', 'Admin', 'SUP-01'],
            [2, 1, 'employee', 'Clara', 'ClinicAdmin', 'EMP-CA'],
            [3, 1, 'employee', 'Grace', 'GuidanceAdmin', 'EMP-GA'],
            [4, 1, 'employee', 'Ben', 'BmgAdmin', 'EMP-BA'],
            [5, 1, 'employee', 'Nina', 'NurseSup', 'EMP-CS'],
            [6, 1, 'employee', 'carl', 'CounselorSup', 'EMP-GC'],
            [7, 1, 'employee', 'Oscar', 'OpsSup', 'EMP-FO'],
            [8, 1, 'student', 'Alice', 'Reyes', '2026-0001'],
            [9, 1, 'student', 'Bianca', 'Santos', '2026-0002'],
            [10, 1, 'student', 'Marco', 'Cruz', '2026-0003'],
            [11, 1, 'student', 'Daniel', 'Lim', '2026-0004'],
            [12, 2, 'student', 'Foreign', 'Student', '2026-9999'],
            [13, 1, 'employee', 'Other', 'Counselor', 'EMP-GC2'],
        ];
        foreach ($people as [$id, $tenant, $kind, $fn, $ln, $mis]) {
            $this->db->table('people')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'kind' => $kind,
                'first_name' => $fn, 'last_name' => $ln, 'mis_id' => $mis,
            ]);
        }

        $emps = [
            [1, 1, 'Administration'], [2, 1, 'Health Services'], [3, 1, 'Guidance'],
            [4, 1, 'Facilities'], [5, 1, 'Health Services'], [6, 1, 'Guidance'],
            [7, 1, 'Facilities'], [13, 1, 'Guidance'],
        ];
        foreach ($emps as [$pid, $tid, $dept]) {
            $this->db->table('employee_profiles')->insert([
                'person_id' => $pid, 'tenant_id' => $tid, 'department' => $dept, 'employment_status' => 'active',
            ]);
        }

        foreach ([8 => 'BSN', 9 => 'BS Psych', 10 => 'BS Agriculture'] as $pid => $prog) {
            $this->db->table('student_profiles')->insert([
                'person_id' => $pid, 'tenant_id' => 1, 'program' => $prog, 'year_level' => '4', 'department' => 'College',
            ]);
        }

        $users = [
            [1, 1, 1, 'superadmin', 'superadmin'],
            [2, 1, 2, 'clinic.admin', 'clinic_admin'],
            [3, 1, 3, 'guidance.admin', 'guidance_admin'],
            [4, 1, 4, 'bmg.admin', 'bmg_admin'],
            [5, 1, 5, 'clinic.sup', 'clinic_staff'],
            [6, 1, 6, 'guidance.sup', 'counsellor'],
            [7, 1, 7, 'bmg.sup', 'facilities_op'],
            [8, 1, 8, 'student.alice', 'student'],
            [9, 1, 9, 'student.bianca', 'student'],
            [10, 1, 10, 'student.marco', 'student'],
            [11, 1, 11, 'student.daniel', 'student'],
            [12, 2, 12, 'student.foreign', 'student'],
            [13, 1, 13, 'guidance.other', 'counsellor'],
        ];
        foreach ($users as [$id, $tenant, $pid, $uname, $role]) {
            $this->db->table('users')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'person_id' => $pid,
                'username' => $uname, 'role' => $role, 'status' => 'active', 'auth_version' => 1,
            ]);
        }

        (new PermissionsAndRolesSeeder(config('Database')))->run();
        config('App')->baseURL = 'http://localhost:8080/';
        helper(['form', 'url']);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        foreach (['request', 'uri', 'response', 'redirectresponse', 'renderer'] as $name) {
            Services::resetSingle($name);
        }
        unset($_SERVER['HTTP_HOST']);
        $this->db->close();
        parent::tearDown();
    }

    private function identity(int $userId, ?int $version = null): array
    {
        $row = $this->db->table('users')->where('id', $userId)->get()->getRowArray();
        $tenantId = (int) $row['tenant_id'];
        return [
            'tenant_id' => $tenantId,
            'user' => [
                'id'          => $userId,
                'tenant_id'   => $tenantId,
                'logged_in'   => true,
                'role'        => $row['role'],
                'permissions' => [],
            ],
            '_auth' => [
                'started_at'   => time(),
                'last_seen_at' => time(),
                'version'      => $version ?? (int) $row['auth_version'],
                'session_id'   => session_id(),
            ],
        ];
    }

    private function webBoundary(int $userId, string $method, string $path, ?int $version = null)
    {
        Services::resetSingle('response');
        session()->set($this->identity($userId, $version));
        $request = service('request');
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        $request->getUri()->setPath('/' . ltrim($path, '/'));
        return (new \App\Filters\WebAccessFilter())->before($request);
    }

    private function requestAs(int $userId, string $method, string $path, array $post = [], ?int $version = null, bool $json = true)
    {
        Services::resetSingle('renderer');
        Services::resetSingle('response');
        Services::resetSingle('redirectresponse');
        if ($method === 'POST') {
            $hash = service('security')->generateHash();
            service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
            Services::resetSingle('security');
            $post[config('Security')->tokenName] = $hash;
        }
        $headers = $json ? ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'] : [];
        return $this->withSession($this->identity($userId, $version))
            ->withHeaders($headers)
            ->call($method, $path, $post);
    }

    public function testClinicInternLifecycleSelfAccessBlockAndImmediateRevocation(): void
    {
        $now = SchedulingClock::now();
        $this->db->table('clinic_encounters')->insertBatch([
            ['id' => 101, 'tenant_id' => 1, 'person_id' => 8, 'status' => 'in_progress'],  // Own encounter
            ['id' => 102, 'tenant_id' => 1, 'person_id' => 11, 'status' => 'in_progress'], // Other student's encounter
        ]);

        // clinic_admin assigns Alice (user 8) as clinic_intern under Nurse Nina (user 5)
        $res = $this->requestAs(2, 'POST', 'admin/interns/store', [
            'user_id'            => 8,
            'unit'               => 'clinic',
            'supervisor_user_id' => 5,
            'starts_at'          => $now->modify('-10 minutes')->format('Y-m-d H:i'),
            'ends_at'            => $now->modify('+30 days')->format('Y-m-d H:i'),
            'academic_term'      => 'AY 2026-2027 1st Sem',
            'reason'             => 'BSN 4th-year clinic duty placement',
        ]);
        $res->assertStatus(200);
        $alice = $this->db->table('users')->where('id', 8)->get()->getRowArray();
        $this->assertSame('clinic_intern', $alice['role']);
        $this->assertSame(2, (int) $alice['auth_version']);
        $this->assertSame('clinic_intern', $alice['role']);
        $this->assertTrue((new AuditService())->verifyChain(1)['valid']);

        // Alice can pass the access boundary for another patient's chart & her own Personal Portal,
        // but CANNOT access her own student record via clinic workstation routes (403).
        $this->requestAs(8, 'GET', 'clinic/patients/8')->assertStatus(403);
        $this->requestAs(8, 'GET', 'clinic/encounters/101')->assertStatus(403);
        $this->assertNull($this->webBoundary(8, 'GET', 'portal'));
        $this->assertNull($this->webBoundary(8, 'GET', 'clinic/patients/11'));
        $this->assertNull($this->webBoundary(8, 'GET', 'clinic/encounters/102'));
        $this->assertSame(403, $this->webBoundary(8, 'POST', 'clinic/encounters/102/complete')->getStatusCode());
        $this->assertSame(403, $this->webBoundary(8, 'POST', 'clinic/inventory/store')->getStatusCode());

        // clinic_admin revokes placement -> role reverts to student, auth_version=3, session version 2 fails closed (401)
        $placement = $this->db->table('student_intern_placements')->where('user_id', 8)->get()->getRowArray();
        $revokeRes = $this->requestAs(2, 'POST', 'admin/interns/' . $placement['id'] . '/revoke', [
            'version' => (string) $placement['version'],
            'reason'  => 'Rotation completed early',
        ]);
        $revokeRes->assertStatus(200);

        $aliceAfter = $this->db->table('users')->where('id', 8)->get()->getRowArray();
        $this->assertSame('student', $aliceAfter['role']);
        $this->assertSame(3, (int) $aliceAfter['auth_version']);
        $this->assertTrue((new AuditService())->verifyChain(1)['valid']);

        // Old session with version=2 is terminated (401)
        $this->requestAs(8, 'GET', 'clinic/patients/11', [], 2)->assertStatus(401);
        // Fresh session with version=3 has student role (403 on clinic routes)
        $this->requestAs(8, 'GET', 'clinic/patients/11', [], 3)->assertStatus(403);
    }

    public function testGuidanceInternSupervisionProvisioningCaseScopeSelfBlockAndAutoExpiry(): void
    {
        $now = SchedulingClock::now();
        $this->db->table('counselling_cases')->insertBatch([
            ['id' => 201, 'tenant_id' => 1, 'person_id' => 9, 'counsellor_user_id' => 6, 'case_number' => 'GC-201', 'category' => 'academic', 'status' => 'open'],  // Bianca's own case!
            ['id' => 202, 'tenant_id' => 1, 'person_id' => 11, 'counsellor_user_id' => 6, 'case_number' => 'GC-202', 'category' => 'academic', 'status' => 'open'], // Supervisor's case for another student
            ['id' => 203, 'tenant_id' => 1, 'person_id' => 8, 'counsellor_user_id' => 13, 'case_number' => 'GC-203', 'category' => 'career', 'status' => 'open'],   // Unrelated counselor's case
        ]);

        // guidance_admin assigns Bianca (user 9) under Counselor Carl (user 6)
        $this->requestAs(3, 'POST', 'admin/interns/store', [
            'user_id'            => 9,
            'unit'               => 'guidance',
            'supervisor_user_id' => 6,
            'starts_at'          => $now->modify('-1 hour')->format('Y-m-d H:i'),
            'ends_at'            => $now->modify('+7 days')->format('Y-m-d H:i'),
            'reason'             => 'BS Psychology guidance internship',
        ])->assertStatus(200);

        $bianca = $this->db->table('users')->where('id', 9)->get()->getRowArray();
        $this->assertSame('guidance_intern', $bianca['role']);

        // Counselor associate practitioner profile & mandatory co-sign supervision auto-created
        $profile = $this->db->table('guidance_practitioner_profiles')->where('tenant_id', 1)->where('user_id', 9)->get()->getRowArray();
        $this->assertNotNull($profile);
        $this->assertSame('counselor_associate', $profile['credential_tier']);
        $this->assertSame(1, (int) $profile['is_active']);

        $sup = $this->db->table('guidance_supervision_assignments')->where('tenant_id', 1)->where('associate_user_id', 9)->where('status', 'active')->get()->getRowArray();
        $this->assertNotNull($sup);
        $this->assertSame(6, (int) $sup['supervisor_user_id']);
        $this->assertSame(1, (int) $sup['co_sign_required']);

        $this->assertFalse((new GuidancePractitionerService(db: $this->db))->canFinalizeDirectly(9, 1));
        $this->assertFalse((new GuidanceAccessPolicy(db: $this->db))->canFinalizeDirectly(9, 1));

        // Intern cannot view her own case (201), cumulative record (clients/9), or unrelated counselor's case (203),
        // but CAN read her supervisor's assigned case for student 11 (202)
        $this->requestAs(9, 'GET', 'guidance/clients/9')->assertStatus(403);
        $this->requestAs(9, 'GET', 'guidance/cases/201')->assertStatus(403);
        $this->requestAs(9, 'GET', 'guidance/cases/203')->assertStatus(403);

        session()->set($this->identity(9));
        $policyActor = (new GuidanceAccessPolicy(db: $this->db))->authorize(['id' => 9, 'tenant_id' => 1]);
        $case202 = $this->db->table('counselling_cases')->where('id', 202)->get()->getRowArray();
        $this->assertSame('guidance_intern', (new GuidanceAccessPolicy(db: $this->db))->readCase($case202, $policyActor)['role']);

        // Simulate placement expiration (ends_at moves into the past):
        // Next AuthenticatedUser::forIdentity() auto-expires placement, reverts role to student,
        // deactivates counselor_associate profile, closes supervision, bumps auth_version!
        $this->db->table('student_intern_placements')->where('user_id', 9)->update([
            'ends_at' => $now->modify('-1 minute')->format('Y-m-d H:i:s'),
        ]);

        $refreshed = (new AuthenticatedUser())->forIdentity(9, 1);
        $this->assertSame('student', $refreshed['role']);
        $this->assertSame(3, (int) $refreshed['auth_version']);
        $this->assertNull($refreshed['intern_placement']);

        $expiredPlacement = $this->db->table('student_intern_placements')->where('user_id', 9)->get()->getRowArray();
        $this->assertSame('expired', $expiredPlacement['status']);

        $profileAfter = $this->db->table('guidance_practitioner_profiles')->where('tenant_id', 1)->where('user_id', 9)->get()->getRowArray();
        $this->assertSame(0, (int) $profileAfter['is_active']);
        $this->assertSame(0, $this->db->table('guidance_supervision_assignments')->where('associate_user_id', 9)->where('status', 'active')->countAllResults());
    }

    public function testBmgInternBatchBoundariesAndScheduledFutureActivation(): void
    {
        $now = SchedulingClock::now();
        $this->db->table('bmg_units')->insert([
            'id' => 1, 'tenant_id' => 1, 'unit_code' => 'BMG-01', 'name' => 'Drum 1',
            'status' => 'active', 'drum_capacity_kg' => '100.00',
        ]);

        // Schedule Marco (user 10) for BMG starting tomorrow -> remains student until starts_at
        $this->requestAs(4, 'POST', 'admin/interns/store', [
            'user_id'            => 10,
            'unit'               => 'bmg',
            'supervisor_user_id' => 7,
            'starts_at'          => $now->modify('+1 day')->format('Y-m-d H:i'),
            'ends_at'            => $now->modify('+14 days')->format('Y-m-d H:i'),
            'reason'             => 'Facilities rotation cohort 2',
        ])->assertStatus(200);

        $marco = $this->db->table('users')->where('id', 10)->get()->getRowArray();
        $this->assertSame('student', $marco['role']);
        $placement = $this->db->table('student_intern_placements')->where('user_id', 10)->get()->getRowArray();
        $this->assertSame('scheduled', $placement['status']);

        // Advance starts_at into the past -> AuthenticatedUser auto-activates!
        $this->db->table('student_intern_placements')->where('id', $placement['id'])->update([
            'starts_at' => $now->modify('-5 minutes')->format('Y-m-d H:i:s'),
        ]);
        $activeIdentity = (new AuthenticatedUser())->forIdentity(10, 1);
        $this->assertSame('bmg_intern', $activeIdentity['role']);
        $this->assertNotNull($activeIdentity['intern_placement']);

        // BMG intern can start & release a batch, but CANNOT void/cancel batches, configure units, or access USB
        session()->set($this->identity(10));
        $bmgService = new BmgService($this->db);
        $batch = $bmgService->createBatch(1, '25.00', 'Intern supervised intake load');
        $this->assertSame('processing', $batch['status']);

        $this->requestAs(10, 'POST', 'facilities/batch/' . $batch['id'] . '/cancel', ['reason' => 'Attempted void'])->assertStatus(403);
        $this->requestAs(10, 'POST', 'facilities/units/store', ['unit_code' => 'BMG-99', 'name' => 'Evil', 'drum_capacity_kg' => '50'])->assertStatus(403);
        $this->requestAs(10, 'GET', 'facilities/unit/1/usb')->assertStatus(403);
        $released = $bmgService->releaseBatch((int) $batch['id'], '10.00', 'unclassified', 'Verified by supervisor');
        $this->assertSame('released', $released['status']);
    }

    public function testUnitCrossIsolationTenantBoundaryUiHygieneAndMisWorkstationLogin(): void
    {
        $now = SchedulingClock::now();

        // Cross-unit & unauthorized actors denied
        $this->requestAs(2, 'POST', 'admin/interns/store', [
            'user_id' => 9, 'unit' => 'guidance', 'supervisor_user_id' => 6,
            'starts_at' => $now->format('Y-m-d H:i'), 'ends_at' => $now->modify('+5 days')->format('Y-m-d H:i'),
            'reason' => 'Clinic admin crossing into Guidance',
        ])->assertStatus(403);
        $this->requestAs(5, 'GET', 'admin/interns')->assertStatus(403);
        $this->requestAs(8, 'GET', 'admin/interns')->assertStatus(403);

        // Superadmin blocked from placing tenant 2 student into tenant 1
        $this->requestAs(1, 'POST', 'admin/interns/store', [
            'user_id' => 12, 'unit' => 'clinic', 'supervisor_user_id' => 5,
            'starts_at' => $now->format('Y-m-d H:i'), 'ends_at' => $now->modify('+5 days')->format('Y-m-d H:i'),
            'reason' => 'Cross-tenant test',
        ])->assertStatus(404);

        // Superadmin assigns Alice (user 8) to Clinic -> render UI pages & check CSP / named inputs hygiene
        $this->requestAs(1, 'POST', 'admin/interns/store', [
            'user_id' => 8, 'unit' => 'clinic', 'supervisor_user_id' => 5,
            'starts_at' => $now->modify('-1 hour')->format('Y-m-d H:i'),
            'ends_at' => $now->modify('+10 days')->format('Y-m-d H:i'),
            'reason' => 'Clinic workstation login verification',
        ])->assertStatus(200);
        $placement = $this->db->table('student_intern_placements')->where('user_id', 8)->get()->getRowArray();

        foreach ([
            'admin/interns',
            'admin/interns/create?fragment=1',
            'admin/interns/' . $placement['id'] . '/edit?fragment=1',
        ] as $path) {
            $resp = $this->requestAs(1, 'GET', $path, [], null, false);
            $resp->assertStatus(200);
            $html = $resp->getBody();
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, 'Inline script prohibited on ' . $path);
            $this->assertStringNotContainsString(' style="', $html, 'Inline style prohibited on ' . $path);
        }

        // Active clinic_intern with password_hash = null CAN authenticate via MIS studentLogin on clinic.localhost
        CurrentTenant::set(1);
        session()->set('tenant_id', 1);
        $client = $this->createMock(\App\Integrations\Mis\MisClientInterface::class);
        $client->expects($this->never())->method('employeeLogin');
        $client->expects($this->once())->method('studentLogin')
            ->with('2026-0001', 'student-secret')
            ->willReturn([
                'data' => [
                    'student_id' => '2026-0001',
                    'first_name' => 'Alice',
                    'last_name'  => 'Reyes',
                ],
            ]);
        Services::injectMock('misClient', $client);

        try {
            $this->httpHost = 'clinic.localhost';
            $req = $this->setupRequest('POST', 'auth/login');
            $req->setGlobal('post', ['identifier' => '2026-0001', 'password' => 'student-secret']);
            Services::injectMock('request', $req);
            $auth = new AuthController();
            $auth->initController($req, service('response'), service('logger'));
            $loginResp = $auth->attemptLogin();
            $this->assertSame('http://clinic.localhost:8080/index.php/clinic', $loginResp->getHeaderLine('Location'));
            $this->assertSame('clinic_intern', session('user')['role']);
        } finally {
            Services::resetSingle('misClient');
        }
    }
}

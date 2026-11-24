<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\GuidanceCallSlipController;
use App\Controllers\GuidanceClinicalController;
use App\Controllers\GuidanceEpisodeController;
use App\Controllers\GuidanceGovernanceController;
use App\Controllers\GuidanceIntakeController;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceActivationGateService;
use App\Services\Guidance\GuidanceClinicalFormService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\View\View;
use Config\Database;
use Config\Services;
use ReflectionProperty;

/** Actual read handlers, private SQLite fixtures, no migrations or configured database. */
final class GuidanceRegistryPaginationTest extends CIUnitTestCase
{
    private BaseConnection $registryDb;
    private array $previousConnections;

    protected function setUp(): void
    {
        if (!defined('PAGINATION_ISOLATED_TESTS') || PAGINATION_ISOLATED_TESTS !== true) {
            self::markTestSkipped('Requires the no-dotenv pagination-isolated-bootstrap.php.');
        }
        parent::setUp();
        $this->registryDb = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $instances = new ReflectionProperty(Database::class, 'instances');
        $this->previousConnections = $instances->getValue();
        $instances->setValue(null, ['default' => $this->registryDb, 'tests' => $this->registryDb]);
        CurrentTenant::set(7);
        $this->createSchema();
        $this->seedAuthority();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('renderer');
        Services::resetSingle('request');
        CurrentTenant::reset();
        if (isset($this->registryDb)) {
            (new ReflectionProperty(Database::class, 'instances'))->setValue(null, $this->previousConnections);
            $this->registryDb->close();
        }
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $tables = [
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, middle_name TEXT, last_name TEXT, mis_id TEXT, kind TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'student_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, program TEXT, year_level INTEGER',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_number TEXT, title TEXT',
            'guidance_episodes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, primary_counselor_user_id INTEGER, case_id INTEGER, status TEXT, opened_at TEXT, deleted_at TEXT',
            'guidance_call_slips' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, issuing_counselor_user_id INTEGER, form_instance_id INTEGER, episode_id INTEGER, attendance_status TEXT, call_date TEXT, call_time TEXT, deleted_at TEXT',
            'guidance_form_definitions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, internal_key TEXT, form_code TEXT, canonical_title TEXT',
            'guidance_form_instances' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, form_definition_id INTEGER, created_by_user_id INTEGER, assigned_supervisor_user_id INTEGER, instance_number TEXT, status TEXT, submitted_for_cosign_at TEXT',
            'guidance_practitioner_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, user_id INTEGER, credential_tier TEXT, credential_expiry TEXT, professional_standing TEXT, service_scope_json TEXT, is_active INTEGER',
            'guidance_supervision_assignments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, associate_user_id INTEGER, supervisor_user_id INTEGER, status TEXT, starts_at TEXT, co_sign_required INTEGER',
            'guidance_intake_referrals' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, referral_number TEXT, source_type TEXT, clinic_referral_id INTEGER, subject_person_id INTEGER, referrer_name_snapshot TEXT, referrer_department_snapshot TEXT, priority_hint TEXT, status TEXT, assigned_counsellor_user_id INTEGER, linked_case_id INTEGER, submitted_at TEXT, received_at TEXT, triaged_at TEXT, created_at TEXT',
            'referrals' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, patient_person_id INTEGER, issuer_user_id INTEGER, referral_code TEXT, target_module TEXT, priority TEXT, reason_code TEXT, status TEXT, created_at TEXT',
            'guidance_activation_gates' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, gate_key TEXT, title TEXT, description TEXT, required_signoffs_json TEXT, recorded_signoffs_json TEXT, is_enabled INTEGER, approved_at TEXT, created_at TEXT, updated_at TEXT',
        ];
        foreach ($tables as $table => $columns) {
            $this->registryDb->query('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
    }

    private function seedAuthority(): void
    {
        $this->registryDb->table('tenants')->insertBatch([
            ['id' => 7, 'is_active' => 1], ['id' => 8, 'is_active' => 1],
        ]);
        foreach ([11 => 'counsellor', 12 => 'counsellor', 13 => 'guidance_admin', 14 => 'guidance_supervisor'] as $id => $role) {
            $this->registryDb->table('people')->insert([
                'id' => $id, 'tenant_id' => 7, 'first_name' => 'Synthetic', 'last_name' => 'Staff',
                'kind' => 'employee', 'mis_id' => 'STAFF-' . $id,
            ]);
            $this->registryDb->table('users')->insert([
                'id' => $id, 'tenant_id' => 7, 'person_id' => $id, 'username' => 'staff-' . $id,
                'role' => $role, 'status' => 'active',
            ]);
        }
        $this->registryDb->table('people')->insertBatch([
            ['id' => 101, 'tenant_id' => 7, 'first_name' => 'Synthetic', 'middle_name' => '', 'last_name' => 'Subject', 'mis_id' => 'SUBJECT-101', 'kind' => 'student', 'deleted_at' => null],
            ['id' => 102, 'tenant_id' => 7, 'first_name' => 'Deleted', 'middle_name' => '', 'last_name' => 'Subject', 'mis_id' => 'SUBJECT-102', 'kind' => 'student', 'deleted_at' => '2026-01-01'],
            ['id' => 103, 'tenant_id' => 8, 'first_name' => 'Foreign', 'middle_name' => '', 'last_name' => 'Subject', 'mis_id' => 'SUBJECT-103', 'kind' => 'student', 'deleted_at' => null],
        ]);
        $this->registryDb->table('permissions')->insertBatch([
            ['id' => 1, 'code' => 'guidance.cases.read'],
            ['id' => 2, 'code' => 'guidance.cases.read_any'],
            ['id' => 3, 'code' => 'guidance.intake.process'],
        ]);
        foreach (['counsellor', 'guidance_admin', 'guidance_supervisor'] as $role) {
            $this->registryDb->table('role_permissions')->insertBatch([
                ['role' => $role, 'permission_id' => 1], ['role' => $role, 'permission_id' => 3],
            ]);
        }
        $this->registryDb->table('role_permissions')->insert(['role' => 'guidance_admin', 'permission_id' => 2]);
        // Preseed the read dependency so a queue read cannot create default gate records.
        $this->registryDb->table('guidance_activation_gates')->insert([
            'id' => 1, 'tenant_id' => 7, 'gate_key' => GuidanceActivationGateService::GATE_CANONICAL_FORMS,
            'title' => 'Synthetic gate', 'required_signoffs_json' => '[]', 'recorded_signoffs_json' => '[]', 'is_enabled' => 0,
        ]);
    }

    private function handler(string $class, string $method, array $query = [], int $actorId = 11): array
    {
        $policy = new GuidanceAccessPolicy(static fn (): array => ['id' => $actorId, 'tenant_id' => 7], $this->registryDb);
        $controller = match ($class) {
            GuidanceIntakeController::class => new GuidanceIntakeController(db: $this->registryDb, actorResolver: static fn (): array => $policy->current()),
            GuidanceGovernanceController::class => new GuidanceGovernanceController(db: $this->registryDb, actorResolver: static fn (): array => $policy->current()),
            default => new $class(accessPolicy: $policy),
        };
        $request = new IncomingRequest(config('App'), new SiteURI(config('App'), 'guidance'), null, new UserAgent());
        $request->setGlobal('get', $query);
        Services::injectMock('request', $request);
        $response = new Response(config('App'));
        $renderer = new GuidanceRegistryPaginationCaptureView(config('View'), APPPATH . 'Views', null, false);
        Services::injectMock('renderer', $renderer);
        $controller->initController($request, $response, service('logger'));
        $before = $this->registryDb->query('SELECT total_changes() AS total')->getRowArray()['total'];
        $controller->{$method}();
        self::assertSame($before, $this->registryDb->query('SELECT total_changes() AS total')->getRowArray()['total'], 'Registry reads must not mutate fixture records.');
        return ['data' => $renderer->getData(), 'response' => $response, 'view' => $renderer->lastView];
    }

    private function read(string $class, string $method, array $query = [], int $actorId = 11): array
    {
        $result = $this->handler($class, $method, $query, $actorId);
        self::assertSame(200, $result['response']->getStatusCode(), (string) $result['response']->getBody());
        self::assertStringContainsString('private', $result['response']->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('no-store', $result['response']->getHeaderLine('Cache-Control'));
        self::assertNotSame('', $result['view']);
        return $result['data'];
    }

    private function ids(array $rows): array
    {
        return array_map('intval', array_column($rows, 'id'));
    }

    private function seedCallSlips(): void
    {
        $base = ['tenant_id' => 7, 'person_id' => 101, 'issuing_counselor_user_id' => 11,
            'call_date' => '2026-11-20', 'call_time' => '09:00:00', 'attendance_status' => 'pending'];
        for ($id = 1; $id <= 130; $id++) {
            $this->registryDb->table('guidance_call_slips')->insert(['id' => $id, 'attendance_status' => $id > 125 ? 'attended' : 'pending'] + $base);
        }
        foreach ([
            ['id' => 201, 'deleted_at' => '2026-01-01'], ['id' => 202, 'person_id' => 102],
            ['id' => 203, 'tenant_id' => 8, 'person_id' => 103], ['id' => 204, 'person_id' => 103],
        ] as $excluded) {
            $this->registryDb->table('guidance_call_slips')->insert($excluded + $base);
        }
    }

    public function testCallSlipFullCountsAllSizesAndPrefillAreScopedAndStable(): void
    {
        $this->seedCallSlips();
        foreach ([20, 25, 50, 100] as $size) {
            $data = $this->read(GuidanceCallSlipController::class, 'index', [
                'status' => 'pending', 'page' => '2', 'per_page' => (string) $size, 'person_id' => '101', 'episode_id' => '42',
            ]);
            self::assertSame(130, $data['pagination']['total']);
            self::assertSame(125, $data['pagination']['filtered']);
            self::assertSame($size + 1, $data['pagination']['from']);
            self::assertSame(range(125 - $size, max(1, 126 - 2 * $size)), $this->ids($data['callSlips']));
            self::assertSame(101, (int) $data['prefillPerson']['id']);
            self::assertSame(42, $data['prefillEpisodeId']);
        }
        $last = $this->read(GuidanceCallSlipController::class, 'index', ['status' => 'pending', 'page' => '1000000', 'per_page' => '50']);
        self::assertSame(['page' => 3, 'per_page' => 50, 'total' => 130, 'filtered' => 125, 'pages' => 3, 'from' => 101, 'to' => 125], $last['pagination']);
        self::assertSame(range(25, 1), $this->ids($last['callSlips']));
        $all = $this->read(GuidanceCallSlipController::class, 'index', ['status' => 'invalid']);
        self::assertSame('', $all['status']);
        self::assertSame(25, $all['pagination']['per_page']);
        self::assertSame(range(130, 106), $this->ids($all['callSlips']));
        $foreignPrefill = $this->read(GuidanceCallSlipController::class, 'index', ['person_id' => '103']);
        self::assertNull($foreignPrefill['prefillPerson']);
    }

    public function testCallSlipDateAndTimeOrderPrecedesIdAndEmptyFiltersClamp(): void
    {
        $this->seedCallSlips();
        $this->registryDb->table('guidance_call_slips')->where('id', 1)->update(['call_date' => '2026-11-21']);
        $this->registryDb->table('guidance_call_slips')->where('id', 2)->update(['call_time' => '10:00:00']);
        $data = $this->read(GuidanceCallSlipController::class, 'index', ['status' => 'pending']);
        self::assertSame([1, 2, 125], array_slice($this->ids($data['callSlips']), 0, 3));
        $empty = $this->read(GuidanceCallSlipController::class, 'index', ['status' => 'missed', 'page' => '99']);
        self::assertSame([], $empty['callSlips']);
        self::assertSame(['page' => 1, 'per_page' => 25, 'total' => 130, 'filtered' => 0, 'pages' => 1, 'from' => 0, 'to' => 0], $empty['pagination']);
    }

    private function seedEpisodes(): void
    {
        $base = ['tenant_id' => 7, 'person_id' => 101, 'primary_counselor_user_id' => 11, 'opened_at' => '2026-11-20 09:00:00'];
        $statuses = ['open', 'active', 'closed', 'transferred'];
        for ($id = 1; $id <= 158; $id++) {
            $this->registryDb->table('guidance_episodes')->insert([
                'id' => $id, 'status' => $id <= 125 ? $statuses[($id - 1) % 4] : 'closed',
                'primary_counselor_user_id' => $id <= 125 ? 11 : 12,
            ] + $base);
        }
        foreach ([['id' => 201, 'deleted_at' => '2026-01-01'], ['id' => 202, 'person_id' => 102],
            ['id' => 203, 'tenant_id' => 8, 'person_id' => 103], ['id' => 204, 'person_id' => 103]] as $excluded) {
            $this->registryDb->table('guidance_episodes')->insert($excluded + ['status' => 'open'] + $base);
        }
    }

    public function testEpisodeVisibilityFullStatusCountsAndOversightStayUnchanged(): void
    {
        $this->seedEpisodes();
        $mine = $this->read(GuidanceEpisodeController::class, 'index', ['page' => '2']);
        self::assertSame(['' => 125, 'open' => 32, 'active' => 31, 'closed' => 31, 'transferred' => 31], $mine['statusCounts']);
        self::assertSame(range(100, 76), $this->ids($mine['episodes']));
        $closed = $this->read(GuidanceEpisodeController::class, 'index', ['status' => 'closed', 'page' => '999']);
        self::assertSame(2, $closed['pagination']['page']);
        self::assertSame(125, $closed['pagination']['total']);
        self::assertSame(31, $closed['pagination']['filtered']);
        self::assertSame([23, 19, 15, 11, 7, 3], $this->ids($closed['episodes']));
        foreach ([13, 14] as $oversightId) {
            $all = $this->read(GuidanceEpisodeController::class, 'index', ['page' => '2'], $oversightId);
            self::assertSame(158, $all['statusCounts']['']);
            self::assertSame(64, $all['statusCounts']['closed']);
            self::assertSame(range(133, 109), $this->ids($all['episodes']));
        }
        $this->registryDb->table('role_permissions')->insert(['role' => 'counsellor', 'permission_id' => 2]);
        $permissionOversight = $this->read(GuidanceEpisodeController::class, 'index');
        self::assertSame(158, $permissionOversight['pagination']['total']);
    }

    private function seedIntake(): void
    {
        $base = ['tenant_id' => 7, 'subject_person_id' => 101, 'referral_number' => 'SYNTHETIC', 'source_type' => 'staff_on_behalf',
            'priority_hint' => 'crisis', 'status' => 'submitted', 'created_at' => '2026-11-20 09:00:00'];
        for ($id = 1; $id <= 125; $id++) {
            $this->registryDb->table('guidance_intake_referrals')->insert(['id' => $id] + $base);
        }
        $this->registryDb->table('guidance_intake_referrals')->insert(['id' => 126, 'priority_hint' => 'urgent', 'status' => 'received'] + $base);
        $this->registryDb->table('guidance_intake_referrals')->insert(['id' => 127, 'priority_hint' => 'routine', 'status' => 'received'] + $base);
        $this->registryDb->table('guidance_intake_referrals')->insert(['id' => 201, 'tenant_id' => 8, 'subject_person_id' => 103] + $base);
        $incoming = ['tenant_id' => 7, 'patient_person_id' => 101, 'issuer_user_id' => 11, 'referral_code' => 'SYNTHETIC',
            'target_module' => 'guidance', 'priority' => 'crisis', 'status' => 'submitted', 'created_at' => '2026-11-20 09:00:00'];
        for ($id = 1; $id <= 65; $id++) {
            $this->registryDb->table('referrals')->insert(['id' => $id, 'priority' => $id > 63 ? 'routine' : 'crisis',
                'status' => $id > 63 ? 'acknowledged' : 'submitted'] + $incoming);
        }
        foreach ([['id' => 201, 'tenant_id' => 8], ['id' => 202, 'target_module' => 'clinic'], ['id' => 203, 'status' => 'closed']] as $excluded) {
            $this->registryDb->table('referrals')->insert($excluded + $incoming);
        }
        $this->registryDb->table('guidance_intake_referrals')->where('id', 1)->update(['clinic_referral_id' => 1]);
    }

    public function testIntakeAndIncomingHaveIndependentCursorsCountsAndPriorityTies(): void
    {
        $this->seedIntake();
        $data = $this->read(GuidanceIntakeController::class, 'index', [
            'status' => 'submitted', 'priority_hint' => 'crisis', 'intake_page' => '2', 'incoming_page' => '999', 'per_page' => '20',
        ]);
        self::assertSame(['page' => 2, 'per_page' => 20, 'total' => 127, 'filtered' => 125, 'pages' => 7, 'from' => 21, 'to' => 40], $data['intakePagination']);
        self::assertSame(range(105, 86), $this->ids($data['referrals']));
        self::assertSame(['page' => 4, 'per_page' => 20, 'total' => 65, 'filtered' => 65, 'pages' => 4, 'from' => 61, 'to' => 65], $data['incomingPagination']);
        self::assertSame([3, 2, 1, 65, 64], $this->ids($data['clinicReferrals']));
        self::assertSame(1, (int) $data['clinicReferrals'][2]['bridged_intake_id']);
        $empty = $this->read(GuidanceIntakeController::class, 'index', ['status' => 'closed', 'intake_page' => '999', 'incoming_page' => '2']);
        self::assertSame([], $empty['referrals']);
        self::assertSame(1, $empty['intakePagination']['page']);
        self::assertSame(65, $empty['incomingPagination']['total']);
        self::assertSame(2, $empty['incomingPagination']['page']);
        self::assertSame(range(38, 14), $this->ids($empty['clinicReferrals']));
    }

    private function seedPractitioners(): void
    {
        for ($id = 1; $id <= 65; $id++) {
            $this->registryDb->table('users')->insert([
                'id' => 1000 + $id, 'tenant_id' => 7, 'person_id' => 101, 'username' => 'tied-name', 'role' => 'counsellor', 'status' => 'active',
            ]);
            $this->registryDb->table('guidance_practitioner_profiles')->insert([
                'id' => $id, 'tenant_id' => 7, 'person_id' => 101, 'user_id' => 1000 + $id,
                'credential_tier' => 'licensed_counselor', 'professional_standing' => 'active', 'service_scope_json' => '["intake"]', 'is_active' => 1,
            ]);
        }
        $this->registryDb->table('guidance_practitioner_profiles')->insert([
            'id' => 69, 'tenant_id' => 7, 'user_id' => 13, 'credential_tier' => 'licensed_counselor', 'is_active' => 0,
        ]);
        $this->registryDb->table('users')->insertBatch([
            ['id' => 1070, 'tenant_id' => 7, 'person_id' => 101, 'username' => 'deleted', 'role' => 'counsellor', 'status' => 'active', 'deleted_at' => '2026-01-01'],
            ['id' => 2001, 'tenant_id' => 8, 'person_id' => 103, 'username' => 'foreign', 'role' => 'counsellor', 'status' => 'active', 'deleted_at' => null],
        ]);
        foreach ([['id' => 66, 'tenant_id' => 7, 'user_id' => 1070], ['id' => 67, 'tenant_id' => 8, 'user_id' => 2001],
            ['id' => 68, 'tenant_id' => 7, 'user_id' => 2001]] as $excluded) {
            $this->registryDb->table('guidance_practitioner_profiles')->insert($excluded + ['credential_tier' => 'licensed_counselor', 'is_active' => 1]);
        }
        $base = ['tenant_id' => 7, 'associate_user_id' => 11, 'supervisor_user_id' => 12, 'status' => 'active', 'starts_at' => '2026-11-20 09:00:00'];
        for ($id = 1; $id <= 63; $id++) {
            $this->registryDb->table('guidance_supervision_assignments')->insert(['id' => $id] + $base);
        }
        foreach ([['id' => 64, 'status' => 'closed'], ['id' => 65, 'tenant_id' => 8],
            ['id' => 66, 'associate_user_id' => 999], ['id' => 67, 'supervisor_user_id' => 999]] as $excluded) {
            $this->registryDb->table('guidance_supervision_assignments')->insert($excluded + $base);
        }
    }

    public function testPractitionerPagesRemainIndependentAndSelectorsRemainComplete(): void
    {
        $this->seedPractitioners();
        $data = $this->read(GuidanceGovernanceController::class, 'practitioners', ['profiles_page' => '2', 'supervision_page' => '999']);
        self::assertSame(66, $data['profilesPagination']['total']);
        self::assertSame(range(26, 50), $this->ids($data['profiles']));
        self::assertSame(['intake'], $data['profiles'][0]['service_scope']);
        self::assertSame(63, $data['supervisionPagination']['total']);
        self::assertSame(3, $data['supervisionPagination']['page']);
        self::assertSame(range(13, 1), $this->ids($data['supervisions']));
        self::assertCount(69, $data['guidanceUsers']);
        self::assertContains(1065, $this->ids($data['guidanceUsers']));
        self::assertNotContains(1070, $this->ids($data['guidanceUsers']));
        self::assertNotContains(2001, $this->ids($data['guidanceUsers']));
        $last = $this->read(GuidanceGovernanceController::class, 'practitioners', ['profiles_page' => '999', 'supervision_page' => '1', 'per_page' => '20']);
        self::assertSame([61, 62, 63, 64, 65, 69], $this->ids($last['profiles']));
        self::assertSame(range(63, 44), $this->ids($last['supervisions']));
        $episodes = $this->read(GuidanceEpisodeController::class, 'index');
        self::assertCount(65, $episodes['qualifiedCounselors'], 'Pagination must not truncate qualified counselor options.');
    }

    private function seedCosign(): void
    {
        $this->registryDb->table('guidance_form_definitions')->insertBatch([
            ['id' => 1, 'tenant_id' => 7, 'internal_key' => GuidanceClinicalFormService::FORM_COUNSELING_CONTACT],
            ['id' => 2, 'tenant_id' => 7, 'internal_key' => 'unrelated_form'],
            ['id' => 3, 'tenant_id' => 8, 'internal_key' => GuidanceClinicalFormService::FORM_COUNSELING_CONTACT],
        ]);
        $base = ['tenant_id' => 7, 'person_id' => 101, 'form_definition_id' => 1, 'created_by_user_id' => 12,
            'status' => 'pending_cosign', 'submitted_for_cosign_at' => '2026-11-20 09:00:00'];
        for ($id = 1; $id <= 162; $id++) {
            $supervisor = $id <= 125 ? 11 : ($id <= 155 ? 12 : 13);
            $this->registryDb->table('guidance_form_instances')->insert(['id' => $id, 'assigned_supervisor_user_id' => $supervisor] + $base);
        }
        foreach ([['id' => 201, 'status' => 'draft'], ['id' => 202, 'form_definition_id' => 2],
            ['id' => 203, 'tenant_id' => 8, 'form_definition_id' => 3], ['id' => 204, 'form_definition_id' => 3]] as $excluded) {
            $this->registryDb->table('guidance_form_instances')->insert($excluded + ['assigned_supervisor_user_id' => 11] + $base);
        }
    }

    public function testCosignOrdinaryAssignedOnlyAndOversightCountsUseFullScope(): void
    {
        $this->seedCosign();
        $mine = $this->read(GuidanceClinicalController::class, 'cosignQueue', ['page' => '2']);
        self::assertFalse($mine['hasOversight']);
        self::assertSame(125, $mine['pendingCount']);
        self::assertSame(125, $mine['assignedCount']);
        self::assertSame(range(100, 76), $this->ids($mine['pendingForms']));
        $all = $this->read(GuidanceClinicalController::class, 'cosignQueue', ['page' => '999', 'per_page' => '100'], 13);
        self::assertTrue($all['hasOversight']);
        self::assertSame(162, $all['pendingCount']);
        self::assertSame(7, $all['assignedCount']);
        self::assertSame(2, $all['pagination']['page']);
        self::assertSame(range(62, 1), $this->ids($all['pendingForms']));
        $roleOversight = $this->read(GuidanceClinicalController::class, 'cosignQueue', [], 14);
        self::assertSame(162, $roleOversight['pagination']['total']);
        self::assertSame(0, $roleOversight['assignedCount']);
    }

    public function testAllReadHandlersRejectMalformedPaginationAs422(): void
    {
        $targets = [
            [GuidanceCallSlipController::class, 'index', 'page'],
            [GuidanceEpisodeController::class, 'index', 'page'],
            [GuidanceIntakeController::class, 'index', 'intake_page'],
            [GuidanceIntakeController::class, 'index', 'incoming_page'],
            [GuidanceGovernanceController::class, 'practitioners', 'profiles_page'],
            [GuidanceGovernanceController::class, 'practitioners', 'supervision_page'],
            [GuidanceClinicalController::class, 'cosignQueue', 'page'],
        ];
        foreach ($targets as [$class, $method, $pageParam]) {
            foreach ([[$pageParam => ['2']], [$pageParam => '0'], [$pageParam => '1000001'], ['per_page' => '10'], ['per_page' => ['25']]] as $query) {
                $result = $this->handler($class, $method, $query);
                self::assertSame(422, $result['response']->getStatusCode(), $class . ' ' . json_encode($query));
                self::assertSame([], $result['data']);
                self::assertStringContainsString('private', $result['response']->getHeaderLine('Cache-Control'));
                self::assertStringContainsString('no-store', $result['response']->getHeaderLine('Cache-Control'));
            }
        }
    }

    public function testEmptyListsClampWithoutSuppressingMetadata(): void
    {
        foreach ([
            [GuidanceCallSlipController::class, 'index', 'callSlips', 'pagination', ['page' => '999']],
            [GuidanceEpisodeController::class, 'index', 'episodes', 'pagination', ['page' => '999']],
            [GuidanceClinicalController::class, 'cosignQueue', 'pendingForms', 'pagination', ['page' => '999']],
            [GuidanceIntakeController::class, 'index', 'referrals', 'intakePagination', ['intake_page' => '999', 'incoming_page' => '999']],
            [GuidanceGovernanceController::class, 'practitioners', 'profiles', 'profilesPagination', ['profiles_page' => '999', 'supervision_page' => '999']],
        ] as [$class, $method, $rows, $metadata, $query]) {
            $data = $this->read($class, $method, $query);
            self::assertSame([], $data[$rows]);
            self::assertSame(['page' => 1, 'per_page' => 25, 'total' => 0, 'filtered' => 0, 'pages' => 1, 'from' => 0, 'to' => 0], $data[$metadata]);
            foreach (['incomingPagination', 'supervisionPagination'] as $otherMetadata) {
                if (isset($data[$otherMetadata])) {
                    self::assertSame(1, $data[$otherMetadata]['page']);
                    self::assertSame(0, $data[$otherMetadata]['total']);
                }
            }
        }
    }
}

/** Capture only controller output data; avoid the shared layout and its unrelated read dependencies. */
final class GuidanceRegistryPaginationCaptureView extends View
{
    public string $lastView = '';

    public function render(string $view, ?array $options = null, ?bool $saveData = null): string
    {
        $this->lastView = $view;
        return '';
    }
}

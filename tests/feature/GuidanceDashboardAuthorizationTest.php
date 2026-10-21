<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\GuidanceController;
use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\AuthenticatedUser;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\View\View;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GuidanceDashboardAuthorizationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = ['DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => 'gd_', 'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000];
        $this->db = Database::connect();
        $this->assertSame(':memory:', $this->db->database);
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT',
            'employee_profiles' => 'person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, case_number TEXT, category TEXT, status TEXT, opened_at TEXT, deleted_at TEXT',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, status TEXT, started_at TEXT, deleted_at TEXT',
            'queue_entries' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, unit TEXT, queue_date TEXT, status TEXT',
            'referrals' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, target_module TEXT, status TEXT',
        ] as $table => $columns) {
            $this->db->query('CREATE TABLE gd_' . $table . ' (' . $columns . ')');
        }
        $this->db->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        foreach ([1 => 'counsellor', 2 => 'counsellor', 3 => 'guidance_supervisor', 4 => 'superadmin'] as $id => $role) {
            $this->person($id, 'Staff' . $id);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => 1, 'person_id' => $id,
                'username' => 'staff' . $id, 'role' => $role, 'status' => 'active']);
        }
        (new PermissionsAndRolesSeeder(config('Database')))->run();
        foreach ([11 => 'ClientAlpha', 12 => 'ClientBeta', 13 => 'ClientGamma', 14 => 'DeletedClient', 15 => 'ForeignClient'] as $id => $name) {
            $this->person($id, $name, $id === 15 ? 2 : 1, $id === 14);
        }
        $this->caseRow(1, 11, 1, 'academic', 'active');
        $this->caseRow(2, 11, 1, 'personal', 'closed');
        $this->caseRow(3, 12, 2, 'crisis', 'open');
        $this->caseRow(4, 13, 3, 'career', 'monitoring');
        $this->sessionRow(1, 1);
        $this->sessionRow(2, 2);
        $this->sessionRow(3, 3);
        $this->sessionRow(4, 4);
        $this->sessionRow(5, 1, ['status' => 'in_progress']);
        // Every excluded row uses a category otherwise absent from the dashboard.
        $this->caseRow(5, 11, 1, 'family', 'active', ['deleted_at' => '2026-01-01']);
        $this->caseRow(6, 14, 1, 'family', 'closed');
        $this->caseRow(7, 15, 1, 'family', 'active', ['tenant_id' => 2]);
        $this->caseRow(8, 15, 1, 'family', 'closed');
        foreach ([5, 6, 7, 8] as $id) $this->sessionRow($id + 10, $id);
        $this->sessionRow(20, 1, ['deleted_at' => '2026-01-01']);
        $this->sessionRow(21, 1, ['tenant_id' => 2]);
        $this->sessionRow(22, 1, ['status' => 'in_progress', 'started_at' => date('Y-m-d H:i:s', strtotime('-8 days'))]);
        $this->db->table('queue_entries')->insertBatch([
            ['tenant_id' => 1, 'unit' => 'guidance', 'queue_date' => SchedulingClock::today(), 'status' => 'waiting'],
            ['tenant_id' => 1, 'unit' => 'guidance', 'queue_date' => SchedulingClock::today(), 'status' => 'called'],
            ['tenant_id' => 2, 'unit' => 'guidance', 'queue_date' => SchedulingClock::today(), 'status' => 'waiting'],
        ]);
        $this->db->table('referrals')->insertBatch([
            ['tenant_id' => 1, 'target_module' => 'guidance', 'status' => 'submitted'],
            ['tenant_id' => 2, 'target_module' => 'guidance', 'status' => 'submitted'],
        ]);
        helper(['form', 'url']);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
        $this->db->close();
        parent::tearDown();
    }

    private function person(int $id, string $name, int $tenant = 1, bool $deleted = false): void
    {
        $this->db->table('people')->insert(['id' => $id, 'tenant_id' => $tenant,
            'first_name' => $name, 'last_name' => 'Private', 'mis_id' => 'MIS-SECRET-' . $id,
            'deleted_at' => $deleted ? '2026-01-01' : null]);
    }

    private function caseRow(int $id, int $person, int $owner, string $category, string $status, array $extra = []): void
    {
        $this->db->table('counselling_cases')->insert($extra + ['id' => $id, 'tenant_id' => 1,
            'person_id' => $person, 'counsellor_user_id' => $owner, 'case_number' => 'CASE-PRIVATE-' . $id,
            'category' => $category, 'status' => $status, 'opened_at' => date('Y-m-d H:i:s')]);
    }

    private function sessionRow(int $id, int $case, array $extra = []): void
    {
        $this->db->table('counselling_sessions')->insert($extra + ['id' => $id, 'tenant_id' => 1,
            'case_id' => $case, 'status' => 'completed', 'started_at' => date('Y-m-d H:i:s')]);
    }

    private function identity(int $id): array
    {
        // Cached ownership/oversight claims must never authorize another counselor's data.
        return ['tenant_id' => 1, 'user' => ['id' => $id, 'tenant_id' => 1, 'logged_in' => true,
            'role' => 'superadmin', 'permissions' => ['guidance.cases.read_any']],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]];
    }

    private function dashboard(int $id, string $path = 'guidance'): array
    {
        Services::resetSingle('renderer');
        Services::resetSingle('response');
        Services::resetSingle('redirectresponse');
        $renderer = new class(config('View'), APPPATH . 'Views/') extends View {
            public array $dashboardData = [];

            public function render(string $view, ?array $options = null, ?bool $saveData = null): string
            {
                if ($view === 'guidance/dashboard') $this->dashboardData = $this->tempData ?? $this->data;
                return parent::render($view, $options, $saveData);
            }
        };
        Services::injectMock('renderer', $renderer);
        $result = $this->withSession($this->identity($id))->withHeaders(['Accept' => 'application/json'])->get($path);
        $result->assertStatus(200);
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        return [$renderer->dashboardData, $result->getBody()];
    }

    private function assertMetrics(array $data, int $active, int $closed, int $completed, int $daily, array $caseIds, array $categories): void
    {
        $this->assertSame($active, $data['activeCases']);
        $this->assertSame($closed, $data['closedCases']);
        $this->assertSame($completed, $data['completedSessions']);
        $this->assertSame(7, count($data['chartSeries']));
        $this->assertSame($daily, array_sum($data['chartSeries']));
        $expectedDays = [];
        for ($i = 6; $i >= 0; $i--) {
            $expectedDays[] = date('Y-m-d', strtotime("-{$i} days")) === date('Y-m-d') ? $daily : 0;
        }
        $this->assertSame($expectedDays, $data['chartSeries']);
        $ids = array_map('intval', array_column($data['recentCases'], 'id'));
        sort($ids);
        $this->assertSame($caseIds, $ids);
        $this->assertEquals($categories + ['academic' => 0, 'personal' => 0, 'career' => 0,
            'crisis' => 0, 'behavioral' => 0, 'family' => 0], $data['categoryCounts']);
        $this->assertSame(0, $data['categoryCounts']['family']);
        $this->assertSame(2, $data['queueToday']);
        $this->assertSame(1, $data['pendingReferrals']);
    }

    public function testBothCounselorsSeeOnlyTheirOwnMetricsAndRecentClientsOnBothAliases(): void
    {
        foreach (['guidance', 'guidance/dashboard'] as $path) {
            [$alpha, $html] = $this->dashboard(1, $path);
            $this->assertMetrics($alpha, 1, 1, 2, 3, [1, 2], ['academic' => 1, 'personal' => 1]);
            $this->assertStringContainsString('ClientAlpha', $html);
            foreach (['ClientBeta', 'ClientGamma', 'MIS-SECRET-12', 'MIS-SECRET-13', 'CASE-PRIVATE-3', 'CASE-PRIVATE-4'] as $secret) {
                $this->assertStringNotContainsString($secret, $html);
            }
            [$beta, $html] = $this->dashboard(2, $path);
            $this->assertMetrics($beta, 1, 0, 1, 1, [3], ['crisis' => 1]);
            $this->assertStringContainsString('ClientBeta', $html);
            foreach (['ClientAlpha', 'ClientGamma', 'MIS-SECRET-11', 'MIS-SECRET-13', 'CASE-PRIVATE-1', 'CASE-PRIVATE-2'] as $secret) {
                $this->assertStringNotContainsString($secret, $html);
            }
        }
    }

    public function testSupervisorAndSuperadminRetainTenantWideLiveCaseOversight(): void
    {
        foreach ([3, 4] as $id) {
            [$data, $html] = $this->dashboard($id);
            $this->assertMetrics($data, 3, 1, 4, 5, [1, 2, 3, 4],
                ['academic' => 1, 'personal' => 1, 'career' => 1, 'crisis' => 1]);
            foreach (['ClientAlpha', 'ClientBeta', 'ClientGamma'] as $name) $this->assertStringContainsString($name, $html);
            foreach (['DeletedClient', 'ForeignClient', 'CASE-PRIVATE-5', 'CASE-PRIVATE-6', 'CASE-PRIVATE-7', 'CASE-PRIVATE-8'] as $secret) {
                $this->assertStringNotContainsString($secret, $html);
            }
        }
    }

    public function testRevokingSupervisorOversightImmediatelyScopesDashboardToAssignedCases(): void
    {
        $permission = $this->db->table('permissions')->where('code', 'guidance.cases.read_any')->get()->getRowArray();
        $this->db->table('role_permissions')->where('role', 'guidance_supervisor')->where('permission_id', $permission['id'])->delete();
        [$data, $html] = $this->dashboard(3);
        $this->assertMetrics($data, 1, 0, 1, 1, [4], ['career' => 1]);
        $this->assertStringNotContainsString('ClientAlpha', $html);
        $this->assertStringNotContainsString('ClientBeta', $html);
    }

    public function testDirectControllerRefreshesForgedOversightBeforeQuerying(): void
    {
        session()->set($this->identity(1));
        Services::resetSingle('renderer');
        $controller = new GuidanceController();
        $controller->initController(service('request'), service('response'), service('logger'));
        $html = $controller->dashboard();
        $this->assertStringContainsString('ClientAlpha', $html);
        $this->assertStringNotContainsString('ClientBeta', $html);
        $this->assertStringNotContainsString('ClientGamma', $html);
        $this->assertSame('counsellor', session('user')['role']);
    }

    public function testDirectControllerRejectsMissingAuthenticationBeforeDashboardQueries(): void
    {
        AuthenticatedUser::clear();
        $request = service('request');
        $request->setHeader('Accept', 'application/json');
        $controller = new GuidanceController();
        $controller->initController($request, service('response'), service('logger'));
        $lastQuery = $this->db->getLastQuery();
        $response = $controller->dashboard();
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame($lastQuery, $this->db->getLastQuery());
    }
}

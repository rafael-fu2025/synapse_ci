<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\DashboardController;
use App\Services\AuthenticatedUser;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionMethod;

/** DB-free: mock builders for audit queries; isolate the template from its shared layout. */
final class DashboardAuditAccessTest extends CIUnitTestCase
{
    private function summary(BaseConnection $db, array $user): array
    {
        return (new ReflectionMethod(DashboardController::class, 'auditSummary'))
            ->invoke(new DashboardController(), $db, $user, 37);
    }

    public function testReportsOnlyNeverTouchesAuditTables(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->never())->method('table');

        $this->assertSame(['canReadAudit' => false, 'totalAuditEvents' => null, 'recentAudit' => []],
            $this->summary($db, ['role' => 'report_viewer', 'permissions' => ['reports.read', 'reports.export']]));
    }

    public static function auditReaders(): iterable
    {
        yield 'explicit permission' => [['role' => 'audit_reader', 'permissions' => ['audit.read']]];
        yield 'superadmin bypass' => [['role' => 'superadmin', 'permissions' => []]];
        yield 'permission rather than role name' => [['role' => 'report_viewer', 'permissions' => ['audit.read']]];
    }

    #[DataProvider('auditReaders')]
    public function testAuditReadersRetainTenantScopedCountAndRecentEvents(array $user): void
    {
        $db = $this->createMock(BaseConnection::class);
        $count = $this->createMock(BaseBuilder::class);
        $recent = $this->createMock(BaseBuilder::class);
        $result = $this->createMock(BaseResult::class);
        $events = [['id' => 42, 'event_type' => 'audit-event-sentinel']];

        $db->expects($this->exactly(2))->method('table')->willReturnMap([
            ['audit_events', $count], ['audit_events a', $recent],
        ]);
        $count->expects($this->once())->method('where')->with('tenant_id', 37)->willReturnSelf();
        $count->expects($this->once())->method('countAllResults')->willReturn(123456);
        $recent->expects($this->once())->method('join')
            ->with('users u', 'u.id = a.actor_user_id AND u.tenant_id = a.tenant_id', 'left')->willReturnSelf();
        $recent->expects($this->once())->method('where')->with('a.tenant_id', 37)->willReturnSelf();
        $recent->expects($this->once())->method('select')->with(
            'a.id, a.event_type, a.entity_type, a.entity_id, a.action, a.occurred_at, u.username as actor_name'
        )->willReturnSelf();
        $recent->expects($this->once())->method('orderBy')->with('a.id', 'DESC')->willReturnSelf();
        $recent->expects($this->once())->method('limit')->with(6)->willReturnSelf();
        $recent->expects($this->once())->method('get')->willReturn($result);
        $result->expects($this->once())->method('getResultArray')->willReturn($events);

        $this->assertSame(['canReadAudit' => true, 'totalAuditEvents' => 123456, 'recentAudit' => $events],
            $this->summary($db, $user));
    }

    public function testRoleWithoutAuditGrantDoesNotInheritAuditAccess(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->never())->method('table');
        $this->assertFalse($this->summary($db, ['role' => 'audit_reader', 'permissions' => []])['canReadAudit']);
    }

    public function testReportsViewOmitsAuditMetadataEvenIfSupplied(): void
    {
        $html = $this->renderDashboard(false);
        foreach (['/admin/audit', 'Audit', 'SHA-256', '123,456', 'audit-event-sentinel',
            'actor-sentinel', 'resource-sentinel', 'No audit events recorded yet.'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
        foreach (['Campus Directory Population', 'Care Operations Volume', 'Active System Accounts',
            'Cross-Department Operations Velocity', 'Operations Reports & Analytics'] as $aggregate) {
            $this->assertStringContainsString($aggregate, $html);
        }
    }

    public function testViewDefaultsToNoAuditAccess(): void
    {
        $this->assertStringNotContainsString('/admin/audit', $this->renderDashboard(null));
    }

    public function testAuthorizedViewRetainsAllAuditSectionsAndLinks(): void
    {
        $html = $this->renderDashboard(true);
        foreach (['Cryptographic Audit Ledger', 'Recent Cryptographic Audit Trail', 'Verify Audit Chain',
            'Open ledger chain', 'Audit Chain Ledger', '123,456', 'audit-event-sentinel',
            'actor-sentinel', 'resource-sentinel'] as $visible) {
            $this->assertStringContainsString($visible, $html);
        }
        $this->assertSame(3, substr_count($html, site_url('/admin/audit')));
    }

    public function testBothEntryPointsRejectMissingIdentityWithoutDatabase(): void
    {
        AuthenticatedUser::clear();
        $request = Services::incomingrequest(null, false);
        $request->setMethod('GET');
        $request->setHeader('Accept', 'application/json');
        $controller = new DashboardController();
        $controller->initController($request, service('response'), new NullLogger());
        foreach (['index', 'admin'] as $method) {
            $response = $controller->{$method}();
            $this->assertSame(401, $response->getStatusCode());
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testEntryPointContractsUseFreshAuthorityAndGuardBeforeDashboardQueries(): void
    {
        // AuthenticatedUser is final and intentionally uncached; verify wiring without replacing it.
        $source = file_get_contents(APPPATH . 'Controllers/DashboardController.php');
        $index = substr($source, strpos($source, 'public function index()'),
            strpos($source, 'public function admin()') - strpos($source, 'public function index()'));
        $admin = substr($source, strpos($source, 'public function admin()'),
            strpos($source, 'private function adminDashboard(') - strpos($source, 'public function admin()'));
        foreach ([$index, $admin] as $entry) {
            $this->assertStringContainsString('$user = (new AuthenticatedUser())->current();', $entry);
            $this->assertStringNotContainsString("session()->get('user')", $entry);
            $this->assertStringContainsString('if ($user === null)', $entry);
            $this->assertLessThan(strpos($entry, 'return $this->adminDashboard($user);'),
                strpos($entry, "\$this->canRead(\$user, 'audit.read')"));
        }
        $this->assertStringContainsString("if (!\$this->canRead(\$user, 'audit.read'))", $admin);
        $this->assertStringContainsString("!\$this->canRead(\$user, 'reports.read') && !\$this->canRead(\$user, 'audit.read')", $index);
        $this->assertSame(2, substr_count($source, "->table('audit_events"));
        $this->assertStringNotContainsString("->table('audit_events",
            substr($source, 0, strpos($source, 'private function auditSummary(')));
        $this->assertStringContainsString('$audit = $this->auditSummary($db, $user, $tenantId);', $source);
        $this->assertStringContainsString("'canReadAudit'           => \$audit['canReadAudit']", $source);
        $this->assertStringContainsString(": 'Reports Dashboard'", $source);
        $this->assertStringContainsString("['label' => 'Reports', 'url' => site_url('/dashboard')]", $source);
    }

    private function renderDashboard(?bool $audit): string
    {
        $data = [
            'user' => ['name' => 'Report Viewer', 'role' => 'report_viewer'],
            'totalPopulation' => 30, 'studentsCount' => 20, 'employeesCount' => 10,
            'totalOperationsVolume' => 7, 'totalClinicEncounters' => 4, 'totalGuidanceSessions' => 3,
            'activeAccounts' => 8, 'chartDays' => [], 'clinicSeries' => [], 'guidanceSeries' => [],
            'totalAuditEvents' => 123456,
            'recentAudit' => [['id' => 42, 'event_type' => 'audit-event-sentinel',
                'entity_type' => 'resource-sentinel', 'entity_id' => 999, 'actor_name' => 'actor-sentinel',
                'occurred_at' => '2026-10-19 12:00:00']],
        ];
        if ($audit !== null) {
            $data['canReadAudit'] = $audit;
        }

        // Execute the actual template, collecting all sections, but never the DB-backed layout.
        $template = new class {
            public function extend(string $layout): string { return ''; }
            public function section(string $name): string { return ''; }
            public function endSection(): string { return ''; }
            public function render(array $data): string
            {
                extract($data);
                ob_start();
                try {
                    include APPPATH . 'Views/dashboard/index.php';
                    return ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            }
        };

        return $template->render($data);
    }
}

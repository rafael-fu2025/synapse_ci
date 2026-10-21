<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Reporting\ExportAccess;
use App\Services\Reporting\ExportRange;
use App\Services\Reporting\OperationalExportService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;

/** Private mock builders only: no operational DB or module services are executed. */
final class ReportExportAuditUnitsTest extends CIUnitTestCase
{
    private function actor(string $role): array
    {
        return ['role' => $role, 'permissions' => ['reports.read', 'reports.export']];
    }

    public function testDepartmentAdminsCannotSelectOtherUnitsOrAll(): void
    {
        foreach (['clinic_admin' => 'clinic', 'guidance_admin' => 'guidance', 'bmg_admin' => 'bmg'] as $role => $own) {
            $actor = $this->actor($role);
            $this->assertSame([$own], ExportAccess::allowedUnits($actor));
            $this->assertSame([$own], ExportAccess::selectUnits($actor, $own));
            foreach (array_diff(['clinic', 'guidance', 'bmg', 'all'], [$own]) as $other) {
                try { ExportAccess::selectUnits($actor, $other); $this->fail('Cross-unit export accepted.'); }
                catch (RuntimeException $e) { $this->assertSame(403, $e->getCode()); }
            }
        }
    }

    public function testGlobalReadersCanSelectAllOrAnySupportedUnit(): void
    {
        foreach (['superadmin', 'audit_reader', 'report_viewer'] as $role) {
            $actor = $this->actor($role);
            $this->assertSame(['clinic', 'guidance', 'bmg'], ExportAccess::allowedUnits($actor));
            $this->assertSame(['clinic', 'guidance', 'bmg'], ExportAccess::selectUnits($actor, 'all'));
            foreach (['clinic', 'guidance', 'bmg'] as $unit) {
                $this->assertSame([$unit], ExportAccess::selectUnits($actor, $unit));
            }
        }
    }

    public function testUnitMustBeAnExplicitSupportedScalar(): void
    {
        foreach ([null, '', [], ['clinic'], 1, true, 'Clinic', 'facilities', 'clinic,guidance', '../clinic', ' all '] as $unit) {
            try { ExportAccess::selectUnits($this->actor('report_viewer'), $unit); $this->fail('Invalid unit accepted.'); }
            catch (RuntimeException $e) { $this->assertSame(422, $e->getCode()); }
        }
    }

    public function testUnitSelectionNeverBypassesRevokedPermissionOrRoleGates(): void
    {
        foreach (['clinic_admin' => 'clinic', 'guidance_admin' => 'guidance', 'bmg_admin' => 'bmg',
            'report_viewer' => 'all', 'audit_reader' => 'all'] as $role => $unit) {
            foreach ([[], ['reports.read'], ['reports.export']] as $permissions) {
                try {
                    ExportAccess::selectUnits(['role' => $role, 'permissions' => $permissions], $unit);
                    $this->fail('Incomplete permissions accepted.');
                } catch (RuntimeException $e) { $this->assertSame(403, $e->getCode()); }
            }
        }
        foreach (['clinic_staff', 'guidance_supervisor', 'counsellor', 'facilities_op', 'student', 'employee'] as $role) {
            try { ExportAccess::selectUnits($this->actor($role), 'all'); $this->fail('Disallowed role accepted.'); }
            catch (RuntimeException $e) { $this->assertSame(403, $e->getCode()); }
        }
    }

    public static function queryScopes(): iterable
    {
        yield 'clinic admin only clinic' => ['clinic_admin', 'clinic', ['clinic']];
        yield 'guidance admin only guidance' => ['guidance_admin', 'guidance', ['guidance']];
        yield 'bmg admin only bmg' => ['bmg_admin', 'bmg', ['bmg']];
        yield 'global single unit' => ['report_viewer', 'bmg', ['bmg']];
        yield 'global all units' => ['audit_reader', 'all', ['clinic', 'guidance', 'bmg']];
        yield 'superadmin all units' => ['superadmin', 'all', ['clinic', 'guidance', 'bmg']];
    }

    #[DataProvider('queryScopes')]
    public function testOnlySelectedModulesAreQueriedWithTenantAndManilaBounds(string $role, string $selection, array $units): void
    {
        $specs = [];
        $expected = [['date_manila', 'unit', 'metric', 'count']];
        if (in_array('clinic', $units, true)) {
            $specs[] = ['clinic_encounters', '', 'started_at', 'completed'];
            $expected[] = ['2026-10-01', 'clinic', 'completed_clinic_encounters_started', 7];
        }
        if (in_array('guidance', $units, true)) {
            $specs[] = ['counselling_sessions s', 's.', 'started_at', 'completed'];
            $expected[] = ['2026-10-01', 'guidance', 'completed_guidance_sessions_started', 7];
        }
        if (in_array('bmg', $units, true)) {
            $specs[] = ['bmg_batches', '', 'started_at', null];
            $specs[] = ['bmg_batches', '', 'completed_at', 'released'];
            $specs[] = ['bmg_batches', '', 'completed_at', 'voided'];
            foreach (['started', 'released', 'voided'] as $status) {
                $expected[] = ['2026-10-01', 'bmg', 'bmg_batches_' . $status, 7];
            }
        }
        $builders = [];
        foreach ($specs as [$table, $prefix, $date, $status]) {
            $builder = $this->createMock(BaseBuilder::class);
            $wheres = [[$prefix . 'tenant_id', 37]];
            if ($status !== null) { $wheres[] = [$prefix . 'status', $status]; }
            $wheres[] = [$prefix . $date . ' >=', '2026-09-30 16:00:00'];
            $wheres[] = [$prefix . $date . ' <', '2026-10-01 16:00:00'];
            $builder->expects($this->exactly(count($wheres)))->method('where')->willReturnCallback(
                function ($key, $value) use ($wheres, $builder) {
                    static $index = 0;
                    $this->assertSame($wheres[$index++], [$key, $value]);
                    return $builder;
                });
            $builder->expects($this->never())->method('select');
            $builder->expects($this->never())->method('get');
            $builder->expects($this->once())->method('countAllResults')->willReturn(7);
            if ($table === 'counselling_sessions s') {
                $builder->expects($this->once())->method('join')
                    ->with('counselling_cases c', 'c.id = s.case_id AND c.tenant_id = s.tenant_id')->willReturnSelf();
            } else { $builder->expects($this->never())->method('join'); }
            $builders[] = $builder;
        }
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->exactly(count($specs)))->method('table')->willReturnCallback(
            function ($table) use ($specs, $builders) {
                static $index = 0;
                $this->assertSame($specs[$index][0], $table);
                return $builders[$index++];
            });
        $rows = (new ReflectionMethod(OperationalExportService::class, 'aggregate'))->invoke(new OperationalExportService(),
            $db, 37, ExportRange::fromInput(['from' => '2026-10-01', 'to' => '2026-10-01']), 'UTC',
            ExportAccess::selectUnits($this->actor($role), $selection));
        $this->assertSame($expected, $rows);
    }

    public function testMaximumAllUnitRangeIsBoundedTo155CountQueriesAndRows(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $builder = $this->createMock(BaseBuilder::class);
        $db->expects($this->exactly(155))->method('table')->willReturn($builder);
        $builder->method('where')->willReturnSelf();
        $builder->method('join')->willReturnSelf();
        $builder->expects($this->never())->method('get');
        $builder->expects($this->exactly(155))->method('countAllResults')->willReturn(0);
        $rows = (new ReflectionMethod(OperationalExportService::class, 'aggregate'))->invoke(new OperationalExportService(),
            $db, 37, ExportRange::fromInput(['from' => '2026-10-01', 'to' => '2026-10-31']), 'Asia/Manila',
            ['clinic', 'guidance', 'bmg']);
        $this->assertCount(156, $rows);
        $this->assertSame(['2026-10-31', 'bmg', 'bmg_batches_voided', 0], $rows[155]);
    }

    public function testUiListsOnlyRoleAllowedUnits(): void
    {
        foreach (['clinic_admin', 'guidance_admin', 'bmg_admin', 'report_viewer'] as $role) {
            $units = ExportAccess::allowedUnits($this->actor($role));
            $template = new class {
                public function extend(string $layout): string { return ''; }
                public function section(string $name): string { return ''; }
                public function endSection(): string { return ''; }
                public function render(array $units): string
                {
                    $from = $to = '2026-10-01';
                    $fromDisplay = $toDisplay = '01/10/2026';
                    ob_start();
                    try { include APPPATH . 'Views/reports/export.php'; return ob_get_contents(); }
                    finally { ob_end_clean(); }
                }
            };
            $html = $template->render($units);
            foreach (['clinic', 'guidance', 'bmg', 'all'] as $unit) {
                $option = 'value="' . $unit . '"';
                if (in_array($unit, $units, true) || ($unit === 'all' && count($units) === 3)) {
                    $this->assertStringContainsString($option, $html);
                } else { $this->assertStringNotContainsString($option, $html); }
            }
            $this->assertStringContainsString('name="unit"', $html);
            if ($role === 'bmg_admin') {
                $this->assertStringContainsString('no sensor, sanitation, or quality claims', $html);
                $this->assertStringNotContainsString('<strong>Clinic:</strong>', $html);
                $this->assertStringNotContainsString('<strong>Guidance:</strong>', $html);
            }
        }
    }

    public function testRuntimeValidatesUnitBeforeQueriesAndAuditsExpandedScopeOnly(): void
    {
        $source = file_get_contents(APPPATH . 'Services/Reporting/OperationalExportService.php');
        $this->assertStringContainsString("ExportAccess::selectUnits(\$actor, \$input['unit'] ?? null)", $source);
        $this->assertLessThan(strpos($source, 'Database::connect()'), strpos($source, 'ExportAccess::selectUnits('));
        $audit = substr($source, strpos($source, '(new AuditService())->log('),
            strpos($source, "return ['csv'") - strpos($source, '(new AuditService())->log('));
        foreach (["'units' => \$units", "'row_count' => count(\$rows) - 1", '$range->metadata()'] as $metadata) {
            $this->assertStringContainsString($metadata, $audit);
        }
        foreach (['$csv', '$input', 'release_notes', 'sensor', 'quality_grade', 'bmg_process_logs'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $audit);
        }
    }
}

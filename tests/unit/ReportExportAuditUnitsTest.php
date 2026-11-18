<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Reporting\ExportAccess;
use App\Services\Reporting\ExportRange;
use App\Services\Reporting\OperationalExportService;
use CodeIgniter\Database\SQLite3\Connection;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** Private SQLite fixtures and pure authorization/UI checks; no operational database. */
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
        $db = $this->fixture($units);
        try {
            $expected = [['date_manila', 'unit', 'metric', 'count']];
            if (in_array('clinic', $units, true)) {
                $expected[] = ['2026-10-01', 'clinic', 'completed_clinic_encounters_started', 7];
            }
            if (in_array('guidance', $units, true)) {
                $db->table('counselling_cases')->insertBatch([['id' => 1, 'tenant_id' => 37], ['id' => 2, 'tenant_id' => 38]]);
                $expected[] = ['2026-10-01', 'guidance', 'completed_guidance_sessions_started', 7];
            }
            if (in_array('bmg', $units, true)) {
                foreach (['started', 'released', 'voided'] as $status) {
                    $expected[] = ['2026-10-01', 'bmg', 'bmg_batches_' . $status, 7];
                }
            }
            for ($index = 0; $index < 7; $index++) {
                if (in_array('clinic', $units, true)) {
                    $db->table('clinic_encounters')->insert(['tenant_id' => 37, 'status' => 'completed', 'started_at' => '2026-09-30 16:00:00']);
                }
                if (in_array('guidance', $units, true)) {
                    $db->table('counselling_sessions')->insert(['tenant_id' => 37, 'case_id' => 1, 'status' => 'completed', 'started_at' => '2026-09-30 16:00:00']);
                }
                if (in_array('bmg', $units, true)) {
                    $db->table('bmg_batches')->insert(['tenant_id' => 37, 'status' => 'released',
                        'started_at' => '2026-09-30 16:00:00', 'completed_at' => '2026-09-30 16:00:00']);
                    $db->table('bmg_batches')->insert(['tenant_id' => 37, 'status' => 'voided',
                        'started_at' => null, 'completed_at' => '2026-09-30 16:00:00']);
                }
            }
            foreach ([38, 37] as $tenant) {
                $time = $tenant === 38 ? '2026-09-30 16:00:00' : '2026-10-01 16:00:00';
                if (in_array('clinic', $units, true)) {
                    $db->table('clinic_encounters')->insert(['tenant_id' => $tenant, 'status' => 'completed', 'started_at' => $time]);
                }
                if (in_array('guidance', $units, true)) {
                    $db->table('counselling_sessions')->insert(['tenant_id' => $tenant, 'case_id' => $tenant === 37 ? 1 : 2,
                        'status' => 'completed', 'started_at' => $time]);
                }
                if (in_array('bmg', $units, true)) {
                    $db->table('bmg_batches')->insert(['tenant_id' => $tenant, 'status' => 'released', 'started_at' => $time, 'completed_at' => $time]);
                }
            }
            $selected = ExportAccess::selectUnits($this->actor($role), $selection);
            $this->assertSame($units, $selected);
            [$rows, $queries] = $this->aggregateObserved($db,
                ExportRange::fromInput(['from' => '2026-10-01', 'to' => '2026-10-01']), 'UTC', $selected);
            $this->assertSame($expected, $rows);
            $this->assertCount(count($units), $queries);
        } finally {
            $db->close();
        }
    }

    public function testMaximumAllUnitRangeUses33QueriesAnd155MetricRows(): void
    {
        $db = $this->fixture(['clinic', 'guidance', 'bmg']);
        try {
            [$rows, $queries] = $this->aggregateObserved($db,
                ExportRange::fromInput(['from' => '2026-10-01', 'to' => '2026-10-31']), 'Asia/Manila', ['clinic', 'guidance', 'bmg']);
            $this->assertCount(33, $queries);
            $this->assertCount(156, $rows);
            $this->assertSame(['2026-10-31', 'bmg', 'bmg_batches_voided', 0], $rows[155]);
        } finally {
            $db->close();
        }
    }

    private function fixture(array $units): Connection
    {
        $db = new Connection(['database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true]);
        $definitions = [
            'clinic' => ['clinic_encounters' => '(tenant_id INTEGER, status TEXT, started_at TEXT)'],
            'guidance' => ['counselling_cases' => '(id INTEGER PRIMARY KEY, tenant_id INTEGER)',
                'counselling_sessions' => '(tenant_id INTEGER, case_id INTEGER, status TEXT, started_at TEXT)'],
            'bmg' => ['bmg_batches' => '(tenant_id INTEGER, status TEXT, started_at TEXT, completed_at TEXT)'],
        ];
        foreach ($units as $unit) {
            foreach ($definitions[$unit] as $table => $columns) {
                $db->query('CREATE TABLE ' . $db->escapeIdentifiers($table) . ' ' . $columns);
            }
        }
        return $db;
    }

    private function aggregateObserved(Connection $db, ExportRange $range, string $timezone, array $units): array
    {
        $queries = [];
        $observer = static function ($query) use ($db, &$queries): void {
            if ($db->getLastQuery() === $query) $queries[] = $query->getQuery();
        };
        Events::on('DBQuery', $observer);
        try {
            $rows = (new OperationalExportService())->aggregate($db, 37, $range, $timezone, $units);
            return [$rows, $queries];
        } finally {
            Events::removeListener('DBQuery', $observer);
        }
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
            strpos($source, "return [\$format =>") - strpos($source, '(new AuditService())->log('));
        foreach (["'units' => \$units", "'row_count' => count(\$rows) - 1", "'format' => \$format", '$range->metadata()'] as $metadata) {
            $this->assertStringContainsString($metadata, $audit);
        }
        foreach (['$content', "'content' =>", "'csv' =>", "'pdf' =>", '$input', 'release_notes', 'sensor', 'quality_grade', 'bmg_process_logs'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $audit);
        }
    }
}

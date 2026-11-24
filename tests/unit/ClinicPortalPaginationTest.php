<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\PortalController;
use App\Libraries\TablePage;
use App\Services\Clinic\PatientHistoryService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceMultipleIntelligenceService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** Guarded private SQLite only: no application records, migrations or encrypted answers. */
final class ClinicPortalPaginationTest extends CIUnitTestCase
{
    private BaseConnection $fixture;
    private array $actor = ['id' => 41, 'tenant_id' => 7, 'person_id' => 88, 'role' => 'student',
        'permissions' => ['guidance.assessments.take']];

    protected function setUp(): void
    {
        if (!defined('PAGINATION_ISOLATED_TESTS') || PAGINATION_ISOLATED_TESTS !== true) {
            throw new \RuntimeException('Use the guarded pagination-isolated-bootstrap.php.');
        }
        parent::setUp();
        $this->fixture = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $this->fixture->initialize();
        foreach ([
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, first_name TEXT, last_name TEXT, email TEXT, deleted_at TEXT',
            'health_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'clinic_encounters' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, status TEXT, chief_complaint TEXT, started_at TEXT, visited_at TEXT, attending_user_id INTEGER, encoded_by_user_id INTEGER, deleted_at TEXT',
            'clinic_vitals' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, encounter_id INTEGER, recorded_at TEXT, deleted_at TEXT',
            'clinic_treatments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, encounter_id INTEGER, diagnosis TEXT, treatment_plan TEXT, services_rendered TEXT',
            'clinic_dispensations' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, encounter_id INTEGER, medicine_id INTEGER, batch_id INTEGER, quantity INTEGER, instructions TEXT, created_at TEXT',
            'clinic_medicines' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, strength TEXT, unit_of_measure TEXT',
            'clinic_medicine_batches' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, medicine_id INTEGER, batch_number TEXT, expiration_date TEXT',
            'clinic_lab_results' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, test_name TEXT, recorded_by_user_id INTEGER, submitted_at TEXT, created_at TEXT, deleted_at TEXT',
            'clinic_enrollment_clearances' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, school_year TEXT, cleared_by_user_id INTEGER, deleted_at TEXT',
            'clinic_isolation_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, isolation_start_date TEXT, reported_by_user_id INTEGER, cleared_by_user_id INTEGER, deleted_at TEXT',
            'guidance_call_slips' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, attendance_status TEXT, call_date TEXT, call_time TEXT, call_slip_number TEXT, form_instance_number TEXT, purpose TEXT, deleted_at TEXT',
            'guidance_mi_assignments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, status TEXT, academic_term TEXT, academic_year TEXT, deleted_at TEXT',
            'guidance_mi_submissions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, assignment_id INTEGER, status TEXT, attested_at TEXT, deleted_at TEXT',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
        $this->fixture->table('people')->insert(['id' => 88, 'tenant_id' => 7, 'kind' => 'guest', 'first_name' => 'Synthetic', 'last_name' => 'Patient', 'email' => 'fixture@example.test']);
        $this->fixture->table('users')->insert(['id' => 41, 'tenant_id' => 7, 'person_id' => 88, 'username' => 'synthetic', 'role' => 'student', 'status' => 'active']);
        $this->fixture->table('permissions')->insert(['id' => 1, 'code' => 'guidance.assessments.take']);
        $this->fixture->table('role_permissions')->insert(['role' => 'student', 'permission_id' => 1]);
        CurrentTenant::set(7);
        cache()->save('inventory_schema_installed', ['installed' => false], 60);
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
        cache()->delete('inventory_schema_installed');
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function insert(string $table, array $row): int
    {
        self::assertTrue($this->fixture->table($table)->insert($row));
        return (int) $this->fixture->insertID();
    }

    private function ids(array $rows): array
    {
        return array_map('intval', array_column($rows, 'id'));
    }

    private function seedChart(): array
    {
        $this->insert('clinic_medicines', ['id' => 1, 'tenant_id' => 7, 'name' => 'Synthetic medicine', 'strength' => '1 mg', 'unit_of_measure' => 'tablet']);
        $this->insert('clinic_medicine_batches', ['id' => 1, 'tenant_id' => 7, 'medicine_id' => 1, 'batch_number' => 'FIXTURE', 'expiration_date' => '2099-01-01']);
        $start = PatientHistoryService::schoolYearUtcBounds(PatientHistoryService::currentSchoolYear())[0];
        $recent = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');
        $ids = [];
        for ($i = 0; $i < 61; $i++) {
            $id = $this->insert('clinic_encounters', ['tenant_id' => 7, 'person_id' => 88, 'status' => $i === 0 ? 'in_progress' : 'completed',
                'chief_complaint' => $i === 0 ? 'Older complaint' : 'Recurring complaint', 'started_at' => $i === 0 ? '2000-01-01 00:00:00' : $recent]);
            $ids[] = $id;
            $this->insert('clinic_treatments', ['tenant_id' => 7, 'encounter_id' => $id, 'diagnosis' => 'Synthetic diagnosis', 'treatment_plan' => 'Synthetic plan', 'services_rendered' => 'Synthetic service']);
            for ($child = 0; $child < 2; $child++) {
                $this->insert('clinic_dispensations', ['tenant_id' => 7, 'encounter_id' => $id, 'medicine_id' => 1, 'batch_id' => 1, 'quantity' => 1, 'instructions' => 'Synthetic only', 'created_at' => $recent]);
            }
            $this->insert('clinic_lab_results', ['tenant_id' => 7, 'person_id' => 88, 'test_name' => 'Synthetic lab', 'created_at' => $recent]);
            if ($i < 31) $this->insert('clinic_enrollment_clearances', ['tenant_id' => 7, 'person_id' => 88, 'school_year' => '2026-2027']);
            if ($i < 36) $this->insert('clinic_isolation_cases', ['tenant_id' => 7, 'person_id' => 88, 'isolation_start_date' => '2026-10-01']);
        }
        foreach (['clinic_encounters', 'clinic_lab_results', 'clinic_enrollment_clearances', 'clinic_isolation_cases'] as $table) {
            foreach ([['tenant_id' => 8], ['person_id' => 89], ['deleted_at' => '2026-11-01']] as $change) {
                $this->insert($table, array_replace(['tenant_id' => 7, 'person_id' => 88], $change));
            }
        }
        return $ids;
    }

    public function testChartIndependentSqlPagesRetainOpenVisitFullSummaryChildrenAndLegacyHistory(): void
    {
        $ids = $this->seedChart();
        $history = new PatientHistoryService($this->fixture);
        $chart = $history->getPatientChart(88, includeAppointments: false, historyQuery: [
            'visits_page' => '2', 'labs_page' => '3', 'clearances_page' => '2', 'isolation_page' => '2',
        ]);
        self::assertSame(array_slice(array_reverse($ids), 25, 25), $this->ids($chart['encounters']));
        self::assertCount(11, $chart['lab_results']);
        self::assertCount(6, $chart['enrollment_clearances']);
        self::assertCount(11, $chart['isolation_cases']);
        foreach (['visits' => [61, 2], 'labs' => [61, 3], 'clearances' => [31, 2], 'isolation' => [36, 2]] as $list => [$total, $page]) {
            self::assertSame($total, $chart['history_pagination'][$list]['total']);
            self::assertSame($total, $chart['history_pagination'][$list]['filtered']);
            self::assertSame($page, $chart['history_pagination'][$list]['page']);
            self::assertSame(25, $chart['history_pagination'][$list]['per_page']);
        }
        self::assertMatchesRegularExpression('/LIMIT 25(?: OFFSET 25|, 25)/i', (string) $this->fixture->getLastQuery());
        self::assertSame($ids[0], (int) $chart['open_visit']['id']);
        self::assertNotContains($ids[0], $this->ids($chart['encounters']));
        self::assertSame(61, $chart['visit_summary']['total_visits']);
        self::assertSame(60, $chart['visit_summary']['current_sy_visits']);
        self::assertSame(['label' => 'Recurring complaint', 'count' => 60], $chart['visit_summary']['top_complaints'][0]);
        self::assertCount(61, $chart['treatment_history']);
        self::assertCount(122, $chart['dispensation_history']);
        $full = $history->getPatientChart(88, includeAppointments: false);
        self::assertArrayNotHasKey('history_pagination', $full);
        self::assertSame(array_reverse($ids), $this->ids($full['encounters']));
        self::assertCount(61, $full['lab_results']);
        self::assertCount(31, $full['enrollment_clearances']);
        self::assertCount(36, $full['isolation_cases']);
        self::assertSame($full['visit_summary'], $chart['visit_summary']);
        self::assertSame($full['treatment_history'], $chart['treatment_history']);
        self::assertSame($full['dispensation_history'], $chart['dispensation_history']);
    }

    public function testChartClampsEachCursorAndRejectsUnsupportedStaffSize(): void
    {
        $this->seedChart();
        $history = new PatientHistoryService($this->fixture);
        foreach ([25, 50, 100] as $size) {
            $chart = $history->getPatientChart(88, false, ['per_page' => (string) $size, 'visits_page' => '999', 'labs_page' => '999', 'clearances_page' => '999', 'isolation_page' => '999']);
            foreach (['visits' => 61, 'labs' => 61, 'clearances' => 31, 'isolation' => 36] as $list => $total) {
                self::assertSame((int) ceil($total / $size), $chart['history_pagination'][$list]['page']);
                self::assertSame($size, $chart['history_pagination'][$list]['per_page']);
                self::assertSame($total, $chart['history_pagination'][$list]['to']);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $history->getPatientChart(88, false, ['per_page' => '20']);
    }

    public function testCountHappensBeforeClampedHistorySqlLimit(): void
    {
        $sequence = [];
        $builder = $this->createMock(BaseBuilder::class);
        $builder->method('countAllResults')->willReturnCallback(static function () use (&$sequence): int { $sequence[] = 'count'; return 26; });
        $rows = $this->createMock(BaseResult::class);
        $rows->method('getResultArray')->willReturn([['id' => 1]]);
        $builder->expects($this->once())->method('get')->with(25, 25)->willReturnCallback(static function () use (&$sequence, $rows) { $sequence[] = 'limit'; return $rows; });
        $history = (new ReflectionClass(PatientHistoryService::class))->newInstanceWithoutConstructor();
        $result = (new ReflectionMethod(PatientHistoryService::class, 'chartHistoryPage'))->invoke($history, $builder, TablePage::forList(['visits_page' => '999'], 'visits_page'));
        self::assertSame(['count', 'limit'], $sequence);
        self::assertSame(['page' => 2, 'per_page' => 25, 'total' => 26, 'filtered' => 26, 'pages' => 2, 'from' => 26, 'to' => 26], $result['pagination']);
    }

    private function slips(string $status, array $query = []): array
    {
        $controller = (new ReflectionClass(PortalController::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod(PortalController::class, 'portalCallSlipPage'))->invoke($controller, $this->fixture, 7, 88, $status, TablePage::forList($query, 'page', 'per_page', 20));
    }

    public function testCallSlipsBeyondFiveHundredUseFullScopedCountsSqlFiltersAndStableRank(): void
    {
        $base = ['tenant_id' => 7, 'person_id' => 88, 'call_date' => '2000-01-01', 'call_time' => '09:00:00', 'call_slip_number' => 'SYNTHETIC', 'form_instance_number' => 'INSTANCE', 'purpose' => 'counseling'];
        $pending = $this->insert('guidance_call_slips', $base + ['attendance_status' => 'pending']);
        $ack = $this->insert('guidance_call_slips', $base + ['attendance_status' => 'acknowledged']);
        $tieOld = $this->insert('guidance_call_slips', array_replace($base, ['attendance_status' => 'pending', 'call_date' => '2001-01-01']));
        $tieNew = $this->insert('guidance_call_slips', array_replace($base, ['attendance_status' => 'pending', 'call_date' => '2001-01-01']));
        $history = [];
        for ($i = 0; $i < 620; $i++) $history[] = $this->insert('guidance_call_slips', array_replace($base, ['attendance_status' => 'attended', 'call_date' => '2026-11-01']));
        foreach ([['tenant_id' => 8], ['person_id' => 89], ['deleted_at' => '2026-11-02']] as $change) {
            $this->insert('guidance_call_slips', array_replace($base, ['attendance_status' => 'pending'], $change));
        }
        $expected = array_merge([$pending, $tieNew, $tieOld, $ack], array_reverse($history));
        $result = $this->slips('all', ['per_page' => '25']);
        self::assertSame(['all' => 624, 'pending' => 3, 'acknowledged' => 1, 'history' => 620], $result['counts']);
        self::assertSame(array_slice($expected, 0, 25), $this->ids($result['callSlips']));
        self::assertSame('INSTANCE', $result['callSlips'][0]['form_instance_number']);
        self::assertSame(624, $result['pagination']['filtered']);
        $second = $this->slips('all', ['per_page' => '25', 'page' => '2']);
        self::assertSame(array_slice($expected, 25, 25), $this->ids($second['callSlips']));
        self::assertStringContainsString('ORDER BY CASE WHEN', (string) $this->fixture->getLastQuery());
        self::assertMatchesRegularExpression('/LIMIT 25(?: OFFSET 25|, 25)/i', (string) $this->fixture->getLastQuery());
        self::assertSame([$pending, $tieNew, $tieOld], $this->ids($this->slips('pending')['callSlips']));
        self::assertSame([$ack], $this->ids($this->slips('acknowledged')['callSlips']));
        foreach ([20, 25, 50, 100] as $size) {
            $last = $this->slips('history', ['per_page' => (string) $size, 'page' => '999']);
            self::assertSame(624, $last['pagination']['total']);
            self::assertSame(620, $last['pagination']['filtered']);
            self::assertSame((int) ceil(620 / $size), $last['pagination']['page']);
            self::assertSame(620, $last['pagination']['to']);
            self::assertLessThanOrEqual($size, count($last['callSlips']));
        }
    }

    public function testEmptyChartAndCallSlipScopesKeepZeroRanges(): void
    {
        $chart = (new PatientHistoryService($this->fixture))->getPatientChart(88, false, ['visits_page' => '999', 'labs_page' => '999', 'clearances_page' => '999', 'isolation_page' => '999']);
        foreach ($chart['history_pagination'] as $pagination) {
            self::assertSame(1, $pagination['page']);
            self::assertSame(0, $pagination['from']);
            self::assertSame(0, $pagination['to']);
            self::assertSame(0, $pagination['total']);
        }
        self::assertNull($chart['open_visit']);
        self::assertSame(0, $chart['visit_summary']['total_visits']);
        $empty = $this->slips('history', ['page' => '999']);
        self::assertSame([], $empty['callSlips']);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 0, 'filtered' => 0, 'pages' => 1, 'from' => 0, 'to' => 0], $empty['pagination']);
    }

    private function miService(array &$draftIds): GuidanceMultipleIntelligenceService
    {
        $service = $this->getMockBuilder(GuidanceMultipleIntelligenceService::class)->disableOriginalConstructor()->onlyMethods(['getDraft'])->getMock();
        (new ReflectionProperty(GuidanceMultipleIntelligenceService::class, 'db'))->setValue($service, $this->fixture);
        $service->method('getDraft')->willReturnCallback(static function (int $id) use (&$draftIds): array { $draftIds[] = $id; return [1 => 2, 2 => 1]; });
        return $service;
    }

    private function seedMi(): void
    {
        foreach (['assigned' => 30, 'in_progress' => 10, 'completed' => 20, 'cancelled' => 15] as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $id = $this->insert('guidance_mi_assignments', ['tenant_id' => 7, 'person_id' => 88, 'status' => $status, 'academic_term' => 'First Term', 'academic_year' => $i < 2 && $status === 'assigned' ? 'MATCH_%!' : '2026-2027']);
                if ($status === 'completed') $this->insert('guidance_mi_submissions', ['tenant_id' => 7, 'person_id' => 88, 'assignment_id' => $id, 'status' => 'completed']);
            }
        }
        for ($i = 0; $i < 5; $i++) $this->insert('guidance_mi_assignments', ['tenant_id' => 7, 'person_id' => 88, 'status' => 'completed', 'academic_term' => 'First Term', 'academic_year' => '2026-2027']);
        foreach ([['tenant_id' => 8], ['person_id' => 89], ['deleted_at' => '2026-11-01']] as $change) {
            $this->insert('guidance_mi_assignments', array_replace(['tenant_id' => 7, 'person_id' => 88, 'status' => 'assigned', 'academic_term' => 'First Term', 'academic_year' => 'MATCH_%!'], $change));
        }
    }

    public function testMiCountsAllStatusesBeforeIndependentLimitAndHydratesOnlyPageRows(): void
    {
        $this->seedMi();
        $draftIds = [];
        $service = $this->miService($draftIds);
        $result = $service->listPortalAssignmentsPage($this->actor, ['status' => 'available', 'mi_page' => '2', 'mi_per_page' => '25', 'page' => '999', 'per_page' => '100']);
        self::assertSame(['all' => 80, 'available' => 45, 'submitted' => 20, 'closed' => 35], $result['counts']);
        self::assertSame(['page' => 2, 'per_page' => 25, 'total' => 80, 'filtered' => 45, 'pages' => 2, 'from' => 26, 'to' => 45], $result['pagination']);
        self::assertCount(20, $result['assignments']);
        self::assertCount(20, $draftIds);
        self::assertSame($this->ids($result['assignments']), $draftIds);
        self::assertSame(2, $result['assignments'][0]['draft_count']);
        self::assertMatchesRegularExpression('/LIMIT 25(?: OFFSET 25|, 25)/i', (string) $this->fixture->getLastQuery());
        $legacy = $service->listPortalAssignments($this->actor);
        self::assertCount(80, $legacy);
        self::assertArrayHasKey('draft_count', $legacy[0]);
        self::assertArrayNotHasKey('assignments', $legacy);
        foreach (['submitted' => 20, 'closed' => 35] as $status => $filtered) {
            $page = $service->listPortalAssignmentsPage($this->actor, ['status' => $status, 'mi_page' => '999']);
            self::assertSame($filtered, $page['pagination']['filtered']);
            self::assertSame((int) ceil($filtered / 25), $page['pagination']['page']);
        }
    }

    public function testMiSearchRunsAcrossFullScopeUsesLiteralWildcardsAndDbResolvedPerson(): void
    {
        $this->seedMi();
        $draftIds = [];
        $service = $this->miService($draftIds);
        $spoofedPerson = array_replace($this->actor, ['person_id' => 89]);
        $literal = $service->listPortalAssignmentsPage($spoofedPerson, ['q' => 'MATCH_%!', 'mi_page' => '999']);
        self::assertSame(['all' => 2, 'available' => 2, 'submitted' => 0, 'closed' => 0], $literal['counts']);
        self::assertSame(80, $literal['pagination']['total']);
        self::assertSame([2, 1], $this->ids($literal['assignments']));
        foreach ($literal['assignments'] as $row) self::assertSame(88, (int) $row['person_id']);
        self::assertStringContainsString("ESCAPE '!'", (string) $this->fixture->getLastQuery());
        $phrase = $service->listPortalAssignmentsPage($this->actor, ['q' => 'Assessment First Term 2026-2027', 'mi_per_page' => '100']);
        self::assertSame(78, $phrase['pagination']['filtered']);
        self::assertCount(78, $phrase['assignments']);
        $catalog = $service->listPortalAssignmentsPage($this->actor, ['q' => 'Walter McKenzie']);
        self::assertSame(80, $catalog['pagination']['filtered']);
        $empty = $service->listPortalAssignmentsPage($this->actor, ['q' => 'No matching topic', 'mi_page' => '999']);
        self::assertSame([], $empty['assignments']);
        self::assertSame(0, $empty['pagination']['from']);
        self::assertSame(0, $empty['pagination']['to']);
        $this->fixture->table('users')->where('id', 41)->update(['status' => 'inactive']);
        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);
        $service->listPortalAssignmentsPage($this->actor);
    }
}

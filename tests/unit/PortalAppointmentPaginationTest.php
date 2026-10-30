<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\PortalController;
use App\Models\AppointmentModel;
use App\Services\Clinic\PatientHistoryService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** Private SQLite queries only; no application connection or migrations. */
final class PortalAppointmentPaginationTest extends CIUnitTestCase
{
    private BaseConnection $fixture;
    private PatientHistoryService $history;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'portal_page_', 'DBDebug' => true], false);
        $this->fixture->initialize();
        $this->fixture->connID->createFunction('CONCAT_WS', static fn ($separator, ...$parts) => implode($separator, array_filter($parts, static fn ($part) => $part !== null)), -1);
        foreach ([
            'appointments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, unit TEXT, status TEXT, starts_at TEXT, provider_user_id INTEGER, staff_assignment_id INTEGER, deleted_at TEXT',
            'department_staff' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, unit TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, username TEXT',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $this->fixture->prefixTable($table) . ' (' . $columns . ')');
        }
        // Exercise the actual final service and model without constructing unrelated chart models.
        $this->history = (new ReflectionClass(PatientHistoryService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PatientHistoryService::class, 'appointmentModel'))->setValue($this->history, new AppointmentModel($this->fixture));
        CurrentTenant::set(7);
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function appointment(array $changes = []): int
    {
        $this->fixture->table('appointments')->insert(array_replace([
            'tenant_id' => 7, 'person_id' => 88, 'unit' => 'clinic', 'status' => 'requested',
            'starts_at' => '2026-10-20 09:00:00',
        ], $changes));
        return (int) $this->fixture->insertID();
    }

    private function ids(array $rows): array
    {
        return array_map('intval', array_column($rows, 'id'));
    }

    public function testCountsScopesAndBoundedSqlOffsetAreAppliedBeforeFetching(): void
    {
        $expected = [];
        for ($i = 0; $i < 43; $i++) $expected[] = $this->appointment();
        $this->appointment(['unit' => 'guidance']);
        $this->appointment(['status' => 'completed']);
        foreach ([['tenant_id' => 8], ['person_id' => 89], ['deleted_at' => '2026-10-21 00:00:00']] as $change) $this->appointment($change);
        $result = $this->history->getPortalAppointmentsPage(88, ['service' => 'clinic', 'status' => 'active', 'per_page' => 20, 'page' => 2]);
        self::assertSame(['page' => 2, 'pages' => 3, 'per_page' => 20, 'total' => 45, 'filtered' => 43, 'from' => 21, 'to' => 40], $result['pagination']);
        self::assertSame(array_slice($expected, 20, 20), $this->ids($result['appointments']));
        $sql = (string) $this->fixture->getLastQuery();
        self::assertMatchesRegularExpression('/LIMIT 20(?: OFFSET 20|, 20)/i', $sql);
        self::assertStringContainsString('ORDER BY CASE WHEN', $sql);
        self::assertNull($this->history->getPortalAppointment(89, $expected[0]));
        self::assertNotNull($this->history->getPortalAppointment(88, $expected[0]));
    }

    public function testAllSortsAndTiesRemainStableAcrossPageBoundaries(): void
    {
        $rows = [];
        foreach (['completed', 'requested', 'cancelled', 'scheduled', 'confirmed', 'no_show', 'checked_in'] as $status) {
            for ($i = 0; $i < 7; $i++) {
                $date = '2026-10-' . (20 + $i % 3) . ' 09:00:00';
                $rows[] = ['id' => $this->appointment(['status' => $status, 'starts_at' => $date]), 'status' => $status, 'starts_at' => $date];
            }
        }
        foreach (['upcoming', 'date_asc', 'date_desc', 'invalid'] as $sort) {
            $expected = $rows;
            usort($expected, static function ($a, $b) use ($sort): int {
                if ($sort === 'date_asc') return strcmp($a['starts_at'], $b['starts_at']) ?: $a['id'] <=> $b['id'];
                if ($sort === 'date_desc') return strcmp($b['starts_at'], $a['starts_at']) ?: $b['id'] <=> $a['id'];
                $active = ['requested', 'scheduled', 'confirmed', 'checked_in'];
                $aa = in_array($a['status'], $active, true);
                $ba = in_array($b['status'], $active, true);
                if ($aa !== $ba) return $aa ? -1 : 1;
                return $aa ? (strcmp($a['starts_at'], $b['starts_at']) ?: $a['id'] <=> $b['id'])
                    : (strcmp($b['starts_at'], $a['starts_at']) ?: $b['id'] <=> $a['id']);
            });
            $actual = [];
            for ($page = 1; $page <= 3; $page++) {
                $result = $this->history->getPortalAppointmentsPage(88, ['sort' => $sort, 'per_page' => 20, 'page' => $page]);
                $actual = array_merge($actual, $this->ids($result['appointments']));
            }
            self::assertSame($this->ids($expected), $actual, $sort);
            self::assertSame($actual, $this->ids($this->history->getPortalAppointments(88, ['sort' => $sort])));
        }
    }

    public function testEveryStatusFilterAndConfirmedAliasRetainTheirMeaning(): void
    {
        $ids = [];
        foreach (['requested', 'scheduled', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show'] as $status) $ids[$status] = $this->appointment(['status' => $status]);
        foreach (['requested', 'checked_in', 'completed', 'cancelled', 'no_show'] as $status) {
            $result = $this->history->getPortalAppointmentsPage(88, ['status' => $status]);
            self::assertSame([$ids[$status]], $this->ids($result['appointments']));
            self::assertSame(1, $result['pagination']['filtered']);
        }
        self::assertSame([$ids['scheduled'], $ids['confirmed']], $this->ids($this->history->getPortalAppointmentsPage(88, ['status' => 'confirmed'])['appointments']));
        self::assertSame(7, $this->history->getPortalAppointmentsPage(88, ['status' => 'invalid', 'service' => 'invalid'])['pagination']['filtered']);
    }

    public function testPageSizesClampingInvalidInputsAndEmptyRanges(): void
    {
        for ($i = 0; $i < 101; $i++) $this->appointment();
        foreach ([20, 25, 50, 100] as $size) {
            $result = $this->history->getPortalAppointmentsPage(88, ['per_page' => (string) $size, 'page' => 999]);
            self::assertSame((int) ceil(101 / $size), $result['pagination']['page']);
            self::assertSame($size, $result['pagination']['per_page']);
            self::assertSame(101, $result['pagination']['from']);
            self::assertCount(1, $result['appointments']);
        }
        foreach ([0, -1, 'bad', [], '999999999999999999999999'] as $invalid) {
            $result = $this->history->getPortalAppointmentsPage(88, ['page' => $invalid, 'per_page' => $invalid]);
            self::assertSame(1, $result['pagination']['page']);
            self::assertSame(25, $result['pagination']['per_page']);
        }
        $empty = $this->history->getPortalAppointmentsPage(88, ['service' => 'guidance', 'page' => 999]);
        self::assertSame([], $empty['appointments']);
        self::assertSame(['page' => 1, 'pages' => 1, 'per_page' => 25, 'total' => 101, 'filtered' => 0, 'from' => 0, 'to' => 0], $empty['pagination']);
    }

    public function testControllerNormalizesUntrustedQueryValues(): void
    {
        $controller = (new ReflectionClass(PortalController::class))->newInstanceWithoutConstructor();
        $request = service('request');
        (new ReflectionProperty(PortalController::class, 'request'))->setValue($controller, $request);
        $method = new ReflectionMethod(PortalController::class, 'appointmentFilters');
        $request->setGlobal('get', ['service' => ' GUIDANCE ', 'status' => 'CONFIRMED', 'sort' => 'DATE_DESC', 'page' => '2', 'per_page' => '20']);
        self::assertSame(['service' => 'guidance', 'status' => 'confirmed', 'sort' => 'date_desc', 'page' => 2, 'per_page' => 20], $method->invoke($controller));
        $request->setGlobal('get', ['service' => [], 'status' => 'other', 'sort' => [], 'page' => ['2'], 'per_page' => '999']);
        self::assertSame(['service' => 'all', 'status' => 'all', 'sort' => 'upcoming', 'page' => 1, 'per_page' => 25], $method->invoke($controller));
        $request->setGlobal('get', []);
    }
}

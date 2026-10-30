<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AppointmentController;
use App\Controllers\ClinicController;
use App\Libraries\TablePage;
use App\Models\AppointmentModel;
use App\Models\ClinicEncounterModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\View\View;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Real counted/limited SQL on a private database, never application migrations or data. */
final class StaffListPaginationTest extends CIUnitTestCase
{
    private BaseConnection $fixture;
    private array $connections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connections = (new ReflectionProperty(Database::class, 'instances'))->getValue();
        $this->fixture = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => '', 'DBDebug' => true], false);
        $this->fixture->initialize();
        $this->fixture->connID->createFunction('CONCAT_WS', static fn ($separator, ...$parts) => implode($separator, array_filter($parts, static fn ($v) => $v !== null)), -1);
        (new ReflectionProperty(Database::class, 'instances'))->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, kind TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'employee_profiles' => 'person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'department_staff' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, unit TEXT',
            'clinic_encounters' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, person_id INTEGER, attending_user_id INTEGER, status TEXT, started_at TEXT, deleted_at TEXT',
            'appointments' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, person_id INTEGER, provider_user_id INTEGER, staff_assignment_id INTEGER, unit TEXT, status TEXT, local_date TEXT, starts_at TEXT, created_at TEXT, deleted_at TEXT',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
        $this->fixture->table('tenants')->insert(['id' => 1, 'is_active' => 1]);
        foreach ([1 => 1, 2 => 2, 3 => 1] as $id => $tenant) {
            $this->fixture->table('people')->insert(['id' => $id, 'tenant_id' => $tenant,
                'first_name' => 'Fixture', 'last_name' => 'Patient', 'kind' => 'employee', 'mis_id' => 'P' . $id,
                'deleted_at' => $id === 3 ? '2026-01-01' : null]);
        }
        $this->fixture->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'person_id' => 1,
            'username' => 'staff', 'role' => 'clinic_staff', 'status' => 'active']);
        CurrentTenant::set(1);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('renderer');
        Services::resetSingle('response');
        AuthenticatedUser::clear();
        (new ReflectionProperty(Database::class, 'instances'))->setValue(null, $this->connections);
        $this->fixture->close();
        parent::tearDown();
    }

    private function encounter(array $overrides = []): int
    {
        $this->fixture->table('clinic_encounters')->insert($overrides + ['tenant_id' => 1, 'person_id' => 1,
            'attending_user_id' => 1, 'status' => 'completed', 'started_at' => '2026-10-20 09:00:00']);
        return (int) $this->fixture->insertID();
    }

    private function appointment(array $overrides = []): int
    {
        $this->fixture->table('appointments')->insert($overrides + ['tenant_id' => 1, 'person_id' => 1,
            'unit' => 'clinic', 'status' => 'confirmed', 'local_date' => '2026-10-20',
            'starts_at' => '2026-10-20 09:00:00', 'created_at' => '2026-10-01 09:00:00']);
        return (int) $this->fixture->insertID();
    }

    public function testEncountersBeyondFiftyAreCompleteStableFilteredAndTenantScoped(): void
    {
        $ids = [];
        for ($i = 0; $i < 61; ++$i) $ids[] = $this->encounter();
        $this->encounter(['status' => 'in_progress']);
        $this->encounter(['started_at' => '2026-10-19 09:00:00']);
        $this->encounter(['tenant_id' => 2, 'person_id' => 2]);
        $this->encounter(['person_id' => 2]); // Invalid cross-tenant patient link must not join.
        $this->encounter(['person_id' => 3]); // Deleted patient.
        $this->encounter(['deleted_at' => '2026-10-21']);
        $model = new ClinicEncounterModel($this->fixture);
        $seen = [];
        foreach ([1, 2, 3, 4] as $number) {
            $result = $model->getListPage(TablePage::forList(['page' => $number, 'per_page' => 20]), 'completed', '2026-10-20');
            $this->assertSame(63, $result['pagination']['total']);
            $this->assertSame(61, $result['pagination']['filtered']);
            $this->assertCount($number === 4 ? 1 : 20, $result['encounters']);
            $seen = array_merge($seen, array_map('intval', array_column($result['encounters'], 'id')));
        }
        $this->assertSame(array_reverse($ids), $seen);
        $last = $model->getListPage(TablePage::forList(['page' => 1000000, 'per_page' => 20]), 'completed', '2026-10-20');
        $this->assertSame(4, $last['pagination']['page']);
        $this->assertSame(61, $last['pagination']['from']);
        $this->assertSame(61, $last['pagination']['to']);
        $empty = $model->getListPage(TablePage::forList(['page' => 100]), 'referred');
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 63, 'filtered' => 0, 'pages' => 1, 'from' => 0, 'to' => 0], $empty['pagination']);
    }

    public function testRequestsAreDateIndependentAndNeverConsumeSchedulePages(): void
    {
        foreach (['clinic', 'guidance'] as $unit) {
            $scheduled = $pending = [];
            for ($i = 0; $i < 61; ++$i) {
                $pending[] = $this->appointment(['unit' => $unit, 'status' => 'requested',
                    'local_date' => $i % 2 ? '2026-10-20' : '2026-11-01']);
                $scheduled[] = $this->appointment(['unit' => $unit]);
            }
            $this->appointment(['unit' => $unit, 'local_date' => '2026-10-19']);
            foreach (['requested', 'confirmed'] as $status) {
                $this->appointment(['unit' => $unit, 'status' => $status, 'tenant_id' => 2, 'person_id' => 2]);
                $this->appointment(['unit' => $unit, 'status' => $status, 'person_id' => 2]);
                $this->appointment(['unit' => $unit, 'status' => $status, 'deleted_at' => '2026-10-21']);
            }
            $model = new AppointmentModel($this->fixture);
            $seenPending = $seenScheduled = [];
            foreach ([1, 2, 3, 4] as $number) {
                $page = TablePage::forList(['page' => $number, 'per_page' => 20]);
                $requests = $model->getPendingRequestsPage($unit, $page);
                $calendar = $model->getScheduledPage($unit, '2026-10-20', $page);
                foreach ([$requests, $calendar] as $result) {
                    $this->assertSame(61, $result['pagination']['filtered']);
                    $this->assertSame(61, $result['pagination']['total']);
                }
                $this->assertCount($number === 4 ? 1 : 20, $requests['requests']);
                $this->assertCount($number === 4 ? 1 : 20, $calendar['appointments']);
                $seenPending = array_merge($seenPending, array_map('intval', array_column($requests['requests'], 'id')));
                $seenScheduled = array_merge($seenScheduled, array_map('intval', array_column($calendar['appointments'], 'id')));
            }
            $this->assertSame($pending, $seenPending);
            $this->assertSame($scheduled, $seenScheduled);
            $last = $model->getScheduledPage($unit, '2026-10-20', TablePage::forList(['page' => 1000000]));
            $this->assertSame(3, $last['pagination']['page']);
            $this->assertCount(11, $last['appointments']);
            $empty = $model->getScheduledPage($unit, '2020-01-01', TablePage::forList(['page' => 100]));
            $this->assertSame(0, $empty['pagination']['from']);
            $this->assertSame(0, $empty['pagination']['to']);
            $this->assertSame([], $empty['appointments']);
        }
    }

    private function signIn(string $role = 'clinic_staff'): void
    {
        $this->fixture->table('users')->where('id', 1)->update(['role' => $role]);
        session()->set(['tenant_id' => 1, 'user' => ['id' => 1, 'tenant_id' => 1, 'logged_in' => true],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
    }

    private function invoke(string $type, array $query)
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getGet')->willReturnCallback(static fn ($key = null) => $key === null ? $query : ($query[$key] ?? null));
        $class = $type === 'encounters' ? ClinicController::class : AppointmentController::class;
        $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach (['request' => $request, 'response' => service('response')] as $property => $value) {
            (new ReflectionProperty($class, $property))->setValue($controller, $value);
        }
        if ($type === 'encounters') {
            (new ReflectionProperty($class, 'encounterModel'))->setValue($controller, new ClinicEncounterModel($this->fixture));
            return $controller->encounters();
        }
        (new ReflectionProperty($class, 'appointmentModel'))->setValue($controller, new AppointmentModel($this->fixture));
        (new ReflectionProperty($class, 'appointmentService'))->setValue($controller, new \App\Services\Scheduling\AppointmentService());
        return $controller->index();
    }

    public function testControllersPassCountedMetadataAndIndependentClampedPages(): void
    {
        $this->signIn();
        for ($i = 0; $i < 61; ++$i) {
            $this->encounter();
            $this->appointment();
            $this->appointment(['status' => 'requested']);
        }
        $data = [];
        $renderer = $this->createMock(View::class);
        $renderer->method('setData')->willReturnCallback(static function ($incoming) use (&$data, $renderer) { $data = $incoming; return $renderer; });
        $renderer->method('render')->willReturn('rendered');
        Services::injectMock('renderer', $renderer);
        $this->assertSame('rendered', $this->invoke('encounters', ['page' => 3, 'per_page' => 20, 'status' => 'completed', 'date' => '2026-10-20']));
        $this->assertCount(20, $data['encounters']);
        $this->assertSame(41, $data['pagination']['from']);
        $this->assertSame('completed', $data['status']);
        $this->assertSame('rendered', $this->invoke('appointments', ['pending_page' => 2, 'scheduled_page' => 999, 'per_page' => 20, 'date' => '2026-10-20']));
        $this->assertSame(2, $data['pendingPagination']['page']);
        $this->assertSame(4, $data['scheduledPagination']['page']);
        $this->assertCount(20, $data['requests']);
        $this->assertCount(1, $data['appointments']);
        $this->assertSame('2026-10-20', $data['date']);
    }

    public function testAppointmentControllerStillDeniesNonDepartmentRoles(): void
    {
        foreach (['student', 'counsellor'] as $role) {
            $this->signIn($role);
            $response = $this->invoke('appointments', ['date' => '2026-10-20']);
            $this->assertSame(403, $response->getStatusCode());
        }
    }

    private function renderContent(string $view, array $data): string
    {
        helper(['form', 'url']);
        $renderer = new class(config('View'), APPPATH . 'Views') extends View {
            public function render(string $view, ?array $options = null, ?bool $saveData = null): string
            {
                // Exercise the real list/partial templates, not unrelated application chrome.
                return $view === 'layouts/app' ? $this->renderSection('content') : parent::render($view, $options, $saveData);
            }
        };
        Services::injectMock('renderer', $renderer);
        return view($view, $data, ['saveData' => false]);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new \DOMXPath($document);
    }

    public function testAppointmentFootersPreserveOtherCursorDateSizeAndLiveRegions(): void
    {
        foreach (['clinic', 'guidance'] as $unit) {
            $html = $this->renderContent('appointments/index', [
                'unit' => $unit, 'date' => '2026-10-20', 'appointments' => [], 'requests' => [],
                'pendingPagination' => TablePage::forList(['page' => 2, 'per_page' => 20])->metadata(61, 61),
                'scheduledPagination' => TablePage::forList(['page' => 3, 'per_page' => 20])->metadata(81, 81),
            ]);
            $this->assertStringContainsString('Showing 21–40 of 61', $html);
            $this->assertStringContainsString('Showing 41–60 of 81', $html);
            $this->assertStringContainsString('badge bg-light text-muted border">61</span>', $html);
                        $this->assertStringContainsString('data-datepicker-counts-url=', $html);
                        $this->assertStringContainsString('/' . $unit . '/appointments/calendar-counts', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
            $xpath = $this->xpath($html);
            foreach (['requests' => ['pending_page', 'scheduled_page', '3'], 'schedule' => ['scheduled_page', 'pending_page', '2']] as $region => [$own, $other, $cursor]) {
                $footers = $xpath->query('//*[@data-filter-region="' . $region . '"]//*[@data-table-pagination]');
                $this->assertCount(1, $footers);
                foreach ($xpath->query('.//a', $footers->item(0)) as $anchor) {
                    $this->assertTrue($anchor->hasAttribute('data-filter-link'));
                    $this->assertSame(parse_url(site_url('/' . $unit . '/appointments'), PHP_URL_PATH), parse_url($anchor->getAttribute('href'), PHP_URL_PATH));
                    parse_str((string) parse_url($anchor->getAttribute('href'), PHP_URL_QUERY), $query);
                    $this->assertSame('2026-10-20', $query['date']);
                    $this->assertSame('20', $query['per_page']);
                    $this->assertSame($cursor, $query[$other]);
                    $this->assertSame($anchor->getAttribute('data-table-page'), $query[$own]);
                }
            }
            foreach (['pending_page' => '2', 'scheduled_page' => '3'] as $name => $cursor) {
                $this->assertSame($cursor, $xpath->query('//form[@data-filter-form]/input[@name="' . $name . '"]')->item(0)->getAttribute('value'));
            }
            $this->assertCount(4, $xpath->query('//select[@name="per_page"]/option'));
        }
    }

    public function testEncounterFooterPreservesFiltersAndEmptyState(): void
    {
        $html = $this->renderContent('clinic/encounters/index', [
            'encounters' => [], 'status' => 'completed', 'date' => '2026-10-20',
            'pagination' => TablePage::forList(['page' => 2, 'per_page' => 25])->metadata(90, 61),
        ]);
        $this->assertStringContainsString('Showing 26–50 of 61', $html);
        $xpath = $this->xpath($html);
        foreach ($xpath->query('//*[@data-table-pagination]//a') as $anchor) {
            parse_str((string) parse_url($anchor->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame('completed', $query['status']);
            $this->assertSame('2026-10-20', $query['date']);
            $this->assertSame('25', $query['per_page']);
            $this->assertSame($anchor->getAttribute('data-table-page'), $query['page']);
        }
        $empty = $this->renderContent('clinic/encounters/index', [
            'encounters' => [], 'status' => '', 'date' => '', 'pagination' => TablePage::forList([])->metadata(0, 0),
        ]);
        $this->assertStringContainsString('Showing 0–0 of 0', $empty);
        $this->assertCount(0, $this->xpath($empty)->query('//*[@data-table-pagination]//a'));
    }

    public function testControllersRejectInvalidPaginationBeforeReadingLists(): void
    {
        $this->signIn();
        foreach (['encounters' => 'page', 'appointments' => 'pending_page'] as $type => $param) {
            foreach ([[$param => []], [$param => 0], ['per_page' => 26]] as $query) {
                $response = $this->invoke($type, $query);
                $this->assertSame(422, $response->getStatusCode());
            }
        }
    }

    public static function invalidPages(): iterable
    {
        foreach ([[], '0', '-1', '1000001', '1.5', '<script>'] as $value) yield [[$value], 'page'];
        yield [[26], 'per_page'];
        yield [[[20]], 'per_page'];
        yield [[0], 'per_page'];
    }

    #[DataProvider('invalidPages')]
    public function testInvalidPaginationIsRejected(array $wrapped, string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TablePage::forList([$key => $wrapped[0]]);
    }

    public function testAllSupportedPageSizesAndIndependentPageNames(): void
    {
        foreach ([20, 25, 50, 100] as $size) {
            $query = ['pending_page' => 2, 'scheduled_page' => 3, 'per_page' => $size];
            $this->assertSame(2, TablePage::forList($query, 'pending_page')->page);
            $this->assertSame(3, TablePage::forList($query, 'scheduled_page')->page);
            $this->assertSame($size, TablePage::forList($query)->perPage);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AppointmentController;
use App\Models\AvailabilityExceptionModel;
use App\Models\PersonModel;
use App\Models\ProviderAvailabilityModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\View\View;
use Config\Services;
use Psr\Log\NullLogger;
use Tests\Support\FreshDatabaseTestCase;

/** Real scheduling writes, exclusively in the guarded runner's disposable schema. */
final class AvailabilityWorkspaceFlowTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private AppointmentService $service;
    private int $tenant;
    private array $managers;

    protected function setUp(): void
    {
        parent::setUp();
        // Scheduling owns its transactions; an outer rollback would bypass its safety contract.
        $this->tenant = $this->tenant();
        $this->service = new AppointmentService();
        $this->managers = [
            'clinic' => $this->account('clinic_admin'),
            'guidance' => $this->account('guidance_admin'),
        ];
        $this->login($this->managers['clinic']);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
        Services::resetSingle('request');
        Services::resetSingle('response');
        parent::tearDown();
    }

    private function tenant(): int
    {
        self::assertTrue($this->db->table('tenants')->insert([
            'slug' => uniqid('availability_'), 'name' => 'Synthetic availability tenant', 'is_active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]));
        return (int) $this->db->insertID();
    }

    private function person(string $department = 'Administration', ?int $tenant = null, string $kind = 'employee'): int
    {
        $tenant ??= $this->tenant;
        CurrentTenant::set($tenant);
        $id = (new PersonModel())->insert(['tenant_id' => $tenant, 'kind' => $kind,
            'mis_id' => uniqid('availability_'), 'first_name' => 'Synthetic', 'last_name' => uniqid('Provider_')]);
        self::assertNotFalse($id);
        if ($kind === 'employee') {
            self::assertTrue($this->db->table('employee_profiles')->insert([
                'tenant_id' => $tenant, 'person_id' => $id, 'department' => $department,
                'position' => 'Synthetic practitioner', 'employment_status' => 'active',
                'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]));
        }
        return (int) $id;
    }

    private function account(string $role, ?int $tenant = null): array
    {
        $tenant ??= $this->tenant;
        $person = $this->person(tenant: $tenant, kind: $role === 'student' ? 'student' : 'employee');
        $id = (new UserModel())->insert(['tenant_id' => $tenant, 'person_id' => $person,
            'username' => uniqid('availability_user_'), 'role' => $role, 'status' => 'active']);
        self::assertNotFalse($id);
        return ['id' => (int) $id, 'person_id' => $person, 'tenant_id' => $tenant, 'role' => $role, 'logged_in' => true];
    }

    private function login(array $identity): array
    {
        AuthenticatedUser::clear();
        session()->set(['user' => $identity, 'tenant_id' => $identity['tenant_id'], '_auth' => [
            'started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id(),
        ]]);
        $actor = (new AuthenticatedUser())->current();
        self::assertNotNull($actor, 'Synthetic identity must pass fresh authentication, not fail with a stale-session 401.');
        self::assertSame($identity['id'], $actor['id']);
        return $actor;
    }

    private function enroll(string $unit = 'clinic', ?array $manager = null): int
    {
        $manager ??= $this->managers[$unit];
        $person = $this->person($unit === 'clinic' ? 'Health Services' : 'Guidance', $manager['tenant_id']);
        $actor = $this->login($manager);
        self::assertSame(['created' => 1, 'skipped' => 0], $this->service->enrollDepartmentStaff($unit, [$person], $actor));
        $row = $this->db->table('department_staff')->where('tenant_id', $manager['tenant_id'])->where('person_id', $person)->where('unit', $unit)->get()->getRowArray();
        self::assertNotNull($row);
        self::assertContains((int) $row['id'], array_column($this->service->providers($unit), 'id'));
        return (int) $row['id'];
    }

    private function weekly(int $id, int $day = 1): array
    {
        return ['staff_assignment_id' => $id, 'day_of_week' => $day, 'start_time' => '08:00',
            'end_time' => '12:00', 'slot_duration_minutes' => 30, 'is_active' => 1];
    }

    private function template(array $ids): array
    {
        return ['staff_assignment_ids' => $ids, 'days' => ['Monday', 'Tuesday'],
            'start_time' => '08:00', 'end_time' => '17:00', 'slot_duration_minutes' => 30];
    }

    private function exception(int $id): array
    {
        return ['id' => 0, 'staff_assignment_id' => $id,
            'exception_date' => SchedulingClock::now()->modify('next monday')->format('Y-m-d'),
            'exception_type' => 'leave', 'reason' => 'Synthetic leave'];
    }

    private function rows(string $table): array
    {
        return $this->db->table($table)->orderBy('id')->get()->getResultArray();
    }

    private function reject(callable $operation, string $class = \InvalidArgumentException::class, int $code = 0): void
    {
        try { $operation(); }
        catch (\Exception $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame($code, $e->getCode(), $e->getMessage());
            return;
        }
        self::fail('Expected availability operation to be rejected.');
    }

    private function controller(array $get = [], array $post = []): AppointmentController
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod($post === [] ? 'GET' : 'POST');
        $request->setGlobal('get', $get);
        $request->setGlobal('post', $post);
        $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response(config('App')));
        $controller = new AppointmentController();
        $controller->initController($request, service('response'), new NullLogger());
        return $controller;
    }

    public function testManagersCanReadAndWriteOnlyTheirOwnDepartment(): void
    {
        foreach (['clinic', 'guidance'] as $unit) {
            $id = $this->enroll($unit);
            $actor = $this->login($this->managers[$unit]);
            $row = $this->service->saveAvailability($unit, $this->weekly($id), $actor);
            self::assertSame($id, (int) $row['staff_assignment_id']);
            self::assertCount(1, $this->service->getAvailability($unit, $actor));
            self::assertCount(1, $this->service->departmentStaff($unit, $actor));
            $other = $unit === 'clinic' ? 'guidance' : 'clinic';
            $this->reject(fn () => $this->service->getAvailability($other, $actor), \DomainException::class, 403);
            self::assertSame(403, $this->controller()->availability($other)->getStatusCode());
        }
    }

    public function testNonManagersCannotUseAnyAvailabilityManagementEntryPoint(): void
    {
        $id = $this->enroll();
        $before = $this->rows('provider_availability');
        foreach (['clinic_staff', 'counsellor', 'student', 'employee'] as $role) {
            $actor = $this->login($this->account($role));
            foreach (['clinic', 'guidance'] as $unit) {
                foreach ([
                    fn () => $this->service->getAvailability($unit, $actor),
                    fn () => $this->service->departmentStaff($unit, $actor),
                    fn () => $this->service->enrollDepartmentStaff($unit, [$actor['person_id']], $actor),
                    fn () => $this->service->saveAvailability($unit, $this->weekly($id), $actor),
                    fn () => $this->service->applyDepartmentTemplate($unit, $this->template([$id]), $actor),
                    fn () => $this->service->getAvailabilityExceptions($unit, $actor),
                    fn () => $this->service->saveAvailabilityException($unit, $this->exception($id), $actor),
                    fn () => $this->service->saveAvailabilityException($unit, $this->exception($id), $actor, true),
                ] as $operation) $this->reject($operation, \DomainException::class, 403);
                self::assertSame(403, $this->controller()->availability($unit)->getStatusCode());
                $response = $this->controller(post: $this->weekly($id))->availabilityStore($unit);
                self::assertSame(403, $response->getStatusCode());
                self::assertFalse(json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)['success']);
            }
        }
        self::assertSame($before, $this->rows('provider_availability'));
    }

    public function testForgedSessionPrivilegesCannotSurvivePersistedRoleDowngrade(): void
    {
        $id = $this->enroll();
        $actor = $this->login($this->managers['clinic']);
        self::assertTrue((new UserModel())->update($actor['id'], ['role' => 'clinic_staff']));
        $this->reject(fn () => $this->service->saveAvailability('clinic', $this->weekly($id), $actor), \DomainException::class, 403);
        self::assertSame(403, $this->controller(post: $this->weekly($id))->availabilityStore('clinic')->getStatusCode());
        self::assertSame('clinic_staff', (new AuthenticatedUser())->current()['role']);
    }

    public function testForeignTenantAndOppositeUnitAssignmentsCannotBeWrittenOrListed(): void
    {
        $own = $this->enroll();
        $opposite = $this->enroll('guidance');
        $foreignManager = $this->account('clinic_admin', $this->tenant());
        $foreign = $this->enroll(manager: $foreignManager);
        $foreignActor = $this->login($foreignManager);
        $this->service->saveAvailability('clinic', $this->weekly($foreign), $foreignActor);
        $this->service->saveAvailabilityException('clinic', $this->exception($foreign), $foreignActor);
        $actor = $this->login($this->managers['clinic']);
        $before = $this->rows('provider_availability');
        $exceptions = $this->rows('availability_exceptions');
        foreach ([$opposite, $foreign] as $id) {
            $this->reject(fn () => $this->service->saveAvailability('clinic', $this->weekly($id), $actor));
            $this->reject(fn () => $this->service->applyDepartmentTemplate('clinic', $this->template([$own, $id]), $actor));
            $this->reject(fn () => $this->service->saveAvailabilityException('clinic', $this->exception($id), $actor));
            $this->reject(fn () => $this->service->staffAssignment('clinic', $id));
        }
        self::assertSame([$own], array_column($this->service->providers('clinic'), 'id'));
        self::assertSame([], $this->service->getAvailability('clinic', $actor));
        self::assertSame([], $this->service->getAvailabilityExceptions('clinic', $actor));
        self::assertSame($before, $this->rows('provider_availability'));
        self::assertSame($exceptions, $this->rows('availability_exceptions'));
    }

    public function testTemplateOnlyFillsSelectedMissingDaysAndPreservesInactiveSplitShifts(): void
    {
        $first = $this->enroll();
        $second = $this->enroll();
        $unselected = $this->enroll();
        $actor = $this->login($this->managers['clinic']);
        foreach ([['08:00:00', '09:00:00', 0], ['10:00:00', '11:00:00', 1]] as [$start, $end, $active]) {
            self::assertNotFalse((new ProviderAvailabilityModel())->insert([
                'tenant_id' => $this->tenant, 'unit' => 'clinic', 'staff_assignment_id' => $first,
                'provider_user_id' => null, 'day_of_week' => 1, 'start_time' => $start, 'end_time' => $end,
                'slot_duration_minutes' => 15, 'is_active' => $active,
            ]));
        }
        $before = $this->rows('provider_availability');
        $payload = $this->template([$first, $second]);
        self::assertSame(['created' => 3, 'skipped' => 1], $this->service->applyDepartmentTemplate('clinic', $payload, $actor));
        $after = $this->rows('provider_availability');
        self::assertSame($before, array_slice($after, 0, count($before)));
        self::assertNotContains($unselected, array_map('intval', array_column($after, 'staff_assignment_id')));
        self::assertSame(['created' => 0, 'skipped' => 4], $this->service->applyDepartmentTemplate('clinic', $payload, $actor));
        $this->reject(fn () => $this->service->saveAvailability('clinic', $this->weekly($first), $actor), \RuntimeException::class, 409);
        self::assertSame($after, $this->rows('provider_availability'));
    }

    public function testExceptionSnapshotsProtectCreateEditAndRemoveWithoutChangingAppointments(): void
    {
        $id = $this->enroll();
        $actor = $this->login($this->managers['clinic']);
        $data = $this->exception($id);
        $this->service->saveAvailability('clinic', $this->weekly($id), $actor);
        $request = $this->service->requestAppointment('clinic', ['person_id' => $actor['person_id'],
            'date' => $data['exception_date'], 'preferred_time' => '08:00', 'type' => 'routine'], $actor);
        $this->service->confirmAppointment('clinic', (int) $request['id'], [
            'staff_assignment_id' => $id, 'starts_at' => $data['exception_date'] . ' 08:00:00',
            'ends_at' => $data['exception_date'] . ' 08:30:00', 'version' => $request['version'],
        ], $actor);
        $appointments = $this->rows('appointments');
        $created = $this->service->saveAvailabilityException('clinic', $data, $actor);
        self::assertSame(1, (int) $created['is_unavailable']);
        self::assertSame([], $this->service->getAvailableSlots('clinic', $id, $data['exception_date']));
        $this->reject(fn () => $this->service->saveAvailabilityException('clinic', $data, $actor), \RuntimeException::class, 409);
        $edit = array_replace($data, ['id' => $created['id'], 'token' => AvailabilityExceptionModel::snapshotToken($created),
            'exception_type' => 'custom_hours', 'custom_start_time' => '10:00', 'custom_end_time' => '11:00', 'reason' => 'Reduced hours']);
        $updated = $this->service->saveAvailabilityException('clinic', $edit, $actor);
        self::assertSame('10:00:00', $updated['custom_start_time']);
        self::assertNotSame($edit['token'], AvailabilityExceptionModel::snapshotToken($updated));
        $before = $this->rows('availability_exceptions');
        foreach ([false, true] as $remove) {
            $this->reject(fn () => $this->service->saveAvailabilityException('clinic', $edit, $actor, $remove), \RuntimeException::class, 409);
            self::assertSame($before, $this->rows('availability_exceptions'));
            self::assertSame($appointments, $this->rows('appointments'));
        }
        $remove = array_replace($edit, ['token' => AvailabilityExceptionModel::snapshotToken($updated)]);
        $this->service->saveAvailabilityException('clinic', $remove, $actor, true);
        self::assertSame([], $this->service->getAvailabilityExceptions('clinic', $actor));
        $this->reject(fn () => $this->service->saveAvailabilityException('clinic', $remove, $actor, true), \RuntimeException::class, 409);
        self::assertSame($appointments, $this->rows('appointments'));
        self::assertSame('confirmed', $this->service->appointment('clinic', (int) $request['id'])['status']);
    }

    public function testControllerRendersRealWorkspaceWithOnlyAuthorizedProviders(): void
    {
        $own = $this->enroll();
        $other = $this->enroll('guidance');
        $this->login($this->managers['clinic']);
        // Keep real view/partials and section handling, but omit unrelated shell queries.
        Services::injectMock('renderer', new class(config('View'), APPPATH . 'Views/') extends View {
            public function render(string $view, ?array $options = null, ?bool $saveData = null): string
            {
                return $view === 'layouts/app' ? $this->renderSection('content') : parent::render($view, $options, $saveData);
            }
        });
        $before = $this->rows('provider_availability');
        $controller = $this->controller(['provider' => (string) $other, 'section' => 'weekly']);
        $html = $controller->availability('clinic');
        self::assertIsString($html);
        self::assertStringContainsString('id="availability-workspace"', $html);
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING));
        $options = (new \DOMXPath($document))->query('//select[@id="workspaceProvider"]/option');
        self::assertNotFalse($options);
        $values = [];
        foreach ($options as $option) $values[] = $option->getAttribute('value');
        self::assertSame(['', (string) $own], $values);
        self::assertStringContainsString('name="return_provider" value=""', $html);
        $cacheControl = array_map('trim', explode(',', service('response')->getHeaderLine('Cache-Control')));
        self::assertContains('private', $cacheControl);
        self::assertContains('no-store', $cacheControl);
        self::assertSame($before, $this->rows('provider_availability'));
    }

    public function testControllerJsonSaveReturnsContextButNavigationCannotSelectWriteTarget(): void
    {
        $id = $this->enroll();
        $unselected = $this->enroll();
        $this->login($this->managers['clinic']);
        $response = $this->controller(post: $this->weekly($id, 2) + [
            'return_q' => 'Synthetic', 'return_provider' => (string) $unselected,
            'return_day' => '6', 'return_section' => 'weekly',
        ])->availabilityStore('clinic');
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('hash', $body['csrf']);
        self::assertSame(parse_url(site_url('/clinic/appointments/availability'), PHP_URL_PATH), parse_url($body['redirect'], PHP_URL_PATH));
        parse_str((string) parse_url($body['redirect'], PHP_URL_QUERY), $query);
        self::assertSame(['q' => 'Synthetic', 'provider' => (string) $id, 'day' => '2', 'section' => 'weekly'], $query);
        $rows = $this->service->getAvailability('clinic', (new AuthenticatedUser())->current());
        self::assertCount(1, $rows);
        self::assertSame($id, (int) $rows[0]['staff_assignment_id']);
        self::assertSame(2, (int) $rows[0]['day_of_week']);
    }
}

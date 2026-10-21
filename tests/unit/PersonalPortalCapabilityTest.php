<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AppointmentModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\Clinic\PatientHistoryService;
use App\Services\CurrentTenant;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\SchedulingClock;
use Tests\Support\FreshDatabaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class PersonalPortalCapabilityTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private array $fixtures = [];
    private int $studentId;
    private int $staffId;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
        $this->studentId = $this->user('student');
        $this->staffId = $this->user('clinic_staff');
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        foreach ($this->fixtures as $fixture) {
            if ($fixture['type'] === 'appointment') (new AppointmentModel())->delete($fixture['id']);
            elseif ($fixture['type'] === 'user') (new UserModel())->delete($fixture['id']);
            elseif ($fixture['type'] === 'person') (new PersonModel())->delete($fixture['id']);
        }
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function user(string $role, int $tenant = 1): int
    {
        $code = uniqid('portal_cap_');
        $person = (int) (new PersonModel())->insert(['tenant_id' => $tenant, 'kind' => $role === 'student' ? 'student' : 'employee',
            'mis_id' => $code, 'first_name' => 'Portal', 'last_name' => 'Fixture']);
        $this->fixtures[] = ['type' => 'person', 'id' => $person];
        $id = (int) (new UserModel())->insert(['tenant_id' => $tenant, 'person_id' => $person,
            'username' => $code, 'role' => $role, 'status' => 'active']);
        $this->fixtures[] = ['type' => 'user', 'id' => $id];
        return $id;
    }

    private function actor(int $id): array
    {
        return (new UserModel())->scoped()->find($id);
    }

    private function revoke(string $role, string $permission): void
    {
        $grant = $this->db->table('role_permissions rp')->select('rp.permission_id')
            ->join('permissions p', 'p.id=rp.permission_id')->where('rp.role', $role)->where('p.code', $permission)->get()->getRowArray();
        self::assertNotNull($grant);
        $this->db->table('role_permissions')->where('role', $role)->where('permission_id', $grant['permission_id'])->delete();
    }

    private function rejected(int $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected personal portal capability rejection.');
        } catch (\DomainException $error) {
            self::assertSame($code, $error->getCode());
        }
    }

    public function testFreshSelfServicePermissionsAndLinkedPersonAreRequiredForRequestsAndCancellation(): void
    {
        $service = new AppointmentService();
        $actor = $this->actor($this->studentId);
        $date = SchedulingClock::now()->modify('+7 days')->format('Y-m-d');
        $request = $service->requestAppointment('clinic', ['date' => $date, 'preferred_time' => '09:00',
            'type' => 'routine', 'reason' => 'Fixture'], $actor, true);
        $this->fixtures[] = ['type' => 'appointment', 'id' => (int) $request['id']];
        self::assertSame($this->studentId, (int) $request['booked_by_user_id']);
        $this->revoke('student', 'portal.appointments.create');
        $this->rejected(403, fn () => $service->cancelAppointment((int) $request['id'], 'Fixture cancellation', 'clinic', $actor, true));
    }

    public function testChangedLinkedPersonCannotImpersonateSelfService(): void
    {
        $service = new AppointmentService();
        $actor = $this->actor($this->studentId);
        $date = SchedulingClock::now()->modify('+7 days')->format('Y-m-d');
        $actor['person_id'] = (int) $this->actor($this->staffId)['person_id'];
        $this->rejected(403, fn () => $service->requestAppointment('clinic', ['date' => $date, 'preferred_time' => '09:00',
            'type' => 'routine', 'person_id' => $actor['person_id']], $actor, true));
    }

    public function testClinicPatientChartDoesNotQueryAppointmentsWhenNotRequested(): void
    {
        $service = new PatientHistoryService();
        $id = (int) $this->actor($this->studentId)['person_id'];
        $chart = $service->getPatientChart($id, false);
        self::assertSame([], $chart['appointments']);
        self::assertArrayHasKey('student_profile', $chart);
    }
}

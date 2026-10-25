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

    public function testPortalAppointmentFiltersSortAndOwnership(): void
    {
        $personId = (int) $this->actor($this->studentId)['person_id'];
        $otherPersonId = (int) $this->actor($this->staffId)['person_id'];
        $appointment = function (string $unit, string $status, string $date) use ($personId): int {
            $start = $date . ' 09:00:00';
            $id = (int) (new AppointmentModel())->insert([
                'tenant_id' => 1,
                'unit' => $unit,
                'person_id' => $personId,
                'starts_at' => $start,
                'ends_at' => date('Y-m-d H:i:s', strtotime($start . ' +30 minutes')),
                'local_date' => $date,
                'status' => $status,
                'type' => $unit === 'clinic' ? 'routine' : 'initial',
                'reason' => 'Portal filter fixture ' . $status,
                'version' => 1,
            ]);
            $this->fixtures[] = ['type' => 'appointment', 'id' => $id];
            return $id;
        };

        $completed = $appointment('clinic', 'completed', date('Y-m-d', strtotime('-5 days')));
        $cancelled = $appointment('guidance', 'cancelled', date('Y-m-d', strtotime('-2 days')));
        $noShow = $appointment('clinic', 'no_show', date('Y-m-d', strtotime('-1 day')));
        $confirmed = $appointment('guidance', 'confirmed', date('Y-m-d', strtotime('+1 day')));
        $requested = $appointment('clinic', 'requested', date('Y-m-d', strtotime('+3 days')));

        $service = new PatientHistoryService();
        $ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'id'));

        self::assertSame([$confirmed, $requested, $noShow, $cancelled, $completed],
            $ids($service->getPortalAppointments($personId, ['sort' => 'upcoming'])));
        self::assertSame([$completed, $cancelled, $noShow, $confirmed, $requested],
            $ids($service->getPortalAppointments($personId, ['sort' => 'date_asc'])));
        self::assertSame([$requested, $confirmed, $noShow, $cancelled, $completed],
            $ids($service->getPortalAppointments($personId, ['sort' => 'date_desc'])));
        self::assertSame([$requested, $noShow, $completed],
            $ids($service->getPortalAppointments($personId, ['service' => 'clinic'])));
        self::assertSame([$confirmed, $requested],
            $ids($service->getPortalAppointments($personId, ['status' => 'active'])));
        self::assertSame([$completed],
            $ids($service->getPortalAppointments($personId, ['status' => 'completed'])));
        self::assertSame([$confirmed, $requested, $noShow, $cancelled, $completed],
            $ids($service->getPortalAppointments($personId, ['status' => 'bogus'])));

        self::assertSame($requested, (int) $service->getPortalAppointment($personId, $requested)['id']);
        self::assertNull($service->getPortalAppointment($otherPersonId, $requested));
        self::assertNull($service->getPortalAppointment($personId, 999999));
    }
}

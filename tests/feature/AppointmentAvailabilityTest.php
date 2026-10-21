<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PersonModel;
use App\Models\ProviderAvailabilityModel;
use App\Models\UserModel;
use App\Services\CurrentTenant;
use App\Services\Scheduling\AppointmentService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\View\View;
use Config\Services;

final class AppointmentAvailabilityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\AppointmentFixtureCleanup;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private array $admin;
    private array $clinical;
    private array $guidanceAdmin;
    private array $counsellor;
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
        $this->admin = $this->user('clinic_admin');
        $this->clinical = $this->user('clinic_staff');
        $this->guidanceAdmin = $this->user('guidance_supervisor');
        $this->counsellor = $this->user('counsellor');
        $this->date = date('Y-m-d', strtotime('+7 days'));
    }

    protected function tearDown(): void
    {
        Services::resetSingle('renderer');
        $this->cleanupAppointmentFixtures();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function user(string $role, int $tenant = 1): array
    {
        $unique = uniqid('availability_', true);
        $person = (int) (new PersonModel())->insert(['tenant_id' => $tenant, 'kind' => 'employee',
            'mis_id' => $unique, 'first_name' => 'Availability', 'last_name' => 'Test']);
        $id = (int) (new UserModel())->insert(['tenant_id' => $tenant, 'person_id' => $person,
            'username' => $unique, 'role' => $role, 'status' => 'active']);
        $this->appointmentFixtureUsers[] = $id;
        $this->appointmentFixturePeople[] = $person;
        return (new UserModel())->find($id) + ['logged_in' => true, 'permissions' => [], 'name' => 'Availability Test'];
    }

    private function sessionFor(array $user): array
    {
        return ['user' => $user, 'tenant_id' => 1];
    }

    private function payload(?array $provider = null): array
    {
        return ['provider_user_id' => (string) ($provider ?? $this->clinical)['id'],
            'day_of_week' => date('w', strtotime($this->date)), 'start_time' => '09:00', 'end_time' => '11:00',
            'slot_duration_minutes' => '30', 'is_active' => '1'];
    }

    private function postAvailability(array $actor, array $data, string $unit = 'clinic')
    {
        $name = csrf_token();
        $token = csrf_hash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $token);
        Services::resetSingle('security');
        return $this->withSession($this->sessionFor($actor))->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post($unit . '/appointments/availability', $data + [$name => $token]);
    }

    public function testManagementRequiresActiveDepartmentManagerEvenForOwnSchedule(): void
    {
        foreach ([$this->clinical, $this->guidanceAdmin, $this->counsellor, $this->user('student')] as $actor) {
            $response = $this->postAvailability($actor, $this->payload());
            if (in_array($actor['role'], ['clinic_staff'], true)) {
                $response->assertStatus(403);
                $this->withSession($this->sessionFor($actor))->get('clinic/appointments/availability')->assertStatus(403);
            } else {
                $response->assertRedirectTo('/portal');
            }
        }
        (new UserModel())->update($this->admin['id'], ['status' => 'suspended']);
        $this->postAvailability($this->admin, $this->payload())->assertStatus(403);
    }

    public function testCreateUpdateDisableAndBothDepartmentsGenerateUsableSlots(): void
    {
        foreach ([['clinic', $this->admin, $this->clinical], ['guidance', $this->guidanceAdmin, $this->counsellor]] as [$unit, $manager, $provider]) {
            $response = $this->postAvailability($manager, $this->payload($provider), $unit);
            $response->assertStatus(200);
            $json = json_decode($response->getJSON(), true);
            $this->assertTrue($json['success']);
            $this->assertNotEmpty($json['csrf']['hash']);
            $this->assertSame(site_url('/' . $unit . '/appointments'), $json['redirect']);
            $service = new AppointmentService();
            $rows = array_values(array_filter($service->getAvailability($unit, $manager), fn (array $row): bool => (int) $row['provider_user_id'] === (int) $provider['id']));
            $this->assertCount(1, $rows);
            $id = (int) $rows[0]['id'];
            $this->assertSame('09:00:00', $rows[0]['start_time']);
            $slots = $service->getAvailableSlots($unit, (int) $provider['id'], $this->date);
            $this->assertCount(4, $slots);
            $this->assertSame('09:00', $slots[0]['slot_time']);
            $this->postAvailability($manager, array_replace($this->payload($provider), ['slot_duration_minutes' => '60']), $unit)->assertStatus(200);
            $this->assertCount(2, $service->getAvailableSlots($unit, (int) $provider['id'], $this->date));
            $this->postAvailability($manager, array_replace($this->payload($provider), ['is_active' => '0']), $unit)->assertStatus(200);
            $this->assertSame([], $service->getAvailableSlots($unit, (int) $provider['id'], $this->date));
            $this->assertSame($id, (int) (new ProviderAvailabilityModel())->scoped()->where('provider_user_id', $provider['id'])->first()['id']);
            $this->assertSame(1, (new ProviderAvailabilityModel())->scoped()->where('provider_user_id', $provider['id'])->countAllResults());
        }
    }

    public function testSuperadminCanConfigureButAdministratorsAreNeverClinicians(): void
    {
        $superadmin = $this->user('superadmin');
        $this->postAvailability($superadmin, $this->payload())->assertStatus(200);
        foreach ([$this->admin, $this->guidanceAdmin, $superadmin, $this->counsellor] as $provider) {
            $this->postAvailability($superadmin, $this->payload($provider))->assertStatus(422);
        }
        // Legacy availability rows must not turn a management-only role into a clinician.
        (new ProviderAvailabilityModel())->insert(['tenant_id' => 1, 'unit' => 'clinic',
            'provider_user_id' => $this->admin['id'], 'day_of_week' => (int) date('w'),
            'start_time' => '09:00:00', 'end_time' => '11:00:00', 'slot_duration_minutes' => 30, 'is_active' => 1]);
        $service = new AppointmentService();
        $this->assertNotContains((int) $this->admin['id'], array_column($service->providers('clinic'), 'id'));
        $this->assertContains((int) $this->clinical['id'], array_column($service->providers('clinic'), 'id'));
    }

    public function testValidationIsStrictAndCannotCreateInvalidWindows(): void
    {
        foreach ([['day_of_week' => '-1'], ['day_of_week' => '7'], ['day_of_week' => ''], ['day_of_week' => '1x'],
            ['start_time' => '9:00'], ['start_time' => '25:00'], ['end_time' => '08:00'], ['end_time' => '09:00'],
            ['end_time' => '09:15', 'slot_duration_minutes' => '30'], ['slot_duration_minutes' => '20'],
            ['slot_duration_minutes' => ''], ['is_active' => '2'], ['is_active' => ''], ['day_of_week' => ['1']]] as $bad) {
            $response = $this->postAvailability($this->admin, array_replace($this->payload(), $bad));
            $response->assertStatus(422);
            $this->assertFalse(json_decode($response->getJSON(), true)['success']);
        }
        $this->assertSame(0, (new ProviderAvailabilityModel())->scoped()->where('provider_user_id', $this->clinical['id'])->countAllResults());
        foreach (['0', '6'] as $day) {
            foreach (['15', '30', '45', '60'] as $duration) {
                $this->postAvailability($this->admin, array_replace($this->payload(), ['day_of_week' => $day, 'slot_duration_minutes' => $duration]))->assertStatus(200);
            }
        }
    }

    public function testMultipleExistingWindowsArePreservedAndRejectedInsteadOfCollapsed(): void
    {
        $this->postAvailability($this->admin, $this->payload())->assertStatus(200);
        (new ProviderAvailabilityModel())->insert(['tenant_id' => 1, 'unit' => 'clinic',
            'provider_user_id' => $this->clinical['id'], 'day_of_week' => (int) $this->payload()['day_of_week'],
            'start_time' => '13:00:00', 'end_time' => '15:00:00', 'slot_duration_minutes' => 30, 'is_active' => 1]);
        $service = new AppointmentService();
        $before = $service->getAvailability('clinic', $this->admin);
        $response = $this->postAvailability($this->admin, array_replace($this->payload(), ['start_time' => '10:00', 'end_time' => '14:00']));
        $response->assertStatus(409);
        $this->assertNotEmpty(json_decode($response->getJSON(), true)['csrf']['hash']);
        $this->assertSame($before, $service->getAvailability('clinic', $this->admin));
    }

    public function testUnavailableOrForeignTenantProvidersCannotBeConfiguredAndListsAreScoped(): void
    {
        foreach (['suspended', 'locked'] as $status) {
            (new UserModel())->update($this->clinical['id'], ['status' => $status]);
            $this->postAvailability($this->admin, $this->payload())->assertStatus(422);
        }
        (new UserModel())->update($this->clinical['id'], ['status' => 'active']);
        (new UserModel())->delete($this->clinical['id']);
        $this->postAvailability($this->admin, $this->payload())->assertStatus(422);
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('availability_tenant_'), 'name' => 'Availability Isolation',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $tenant = (int) $this->db->insertID();
        $this->appointmentFixtureTenants[] = $tenant;
        $foreign = $this->user('clinic_staff', $tenant);
        $this->postAvailability($this->admin, $this->payload($foreign))->assertStatus(422);
        (new ProviderAvailabilityModel())->insert(['tenant_id' => $tenant, 'unit' => 'clinic',
            'provider_user_id' => $foreign['id'], 'day_of_week' => 0, 'start_time' => '09:00:00',
            'end_time' => '11:00:00', 'slot_duration_minutes' => 30, 'is_active' => 1]);
        $service = new AppointmentService();
        $this->assertNotContains((int) $foreign['id'], array_column($service->providers('clinic'), 'id'));
        foreach ($service->getAvailability('clinic', $this->admin) as $row) {
            $this->assertSame(1, (int) $row['tenant_id']);
            $this->assertSame('clinic', $row['unit']);
        }
    }

    public function testModalControllerSuppliesAgreedDataWithoutRequiringFrontendFixture(): void
    {
        $this->postAvailability($this->admin, $this->payload())->assertStatus(200);
        $renderer = $this->createMock(View::class);
        $renderer->expects($this->once())->method('setData')->with($this->callback(function (array $data): bool {
            $this->assertSame('clinic', $data['unit']);
            $this->assertSame('Clinic Provider Availability', $data['title']);
            $this->assertSame(site_url('/clinic/appointments/availability'), $data['formAction']);
            $this->assertSame(site_url('/clinic/appointments'), $data['cancelUrl']);
            $this->assertContains((int) $this->clinical['id'], array_column($data['providers'], 'id'));
            $this->assertNotContains((int) $this->admin['id'], array_column($data['providers'], 'id'));
            $this->assertContains((int) $this->clinical['id'], array_map('intval', array_column($data['availability'], 'provider_user_id')));
            return true;
        }), 'raw')->willReturnSelf();
        $renderer->expects($this->once())->method('render')->with('appointments/availability', $this->anything(), $this->anything())->willReturn('availability modal contract');
        Services::injectMock('renderer', $renderer);
        $response = $this->withSession($this->sessionFor($this->admin))->withHeaders([])->get('clinic/appointments/availability');
        $response->assertStatus(200);
        $response->assertSee('availability modal contract');
    }

    public function testConfiguredAvailabilitySupportsRequestThenConfirmation(): void
    {
        $service = new AppointmentService();
        $this->assertSame([], $service->getAvailableSlots('clinic', (int) $this->clinical['id'], $this->date));
        $this->postAvailability($this->admin, $this->payload())->assertStatus(200);
        $request = $service->requestAppointment('clinic', ['date' => $this->date, 'preferred_time' => '09:00', 'type' => 'routine'], $this->clinical, true);
        $slot = $service->getAvailableSlots('clinic', (int) $this->clinical['id'], $this->date)[0];
        $confirmed = $service->confirmAppointment('clinic', (int) $request['id'], $slot + [
            'provider_user_id' => $this->clinical['id'], 'version' => $request['version']], $this->admin);
        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertFalse($service->getAvailableSlots('clinic', (int) $this->clinical['id'], $this->date)[0]['available']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppointmentModel;
use App\Models\PersonModel;
use App\Models\ProviderAvailabilityModel;
use App\Models\UserModel;
use App\Services\CurrentTenant;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

final class AppointmentRequestRoutesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\AppointmentFixtureCleanup;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private array $student;
    private array $staff;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
        $this->student = $this->user('student');
        $this->staff = $this->user('clinic_staff');
        (new ProviderAvailabilityModel())->insert(['tenant_id' => 1, 'provider_user_id' => $this->staff['id'],
            'unit' => 'clinic', 'day_of_week' => (int) date('w'), 'start_time' => '08:00:00',
            'end_time' => '17:00:00', 'slot_duration_minutes' => 30, 'is_active' => 1]);
    }

    protected function tearDown(): void
    {
        \Config\Services::resetSingle('renderer');
        $this->cleanupAppointmentFixtures();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function user(string $role): array
    {
        $unique = uniqid('route_request_', true);
        $person = (int) (new PersonModel())->insert(['tenant_id' => 1, 'kind' => 'employee', 'mis_id' => $unique,
            'first_name' => 'Route', 'last_name' => 'Patient']);
        $id = (int) (new UserModel())->insert(['tenant_id' => 1, 'person_id' => $person,
            'username' => $unique, 'role' => $role, 'status' => 'active']);
        $this->appointmentFixtureUsers[] = $id;
        $this->appointmentFixturePeople[] = $person;
        return (new UserModel())->find($id) + ['logged_in' => true, 'permissions' => [], 'name' => 'Route Patient'];
    }

    private function sessionFor(array $user): array
    {
        return ['user' => $user, 'tenant_id' => 1];
    }

    private function payload(string $unit = 'clinic'): array
    {
        return ['date' => date('Y-m-d', strtotime('+7 days')), 'preferred_time' => '09:00', 'type' => $unit === 'clinic' ? 'routine' : 'initial', 'reason' => 'Route request'];
    }

    private function ajaxPost(array $user, string $route, array $data)
    {
        $name = csrf_token();
        $token = csrf_hash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $token);
        \Config\Services::resetSingle('security');
        return $this->withSession($this->sessionFor($user))->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post($route, $data + [$name => $token]);
    }

    private function latest(array $user, string $unit = 'clinic'): array
    {
        return (new AppointmentModel())->scoped()->where('person_id', $user['person_id'])
            ->where('unit', $unit)->orderBy('id', 'DESC')->first();
    }

    public function testPersonalRequestEndpointsIgnoreSpoofedPatientAndReturnRequestMessage(): void
    {
        foreach ([$this->student, $this->staff] as $user) {
            foreach (['clinic', 'guidance'] as $unit) {
                $response = $this->ajaxPost($user, 'portal/appointments/' . $unit . '/store',
                    $this->payload($unit) + ['person_id' => 999999, 'provider_user_id' => 999999, 'status' => 'confirmed']);
                $response->assertStatus(200);
                $json = json_decode($response->getJSON(), true);
                $this->assertStringContainsString('requested', $json['message']);
                $this->assertSame(site_url('/portal'), $json['redirect']);
                $this->assertNotEmpty($json['csrf']['hash']);
                $appt = $this->latest($user, $unit);
                $this->assertSame('requested', $appt['status']);
                $this->assertNull($appt['provider_user_id']);
            }
        }
    }

    public function testPersonalValidationAndMissingPersonFailWithoutInsert(): void
    {
        $response = $this->ajaxPost($this->student, 'portal/appointments/clinic/store', []);
        $response->assertStatus(422);
        $this->assertFalse(json_decode($response->getJSON(), true)['success']);
        $user = $this->student;
        unset($user['person_id']);
        $this->ajaxPost($user, 'portal/appointments/clinic/store', $this->payload())->assertStatus(403);
        $this->ajaxPost($this->student, 'portal/appointments/clinic/store', array_replace($this->payload(), ['date' => ['bad']]))->assertStatus(422);
    }

    public function testStudentsCannotReachDepartmentManagementRoutes(): void
    {
        foreach (['clinic', 'guidance'] as $unit) {
            foreach (['', '/create', '/123/confirm'] as $suffix) {
                $this->withSession($this->sessionFor($this->student))->get($unit . '/appointments' . $suffix)->assertRedirectTo('/portal');
            }
            foreach (['/store', '/123/confirm', '/check-in/123', '/cancel/123'] as $suffix) {
                $this->ajaxPost($this->student, $unit . '/appointments' . $suffix, $this->payload($unit))->assertRedirectTo('/portal');
            }
        }
    }

    public function testPersonalCancellationRequiresOwnerAndMatchingUnit(): void
    {
        $this->ajaxPost($this->student, 'portal/appointments/clinic/store', $this->payload())->assertStatus(200);
        $appt = $this->latest($this->student);
        $reason = ['reason' => 'Patient requested cancellation'];
        $this->ajaxPost($this->staff, 'portal/appointments/clinic/cancel/' . $appt['id'], $reason)->assertStatus(403);
        $this->ajaxPost($this->student, 'portal/appointments/guidance/cancel/' . $appt['id'], $reason)->assertStatus(404);
        $this->ajaxPost($this->student, 'portal/appointments/clinic/cancel/' . $appt['id'], $reason)->assertStatus(200);
        $this->ajaxPost($this->student, 'portal/appointments/clinic/cancel/' . $appt['id'], $reason)->assertStatus(409);
    }

    public function testStaffRequestConfirmationAndStaleAjaxContract(): void
    {
        $this->ajaxPost($this->staff, 'clinic/appointments/store', $this->payload() + ['person_id' => $this->student['person_id']])->assertStatus(200);
        $appt = $this->latest($this->student);
        $data = ['provider_user_id' => $this->staff['id'], 'starts_at' => date('Y-m-d', strtotime('+7 days')) . 'T09:00',
            'ends_at' => date('Y-m-d', strtotime('+7 days')) . 'T09:30', 'version' => 1];
        $this->ajaxPost($this->staff, 'clinic/appointments/' . $appt['id'] . '/confirm', $data)->assertStatus(200);
        $response = $this->ajaxPost($this->staff, 'clinic/appointments/' . $appt['id'] . '/confirm', $data);
        $response->assertStatus(409);
        $json = json_decode($response->getJSON(), true);
        $this->assertFalse($json['success']);
        $this->assertNotEmpty($json['csrf']['hash']);
        $this->assertSame('confirmed', $this->latest($this->student)['status']);
    }

    public function testInvalidGridDurationAndStaleAvailabilityReturn422WhileConflictRemains409(): void
    {
        $this->ajaxPost($this->student, 'portal/appointments/clinic/store', $this->payload())->assertStatus(200);
        $appt = $this->latest($this->student);
        $date = $appt['local_date'];
        $route = 'clinic/appointments/' . $appt['id'] . '/confirm';
        foreach ([['09:05', '09:06'], ['09:05', '09:35'], ['09:00', '10:00']] as [$start, $end]) {
            $response = $this->ajaxPost($this->staff, $route, ['provider_user_id' => $this->staff['id'],
                'starts_at' => $date . 'T' . $start, 'ends_at' => $date . 'T' . $end, 'version' => 1]);
            $response->assertStatus(422);
            $json = json_decode($response->getJSON(), true);
            $this->assertFalse($json['success']);
            $this->assertNotEmpty($json['csrf']['hash']);
            $this->assertSame('requested', $this->latest($this->student)['status']);
        }
        $admin = $this->user('clinic_admin');
        $this->ajaxPost($admin, 'clinic/appointments/availability', ['provider_user_id' => $this->staff['id'],
            'day_of_week' => date('w', strtotime($date)), 'start_time' => '08:00', 'end_time' => '17:00',
            'slot_duration_minutes' => 60, 'is_active' => 1])->assertStatus(200);
        $data = ['provider_user_id' => $this->staff['id'], 'starts_at' => $date . 'T09:00', 'ends_at' => $date . 'T09:30', 'version' => 1];
        $this->ajaxPost($this->staff, $route, $data)->assertStatus(422);
        $this->assertSame(1, (int) $this->latest($this->student)['version']);
        $data['ends_at'] = $date . 'T10:00';
        $this->ajaxPost($this->staff, $route, $data)->assertStatus(200);
        $this->ajaxPost($this->student, 'portal/appointments/clinic/store', $this->payload())->assertStatus(200);
        $second = $this->latest($this->student);
        $this->ajaxPost($this->staff, 'clinic/appointments/' . $second['id'] . '/confirm', $data)->assertStatus(409);
    }

    public function testCheckInRejectsProviderRoleChangeAndInactivityWithConflictResponse(): void
    {
        foreach ([['role' => 'counsellor'], ['status' => 'suspended']] as $change) {
            $provider = $this->user('clinic_staff');
            $appt = (new \App\Services\Scheduling\AppointmentService())->bookAppointment([
                'unit' => 'clinic', 'person_id' => $this->student['person_id'], 'provider_user_id' => $provider['id'],
                'starts_at' => date('Y-m-d') . ' 09:00:00', 'ends_at' => date('Y-m-d') . ' 09:30:00',
            ]);
            (new UserModel())->update($provider['id'], $change);
            $response = $this->ajaxPost($this->staff, 'clinic/appointments/check-in/' . $appt['id'], []);
            $response->assertStatus(409);
            $json = json_decode($response->getJSON(), true);
            $this->assertFalse($json['success']);
            $this->assertNotEmpty($json['csrf']['hash']);
            $this->assertSame('scheduled', $this->latest($this->student)['status']);
            $this->assertSame(0, $this->db->table('queue_entries')->where('appointment_id', $appt['id'])->countAllResults());
        }
    }

    public function testPastRequestsAndConfirmationsAreRejectedWithoutChangingState(): void
    {
        $past = date('Y-m-d', strtotime('-1 day'));
        $response = $this->ajaxPost($this->student, 'portal/appointments/clinic/store', array_replace($this->payload(), ['date' => $past]));
        $response->assertStatus(422);
        $this->assertStringContainsString('past', json_decode($response->getJSON(), true)['message']);
        $this->ajaxPost($this->student, 'portal/appointments/clinic/store', $this->payload())->assertStatus(200);
        $appt = $this->latest($this->student);
        $this->ajaxPost($this->staff, 'clinic/appointments/' . $appt['id'] . '/confirm', [
            'provider_user_id' => $this->staff['id'], 'starts_at' => $past . 'T09:00', 'ends_at' => $past . 'T09:30', 'version' => 1,
        ])->assertStatus(422);
        $this->assertSame('requested', $this->latest($this->student)['status']);
        $this->assertSame(1, (int) $this->latest($this->student)['version']);
        $this->ajaxPost($this->student, 'portal/appointments/clinic/store', array_replace($this->payload(), [
            'date' => date('Y-m-d'), 'preferred_time' => '00:00',
        ]))->assertStatus(422);
    }

    public function testPersonalModalUsesRealLinkedActiveAccountAndAgreedRequestContract(): void
    {
        $renderer = $this->createMock(\CodeIgniter\View\View::class);
        $renderer->expects($this->once())->method('setData')->with($this->callback(function (array $data): bool {
            $this->assertSame('personal', $data['bookingMode']);
            $this->assertSame((int) $this->student['person_id'], (int) $data['person']['id']);
            $this->assertSame(site_url('/portal/appointments/clinic/store'), $data['formAction']);
            $this->assertSame(site_url('/portal'), $data['cancelUrl']);
            $this->assertArrayNotHasKey('providers', $data);
            return true;
        }), 'raw')->willReturnSelf();
        $renderer->expects($this->once())->method('render')->with('appointments/create', $this->anything(), $this->anything())->willReturn('personal request contract');
        \Config\Services::injectMock('renderer', $renderer);
        $this->withSession($this->sessionFor($this->student))->withHeaders([])->get('portal/appointments/clinic/create')->assertStatus(200);
    }

    public function testSlotsRequireMatchingDepartmentStaffAndEligibleProvider(): void
    {
        $query = 'api/appointments/slots?unit=clinic&provider_id=' . $this->staff['id'] . '&date=' . date('Y-m-d', strtotime('+7 days'));
        $this->withSession($this->sessionFor($this->student))->get($query)->assertStatus(403);
        $this->withSession($this->sessionFor($this->staff))->get($query)->assertStatus(200);
        $this->withSession($this->sessionFor($this->staff))->get(str_replace('unit=clinic', 'unit=guidance', $query))->assertStatus(403);
        $this->withSession($this->sessionFor($this->staff))->get(str_replace('provider_id=' . $this->staff['id'], 'provider_id=' . $this->student['id'], $query))->assertStatus(422);
    }
}

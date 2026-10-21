<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppointmentModel;
use App\Models\PersonModel;

use App\Models\UserModel;
use App\Services\CurrentTenant;
use App\Services\Scheduling\AppointmentService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

final class AppointmentCsrfTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\AppointmentFixtureCleanup;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private array $admin;
    private array $clinician;
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
        $this->admin = $this->user('superadmin');
        $this->clinician = $this->user('clinic_staff');
        $this->date = date('Y-m-d', strtotime('+7 days'));
    }

    protected function tearDown(): void
    {
        Services::resetSingle('security');
        Services::resetSingle('renderer');
        service('superglobals')->setCookieArray([]);
        $this->cleanupAppointmentFixtures();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function user(string $role): array
    {
        $unique = uniqid('csrf_appointment_', true);
        $personId = (int) (new PersonModel())->insert(['tenant_id' => 1, 'kind' => 'employee',
            'mis_id' => $unique, 'first_name' => 'CSRF', 'last_name' => 'Tester']);
        $id = (int) (new UserModel())->insert(['tenant_id' => 1, 'person_id' => $personId,
            'username' => $unique, 'role' => $role, 'status' => 'active']);
        $this->appointmentFixtureUsers[] = $id;
        $this->appointmentFixturePeople[] = $personId;
        return (new UserModel())->find($id) + ['logged_in' => true, 'permissions' => [], 'name' => 'CSRF Tester'];
    }

    private function requestPayload(string $unit = 'clinic'): array
    {
        return ['person_id' => $this->admin['person_id'], 'date' => $this->date,
            'preferred_time' => '09:00', 'type' => $unit === 'clinic' ? 'routine' : 'initial', 'reason' => 'CSRF request'];
    }

    private function tokenForNextRequest(?string $hash = null): string
    {
        $hash ??= service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
        // Simulate a fresh HTTP request so verification must restore the hash from the client cookie.
        Services::resetSingle('security');
        return $hash;
    }

    private function submit(string $route, array $data, bool $ajax = true)
    {
        return $this->withSession(['user' => $this->admin, 'tenant_id' => 1])
            ->withHeaders($ajax ? ['X-Requested-With' => 'XMLHttpRequest'] : [])->post($route, $data);
    }

    public function testEveryAppointmentPostRejectsMissingInvalidAndMalformedTokenWithoutMutation(): void
    {
        foreach (['clinic', 'guidance'] as $unit) {
            $appt = (new AppointmentService())->requestAppointment($unit, $this->requestPayload($unit), $this->admin, true);
            $id = (int) $appt['id'];
            $routes = [
                'portal/appointments/' . $unit . '/store' => $this->requestPayload($unit),
                'portal/appointments/' . $unit . '/cancel/' . $id => [],
                $unit . '/appointments/store' => $this->requestPayload($unit),
                $unit . '/appointments/' . $id . '/confirm' => ['provider_user_id' => $this->clinician['id'],
                    'starts_at' => $this->date . 'T09:00', 'ends_at' => $this->date . 'T09:30', 'version' => 1],
                $unit . '/appointments/availability' => ['provider_user_id' => $this->clinician['id'], 'day_of_week' => 0,
                    'start_time' => '09:00', 'end_time' => '11:00', 'slot_duration_minutes' => 30, 'is_active' => 1],
                $unit . '/appointments/cancel/' . $id => [],
                $unit . '/appointments/check-in/' . $id => [],
            ];
            $before = $this->db->table('appointments')->countAllResults();
            $beforeAvailability = $this->db->table('provider_availability')->countAllResults();
            foreach ($routes as $route => $data) {
                foreach ([null, str_repeat('0', 64), ['malformed']] as $badToken) {
                    $this->tokenForNextRequest();
                    $payload = $badToken === null ? $data : $data + [config('Security')->tokenName => $badToken];
                    $response = $this->submit($route, $payload);
                    $response->assertStatus(403);
                    $json = json_decode($response->getJSON(), true);
                    $this->assertFalse($json['success']);
                    $this->assertTrue($json['reload']);
                    $this->assertStringContainsString('Reload', $json['message']);
                    $this->assertSame(config('Security')->tokenName, $json['csrf']['name']);
                    $this->assertNotEmpty($json['csrf']['hash']);
                    $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
                    $this->assertSame('requested', (new AppointmentModel())->find($id)['status']);
                }
            }
            $this->assertSame($before, $this->db->table('appointments')->countAllResults());
            $this->assertSame($beforeAvailability, $this->db->table('provider_availability')->countAllResults());
        }
    }

    public function testPostedTokenWithoutMatchingCookieIsRejectedAndPlainFormFailureIs403(): void
    {
        service('superglobals')->setCookieArray([]);
        Services::resetSingle('security');
        $response = $this->submit('portal/appointments/clinic/store', $this->requestPayload() + [config('Security')->tokenName => bin2hex(random_bytes(32))]);
        $response->assertStatus(403);
        $this->tokenForNextRequest();
        $response = $this->submit('portal/appointments/clinic/store', $this->requestPayload(), false);
        $response->assertStatus(403);
        $response->assertSee('Reload this page');
        $this->assertFalse($response->isRedirect());
    }

    public function testValidationReturnsRotatedTokenThatWorksAndOldTokenIsRejected(): void
    {
        $token = $this->tokenForNextRequest();
        $response = $this->submit('portal/appointments/clinic/store', [config('Security')->tokenName => $token]);
        $response->assertStatus(422);
        $json = json_decode($response->getJSON(), true);
        $this->assertFalse($json['success']);
        $this->assertNotSame($token, $json['csrf']['hash']);
        $this->assertSame($json['csrf']['hash'], $response->response()->getCookie(config('Security')->cookieName)->getValue());
        $nextToken = $this->tokenForNextRequest($json['csrf']['hash']);
        $response = $this->submit('portal/appointments/clinic/store', $this->requestPayload() + [config('Security')->tokenName => $nextToken]);
        $response->assertStatus(200);
        $json = json_decode($response->getJSON(), true);
        $this->assertTrue($json['success']);
        $this->tokenForNextRequest($json['csrf']['hash']);
        $this->submit('portal/appointments/clinic/store', $this->requestPayload() + [config('Security')->tokenName => $nextToken])->assertStatus(403);
    }

    public function testDirectPostRedirectIncludesRotatedCookie(): void
    {
        $token = $this->tokenForNextRequest();
        $response = $this->submit('portal/appointments/clinic/store', $this->requestPayload() + [config('Security')->tokenName => $token], false);
        $response->assertRedirectTo('/portal');
        $cookie = $response->response()->getCookie(config('Security')->cookieName);
        $this->assertNotNull($cookie);
        $this->assertNotSame($token, $cookie->getValue());
    }

    public function testValidTokensReachConfirmationAvailabilityCancellationAndCheckIn(): void
    {
        $service = new AppointmentService();
        $token = $this->tokenForNextRequest();
        $this->submit('clinic/appointments/availability', [config('Security')->tokenName => $token,
            'provider_user_id' => $this->clinician['id'], 'day_of_week' => date('w', strtotime($this->date)),
            'start_time' => '09:00', 'end_time' => '11:00', 'slot_duration_minutes' => 30, 'is_active' => 1])->assertStatus(200);
        $appt = $service->requestAppointment('clinic', $this->requestPayload(), $this->admin, true);
        $token = $this->tokenForNextRequest();
        $response = $this->submit('clinic/appointments/' . $appt['id'] . '/confirm', [config('Security')->tokenName => $token,
            'provider_user_id' => $this->clinician['id'], 'starts_at' => $this->date . 'T09:00', 'ends_at' => $this->date . 'T09:30', 'version' => 1]);
        $response->assertStatus(200);
        $token = $this->tokenForNextRequest();
        $response = $this->submit('clinic/appointments/' . $appt['id'] . '/confirm', [config('Security')->tokenName => $token,
            'provider_user_id' => $this->clinician['id'], 'starts_at' => $this->date . 'T09:00', 'ends_at' => $this->date . 'T09:30', 'version' => 1]);
        $response->assertStatus(409);
        $this->assertNotSame($token, json_decode($response->getJSON(), true)['csrf']['hash']);
        $token = $this->tokenForNextRequest();
        $this->submit('clinic/appointments/cancel/' . $appt['id'], [config('Security')->tokenName => $token])->assertStatus(200);
        $booking = $service->bookAppointment(['unit' => 'clinic', 'person_id' => $this->admin['person_id'],
            'provider_user_id' => $this->clinician['id'], 'starts_at' => date('Y-m-d') . ' 09:00:00', 'ends_at' => date('Y-m-d') . ' 09:30:00']);
        $token = $this->tokenForNextRequest();
        $this->submit('clinic/appointments/check-in/' . $booking['id'], [config('Security')->tokenName => $token])->assertStatus(200);
        $this->assertSame('checked_in', (new AppointmentModel())->find($booking['id'])['status']);
    }

    public function testUnrelatedPostRoutesAreNotSubjectToAppointmentCsrfFilter(): void
    {
        service('superglobals')->setCookieArray([]);
        Services::resetSingle('security');
        $this->submit('referrals/store', [])->assertStatus(422);
    }

    public function testTestSchemaHasAgreedAppointmentAndAvailabilityFields(): void
    {
        $this->assertSame([], array_values(array_diff([
            'provider_user_id', 'status', 'version', 'confirmed_by_user_id', 'confirmed_at', 'assigned_by_user_id', 'assigned_at',
        ], $this->db->getFieldNames('appointments'))));
        $this->assertSame([], array_values(array_diff([
            'tenant_id', 'unit', 'provider_user_id', 'day_of_week', 'start_time', 'end_time', 'slot_duration_minutes', 'is_active',
        ], $this->db->getFieldNames('provider_availability'))));
        $provider = $this->db->query('SHOW COLUMNS FROM ' . $this->db->prefixTable('appointments') . " LIKE 'provider_user_id'")->getRowArray();
        $this->assertSame('YES', $provider['Null']);
        $status = $this->db->query('SHOW COLUMNS FROM ' . $this->db->prefixTable('appointments') . " LIKE 'status'")->getRowArray();
        $this->assertStringContainsString("'requested'", $status['Type']);
        $this->assertStringContainsString("'confirmed'", $status['Type']);
    }

    public function testStaffGetsRejectStaleDatabaseRoleSuspensionDeletionAndMissingUser(): void
    {
        foreach (['demoted', 'suspended', 'deleted', 'missing'] as $state) {
            $user = $this->user('clinic_staff');
            $session = ['user' => $user, 'tenant_id' => 1];
            match ($state) {
                'demoted' => (new UserModel())->update($user['id'], ['role' => 'student']),
                'suspended' => (new UserModel())->update($user['id'], ['status' => 'suspended']),
                'deleted' => (new UserModel())->delete($user['id']),
                'missing' => (new UserModel())->delete($user['id'], true),
            };
            foreach (['clinic/appointments', 'clinic/appointments/create', 'clinic/appointments/1/confirm',
                'api/appointments/slots?unit=clinic&provider_id=' . $this->clinician['id'] . '&date=' . $this->date] as $route) {
                $this->withSession($session)->withHeaders([])->get($route)->assertStatus(403);
            }
        }
    }
}

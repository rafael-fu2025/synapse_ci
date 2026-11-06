<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppointmentModel;
use App\Models\PersonModel;
use App\Database\Seeds\PermissionsAndRolesSeeder;
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
    private int $assignment;
    private string $date;
    private string $savedDefaultGroup;

    protected function setUp(): void
    {
        parent::setUp();
        // Route handlers revalidate identity via the default group; bind it to the
        // migrated tests schema so fixtures and controllers share one database.
        $this->savedDefaultGroup = config('Database')->defaultGroup;
        config('Database')->defaultGroup = 'tests';
        (new PermissionsAndRolesSeeder(config('Database')))->run();
        CurrentTenant::set(1);
        $this->admin = $this->user('superadmin');
        $this->clinician = $this->user('clinic_staff');
        // Confirmation and availability operate on department staff assignments.
        (new \App\Models\EmployeeProfileModel())->insert(['tenant_id' => 1, 'person_id' => $this->clinician['person_id'],
            'department' => 'Health Services', 'position' => 'Nurse', 'employment_status' => 'active']);
        $this->assignment = (int) (new \App\Models\DepartmentStaffModel())->insert(['tenant_id' => 1,
            'person_id' => $this->clinician['person_id'], 'unit' => 'clinic', 'status' => 'active']);
        // Scheduling runs on Asia/Manila; availability weekdays must match that clock.
        $manilaNow = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
        $this->date = $manilaNow->modify('+7 days')->format('Y-m-d');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('security');
        Services::resetSingle('renderer');
        service('superglobals')->setCookieArray([]);
        // Remove rows referencing fixture people in FK-safe order before the shared
        // cleanup deletes appointments, users, and people.
        if ($this->assignment > 0) {
            $this->db->table('provider_availability')->where('staff_assignment_id', $this->assignment)->delete();
            $apptIds = array_column($this->db->table('appointments')->select('id')
                ->where('staff_assignment_id', $this->assignment)->get()->getResultArray(), 'id');
            if ($apptIds !== []) {
                $this->db->table('notifications')->whereIn('appointment_id', $apptIds)->delete();
            }
            $this->db->table('appointments')->where('staff_assignment_id', $this->assignment)->delete();
            $this->db->table('department_staff')->where('id', $this->assignment)->delete();
            $this->db->table('employee_profiles')->where('person_id', $this->clinician['person_id'] ?? 0)->delete();
        }
        $this->cleanupAppointmentFixtures();
        CurrentTenant::reset();
        config('Database')->defaultGroup = $this->savedDefaultGroup;
        parent::tearDown();
    }

    /** Session identity is revalidated from the database and requires bound _auth metadata. */
    private function authSession(array $user): array
    {
        return ['user' => $user, 'tenant_id' => 1,
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]];
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
        return $this->withSession($this->authSession($this->admin))
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
                $unit . '/appointments/availability' => ['staff_assignment_id' => $this->assignment, 'day_of_week' => 0,
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
        $this->assertStringContainsString('This request was not processed', $response->response()->getBody());
        $this->assertNotNull($response->response()->getCookie(config('Security')->cookieName), 'Rejected browser posts receive a fresh CSRF cookie');
    }

    public function testValidationAndSuccessfulWritesKeepCookieTokenValidForOtherOpenForms(): void
    {
        $token = $this->tokenForNextRequest();
        $response = $this->submit('portal/appointments/clinic/store', [config('Security')->tokenName => $token]);
        $response->assertStatus(422);
        $json = json_decode($response->getJSON(), true);
        $this->assertFalse($json['success']);
        $this->assertSame($token, $json['csrf']['hash']);

        // A form rendered before the validation POST still carries the same cookie-bound token.
        $anotherOpenFormToken = $token;
        $nextToken = $this->tokenForNextRequest($json['csrf']['hash']);
        $response = $this->submit('portal/appointments/clinic/store', $this->requestPayload() + [config('Security')->tokenName => $nextToken]);
        $response->assertStatus(200);
        $json = json_decode($response->getJSON(), true);
        $this->assertTrue($json['success']);
        $this->assertSame($anotherOpenFormToken, $json['csrf']['hash']);

        // A second outstanding form in another tab can submit using the original token.
        $this->tokenForNextRequest($anotherOpenFormToken);
        $this->submit('portal/appointments/clinic/store', $this->requestPayload() + [config('Security')->tokenName => $anotherOpenFormToken])->assertStatus(200);
    }

    public function testDirectPostDoesNotRotateSharedCookieToken(): void
    {
        $token = $this->tokenForNextRequest();
        $response = $this->submit('portal/appointments/clinic/store', $this->requestPayload() + [config('Security')->tokenName => $token], false);
        $response->assertRedirectTo('/portal');
        $this->assertFalse(config('Security')->regenerate, 'Cookie CSRF tokens remain stable across tabs and POST requests.');
        $cookie = $response->response()->getCookie(config('Security')->cookieName);
        $this->assertTrue($cookie === null || $cookie->getValue() === $token, 'A successful POST cannot invalidate a form already open in another tab.');
    }

    public function testValidTokensReachConfirmationAvailabilityCancellationAndCheckIn(): void
    {
        $service = new AppointmentService();
        $token = $this->tokenForNextRequest();
        $this->submit('clinic/appointments/availability', [config('Security')->tokenName => $token,
            'staff_assignment_id' => $this->assignment, 'day_of_week' => (int) (new \DateTimeImmutable($this->date, new \DateTimeZone('Asia/Manila')))->format('w'),
            'start_time' => '09:00', 'end_time' => '11:00', 'slot_duration_minutes' => 30, 'is_active' => 1])->assertStatus(200);
        // The availability POST replaces the provider's schedule; add today's window
        // afterwards so the same-day check-in leg finds the provider available.
        (new \App\Models\ProviderAvailabilityModel())->insert(['tenant_id' => 1, 'unit' => 'clinic',
            'staff_assignment_id' => $this->assignment,
            'day_of_week' => (int) (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('w'),
            'start_time' => '00:00:00', 'end_time' => '23:59:00', 'slot_duration_minutes' => 30, 'is_active' => 1]);
        $appt = $service->requestAppointment('clinic', $this->requestPayload(), $this->admin, true);
        $token = $this->tokenForNextRequest();
        $response = $this->submit('clinic/appointments/' . $appt['id'] . '/confirm', [config('Security')->tokenName => $token,
            'staff_assignment_id' => $this->assignment, 'starts_at' => $this->date . 'T09:00', 'ends_at' => $this->date . 'T09:30', 'version' => 1]);
        $response->assertStatus(200);
        $token = $this->tokenForNextRequest();
        $response = $this->submit('clinic/appointments/' . $appt['id'] . '/confirm', [config('Security')->tokenName => $token,
            'staff_assignment_id' => $this->assignment, 'starts_at' => $this->date . 'T09:00', 'ends_at' => $this->date . 'T09:30', 'version' => 1]);
        $response->assertStatus(409);
        // The rejection still answers with a usable CSRF pair for the next attempt.
        $this->assertSame(csrf_token(), json_decode($response->getJSON(), true)['csrf']['name'] ?? null);
        $this->assertNotEmpty(json_decode($response->getJSON(), true)['csrf']['hash'] ?? '');
        $token = $this->tokenForNextRequest();
        $this->submit('clinic/appointments/cancel/' . $appt['id'], [config('Security')->tokenName => $token,
            'reason' => 'Patient requested cancellation'])->assertStatus(200);
        // Check-in requires the appointment date to be today, and the slot must be a
        // future 30-minute grid slot of the provider's all-day availability window.
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
        $totalMinutes = ((int) $now->format('H')) * 60 + (int) $now->format('i');
        $slotMinutes = (int) (ceil(($totalMinutes + 15) / 30) * 30);
        $dayOffset = intdiv($slotMinutes, 1440);
        $slotMinutes %= 1440;
        $slotStart = $now->modify('+' . $dayOffset . ' days')->setTime(intdiv($slotMinutes, 60), $slotMinutes % 60);
        $booking = $service->bookAppointment(['unit' => 'clinic', 'person_id' => $this->admin['person_id'],
            'staff_assignment_id' => $this->assignment,
            'starts_at' => $slotStart->format('Y-m-d H:i:s'), 'ends_at' => $slotStart->modify('+30 minutes')->format('Y-m-d H:i:s')]);
        $token = $this->tokenForNextRequest();
        $this->submit('clinic/appointments/check-in/' . $booking['id'], [config('Security')->tokenName => $token])->assertStatus(200);
        $this->assertSame('checked_in', (new AppointmentModel())->find($booking['id'])['status']);
    }

    public function testUnrelatedPostRoutesAreNotSubjectToAppointmentCsrfFilter(): void
    {
        service('superglobals')->setCookieArray([]);
        Services::resetSingle('security');
        // With the global CSRF pair satisfied the route reaches validation (422):
        // the appointment CSRF filter is not what protects it.
        $token = $this->tokenForNextRequest();
        $this->submit('referrals/store', [config('Security')->tokenName => $token])->assertStatus(422);
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
            $session = $this->authSession($user);
            match ($state) {
                'demoted' => (new UserModel())->update($user['id'], ['role' => 'student']),
                'suspended' => (new UserModel())->update($user['id'], ['status' => 'suspended']),
                'deleted' => (new UserModel())->delete($user['id']),
                'missing' => (new UserModel())->delete($user['id'], true),
            };
            foreach (['clinic/appointments', 'clinic/appointments/create', 'clinic/appointments/1/confirm',
                'api/appointments/slots?unit=clinic&provider_id=' . $this->clinician['id'] . '&date=' . $this->date] as $route) {
                $probeStale = $this->withSession($session)->withHeaders([])->get($route);
                if ($state === 'demoted' && str_starts_with($route, 'api/')) {
                    // A demoted-but-valid identity is permission-denied on JSON surfaces.
                    $probeStale->assertStatus(403);
                } else {
                    // Guests and stale identities alike are redirected out of staff surfaces.
                    $probeStale->assertRedirect();
                }
            }
        }
    }
}

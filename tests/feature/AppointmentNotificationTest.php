<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Mis\MisClientInterface;
use App\Models\AppointmentModel;
use App\Models\DepartmentStaffModel;
use App\Models\EmployeeProfileModel;
use App\Models\PersonModel;
use App\Models\ProviderAvailabilityModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\NotificationService;
use App\Services\Scheduling\AppointmentService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\InventoryTestDatabase;

/** Requires the parent's protected notification routes; never run against the main schema. */
final class AppointmentNotificationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\AppointmentFixtureCleanup;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private int $tenant;
    private int $patient;
    private int $assignment;
    private array $staff;
    private string $date;
    private ?string $trigger = null;

    protected function setUp(): void
    {
        InventoryTestDatabase::verify();
        parent::setUp();
        AuthenticatedUser::clear();
        $mis = $this->createMock(MisClientInterface::class);
        foreach (['studentLogin', 'employeeLogin', 'searchStudents', 'searchEmployees', 'fetchDepartments', 'fetchPrograms', 'fetchYearLevels'] as $method) {
            $mis->expects($this->never())->method($method);
        }
        Services::injectMock('misClient', $mis);
        $this->tenant = $this->tenant();
        CurrentTenant::set($this->tenant);
        $this->patient = $this->person($this->tenant);
        $provider = $this->person($this->tenant);
        (new EmployeeProfileModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $provider,
            'department' => 'Health Services', 'position' => 'Nurse', 'employment_status' => 'active']);
        $this->staff = $this->account($provider, 'clinic_staff');
        $this->assignment = (int) (new DepartmentStaffModel())->insert(['tenant_id' => $this->tenant,
            'person_id' => $provider, 'unit' => 'clinic', 'status' => 'active']);
        $this->date = date('Y-m-d', strtotime('+7 days'));
        (new ProviderAvailabilityModel())->insert(['tenant_id' => $this->tenant, 'unit' => 'clinic',
            'staff_assignment_id' => $this->assignment, 'day_of_week' => (int) date('w', strtotime($this->date)),
            'start_time' => '09:00:00', 'end_time' => '17:00:00', 'slot_duration_minutes' => 30, 'is_active' => 1]);
    }

    protected function tearDown(): void
    {
        if ($this->trigger !== null) {
            $this->db->query('DROP TRIGGER IF EXISTS ' . $this->db->protectIdentifiers($this->trigger));
        }
        AuthenticatedUser::clear();
        Services::resetSingle('misClient');
        Services::resetSingle('security');
        service('superglobals')->setCookieArray([]);
        foreach ($this->appointmentFixtureTenants as $tenant) {
            $this->db->table('notifications')->where('tenant_id', $tenant)->delete();
            $this->db->table('provider_availability')->where('tenant_id', $tenant)->delete();
            $this->db->table('appointments')->where('tenant_id', $tenant)->delete();
            $this->db->table('department_staff')->where('tenant_id', $tenant)->delete();
        }
        $this->cleanupAppointmentFixtures();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function tenant(): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('notification_'), 'name' => 'Notification fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $this->db->insertID();
        $this->appointmentFixtureTenants[] = $id;
        return $id;
    }

    private function person(int $tenant): int
    {
        $id = (int) (new PersonModel())->insert(['tenant_id' => $tenant, 'kind' => 'employee',
            'mis_id' => uniqid('notification_mis_'), 'first_name' => 'Private', 'last_name' => 'Patient']);
        $this->appointmentFixturePeople[] = $id;
        return $id;
    }

    private function account(?int $person, string $role = 'employee', ?int $tenant = null): array
    {
        $tenant ??= $this->tenant;
        $id = (int) (new UserModel())->insert(['tenant_id' => $tenant, 'person_id' => $person,
            'username' => uniqid('notification_user_'), 'role' => $role, 'status' => 'active']);
        $this->appointmentFixtureUsers[] = $id;
        return $this->db->table('users')->where('id', $id)->get()->getRowArray() + ['logged_in' => true];
    }

    private function requestAppointment(?int $person = null): array
    {
        CurrentTenant::set($this->tenant);
        $id = (new AppointmentModel())->insert(['tenant_id' => $this->tenant, 'unit' => 'clinic',
            'person_id' => $person ?? $this->patient, 'starts_at' => $this->date . ' 09:00:00',
            'ends_at' => $this->date . ' 09:30:00', 'local_date' => $this->date, 'status' => 'requested',
            'type' => 'routine', 'reason' => 'SECRET diagnosis reason notes rawMIS',
            'booked_by_user_id' => $this->staff['id'], 'version' => 1]);
        return (new AppointmentModel())->scoped()->find($id);
    }

    private function confirm(array $appointment, array $overrides = []): array
    {
        CurrentTenant::set($this->tenant);
        return (new AppointmentService())->confirmAppointment('clinic', (int) $appointment['id'], $overrides + [
            'staff_assignment_id' => $this->assignment, 'starts_at' => $this->date . 'T09:00',
            'ends_at' => $this->date . 'T09:30', 'version' => $appointment['version'],
        ], $this->staff);
    }

    private function login(array $actor): void
    {
        session()->set(['tenant_id' => (int) $actor['tenant_id'], 'user' => $actor]);
    }

    private function postAs(array $actor, string $url, bool $csrf = true)
    {
        $token = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $token);
        Services::resetSingle('security');
        return $this->withSession(['tenant_id' => (int) $actor['tenant_id'], 'user' => $actor])
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->post($url, $csrf ? [config('Security')->tokenName => $token] : []);
    }

    public function testConfirmationPersistsForPatientBeforeFirstLoginAndRetryIsIdempotent(): void
    {
        $confirmed = $this->confirm($this->requestAppointment());
        $service = new NotificationService();
        $this->db->transBegin();
        try {
            $service->notifyConfirmation($confirmed);
            $service->notifyConfirmation($confirmed);
            $this->assertTrue($this->db->transCommit());
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
        $rows = $this->db->table('notifications')->where('tenant_id', $this->tenant)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame($this->patient, (int) $rows[0]['recipient_person_id']);
        $this->assertSame(2, (int) $rows[0]['appointment_version']);
        $this->assertStringNotContainsString('SECRET', json_encode($rows));
        $this->login($this->staff);
        $this->assertSame(['items' => [], 'unread_count' => 0], $service->ownInbox());
        // MIS identity linking later creates a user for the existing person, not a new recipient.
        $patientAccount = $this->account($this->patient);
        $this->login($patientAccount);
        $inbox = $service->ownInbox();
        $this->assertSame(1, $inbox['unread_count']);
        $this->assertSame(['id', 'title', 'message', 'created_at', 'read_at', 'url'], array_keys($inbox['items'][0]));
        $this->assertSame('Appointment confirmed', $inbox['items'][0]['title']);
        $this->assertSame('Your appointment has been confirmed. Open your portal to see details.', $inbox['items'][0]['message']);
        $this->assertSame(site_url('/portal#my-appointments'), $inbox['items'][0]['url']);
        $this->assertStringNotContainsString('SECRET', json_encode($inbox));
        try {
            $this->confirm($confirmed, ['version' => 1]);
            $this->fail('Stale confirmation must fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertSame(1, $this->db->table('notifications')->where('tenant_id', $this->tenant)->countAllResults());
    }

    public function testInsertFailureRollsBackConfirmation(): void
    {
        $appointment = $this->requestAppointment();
        $this->trigger = $this->db->prefixTable(uniqid('notification_fail_'));
        $trigger = $this->db->protectIdentifiers($this->trigger);
        $table = $this->db->protectIdentifiers($this->db->prefixTable('notifications'));
        $this->assertNotFalse($this->db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.tenant_id = {$this->tenant} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fixture notification failure'; END IF; END"));
        $failed = false;
        try {
            $this->confirm($appointment);
        } catch (\Throwable $e) {
            $failed = true;
        }
        $this->assertTrue($failed);
        $stored = $this->db->table('appointments')->where('id', $appointment['id'])->get()->getRowArray();
        $this->assertSame('requested', $stored['status']);
        $this->assertSame(1, (int) $stored['version']);
        $this->assertNull($stored['confirmed_at']);
        $this->assertSame(0, $this->db->table('notifications')->where('tenant_id', $this->tenant)->countAllResults());
    }

    public function testRequestedCancelledFailedAndForgedRowsCannotFabricateConfirmation(): void
    {
        $appointment = $this->requestAppointment();
        try {
            $this->confirm($appointment, ['version' => 999]);
            $this->fail('Invalid version must fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        foreach (['requested', 'cancelled'] as $status) {
            $this->db->table('appointments')->where('id', $appointment['id'])->update(['status' => $status]);
            $this->db->transBegin();
            try {
                (new NotificationService())->notifyConfirmation(array_replace($appointment, ['status' => 'confirmed']));
                $this->fail('Stored status must be confirmed.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Invalid confirmation notification.', $e->getMessage());
            } finally {
                $this->db->transRollback();
            }
        }
        $this->assertSame(0, $this->db->table('notifications')->where('tenant_id', $this->tenant)->countAllResults());
    }

    public function testNotificationRejectsForgedTenantRecipientVersionAndMissingTransaction(): void
    {
        $confirmed = $this->confirm($this->requestAppointment());
        $service = new NotificationService();
        try {
            $service->notifyConfirmation($confirmed);
            $this->fail('A caller transaction is required.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Confirmation notifications require an active transaction.', $e->getMessage());
        }
        foreach ([['tenant_id' => $this->tenant()], ['person_id' => $this->staff['person_id']], ['version' => 999]] as $forgery) {
            CurrentTenant::set($this->tenant);
            $this->db->transBegin();
            try {
                $service->notifyConfirmation(array_replace($confirmed, $forgery));
                $this->fail('Forged confirmation must fail.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Invalid confirmation notification.', $e->getMessage());
            } finally {
                $this->db->transRollback();
            }
        }
        $this->assertSame(1, $this->db->table('notifications')->where('tenant_id', $this->tenant)->countAllResults());
    }

    public function testHttpOwnershipCsrfPostOnlyAndIdempotentReads(): void
    {
        $this->confirm($this->requestAppointment());
        $row = $this->db->table('notifications')->where('tenant_id', $this->tenant)->get()->getRowArray();
        $id = (int) $row['id'];
        $owner = $this->account($this->patient);
        $foreignTenant = $this->tenant();
        $foreign = $this->account($this->person($foreignTenant), tenant: $foreignTenant);
        foreach ([$this->staff, $foreign] as $actor) {
            $this->postAs($actor, 'notifications/' . $id . '/read')->assertStatus(404);
            $this->postAs($actor, 'notifications/read-all')->assertStatus(200);
            $this->assertNull($this->db->table('notifications')->where('id', $id)->get()->getRowArray()['read_at']);
        }
        $this->postAs($owner, 'notifications/' . $id . '/read', false)->assertStatus(403);
        $this->postAs($owner, 'notifications/read-all', false)->assertStatus(403);
        $this->withSession(['tenant_id' => $this->tenant, 'user' => $owner])->withHeaders(['Accept' => 'application/json'])
            ->get('notifications/read-all')->assertStatus(405);
        $this->withSession(['tenant_id' => $this->tenant, 'user' => $owner])->withHeaders(['Accept' => 'application/json'])
            ->get('notifications/' . $id . '/read')->assertStatus(405);
        $this->assertNull($this->db->table('notifications')->where('id', $id)->get()->getRowArray()['read_at']);
        $response = $this->withSession(['tenant_id' => $this->tenant, 'user' => $owner])->get('notifications');
        $response->assertStatus(200);
        $this->assertStringContainsString('private', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
        for ($retry = 0; $retry < 2; ++$retry) {
            $response = $this->postAs($owner, 'notifications/' . $id . '/read');
            $response->assertStatus(200);
            $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(0, $json['unread_count']);
            $this->assertNotNull($json['items'][0]['read_at']);
            $this->assertSame(csrf_token(), $json['csrf']['name']);
            $this->assertNotEmpty($json['csrf']['hash']);
            $readAt ??= $json['items'][0]['read_at'];
            $this->assertSame($readAt, $json['items'][0]['read_at']);
        }
        $this->postAs($owner, 'notifications/read-all')->assertStatus(200);
        $this->db->table('users')->where('id', $owner['id'])->update(['status' => 'suspended']);
        $response = $this->postAs($owner, 'notifications/' . $id . '/read');
        $this->assertContains($response->response()->getStatusCode(), [401, 403]);
        AuthenticatedUser::clear();
        $this->withSession([])->withHeaders(['Accept' => 'application/json'])->get('notifications')->assertStatus(401);
    }

    public function testPersonalPortalShowsConfirmedTimeAndAssignedStaffForItsOwner(): void
    {
        $this->db->table('people')->where('id', $this->staff['person_id'])->update(['first_name' => 'Assigned', 'last_name' => 'Clinician']);
        $confirmed = $this->confirm($this->requestAppointment());
        $other = $this->person($this->tenant);
        (new AppointmentModel())->insert(['tenant_id' => $this->tenant, 'unit' => 'guidance', 'person_id' => $other,
            'starts_at' => $this->date . ' 12:00:00', 'ends_at' => $this->date . ' 12:30:00',
            'local_date' => $this->date, 'status' => 'requested', 'type' => 'initial', 'version' => 1]);
        $owner = $this->account($this->patient);
        $response = $this->withSession(['tenant_id' => $this->tenant, 'user' => $owner])->withHeaders([])->get('portal');
        $response->assertStatus(200);
        $body = $response->response()->getBody();
        $this->assertStringContainsString('id="my-appointments"', $body);
        $this->assertStringContainsString('Assigned Clinician', $body);
        $this->assertStringContainsString('Confirmed', $body);
        $this->assertStringContainsString(date('h:i A', strtotime($confirmed['starts_at'])), $body);
        $this->assertStringContainsString(date('h:i A', strtotime($confirmed['ends_at'])), $body);
        $chart = (new \App\Services\Clinic\PatientHistoryService())->getPatientChart($this->patient);
        $this->assertCount(1, $chart['appointments']);
        $this->assertSame((int) $confirmed['id'], (int) $chart['appointments'][0]['id']);
    }

    public function testFreshIdentityPersonlessSuperadminAndBoundedPersonalInbox(): void
    {
        $confirmed = $this->confirm($this->requestAppointment());
        $owner = $this->account($this->patient, 'clinic_staff');
        $this->login($owner);
        for ($version = 3; $version <= 105; ++$version) {
            $this->db->table('notifications')->insert(['tenant_id' => $this->tenant, 'recipient_person_id' => $this->patient,
                'appointment_id' => $confirmed['id'], 'appointment_version' => $version,
                'type' => 'appointment_confirmed', 'created_at' => date('Y-m-d H:i:s')]);
        }
        $service = new NotificationService();
        $inbox = $service->ownInbox();
        $this->assertCount(10, $inbox['items']);
        $this->assertSame(100, $inbox['unread_count']);
        $this->assertGreaterThan($inbox['items'][1]['id'], $inbox['items'][0]['id']);
        $service->markAllRead();
        $this->assertSame(0, $service->ownInbox()['unread_count']);
        $superadmin = $this->account(null, 'superadmin');
        $this->login($superadmin);
        $this->assertSame(['items' => [], 'unread_count' => 0], $service->ownInbox());
        $this->login($owner);
        // A cached person/role cannot override the current database identity.
        $this->db->table('users')->where('id', $owner['id'])->update(['person_id' => $this->person($this->tenant)]);
        $this->assertSame(['items' => [], 'unread_count' => 0], $service->ownInbox());
    }
}

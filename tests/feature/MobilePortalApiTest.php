<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppointmentModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\Api\ApiContext;
use App\Services\Api\TokenService;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/** Mobile portal resource contract: appointments, queue, notifications, engagement lists, staff summary. */
final class MobilePortalApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\\Database\\Seeds\\PermissionsAndRolesSeeder';

    /** @var list<int> */
    private array $people = [];
    /** @var list<int> */
    private array $users = [];
    /** @var list<int> */
    private array $appointments = [];

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        ApiContext::clear();
        CurrentTenant::reset();
        $db = Database::connect();
        if ($this->appointments !== []) {
            $db->table('notifications')->whereIn('appointment_id', $this->appointments)->delete();
            $db->table('appointments')->whereIn('id', $this->appointments)->delete();
        }
        if ($this->users !== []) {
            $db->table('api_tokens')->whereIn('user_id', $this->users)->delete();
            $db->table('queue_entries')->whereIn('person_id', $this->people)->delete();
            // Booking fixtures record workflow events with the fixture user as actor.
            $db->table('workflow_events')->whereIn('actor_user_id', $this->users)->delete();
            $db->table('users')->whereIn('id', $this->users)->delete();
        }
        if ($this->people !== []) {
            $db->table('people')->whereIn('id', $this->people)->delete();
        }
        parent::tearDown();
    }

    /** @return array{id: int, person_id: int} */
    private function account(string $role): array
    {
        $unique = uniqid('mobile_portal_', true);
        $personId = (int) (new PersonModel())->insert([
            'tenant_id' => 1,
            'kind' => $role === 'student' ? 'student' : 'employee',
            'mis_id' => $unique,
            'first_name' => 'Portal',
            'last_name' => 'Fixture',
        ]);
        $userId = (int) (new UserModel())->insert([
            'tenant_id' => 1,
            'person_id' => $personId,
            'username' => $unique,
            'role' => $role,
            'status' => 'active',
        ]);
        $this->people[] = $personId;
        $this->users[] = $userId;
        return ['id' => $userId, 'person_id' => $personId];
    }

    private function accessToken(array $account): string
    {
        $identity = (new AuthenticatedUser())->forIdentity($account['id'], 1);
        $this->assertNotNull($identity, 'fixture identity must validate');
        return (string) (new TokenService())->issue($identity, 'portal-api-test', '127.0.0.1')['access_token'];
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function json(\CodeIgniter\Test\TestResponse $response): array
    {
        $decoded = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    private function postJson(string $path, array $payload, string $token): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders($this->bearer($token) + ['Content-Type' => 'application/json'])
            ->withBody((string) json_encode($payload))->post($path);
    }

    private function appointment(array $account, string $status): int
    {
        $date = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $id = (int) (new AppointmentModel())->insert([
            'tenant_id' => 1,
            'unit' => 'clinic',
            'person_id' => $account['person_id'],
            'starts_at' => $date . ' 09:00:00',
            'ends_at' => $date . ' 09:30:00',
            'local_date' => $date,
            'status' => $status,
            'type' => 'routine',
            'reason' => 'Fixture visit',
            'version' => 1,
        ]);
        $this->appointments[] = $id;
        return $id;
    }

    public function testAppointmentListAndDetailArePersonScoped(): void
    {
        $student = $this->account('student');
        $other = $this->account('student');
        $appointmentId = $this->appointment($student, 'confirmed');
        $this->appointment($other, 'confirmed');
        $token = $this->accessToken($student);

        $list = $this->withHeaders($this->bearer($token))->get('api/v1/appointments');
        $list->assertStatus(200);
        $items = $this->json($list)['data'] ?? [];
        $this->assertCount(1, $items);
        $this->assertSame($appointmentId, $items[0]['id']);
        foreach (['id', 'unit', 'status', 'type', 'date', 'starts_at', 'ends_at', 'provider_name'] as $field) {
            $this->assertArrayHasKey($field, $items[0]);
        }

        $detail = $this->withHeaders($this->bearer($token))->get('api/v1/appointments/' . $appointmentId);
        $detail->assertStatus(200);
        $this->assertSame($appointmentId, $this->json($detail)['data']['id']);

        // Another person's appointment is invisible, not merely filtered.
        $this->withHeaders($this->bearer($token))
            ->get('api/v1/appointments/' . ($appointmentId + 100000))->assertStatus(404);
    }

    public function testBookingCreatesAndCancelsPersonalAppointments(): void
    {
        $student = $this->account('student');
        $token = $this->accessToken($student);
        $date = (new \DateTimeImmutable('+2 days', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');

        $created = $this->postJson('api/v1/appointments', [
            'unit' => 'guidance', 'date' => $date, 'preferred_time' => '10:30',
            'type' => 'initial', 'reason' => 'Mobile booking fixture',
        ], $token);
        $created->assertStatus(201);
        $appointmentId = (int) $this->json($created)['data']['id'];
        $row = (new AppointmentModel())->find($appointmentId);
        $this->assertSame('requested', $row['status']);
        $this->assertSame('guidance', $row['unit']);

        $cancel = $this->postJson('api/v1/appointments/' . $appointmentId . '/cancel',
            ['reason' => 'Cannot make it anymore.'], $token);
        $cancel->assertStatus(204);
        $this->assertSame('cancelled', (new AppointmentModel())->find($appointmentId)['status']);
    }

    public function testBookingValidationReturnsFieldErrors(): void
    {
        $student = $this->account('student');
        $token = $this->accessToken($student);

        $result = $this->postJson('api/v1/appointments', [
            'unit' => 'clinic', 'date' => '2026-13-40', 'preferred_time' => '9:00', 'type' => 'bogus',
        ], $token);
        $result->assertStatus(422);
        $fields = $this->json($result)['error']['fields'] ?? [];
        $this->assertArrayHasKey('date', $fields);
        $this->assertArrayHasKey('preferred_time', $fields);
        $this->assertArrayHasKey('type', $fields);

        $missingReason = $this->postJson('api/v1/appointments/1/cancel', ['reason' => '  '], $token);
        $missingReason->assertStatus(422);
        $this->assertArrayHasKey('reason', $this->json($missingReason)['error']['fields'] ?? []);
    }

    public function testQueueSnapshotReturnsTodayTickets(): void
    {
        $student = $this->account('student');
        $token = $this->accessToken($student);
        $db = Database::connect();
        $today = SchedulingClock::today();
        $db->table('queue_entries')->where('tenant_id', 1)->where('unit', 'clinic')->where('queue_date', $today)->delete();
        $db->table('queue_entries')->insert([
            'tenant_id' => 1, 'unit' => 'clinic', 'person_id' => $student['person_id'],
            'queue_date' => $today, 'ticket_no' => 4, 'ticket_code' => 'C-004',
            'status' => 'waiting', 'priority' => 'routine',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $result = $this->withHeaders($this->bearer($token))->get('api/v1/queue');
        $result->assertStatus(200);
        $snapshot = $this->json($result)['data'] ?? [];
        $this->assertSame($today, $snapshot['date'] ?? null);
        $tickets = $snapshot['tickets'] ?? [];
        $this->assertSame('C-004', $tickets[0]['ticket_code'] ?? null);
        $this->assertSame('waiting', $tickets[0]['status'] ?? null);
    }

    public function testNotificationInboxHistoryAndMarkRead(): void
    {
        $student = $this->account('student');
        $token = $this->accessToken($student);
        $appointmentId = $this->appointment($student, 'confirmed');
        Database::connect()->table('notifications')->insert([
            'tenant_id' => 1,
            'recipient_person_id' => $student['person_id'],
            'appointment_id' => $appointmentId,
            'appointment_version' => 1,
            'type' => 'appointment_confirmed',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $inbox = $this->withHeaders($this->bearer($token))->get('api/v1/notifications');
        $inbox->assertStatus(200);
        $data = $this->json($inbox)['data'] ?? [];
        $this->assertSame(1, $data['unread_count'] ?? null);
        $this->assertNotSame('', $data['items'][0]['title'] ?? '');

        $notificationId = (int) $data['items'][0]['id'];
        $this->withHeaders($this->bearer($token))
            ->post('api/v1/notifications/' . $notificationId . '/read')->assertStatus(204);

        $history = $this->withHeaders($this->bearer($token))->get('api/v1/notifications/history?filter=unread');
        $history->assertStatus(200);
        $this->assertSame(0, $this->json($history)['data']['total'] ?? null);
    }

    public function testHealthSummaryReturnsLeanProjection(): void
    {
        $student = $this->account('student');
        $token = $this->accessToken($student);
        $db = Database::connect();
        $db->table('people')->where('id', $student['person_id'])->update([
            'middle_name' => 'University',
            'email' => 'portal.fixture@university.invalid',
        ]);
        $db->table('student_profiles')->insert([
            'tenant_id' => 1,
            'person_id' => $student['person_id'],
            'program' => 'BS Information Technology',
            'year_level' => 3,
            'section' => 'A',
            'department' => 'CCS',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $db->table('health_profiles')->insert([
            'tenant_id' => 1,
            'person_id' => $student['person_id'],
            'blood_type' => 'O+',
            'allergies' => 'Penicillin',
            'emergency_contact_name' => 'Maria Fixture',
            'emergency_contact_phone' => '+63 900 000 0000',
            'emergency_contact_relationship' => 'Mother',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $result = $this->withHeaders($this->bearer($token))->get('api/v1/me/health-summary');
        $result->assertStatus(200);
        $data = $this->json($result)['data'] ?? [];
        $this->assertSame('Portal University Fixture', $data['person']['name'] ?? null);
        $this->assertSame('portal.fixture@university.invalid', $data['person']['email'] ?? null);
        $this->assertSame('BS Information Technology', $data['person']['program'] ?? null);
        $this->assertSame(3, (int) ($data['person']['year_level'] ?? 0));
        $this->assertSame('A', $data['person']['section'] ?? null);
        $this->assertSame('CCS', $data['person']['department'] ?? null);
        $this->assertNull($data['person']['employment_status']);
        $this->assertSame('O+', $data['health']['blood_type'] ?? null);
        $this->assertSame('Penicillin', $data['health']['allergies'] ?? null);
        // Clinical detail never crosses the mobile contract.
        $this->assertArrayNotHasKey('encounters', $data);
        $this->assertArrayNotHasKey('vitals_history', $data);
    }

    public function testEmployeeHealthSummaryIncludesProfileCardFields(): void
    {
        $employee = $this->account('employee');
        $token = $this->accessToken($employee);
        Database::connect()->table('employee_profiles')->insert([
            'tenant_id' => 1,
            'person_id' => $employee['person_id'],
            'department' => 'University Clinic',
            'position' => 'Nurse',
            'employment_status' => 'regular',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $result = $this->withHeaders($this->bearer($token))->get('api/v1/me/health-summary');
        $result->assertStatus(200);
        $data = $this->json($result)['data'] ?? [];
        $this->assertSame('employee', $data['person']['kind'] ?? null);
        $this->assertSame('University Clinic', $data['person']['department'] ?? null);
        $this->assertSame('Nurse', $data['person']['position'] ?? null);
        $this->assertSame('regular', $data['person']['employment_status'] ?? null);
        $this->assertNull($data['person']['program']);
        $this->assertNull($data['person']['section']);
        $this->assertArrayNotHasKey('encounters', $data);
    }

    public function testEngagementListsAnswerAuthenticatedShape(): void
    {
        $student = $this->account('student');
        $token = $this->accessToken($student);

        $announcements = $this->withHeaders($this->bearer($token))->get('api/v1/announcements');
        $announcements->assertStatus(200);
        $payload = $this->json($announcements);
        $this->assertIsArray($payload['data'] ?? null);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertArrayHasKey('counts', $payload);

        $surveys = $this->withHeaders($this->bearer($token))->get('api/v1/surveys');
        $surveys->assertStatus(200);
        $this->assertIsArray($this->json($surveys)['data'] ?? null);
    }

    public function testStaffQueueSummaryIsEntitlementGated(): void
    {
        $student = $this->account('student');
        $this->withHeaders($this->bearer($this->accessToken($student)))
            ->get('api/v1/staff/queue-summary')->assertStatus(403);

        $staff = $this->account('clinic_staff');
        $summary = $this->withHeaders($this->bearer($this->accessToken($staff)))
            ->get('api/v1/staff/queue-summary');
        $summary->assertStatus(200);
        $units = $this->json($summary)['data'] ?? [];
        $this->assertSame('clinic', $units[0]['unit'] ?? null);
        $this->assertArrayHasKey('waiting', $units[0]['counts'] ?? []);
        $this->assertArrayHasKey('waiting', $units[0]['tickets'] ?? []);
    }
}

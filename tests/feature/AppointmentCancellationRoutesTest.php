<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppointmentModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Scheduling\AppointmentService;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FreshDatabaseTestCase;

/** Only run against the guarded runner's disposable migration-backed schema. */
final class AppointmentCancellationRoutesTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private int $tenant;
    private array $patient;
    private array $clinic;
    private array $guidance;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('cancel_'), 'name' => 'Cancellation fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->tenant = (int) $this->db->insertID();
        CurrentTenant::set($this->tenant);
        $this->patient = $this->account('student');
        $this->clinic = $this->account('clinic_staff');
        $this->guidance = $this->account('counsellor');
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function account(string $role): array
    {
        $person = (int) (new PersonModel())->insert(['tenant_id' => $this->tenant,
            'kind' => $role === 'student' ? 'student' : 'employee', 'mis_id' => uniqid('cancel_person_'),
            'first_name' => 'Cancellation', 'last_name' => 'Fixture']);
        $id = (int) (new UserModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $person,
            'username' => uniqid('cancel_user_'), 'role' => $role, 'status' => 'active']);
        return (new UserModel())->find($id) + ['logged_in' => true];
    }

    private function appointment(string $unit, string $status = 'requested'): int
    {
        $date = date('Y-m-d', strtotime('+7 days'));
        $id = (new AppointmentModel())->insert(['tenant_id' => $this->tenant, 'unit' => $unit,
            'person_id' => $this->patient['person_id'], 'starts_at' => $date . ' 09:00:00',
            'ends_at' => $date . ' 09:30:00', 'local_date' => $date, 'status' => $status,
            'type' => $unit === 'clinic' ? 'routine' : 'initial', 'reason' => 'Fixture request', 'version' => 1]);
        $this->assertNotFalse($id);
        return (int) $id;
    }

    private function state(int $id): array
    {
        $state = ['appointment' => (new AppointmentModel())->find($id)];
        foreach (['workflow_events', 'notifications'] as $table) {
            $state[$table] = $this->db->table($table)->where('tenant_id', $this->tenant)
                ->orderBy('id')->get()->getResultArray();
        }
        return $state;
    }

    private function actor(string $unit, bool $personal): array
    {
        return $personal ? $this->patient : ($unit === 'clinic' ? $this->clinic : $this->guidance);
    }

    private function postCancellation(string $unit, bool $personal, int $id, array $data)
    {
        $actor = $this->actor($unit, $personal);
        $token = csrf_hash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $token);
        \Config\Services::resetSingle('security');
        $route = $personal ? 'portal/appointments/' . $unit : $unit . '/appointments';
        return $this->withSession(['user' => $actor, 'tenant_id' => $this->tenant,
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]])
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post($route . '/cancel/' . $id, $data + [csrf_token() => $token]);
    }

    public static function contexts(): iterable
    {
        yield 'clinic patient' => ['clinic', true];
        yield 'guidance patient' => ['guidance', true];
        yield 'clinic staff' => ['clinic', false];
        yield 'guidance staff' => ['guidance', false];
    }

    #[DataProvider('contexts')]
    public function testRejectedReasonsLeaveAppointmentVersionAndNotificationsUntouched(string $unit, bool $personal): void
    {
        $id = $this->appointment($unit);
        $before = $this->state($id);
        $payloads = [[], ['reason' => null], ['reason' => ''], ['reason' => '   '],
            ['reason' => "\t\r\n"], ['reason' => "\u{00A0}\u{2003}\u{3000}"],
            ['reason' => "\u{200B}"], ['reason' => str_repeat('界', 256)],
            ['reason' => str_repeat('a', 256)], ['reason' => "bad\xC3\x28"],
            ['reason' => "bad\0"], ['reason' => "bad\u{0085}"], ['reason' => ['nested']]];
        $service = new AppointmentService();
        foreach ($payloads as $payload) {
            if (!isset($payload['reason']) || is_string($payload['reason'])) {
                try {
                    $service->cancelAppointment($id, $payload['reason'] ?? '', $unit, $this->actor($unit, $personal), $personal);
                    $this->fail('Invalid reason must not cancel an appointment.');
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString('Cancellation reason must', $e->getMessage());
                }
                $this->assertSame($before, $this->state($id));
            }
            $response = $this->postCancellation($unit, $personal, $id, $payload);
            $response->assertStatus(422);
            $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertFalse($json['success']);
            $this->assertNotEmpty($json['csrf']['hash']);
            $this->assertSame($before, $this->state($id));
        }
    }

    #[DataProvider('contexts')]
    public function testValidReasonsAreTrimmedAndPersisted(string $unit, bool $personal): void
    {
        foreach (['0', 'Schedule conflict', str_repeat('界', 255)] as $reason) {
            foreach (['requested', 'scheduled', 'confirmed'] as $status) {
                $id = $this->appointment($unit, $status);
                $response = $this->postCancellation($unit, $personal, $id, ['reason' => " \u{00A0}" . $reason . "\u{3000} "]);
                $response->assertStatus(200);
                $this->assertTrue(json_decode($response->getJSON(), true)['success']);
                $updated = (new AppointmentModel())->find($id);
                $this->assertSame('cancelled', $updated['status']);
                $this->assertSame(2, (int) $updated['version']);
                $this->assertSame($reason, $updated['cancellation_reason']);
                $event = $this->db->table('workflow_events')->where('tenant_id', $this->tenant)
                    ->where('subject_id', $id)->where('unit', $unit)->get()->getRowArray();
                $this->assertNotNull($event);
                $this->assertSame($personal ? 'appointment_cancelled_staff' : 'appointment_cancelled_patient', $event['event_kind']);
                $this->assertSame('2', $event['occurrence_key']);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AppointmentModel;
use App\Models\AvailabilityExceptionModel;
use App\Models\PersonModel;
use App\Models\ProviderAvailabilityModel;
use App\Models\UserModel;
use App\Services\CurrentTenant;
use App\Services\Clinic\PatientHistoryService;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\QueueService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class AppointmentRequestServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use \Tests\Support\AppointmentFixtureCleanup;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private AppointmentService $service;
    private array $patient;
    private array $staff;
    private array $counsellor;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
        $this->service = new AppointmentService();
        $this->patient = $this->user('student');
        $this->staff = $this->user('clinic_staff');
        $this->counsellor = $this->user('counsellor');
        $this->availability($this->staff, 'clinic');
        $this->availability($this->counsellor, 'guidance');
    }

    protected function tearDown(): void
    {
        $this->cleanupAppointmentFixtures();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function user(string $role, int $tenant = 1): array
    {
        $unique = uniqid('request_', true);
        $person = (int) (new PersonModel())->insert(['tenant_id' => $tenant, 'kind' => 'employee',
            'mis_id' => $unique, 'first_name' => 'Request', 'last_name' => 'Tester']);
        $id = (int) (new UserModel())->insert(['tenant_id' => $tenant, 'person_id' => $person,
            'username' => $unique, 'role' => $role, 'status' => 'active']);
        $this->appointmentFixtureUsers[] = $id;
        $this->appointmentFixturePeople[] = $person;
        return (new UserModel())->find($id);
    }

    private function availability(array $user, string $unit): void
    {
        (new ProviderAvailabilityModel())->insert(['tenant_id' => (int) $user['tenant_id'],
            'provider_user_id' => $user['id'], 'unit' => $unit, 'day_of_week' => (int) date('w'),
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'slot_duration_minutes' => 30, 'is_active' => 1]);
    }

    private function request(string $unit = 'clinic', ?array $actor = null): array
    {
        return $this->service->requestAppointment($unit, $this->payload($unit), $actor ?? $this->patient, true);
    }

    private function bookingDate(): string
    {
        return date('Y-m-d', strtotime('+7 days'));
    }

    private function payload(string $unit = 'clinic'): array
    {
        return ['date' => $this->bookingDate(), 'preferred_time' => '09:00', 'type' => $unit === 'clinic' ? 'routine' : 'initial', 'reason' => 'A request'];
    }

    private function confirmation(array $appt, ?array $provider = null): array
    {
        return ['provider_user_id' => ($provider ?? $this->staff)['id'], 'starts_at' => $appt['local_date'] . 'T09:00',
            'ends_at' => $appt['local_date'] . 'T09:30', 'version' => $appt['version']];
    }

    private function rejected(callable $operation, int $code = 0): void
    {
        try {
            $operation();
        } catch (\InvalidArgumentException | \DomainException | \RuntimeException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
            return;
        }
        $this->fail('Expected appointment operation to be rejected.');
    }

    public function testPersonalRequestsIgnorePostedIdentityAndAllocationFields(): void
    {
        foreach (['clinic', 'guidance'] as $unit) {
            $data = $this->payload($unit) + ['person_id' => $this->staff['person_id'],
                'provider_user_id' => $this->staff['id'], 'status' => 'confirmed', 'tenant_id' => 999, 'version' => 55];
            $appt = $this->service->requestAppointment($unit, $data, $this->patient, true);
            $this->assertSame('requested', $appt['status']);
            $this->assertNull($appt['provider_user_id']);
            $this->assertSame((int) $this->patient['person_id'], (int) $appt['person_id']);
            $this->assertSame(1, (int) $appt['tenant_id']);
            $this->assertSame(1, (int) $appt['version']);
            $this->assertSame($this->bookingDate() . ' 09:30:00', $appt['ends_at']);
        }
    }

    public function testStaffRetainPersonalAccessToBothDepartments(): void
    {
        foreach ([$this->staff, $this->counsellor] as $actor) {
            foreach (['clinic', 'guidance'] as $unit) {
                $appt = $this->request($unit, $actor);
                $this->assertSame((int) $actor['person_id'], (int) $appt['person_id']);
            }
        }
    }

    public function testStaffRequestForAnotherPatientAndWrongDepartmentRejection(): void
    {
        $appt = $this->service->requestAppointment('clinic', $this->payload() + ['person_id' => $this->patient['person_id']], $this->staff);
        $this->assertSame((int) $this->patient['person_id'], (int) $appt['person_id']);
        $this->rejected(fn () => $this->service->requestAppointment('clinic', $this->payload(), $this->counsellor), 403);
    }

    public function testRequestValidation(): void
    {
        foreach ([['date' => '2026-02-30'], ['date' => '2026-2-03'], ['preferred_time' => '25:00'],
            ['preferred_time' => '9:00'], ['type' => 'initial'], ['type' => 'crisis'], ['reason' => str_repeat('x', 256)]] as $bad) {
            $this->rejected(fn () => $this->service->requestAppointment('clinic', array_replace($this->payload(), $bad), $this->patient, true));
        }
        $this->rejected(fn () => $this->service->requestAppointment('guidance', $this->payload(), $this->patient, true));
        $this->rejected(fn () => $this->request('unknown'));
        $actor = $this->patient;
        $actor['person_id'] = $this->staff['person_id'];
        $this->rejected(fn () => $this->request('clinic', $actor), 403);
    }

    public function testPendingRequestsRemainVisibleOutsideCalendarDate(): void
    {
        $appt = $this->request();
        $model = new AppointmentModel();
        $this->assertContains((int) $appt['id'], array_map('intval', array_column($model->getPendingRequests('clinic'), 'id')));
        $this->assertContains((int) $appt['id'], array_map('intval', array_column($model->getForDay('clinic', $this->bookingDate()), 'id')));
        $this->assertNotContains((int) $appt['id'], array_map('intval', array_column($model->getForDay('clinic', '2099-01-01'), 'id')));
        $this->assertNotContains((int) $appt['id'], array_map('intval', array_column($model->getPendingRequests('guidance'), 'id')));
    }

    private function chartAppointment(int $personId, int $tenantId, string $status, int $days, string $unit = 'clinic', ?int $providerId = null): int
    {
        $start = new \DateTimeImmutable($this->bookingDate() . ' 09:00:00');
        $start = $start->modify('+' . $days . ' days');
        $id = (new AppointmentModel())->insert([
            'tenant_id' => $tenantId, 'person_id' => $personId, 'unit' => $unit,
            'provider_user_id' => $status === 'requested' ? null : ($providerId ?? ($unit === 'clinic' ? $this->staff['id'] : $this->counsellor['id'])),
            'status' => $status, 'type' => $unit === 'clinic' ? 'routine' : 'initial',
            'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
            'local_date' => $start->format('Y-m-d'),
        ]);
        $this->assertNotFalse($id);
        return (int) $id;
    }

    public function testPatientChartKeepsAllActiveAppointmentsBeforeIndependentlyCappedHistory(): void
    {
        $personId = (int) $this->patient['person_id'];
        $historyIds = [];
        $historyStatuses = ['completed', 'cancelled', 'no_show'];
        for ($i = 0; $i < 15; $i++) {
            $historyIds[] = $this->chartAppointment($personId, 1, $historyStatuses[$i % 3], 100 + $i,
                $i % 2 === 0 ? 'clinic' : 'guidance');
        }
        $expectedHistory = array_reverse(array_slice($historyIds, -10));
        $service = new PatientHistoryService();
        $historyOnly = $service->getPatientChart($personId)['appointments'];
        $this->assertCount(10, $historyOnly);
        $this->assertSame($expectedHistory, array_map('intval', array_column($historyOnly, 'id')));

        $activeStatuses = ['requested', 'scheduled', 'confirmed', 'checked_in'];
        $laterActiveIds = [];
        for ($i = 0; $i < 12; $i++) {
            $laterActiveIds[] = $this->chartAppointment($personId, 1, $activeStatuses[$i % 4], 10 + $i,
                $i % 2 === 0 ? 'clinic' : 'guidance');
        }
        // Insert the earliest actionable rows last so neither insertion order nor a DESC limit can pass.
        $requestedId = $this->chartAppointment($personId, 1, 'requested', 0);
        $confirmedId = $this->chartAppointment($personId, 1, 'confirmed', 1, 'guidance');
        $appointments = $service->getPatientChart($personId)['appointments'];
        $expectedActive = array_merge([$requestedId, $confirmedId], $laterActiveIds);
        $this->assertCount(24, $appointments);
        $this->assertSame(array_merge($expectedActive, $expectedHistory), array_map('intval', array_column($appointments, 'id')));
        $active = array_values(array_filter($appointments, static fn (array $row): bool => in_array($row['status'], $activeStatuses, true)));
        $history = array_values(array_filter($appointments, static fn (array $row): bool => in_array($row['status'], $historyStatuses, true)));
        $this->assertCount(14, $active);
        $this->assertCount(10, $history);
        $this->assertSame($expectedHistory, array_map('intval', array_column($history, 'id')));
        $this->assertSame('requested', $appointments[0]['status']);
        $this->assertSame('confirmed', $appointments[1]['status']);
    }

    public function testPatientChartScopesPersonAndBothAppointmentBucketsToCurrentTenant(): void
    {
        $personId = (int) $this->patient['person_id'];
        $other = $this->user('student');
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('chart_tenant_'), 'name' => 'Chart Isolation',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $tenantId = (int) $this->db->insertID();
        $this->appointmentFixtureTenants[] = $tenantId;
        $foreign = $this->user('clinic_staff', $tenantId);
        $requestedId = $this->chartAppointment($personId, 1, 'requested', 0);
        $confirmedId = $this->chartAppointment($personId, 1, 'confirmed', 1, 'guidance');
        $completedId = $this->chartAppointment($personId, 1, 'completed', 20);
        $cancelledId = $this->chartAppointment($personId, 1, 'cancelled', 21);
        $foreignIds = [];
        foreach (['requested', 'confirmed', 'completed', 'cancelled', 'no_show'] as $i => $status) {
            $this->chartAppointment((int) $other['person_id'], 1, $status, 30 + $i);
            $foreignIds[] = $this->chartAppointment((int) $foreign['person_id'], $tenantId, $status, 40 + $i, 'clinic', (int) $foreign['id']);
            // Same-person foreign-tenant rows test tenant predicates independently of person filtering.
            $this->chartAppointment($personId, $tenantId, $status, 50 + $i, 'clinic', (int) $foreign['id']);
        }
        $service = new PatientHistoryService();
        $chart = $service->getPatientChart($personId);
        $this->assertSame($personId, (int) $chart['person']['id']);
        $this->assertSame([$requestedId, $confirmedId, $cancelledId, $completedId], array_map('intval', array_column($chart['appointments'], 'id')));
        foreach ($chart['appointments'] as $appointment) {
            $this->assertSame(1, (int) $appointment['tenant_id']);
            $this->assertSame($personId, (int) $appointment['person_id']);
        }
        $this->rejected(fn () => $service->getPatientChart((int) $foreign['person_id']));
        CurrentTenant::set($tenantId);
        try {
            $this->rejected(fn () => $service->getPatientChart($personId));
            $foreignChart = $service->getPatientChart((int) $foreign['person_id']);
            $this->assertSame([$foreignIds[0], $foreignIds[1], $foreignIds[4], $foreignIds[3], $foreignIds[2]],
                array_map('intval', array_column($foreignChart['appointments'], 'id')));
        } finally {
            CurrentTenant::set(1);
        }
    }

    public function testConfirmationAssignsAuditAndRejectsStaleVersion(): void
    {
        $appt = $this->request();
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'], array_replace($this->confirmation($appt), ['version' => 3]), $this->staff), 409);
        $confirmed = $this->service->confirmAppointment('clinic', (int) $appt['id'], $this->confirmation($appt), $this->staff);
        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertSame(2, (int) $confirmed['version']);
        $this->assertSame((int) $this->staff['id'], (int) $confirmed['provider_user_id']);
        $this->assertSame((int) $this->staff['id'], (int) $confirmed['confirmed_by_user_id']);
        $this->assertSame((int) $this->staff['id'], (int) $confirmed['assigned_by_user_id']);
        $this->assertNotEmpty($confirmed['confirmed_at']);
        $this->assertNotEmpty($confirmed['assigned_at']);
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'], $this->confirmation($appt), $this->staff), 409);
    }

    public function testConfirmationRequiresEligibilityAvailabilityAndActiveProvider(): void
    {
        $appt = $this->request();
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'], $this->confirmation($appt, $this->counsellor), $this->staff));
        $admin = $this->user('clinic_admin');
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'], $this->confirmation($appt, $admin), $this->staff));
        $this->availability($admin, 'clinic');
        $this->assertNotContains((int) $admin['id'], array_column($this->service->providers('clinic'), 'id'));
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'], $this->confirmation($appt, $admin), $this->staff));
        (new UserModel())->update($admin['id'], ['status' => 'suspended']);
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'], $this->confirmation($appt, $admin), $this->staff));
        $bad = array_replace($this->confirmation($appt), ['starts_at' => $this->bookingDate() . ' 17:00:00', 'ends_at' => $this->bookingDate() . ' 17:30:00']);
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'], $bad, $this->staff));
        (new AvailabilityExceptionModel())->insert(['tenant_id' => 1, 'provider_user_id' => $this->staff['id'],
            'exception_date' => $this->bookingDate(), 'is_unavailable' => 1]);
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'], $this->confirmation($appt), $this->staff));
    }

    public function testConfirmationRejectsOffGridStartsAndWrongDurationsWithoutMutation(): void
    {
        $appt = $this->request();
        foreach ([['09:05:00', '09:06:00'], ['09:05:00', '09:35:00'], ['09:00:00', '09:15:00'],
            ['09:00:00', '10:00:00'], ['09:00:01', '09:30:01']] as [$start, $end]) {
            $data = array_replace($this->confirmation($appt), ['starts_at' => $this->bookingDate() . ' ' . $start,
                'ends_at' => $this->bookingDate() . ' ' . $end]);
            $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'], $data, $this->staff));
            $unchanged = $this->service->appointment('clinic', (int) $appt['id']);
            $this->assertSame('requested', $unchanged['status']);
            $this->assertSame(1, (int) $unchanged['version']);
            $this->assertNull($unchanged['provider_user_id']);
            $this->assertNull($unchanged['confirmed_at']);
        }
    }

    public function testConfirmationUsesWindowAnchoredGridRatherThanWallClockBoundaries(): void
    {
        $model = new ProviderAvailabilityModel();
        $window = $model->scoped()->where('provider_user_id', $this->staff['id'])->where('unit', 'clinic')->first();
        $model->update($window['id'], ['start_time' => '08:05:00', 'end_time' => '09:00:00']);
        $appt = $this->request();
        $bad = array_replace($this->confirmation($appt), ['starts_at' => $this->bookingDate() . ' 08:15:00',
            'ends_at' => $this->bookingDate() . ' 08:45:00']);
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'], $bad, $this->staff));
        $slots = $this->service->getAvailableSlots('clinic', (int) $this->staff['id'], $this->bookingDate());
        $this->assertCount(1, $slots);
        $this->assertSame('08:05', $slots[0]['slot_time']);
        $confirmed = $this->service->confirmAppointment('clinic', (int) $appt['id'],
            array_replace($this->confirmation($appt), $slots[0]), $this->staff);
        $this->assertSame('confirmed', $confirmed['status']);
    }

    public function testConfirmationMatchesGeneratorMinimumTenMinuteStepForLegacyWindows(): void
    {
        $model = new ProviderAvailabilityModel();
        $window = $model->scoped()->where('provider_user_id', $this->staff['id'])->where('unit', 'clinic')->first();
        $model->update($window['id'], ['slot_duration_minutes' => 5]);
        $appt = $this->request();
        $bad = array_replace($this->confirmation($appt), ['starts_at' => $this->bookingDate() . ' 09:00:00',
            'ends_at' => $this->bookingDate() . ' 09:05:00']);
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'], $bad, $this->staff));
        $slots = $this->service->getAvailableSlots('clinic', (int) $this->staff['id'], $this->bookingDate());
        $this->assertSame($this->bookingDate() . ' 08:10:00', $slots[0]['ends_at']);
        $confirmed = $this->service->confirmAppointment('clinic', (int) $appt['id'],
            array_replace($this->confirmation($appt), $slots[0]), $this->staff);
        $this->assertSame('confirmed', $confirmed['status']);
    }

    public function testConfirmationRechecksDurationAfterAvailabilityChanges(): void
    {
        $appt = $this->request();
        $selection = $this->service->getAvailableSlots('clinic', (int) $this->staff['id'], $this->bookingDate())[2];
        $this->assertSame('09:00', $selection['slot_time']);
        $admin = $this->user('clinic_admin');
        $this->service->saveAvailability('clinic', ['provider_user_id' => $this->staff['id'],
            'day_of_week' => date('w', strtotime($this->bookingDate())), 'start_time' => '08:00', 'end_time' => '17:00',
            'slot_duration_minutes' => 60, 'is_active' => 1], $admin);
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'],
            array_replace($this->confirmation($appt), $selection), $admin));
        $this->assertSame(1, (int) $this->service->appointment('clinic', (int) $appt['id'])['version']);
        $fresh = $this->service->getAvailableSlots('clinic', (int) $this->staff['id'], $this->bookingDate())[1];
        $this->assertSame($this->bookingDate() . ' 10:00:00', $fresh['ends_at']);
        $confirmed = $this->service->confirmAppointment('clinic', (int) $appt['id'],
            array_replace($this->confirmation($appt), $fresh), $admin);
        $this->assertSame('confirmed', $confirmed['status']);
    }

    public function testCheckInRequiresCurrentClinicalDepartmentRoleAfterConfirmation(): void
    {
        $actor = $this->user('superadmin');
        foreach (['clinic', 'guidance'] as $unit) {
            $clinicalRole = $unit === 'clinic' ? 'clinic_staff' : 'counsellor';
            $changes = [['role' => $unit === 'clinic' ? 'clinic_admin' : 'guidance_admin'],
                ['role' => $unit === 'clinic' ? 'counsellor' : 'clinic_staff'], ['role' => 'employee'],
                ['status' => 'suspended'], ['status' => 'locked'], ['deleted' => true]];
            foreach ($changes as $change) {
                $provider = $this->user($clinicalRole);
                $appt = $this->service->bookAppointment(['unit' => $unit, 'person_id' => $this->patient['person_id'],
                    'provider_user_id' => $provider['id'], 'starts_at' => date('Y-m-d') . ' 09:00:00',
                    'ends_at' => date('Y-m-d') . ' 09:30:00']);
                // A persisted confirmed fixture allows same-day check-in without changing the clock.
                (new AppointmentModel())->update($appt['id'], ['status' => 'confirmed',
                    'confirmed_by_user_id' => $actor['id'], 'confirmed_at' => date('Y-m-d H:i:s')]);
                if (isset($change['deleted'])) {
                    (new UserModel())->delete($provider['id']);
                } else {
                    (new UserModel())->update($provider['id'], $change);
                }
                $counter = $this->db->table('daily_ticket_counters')->where('tenant_id', 1)
                    ->where('unit', $unit)->where('queue_date', date('Y-m-d'))->get()->getRowArray();
                $this->rejected(fn () => (new QueueService())->enqueueAppointment((int) $appt['id'], $unit, $actor), 409);
                $this->assertSame('confirmed', $this->service->appointment($unit, (int) $appt['id'])['status']);
                $this->assertSame(1, (int) $this->service->appointment($unit, (int) $appt['id'])['version']);
                $this->assertSame(0, $this->db->table('queue_entries')->where('appointment_id', $appt['id'])->countAllResults());
                $this->assertSame($counter, $this->db->table('daily_ticket_counters')->where('tenant_id', 1)
                    ->where('unit', $unit)->where('queue_date', date('Y-m-d'))->get()->getRowArray());
            }
        }
    }

    public function testCheckInRejectsLegacyAdministratorAssignmentsButAllowsClinicalProviders(): void
    {
        $actor = $this->user('superadmin');
        foreach (['clinic', 'guidance'] as $unit) {
            $admin = $this->user($unit === 'clinic' ? 'clinic_admin' : 'guidance_supervisor');
            $legacy = $this->service->bookAppointment(['unit' => $unit, 'person_id' => $this->patient['person_id'],
                'provider_user_id' => $admin['id'], 'starts_at' => date('Y-m-d') . ' 09:00:00',
                'ends_at' => date('Y-m-d') . ' 09:30:00']);
            $this->rejected(fn () => (new QueueService())->enqueueAppointment((int) $legacy['id'], $unit, $actor), 409);
            $this->assertSame('scheduled', $this->service->appointment($unit, (int) $legacy['id'])['status']);
            $provider = $unit === 'clinic' ? $this->staff : $this->counsellor;
            $booking = $this->service->bookAppointment(['unit' => $unit, 'person_id' => $this->patient['person_id'],
                'provider_user_id' => $provider['id'], 'starts_at' => date('Y-m-d') . ' 09:00:00',
                'ends_at' => date('Y-m-d') . ' 09:30:00']);
            $entry = (new QueueService())->enqueueAppointment((int) $booking['id'], $unit, $actor);
            $this->assertSame($unit, $entry['unit']);
            $this->assertSame('checked_in', $this->service->appointment($unit, (int) $booking['id'])['status']);
        }
    }

    public function testOverlapBlocksConfirmationButPendingAndAdjacentIntervalsDoNot(): void
    {
        $first = $this->request();
        $second = $this->request();
        $this->service->confirmAppointment('clinic', (int) $first['id'], $this->confirmation($first), $this->staff);
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $second['id'], $this->confirmation($second), $this->staff), 409);
        $adjacent = array_replace($this->confirmation($second), ['starts_at' => $this->bookingDate() . ' 09:30:00', 'ends_at' => $this->bookingDate() . ' 10:00:00']);
        $confirmed = $this->service->confirmAppointment('clinic', (int) $second['id'], $adjacent, $this->staff);
        $this->assertSame('confirmed', $confirmed['status']);
    }

    public function testGuidanceConfirmationAndCrossUnitProviderConflict(): void
    {
        $guidance = $this->request('guidance');
        $confirmed = $this->service->confirmAppointment('guidance', (int) $guidance['id'],
            $this->confirmation($guidance, $this->counsellor), $this->counsellor);
        $this->assertSame('confirmed', $confirmed['status']);
        $this->service->bookAppointment(['unit' => 'guidance', 'person_id' => $this->patient['person_id'],
            'provider_user_id' => $this->staff['id'], 'starts_at' => $this->bookingDate() . ' 09:00:00',
            'ends_at' => $this->bookingDate() . ' 09:30:00']);
        $clinic = $this->request();
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $clinic['id'],
            $this->confirmation($clinic), $this->staff), 409);
    }

    public function testCustomHoursCannotGrantWrongDepartmentAndDeletedProvidersCannotBeAssigned(): void
    {
        (new AvailabilityExceptionModel())->insert(['tenant_id' => 1, 'provider_user_id' => $this->counsellor['id'],
            'exception_date' => $this->bookingDate(), 'is_unavailable' => 0,
            'custom_start_time' => '10:00:00', 'custom_end_time' => '12:00:00']);
        $this->rejected(fn () => $this->service->getAvailableSlots('clinic', (int) $this->counsellor['id'], $this->bookingDate()));
        $slots = $this->service->getAvailableSlots('guidance', (int) $this->counsellor['id'], $this->bookingDate());
        $this->assertCount(4, $slots);
        $this->assertSame('10:00', $slots[0]['slot_time']);
        $appt = $this->request();
        (new UserModel())->delete($this->staff['id']);
        $admin = $this->user('clinic_admin');
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'], $this->confirmation($appt), $admin));
    }

    public function testCheckInRejectsOtherDates(): void
    {
        $appt = $this->service->bookAppointment(['unit' => 'clinic', 'person_id' => $this->patient['person_id'],
            'provider_user_id' => $this->staff['id'], 'starts_at' => '2099-01-01 09:00:00', 'ends_at' => '2099-01-01 09:30:00']);
        $this->rejected(fn () => (new QueueService())->enqueueAppointment((int) $appt['id'], 'clinic', $this->staff), 409);
    }

    public function testOwnerAndStaffCancellationGuardStatusUnitAndTenant(): void
    {
        $appt = $this->request();
        $id = (int) $appt['id'];
        $other = $this->user('employee');
        $this->rejected(fn () => $this->service->cancelAppointment($id, 'Other patient', 'clinic', $other, true), 403);
        $this->rejected(fn () => $this->service->cancelAppointment($id, 'Wrong department', 'guidance', $this->counsellor), 404);
        CurrentTenant::set(999);
        $this->rejected(fn () => $this->service->cancelAppointment($id, 'Wrong tenant'), 404);
        CurrentTenant::set(1);
        $this->assertTrue($this->service->cancelAppointment($id, 'Owner', 'clinic', $this->patient, true));
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', $id, $this->confirmation($appt), $this->staff), 409);
        $this->rejected(fn () => $this->service->cancelAppointment($id, 'Again', 'clinic', $this->staff), 409);
        $this->rejected(fn () => (new QueueService())->enqueueAppointment($id, 'clinic', $this->staff), 409);
    }

    public function testCheckInIsIdempotentAndRejectsRequestedCancelledAndWrongUnit(): void
    {
        $queue = new QueueService();
        $request = $this->request();
        $this->rejected(fn () => $queue->enqueueAppointment((int) $request['id'], 'clinic', $this->staff), 409);
        $appt = $this->checkInBooking();
        $id = (int) $appt['id'];
        $this->rejected(fn () => $queue->enqueueAppointment($id, 'guidance', $this->counsellor), 404);
        $this->rejected(fn () => $queue->enqueueAppointment($id, 'clinic', $this->patient), 403);
        $one = $queue->enqueueAppointment($id, 'clinic', $this->staff);
        $two = $queue->enqueueAppointment($id, 'clinic', $this->staff);
        $this->assertSame((int) $one['id'], (int) $two['id']);
        $this->assertSame(1, $this->db->table('queue_entries')->where('appointment_id', $id)->countAllResults());
        $this->rejected(fn () => $this->service->cancelAppointment($id, 'Too late', 'clinic', $this->patient, true), 409);
    }

    public function testForeignTenantPeopleProvidersAndAppointmentsAreRejected(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('request_tenant_'), 'name' => 'Request Isolation',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $tenant = (int) $this->db->insertID();
        $this->appointmentFixtureTenants[] = $tenant;
        $foreign = $this->user('clinic_staff', $tenant);
        $this->availability($foreign, 'clinic');
        $this->rejected(fn () => $this->service->requestAppointment('clinic',
            $this->payload() + ['person_id' => $foreign['person_id']], $this->staff));
        $appt = $this->request();
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'],
            $this->confirmation($appt, $foreign), $this->staff));
        $this->rejected(fn () => $this->service->getAvailableSlots('clinic', (int) $foreign['id'], $this->bookingDate()));
        CurrentTenant::set($tenant);
        $this->rejected(fn () => $this->service->appointment('clinic', (int) $appt['id']), 404);
        $this->rejected(fn () => (new QueueService())->enqueueAppointment((int) $appt['id'], 'clinic', $foreign), 404);
        $this->rejected(fn () => $this->service->confirmAppointment('clinic', (int) $appt['id'],
            $this->confirmation($appt, $foreign), $foreign), 404);
        $this->rejected(fn () => $this->service->cancelAppointment((int) $appt['id'], 'Foreign', 'clinic', $foreign), 404);
        CurrentTenant::set(1);
        $this->assertSame('requested', $this->service->appointment('clinic', (int) $appt['id'])['status']);
    }

    public function testConcurrentConfirmationsSerializeProviderAndAppointment(): void
    {
        $first = $this->request();
        $second = $this->request();
        $results = $this->race([
            ['operation' => 'confirm', 'id' => (int) $first['id'], 'data' => $this->confirmation($first)],
            ['operation' => 'confirm', 'id' => (int) $second['id'], 'data' => $this->confirmation($second)],
        ], 'users', (int) $this->staff['id']);
        $codes = array_column($results, 'code');
        sort($codes);
        $this->assertSame([200, 409], $codes);
        $third = $this->request();
        $data = array_replace($this->confirmation($third), ['starts_at' => $this->bookingDate() . ' 11:00:00', 'ends_at' => $this->bookingDate() . ' 11:30:00']);
        $results = $this->race([
            ['operation' => 'confirm', 'id' => (int) $third['id'], 'data' => $data],
            ['operation' => 'confirm', 'id' => (int) $third['id'], 'data' => $data],
        ], 'users', (int) $this->staff['id']);
        $codes = array_column($results, 'code');
        sort($codes);
        $this->assertSame([200, 409], $codes);
    }

    public function testLegacyBookingSharesConfirmationMutex(): void
    {
        $appt = $this->request();
        $results = $this->race([
            ['operation' => 'confirm', 'id' => (int) $appt['id'], 'data' => $this->confirmation($appt)],
            ['operation' => 'book', 'data' => ['unit' => 'clinic', 'person_id' => $this->patient['person_id'],
                'provider_user_id' => $this->staff['id'], 'starts_at' => $this->bookingDate() . ' 09:00:00', 'ends_at' => $this->bookingDate() . ' 09:30:00']],
        ], 'users', (int) $this->staff['id']);
        $codes = array_column($results, 'code');
        sort($codes);
        $this->assertSame([200, 409], $codes);
    }

    public function testCancellationCannotBeOverwrittenByConcurrentConfirmation(): void
    {
        $appt = $this->request();
        $results = $this->race([
            ['operation' => 'confirm', 'id' => (int) $appt['id'], 'data' => $this->confirmation($appt)],
            ['operation' => 'cancel', 'id' => (int) $appt['id']],
        ], 'appointments', (int) $appt['id']);
        $this->assertContains($results[0]['code'], [200, 409]);
        $this->assertSame(200, $results[1]['code']);
        $this->assertSame('cancelled', $this->service->appointment('clinic', (int) $appt['id'])['status']);
    }

    public function testConcurrentAvailabilityUpsertsKeepOneWeekdayWindow(): void
    {
        $provider = $this->user('clinic_staff');
        $admin = $this->user('clinic_admin');
        $data = ['provider_user_id' => $provider['id'], 'day_of_week' => 0, 'start_time' => '09:00',
            'end_time' => '11:00', 'slot_duration_minutes' => 30, 'is_active' => 1];
        $results = $this->race([
            ['operation' => 'availability', 'data' => $data, 'actor' => $admin],
            ['operation' => 'availability', 'data' => $data, 'actor' => $admin],
        ], 'users', (int) $provider['id']);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, (new ProviderAvailabilityModel())->scoped()->where('unit', 'clinic')
            ->where('provider_user_id', $provider['id'])->where('day_of_week', 0)->countAllResults());
    }

    private function checkInBooking(): array
    {
        return $this->service->bookAppointment(['unit' => 'clinic', 'person_id' => $this->patient['person_id'],
            'provider_user_id' => $this->staff['id'], 'starts_at' => date('Y-m-d') . ' 09:00:00',
            'ends_at' => date('Y-m-d') . ' 09:30:00']);
    }

    public function testConcurrentCheckInCreatesOneTicket(): void
    {
        $appt = $this->checkInBooking();
        $results = $this->race([
            ['operation' => 'checkin', 'id' => (int) $appt['id']],
            ['operation' => 'checkin', 'id' => (int) $appt['id']],
        ], 'appointments', (int) $appt['id']);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, $this->db->table('queue_entries')->where('appointment_id', $appt['id'])->countAllResults());
    }

    private function race(array $jobs, string $table, int $id): array
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for independent-connection concurrency tests.');
        }
        $workers = [];
        $this->db->transBegin();
        try {
            $this->db->query('SELECT id FROM ' . $this->db->prefixTable($table) . ' WHERE id = ? FOR UPDATE', [$id]);
            foreach ($jobs as $job) {
                $process = proc_open([PHP_BINARY, ROOTPATH . 'tests/_support/appointment-race-worker.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
                fwrite($pipes[0], json_encode($job + ['actor' => $this->staff, 'database' => config('Database')->tests], JSON_THROW_ON_ERROR) . "\n");
                stream_set_timeout($pipes[1], 15);
                $this->assertSame("READY\n", fgets($pipes[1]));
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "GO\n");
                fclose($pipes[0]);
            }
            usleep(100000);
            $this->db->transCommit();
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $line = fgets($pipes[1]);
                $this->assertIsString($line, 'Worker timed out.');
                $results[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('', stream_get_contents($pipes[2]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process));
            }
            $workers = [];
            return $results;
        } finally {
            $this->db->transRollback();
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }
}

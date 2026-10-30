<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppointmentModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AppointmentFixtureCleanup;

final class PortalAppointmentRoutesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AppointmentFixtureCleanup;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\\Database\\Seeds\\PermissionsAndRolesSeeder';

    private array $student;
    private array $staff;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
        $this->student = $this->user('student');
        $this->staff = $this->user('clinic_staff');
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->cleanupAppointmentFixtures();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function user(string $role): array
    {
        $unique = uniqid('portal_fixture_', true);
        $person = (int) (new PersonModel())->insert([
            'tenant_id' => 1,
            'kind' => $role === 'student' ? 'student' : 'employee',
            'mis_id' => $unique,
            'first_name' => 'Portal',
            'last_name' => 'Fixture',
        ]);
        $id = (int) (new UserModel())->insert([
            'tenant_id' => 1,
            'person_id' => $person,
            'username' => $unique,
            'role' => $role,
            'status' => 'active',
        ]);
        $this->appointmentFixturePeople[] = $person;
        $this->appointmentFixtureUsers[] = $id;
        return (new UserModel())->find($id) + ['logged_in' => true];
    }

    private function sessionFor(array $user): array
    {
        return [
            'user' => $user,
            'tenant_id' => 1,
            '_auth' => [
                'started_at' => time(),
                'last_seen_at' => time(),
                'version' => 1,
                'session_id' => session_id(),
            ],
        ];
    }

    private function appointment(string $unit, string $status, string $date, string $reason): int
    {
        $start = $date . ' 09:00:00';
        return (int) (new AppointmentModel())->insert([
            'tenant_id' => 1,
            'unit' => $unit,
            'person_id' => $this->student['person_id'],
            'starts_at' => $start,
            'ends_at' => date('Y-m-d H:i:s', strtotime($start . ' +30 minutes')),
            'local_date' => $date,
            'status' => $status,
            'type' => $unit === 'clinic' ? 'routine' : 'initial',
            'reason' => $reason,
            'version' => 1,
        ]);
    }

    public function testPortalFiltersDetailOwnershipAndSidebarScope(): void
    {
        $future = date('Y-m-d', strtotime('+40 days'));
        $past = date('Y-m-d', strtotime('-40 days'));
        $clinicId = $this->appointment('clinic', 'requested', $future, 'CLINIC DETAIL FIXTURE');
        $guidanceId = $this->appointment('guidance', 'completed', $past, 'GUIDANCE DETAIL FIXTURE');
        $this->db->table('appointments')->where('id', $guidanceId)->update([
            'created_at' => '2026-10-22 01:57:00', 'confirmed_at' => '2026-10-22 09:57:00',
        ]);
        $this->assertNotSame($clinicId, $guidanceId);

        $page = $this->withSession($this->sessionFor($this->student))->withHeaders([])
            ->get('portal?service=guidance&status=completed&sort=date_desc');
        $page->assertStatus(200);
        $body = $page->response()->getBody();
        $this->assertStringContainsString('Guidance &amp; Counseling', $body);
        $this->assertStringContainsString(date('M j, Y', strtotime($past)), $body);
        $this->assertStringNotContainsString(date('M j, Y', strtotime($future)), $body);
        $this->assertStringNotContainsString('>Personal Health</li>', $body);
        $this->assertStringNotContainsString('Apply filters', $body);
        $this->assertStringNotContainsString('Filters update automatically', $body);
        $this->assertStringContainsString('Showing 1–1 of 1', $body);
        $this->assertStringContainsString('(2 total appointments)', $body);

        $unfiltered = $this->withSession($this->sessionFor($this->student))->withHeaders([])->get('portal');
        $unfiltered->assertStatus(200);
        $unfilteredBody = $unfiltered->response()->getBody();
        $this->assertStringNotContainsString('Apply filters', $unfilteredBody);
        $this->assertStringNotContainsString('Filters update automatically', $unfilteredBody);
        $this->assertStringContainsString('Showing 1–2 of 2', $unfilteredBody);

        $detail = $this->withSession($this->sessionFor($this->student))->withHeaders([])
            ->get('portal/appointments/' . $guidanceId);
        $detail->assertStatus(200);
        $detailBody = $detail->response()->getBody();
        $this->assertStringContainsString('GUIDANCE DETAIL FIXTURE', $detailBody);
        $this->assertStringContainsString('Completed', $detailBody);
        $this->assertStringContainsString('Reference #' . $guidanceId, $detailBody);
        $this->assertStringContainsString('Oct 22, 2026 · 9:57 AM', $detailBody);
        $this->assertStringNotContainsString('Oct 22, 2026 · 1:57 AM', $detailBody);
        $this->assertStringNotContainsString('CLINIC DETAIL FIXTURE', $detailBody);

        $fragment = $this->withSession($this->sessionFor($this->student))
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get('portal/appointments/' . $guidanceId . '?fragment=1');
        $fragment->assertStatus(200);
        $this->assertStringContainsString('data-app-form-modal', $fragment->response()->getBody());

        $this->withSession($this->sessionFor($this->staff))->withHeaders([])
            ->get('portal/appointments/' . $guidanceId)->assertStatus(404);
        $other = $this->user('student');
        $this->withSession($this->sessionFor($other))->withHeaders([])
            ->get('portal/appointments/' . $guidanceId)->assertStatus(404);

        $staffPage = $this->withSession($this->sessionFor($this->staff))->withHeaders([])->get('portal');
        $staffPage->assertStatus(200);
        $this->assertStringContainsString('>Personal Health</li>', $staffPage->response()->getBody());
    }
}

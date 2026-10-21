<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PersonModel;
use App\Models\QueueEntryModel;
use App\Services\Scheduling\QueueService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class QueueRoutesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $userModel = new \App\Models\UserModel();
        $existing = $userModel->where('username', 'staff_test')->first();
        if ($existing) {
            $this->testUserId = (int) $existing['id'];
        } else {
            $this->testUserId = (int) $userModel->insert([
                'tenant_id' => 1,
                'username'  => 'staff_test',
                'role'      => 'clinic_staff',
                'status'    => 'active',
            ]);
        }
    }

    private function authSession(string $role = 'clinic_staff'): array
    {
        return [
            'user' => [
                'id'          => $this->testUserId,
                'name'        => 'Staff Nurse',
                'username'    => 'staff_test',
                'role'        => $role,
                'permissions' => ['clinic.queue.read', 'clinic.queue.manage', 'clinic.appointments.read'],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
        ];
    }

    public function testQueuePageRendersKanbanBoard(): void
    {
        $res = $this->withSession($this->authSession())->get('clinic/queue');

        $res->assertStatus(200);
        $res->assertSee('University Clinic Queue Board');
        $res->assertSee('Waiting Line');
        $res->assertSee('In Consultation');
    }

    public function testHtmxBoardEndpointReturnsPartial(): void
    {
        $res = $this->withSession($this->authSession())->get('clinic/queue/board');

        $res->assertStatus(200);
        $res->assertSee('Waiting Line');
        $res->assertDontSee('<!DOCTYPE html>'); // Must be clean fragment, not full layout
    }

    public function testCallNextAdvancesPatient(): void
    {
        $personModel = new PersonModel();
        $person = (int) $personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'CALL_NEXT_TEST',
            'first_name' => 'Next',
            'last_name'  => 'Patient',
        ]);

        $queueService = new QueueService();
        $entry = $queueService->enqueueWalkIn('clinic', $person, 'routine');

        $res = $this->withSession($this->authSession())
            ->withHeaders(['HX-Request' => 'true'])
            ->post('clinic/queue/call-next');

        $res->assertStatus(200);
        $res->assertSee($entry['ticket_code']);
        $res->assertSee('Start Visit'); // Appears in Called column
    }

    public function testPatientSearchApiReturnsJson(): void
    {
        $res = $this->withSession($this->authSession())
            ->get('api/patients/search?q=Dela');

        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $body = json_decode($res->getJSON(), true);
        $this->assertIsArray($body['results']);
    }
}

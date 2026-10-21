<?php

declare(strict_types=1);

namespace Tests\Feature;


use Config\Services;
use Tests\Support\InventoryDatabaseTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class ClinicRoutesTest extends InventoryDatabaseTestCase
{
    use FeatureTestTrait;

    private int $staffUserId;
    private array $inventoryGrants = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->staffUserId = $this->user('clinic_staff');
        foreach (['clinic.inventory.read', 'clinic.inventory.write', 'clinic.encounters.read', 'clinic.encounters.write', 'clinic.patients.read', 'clinic.encounters.create'] as $code) {
            $permission = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
            if ($permission === null) {
                $this->db->table('permissions')->insert(['code' => $code, 'name' => $code, 'module' => 'clinic']);
                $permission = ['id' => $this->db->insertID()];
            }
            $id = (int) $permission['id'];
            $existed = $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $id)->countAllResults() > 0;
            $this->inventoryGrants[$id] = $existed;
            if (!$existed) {
                $this->db->table('role_permissions')->insert(['role' => 'clinic_staff', 'permission_id' => $id]);
            }
        }
        $this->db->table('people')->where('id', $this->patientId)->update(['first_name' => 'Feat']);
        $this->db->table('clinic_encounters')->where('id', $this->encounterId)
            ->update(['chief_complaint' => 'Mild cough and cold', 'attending_user_id' => $this->staffUserId]);
    }

    private function authSession(): array
    {
        return [
            'user' => [
                'id'          => $this->staffUserId,
                'name'        => 'Clinic Nurse',
                'username'    => 'nurse_test',
                'role'        => 'clinic_staff',
                'permissions' => ['clinic.encounters.read', 'clinic.encounters.create', 'clinic.encounters.write', 'clinic.patients.read', 'clinic.patients.write'],
                'logged_in'   => true,
            ],
            'tenant_id' => $this->tenantId,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->inventoryGrants as $id => $existed) {
            if (!$existed) {
                $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $id)->delete();
            }
        }
        Services::resetSingle('security');
        service('superglobals')->setCookieArray([]);
        parent::tearDown();
    }

    private function csrfData(): array
    {
        $hash = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
        Services::resetSingle('security');
        return [config('Security')->tokenName => $hash];
    }

    public function testCompleteRequiresTokenAndValidTokenCompletesEncounter(): void
    {
        $path = 'clinic/encounters/' . $this->encounterId . '/complete';
        $this->withSession($this->authSession())->post($path, ['disposition' => 'returned_to_class'])->assertStatus(403);
        $this->assertSame('in_progress', $this->db->table('clinic_encounters')->where('id', $this->encounterId)->get()->getRow()->status);
        $this->withSession($this->authSession())->post($path, $this->csrfData() + ['disposition' => 'returned_to_class'])
            ->assertRedirectTo('/clinic/encounters');
        $this->assertSame('completed', $this->db->table('clinic_encounters')->where('id', $this->encounterId)->get()->getRow()->status);
    }

    public function testDispenseRequiresCsrfAndExplicitIdempotencyKey(): void
    {
        $this->receipt();
        $path = 'clinic/encounters/' . $this->encounterId . '/dispense';
        $this->withSession($this->authSession())->post($path, ['medicine_id' => $this->medicineId, 'quantity' => '1'])->assertStatus(403);
        $this->withSession($this->authSession())->post($path, $this->csrfData() + [
            'medicine_id' => (string) $this->medicineId, 'quantity' => '1', 'idempotency_key' => bin2hex(random_bytes(32)),
        ])->assertRedirectTo('/clinic/encounters/' . $this->encounterId);
        $this->assertBalanced(9);
    }

    public function testEncountersListRenders(): void
    {
        $res = $this->withSession($this->authSession())->get('clinic/encounters');

        $res->assertStatus(200);
        $res->assertSee('Patient Clinic Encounters');
        $res->assertSee('Mild cough and cold');
    }

    public function testEncounterStepperRenders(): void
    {
        $res = $this->withSession($this->authSession())->get('clinic/encounters/' . $this->encounterId);

        $res->assertStatus(200);
        $res->assertSee('Triage');
        $res->assertSee('Assessment');
        $res->assertSee('Dispensing');
        $res->assertSee('Outcome');
    }

    public function testSaveVitalsPersistsData(): void
    {
        $res = $this->withSession($this->authSession())->post('clinic/encounters/' . $this->encounterId . '/vitals', [
            'bp_systolic'  => '118',
            'bp_diastolic' => '78',
            'pulse_bpm'    => '72',
            'temp_c'       => '36.5',
            'weight_kg'    => '60.0',
            'height_cm'    => '165.0',
        ]);

        $res->assertRedirectTo('/clinic/encounters/' . $this->encounterId);

        $vitalsModel = new \App\Models\ClinicVitalsModel();
        $vitals = $vitalsModel->findByEncounterId($this->encounterId);
        $this->assertNotNull($vitals);
        $this->assertSame(118, (int) $vitals['bp_systolic']);
        $this->assertSame('22.04', (string) $vitals['bmi']);
    }

    public function testPatientDirectoryAndChart(): void
    {
        // 1. Directory
        $dirRes = $this->withSession($this->authSession())->get('clinic/patients');
        $dirRes->assertStatus(200);
        $dirRes->assertSee('Patient Registry Directory');
        $dirRes->assertSee('Patient, Feat');

        // 2. Chart
        $chartRes = $this->withSession($this->authSession())->get('clinic/patients/' . $this->patientId);
        $chartRes->assertStatus(200);
        $chartRes->assertSee('Visit History');
        $chartRes->assertSee('Update Health Profile');
    }

    public function testUpdateHealthProfile(): void
    {
        $res = $this->withSession($this->authSession())->post('clinic/patients/' . $this->patientId . '/health', [
            'blood_type'                     => 'O+',
            'allergies'                      => 'Penicillin, Shellfish',
            'emergency_contact_name'         => 'Maria Patient',
            'emergency_contact_phone'        => '09171234567',
            'emergency_contact_relationship' => 'Mother',
        ]);

        $res->assertRedirectTo('/clinic/patients/' . $this->patientId);

        $healthModel = new \App\Models\HealthProfileModel();
        $health = $healthModel->findByPersonId($this->patientId);
        $this->assertNotNull($health);
        $this->assertSame('O+', $health['blood_type']);
        $this->assertSame('Penicillin, Shellfish', $health['allergies']);
    }
}

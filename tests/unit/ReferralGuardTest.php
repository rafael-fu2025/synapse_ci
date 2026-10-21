<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ClinicEncounterModel;
use App\Models\PersonModel;
use App\Models\ReferralModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Referrals\ReferralService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use RuntimeException;

/** Run only in the isolated test database harness. */
final class ReferralGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private ReferralService $service;
    private array $clinic;
    private array $guidance;
    private int $patient;

    protected function setUp(): void
    {
        \Tests\Support\InventoryTestDatabase::verify();
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);
        $this->service = new ReferralService();
        $this->patient = $this->person();
        $this->clinic = $this->user('clinic_staff');
        $this->guidance = $this->user('counsellor');
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        parent::tearDown();
    }

    private function person(int $tenant = 1): int
    {
        return (int) (new PersonModel())->insert([
            'tenant_id' => $tenant, 'kind' => 'student', 'mis_id' => uniqid('guard_person_'),
            'first_name' => 'Guard', 'last_name' => 'Patient',
        ]);
    }

    private function user(string $role, int $tenant = 1): array
    {
        $id = (int) (new UserModel())->insert([
            'tenant_id' => $tenant, 'person_id' => $this->person($tenant),
            'username' => uniqid('guard_user_'), 'role' => $role, 'status' => 'active',
        ]);
        return ['id' => $id, 'tenant_id' => $tenant, 'role' => 'superadmin', 'permissions' => ['*']];
    }

    private function data(): array
    {
        return ['patient_person_id' => $this->patient, 'source_module' => 'clinic',
            'target_module' => 'guidance', 'issuer_user_id' => $this->clinic['id'], 'reason_code' => 'academic_distress',
                        'reason_description' => 'Patient requests academic support.'];
    }

    private function denied(int $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected referral guard to reject operation.');
        } catch (RuntimeException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
        }
    }

    public function testOnlyFreshDepartmentStaffCanIssueWithoutImpersonation(): void
    {
        foreach (['student', 'employee', 'bmg_admin', 'audit_reader'] as $role) {
            $actor = $this->user($role);
            $this->denied(403, fn () => $this->service->createReferral(
                array_replace($this->data(), ['issuer_user_id' => $actor['id']]), $actor));
        }
        $this->denied(403, fn () => $this->service->createReferral($this->data(), $this->guidance));
        $this->denied(403, fn () => $this->service->createReferral(array_replace($this->data(), [
            'source_module' => 'guidance', 'target_module' => 'clinic',
        ]), $this->clinic));
        (new UserModel())->update($this->clinic['id'], ['status' => 'suspended']);
        $this->denied(403, fn () => $this->service->createReferral($this->data()));
    }

    public function testStrictIdsPatientTenantDeletedAndEncounterRelationship(): void
    {
        foreach ([0, -1, '1junk', '1.0', true, [], '999999999999999999999999'] as $bad) {
            $this->denied(422, fn () => $this->service->createReferral(array_replace($this->data(), ['patient_person_id' => $bad])));
        }
        $foreign = $this->person(2);
        $deleted = $this->person();
        (new PersonModel())->delete($deleted);
        foreach ([$foreign, $deleted] as $bad) {
            $this->denied(422, fn () => $this->service->createReferral(array_replace($this->data(), ['patient_person_id' => $bad])));
        }
        $encounter = (int) (new ClinicEncounterModel())->insert([
            'tenant_id' => 1, 'person_id' => $this->person(), 'attending_user_id' => $this->clinic['id'],
            'status' => 'in_progress', 'chief_complaint' => 'Private', 'started_at' => date('Y-m-d H:i:s'),
        ]);
        $this->denied(422, fn () => $this->service->createReferral($this->data() + ['source_encounter_id' => $encounter]));
        $this->denied(422, fn () => $this->service->createReferral(array_replace($this->data(), ['target_module' => 'clinic'])));
        $foreignActor = $this->user('clinic_staff', 2);
        $this->denied(403, fn () => $this->service->createReferral(array_replace($this->data(), ['issuer_user_id' => $foreignActor['id']])));
    }

    public function testTargetAndStatusGuardsLeaveRowUnchanged(): void
    {
        $ref = $this->service->createReferral($this->data(), $this->clinic);
        $id = (int) $ref['id'];
        $this->denied(403, fn () => $this->service->acknowledge($id, $this->clinic['id'], $this->clinic));
        $this->denied(409, fn () => $this->service->review($id, $this->guidance['id'], $this->guidance));
        $this->denied(409, fn () => $this->service->convertToCase($id, $this->guidance['id'], 'No premature case', 'personal', $this->guidance));
        $this->assertSame('submitted', (new ReferralModel())->find($id)['status']);
        $this->service->acknowledge($id, $this->guidance['id'], $this->guidance);
        $this->denied(409, fn () => $this->service->acknowledge($id, $this->guidance['id'], $this->guidance));
        $this->service->review($id, $this->guidance['id'], $this->guidance);
        $this->service->close($id, $this->guidance);
        $this->denied(409, fn () => $this->service->convertToCase($id, $this->guidance['id'], 'Closed', 'personal', $this->guidance));
        $this->assertSame('closed', (new ReferralModel())->find($id)['status']);
    }

    public function testReverseFlowAndSuperadminStillRespectConversionType(): void
    {
        $ref = $this->service->createReferral(array_replace($this->data(), [
            'source_module' => 'guidance', 'target_module' => 'clinic', 'issuer_user_id' => $this->guidance['id'],
                        'reason_code' => 'medical_assessment',
        ]), $this->guidance);
        $id = (int) $ref['id'];
        $this->service->acknowledge($id, $this->clinic['id'], $this->clinic);
        $admin = $this->user('superadmin');
        $before = $this->db->table('counselling_cases')->countAllResults();
        $this->denied(403, fn () => $this->service->convertToCase($id, $admin['id'], 'Wrong module', 'personal', $admin));
        $this->assertSame($before, $this->db->table('counselling_cases')->countAllResults());
        $this->denied(403, fn () => $this->service->close($id, $this->guidance));
        $this->assertSame('closed', $this->service->close($id, $admin)['status']);
    }

    public function testInvalidStoredPatientOrEncounterCannotBeMutated(): void
    {
        $ref = $this->service->createReferral($this->data());
        $id = (int) $ref['id'];
        $model = new ReferralModel();
        $model->update($id, ['patient_person_id' => $this->person(2)]);
        $this->denied(422, fn () => $this->service->acknowledge($id, $this->guidance['id']));
        $model->update($id, ['patient_person_id' => $this->patient, 'source_encounter_id' => PHP_INT_MAX]);
        $this->denied(422, fn () => $this->service->acknowledge($id, $this->guidance['id']));
        $this->assertSame('submitted', $model->find($id)['status']);
    }

    public function testForeignReferralCannotBeMutatedEvenByTrustedLegacyClose(): void
    {
        $ref = $this->service->createReferral($this->data());
        $id = (int) $ref['id'];
        // Test a foreign caller without constructing a row forbidden by the notification FK.
        CurrentTenant::set(2);
        try {
            $this->denied(404, fn () => $this->service->acknowledge($id, $this->guidance['id']));
            $this->denied(404, fn () => $this->service->close($id));
        } finally {
            CurrentTenant::set(1);
        }
        $this->assertSame('submitted', (new ReferralModel())->find($id)['status']);
    }

    public function testRevokedGrantAndInvalidModuleCannotBeReplacedByForgedContext(): void
    {
        $permission = $this->db->table('permissions')->where('code', 'referrals.create')->get()->getRowArray();
        $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $permission['id'])->delete();
        $this->denied(403, fn () => $this->service->createReferral($this->data(), $this->clinic));
        $this->db->table('role_permissions')->insert(['role' => 'clinic_staff', 'permission_id' => $permission['id']]);
        $this->denied(422, fn () => $this->service->createReferral(array_replace($this->data(), ['source_module' => ['clinic']])));
    }
}

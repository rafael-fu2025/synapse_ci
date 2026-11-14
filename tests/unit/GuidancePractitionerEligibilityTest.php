<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\GuidancePractitionerProfileModel;
use App\Models\GuidanceSupervisionAssignmentModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidancePractitionerService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidancePractitionerEligibilityTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidancePractitionerService $practitionerService;
    private GuidanceAccessPolicy $accessPolicy;
    private GuidancePractitionerProfileModel $profileModel;
    private GuidanceSupervisionAssignmentModel $supervisionModel;
    private PersonModel $personModel;
    private UserModel $userModel;

    private int $associateUserId;
    private int $licensedUserId;
    private int $supervisorUserId;
    private int $superadminUserId;
    private int $guidanceAdminUserId;
    private int $studentPersonId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        $this->profileModel = new GuidancePractitionerProfileModel();
        $this->supervisionModel = new GuidanceSupervisionAssignmentModel();
        $this->personModel = new PersonModel();
        $this->userModel = new UserModel();

        $this->accessPolicy = new GuidanceAccessPolicy(db: $this->db);
        $this->practitionerService = new GuidancePractitionerService(
            $this->profileModel,
            $this->supervisionModel,
            $this->userModel,
            $this->accessPolicy,
            $this->db
        );

        $unique = bin2hex(random_bytes(4));

        // 1. Associate Counselor
        $assocPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_ASSOC_' . $unique,
            'first_name' => 'Ana',
            'last_name'  => 'Associate',
        ]);
        $this->associateUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $assocPersonId,
            'username'  => 'assoc_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        // 2. Licensed Counselor
        $licPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_LIC_' . $unique,
            'first_name' => 'Luis',
            'last_name'  => 'Licensed',
        ]);
        $this->licensedUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $licPersonId,
            'username'  => 'lic_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        // 3. Guidance Supervisor
        $supPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_SUP_' . $unique,
            'first_name' => 'Sofia',
            'last_name'  => 'Supervisor',
        ]);
        $this->supervisorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $supPersonId,
            'username'  => 'sup_' . $unique,
            'role'      => 'guidance_supervisor',
            'status'    => 'active',
        ]);

        // 4. Superadmin (no clinical credentials)
        $this->superadminUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => null,
            'username'  => 'superadmin_' . $unique,
            'role'      => 'superadmin',
            'status'    => 'active',
        ]);

        // 5. Guidance Admin (administrative manager, no clinical credentials)
        $adminPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_ADM_' . $unique,
            'first_name' => 'George',
            'last_name'  => 'Admin',
        ]);
        $this->guidanceAdminUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $adminPersonId,
            'username'  => 'admin_' . $unique,
            'role'      => 'guidance_admin',
            'status'    => 'active',
        ]);

        // Student for form instances
        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'STU_' . $unique,
            'first_name' => 'Maria',
            'last_name'  => 'Santos',
        ]);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        parent::tearDown();
    }

    private function signInUser(int $userId, int $tenantId = 1): array
    {
        CurrentTenant::set($tenantId);
        $authority = new AuthenticatedUser();
        $identity = $authority->forIdentity($userId, $tenantId);
        $this->assertNotNull($identity, 'User identity must exist.');

        $now = time();
        session()->set([
            'user' => $identity,
            'tenant_id' => $identity['tenant_id'],
            '_auth' => [
                'started_at' => $now,
                'last_seen_at' => $now,
                'version' => $identity['auth_version'],
                'session_id' => session_id(),
            ],
        ]);

        return $identity;
    }

    public function testPractitionerProfileCreationAndCredentialTierEnforcement(): void
    {
        $actor = $this->signInUser($this->supervisorUserId);

        // 1. Valid registration: counselor_associate
        $assocProfile = $this->practitionerService->registerProfile(
            $this->associateUserId,
            'counselor_associate',
            null,
            null,
            ['counseling', 'intake'],
            $actor
        );
        $this->assertSame('counselor_associate', $assocProfile['credential_tier']);
        $this->assertSame(['counseling', 'intake'], $assocProfile['service_scope']);
        $this->assertSame('active', $assocProfile['professional_standing']);
        $this->assertSame(1, (int) $assocProfile['is_active']);

        // 2. Valid registration: licensed_counselor
        $licProfile = $this->practitionerService->registerProfile(
            $this->licensedUserId,
            'licensed_counselor',
            'PRC-0012345',
            '2028-12-31',
            ['counseling', 'intake', 'psychosocial'],
            $actor
        );
        $this->assertSame('licensed_counselor', $licProfile['credential_tier']);
        $this->assertSame('PRC-0012345', $licProfile['license_number']);
        $this->assertSame('2028-12-31', $licProfile['credential_expiry']);
        $this->assertContains('psychosocial', $licProfile['service_scope']);

        // 3. Valid registration: guidance_supervisor
        $supProfile = $this->practitionerService->registerProfile(
            $this->supervisorUserId,
            'guidance_supervisor',
            'PRC-0098765',
            '2029-06-30',
            ['counseling', 'intake', 'psychosocial', 'assessment_interpretation'],
            $actor
        );
        $this->assertSame('guidance_supervisor', $supProfile['credential_tier']);
        $this->assertSame('PRC-0098765', $supProfile['license_number']);

        // 4. Invalid credential tier throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->practitionerService->registerProfile(
            $this->associateUserId,
            'unlicensed_intern',
            null,
            null,
            ['counseling'],
            $actor
        );
    }

    public function testSupervisorAssignmentLifecycleAndSelfSupervisionPrevention(): void
    {
        $actor = $this->signInUser($this->supervisorUserId);

        // Self-supervision is prohibited
        try {
            $this->practitionerService->assignSupervisor($this->supervisorUserId, $this->supervisorUserId, true, $actor);
            $this->fail('Self-supervision must throw DomainException (422)');
        } catch (DomainException $e) {
            $this->assertSame(422, $e->getCode());
        }

        // Assign supervisor to associate
        $assignment = $this->practitionerService->assignSupervisor(
            $this->associateUserId,
            $this->supervisorUserId,
            true,
            $actor
        );
        $this->assertSame('active', $assignment['status']);
        $this->assertSame(1, (int) $assignment['co_sign_required']);
        $this->assertSame($this->supervisorUserId, (int) $assignment['supervisor_user_id']);

        // Verify active supervision query
        $active = $this->practitionerService->getActiveSupervision($this->associateUserId);
        $this->assertNotNull($active);
        $this->assertSame((int) $assignment['id'], (int) $active['id']);

        // Reassigning supervisor closes previous active assignment
        $otherSupPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_SUP2_' . bin2hex(random_bytes(3)),
            'first_name' => 'Eduardo',
            'last_name'  => 'SupervisorTwo',
        ]);
        $otherSupUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $otherSupPersonId,
            'username'  => 'sup2_' . bin2hex(random_bytes(3)),
            'role'      => 'guidance_supervisor',
            'status'    => 'active',
        ]);

        $newAssignment = $this->practitionerService->assignSupervisor(
            $this->associateUserId,
            $otherSupUserId,
            true,
            $actor
        );
        $this->assertSame($otherSupUserId, (int) $newAssignment['supervisor_user_id']);

        // Prior assignment must be completed
        $prior = $this->supervisionModel->find((int) $assignment['id']);
        $this->assertSame('completed', $prior['status']);
        $this->assertNotNull($prior['ends_at']);
    }

    public function testAssociateRecordsMoveDraftToPendingCosignToSupervisorFinalized(): void
    {
        $actor = $this->signInUser($this->supervisorUserId);

        // Register associate and supervisor profiles
        $this->practitionerService->registerProfile(
            $this->associateUserId,
            'counselor_associate',
            null,
            null,
            ['counseling', 'intake'],
            $actor
        );
        $this->practitionerService->registerProfile(
            $this->supervisorUserId,
            'guidance_supervisor',
            'PRC-0055555',
            '2028-10-10',
            ['counseling', 'intake', 'psychosocial'],
            $actor
        );

        // Assign supervisor to associate
        $this->practitionerService->assignSupervisor($this->associateUserId, $this->supervisorUserId, true, $actor);

        // Seed a controlled form definition and revision to create an instance
        $now = date('Y-m-d H:i:s');
        $uniq = bin2hex(random_bytes(3));
        $this->db->table('guidance_form_definitions')->insert([
            'tenant_id' => 1,
            'internal_key' => 'intake_assessment_' . $uniq,
            'form_code' => 'OGC-IA-' . $uniq,
            'canonical_title' => 'Intake Assessment Form',
            'owner' => 'Office of the Guidance Center (OGC)',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $defId = (int) $this->db->insertID();
        $schemaJson = json_encode(['type' => 'object', 'properties' => ['notes' => ['type' => 'string']]]);
        $this->db->table('guidance_form_definition_revisions')->insert([
            'tenant_id' => 1,
            'form_definition_id' => $defId,
            'revision_number' => '01',
            'schema_version' => '1.0.0',
            'renderer_version' => '1.0.0',
            'schema_json' => $schemaJson,
            'schema_hash' => hash('sha256', $schemaJson),
            'effectivity_date' => date('Y-m-d'),
            'approval_state' => 'approved',
            'created_at' => $now,
        ]);
        $revId = (int) $this->db->insertID();

        // Associate creates a form instance in draft status
        $associateActor = $this->signInUser($this->associateUserId);
        $this->db->table('guidance_form_instances')->insert([
            'tenant_id' => 1,
            'form_definition_id' => $defId,
            'definition_revision_id' => $revId,
            'person_id' => $this->studentPersonId,
            'instance_number' => 'OGC-2026-TEST-' . $uniq,
            'status' => 'draft',
            'created_by_user_id' => $this->associateUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $instanceId = (int) $this->db->insertID();

        // Associate cannot finalize directly
        $this->assertFalse($this->practitionerService->canFinalizeDirectly($this->associateUserId));
        try {
            $this->practitionerService->validateNoteFinalization($this->associateUserId, $this->associateUserId);
            $this->fail('Associate attempting to finalize directly must throw DomainException (403)');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('Associate authored records require supervisor co-sign', $e->getMessage());
        }

        // Associate submits for co-sign: draft -> pending_cosign
        $submitted = $this->practitionerService->submitForCoSign($instanceId, 'form_instance', $associateActor);
        $this->assertSame('pending_cosign', $submitted['status']);

        // Supervisor signs in and co-signs: pending_cosign -> finalized
        $supervisorActor = $this->signInUser($this->supervisorUserId);
        $finalized = $this->practitionerService->coSignRecord(
            $instanceId,
            'form_instance',
            'Clinical review completed and approved.',
            $supervisorActor
        );
        $this->assertSame('finalized', $finalized['status']);
        $this->assertSame($this->supervisorUserId, (int) $finalized['finalized_by_user_id']);
        $this->assertNotNull($finalized['finalized_at']);
    }

    public function testLicensedCounselorsCanFinalizeDirectlyWithoutCosign(): void
    {
        $actor = $this->signInUser($this->supervisorUserId);

        $this->practitionerService->registerProfile(
            $this->licensedUserId,
            'licensed_counselor',
            'PRC-0077777',
            '2028-05-15',
            ['counseling', 'intake', 'psychosocial'],
            $actor
        );

        // 1. canFinalizeDirectly returns true
        $this->assertTrue($this->practitionerService->canFinalizeDirectly($this->licensedUserId));
        $this->assertTrue($this->accessPolicy->canFinalizeDirectly($this->licensedUserId));

        // 2. validateNoteFinalization permits direct finalization without co-sign
        $validation = $this->practitionerService->validateNoteFinalization($this->licensedUserId, $this->licensedUserId);
        $this->assertTrue($validation['can_finalize']);
        $this->assertFalse($validation['requires_cosign']);

        // 3. assertCanFinalizeNote via access policy succeeds
        $licensedActor = $this->signInUser($this->licensedUserId);
        $authorized = $this->accessPolicy->assertCanFinalizeNote($licensedActor, $this->licensedUserId);
        $this->assertSame($this->licensedUserId, (int) $authorized['id']);
    }

    public function testAdminAndSuperadminStatusCannotBypassProfessionalEligibility(): void
    {
        $actor = $this->signInUser($this->supervisorUserId);

        // Associate is registered
        $this->practitionerService->registerProfile(
            $this->associateUserId,
            'counselor_associate',
            null,
            null,
            ['counseling'],
            $actor
        );

        // 1. Admin and Superadmin without practitioner profile cannot finalize directly
        $this->assertFalse($this->practitionerService->canFinalizeDirectly($this->guidanceAdminUserId));
        $this->assertFalse($this->practitionerService->canFinalizeDirectly($this->superadminUserId));
        $this->assertFalse($this->accessPolicy->canFinalizeDirectly($this->guidanceAdminUserId));
        $this->assertFalse($this->accessPolicy->canFinalizeDirectly($this->superadminUserId));

        // 2. Superadmin attempting to finalize clinical record directly throws 403
        $superadminActor = $this->signInUser($this->superadminUserId);
        try {
            $this->accessPolicy->assertCanFinalizeNote($superadminActor, $this->superadminUserId);
            $this->fail('Superadmin without professional profile must throw DomainException (403)');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // 3. Guidance admin attempting to finalize associate record without supervisor standing throws 403
        $adminActor = $this->signInUser($this->guidanceAdminUserId);
        try {
            $this->accessPolicy->assertCanFinalizeNote($adminActor, $this->associateUserId);
            $this->fail('Guidance Admin without clinical supervisor profile cannot co-sign associate record');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // 4. Superadmin attempting to co-sign associate record without clinical supervisor profile throws 403
        try {
            $this->accessPolicy->assertCanFinalizeNote($superadminActor, $this->associateUserId);
            $this->fail('Superadmin cannot bypass clinical eligibility to co-sign clinical notes');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    public function testServiceScopeCapabilitiesCheckEnforcement(): void
    {
        $actor = $this->signInUser($this->supervisorUserId);

        // Register practitioner with only counseling and intake
        $this->practitionerService->registerProfile(
            $this->licensedUserId,
            'licensed_counselor',
            'PRC-0088888',
            '2028-11-20',
            ['counseling', 'intake'],
            $actor
        );

        // 1. Permitted scopes pass cleanly
        $this->practitionerService->assertEligibilityForService($this->licensedUserId, 'counseling');
        $this->practitionerService->assertEligibilityForService($this->licensedUserId, 'intake');
        $this->accessPolicy->assertEligibilityForService($this->licensedUserId, 'counseling');

        // 2. Missing capability throws 403 DomainException
        try {
            $this->practitionerService->assertEligibilityForService($this->licensedUserId, 'assessment_interpretation');
            $this->fail('Attempting service without capability scope must throw 403 DomainException');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString("Practitioner lacks required 'assessment_interpretation' capability scope", $e->getMessage());
        }

        try {
            $this->accessPolicy->assertEligibilityForService($this->licensedUserId, 'assessment_interpretation');
            $this->fail('AccessPolicy must also throw 403 DomainException for missing scope');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString("Practitioner lacks required 'assessment_interpretation' capability scope", $e->getMessage());
        }

        // 3. Suspended standing blocks service eligibility
        $this->profileModel->where('user_id', $this->licensedUserId)->set(['professional_standing' => 'suspended'])->update();
        try {
            $this->practitionerService->assertEligibilityForService($this->licensedUserId, 'counseling');
            $this->fail('Suspended practitioner must throw 403 DomainException');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('active professional standing', $e->getMessage());
        }

        // 4. Expired credential blocks service eligibility
        $this->profileModel->where('user_id', $this->licensedUserId)->set([
            'professional_standing' => 'active',
            'credential_expiry' => '2020-01-01',
        ])->update();
        try {
            $this->practitionerService->assertEligibilityForService($this->licensedUserId, 'counseling');
            $this->fail('Expired credentials must throw 403 DomainException');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('credentials have expired', $e->getMessage());
        }
        $this->assertFalse($this->practitionerService->canFinalizeDirectly($this->licensedUserId));
    }
}

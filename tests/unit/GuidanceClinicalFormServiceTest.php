<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\CounsellingCaseModel;
use App\Models\CounsellingNoteModel;
use App\Models\CounsellingSessionModel;
use App\Models\GuidanceAppointmentModel;
use App\Models\GuidanceCallSlipModel;
use App\Models\GuidanceEpisodeModel;
use App\Models\GuidanceFormAttestationModel;
use App\Models\GuidanceFormDraftModel;
use App\Models\GuidanceFormInstanceModel;
use App\Models\GuidanceFormRevisionModel;
use App\Models\GuidancePractitionerProfileModel;
use App\Models\GuidanceSupervisionAssignmentModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\Crypto\GuidanceFormCipher;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceClinicalFormService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use App\Services\Guidance\GuidancePractitionerService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceClinicalFormServiceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidanceClinicalFormService $clinicalService;
    private GuidanceFormInstanceModel $instanceModel;
    private GuidanceFormDraftModel $draftModel;
    private GuidanceFormRevisionModel $revisionModel;
    private GuidanceFormAttestationModel $attestationModel;
    private GuidancePractitionerProfileModel $profileModel;
    private GuidanceSupervisionAssignmentModel $supervisionModel;
    private CounsellingCaseModel $caseModel;
    private CounsellingSessionModel $sessionModel;
    private CounsellingNoteModel $noteModel;
    private GuidanceEpisodeModel $episodeModel;
    private GuidanceAppointmentModel $appointmentModel;
    private PersonModel $personModel;
    private StudentProfileModel $studentModel;
    private UserModel $userModel;
    private GuidancePractitionerService $practitionerService;
    private GuidanceAccessPolicy $accessPolicy;
    private GuidanceFormDefinitionService $definitionService;
    private GuidanceFormCipher $cipher;
    private EncryptionService $encryptionService;

    private int $studentPersonId;
    private int $associateUserId;
    private int $supervisorUserId;
    private int $otherSupervisorUserId;
    private int $licensedCounselorUserId;
    private int $expiredPractitionerUserId;
    private int $superadminUserId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        $this->instanceModel    = new GuidanceFormInstanceModel($this->db);
        $this->draftModel       = new GuidanceFormDraftModel($this->db);
        $this->revisionModel    = new GuidanceFormRevisionModel($this->db);
        $this->attestationModel = new GuidanceFormAttestationModel($this->db);
        $this->profileModel     = new GuidancePractitionerProfileModel($this->db);
        $this->supervisionModel = new GuidanceSupervisionAssignmentModel($this->db);
        $this->caseModel        = new CounsellingCaseModel($this->db);
        $this->sessionModel     = new CounsellingSessionModel($this->db);
        $this->noteModel        = new CounsellingNoteModel($this->db);
        $this->episodeModel     = new GuidanceEpisodeModel($this->db);
        $this->appointmentModel = new GuidanceAppointmentModel($this->db);
        $this->personModel      = new PersonModel($this->db);
        $this->studentModel     = new StudentProfileModel($this->db);
        $this->userModel        = new UserModel($this->db);

        $this->accessPolicy        = new GuidanceAccessPolicy(db: $this->db);
        $this->practitionerService = new GuidancePractitionerService(
            profileModel: $this->profileModel,
            supervisionModel: $this->supervisionModel,
            userModel: $this->userModel,
            accessPolicy: $this->accessPolicy,
            db: $this->db
        );
        $this->definitionService   = new GuidanceFormDefinitionService();
        $this->cipher              = new GuidanceFormCipher();
        $this->encryptionService   = new EncryptionService();

        $this->clinicalService = new GuidanceClinicalFormService(
            instanceModel: $this->instanceModel,
            draftModel: $this->draftModel,
            revisionModel: $this->revisionModel,
            attestationModel: $this->attestationModel,
            profileModel: $this->profileModel,
            supervisionModel: $this->supervisionModel,
            caseModel: $this->caseModel,
            sessionModel: $this->sessionModel,
            noteModel: $this->noteModel,
            episodeModel: $this->episodeModel,
            appointmentModel: $this->appointmentModel,
            personModel: $this->personModel,
            studentModel: $this->studentModel,
            userModel: $this->userModel,
            practitionerService: $this->practitionerService,
            accessPolicy: $this->accessPolicy,
            definitionService: $this->definitionService,
            cipher: $this->cipher,
            encryptionService: $this->encryptionService,
            db: $this->db
        );

        $unique = bin2hex(random_bytes(4));

        // 1. Student
        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'STU_' . $unique,
            'first_name' => 'Maria',
            'last_name'  => 'Santos',
        ]);
        $this->studentModel->insert([
            'tenant_id'   => 1,
            'person_id'   => $this->studentPersonId,
            'student_id'  => '2026-STU-' . $unique,
            'course'      => 'BS Computer Science',
            'year_level'  => 3,
        ]);

        // 2. Associate Counselor
        $assocPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_ASC_' . $unique,
            'first_name' => 'Alex',
            'last_name'  => 'Associate',
        ]);
        $this->associateUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $assocPersonId,
            'username'  => 'assoc_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $assocPersonId,
            'user_id'               => $this->associateUserId,
            'credential_tier'       => 'counselor_associate',
            'license_number'        => 'ASC-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+1 year')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'psychosocial']),
            'is_active'             => 1,
        ]);

        // 3. Assigned Supervisor
        $supPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_SUP_' . $unique,
            'first_name' => 'Sarah',
            'last_name'  => 'Supervisor',
        ]);
        $this->supervisorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $supPersonId,
            'username'  => 'sup_' . $unique,
            'role'      => 'guidance_supervisor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $supPersonId,
            'user_id'               => $this->supervisorUserId,
            'credential_tier'       => 'guidance_supervisor',
            'license_number'        => 'SUP-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+2 years')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'psychosocial', 'supervision']),
            'is_active'             => 1,
        ]);

        // Supervision assignment: Supervisor supervises Associate
        $this->supervisionModel->insert([
            'tenant_id'          => 1,
            'associate_user_id'  => $this->associateUserId,
            'supervisor_user_id' => $this->supervisorUserId,
            'status'             => 'active',
            'co_sign_required'   => 1,
            'starts_at'          => date('Y-m-d H:i:s'),
        ]);

        // 4. Other Supervisor (not assigned to this associate)
        $othPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_OTH_' . $unique,
            'first_name' => 'Oliver',
            'last_name'  => 'OtherSupervisor',
        ]);
        $this->otherSupervisorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $othPersonId,
            'username'  => 'oth_sup_' . $unique,
            'role'      => 'guidance_supervisor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $othPersonId,
            'user_id'               => $this->otherSupervisorUserId,
            'credential_tier'       => 'guidance_supervisor',
            'license_number'        => 'SUP-OTH-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+2 years')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'psychosocial', 'supervision']),
            'is_active'             => 1,
        ]);

        // 5. Licensed Counselor
        $licPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_LIC_' . $unique,
            'first_name' => 'Laura',
            'last_name'  => 'Licensed',
        ]);
        $this->licensedCounselorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $licPersonId,
            'username'  => 'lic_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $licPersonId,
            'user_id'               => $this->licensedCounselorUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'LIC-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+1 year')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'psychosocial']),
            'is_active'             => 1,
        ]);

        // 6. Expired Practitioner
        $expPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_EXP_' . $unique,
            'first_name' => 'Edward',
            'last_name'  => 'Expired',
        ]);
        $this->expiredPractitionerUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $expPersonId,
            'username'  => 'exp_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $expPersonId,
            'user_id'               => $this->expiredPractitionerUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'EXP-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('-1 month')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling']),
            'is_active'             => 1,
        ]);

        // 7. Superadmin without clinical practitioner profile
        $adminPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_ADM_' . $unique,
            'first_name' => 'Adam',
            'last_name'  => 'Admin',
        ]);
        $this->superadminUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $adminPersonId,
            'username'  => 'superadmin_' . $unique,
            'role'      => 'superadmin',
            'status'    => 'active',
        ]);
    }

    public function testCreateClinicalDraftForThreeControlledForms(): void
    {
        $actor = ['id' => $this->associateUserId, 'tenant_id' => 1];

        // 1. Psychosocial Support Interview
        $interviewDraft = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            [
                'person_id'           => $this->studentPersonId,
                'presenting_concerns' => 'Academic stress and anxiety regarding final capstone project.',
                'intervention_plan'   => 'Stress reduction coaching and bi-weekly check-ins.',
            ],
            1,
            $actor
        );

        $this->assertSame('draft', $interviewDraft['status']);
        $this->assertNotEmpty($interviewDraft['instance_number']);
        $this->assertSame($this->studentPersonId, (int) $interviewDraft['person_id']);

        // Check encrypted draft in guidance_form_drafts
        $draftRow = $this->draftModel->where('form_instance_id', (int) $interviewDraft['id'])->first();
        $this->assertNotNull($draftRow);
        $this->assertNotEmpty($draftRow['draft_cipher']);

        // 2. Psychosocial Support Contact
        $contactDraft = $this->clinicalService->createClinicalDraft(
            'F-OGC-GPS-PCF-008-00', // Using form code
            [
                'person_id'          => $this->studentPersonId,
                'client_state'       => 'Calm and reflective.',
                'interventions_used' => 'Cognitive reframing.',
                'client_response'    => 'Expressed positive outlook.',
            ],
            1,
            $actor
        );

        $this->assertSame('draft', $contactDraft['status']);
        $this->assertSame($this->studentPersonId, (int) $contactDraft['person_id']);

        // 3. Counseling Contact Sheet
        $contactSheetDraft = $this->clinicalService->createClinicalDraft(
            'counseling_contact_sheet',
            [
                'person_id'                   => $this->studentPersonId,
                'concerns_discussed'          => 'Career counseling and goal setting.',
                'counseling_goals'            => 'Clarify career direction.',
                'interventions_and_responses' => 'Discussed strengths inventory.',
            ],
            1,
            $actor
        );

        $this->assertSame('draft', $contactSheetDraft['status']);
        $this->assertSame($this->studentPersonId, (int) $contactSheetDraft['person_id']);
    }

    public function testLinkagesAreStoredAndAppointmentLinked(): void
    {
        $actor = ['id' => $this->licensedCounselorUserId, 'tenant_id' => 1];

        // Create episode
        $episodeId = (int) $this->episodeModel->insert([
            'tenant_id'                  => 1,
            'person_id'                  => $this->studentPersonId,
            'primary_counselor_user_id'  => $this->licensedCounselorUserId,
            'status'                     => 'active',
            'opened_at'                  => date('Y-m-d H:i:s'),
        ]);

        // Create case
        $caseId = (int) $this->caseModel->insert([
            'tenant_id'          => 1,
            'person_id'          => $this->studentPersonId,
            'counsellor_user_id' => $this->licensedCounselorUserId,
            'case_number'        => 'CASE-' . bin2hex(random_bytes(3)),
            'title'              => 'Academic Stress',
            'category'           => 'academic',
            'status'             => 'open',
            'opened_at'          => date('Y-m-d H:i:s'),
        ]);

        // Create session
        $sessionId = (int) $this->sessionModel->insert([
            'tenant_id'          => 1,
            'case_id'            => $caseId,
            'counsellor_user_id' => $this->licensedCounselorUserId,
            'session_number'     => 1,
            'session_type'       => 'regular',
            'status'             => 'in_progress',
            'started_at'         => date('Y-m-d H:i:s'),
        ]);

        // Create appointment
        $appointmentId = (int) $this->appointmentModel->insert([
            'tenant_id'          => 1,
            'episode_id'         => $episodeId,
            'person_id'          => $this->studentPersonId,
            'counselor_user_id'  => $this->licensedCounselorUserId,
            'appointment_number' => 'APT-' . bin2hex(random_bytes(3)),
            'scheduled_date'     => date('Y-m-d'),
            'scheduled_time'     => '10:00:00',
            'purpose'            => 'counseling',
            'status'             => 'scheduled',
        ]);

        $draft = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_COUNSELING_CONTACT,
            [
                'person_id'      => $this->studentPersonId,
                'case_id'        => $caseId,
                'session_id'     => $sessionId,
                'appointment_id' => $appointmentId,
                'episode_id'     => $episodeId,
            ],
            1,
            $actor
        );

        $this->assertSame($caseId, (int) $draft['case_id']);
        $this->assertSame($sessionId, (int) $draft['session_id']);
        $this->assertSame($appointmentId, (int) $draft['appointment_id']);
        $this->assertSame($episodeId, (int) $draft['episode_id']);

        // Check appointment record was updated with form_instance_id
        $aptRecord = $this->appointmentModel->find($appointmentId);
        $this->assertSame((int) $draft['id'], (int) $aptRecord['form_instance_id']);
    }

    public function testNonClinicalSuperadminCannotBypassPractitionerRequirement(): void
    {
        $superadminActor = ['id' => $this->superadminUserId, 'tenant_id' => 1];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Clinical record authoring requires an active practitioner profile.');

        $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            ['person_id' => $this->studentPersonId],
            1,
            $superadminActor
        );
    }

    public function testNonClinicalSuperadminCannotBypassPractitionerRequirementToFinalize(): void
    {
        $licActor = ['id' => $this->licensedCounselorUserId, 'tenant_id' => 1];
        $superadminActor = ['id' => $this->superadminUserId, 'tenant_id' => 1];

        $draft = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            ['person_id' => $this->studentPersonId],
            1,
            $licActor
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Clinical record finalization requires an active practitioner profile.');

        $this->clinicalService->finalizeDirectly(
            (int) $draft['id'],
            'Superadmin attempting direct finalization.',
            1,
            $superadminActor
        );
    }

    public function testExpiredPractitionerCannotAuthorClinicalDraft(): void
    {
        $expiredActor = ['id' => $this->expiredPractitionerUserId, 'tenant_id' => 1];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Practitioner credentials have expired.');

        $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            ['person_id' => $this->studentPersonId],
            1,
            $expiredActor
        );
    }

    public function testAssociateCounselorCannotDirectlyFinalize(): void
    {
        $actor = ['id' => $this->associateUserId, 'tenant_id' => 1];

        $draft = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            ['person_id' => $this->studentPersonId],
            1,
            $actor
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Associate counselors require supervisor co-signature');

        $this->clinicalService->finalizeDirectly(
            (int) $draft['id'],
            'I confirm entries are true.',
            1,
            $actor
        );
    }

    public function testEncryptedClinicalStorageInspectionRawDbRowsContainNoPlaintext(): void
    {
        $licActor = ['id' => $this->licensedCounselorUserId, 'tenant_id' => 1];

        $secretConcerns = 'CONFIDENTIAL_PRESENTING_CONCERN_XYZ_987';
        $secretAssessment = 'CONFIDENTIAL_COUNSELOR_ASSESSMENT_ABC_654';
        $secretPlan = 'CONFIDENTIAL_INTERVENTION_PLAN_MNO_321';

        // 1. Create draft with confidential clinical text
        $draft = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            [
                'person_id'            => $this->studentPersonId,
                'presenting_concerns'  => $secretConcerns,
                'counselor_assessment' => $secretAssessment,
                'intervention_plan'    => $secretPlan,
            ],
            1,
            $licActor
        );
        $instanceId = (int) $draft['id'];

        // Raw DB inspection of guidance_form_drafts
        $rawDraftRow = $this->db->table('guidance_form_drafts')
            ->where('form_instance_id', $instanceId)
            ->get()->getRowArray();

        $this->assertNotNull($rawDraftRow);
        foreach ($rawDraftRow as $col => $val) {
            if (is_string($val)) {
                $this->assertStringNotContainsString($secretConcerns, $val, "Draft column {$col} must not contain plaintext.");
                $this->assertStringNotContainsString($secretAssessment, $val, "Draft column {$col} must not contain plaintext.");
                $this->assertStringNotContainsString($secretPlan, $val, "Draft column {$col} must not contain plaintext.");
            }
        }

        // Raw DB inspection of guidance_form_instances
        $rawInstanceRow = $this->db->table('guidance_form_instances')
            ->where('id', $instanceId)
            ->get()->getRowArray();
        $this->assertNotNull($rawInstanceRow);
        foreach ($rawInstanceRow as $col => $val) {
            if (is_string($val)) {
                $this->assertStringNotContainsString($secretConcerns, $val, "Instance column {$col} must not contain plaintext.");
                $this->assertStringNotContainsString($secretAssessment, $val, "Instance column {$col} must not contain plaintext.");
                $this->assertStringNotContainsString($secretPlan, $val, "Instance column {$col} must not contain plaintext.");
            }
        }

        // 2. Finalize directly and inspect immutable revision in guidance_form_revisions
        $this->clinicalService->finalizeDirectly(
            $instanceId,
            'Attestation statement for encryption verification.',
            1,
            $licActor
        );

        $rawRevRow = $this->db->table('guidance_form_revisions')
            ->where('form_instance_id', $instanceId)
            ->get()->getRowArray();

        $this->assertNotNull($rawRevRow);
        foreach ($rawRevRow as $col => $val) {
            if (is_string($val)) {
                $this->assertStringNotContainsString($secretConcerns, $val, "Revision column {$col} must not contain plaintext.");
                $this->assertStringNotContainsString($secretAssessment, $val, "Revision column {$col} must not contain plaintext.");
                $this->assertStringNotContainsString($secretPlan, $val, "Revision column {$col} must not contain plaintext.");
            }
        }

        // 3. Verify that authorized service decryption retrieves the exact plaintext
        $finalized = $this->clinicalService->getFinalizedClinicalForm($instanceId, 1, 1, $licActor);
        $this->assertSame($secretConcerns, $finalized['data']['presenting_concerns']);
        $this->assertSame($secretAssessment, $finalized['data']['counselor_assessment']);
        $this->assertSame($secretPlan, $finalized['data']['intervention_plan']);
    }

    public function testAssociateWorkflowSubmitCoSignAndSupervisorFinalization(): void
    {
        $assocActor = ['id' => $this->associateUserId, 'tenant_id' => 1];
        $supActor = ['id' => $this->supervisorUserId, 'tenant_id' => 1];
        $otherSupActor = ['id' => $this->otherSupervisorUserId, 'tenant_id' => 1];

        // 1. Create draft
        $draft = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            ['person_id' => $this->studentPersonId],
            1,
            $assocActor
        );
        $instanceId = (int) $draft['id'];
        $this->assertSame('draft', $draft['status']);

        // 2. Submit for co-sign
        $submitted = $this->clinicalService->submitForCoSign($instanceId, 'Please review for co-signature.', 1, $assocActor);
        $this->assertSame('pending_cosign', $submitted['status']);
        $this->assertSame($this->supervisorUserId, (int) $submitted['assigned_supervisor_user_id']);
        $this->assertNotEmpty($submitted['submitted_for_cosign_at']);
        $this->assertSame('Please review for co-signature.', $submitted['submission_notes']);

        // 3. Other supervisor (not assigned) attempts to co-sign -> 403 DomainException
        try {
            $this->clinicalService->coSignAndFinalize(
                $instanceId,
                'Supervisor co-signature attestation statement.',
                'Good job.',
                1,
                $otherSupActor
            );
            $this->fail('Unassigned supervisor must not be allowed to co-sign.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('assigned supervisor', $e->getMessage());
        }

        // 4. Assigned supervisor co-signs and finalizes
        $statement = 'I, Sarah Supervisor, confirm and digitally attest that the entries recorded in this controlled form are true, complete, and finalized according to guidance standards.';
        $finalized = $this->clinicalService->coSignAndFinalize(
            $instanceId,
            $statement,
            'Reviewed and approved without modifications.',
            1,
            $supActor
        );

        $this->assertSame('finalized', $finalized['status']);
        $this->assertTrue($finalized['cosigned']);
        $this->assertSame($instanceId, (int) $finalized['instance_id']);

        // Check attestation has role 'cosigner'
        $attestation = $finalized['attestation'];
        $this->assertSame('cosigner', $attestation['signer_role']);
        $this->assertSame($this->supervisorUserId, (int) $attestation['signer_user_id']);
        $this->assertSame(hash('sha256', trim($statement)), $attestation['attestation_statement_hash']);

        // Check instance is finalized in DB
        $dbInstance = $this->instanceModel->find($instanceId);
        $this->assertSame('finalized', $dbInstance['status']);
        $this->assertSame($this->supervisorUserId, (int) $dbInstance['finalized_by_user_id']);
        $this->assertSame('Reviewed and approved without modifications.', $dbInstance['supervisor_notes']);

        // Check mutable draft was removed
        $draftRow = $this->draftModel->where('form_instance_id', $instanceId)->first();
        $this->assertNull($draftRow);
    }

    public function testLicensedCounselorDirectFinalization(): void
    {
        $licActor = ['id' => $this->licensedCounselorUserId, 'tenant_id' => 1];

        // 1. Create draft
        $draft = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_CONTACT,
            [
                'person_id'    => $this->studentPersonId,
                'client_state' => 'Relaxed and responsive.',
            ],
            1,
            $licActor
        );
        $instanceId = (int) $draft['id'];

        // 2. Direct finalization
        $statement = 'I, Laura Licensed, confirm and digitally attest that the entries recorded are accurate.';
        $result = $this->clinicalService->finalizeDirectly(
            $instanceId,
            $statement,
            1,
            $licActor
        );

        $this->assertSame('finalized', $result['status']);
        $attestation = $result['attestation'];
        $this->assertSame('primary_counselor', $attestation['signer_role']);
        $this->assertSame($this->licensedCounselorUserId, (int) $attestation['signer_user_id']);

        // Verify finalized data is readable
        $finalizedBundle = $this->clinicalService->getFinalizedClinicalForm($instanceId, 1, 1, $licActor);
        $this->assertSame('Relaxed and responsive.', $finalizedBundle['data']['client_state']);
    }

    public function testClinicalHistoryForCaseMergesControlledFormsAndLegacyNotes(): void
    {
        $licActor = ['id' => $this->licensedCounselorUserId, 'tenant_id' => 1];

        // Create case
        $caseId = (int) $this->caseModel->insert([
            'tenant_id'          => 1,
            'person_id'          => $this->studentPersonId,
            'counsellor_user_id' => $this->licensedCounselorUserId,
            'case_number'        => 'CASE-HIST-' . bin2hex(random_bytes(3)),
            'title'              => 'Clinical History Integration Test',
            'category'           => 'personal',
            'status'             => 'open',
            'opened_at'          => date('Y-m-d H:i:s'),
        ]);

        // 1. Legacy session and notes
        $sessionId = (int) $this->sessionModel->insert([
            'tenant_id'          => 1,
            'case_id'            => $caseId,
            'counsellor_user_id' => $this->licensedCounselorUserId,
            'session_number'     => 1,
            'session_type'       => 'regular',
            'status'             => 'completed',
            'started_at'         => date('Y-m-d H:i:s', strtotime('-2 days')),
            'ended_at'           => date('Y-m-d H:i:s', strtotime('-2 days +1 hour')),
        ]);

        $enc = $this->encryptionService->encrypt('Legacy session note: Student discussed family relationships.');
        $this->noteModel->insert([
            'tenant_id'           => 1,
            'session_id'          => $sessionId,
            'version'             => 1,
            'is_draft'            => 0,
            'notes_cipher'        => $enc['cipher'],
            'notes_nonce'         => $enc['nonce'],
            'notes_tag'           => $enc['tag'],
            'notes_key_version'   => $enc['key_version'],
            'created_by_user_id'  => $this->licensedCounselorUserId,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        // 2. Controlled Interview form
        $interview = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            [
                'person_id'           => $this->studentPersonId,
                'case_id'             => $caseId,
                'presenting_concerns' => 'Severe test anxiety.',
            ],
            1,
            $licActor
        );
        $this->clinicalService->finalizeDirectly(
            (int) $interview['id'],
            'Interview attestation statement.',
            1,
            $licActor
        );

        // 3. Controlled Contact Sheet
        $contactSheet = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_COUNSELING_CONTACT,
            [
                'person_id'          => $this->studentPersonId,
                'case_id'            => $caseId,
                'concerns_discussed' => 'Follow up on test anxiety coping mechanisms.',
            ],
            1,
            $licActor
        );

        // Query history
        $history = $this->clinicalService->getClinicalHistoryForCase($caseId, 1, $licActor);

        $this->assertSame($caseId, $history['case_id']);
        $this->assertCount(1, $history['interviews']);
        $this->assertCount(1, $history['contact_sheets']);
        $this->assertCount(2, $history['controlled_forms']);
        $this->assertCount(1, $history['legacy_session_notes']);
        $this->assertStringContainsString('family relationships', $history['legacy_session_notes'][0]['decrypted_text']);
        $this->assertNotEmpty($history['timeline']);
    }

    public function testAttachContactSheetToSessionClosesSessionAndPreventsDuplicateData(): void
    {
        $licActor = ['id' => $this->licensedCounselorUserId, 'tenant_id' => 1];

        // Create case
        $caseId = (int) $this->caseModel->insert([
            'tenant_id'          => 1,
            'person_id'          => $this->studentPersonId,
            'counsellor_user_id' => $this->licensedCounselorUserId,
            'case_number'        => 'CASE-ATTACH-' . bin2hex(random_bytes(3)),
            'title'              => 'Attach Contact Sheet Test',
            'category'           => 'academic',
            'status'             => 'open',
            'opened_at'          => date('Y-m-d H:i:s'),
        ]);

        // Open in-progress session
        $sessionId = (int) $this->sessionModel->insert([
            'tenant_id'          => 1,
            'case_id'            => $caseId,
            'counsellor_user_id' => $this->licensedCounselorUserId,
            'session_number'     => 1,
            'session_type'       => 'regular',
            'status'             => 'in_progress',
            'started_at'         => date('Y-m-d H:i:s'),
        ]);

        // Save legacy note in session
        $enc = $this->encryptionService->encrypt('Pre-existing session notes about time management.');
        $this->noteModel->insert([
            'tenant_id'          => 1,
            'session_id'         => $sessionId,
            'version'            => 1,
            'is_draft'           => 1,
            'notes_cipher'       => $enc['cipher'],
            'notes_nonce'        => $enc['nonce'],
            'notes_tag'          => $enc['tag'],
            'notes_key_version'  => $enc['key_version'],
            'created_by_user_id' => $this->licensedCounselorUserId,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        // Attach contact sheet to session without providing concerns_discussed
        $result = $this->clinicalService->attachContactSheetToSession(
            $sessionId,
            [
                'counseling_goals' => 'Establish weekly schedule planner.',
            ],
            1,
            $licActor
        );

        // 1. Session is automatically closed
        $this->assertSame('completed', $result['session']['status']);
        $this->assertNotNull($result['session']['ended_at']);

        // 2. Contact sheet is created and linked
        $contactSheet = $result['contact_sheet'];
        $this->assertSame('draft', $contactSheet['status']);
        $this->assertSame($sessionId, (int) $contactSheet['session_id']);
        $this->assertSame($caseId, (int) $contactSheet['case_id']);
        $this->assertSame($this->studentPersonId, (int) $contactSheet['person_id']);

        // 3. Pre-existing note was automatically populated without duplicate data entry
        $this->assertStringContainsString('Pre-existing session notes about time management.', $contactSheet['data']['concerns_discussed']);
    }

    public function testInvalidClinicalFormKeyThrowsException(): void
    {
        $actor = ['id' => $this->licensedCounselorUserId, 'tenant_id' => 1];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid clinical form key');

        $this->clinicalService->createClinicalDraft(
            'guidance_general_referral',
            ['person_id' => $this->studentPersonId],
            1,
            $actor
        );
    }

    public function testPersonNotFoundThrowsException(): void
    {
        $actor = ['id' => $this->licensedCounselorUserId, 'tenant_id' => 1];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Person not found in active tenant.');

        $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            ['person_id' => 999999],
            1,
            $actor
        );
    }

    public function testSubmitForCoSignFailsIfNoActiveSupervisorAssigned(): void
    {
        // Create an associate without supervisor
        $unique = bin2hex(random_bytes(4));
        $unsupervisedPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_UNSUP_' . $unique,
            'first_name' => 'Unsupervised',
            'last_name'  => 'Associate',
        ]);
        $unsupervisedUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $unsupervisedPersonId,
            'username'  => 'unsup_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $unsupervisedPersonId,
            'user_id'               => $unsupervisedUserId,
            'credential_tier'       => 'counselor_associate',
            'license_number'        => 'ASC-UNSUP-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+1 year')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling']),
            'is_active'             => 1,
        ]);

        $actor = ['id' => $unsupervisedUserId, 'tenant_id' => 1];

        $draft = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            ['person_id' => $this->studentPersonId],
            1,
            $actor
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No active supervisor assignment found for associate author.');

        $this->clinicalService->submitForCoSign((int) $draft['id'], 'Please review', 1, $actor);
    }

    public function testCipherTamperingCausesDecryptionFailure(): void
    {
        $actor = ['id' => $this->licensedCounselorUserId, 'tenant_id' => 1];

        $draft = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            ['person_id' => $this->studentPersonId],
            1,
            $actor
        );
        $instanceId = (int) $draft['id'];

        // Tamper with the draft ciphertext in DB
        $draftRow = $this->draftModel->where('form_instance_id', $instanceId)->first();
        $tamperedCipher = substr_replace((string) $draftRow['draft_cipher'], 'X', 0, 1);
        $this->draftModel->update((int) $draftRow['id'], ['draft_cipher' => $tamperedCipher]);

        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->clinicalService->getClinicalDraft($instanceId, 1, $actor);
    }

    public function testCannotFinalizeAlreadyFinalizedInstance(): void
    {
        $actor = ['id' => $this->licensedCounselorUserId, 'tenant_id' => 1];

        $draft = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_CONTACT,
            ['person_id' => $this->studentPersonId],
            1,
            $actor
        );
        $instanceId = (int) $draft['id'];

        $this->clinicalService->finalizeDirectly($instanceId, 'Attestation statement', 1, $actor);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Clinical form instance is already finalized.');

        $this->clinicalService->finalizeDirectly($instanceId, 'Attestation second attempt', 1, $actor);
    }
}

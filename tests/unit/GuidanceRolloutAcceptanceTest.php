<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\CounsellingCaseModel;
use App\Models\CounsellingNoteModel;
use App\Models\CounsellingSessionModel;
use App\Models\GuidanceActivationGateModel;
use App\Models\GuidanceAppointmentModel;
use App\Models\GuidanceCallSlipModel;
use App\Models\GuidanceDocumentIssuanceModel;
use App\Models\GuidanceDocumentJobModel;
use App\Models\GuidanceEpisodeModel;
use App\Models\GuidanceEvaluationTokenModel;
use App\Models\GuidanceFormAttestationModel;
use App\Models\GuidanceFormDraftModel;
use App\Models\GuidanceFormInstanceModel;
use App\Models\GuidanceFormRevisionModel;
use App\Models\GuidanceIntakeReferralModel;
use App\Models\GuidanceMultipleIntelligenceAssignmentModel;
use App\Models\GuidanceMultipleIntelligenceSubmissionModel;
use App\Models\GuidancePractitionerProfileModel;
use App\Models\GuidanceSafetyAssessmentModel;
use App\Models\GuidanceSafetyEscalationModel;
use App\Models\GuidanceServiceEvaluationModel;
use App\Models\GuidanceSupervisionAssignmentModel;
use App\Models\PersonModel;
use App\Models\ReferralModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\Crypto\GuidanceFormCipher;
use App\Services\CurrentTenant;
use App\Services\Guidance\FormAttestationService;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceActivationGateService;
use App\Services\Guidance\GuidanceAppointmentService;
use App\Services\Guidance\GuidanceCallSlipService;
use App\Services\Guidance\GuidanceClinicalFormService;
use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Guidance\GuidanceEpisodeService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use App\Services\Guidance\GuidanceFormService;
use App\Services\Guidance\GuidanceIntakeService;
use App\Services\Guidance\GuidanceMobileContractService;
use App\Services\Guidance\GuidanceMultipleIntelligenceService;
use App\Services\Guidance\GuidancePractitionerService;
use App\Services\Guidance\GuidanceSafetyService;
use App\Services\Guidance\GuidanceServiceEvaluationService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceRolloutAcceptanceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidanceActivationGateModel $gateModel;
    private GuidanceActivationGateService $gateService;
    private FormAttestationService $attestationService;
    private GuidanceMobileContractService $mobileService;
    private GuidanceIntakeService $intakeService;
    private GuidanceSafetyService $safetyService;
    private GuidanceEpisodeService $episodeService;
    private GuidanceAppointmentService $appointmentService;
    private GuidanceCallSlipService $callSlipService;
    private GuidanceClinicalFormService $clinicalService;
    private GuidanceMultipleIntelligenceService $miService;
    private GuidanceServiceEvaluationService $evaluationService;
    private GuidanceDocumentService $documentService;
    private GuidanceFormDefinitionService $definitionService;

    private PersonModel $personModel;
    private StudentProfileModel $studentModel;
    private UserModel $userModel;
    private GuidancePractitionerProfileModel $profileModel;
    private GuidanceSupervisionAssignmentModel $supervisionModel;
    private GuidanceAppointmentModel $appointmentModel;
    private GuidanceCallSlipModel $callSlipModel;
    private GuidanceFormInstanceModel $instanceModel;
    private GuidanceFormDraftModel $draftModel;
    private GuidanceFormRevisionModel $revisionModel;
    private GuidanceFormAttestationModel $attestationModel;
    private GuidanceEvaluationTokenModel $tokenModel;
    private GuidanceServiceEvaluationModel $evaluationModel;
    private EncryptionService $encryptionService;

    /** @var array<int, int> */
    private array $studentPersonIds = [];
    /** @var array<int, int> */
    private array $studentUserIds = [];
    private string $primaryStudentMisId;
    private int $facultyUserId;
    private int $associateUserId;
    private int $supervisorUserId;
    private int $licensedCounselorUserId;
    private int $qaOfficerUserId;
    private int $dpoUserId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        $this->personModel      = new PersonModel($this->db);
        $this->studentModel     = new StudentProfileModel($this->db);
        $this->userModel        = new UserModel($this->db);
        $this->profileModel     = new GuidancePractitionerProfileModel($this->db);
        $this->supervisionModel = new GuidanceSupervisionAssignmentModel($this->db);
        $this->appointmentModel = new GuidanceAppointmentModel($this->db);
        $this->callSlipModel    = new GuidanceCallSlipModel($this->db);
        $this->instanceModel    = new GuidanceFormInstanceModel($this->db);
        $this->draftModel       = new GuidanceFormDraftModel($this->db);
        $this->revisionModel    = new GuidanceFormRevisionModel($this->db);
        $this->attestationModel = new GuidanceFormAttestationModel($this->db);
        $this->tokenModel       = new GuidanceEvaluationTokenModel($this->db);
        $this->evaluationModel  = new GuidanceServiceEvaluationModel($this->db);
        $this->gateModel        = new GuidanceActivationGateModel($this->db);
        $this->encryptionService = new EncryptionService();

        $this->gateService = new GuidanceActivationGateService(
            gateModel: $this->gateModel,
            db: $this->db
        );

        $this->attestationService = new FormAttestationService(
            attestationModel: $this->attestationModel,
            activationGateService: $this->gateService,
            db: $this->db
        );

        $accessPolicy            = new GuidanceAccessPolicy(db: $this->db);
        $cipher                  = new GuidanceFormCipher();
        $this->definitionService = new GuidanceFormDefinitionService();
        $this->definitionService->syncManifestToDatabase(1);

        $formService = new GuidanceFormService(
            instanceModel: $this->instanceModel,
            draftModel: $this->draftModel,
            revisionModel: $this->revisionModel,
            personModel: $this->personModel,
            cipher: $cipher,
            accessPolicy: $accessPolicy,
            definitionService: $this->definitionService,
            attestationService: $this->attestationService,
            db: $this->db
        );

        $practitionerService = new GuidancePractitionerService(
            profileModel: $this->profileModel,
            supervisionModel: $this->supervisionModel,
            userModel: $this->userModel,
            accessPolicy: $accessPolicy,
            db: $this->db
        );

        $caseModel    = new CounsellingCaseModel($this->db);
        $sessionModel = new CounsellingSessionModel($this->db);
        $noteModel    = new CounsellingNoteModel($this->db);
        $episodeModel = new GuidanceEpisodeModel($this->db);
        $referralModel = new GuidanceIntakeReferralModel($this->db);

        $this->intakeService = new GuidanceIntakeService(
            referralModel: $referralModel,
            personModel: $this->personModel,
            studentProfileModel: $this->studentModel,
            userModel: $this->userModel,
            caseModel: $caseModel,
            clinicReferralModel: new ReferralModel($this->db),
            encryptionService: $this->encryptionService,
            accessPolicy: $accessPolicy,
            db: $this->db
        );

        $this->safetyService = new GuidanceSafetyService(
            safetyAssessmentModel: new GuidanceSafetyAssessmentModel($this->db),
            safetyEscalationModel: new GuidanceSafetyEscalationModel($this->db),
            personModel: $this->personModel,
            profileModel: $this->profileModel,
            intakeReferralModel: $referralModel,
            caseModel: $caseModel,
            encryptionService: $this->encryptionService,
            accessPolicy: $accessPolicy,
            db: $this->db
        );

        $this->episodeService = new GuidanceEpisodeService(
            episodeModel: $episodeModel,
            appointmentModel: $this->appointmentModel,
            callSlipModel: $this->callSlipModel,
            intakeReferralModel: $referralModel,
            caseModel: $caseModel,
            sessionModel: $sessionModel,
            formInstanceModel: $this->instanceModel,
            personModel: $this->personModel,
            userModel: $this->userModel,
            practitionerProfileModel: $this->profileModel,
            practitionerService: $practitionerService,
            accessPolicy: $accessPolicy,
            encryptionService: $this->encryptionService,
            db: $this->db
        );

        $this->appointmentService = new GuidanceAppointmentService(
            appointmentModel: $this->appointmentModel,
            accessPolicy: $accessPolicy,
            formService: $formService,
            personModel: $this->personModel,
            studentProfileModel: $this->studentModel,
            userModel: $this->userModel,
            encryptionService: $this->encryptionService,
            db: $this->db
        );

        $this->callSlipService = new GuidanceCallSlipService(
            callSlipModel: $this->callSlipModel,
            accessPolicy: $accessPolicy,
            formService: $formService,
            personModel: $this->personModel,
            studentProfileModel: $this->studentModel,
            userModel: $this->userModel,
            profileModel: $this->profileModel,
            db: $this->db
        );

        $this->clinicalService = new GuidanceClinicalFormService(
            instanceModel: $this->instanceModel,
            draftModel: $this->draftModel,
            revisionModel: $this->revisionModel,
            attestationModel: $this->attestationModel,
            profileModel: $this->profileModel,
            supervisionModel: $this->supervisionModel,
            caseModel: $caseModel,
            sessionModel: $sessionModel,
            noteModel: $noteModel,
            episodeModel: $episodeModel,
            appointmentModel: $this->appointmentModel,
            personModel: $this->personModel,
            studentModel: $this->studentModel,
            userModel: $this->userModel,
            practitionerService: $practitionerService,
            accessPolicy: $accessPolicy,
            definitionService: $this->definitionService,
            cipher: $cipher,
            encryptionService: $this->encryptionService,
            db: $this->db
        );

        $miAssignmentModel = new GuidanceMultipleIntelligenceAssignmentModel($this->db);
        $miSubmissionModel = new GuidanceMultipleIntelligenceSubmissionModel($this->db);

        $this->miService = new GuidanceMultipleIntelligenceService(
            assignmentModel: $miAssignmentModel,
            submissionModel: $miSubmissionModel,
            encryptionService: $this->encryptionService,
            attestationService: $this->attestationService,
            db: $this->db
        );

        $this->evaluationService = new GuidanceServiceEvaluationService(
            tokenModel: $this->tokenModel,
            evaluationModel: $this->evaluationModel,
            encryptionService: $this->encryptionService,
            db: $this->db
        );

        $this->mobileService = new GuidanceMobileContractService(
            appointmentModel: $this->appointmentModel,
            callSlipModel: $this->callSlipModel,
            miAssignmentModel: $miAssignmentModel,
            evaluationTokenModel: $this->tokenModel,
            callSlipService: $this->callSlipService,
            evaluationService: $this->evaluationService,
            personModel: $this->personModel,
            userModel: $this->userModel,
            db: $this->db
        );

        $this->documentService = new GuidanceDocumentService(
            jobModel: new GuidanceDocumentJobModel($this->db),
            issuanceModel: new GuidanceDocumentIssuanceModel($this->db),
            db: $this->db
        );

        $unique = bin2hex(random_bytes(4));
        $now    = date('Y-m-d H:i:s');

        $this->studentPersonIds = [];
        $this->studentUserIds   = [];
        for ($i = 1; $i <= 5; $i++) {
            $misId = "STU_P8_{$i}_{$unique}";
            if ($i === 1) {
                $this->primaryStudentMisId = $misId;
            }
            $pid = (int) $this->personModel->insert([
                'tenant_id'  => 1,
                'kind'       => 'student',
                'mis_id'     => $misId,
                'first_name' => "Student{$i}",
                'last_name'  => "Rollout_{$unique}",
                'email'      => "student_p8_{$i}_{$unique}@example.com",
            ]);
            $this->studentPersonIds[$i] = $pid;

            $this->studentModel->insert([
                'tenant_id'  => 1,
                'person_id'  => $pid,
                'student_id' => $misId,
                'program'    => 'BS Computer Science',
                'course'     => 'BS Computer Science',
                'year_level' => 3,
                'section'    => 'A',
                'department' => 'CCS',
            ]);

            $this->studentUserIds[$i] = $this->insertUser($pid, "stu_p8_{$i}_{$unique}", 'student', $now);
        }

        // Faculty referrer
        $facultyPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => "FAC_{$unique}",
            'first_name' => 'Prof',
            'last_name'  => "Referrer_{$unique}",
            'email'      => "faculty_{$unique}@example.com",
        ]);
        $this->facultyUserId = $this->insertUser($facultyPersonId, "faculty_{$unique}", 'employee', $now);

        // Counselor Associate
        $assocPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => "ASC_{$unique}",
            'first_name' => 'Alex',
            'last_name'  => "Associate_{$unique}",
            'email'      => "assoc_{$unique}@example.com",
        ]);
        $this->associateUserId = $this->insertUser($assocPersonId, "assoc_{$unique}", 'counsellor', $now);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $assocPersonId,
            'user_id'               => $this->associateUserId,
            'credential_tier'       => 'counselor_associate',
            'license_number'        => "ASC-LIC-{$unique}",
            'credential_expiry'     => date('Y-m-d', strtotime('+1 year')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'psychosocial']),
            'is_active'             => 1,
        ]);

        // Guidance Supervisor / Guidance Head
        $supPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => "SUP_{$unique}",
            'first_name' => 'Sarah',
            'last_name'  => "Supervisor_{$unique}",
            'email'      => "sup_{$unique}@example.com",
        ]);
        $this->supervisorUserId = $this->insertUser($supPersonId, "sup_{$unique}", 'guidance_supervisor', $now);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $supPersonId,
            'user_id'               => $this->supervisorUserId,
            'credential_tier'       => 'guidance_supervisor',
            'license_number'        => "SUP-LIC-{$unique}",
            'credential_expiry'     => date('Y-m-d', strtotime('+2 years')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'psychosocial', 'supervision']),
            'is_active'             => 1,
        ]);

        // Active supervision assignment between Associate and Supervisor
        $this->supervisionModel->insert([
            'tenant_id'          => 1,
            'associate_user_id'  => $this->associateUserId,
            'supervisor_user_id' => $this->supervisorUserId,
            'status'             => 'active',
            'co_sign_required'   => 1,
            'starts_at'          => $now,
        ]);

        // Licensed Counselor
        $licPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => "LIC_{$unique}",
            'first_name' => 'Laura',
            'last_name'  => "Licensed_{$unique}",
            'email'      => "lic_{$unique}@example.com",
        ]);
        $this->licensedCounselorUserId = $this->insertUser($licPersonId, "lic_{$unique}", 'counsellor', $now);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $licPersonId,
            'user_id'               => $this->licensedCounselorUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => "LIC-RGC-{$unique}",
            'credential_expiry'     => date('Y-m-d', strtotime('+2 years')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'psychosocial']),
            'is_active'             => 1,
        ]);

        // QA Officer & DPO users for activation gate signoffs
        $qaPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => "QA_{$unique}",
            'first_name' => 'Quentin',
            'last_name'  => "Quality_{$unique}",
        ]);
        $this->qaOfficerUserId = $this->insertUser($qaPersonId, "qa_{$unique}", 'guidance_admin', $now);

        $dpoPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => "DPO_{$unique}",
            'first_name' => 'Diana',
            'last_name'  => "Privacy_{$unique}",
        ]);
        $this->dpoUserId = $this->insertUser($dpoPersonId, "dpo_{$unique}", 'guidance_admin', $now);
    }

    private function insertUser(int $personId, string $username, string $role, string $now): int
    {
        $this->db->table('users')->insert([
            'tenant_id'     => 1,
            'person_id'     => $personId,
            'username'      => $username,
            'email'         => "{$username}@example.com",
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'role'          => $role,
            'status'        => 'active',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * @return array<string, mixed>
     */
    private function signInUser(int $userId, int $tenantId = 1): array
    {
        CurrentTenant::set($tenantId);
        $authority = new AuthenticatedUser();
        $identity  = $authority->forIdentity($userId, $tenantId);
        self::assertNotNull($identity, 'User identity must exist.');

        $now = time();
        session()->set([
            'user'      => $identity,
            'tenant_id' => $identity['tenant_id'],
            '_auth'     => [
                'started_at'   => $now,
                'last_seen_at' => $now,
                'version'      => $identity['auth_version'],
                'session_id'   => session_id(),
            ],
        ]);

        return $identity;
    }

    public function testAllSixActivationGatesSeededAsDisabledByDefault(): void
    {
        $expectedKeys = [
            'canonical_forms_signoff',
            'safety_escalation_sop_signoff',
            'practitioner_credentials_signoff',
            'privacy_retention_dpo_signoff',
            'mi_reproduction_signoff',
            'evaluation_cohort_policy_signoff',
        ];

        $gates = $this->gateService->getGates(1);
        self::assertCount(6, $gates);

        $byKey = [];
        foreach ($gates as $gate) {
            $byKey[$gate['gate_key']] = $gate;
        }

        foreach ($expectedKeys as $gateKey) {
            self::assertArrayHasKey($gateKey, $byKey, "Activation gate '{$gateKey}' must be seeded.");
            $gate = $byKey[$gateKey];
            self::assertSame(0, (int) $gate['is_enabled'], "Gate '{$gateKey}' must default to is_enabled = 0.");
            self::assertNull($gate['approved_at'], "Gate '{$gateKey}' must have null approved_at before signoff.");
            self::assertSame(['guidance_head', 'qa_office', 'dpo'], $gate['required_signoffs']);
            self::assertSame([], $gate['recorded_signoffs']);
            self::assertFalse($this->gateService->isGateEnabled($gateKey, 1));
        }
    }

    public function testAssertGateEnabledBlocksUntilAllRequiredSignoffsRecorded(): void
    {
        $gateKey = GuidanceActivationGateService::GATE_CANONICAL_FORMS;

        // 1. Blocked initially before any signoffs
        try {
            $this->gateService->assertGateEnabled($gateKey, 1);
            self::fail('Expected DomainException (403) when gate has zero signoffs.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
            self::assertStringContainsString('canonical_forms_signoff', $e->getMessage());
        }

        // 2. Invalid authority role is rejected with 422
        try {
            $this->gateService->recordSignoff($gateKey, 'unauthorized_role', 'Invalid role test', 1, ['id' => $this->supervisorUserId]);
            self::fail('Expected InvalidArgumentException (422) for unknown authority role.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(422, $e->getCode());
        }

        // 3. Record 1st signoff: guidance_head -> still disabled
        $afterHead = $this->gateService->recordSignoff(
            $gateKey,
            'guidance_head',
            'Guidance Head reviewed form headers and revisions.',
            1,
            ['id' => $this->supervisorUserId]
        );
        self::assertSame(0, (int) $afterHead['is_enabled']);
        self::assertNull($afterHead['approved_at']);
        self::assertArrayHasKey('guidance_head', $afterHead['recorded_signoffs']);
        self::assertSame($this->supervisorUserId, (int) $afterHead['recorded_signoffs']['guidance_head']['user_id']);

        try {
            $this->gateService->assertGateEnabled($gateKey, 1);
            self::fail('Expected DomainException (403) when only 1 of 3 signoffs is recorded.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        // 4. Record 2nd signoff: qa_office -> still disabled
        $afterQa = $this->gateService->recordSignoff(
            $gateKey,
            'qa_office',
            'QA Office verified canonical form control codes.',
            1,
            ['id' => $this->qaOfficerUserId]
        );
        self::assertSame(0, (int) $afterQa['is_enabled']);
        self::assertNull($afterQa['approved_at']);
        self::assertArrayHasKey('qa_office', $afterQa['recorded_signoffs']);

        try {
            $this->gateService->assertGateEnabled($gateKey, 1);
            self::fail('Expected DomainException (403) when only 2 of 3 signoffs are recorded.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        // 5. Record 3rd (final) signoff: dpo -> automatically enables gate!
        $afterDpo = $this->gateService->recordSignoff(
            $gateKey,
            'dpo',
            'DPO verified form privacy notices and retention metadata.',
            1,
            ['id' => $this->dpoUserId]
        );
        self::assertSame(1, (int) $afterDpo['is_enabled']);
        self::assertNotEmpty($afterDpo['approved_at']);
        self::assertCount(3, $afterDpo['recorded_signoffs']);
        self::assertTrue($this->gateService->isGateEnabled($gateKey, 1));

        // assertGateEnabled now passes without throwing
        $this->gateService->assertGateEnabled($gateKey, 1);
    }

    public function testV1SignerCapacityPolicyInActivationGateAndFormAttestationServices(): void
    {
        // 1. Supported v1 capacities succeed in GuidanceActivationGateService
        foreach (['student', 'staff', 'practitioner', 'counselor_associate', 'licensed_counselor', 'guidance_supervisor'] as $allowed) {
            $this->gateService->assertSignerCapacityAllowed($allowed, 1);
        }

        // 2. Guardian / parent capacities are rejected with 403 DomainException in GuidanceActivationGateService
        foreach (['guardian', 'parent', 'parent_guardian', 'simulated_guardian'] as $blockedCapacity) {
            try {
                $this->gateService->assertSignerCapacityAllowed($blockedCapacity, 1);
                self::fail("Expected DomainException (403) for guardian capacity '{$blockedCapacity}'.");
            } catch (DomainException $e) {
                self::assertSame(403, $e->getCode());
                self::assertSame(
                    'Digital guardian attestation is disabled in v1 until guardian identity verification workflow is approved.',
                    $e->getMessage()
                );
            }
        }

        // Create a real form instance & revision to bind attestations against
        $def = $this->definitionService->getActiveDefinition('guidance_appointment_form', 1);
        self::assertNotNull($def);
        $instanceId = (int) $this->instanceModel->insert([
            'tenant_id'              => 1,
            'form_definition_id'     => (int) $def['definition']['id'],
            'definition_revision_id' => (int) $def['revision']['id'],
            'instance_number'        => 'FRM-SIGNER-' . bin2hex(random_bytes(3)),
            'person_id'              => $this->studentPersonIds[1],
            'status'                 => 'finalized',
            'created_by_user_id'     => $this->licensedCounselorUserId,
        ]);
        $revisionId = (int) $this->revisionModel->insert([
            'tenant_id'              => 1,
            'form_instance_id'       => $instanceId,
            'revision_number'        => 1,
            'revision_type'          => 'initial',
            'snapshot_hash'          => hash('sha256', 'test-snapshot'),
            'snapshot_cipher'        => 'cipher',
            'snapshot_nonce'         => 'nonce',
            'snapshot_tag'           => 'tag',
            'snapshot_key_version'   => 1,
            'schema_version'         => '1.0.0',
            'definition_revision_id' => (int) $def['revision']['id'],
            'created_by_user_id'     => $this->licensedCounselorUserId,
        ]);
        $formInstance = $this->instanceModel->find($instanceId);
        $defRevision  = $def['revision'];

        // 3. Student digital account attestation succeeds via FormAttestationService
        $studentAttestation = $this->attestationService->recordAttestation(
            actor: [
                'id'        => $this->studentUserIds[1],
                'tenant_id' => 1,
                'person_id' => $this->studentPersonIds[1],
                'role'      => 'student',
                'name'      => 'Student1 Rollout',
            ],
            formInstance: $formInstance,
            definitionRevision: $defRevision,
            formRevisionId: $revisionId,
            snapshotHash: hash('sha256', 'student-attestation-snapshot'),
            options: ['signer_capacity' => 'student']
        );
        self::assertSame('account_attestation', $studentAttestation['attestation_method']);
        self::assertSame('student', $studentAttestation['signer_capacity']);

        // 4. Staff digital account attestation succeeds via FormAttestationService
        $staffAttestation = $this->attestationService->recordAttestation(
            actor: [
                'id'        => $this->licensedCounselorUserId,
                'tenant_id' => 1,
                'role'      => 'counsellor',
                'name'      => 'Laura Licensed',
            ],
            formInstance: $formInstance,
            definitionRevision: $defRevision,
            formRevisionId: $revisionId,
            snapshotHash: hash('sha256', 'staff-attestation-snapshot'),
            options: ['signer_capacity' => 'staff']
        );
        self::assertSame('account_attestation', $staffAttestation['attestation_method']);
        self::assertSame('staff', $staffAttestation['signer_capacity']);

        // 5. Guardian / simulated guardian signature is rejected in FormAttestationService with 403 DomainException
        try {
            $this->attestationService->recordAttestation(
                actor: [
                    'id'        => $this->studentUserIds[1],
                    'tenant_id' => 1,
                    'role'      => 'student',
                    'name'      => 'Simulated Guardian',
                ],
                formInstance: $formInstance,
                definitionRevision: $defRevision,
                formRevisionId: $revisionId,
                snapshotHash: hash('sha256', 'guardian-snapshot'),
                options: ['signer_capacity' => 'guardian']
            );
            self::fail('Expected FormAttestationService::recordAttestation to reject guardian signer_capacity with 403.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
            self::assertSame(
                'Digital guardian attestation is disabled in v1 until guardian identity verification workflow is approved.',
                $e->getMessage()
            );
        }

        // Also rejected when passed in credentialSnapshot or actor role
        try {
            $this->attestationService->recordAttestation(
                actor: [
                    'id'        => $this->studentUserIds[1],
                    'tenant_id' => 1,
                    'role'      => 'guardian',
                    'name'      => 'Guardian Role Actor',
                ],
                formInstance: $formInstance,
                definitionRevision: $defRevision,
                formRevisionId: $revisionId,
                snapshotHash: hash('sha256', 'guardian-role-snapshot')
            );
            self::fail('Expected FormAttestationService::recordAttestation to reject guardian actor role with 403.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }
    }

    public function testGuidanceMobileContractServiceIsolationAndWorkstationEnforcement(): void
    {
        $student1PersonId = $this->studentPersonIds[1];
        $student2PersonId = $this->studentPersonIds[2];
        $student1Actor    = ['id' => $this->studentUserIds[1], 'person_id' => $student1PersonId, 'role' => 'student', 'tenant_id' => 1];
        $student2Actor    = ['id' => $this->studentUserIds[2], 'person_id' => $student2PersonId, 'role' => 'student', 'tenant_id' => 1];
        $counselorActor   = ['id' => $this->licensedCounselorUserId, 'tenant_id' => 1];

        $counselorActor = $this->signInUser($this->licensedCounselorUserId);

        // 1. Seed confidential clinical note, safety assessment, appointment with secret notes, call slip, MI assignment, and evaluation token
        $secretSafetyMarker   = 'ULTRA_SECRET_SAFETY_PLAN_DO_NOT_LEAK_TO_MOBILE';
        $secretClinicalMarker = 'ULTRA_SECRET_CLINICAL_COUNSELOR_ASSESSMENT_DO_NOT_LEAK';
        $secretAptNotesMarker = 'INTERNAL_COUNSELOR_APPOINTMENT_PREP_NOTES_DO_NOT_LEAK';
        $secretSlipNotes      = 'INTERNAL_CALL_SLIP_DELIVERY_NOTES_DO_NOT_LEAK';

        $this->safetyService->recordSafetyAssessment(
            personId: $student1PersonId,
            riskLevel: 'moderate',
            selfHarm: false,
            harmToOthers: false,
            indicators: ['anxiety_spike', $secretSafetyMarker],
            immediateActions: 'De-escalation and grounding exercises.',
            safetyPlan: $secretSafetyMarker,
            actor: $counselorActor
        );

        $clinicalDraft = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            [
                'person_id'            => $student1PersonId,
                'presenting_concerns'  => $secretClinicalMarker,
                'counselor_assessment' => $secretClinicalMarker,
            ],
            1,
            $counselorActor
        );
        $this->clinicalService->finalizeDirectly((int) $clinicalDraft['id'], 'Finalized by licensed counselor.', 1, $counselorActor);

        $appointment = $this->appointmentService->scheduleAppointment([
            'person_id'         => $student1PersonId,
            'counselor_user_id' => $this->licensedCounselorUserId,
            'scheduled_date'    => date('Y-m-d', strtotime('+2 days')),
            'scheduled_time'    => '09:30:00',
            'purpose'           => 'counseling',
            'notes'             => $secretAptNotesMarker,
            'actor'             => $counselorActor,
        ], 1);

        $callSlip = $this->callSlipService->issueCallSlip([
            'person_id'                 => $student1PersonId,
            'issuing_counselor_user_id' => $this->licensedCounselorUserId,
            'call_date'                 => date('Y-m-d', strtotime('+1 day')),
            'call_time'                 => '14:00:00',
            'purpose'                   => 'routine_interview',
            'delivery_notes'            => $secretSlipNotes,
            'actor'                     => $counselorActor,
        ], 1);

        $miAssignment = $this->miService->assignSurvey(
            $student1PersonId,
            ['academic_term' => '1st Trimester', 'academic_year' => '2026-2027'],
            1,
            $counselorActor
        );

        $this->evaluationService->issueEvaluationToken(
            $student1PersonId,
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            $this->licensedCounselorUserId,
            ['session_id' => 901]
        );

        // 2. Verify getStudentDashboardPayload returns student artifacts without leaking confidential data
        $student1Actor = $this->signInUser($this->studentUserIds[1]);
        $dashboard = $this->mobileService->getStudentDashboardPayload($student1PersonId, 1, $student1Actor);

        self::assertSame($student1PersonId, $dashboard['person_id']);
        self::assertSame('mobile_student', $dashboard['surface']);
        self::assertCount(1, $dashboard['appointments']);
        self::assertCount(1, $dashboard['call_slips']);
        self::assertCount(1, $dashboard['mi_assignments']);
        self::assertCount(1, $dashboard['evaluation_tokens']);

        self::assertSame((int) $appointment['id'], $dashboard['appointments'][0]['id']);
        self::assertArrayNotHasKey('notes_cipher', $dashboard['appointments'][0]);
        self::assertArrayNotHasKey('notes', $dashboard['appointments'][0]);

        self::assertSame((int) $callSlip['id'], $dashboard['call_slips'][0]['id']);
        self::assertSame('pending', $dashboard['call_slips'][0]['attendance_status']);
        self::assertArrayNotHasKey('delivery_notes', $dashboard['call_slips'][0]);

        self::assertSame((int) $miAssignment['id'], $dashboard['mi_assignments'][0]['id']);
        self::assertSame('assigned', $dashboard['mi_assignments'][0]['status']);

        $serializedDashboard = json_encode($dashboard, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secretSafetyMarker, $serializedDashboard);
        self::assertStringNotContainsString($secretClinicalMarker, $serializedDashboard);
        self::assertStringNotContainsString($secretAptNotesMarker, $serializedDashboard);
        self::assertStringNotContainsString($secretSlipNotes, $serializedDashboard);
        self::assertArrayNotHasKey('safety_assessments', $dashboard);
        self::assertArrayNotHasKey('clinical_forms', $dashboard);

        // 3. Verify acknowledgeCallSlipFromMobile blocks other students (403) and allows target student
        $student2Actor = $this->signInUser($this->studentUserIds[2]);
        try {
            $this->mobileService->acknowledgeCallSlipFromMobile((int) $callSlip['id'], $student2PersonId, 1, $student2Actor);
            self::fail('Expected DomainException (403) when another student attempts to acknowledge call slip.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        $student1Actor = $this->signInUser($this->studentUserIds[1]);
        $acknowledged = $this->mobileService->acknowledgeCallSlipFromMobile((int) $callSlip['id'], $student1PersonId, 1, $student1Actor);
        self::assertSame('acknowledged', $acknowledged['attendance_status']);
        self::assertNotEmpty($acknowledged['acknowledged_at']);

        // 4. Verify assertMobileActionPermitted allows student actions and blocks workstation-only clinical/safety actions
        foreach (GuidanceMobileContractService::ALLOWED_MOBILE_ACTIONS as $permittedAction) {
            $this->mobileService->assertMobileActionPermitted($permittedAction);
        }

        $workstationOnlyActions = [
            'create_clinical_draft',
            'finalize_clinical_form',
            'cosign_clinical_form',
            'record_safety_assessment',
            'dispatch_safety_escalation',
            'initiate_case_transfer',
            'moderate_evaluation_comments',
        ];
        foreach ($workstationOnlyActions as $blockedAction) {
            try {
                $this->mobileService->assertMobileActionPermitted($blockedAction);
                self::fail("Expected workstation action '{$blockedAction}' to be blocked on mobile with 403.");
            } catch (DomainException $e) {
                self::assertSame(403, $e->getCode());
                self::assertSame(
                    'Clinical authoring and safety triage workflows are restricted to the web workstation.',
                    $e->getMessage()
                );
            }
        }
    }

    public function testEndToEndPaperlessJourneyAcrossPhasesZeroToEight(): void
    {
        $facultyActor    = ['id' => $this->facultyUserId, 'tenant_id' => 1];
        $associateActor  = ['id' => $this->associateUserId, 'tenant_id' => 1];
        $supervisorActor = ['id' => $this->supervisorUserId, 'tenant_id' => 1];
        $student1Actor   = [
            'id'        => $this->studentUserIds[1],
            'person_id' => $this->studentPersonIds[1],
            'role'      => 'student',
            'name'      => 'Student1 Rollout',
            'tenant_id' => 1,
        ];

        // =====================================================================
        // Step 1: Phase 8 Activation Gates Signoff (Guidance Head + QA + DPO)
        // =====================================================================
        foreach (array_keys(GuidanceActivationGateService::DEFAULT_GATES) as $gateKey) {
            $this->gateService->recordSignoff($gateKey, 'guidance_head', 'Approved by Guidance Head', 1, $supervisorActor);
            $this->gateService->recordSignoff($gateKey, 'qa_office', 'Approved by QA Office', 1, ['id' => $this->qaOfficerUserId]);
            $this->gateService->recordSignoff($gateKey, 'dpo', 'Approved by DPO', 1, ['id' => $this->dpoUserId]);
            $this->gateService->assertGateEnabled($gateKey, 1);
        }

        // =====================================================================
        // Step 2: Phase 3 General Referral Intake & Authoritative Safety Triage
        // =====================================================================
        $facultyActor = $this->signInUser($this->facultyUserId);
        $resolvedSubject = $this->intakeService->resolveSubject($this->primaryStudentMisId, 1);
        $subjectPersonId = (int) $resolvedSubject['person_id'];
        self::assertSame($this->studentPersonIds[1], $subjectPersonId);

        $referralDraft = $this->intakeService->createDraft(
            subjectPersonId: $subjectPersonId,
            sourceType: 'faculty',
            selections: ['academic_deficiency', 'emotional_distress'],
            narrative: 'Student missed two capstone milestones and expressed feeling overwhelmed.',
            priorActions: 'Initial academic advising check-in.',
            priorityHint: 'urgent',
            actor: $facultyActor
        );
        self::assertSame('draft', $referralDraft['status']);

        $submittedReferral = $this->intakeService->submitReferral((int) $referralDraft['id'], $facultyActor);
        self::assertSame('submitted', $submittedReferral['status']);

        $associateActor = $this->signInUser($this->associateUserId);
        $receivedReferral = $this->intakeService->receiveReferral((int) $referralDraft['id'], $associateActor);
        self::assertSame('received', $receivedReferral['status']);

        $triagedReferral = $this->intakeService->triageReferral(
            (int) $referralDraft['id'],
            'urgent',
            'Prioritize for immediate psychosocial interview and safety check.',
            $associateActor
        );
        self::assertSame('triaged', $triagedReferral['status']);

        $acceptedReferral = $this->intakeService->acceptReferral(
            (int) $referralDraft['id'],
            $this->associateUserId,
            $associateActor
        );
        self::assertSame('accepted', $acceptedReferral['status']);

        // Authoritative clinical safety assessment by qualified practitioner
        $safetyAssessment = $this->safetyService->recordSafetyAssessment(
            personId: $subjectPersonId,
            riskLevel: 'low',
            selfHarm: false,
            harmToOthers: false,
            indicators: ['academic_burnout', 'sleep_disruption'],
            immediateActions: 'Conducted supportive intake and scheduled structured follow-up.',
            safetyPlan: 'Maintain regular sleep schedule and contact OGC if stress escalates.',
            intakeReferralId: (int) $referralDraft['id'],
            actor: $associateActor
        );
        self::assertSame('low', $safetyAssessment['risk_level']);
        self::assertSame((int) $referralDraft['id'], (int) $safetyAssessment['intake_referral_id']);

        // =====================================================================
        // Step 3: Phase 2 & 4 Case Linkage, Episode, Appointment & Call Slip
        // =====================================================================
        $linkedReferral = $this->intakeService->linkToCase((int) $referralDraft['id'], null, $associateActor);
        self::assertSame('case_linked', $linkedReferral['status']);
        $caseId = (int) $linkedReferral['linked_case_id'];
        self::assertGreaterThan(0, $caseId);

        $episode = $this->episodeService->openEpisode($subjectPersonId, [
            'intake_referral_id'        => (int) $referralDraft['id'],
            'case_id'                   => $caseId,
            'primary_counselor_user_id' => $this->associateUserId,
            'actor'                     => $associateActor,
        ], 1);
        $episodeId = (int) $episode['id'];
        self::assertSame('open', $episode['status']);

        $appointment = $this->appointmentService->scheduleAppointment([
            'person_id'         => $subjectPersonId,
            'episode_id'        => $episodeId,
            'counselor_user_id' => $this->associateUserId,
            'scheduled_date'    => date('Y-m-d', strtotime('+1 day')),
            'scheduled_time'    => '10:00:00',
            'purpose'           => 'counseling',
            'notes'             => 'First structured counseling session.',
            'actor'             => $associateActor,
        ], 1);
        $appointmentId = (int) $appointment['id'];
        self::assertSame('scheduled', $appointment['status']);

        $callSlip = $this->callSlipService->issueCallSlip([
            'person_id'                 => $subjectPersonId,
            'episode_id'                => $episodeId,
            'issuing_counselor_user_id' => $this->associateUserId,
            'call_date'                 => date('Y-m-d', strtotime('+1 day')),
            'call_time'                 => '10:00:00',
            'purpose'                   => 'personal_counseling',
            'actor'                     => $associateActor,
        ], 1);
        $callSlipId = (int) $callSlip['id'];
        self::assertSame('pending', $callSlip['attendance_status']);

        // Student acknowledges Call Slip from Mobile Contract Service
        $student1Actor = $this->signInUser($this->studentUserIds[1]);
        $ackSlip = $this->mobileService->acknowledgeCallSlipFromMobile($callSlipId, $subjectPersonId, 1, $student1Actor);
        self::assertSame('acknowledged', $ackSlip['attendance_status']);

        // =====================================================================
        // Step 4: Phase 1 & 5 Controlled Clinical Form (Associate + Co-Sign)
        // =====================================================================
        $associateActor = $this->signInUser($this->associateUserId);
        $clinicalDraft = $this->clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            [
                'person_id'            => $subjectPersonId,
                'case_id'              => $caseId,
                'episode_id'           => $episodeId,
                'appointment_id'       => $appointmentId,
                'presenting_concerns'  => 'Capstone workload anxiety and perfectionism.',
                'counselor_assessment' => 'Student demonstrates strong resilience; needs structured milestone breakdown.',
                'intervention_plan'    => 'Weekly task chunking and cognitive reframing exercises.',
            ],
            1,
            $associateActor
        );
        $clinicalInstanceId = (int) $clinicalDraft['id'];
        self::assertSame('draft', $clinicalDraft['status']);

        // Associate submits for supervisor co-signature
        $pendingCosign = $this->clinicalService->submitForCoSign(
            $clinicalInstanceId,
            'Ready for supervisor review and co-signature.',
            1,
            $associateActor
        );
        self::assertSame('pending_cosign', $pendingCosign['status']);
        self::assertSame($this->supervisorUserId, (int) $pendingCosign['assigned_supervisor_user_id']);

        // Assigned Supervisor co-signs and finalizes
        $supervisorActor = $this->signInUser($this->supervisorUserId);
        $finalizedClinical = $this->clinicalService->coSignAndFinalize(
            $clinicalInstanceId,
            'I, Sarah Supervisor, digitally attest and co-sign this Psychosocial Support Interview record.',
            'Co-signed and approved for official clinical record.',
            1,
            $supervisorActor
        );
        self::assertSame('finalized', $finalizedClinical['status']);
        self::assertTrue($finalizedClinical['cosigned']);
        self::assertSame('cosigner', $finalizedClinical['attestation']['signer_role']);

        $decryptedClinical = $this->clinicalService->getFinalizedClinicalForm($clinicalInstanceId, 1, 1, $supervisorActor);
        self::assertSame('Capstone workload anxiety and perfectionism.', $decryptedClinical['data']['presenting_concerns']);

        // =====================================================================
        // Step 5: Phase 6 Multiple Intelligence 80-Item Assessment
        // =====================================================================
        $associateActor = $this->signInUser($this->associateUserId);
        $miAssignment = $this->miService->assignSurvey(
            $subjectPersonId,
            ['academic_term' => '1st Trimester', 'academic_year' => '2026-2027'],
            1,
            $associateActor
        );
        $miAssignmentId = (int) $miAssignment['id'];

        $miResponses = [];
        for ($item = 1; $item <= 80; $item++) {
            $miResponses[$item] = ($item % 5); // 0..4
        }

        $student1Actor = $this->signInUser($this->studentUserIds[1]);
        $this->miService->saveDraft($miAssignmentId, array_slice($miResponses, 0, 20, true), 1, $student1Actor);

        $finalizedMi = $this->miService->finalizeAssessment(
            $miAssignmentId,
            $miResponses,
            'I, Student1 Rollout, digitally attest that I completed this Multiple Intelligences Survey honestly.',
            1,
            $student1Actor
        );
        self::assertSame('finalized', $finalizedMi['status']);

        $miResults = $this->miService->getResults($miAssignmentId, 1, $student1Actor);
        self::assertCount(8, $miResults['domains']);

        // =====================================================================
        // Step 6: Phase 7 Pseudonymous Service Evaluation (Cohort = 5)
        // =====================================================================
        $supervisorActor = $this->signInUser($this->supervisorUserId);
        for ($i = 1; $i <= 5; $i++) {
            $tok = $this->evaluationService->issueEvaluationToken(
                $this->studentPersonIds[$i],
                GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
                $this->associateUserId,
                ['episode_id' => $episodeId, 'session_id' => 1000 + $i]
            )['token'];

            $evalRow = $this->evaluationService->submitEvaluation($tok, [
                'visit_type'               => 'referred',
                'rating_welcoming'         => 5,
                'rating_empathy'           => 5,
                'rating_clarity'           => 4,
                'rating_helpfulness'       => 5,
                'rating_environment'       => 5,
                'future_visit_willingness' => true,
                'qualitative_comments'     => "End-to-end evaluation feedback #{$i}",
                'academic_term'            => '1st Trimester',
                'academic_year'            => '2026-2027',
            ]);

            $this->evaluationService->moderateComment((int) $evalRow['id'], 'approved', 1, $supervisorActor);
        }

        $aggregates = $this->evaluationService->getProviderAggregates(
            $this->associateUserId,
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            [],
            1,
            $supervisorActor
        );
        self::assertTrue($aggregates['cohort_met']);
        self::assertSame(5, $aggregates['total_responses']);
        self::assertCount(5, $aggregates['comments']);
        self::assertSame(4.8, $aggregates['averages']['overall_mean']);

        // =====================================================================
        // Step 7: Controlled PDF Compilation Across Subsystems
        // =====================================================================
        // 7a. Clinical Psychosocial Interview PDF issuance
        $clinicalInstanceRow = $this->instanceModel->find($clinicalInstanceId);
        $clinicalRevisionRow = $this->revisionModel->where('form_instance_id', $clinicalInstanceId)->first();
        $clinicalDef         = $this->definitionService->getActiveDefinition(GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW, 1);

        $issuedClinicalDoc = $this->documentService->issueDocument(
            $clinicalInstanceRow,
            $clinicalDef['revision'],
            $clinicalRevisionRow,
            $decryptedClinical['data'],
            $finalizedClinical['attestation'],
            $supervisorActor
        );
        self::assertStringStartsWith('%PDF-', $issuedClinicalDoc['pdf']);
        self::assertStringStartsWith('SYN-DOC-', $issuedClinicalDoc['issuance']['verification_code']);

        // 7b. Multiple Intelligence 80-Item Report PDF
        $miPdf = $this->documentService->renderMultipleIntelligencePdf([
            'student_name'    => 'Student1 Rollout',
            'student_id'      => $this->primaryStudentMisId,
            'course_year'     => 'BS Computer Science - 3',
            'assessed_at'     => $finalizedMi['attested_at'],
            'domain_scores'   => $miResults['domain_scores'],
            'domains'         => $miResults['domains'],
            'attestation'     => [
                'signer_name'   => 'Student1 Rollout',
                'signer_mis_id' => $this->primaryStudentMisId,
                'attested_at'   => $finalizedMi['attested_at'],
                'snapshot_hash' => $finalizedMi['snapshot_hash'],
            ],
            'is_draft'        => false,
        ]);
        self::assertStringStartsWith('%PDF-', $miPdf);

        // 7c. Pseudonymous Service Evaluation Aggregate Report PDF
        $evalPdf = $this->documentService->renderPsychosocialEvaluationPdf($aggregates);
        self::assertStringStartsWith('%PDF-', $evalPdf);
    }
}

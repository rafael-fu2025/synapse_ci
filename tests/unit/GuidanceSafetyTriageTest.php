<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\CounsellingCaseModel;
use App\Models\GuidanceIntakeReferralModel;
use App\Models\GuidancePractitionerProfileModel;
use App\Models\GuidanceSafetyAssessmentModel;
use App\Models\GuidanceSafetyEscalationModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceCaseService;
use App\Services\Guidance\GuidanceIntakeService;
use App\Services\Guidance\GuidancePractitionerService;
use App\Services\Guidance\GuidanceSafetyService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceSafetyTriageTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidanceSafetyService $safetyService;
    private GuidanceIntakeService $intakeService;
    private GuidanceSafetyAssessmentModel $safetyAssessmentModel;
    private GuidanceSafetyEscalationModel $safetyEscalationModel;
    private GuidanceIntakeReferralModel $intakeReferralModel;
    private GuidancePractitionerProfileModel $profileModel;
    private PersonModel $personModel;
    private UserModel $userModel;
    private CounsellingCaseModel $caseModel;
    private EncryptionService $encryptionService;
    private GuidanceAccessPolicy $accessPolicy;

    private int $qualifiedCounselorUserId;
    private int $guidanceSupervisorUserId;
    private int $expiredPractitionerUserId;
    private int $inactivePractitionerUserId;
    private int $superadminUserId;
    private int $generalEmployeeUserId;
    private int $studentPersonId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        $this->safetyAssessmentModel = new GuidanceSafetyAssessmentModel($this->db);
        $this->safetyEscalationModel = new GuidanceSafetyEscalationModel($this->db);
        $this->intakeReferralModel = new GuidanceIntakeReferralModel($this->db);
        $this->profileModel = new GuidancePractitionerProfileModel($this->db);
        $this->personModel = new PersonModel($this->db);
        $this->userModel = new UserModel($this->db);
        $this->caseModel = new CounsellingCaseModel($this->db);
        $studentProfileModel = new StudentProfileModel($this->db);
        $this->encryptionService = new EncryptionService();
        $this->accessPolicy = new GuidanceAccessPolicy(db: $this->db);

        $this->safetyService = new GuidanceSafetyService(
            safetyAssessmentModel: $this->safetyAssessmentModel,
            safetyEscalationModel: $this->safetyEscalationModel,
            personModel: $this->personModel,
            profileModel: $this->profileModel,
            intakeReferralModel: $this->intakeReferralModel,
            caseModel: $this->caseModel,
            encryptionService: $this->encryptionService,
            accessPolicy: $this->accessPolicy,
            db: $this->db
        );

        $guidanceCaseService = new GuidanceCaseService(
            caseModel: $this->caseModel,
            personModel: $this->personModel,
            studentModel: $studentProfileModel,
            encryptionService: $this->encryptionService,
            db: $this->db,
            accessPolicy: $this->accessPolicy
        );

        $this->intakeService = new GuidanceIntakeService(
            referralModel: $this->intakeReferralModel,
            personModel: $this->personModel,
            studentProfileModel: $studentProfileModel,
            userModel: $this->userModel,
            caseModel: $this->caseModel,
            guidanceCaseService: $guidanceCaseService,
            encryptionService: $this->encryptionService,
            accessPolicy: $this->accessPolicy,
            db: $this->db
        );

        $unique = bin2hex(random_bytes(4));

        // 1. Student person
        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'STU_SAFE_' . $unique,
            'first_name' => 'Bea',
            'last_name'  => 'Alvarez',
        ]);

        // 2. Qualified Licensed Counselor (active profile, valid PRC license)
        $counsPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'LIC_COUNS_' . $unique,
            'first_name' => 'Elena',
            'last_name'  => 'Reyes',
        ]);

        $this->qualifiedCounselorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $counsPersonId,
            'username'  => 'elena_couns_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $counsPersonId,
            'user_id'               => $this->qualifiedCounselorUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'PRC-SAFE-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+2 years')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'intake', 'psychosocial', 'crisis']),
            'is_active'             => 1,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        // 3. Guidance Supervisor
        $supPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'SUP_SAFE_' . $unique,
            'first_name' => 'Rolando',
            'last_name'  => 'Supervisor',
        ]);

        $this->guidanceSupervisorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $supPersonId,
            'username'  => 'rolando_sup_' . $unique,
            'role'      => 'guidance_supervisor',
            'status'    => 'active',
        ]);

        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $supPersonId,
            'user_id'               => $this->guidanceSupervisorUserId,
            'credential_tier'       => 'guidance_supervisor',
            'license_number'        => 'PRC-SUP-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+3 years')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'intake', 'supervision', 'crisis']),
            'is_active'             => 1,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        // 4. Practitioner with Expired Credentials
        $expPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EXP_COUNS_' . $unique,
            'first_name' => 'Oscar',
            'last_name'  => 'Expired',
        ]);

        $this->expiredPractitionerUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $expPersonId,
            'username'  => 'oscar_exp_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $expPersonId,
            'user_id'               => $this->expiredPractitionerUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'PRC-EXP-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('-1 month')), // Expired
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling']),
            'is_active'             => 1,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        // 5. Practitioner with Inactive Professional Standing
        $inactPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'INACT_COUNS_' . $unique,
            'first_name' => 'Irene',
            'last_name'  => 'Inactive',
        ]);

        $this->inactivePractitionerUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $inactPersonId,
            'username'  => 'irene_inact_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $inactPersonId,
            'user_id'               => $this->inactivePractitionerUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'PRC-INACT-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+1 year')),
            'professional_standing' => 'suspended', // Suspended standing
            'service_scope_json'    => json_encode(['counseling']),
            'is_active'             => 1,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        // 6. Superadmin (administrative role, but no clinical practitioner profile)
        $this->superadminUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => null,
            'username'  => 'superadmin_' . $unique,
            'role'      => 'superadmin',
            'status'    => 'active',
        ]);

        // 7. General Employee (faculty / staff, no clinical credentials)
        $empPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'GEN_EMP_' . $unique,
            'first_name' => 'Fiona',
            'last_name'  => 'Faculty',
        ]);

        $this->generalEmployeeUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $empPersonId,
            'username'  => 'fiona_gen_' . $unique,
            'role'      => 'employee',
            'status'    => 'active',
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

    /**
     * Verify distinction between referral priority attention hint and clinical safety assessment.
     */
    public function testDistinctionBetweenReferralPriorityHintAndClinicalSafetyAssessment(): void
    {
        $employeeActor = $this->signInUser($this->generalEmployeeUserId);

        // 1. Employee creates a referral with 'routine' priority hint
        $draft = $this->intakeService->createDraft(
            subjectPersonId: $this->studentPersonId,
            sourceType: 'employee_teacher',
            selections: ['emotional_distress'],
            narrative: 'Student appears a bit fatigued in morning classes.',
            priorActions: null,
            priorityHint: 'routine',
            actor: $employeeActor
        );
        $referralId = (int) $draft['id'];
        $submitted = $this->intakeService->submitReferral($referralId, $employeeActor);
        $this->assertSame('routine', $submitted['priority_hint']);

        // 2. Qualified counselor evaluates student and makes authoritative clinical determination: imminent_danger
        $counselorActor = $this->signInUser($this->qualifiedCounselorUserId);

        $assessment = $this->safetyService->recordSafetyAssessment(
            personId: $this->studentPersonId,
            riskLevel: 'imminent_danger',
            selfHarm: true,
            harmToOthers: false,
            indicators: ['active_suicidal_ideation', 'stated_intent', 'lethal_means_access'],
            immediateActions: 'Immediate 1-on-1 visual observation maintained; crisis response activated.',
            safetyPlan: 'Student escorted to health center for urgent medical-psychiatric evaluation.',
            intakeReferralId: $referralId,
            caseId: null,
            actor: $counselorActor
        );

        $this->assertSame('imminent_danger', $assessment['risk_level']);
        $this->assertSame(1, (int) $assessment['self_harm_risk']);
        $this->assertSame(0, (int) $assessment['harm_to_others_risk']);
        $this->assertSame($referralId, (int) $assessment['intake_referral_id']);

        // The referral attention hint remains 'routine' (the referrer's subjective view),
        // proving the clinical safety assessment is authoritative, independent, and distinct.
        $refCheck = $this->intakeService->getReferral($referralId);
        $this->assertSame('routine', $refCheck['priority_hint']);

        // 3. Clinical assessment strictly rejects referrer priority hints ('routine', 'urgent', 'crisis')
        // that are not valid clinical risk levels.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->safetyService->recordSafetyAssessment(
            personId: $this->studentPersonId,
            riskLevel: 'routine', // Invalid clinical risk level! Must be low, moderate, high, imminent_danger
            selfHarm: false,
            harmToOthers: false,
            indicators: ['minor_stress'],
            immediateActions: 'None',
            actor: $counselorActor
        );
    }

    /**
     * Verify professional eligibility enforcement on safety assessment (throws 403 for unauthorized users).
     */
    public function testProfessionalEligibilityEnforcementThrows403ForUnauthorizedUsers(): void
    {
        $counselorActor = $this->signInUser($this->qualifiedCounselorUserId);

        // 1. Qualified practitioner passes eligibility check
        $this->assertTrue($this->safetyService->canPerformSafetyAssessment($this->qualifiedCounselorUserId));
        $verified = $this->safetyService->assertProfessionalEligibility($this->qualifiedCounselorUserId);
        $this->assertSame('licensed_counselor', $verified['credential_tier']);

        // 2. Superadmin without clinical practitioner profile -> throws 403
        $superadminActor = $this->signInUser($this->superadminUserId);
        $this->assertFalse($this->safetyService->canPerformSafetyAssessment($this->superadminUserId));
        try {
            $this->safetyService->recordSafetyAssessment(
                personId: $this->studentPersonId,
                riskLevel: 'high',
                selfHarm: true,
                harmToOthers: false,
                indicators: ['severe_anxiety'],
                immediateActions: 'Attempted by superadmin',
                actor: $superadminActor
            );
            $this->fail('Superadmin without clinical credentials must be rejected with 403.');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // 3. General employee / teacher -> throws 403
        $employeeActor = $this->signInUser($this->generalEmployeeUserId);
        $this->assertFalse($this->safetyService->canPerformSafetyAssessment($this->generalEmployeeUserId));
        try {
            $this->safetyService->recordSafetyAssessment(
                personId: $this->studentPersonId,
                riskLevel: 'moderate',
                selfHarm: false,
                harmToOthers: false,
                indicators: ['stress'],
                immediateActions: 'Attempted by teacher',
                actor: $employeeActor
            );
            $this->fail('General employee without clinical credentials must be rejected with 403.');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // 4. Practitioner with expired credentials -> throws 403
        $expiredActor = $this->signInUser($this->expiredPractitionerUserId);
        $this->assertFalse($this->safetyService->canPerformSafetyAssessment($this->expiredPractitionerUserId));
        try {
            $this->safetyService->recordSafetyAssessment(
                personId: $this->studentPersonId,
                riskLevel: 'high',
                selfHarm: true,
                harmToOthers: false,
                indicators: ['self_harm_ideation'],
                immediateActions: 'Attempted by expired practitioner',
                actor: $expiredActor
            );
            $this->fail('Practitioner with expired credentials must be rejected with 403.');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // 5. Practitioner with suspended standing -> throws 403
        $inactiveActor = $this->signInUser($this->inactivePractitionerUserId);
        $this->assertFalse($this->safetyService->canPerformSafetyAssessment($this->inactivePractitionerUserId));
        try {
            $this->safetyService->recordSafetyAssessment(
                personId: $this->studentPersonId,
                riskLevel: 'moderate',
                selfHarm: false,
                harmToOthers: false,
                indicators: ['panic_attacks'],
                immediateActions: 'Attempted by suspended practitioner',
                actor: $inactiveActor
            );
            $this->fail('Practitioner with inactive standing must be rejected with 403.');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    /**
     * Verify safety assessment risk levels and encrypted indicators.
     */
    public function testSafetyAssessmentRiskLevelsAndEncryptedIndicators(): void
    {
        $counselorActor = $this->signInUser($this->qualifiedCounselorUserId);

        // 1. Verify all 4 authoritative risk levels can be recorded
        $validLevels = ['low', 'moderate', 'high', 'imminent_danger'];
        foreach ($validLevels as $level) {
            $res = $this->safetyService->recordSafetyAssessment(
                personId: $this->studentPersonId,
                riskLevel: $level,
                selfHarm: ($level === 'high' || $level === 'imminent_danger'),
                harmToOthers: false,
                indicators: ["indicator_for_{$level}"],
                immediateActions: "Action taken for {$level}",
                actor: $counselorActor
            );
            $this->assertSame($level, $res['risk_level']);
        }

        // 2. Verify encrypted indicators and safety plan in raw database row
        $sensitiveIndicators = [
            'chronic_depression',
            'hopelessness',
            'previous_attempt_2025',
            'specific_plan_narrated',
        ];
        $confidentialPlan = 'Confidential Crisis Plan: 24/7 guardian presence, safety contract signed, weekly counseling.';

        $assessment = $this->safetyService->recordSafetyAssessment(
            personId: $this->studentPersonId,
            riskLevel: 'high',
            selfHarm: true,
            harmToOthers: false,
            indicators: $sensitiveIndicators,
            immediateActions: 'De-escalated via crisis interview; parent notified immediately.',
            safetyPlan: $confidentialPlan,
            actor: $counselorActor
        );
        $assessmentId = (int) $assessment['id'];

        $rawRow = $this->db->table('guidance_safety_assessments')
            ->where('id', $assessmentId)
            ->get()
            ->getRowArray();

        $this->assertNotNull($rawRow, 'Raw safety assessment row must exist.');

        // Plaintext must NEVER appear in raw database storage
        $this->assertStringNotContainsString('hopelessness', (string) $rawRow['indicators_cipher']);
        $this->assertStringNotContainsString('previous_attempt', (string) $rawRow['indicators_cipher']);
        $this->assertStringNotContainsString('safety contract signed', (string) $rawRow['safety_plan_cipher']);

        // GCM envelope metadata must be populated
        $this->assertNotEmpty($rawRow['indicators_cipher']);
        $this->assertNotEmpty($rawRow['indicators_nonce']);
        $this->assertNotEmpty($rawRow['indicators_tag']);
        $this->assertGreaterThan(0, (int) $rawRow['indicators_key_version']);

        $this->assertNotEmpty($rawRow['safety_plan_cipher']);
        $this->assertNotEmpty($rawRow['safety_plan_nonce']);
        $this->assertNotEmpty($rawRow['safety_plan_tag']);
        $this->assertGreaterThan(0, (int) $rawRow['safety_plan_key_version']);

        // Decrypted retrieval via getSafetyHistory returns authentic data
        $history = $this->safetyService->getSafetyHistory($this->studentPersonId, $counselorActor);
        $this->assertSame($this->studentPersonId, $history['person_id']);
        $this->assertNotEmpty($history['assessments']);

        $found = null;
        foreach ($history['assessments'] as $item) {
            if ((int) $item['id'] === $assessmentId) {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found, 'Recorded assessment must be in history.');
        $this->assertSame($sensitiveIndicators, $found['indicators']);
        $this->assertSame($confidentialPlan, $found['safety_plan']);
    }

    /**
     * Verify safety escalation event creation and acknowledgment receipt logging.
     */
    public function testSafetyEscalationEventCreationAndAcknowledgmentReceiptLogging(): void
    {
        $counselorActor = $this->signInUser($this->qualifiedCounselorUserId);

        // 1. Record safety assessment
        $assessment = $this->safetyService->recordSafetyAssessment(
            personId: $this->studentPersonId,
            riskLevel: 'imminent_danger',
            selfHarm: true,
            harmToOthers: true,
            indicators: ['extreme_agitation', 'threats_to_self_and_others'],
            immediateActions: 'Security and medical standby requested on site.',
            safetyPlan: 'Emergency transfer to tertiary hospital psychiatric emergency service.',
            actor: $counselorActor
        );
        $assessmentId = (int) $assessment['id'];

        // 2. Dispatch escalation to valid channel
        $escalation = $this->safetyService->recordEscalation(
            safetyAssessmentId: $assessmentId,
            channel: 'internal_crisis_team',
            recipient: 'University Crisis Response Team (UCRT)',
            summary: 'Active danger protocol activated. Team requested in Building B guidance office immediately.',
            actor: $counselorActor
        );

        $escalationId = (int) $escalation['id'];
        $this->assertSame('internal_crisis_team', $escalation['escalation_channel']);
        $this->assertSame('University Crisis Response Team (UCRT)', $escalation['recipient_agency_or_person']);
        $this->assertNull($escalation['acknowledged_at']);
        $this->assertNull($escalation['acknowledged_by']);

        // 3. Reject invalid escalation channel
        try {
            $this->safetyService->recordEscalation(
                safetyAssessmentId: $assessmentId,
                channel: 'unregistered_social_channel',
                recipient: 'Campus Group Chat',
                summary: 'Invalid broadcast attempt',
                actor: $counselorActor
            );
            $this->fail('Invalid escalation channel must throw InvalidArgumentException (422).');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(422, $e->getCode());
        }

        // 4. Acknowledge escalation receipt
        $acknowledged = $this->safetyService->acknowledgeEscalation(
            escalationId: $escalationId,
            acknowledgedBy: 'Officer R. Mendoza, UCRT Commander',
            actor: $counselorActor
        );

        $this->assertSame('Officer R. Mendoza, UCRT Commander', $acknowledged['acknowledged_by']);
        $this->assertNotNull($acknowledged['acknowledged_at']);

        // Acknowledgment with empty name throws 422
        try {
            $this->safetyService->acknowledgeEscalation(
                escalationId: $escalationId,
                acknowledgedBy: '   ',
                actor: $counselorActor
            );
            $this->fail('Acknowledgment with blank name must throw 422.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(422, $e->getCode());
        }

        // 5. Verify escalation and acknowledgment in safety history timeline
        $history = $this->safetyService->getSafetyHistory($this->studentPersonId, $counselorActor);
        $this->assertNotEmpty($history['escalations']);

        $escalationFound = null;
        foreach ($history['escalations'] as $esc) {
            if ((int) $esc['id'] === $escalationId) {
                $escalationFound = $esc;
                break;
            }
        }
        $this->assertNotNull($escalationFound);
        $this->assertSame('Officer R. Mendoza, UCRT Commander', $escalationFound['acknowledged_by']);
        $this->assertNotNull($escalationFound['acknowledged_at']);

        // Verify chronological events timeline
        $this->assertNotEmpty($history['events']);
        $hasEscalationEvent = false;
        foreach ($history['events'] as $evt) {
            if ($evt['event_type'] === 'safety_escalation') {
                $hasEscalationEvent = true;
                break;
            }
        }
        $this->assertTrue($hasEscalationEvent, 'Safety history must include escalation in timeline events.');
    }
}

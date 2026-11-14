<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\CounsellingCaseModel;
use App\Models\GuidanceIntakeReferralModel;
use App\Models\PersonModel;
use App\Models\ReferralModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceCaseService;
use App\Services\Guidance\GuidanceIntakeService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceIntakeServiceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidanceIntakeService $intakeService;
    private GuidanceIntakeReferralModel $referralModel;
    private PersonModel $personModel;
    private StudentProfileModel $studentProfileModel;
    private UserModel $userModel;
    private CounsellingCaseModel $caseModel;
    private ReferralModel $clinicReferralModel;
    private EncryptionService $encryptionService;
    private GuidanceAccessPolicy $accessPolicy;

    private int $referrerUserId;
    private int $otherReferrerUserId;
    private int $counsellorUserId;
    private int $studentPersonId;
    private string $studentMisId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        $this->referralModel = new GuidanceIntakeReferralModel($this->db);
        $this->personModel = new PersonModel($this->db);
        $this->studentProfileModel = new StudentProfileModel($this->db);
        $this->userModel = new UserModel($this->db);
        $this->caseModel = new CounsellingCaseModel($this->db);
        $this->clinicReferralModel = new ReferralModel($this->db);
        $this->encryptionService = new EncryptionService();
        $this->accessPolicy = new GuidanceAccessPolicy(db: $this->db);

        $guidanceCaseService = new GuidanceCaseService(
            caseModel: $this->caseModel,
            personModel: $this->personModel,
            studentModel: $this->studentProfileModel,
            encryptionService: $this->encryptionService,
            db: $this->db,
            accessPolicy: $this->accessPolicy
        );

        $this->intakeService = new GuidanceIntakeService(
            referralModel: $this->referralModel,
            personModel: $this->personModel,
            studentProfileModel: $this->studentProfileModel,
            userModel: $this->userModel,
            caseModel: $this->caseModel,
            clinicReferralModel: $this->clinicReferralModel,
            guidanceCaseService: $guidanceCaseService,
            encryptionService: $this->encryptionService,
            accessPolicy: $this->accessPolicy,
            db: $this->db
        );

        $unique = bin2hex(random_bytes(4));

        // 1. Student person + profile
        $this->studentMisId = 'STU_INT_' . $unique;
        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => $this->studentMisId,
            'first_name' => 'Carla',
            'last_name'  => 'Cruz',
        ]);

        $this->studentProfileModel->insert([
            'tenant_id'  => 1,
            'person_id'  => $this->studentPersonId,
            'program'    => 'BS Information Technology',
            'year_level' => 3,
            'section'    => '3-A',
            'department' => 'College of Computer Studies',
        ]);

        // 2. Referrer User (Teacher / Faculty)
        $teacherPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'FAC_' . $unique,
            'first_name' => 'Professor',
            'last_name'  => 'Oak',
        ]);

        $this->referrerUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $teacherPersonId,
            'username'  => 'prof_oak_' . $unique,
            'role'      => 'employee',
            'status'    => 'active',
        ]);

        // 3. Other Referrer User (for unauthorized withdrawal check)
        $otherPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'FAC_OTHER_' . $unique,
            'first_name' => 'Instructor',
            'last_name'  => 'Birch',
        ]);

        $this->otherReferrerUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $otherPersonId,
            'username'  => 'prof_birch_' . $unique,
            'role'      => 'employee',
            'status'    => 'active',
        ]);

        // 4. Counselor User
        $counsellorPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'COUNS_' . $unique,
            'first_name' => 'Grace',
            'last_name'  => 'Guidance',
        ]);

        $this->counsellorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $counsellorPersonId,
            'username'  => 'counsellor_' . $unique,
            'role'      => 'counsellor',
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
     * Verify subject resolver: exact MIS ID returns subject; non-existent ID throws 404; no directory search permitted.
     */
    public function testSubjectResolverExactLookupAndPrivacyGuards(): void
    {
        // 1. Exact lookup returns subject and student academic metadata
        $subject = $this->intakeService->resolveSubject($this->studentMisId);
        $this->assertSame($this->studentPersonId, $subject['person_id']);
        $this->assertSame($this->studentMisId, $subject['mis_id']);
        $this->assertSame('Carla', $subject['first_name']);
        $this->assertSame('Cruz', $subject['last_name']);
        $this->assertSame('student', $subject['kind']);
        $this->assertSame('BS Information Technology', $subject['program']);
        $this->assertSame(3, $subject['year_level']);

        // Alias lookupSubjectByMisId also succeeds
        $aliasSubject = $this->intakeService->lookupSubjectByMisId($this->studentMisId);
        $this->assertSame($subject['person_id'], $aliasSubject['person_id']);

        // 2. Non-existent ID throws 404
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(404);
        $this->intakeService->resolveSubject('NON_EXISTENT_MIS_ID_9999');
    }

    public function testSubjectResolverRejectsEmptySearch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(404);
        $this->intakeService->resolveSubject('   ');
    }

    public function testSubjectResolverRejectsWildcardSearch(): void
    {
        // Wildcard / directory browsing characters (%) must be strictly rejected
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(404);
        $this->intakeService->resolveSubject('%');
    }

    public function testSubjectResolverRejectsAsteriskSearch(): void
    {
        // Wildcard (*) must be rejected to prevent directory harvesting
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(404);
        $this->intakeService->resolveSubject('STU*');
    }

    /**
     * Verify concern catalog version and checklist retrieval.
     */
    public function testConcernCatalogVersionAndChecklistRetrieval(): void
    {
        $catalog = $this->intakeService->getConcernCatalog();
        $this->assertSame('1.0.0', $catalog['version']);
        $this->assertSame('F-OGC-GRE-GGR-005-00', $catalog['form_code']);
        $this->assertNotEmpty($catalog['checklist']);
        $this->assertArrayHasKey('academic_deficiency', $catalog['checklist']);
        $this->assertArrayHasKey('emotional_distress', $catalog['checklist']);
        $this->assertArrayHasKey('behavioral_misconduct', $catalog['checklist']);
        $this->assertArrayHasKey('substance_use_suspicion', $catalog['checklist']);

        // Verify checklist helper
        $checklist = $this->intakeService->getChecklist();
        $this->assertSame($catalog['checklist'], $checklist);

        // Verify getActiveCatalog helper
        $activeCatalog = $this->intakeService->getActiveCatalog();
        $this->assertSame($catalog['version'], $activeCatalog['version']);
    }

    /**
     * Verify state machine transitions: draft -> submitted -> received -> triaged -> accepted -> case_linked -> closed.
     */
    public function testStateMachineTransitionsFromDraftToClosed(): void
    {
        $referrerActor = $this->signInUser($this->referrerUserId);

        // 1. Draft
        $selections = ['academic_deficiency', 'emotional_distress'];
        $narrative = 'Student has had unexcused absences and appears anxious in class.';
        $priorActions = 'Sent email notification to student and scheduled initial teacher conference.';

        $draft = $this->intakeService->createDraft(
            subjectPersonId: $this->studentPersonId,
            sourceType: 'employee_teacher',
            selections: $selections,
            narrative: $narrative,
            priorActions: $priorActions,
            priorityHint: 'routine',
            actor: $referrerActor
        );
        $this->assertSame('draft', $draft['status']);
        $this->assertSame('routine', $draft['priority_hint']);
        $referralId = (int) $draft['id'];

        // 2. Submitted
        $submitted = $this->intakeService->submitReferral($referralId, $referrerActor);
        $this->assertSame('submitted', $submitted['status']);
        $this->assertNotNull($submitted['submitted_at']);

        // 3. Received
        $counsellorActor = $this->signInUser($this->counsellorUserId);
        $received = $this->intakeService->receiveReferral($referralId, $counsellorActor);
        $this->assertSame('received', $received['status']);
        $this->assertNotNull($received['received_at']);

        // 4. Triaged (Enforces priority hint is attention hint)
        $triaged = $this->intakeService->triageReferral(
            referralId: $referralId,
            priorityHint: 'urgent',
            triageNotes: 'Needs prompt follow-up due to emotional distress symptoms.',
            actor: $counsellorActor
        );
        $this->assertSame('triaged', $triaged['status']);
        $this->assertSame('urgent', $triaged['priority_hint']);
        $this->assertNotNull($triaged['triaged_at']);

        // 5. Accepted
        $accepted = $this->intakeService->acceptReferral(
            referralId: $referralId,
            assignedCounsellorUserId: $this->counsellorUserId,
            actor: $counsellorActor
        );
        $this->assertSame('accepted', $accepted['status']);
        $this->assertSame($this->counsellorUserId, (int) $accepted['assigned_counsellor_user_id']);
        $this->assertNotNull($accepted['responded_at']);

        // 6. Case Linked
        $linked = $this->intakeService->linkToCase($referralId, null, $counsellorActor);
        $this->assertSame('case_linked', $linked['status']);
        $this->assertNotNull($linked['linked_case_id']);
        $this->assertGreaterThan(0, (int) $linked['linked_case_id']);

        // 7. Closed
        $closed = $this->intakeService->closeReferral($referralId, 'Intake intake case initiated and onboarding finished.', $counsellorActor);
        $this->assertSame('closed', $closed['status']);
        $this->assertNotNull($closed['closed_at']);
    }

    /**
     * Verify withdrawal branch (permitted only before triage by referrer).
     */
    public function testWithdrawalPermittedOnlyBeforeTriageByOriginalReferrer(): void
    {
        $referrerActor = $this->signInUser($this->referrerUserId);

        $draft = $this->intakeService->createDraft(
            subjectPersonId: $this->studentPersonId,
            sourceType: 'employee_teacher',
            selections: ['behavioral_misconduct'],
            narrative: 'Referral drafted in error.',
            priorActions: null,
            priorityHint: 'routine',
            actor: $referrerActor
        );
        $referralId = (int) $draft['id'];

        // Submit referral
        $this->intakeService->submitReferral($referralId, $referrerActor);

        // 1. Attempt withdrawal by a different referrer -> throws 403
        $otherActor = $this->signInUser($this->otherReferrerUserId);
        try {
            $this->intakeService->withdrawReferral($referralId, $otherActor);
            $this->fail('Withdrawal by different user must throw DomainException (403).');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // 2. Withdrawal by original referrer prior to triage -> SUCCEEDS
        $withdrawn = $this->intakeService->withdrawReferral($referralId, $referrerActor);
        $this->assertSame('withdrawn', $withdrawn['status']);
        $this->assertNotNull($withdrawn['closed_at']);

        // 3. Create second referral, advance past triage, attempt withdrawal -> throws 403
        $draft2 = $this->intakeService->createDraft(
            subjectPersonId: $this->studentPersonId,
            sourceType: 'employee_teacher',
            selections: ['academic_deficiency'],
            narrative: 'Legitimate referral.',
            priorActions: null,
            priorityHint: 'routine',
            actor: $referrerActor
        );
        $referralId2 = (int) $draft2['id'];
        $this->intakeService->submitReferral($referralId2, $referrerActor);

        $counsellorActor = $this->signInUser($this->counsellorUserId);
        $this->intakeService->receiveReferral($referralId2, $counsellorActor);
        $this->intakeService->triageReferral($referralId2, 'routine', 'Triaged routine', $counsellorActor);

        // Original referrer tries to withdraw post-triage -> throws 403
        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);
        $this->intakeService->withdrawReferral($referralId2, $referrerActor);
    }

    /**
     * Verify decline branch with mandatory reason.
     */
    public function testDeclineBranchWithMandatoryReason(): void
    {
        $referrerActor = $this->signInUser($this->referrerUserId);
        $counsellorActor = $this->signInUser($this->counsellorUserId);

        $draft = $this->intakeService->createDraft(
            subjectPersonId: $this->studentPersonId,
            sourceType: 'employee_teacher',
            selections: ['career_indecision'],
            narrative: 'Student wants assistance with college major choices.',
            priorActions: null,
            priorityHint: 'routine',
            actor: $referrerActor
        );
        $referralId = (int) $draft['id'];
        $this->intakeService->submitReferral($referralId, $referrerActor);
        $this->intakeService->receiveReferral($referralId, $counsellorActor);

        // 1. Declining with blank reason -> throws InvalidArgumentException (422)
        try {
            $this->intakeService->declineReferral($referralId, '   ', $counsellorActor);
            $this->fail('Decline without reason must throw InvalidArgumentException (422).');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(422, $e->getCode());
        }

        // 2. Declining with valid reason -> sets status = 'declined' and decline_reason
        $declined = $this->intakeService->declineReferral(
            $referralId,
            'Already being served under Career Placement Office module.',
            $counsellorActor
        );
        $this->assertSame('declined', $declined['status']);
        $this->assertSame('Already being served under Career Placement Office module.', $declined['decline_reason']);
        $this->assertNotNull($declined['closed_at']);
        $this->assertNotNull($declined['responded_at']);
    }

    /**
     * Verify external handoff branch.
     */
    public function testExternalHandoffBranch(): void
    {
        $referrerActor = $this->signInUser($this->referrerUserId);
        $counsellorActor = $this->signInUser($this->counsellorUserId);

        $draft = $this->intakeService->createDraft(
            subjectPersonId: $this->studentPersonId,
            sourceType: 'employee_teacher',
            selections: ['emotional_distress'],
            narrative: 'Requires specialized psychiatric facility intervention.',
            priorActions: null,
            priorityHint: 'urgent',
            actor: $referrerActor
        );
        $referralId = (int) $draft['id'];
        $this->intakeService->submitReferral($referralId, $referrerActor);
        $this->intakeService->receiveReferral($referralId, $counsellorActor);

        // 1. Missing external facility name -> throws 422
        try {
            $this->intakeService->externalHandoff($referralId, '  ', 'Handed over', $counsellorActor);
            $this->fail('Handoff without facility name must throw InvalidArgumentException (422).');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(422, $e->getCode());
        }

        // 2. Successful handoff
        $handoff = $this->intakeService->externalHandoff(
            referralId: $referralId,
            facilityName: 'National Center for Mental Health (NCMH)',
            notes: 'Coordinated with admitting physician Dr. Perez; student escorted by guardian.',
            actor: $counsellorActor
        );

        $this->assertSame('external_handoff', $handoff['status']);
        $this->assertSame('National Center for Mental Health (NCMH)', $handoff['external_facility']);
        $this->assertNotNull($handoff['closed_at']);
        $this->assertStringContainsString('NCMH', $handoff['triage_notes']);
    }

    /**
     * Verify encrypted storage of selections, narrative, and prior actions
     * (raw database rows contain ciphertext, not plaintext).
     */
    public function testEncryptedStorageInRawDatabaseRows(): void
    {
        $referrerActor = $this->signInUser($this->referrerUserId);

        $secretNarrative = 'Highly sensitive narrative: Student experiencing severe panic attacks during math exams.';
        $secretPriorActions = 'Confidential prior action: Advised parents and scheduled quiet exam room accommodation.';
        $selections = ['emotional_distress', 'academic_deficiency'];

        $draft = $this->intakeService->createDraft(
            subjectPersonId: $this->studentPersonId,
            sourceType: 'employee_teacher',
            selections: $selections,
            narrative: $secretNarrative,
            priorActions: $secretPriorActions,
            priorityHint: 'urgent',
            actor: $referrerActor
        );
        $referralId = (int) $draft['id'];

        // Inspect raw database row directly using DB connection
        $rawRow = $this->db->table('guidance_intake_referrals')
            ->where('id', $referralId)
            ->get()
            ->getRowArray();

        $this->assertNotNull($rawRow, 'Raw DB row must exist.');

        // 1. Plaintext strings must NEVER exist anywhere in raw DB columns
        $this->assertStringNotContainsString($secretNarrative, (string) $rawRow['narrative_cipher']);
        $this->assertStringNotContainsString($secretPriorActions, (string) $rawRow['prior_actions_cipher']);
        $this->assertStringNotContainsString('emotional_distress', (string) $rawRow['selections_cipher']);

        // 2. Cipher columns must contain non-empty encrypted data
        $this->assertNotEmpty($rawRow['narrative_cipher']);
        $this->assertNotEmpty($rawRow['narrative_nonce']);
        $this->assertNotEmpty($rawRow['narrative_tag']);
        $this->assertGreaterThan(0, (int) $rawRow['narrative_key_version']);

        $this->assertNotEmpty($rawRow['selections_cipher']);
        $this->assertNotEmpty($rawRow['selections_nonce']);
        $this->assertNotEmpty($rawRow['selections_tag']);
        $this->assertGreaterThan(0, (int) $rawRow['selections_key_version']);

        $this->assertNotEmpty($rawRow['prior_actions_cipher']);
        $this->assertNotEmpty($rawRow['prior_actions_nonce']);
        $this->assertNotEmpty($rawRow['prior_actions_tag']);
        $this->assertGreaterThan(0, (int) $rawRow['prior_actions_key_version']);

        // 3. Service retrieval transparently decrypts payloads
        $decrypted = $this->intakeService->getReferral($referralId);
        $this->assertSame($secretNarrative, $decrypted['narrative']);
        $this->assertSame($secretPriorActions, $decrypted['prior_actions']);
        $this->assertSame($selections, $decrypted['selections']);
    }

    /**
     * Verify Clinic referral bridge imports into Guidance intake without altering clinic data.
     */
    public function testClinicReferralBridgeImportsWithoutAlteringClinicDomain(): void
    {
        // 1. Create clinic referral in existing `referrals` table
        $clinicRefId = (int) $this->db->table('referrals')->insert([
            'tenant_id'          => 1,
            'patient_person_id'  => $this->studentPersonId,
            'issuer_user_id'     => $this->referrerUserId,
            'referral_code'      => 'REF-CLINIC-TEST-001',
            'source_module'      => 'clinic',
            'target_module'      => 'guidance',
            'priority'           => 'urgent',
            'reason_code'        => 'health_concern',
            'reason_description' => 'Student fainted during physical exam; reports sleep deprivation and severe stress.',
            'status'             => 'submitted',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $counsellorActor = $this->signInUser($this->counsellorUserId);

        // 2. Bridge into Guidance Intake
        $bridged = $this->intakeService->bridgeClinicReferral($clinicRefId, $counsellorActor);

        $this->assertSame($clinicRefId, (int) $bridged['clinic_referral_id']);
        $this->assertSame($this->studentPersonId, (int) $bridged['subject_person_id']);
        $this->assertContains($bridged['source_type'], ['clinic_link', 'clinic_bridge']);
        $this->assertSame('submitted', $bridged['status']);
        $this->assertSame('urgent', $bridged['priority_hint']);
        $this->assertStringContainsString('fainted', $bridged['narrative']);

        // 3. Verify original clinic referral in `referrals` table was NOT altered
        $clinicRow = $this->db->table('referrals')
            ->where('id', $clinicRefId)
            ->get()
            ->getRowArray();

        $this->assertSame('submitted', $clinicRow['status']);
        $this->assertSame('clinic', $clinicRow['source_module']);
        $this->assertSame('guidance', $clinicRow['target_module']);
        $this->assertSame('REF-CLINIC-TEST-001', $clinicRow['referral_code']);

        // 4. Repeated bridge call is idempotent and returns the same intake record
        $bridgedSecondTime = $this->intakeService->bridgeClinicReferral($clinicRefId, $counsellorActor);
        $this->assertSame((int) $bridged['id'], (int) $bridgedSecondTime['id']);
    }
}

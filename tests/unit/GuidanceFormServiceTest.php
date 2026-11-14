<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\GuidanceFormDefinitionModel;
use App\Models\GuidanceFormDefinitionRevisionModel;
use App\Models\GuidanceFormDraftModel;
use App\Models\GuidanceFormInstanceModel;
use App\Models\GuidanceFormRevisionModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\GuidanceFormCipher;
use App\Services\CurrentTenant;
use App\Services\Guidance\FormAttestationService;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use App\Services\Guidance\GuidanceFormService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceFormServiceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidanceFormService $formService;
    private GuidanceFormDefinitionService $definitionService;
    private GuidanceDocumentService $documentService;
    private GuidanceFormCipher $cipher;
    private PersonModel $personModel;
    private UserModel $userModel;

    private int $counsellorUserId;
    private int $studentPersonId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        $this->personModel = new PersonModel();
        $this->userModel = new UserModel();

        $keyHex = str_repeat('ab', 32);
        putenv('COUNSELLING_KEY=' . $keyHex);
        putenv('COUNSELLING_KEY_VERSION=1');
        $_ENV['COUNSELLING_KEY'] = $keyHex;
        $_ENV['COUNSELLING_KEY_VERSION'] = '1';

        $this->cipher = new GuidanceFormCipher($keyHex, 1);
        $this->definitionService = new GuidanceFormDefinitionService();
        $this->documentService = new GuidanceDocumentService(db: $this->db);
        $this->formService = new GuidanceFormService(
            cipher: $this->cipher,
            definitionService: $this->definitionService,
            documentService: $this->documentService,
            db: $this->db
        );

        $unique = bin2hex(random_bytes(4));
        $empPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_FORM_' . $unique,
            'first_name' => 'Guidance',
            'last_name'  => 'Counselor',
        ]);
        $this->counsellorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $empPersonId,
            'username'  => 'counsellor_form_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'STU_FORM_' . $unique,
            'first_name' => 'Juana',
            'last_name'  => 'Dela Cruz',
        ]);

        $this->signInUser($this->counsellorUserId, 1);
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

    public function testManifestSyncAndCatalogPopulation(): void
    {
        $syncResult = $this->definitionService->syncManifestToDatabase(1);
        $this->assertGreaterThanOrEqual(8, $syncResult['definitions']);
        $this->assertGreaterThanOrEqual(8, $syncResult['revisions']);

        // Verify General Referral definition
        $genRef = $this->definitionService->getActiveDefinition('guidance_general_referral', 1);
        $this->assertSame('F-OGC-GRE-GGR-005-00', $genRef['definition']['form_code']);
        $this->assertSame('Guidance General Referral Form', $genRef['definition']['canonical_title']);
        $this->assertSame('00', $genRef['revision']['revision_number']);
        $this->assertNotEmpty($genRef['revision']['schema_hash']);

        // Verify Call Slip definition
        $callSlip = $this->definitionService->getActiveDefinition('guidance_call_slip', 1);
        $this->assertSame('F-OGC-GSP-CSP-007-00', $callSlip['definition']['form_code']);
        $this->assertSame('Guidance Call Slip', $callSlip['definition']['canonical_title']);
    }

    public function testFormInstanceLifecycleWithDraftAndFinalization(): void
    {
        // 1. Sync catalog first
        $this->definitionService->syncManifestToDatabase(1);

        // 2. Create Instance
        $actor = (new AuthenticatedUser())->current();
        $instance = $this->formService->createInstance(
            $this->studentPersonId,
            'guidance_general_referral',
            actor: $actor
        );

        $this->assertNotNull($instance);
        $instanceId = (int) $instance['id'];
        $this->assertSame('draft', $instance['status']);
        $this->assertStringStartsWith('OGC-' . date('Y') . '-', $instance['instance_number']);

        // 3. Save Draft (Valid data)
        $formData = [
            'student_name' => 'Juana Dela Cruz',
            'student_id' => 'STU-10023',
            'course_year' => 'BS Psychology 3rd Year',
            'referring_name' => 'Prof. Santos',
            'referring_designation' => 'Faculty Instructor',
            'referring_department' => 'College of Arts and Sciences',
            'referral_date' => date('Y-m-d'),
            'reasons' => ['academic_deficiency', 'emotional_distress'],
            'reasons_narrative' => 'Student has missed major exams and appeared withdrawn in class.',
            'prior_actions_taken' => 'Instructor spoke with student after class and suggested guidance consultation.',
            'priority_hint' => 'routine',
        ];

        $saveResult = $this->formService->saveDraft($instanceId, $formData, $actor);
        $this->assertSame('draft_saved', $saveResult['status']);

        // 4. Retrieve and verify draft decryption
        $readDraft = $this->formService->getDraft($instanceId, $actor);
        $this->assertSame($formData['student_name'], $readDraft['data']['student_name']);
        $this->assertSame($formData['reasons_narrative'], $readDraft['data']['reasons_narrative']);
        $this->assertSame($formData['reasons'], $readDraft['data']['reasons']);

        // 5. Finalize with authenticated account attestation
        $idempotencyKey = bin2hex(random_bytes(16));
        $finalizeResult = $this->formService->finalizeInstance($instanceId, $idempotencyKey, $actor);

        $this->assertSame('finalized', $finalizeResult['status']);
        $this->assertNotEmpty($finalizeResult['snapshot_hash']);
        $this->assertNotNull($finalizeResult['attestation']);
        $this->assertSame('account_attestation', $finalizeResult['attestation']['attestation_method']);
        $this->assertSame($this->counsellorUserId, (int) $finalizeResult['attestation']['signer_user_id']);

        // 6. Draft row must be cleaned up
        $draftModel = new GuidanceFormDraftModel();
        $this->assertNull($draftModel->where('form_instance_id', $instanceId)->first());

        // 7. Instance status must be finalized with retention due date
        $instanceModel = new GuidanceFormInstanceModel();
        $updated = $instanceModel->find($instanceId);
        $this->assertSame('finalized', $updated['status']);
        $this->assertNotNull($updated['retention_due_at']);
        $this->assertNotNull($updated['finalized_at']);

        // 8. Retrieve finalized immutable revision
        $finalized = $this->formService->getFinalizedRevision($instanceId, 1, $actor);
        $this->assertSame($formData['student_name'], $finalized['data']['student_name']);
        $this->assertSame($formData['reasons_narrative'], $finalized['data']['reasons_narrative']);
        $this->assertSame($finalizeResult['snapshot_hash'], $finalized['revision']['snapshot_hash']);
    }

    public function testSchemaValidationRejectsInvalidData(): void
    {
        $this->definitionService->syncManifestToDatabase(1);
        $actor = (new AuthenticatedUser())->current();

        $instance = $this->formService->createInstance(
            $this->studentPersonId,
            'guidance_appointment_form',
            actor: $actor
        );
        $instanceId = (int) $instance['id'];

        // Missing required fields
        $invalidData = [
            'appointment_number' => 'APP-001',
            // student_name, student_id, course_year, scheduled_date, scheduled_time missing!
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->formService->saveDraft($instanceId, $invalidData, $actor);
    }

    public function testDraftPreviewAndFinalDocumentIssuance(): void
    {
        $this->definitionService->syncManifestToDatabase(1);
        $actor = (new AuthenticatedUser())->current();

        $instance = $this->formService->createInstance(
            $this->studentPersonId,
            'guidance_call_slip',
            actor: $actor
        );
        $instanceId = (int) $instance['id'];

        $formData = [
            'call_slip_number' => 'CS-2026-0042',
            'student_name' => 'Juana Dela Cruz',
            'student_id' => 'STU-10023',
            'course_year' => 'BS Psychology 3rd Year',
            'call_date' => date('Y-m-d'),
            'call_time' => '10:00:00',
            'purpose' => 'personal_counseling',
            'issuing_counselor_name' => 'Guidance Counselor',
        ];

        $this->formService->saveDraft($instanceId, $formData, $actor);

        // 1. Generate Draft Preview
        $defBundle = $this->definitionService->getActiveDefinition('guidance_call_slip', 1);
        $preview = $this->documentService->generateDraftPreview(
            $instance,
            $defBundle['definition'],
            $defBundle['revision'],
            $formData
        );

        $this->assertNotEmpty($preview['latex']);
        $this->assertStringContainsString('DRAFT - PREVIEW ONLY', $preview['latex']);
        $this->assertNotEmpty($preview['pdf']);

        // 2. Finalize instance
        $finalized = $this->formService->finalizeInstance($instanceId, bin2hex(random_bytes(16)), $actor);

        // 3. Issue Document
        $issued = $this->documentService->issueDocument(
            $instance,
            $defBundle['definition'],
            $defBundle['revision'],
            $formData,
            $finalized['attestation'],
            $actor
        );

        $this->assertNotNull($issued['issuance']);
        $verificationCode = $issued['issuance']['verification_code'];
        $this->assertStringStartsWith('SYN-DOC-', $verificationCode);
        $this->assertSame('active', $issued['issuance']['status']);

        // 4. Public Non-PII Verification
        $verified = $this->documentService->verifyCode($verificationCode);
        $this->assertNotNull($verified);
        $this->assertSame($verificationCode, $verified['verification_code']);
        $this->assertSame('active', $verified['status']);
        $this->assertNotEmpty($verified['pdf_sha256']);
        // Verify NO personal PII is disclosed
        $this->assertArrayNotHasKey('student_name', $verified);
        $this->assertArrayNotHasKey('narrative', $verified);
        $this->assertArrayNotHasKey('person_id', $verified);
    }
}


<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\CounsellingCaseModel;
use App\Models\GuidanceCaseAssignmentModel;
use App\Models\GuidanceCaseTransferModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceTransferService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceCaseTransferTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidanceTransferService $transferService;
    private GuidanceAccessPolicy $accessPolicy;
    private CounsellingCaseModel $caseModel;
    private GuidanceCaseTransferModel $transferModel;
    private GuidanceCaseAssignmentModel $assignmentModel;
    private PersonModel $personModel;
    private UserModel $userModel;

    private int $counsellorAUserId;
    private int $counsellorBUserId;
    private int $counsellorCUserId;
    private int $supervisorUserId;
    private int $studentPersonId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        $this->caseModel = new CounsellingCaseModel();
        $this->transferModel = new GuidanceCaseTransferModel();
        $this->assignmentModel = new GuidanceCaseAssignmentModel();
        $this->personModel = new PersonModel();
        $this->userModel = new UserModel();

        $this->accessPolicy = new GuidanceAccessPolicy(db: $this->db);
        $this->transferService = new GuidanceTransferService(
            $this->caseModel,
            $this->transferModel,
            $this->assignmentModel,
            $this->userModel,
            $this->accessPolicy,
            $this->db
        );

        $unique = bin2hex(random_bytes(4));

        // Counselor A
        $empAPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_TA_' . $unique,
            'first_name' => 'Alice',
            'last_name'  => 'Counselor',
        ]);
        $this->counsellorAUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $empAPersonId,
            'username'  => 'counsellor_a_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        // Counselor B
        $empBPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_TB_' . $unique,
            'first_name' => 'Bob',
            'last_name'  => 'Counselor',
        ]);
        $this->counsellorBUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $empBPersonId,
            'username'  => 'counsellor_b_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        // Counselor C
        $empCPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_TC_' . $unique,
            'first_name' => 'Charlie',
            'last_name'  => 'Counselor',
        ]);
        $this->counsellorCUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $empCPersonId,
            'username'  => 'counsellor_c_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        // Guidance Supervisor
        $supPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_TSUP_' . $unique,
            'first_name' => 'Sarah',
            'last_name'  => 'Supervisor',
        ]);
        $this->supervisorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $supPersonId,
            'username'  => 'supervisor_t_' . $unique,
            'role'      => 'guidance_supervisor',
            'status'    => 'active',
        ]);

        // Student
        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'STU_T_' . $unique,
            'first_name' => 'Danilo',
            'last_name'  => 'Ramos',
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

    private function createSampleCase(int $counsellorUserId, string $title = 'Sample Case'): int
    {
        $now = date('Y-m-d H:i:s');
        $caseId = (int) $this->caseModel->insert([
            'tenant_id' => 1,
            'person_id' => $this->studentPersonId,
            'counsellor_user_id' => $counsellorUserId,
            'case_number' => 'GC-CASE-' . bin2hex(random_bytes(3)),
            'title' => $title,
            'category' => 'personal',
            'status' => 'open',
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Record initial assignment
        $this->assignmentModel->insert([
            'tenant_id' => 1,
            'case_id' => $caseId,
            'counsellor_user_id' => $counsellorUserId,
            'assigned_by_user_id' => $counsellorUserId,
            'assignment_type' => 'initial',
            'reason' => 'Initial case creation',
            'assigned_at' => $now,
            'relinquished_at' => null,
            'created_at' => $now,
        ]);

        return $caseId;
    }

    public function testConsultationRequestVsFullTransferProposal(): void
    {
        $actorA = $this->signInUser($this->counsellorAUserId);
        $caseId = $this->createSampleCase($this->counsellorAUserId, 'Consultation Case');

        // 1. Propose Consultation
        $consultation = $this->transferService->requestTransfer(
            $caseId,
            $this->counsellorBUserId,
            'consultation',
            'Requesting clinical consultation on career pathing',
            $actorA
        );
        $this->assertSame('consultation', $consultation['transfer_type']);
        $this->assertSame('pending', $consultation['status']);
        $this->assertSame($this->counsellorAUserId, (int) $consultation['from_counsellor_user_id']);
        $this->assertSame($this->counsellorBUserId, (int) $consultation['to_counsellor_user_id']);

        // Receiver Counselor B accepts consultation
        $actorB = $this->signInUser($this->counsellorBUserId);
        $respondedConsultation = $this->transferService->respondTransfer(
            (int) $consultation['id'],
            true,
            'Consultation accepted, will review notes',
            $actorB
        );
        $this->assertSame('accepted', $respondedConsultation['status']);

        // Case owner must REMAIN Counselor A for consultations
        $case = $this->caseModel->find($caseId);
        $this->assertSame($this->counsellorAUserId, (int) $case['counsellor_user_id']);

        // Assignment history must still only have the initial assignment
        $history = $this->transferService->getAssignmentHistory($caseId);
        $this->assertCount(1, $history);
        $this->assertSame('initial', $history[0]['assignment_type']);

        // 2. Propose Full Transfer
        $this->signInUser($this->counsellorAUserId);
        $fullTransfer = $this->transferService->requestTransfer(
            $caseId,
            $this->counsellorBUserId,
            'full_transfer',
            'Student requested female or specialized counselor',
            $actorA
        );
        $this->assertSame('full_transfer', $fullTransfer['transfer_type']);
        $this->assertSame('pending', $fullTransfer['status']);

        // 3. Validation: Invalid type throws 422
        try {
            $this->transferService->requestTransfer($caseId, $this->counsellorBUserId, 'invalid_transfer', 'Reason', $actorA);
            $this->fail('Invalid transfer type must throw InvalidArgumentException (422)');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(422, $e->getCode());
        }

        // 4. Validation: Empty reason throws 422
        try {
            $this->transferService->requestTransfer($caseId, $this->counsellorBUserId, 'full_transfer', '   ', $actorA);
            $this->fail('Empty transfer reason must throw InvalidArgumentException (422)');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(422, $e->getCode());
        }

        // 5. Validation: Transfer to self throws 422
        try {
            $this->transferService->requestTransfer($caseId, $this->counsellorAUserId, 'full_transfer', 'Self transfer', $actorA);
            $this->fail('Self transfer must throw InvalidArgumentException (422)');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(422, $e->getCode());
        }

        // 6. Validation: Non-owner attempting transfer throws 403
        try {
            $this->transferService->requestTransfer($caseId, $this->counsellorAUserId, 'full_transfer', 'Hijack attempt', $actorB);
            $this->fail('Non-assigned counselor must throw DomainException (403)');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    public function testReceiverAcceptanceUpdatesCaseOwnerAndRecordsAssignmentHistory(): void
    {
        $actorA = $this->signInUser($this->counsellorAUserId);
        $caseId = $this->createSampleCase($this->counsellorAUserId, 'Full Transfer Case');

        // Counselor A initiates full transfer
        $transfer = $this->transferService->requestTransfer(
            $caseId,
            $this->counsellorBUserId,
            'full_transfer',
            'Transferring case due to schedule conflict',
            $actorA
        );

        // Counselor B accepts
        $actorB = $this->signInUser($this->counsellorBUserId);
        $response = $this->transferService->respondTransfer(
            (int) $transfer['id'],
            true,
            'Accepted and schedule confirmed',
            $actorB
        );

        $this->assertSame('accepted', $response['status']);

        // Case owner is now Counselor B
        $updatedCase = $this->caseModel->find($caseId);
        $this->assertSame($this->counsellorBUserId, (int) $updatedCase['counsellor_user_id']);

        // Check assignment history: exactly two chronological assignments
        $history = $this->transferService->getAssignmentHistory($caseId);
        $this->assertCount(2, $history);

        // 1st assignment: initial, now closed (relinquished_at populated)
        $this->assertSame('initial', $history[0]['assignment_type']);
        $this->assertSame($this->counsellorAUserId, (int) $history[0]['counsellor_user_id']);
        $this->assertNotNull($history[0]['relinquished_at']);

        // 2nd assignment: transfer_accepted, active (relinquished_at null)
        $this->assertSame('transfer_accepted', $history[1]['assignment_type']);
        $this->assertSame($this->counsellorBUserId, (int) $history[1]['counsellor_user_id']);
        $this->assertSame($this->counsellorAUserId, (int) $history[1]['assigned_by_user_id']);
        $this->assertNull($history[1]['relinquished_at']);
    }

    public function testReceiverDeclineKeepsOriginalAssignedCounselor(): void
    {
        $actorA = $this->signInUser($this->counsellorAUserId);
        $caseId = $this->createSampleCase($this->counsellorAUserId, 'Declined Transfer Case');

        $transfer = $this->transferService->requestTransfer(
            $caseId,
            $this->counsellorBUserId,
            'full_transfer',
            'Proposed reassignment',
            $actorA
        );

        // Counselor C (not target) cannot respond
        $actorC = $this->signInUser($this->counsellorCUserId);
        try {
            $this->transferService->respondTransfer((int) $transfer['id'], true, 'Intercept', $actorC);
            $this->fail('Non-target counselor responding must throw DomainException (403)');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // Counselor B declines
        $actorB = $this->signInUser($this->counsellorBUserId);
        $declined = $this->transferService->respondTransfer(
            (int) $transfer['id'],
            false,
            'Caseload currently at capacity',
            $actorB
        );

        $this->assertSame('declined', $declined['status']);
        $this->assertSame('Caseload currently at capacity', $declined['response_notes']);

        // Case owner remains Counselor A
        $case = $this->caseModel->find($caseId);
        $this->assertSame($this->counsellorAUserId, (int) $case['counsellor_user_id']);

        // Prior assignment remains active
        $history = $this->transferService->getAssignmentHistory($caseId);
        $this->assertCount(1, $history);
        $this->assertNull($history[0]['relinquished_at']);
    }

    public function testManagerForcedReassignmentRequiresPermissionAndExplicitReason(): void
    {
        $actorA = $this->signInUser($this->counsellorAUserId);
        $caseId = $this->createSampleCase($this->counsellorAUserId, 'Manager Reassignment Case');

        // 1. Regular counselor (lacking guidance.cases.reassign) cannot reassign
        try {
            $this->transferService->reassignCase($caseId, $this->counsellorBUserId, 'Unauthorized reassignment', $actorA);
            $this->fail('Counselor without guidance.cases.reassign must throw DomainException (403)');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // 2. Supervisor with empty reason throws 422
        $supervisorActor = $this->signInUser($this->supervisorUserId);
        try {
            $this->transferService->reassignCase($caseId, $this->counsellorBUserId, '   ', $supervisorActor);
            $this->fail('Empty reassignment reason must throw InvalidArgumentException (422)');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(422, $e->getCode());
        }

        // 3. Supervisor with permission executes manager reassignment
        $result = $this->transferService->reassignCase(
            $caseId,
            $this->counsellorBUserId,
            'Urgent caseload redistribution by supervisor order',
            $supervisorActor
        );

        $this->assertSame($this->counsellorBUserId, (int) $result['counsellor_user_id']);
        $this->assertSame('manager_reassigned', $result['assignment_type']);

        // Verify assignment history
        $history = $this->transferService->getAssignmentHistory($caseId);
        $this->assertCount(2, $history);

        // Previous assignment closed
        $this->assertSame('initial', $history[0]['assignment_type']);
        $this->assertNotNull($history[0]['relinquished_at']);

        // Manager reassignment active
        $this->assertSame('manager_reassigned', $history[1]['assignment_type']);
        $this->assertSame($this->counsellorBUserId, (int) $history[1]['counsellor_user_id']);
        $this->assertSame($this->supervisorUserId, (int) $history[1]['assigned_by_user_id']);
        $this->assertSame('Urgent caseload redistribution by supervisor order', $history[1]['reason']);
        $this->assertNull($history[1]['relinquished_at']);
    }

    public function testLegacyImportBackfillCreatesExactlyOneAssignmentWithoutInventingFakeTransfers(): void
    {
        $now = date('Y-m-d H:i:s');

        // Baseline any unassigned cases created by prior test suites in the shared test DB
        $this->transferService->backfillLegacyAssignments(1);

        // 1. Case without any assignment rows
        $legacyCaseId1 = (int) $this->caseModel->insert([
            'tenant_id' => 1,
            'person_id' => $this->studentPersonId,
            'counsellor_user_id' => $this->counsellorAUserId,
            'case_number' => 'GC-LEGACY-01',
            'title' => 'Legacy Unassigned Case 1',
            'category' => 'academic',
            'status' => 'open',
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 2. Second case without any assignment rows
        $legacyCaseId2 = (int) $this->caseModel->insert([
            'tenant_id' => 1,
            'person_id' => $this->studentPersonId,
            'counsellor_user_id' => $this->counsellorBUserId,
            'case_number' => 'GC-LEGACY-02',
            'title' => 'Legacy Unassigned Case 2',
            'category' => 'career',
            'status' => 'open',
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 3. Third case which already has an assignment row
        $caseWithAssignmentId = $this->createSampleCase($this->counsellorCUserId, 'Case With Existing Assignment');

        // Run legacy backfill
        $backfilledCount = $this->transferService->backfillLegacyAssignments(1);
        $this->assertSame(2, $backfilledCount);

        // Verify Case 1 has exactly 1 assignment of type legacy_import
        $history1 = $this->transferService->getAssignmentHistory($legacyCaseId1);
        $this->assertCount(1, $history1);
        $this->assertSame('legacy_import', $history1[0]['assignment_type']);
        $this->assertSame($this->counsellorAUserId, (int) $history1[0]['counsellor_user_id']);
        $this->assertSame($this->counsellorAUserId, (int) $history1[0]['assigned_by_user_id']);
        $this->assertNull($history1[0]['relinquished_at']);

        // Verify Case 2 has exactly 1 assignment of type legacy_import
        $history2 = $this->transferService->getAssignmentHistory($legacyCaseId2);
        $this->assertCount(1, $history2);
        $this->assertSame('legacy_import', $history2[0]['assignment_type']);
        $this->assertSame($this->counsellorBUserId, (int) $history2[0]['counsellor_user_id']);
        $this->assertNull($history2[0]['relinquished_at']);

        // Verify Case 3 was not modified (still 1 initial assignment)
        $history3 = $this->transferService->getAssignmentHistory($caseWithAssignmentId);
        $this->assertCount(1, $history3);
        $this->assertSame('initial', $history3[0]['assignment_type']);

        // Idempotency check: Running backfill again produces 0 new assignments
        $secondRunCount = $this->transferService->backfillLegacyAssignments(1);
        $this->assertSame(0, $secondRunCount);

        $this->assertCount(1, $this->transferService->getAssignmentHistory($legacyCaseId1));
        $this->assertCount(1, $this->transferService->getAssignmentHistory($legacyCaseId2));
    }
}

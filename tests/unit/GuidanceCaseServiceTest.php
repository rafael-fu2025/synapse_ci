<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CounsellingCaseModel;
use App\Models\CounsellingNoteModel;
use App\Models\CounsellingSessionModel;
use App\Models\GuidanceFollowupModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceCaseService;
use Tests\Support\FreshDatabaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class GuidanceCaseServiceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidanceCaseService $caseService;
    private CounsellingCaseModel $caseModel;
    private CounsellingSessionModel $sessionModel;
    private CounsellingNoteModel $noteModel;
    private GuidanceFollowupModel $followupModel;
    private PersonModel $personModel;
    private int $studentPersonId;
    private int $counsellorUserId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        $this->caseModel = new CounsellingCaseModel();
        $this->sessionModel = new CounsellingSessionModel();
        $this->noteModel = new CounsellingNoteModel();
        $this->followupModel = new GuidanceFollowupModel();
        $this->personModel = new PersonModel();

        $this->caseService = new GuidanceCaseService(
            $this->caseModel,
            $this->sessionModel,
            $this->noteModel,
            $this->followupModel,
            $this->personModel,
            encryptionService: new \App\Services\Crypto\EncryptionService(str_repeat('ab', 32))
        );

        $unique = uniqid();
        $counsellorPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'GC_EMP_' . $unique,
            'first_name' => 'Synthetic',
            'last_name'  => 'Counsellor',
        ]);
        $userModel = new UserModel();
        $this->counsellorUserId = (int) $userModel->insert([
            'tenant_id' => 1,
            'person_id' => $counsellorPersonId,
            'username'  => 'counsellor_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'GC_STU_' . $unique,
            'first_name' => 'Carla',
            'last_name'  => 'Mendoza',
        ]);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        parent::tearDown();
    }

    private function signInCounsellor(): void
    {
        // Fresh migrations do not seed counsellor grants; install only these contracts' grants.
        $permissions = [
            'guidance.cases.read' => 'View assigned counseling cases',
            'guidance.cases.write' => 'Write encrypted session notes and plans',
        ];
        foreach ($permissions as $code => $name) {
            $permission = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
            if ($permission === null) {
                $this->assertTrue($this->db->table('permissions')->insert([
                    'code' => $code, 'name' => $name, 'module' => 'guidance',
                ]));
                $permission = ['id' => $this->db->insertID()];
            }
            $grant = ['role' => 'counsellor', 'permission_id' => $permission['id']];
            if ($this->db->table('role_permissions')->where($grant)->countAllResults() === 0) {
                $this->assertTrue($this->db->table('role_permissions')->insert($grant));
            }
        }

        $authority = new AuthenticatedUser();
        $identity = $authority->forIdentity($this->counsellorUserId, 1);
        $this->assertNotNull($identity);
        $this->assertSame('counsellor', $identity['role']);
        $this->assertNotNull($identity['person_id']);
        $this->assertContains('guidance.cases.read', $identity['permissions']);
        $this->assertContains('guidance.cases.write', $identity['permissions']);
        $now = time();
        session()->set(['user' => $identity, 'tenant_id' => $identity['tenant_id'], '_auth' => [
            'started_at' => $now, 'last_seen_at' => $now,
            'version' => $identity['auth_version'], 'session_id' => session_id(),
        ]]);
        $this->assertSame($identity, $authority->current());
        $this->assertSame($identity, session()->get('user'));
    }

    public function testCreateCaseGeneratesSequentialNumber(): void
    {
        $pkg1 = $this->caseService->createCase($this->studentPersonId, $this->counsellorUserId, 'Test Case 1', 'academic');
        $this->assertNotNull($pkg1['case']);
        $this->assertStringStartsWith('GC-' . \App\Services\Scheduling\SchedulingClock::now()->format('Y') . '-', $pkg1['case']['case_number']);
        $this->assertSame('open', $pkg1['case']['status']);

        $pkg2 = $this->caseService->createCase($this->studentPersonId, $this->counsellorUserId, 'Test Case 2', 'personal');
        $this->assertNotNull($pkg2['case']);
        $this->assertNotSame($pkg1['case']['case_number'], $pkg2['case']['case_number']);
    }

    public function testOpenSessionAdvancesCaseStatus(): void
    {
        $pkg = $this->caseService->createCase($this->studentPersonId, $this->counsellorUserId, 'Academic Stress', 'academic');
        $caseId = (int) $pkg['case']['id'];

        $session = $this->caseService->openSession($caseId, $this->counsellorUserId, 'intake');
        $this->assertSame(1, (int) $session['session_number']);
        $this->assertSame('intake', $session['session_type']);
        $this->assertSame('in_progress', $session['status']);

        $updatedCase = $this->caseModel->find($caseId);
        $this->assertSame('active', $updatedCase['status']);
    }

    public function testEncryptedNotesDraftAndFinalizeLifecycle(): void
    {
        $this->signInCounsellor();
        $pkg = $this->caseService->createCase($this->studentPersonId, $this->counsellorUserId, 'Test Notes Case', 'personal');
        $caseId = (int) $pkg['case']['id'];
        $session = $this->caseService->openSession($caseId, $this->counsellorUserId, 'regular');
        $sessionId = (int) $session['id'];

        // 1. Save Draft
        $draftPlaintext = 'Working draft: Student talked about sleeplessness.';
        $this->caseService->saveDraftNotes($sessionId, $draftPlaintext, $this->counsellorUserId);

        $readDraft = $this->caseService->getDecryptedSessionNote($sessionId);
        $this->assertNotNull($readDraft);
        $this->assertSame($draftPlaintext, $readDraft['text']);
        $this->assertTrue($readDraft['is_draft']);

        // Verify that raw DB stored encrypted bytes, not plaintext!
        $rawDraft = $this->noteModel->getDraftNote($sessionId);
        $this->assertNotSame($draftPlaintext, $rawDraft['notes_cipher']);

        // 2. Finalize Note (Creates version 1)
        $finalPlaintext = 'Final note: Assessment completed. Student agreed on study schedule.';
        $this->caseService->finalizeSessionNotes($sessionId, $finalPlaintext, $this->counsellorUserId);

        $readFinal = $this->caseService->getDecryptedSessionNote($sessionId);
        $this->assertNotNull($readFinal);
        $this->assertSame($finalPlaintext, $readFinal['text']);
        $this->assertSame(1, $readFinal['version']);
        $this->assertFalse($readFinal['is_draft']);

        // Draft must be cleaned up
        $this->assertNull($this->noteModel->getDraftNote($sessionId));
    }

    public function testFollowupActionItems(): void
    {
        $pkg = $this->caseService->createCase($this->studentPersonId, $this->counsellorUserId, 'Follow-up Test', 'academic');
        $caseId = (int) $pkg['case']['id'];

        $followup = $this->caseService->addFollowup($caseId, null, date('Y-m-d', strtotime('+7 days')), 'Review midterm syllabus');
        $this->assertSame('pending', $followup['status']);

        $this->signInCounsellor();
        $completed = $this->caseService->completeFollowup((int) $followup['id']);
        $this->assertSame('completed', $completed['status']);
        $this->assertNotNull($completed['completed_at']);
    }

    public function testFollowupCompletionWithoutActorIsDeniedWithoutChangingState(): void
    {
        $pkg = $this->caseService->createCase($this->studentPersonId, $this->counsellorUserId, 'Unauthenticated Follow-up', 'academic');
        $followup = $this->caseService->addFollowup((int) $pkg['case']['id'], null,
            date('Y-m-d', strtotime('+7 days')), 'Must remain pending');
        AuthenticatedUser::clear();

        try {
            $this->caseService->completeFollowup((int) $followup['id']);
            $this->fail('Follow-up completion must require an authenticated actor.');
        } catch (\RuntimeException $error) {
            $this->assertSame(401, $error->getCode());
            $this->assertSame('Please sign in to continue.', $error->getMessage());
        }
        CurrentTenant::set(1);
        $unchanged = $this->followupModel->scoped()->find((int) $followup['id']);
        $this->assertSame('pending', $unchanged['status']);
        $this->assertNull($unchanged['completed_at']);
    }
}

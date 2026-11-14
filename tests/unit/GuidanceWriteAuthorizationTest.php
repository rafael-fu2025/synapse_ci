<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\CounsellingCaseModel;
use App\Models\CounsellingNoteModel;
use App\Models\CounsellingSessionModel;
use App\Models\GuidanceFollowupModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceCaseService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

/**
 * Verifies Phase 0 Guidance write authorization policies inside MariaDB/MySQL transactions.
 * Tests same-tenant cross-counselor denial, read_any write prohibition, revoked role,
 * inactive user/tenant, forged author rejection, cross-tenant denial, and rollback integrity.
 */
final class GuidanceWriteAuthorizationTest extends FreshDatabaseTestCase
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
    private UserModel $userModel;

    private int $counsellorAUserId;
    private int $counsellorBUserId;
    private int $supervisorUserId;
    private int $tenant2CounsellorUserId;
    private int $studentPersonId;
    private int $tenant2StudentPersonId;

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
        $this->userModel = new UserModel();

        $this->caseService = new GuidanceCaseService(
            $this->caseModel,
            $this->sessionModel,
            $this->noteModel,
            $this->followupModel,
            $this->personModel,
            encryptionService: new EncryptionService(str_repeat('ab', 32))
        );

        // Ensure roles & permissions are seeded
        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        // Ensure tenant 2 exists
        $tenant2 = $this->db->table('tenants')->where('id', 2)->get()->getRowArray();
        if ($tenant2 === null) {
            $now = date('Y-m-d H:i:s');
            $this->db->table('tenants')->insert([
                'id' => 2,
                'slug' => 'tenant-2',
                'name' => 'Synthetic Tenant 2',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $unique = bin2hex(random_bytes(4));

        // Counselor A (Tenant 1)
        $empAPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_A_' . $unique,
            'first_name' => 'Counselor',
            'last_name'  => 'Alpha',
        ]);
        $this->counsellorAUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $empAPersonId,
            'username'  => 'counsellor_a_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        // Counselor B (Tenant 1)
        $empBPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_B_' . $unique,
            'first_name' => 'Counselor',
            'last_name'  => 'Beta',
        ]);
        $this->counsellorBUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $empBPersonId,
            'username'  => 'counsellor_b_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        // Supervisor (Tenant 1 - has guidance.cases.read_any)
        $supPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_SUP_' . $unique,
            'first_name' => 'Supervisor',
            'last_name'  => 'Gamma',
        ]);
        $this->supervisorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $supPersonId,
            'username'  => 'supervisor_' . $unique,
            'role'      => 'guidance_supervisor',
            'status'    => 'active',
        ]);

        // Counselor (Tenant 2)
        $t2EmpPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 2,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_T2_' . $unique,
            'first_name' => 'Counselor',
            'last_name'  => 'Delta',
        ]);
        $this->tenant2CounsellorUserId = (int) $this->userModel->insert([
            'tenant_id' => 2,
            'person_id' => $t2EmpPersonId,
            'username'  => 'counsellor_t2_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        // Student (Tenant 1)
        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'STU_1_' . $unique,
            'first_name' => 'Carla',
            'last_name'  => 'Mendoza',
        ]);

        // Student (Tenant 2)
        $this->tenant2StudentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 2,
            'kind'       => 'student',
            'mis_id'     => 'STU_2_' . $unique,
            'first_name' => 'Marco',
            'last_name'  => 'Cruz',
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

    public function testCrossCounselorSameTenantCannotOpenSessionOrModifyCase(): void
    {
        // Counselor A creates a case
        $this->signInUser($this->counsellorAUserId, 1);
        $pkg = $this->caseService->createCase($this->studentPersonId, $this->counsellorAUserId, 'Counselor A Case', 'personal');
        $caseId = (int) $pkg['case']['id'];
        $this->assertSame($this->counsellorAUserId, (int) $pkg['case']['counsellor_user_id']);

        // Counselor B logs in
        $this->signInUser($this->counsellorBUserId, 1);

        // 1. openSession denial
        try {
            $this->caseService->openSession($caseId, $this->counsellorBUserId, 'regular');
            $this->fail('Counselor B must not open session for Counselor A case');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Case mutation forbidden.', $e->getMessage());
        }

        // Counselor A opens a session
        $this->signInUser($this->counsellorAUserId, 1);
        $session = $this->caseService->openSession($caseId, $this->counsellorAUserId, 'regular');
        $sessionId = (int) $session['id'];

        // Counselor B tries to save draft notes
        $this->signInUser($this->counsellorBUserId, 1);
        try {
            $this->caseService->saveDraftNotes($sessionId, 'Illegal draft notes', $this->counsellorBUserId);
            $this->fail('Counselor B must not save draft notes for Counselor A session');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Case mutation forbidden.', $e->getMessage());
        }

        // Counselor B tries to finalize notes
        try {
            $this->caseService->finalizeSessionNotes($sessionId, 'Illegal final notes', $this->counsellorBUserId);
            $this->fail('Counselor B must not finalize notes for Counselor A session');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Case mutation forbidden.', $e->getMessage());
        }

        // Counselor B tries to close session
        try {
            $this->caseService->closeSession($sessionId);
            $this->fail('Counselor B must not close session for Counselor A case');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Case mutation forbidden.', $e->getMessage());
        }

        // Counselor B tries to add followup
        try {
            $this->caseService->addFollowup($caseId, null, date('Y-m-d', strtotime('+3 days')), 'Illegal follow-up');
            $this->fail('Counselor B must not add follow-up to Counselor A case');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Case mutation forbidden.', $e->getMessage());
        }

        // Counselor A adds a followup
        $this->signInUser($this->counsellorAUserId, 1);
        $followup = $this->caseService->addFollowup($caseId, null, date('Y-m-d', strtotime('+3 days')), 'Legit follow-up');
        $followupId = (int) $followup['id'];

        // Counselor B tries to complete followup
        $this->signInUser($this->counsellorBUserId, 1);
        try {
            $this->caseService->completeFollowup($followupId);
            $this->fail('Counselor B must not complete follow-up on Counselor A case');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Case mutation forbidden.', $e->getMessage());
        }

        // Counselor B tries to mark followup missed
        try {
            $this->caseService->missFollowup($followupId);
            $this->fail('Counselor B must not mark follow-up missed on Counselor A case');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Case mutation forbidden.', $e->getMessage());
        }

        // Counselor B tries to monitor case
        try {
            $this->caseService->monitorCase($caseId);
            $this->fail('Counselor B must not monitor Counselor A case');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Case mutation forbidden.', $e->getMessage());
        }

        // Counselor B tries to close case
        try {
            $this->caseService->closeCase($caseId, 'resolved', true);
            $this->fail('Counselor B must not close Counselor A case');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Case mutation forbidden.', $e->getMessage());
        }
    }

    public function testSupervisorWithReadAnyCannotWriteCase(): void
    {
        // Counselor A creates a case
        $this->signInUser($this->counsellorAUserId, 1);
        $pkg = $this->caseService->createCase($this->studentPersonId, $this->counsellorAUserId, 'Counselor A Case', 'personal');
        $caseId = (int) $pkg['case']['id'];

        // Supervisor logs in (supervisor has guidance.cases.read_any and guidance.cases.write)
        $identity = $this->signInUser($this->supervisorUserId, 1);
        $this->assertContains('guidance.cases.read_any', $identity['permissions']);

        // Read works for supervisor
        $found = $this->caseService->getCaseDetails($caseId);
        $this->assertNotNull($found['case']);

        // Write mutation is strictly denied because supervisor is NOT the assigned counselor
        try {
            $this->caseService->openSession($caseId, $this->supervisorUserId, 'regular');
            $this->fail('Supervisor must not mutate a case assigned to another counselor');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Case mutation forbidden.', $e->getMessage());
        }

        try {
            $this->caseService->closeCase($caseId, 'resolved', true);
            $this->fail('Supervisor must not close a case assigned to another counselor');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Case mutation forbidden.', $e->getMessage());
        }
    }

    public function testForgedAuthorAndCounsellorIdentityIsRejected(): void
    {
        // Counselor A signed in, attempts to create case with counsellorUserId = Counselor B
        $this->signInUser($this->counsellorAUserId, 1);
        try {
            $this->caseService->createCase($this->studentPersonId, $this->counsellorBUserId, 'Forged Case', 'personal');
            $this->fail('Forged counsellorUserId in createCase must be rejected');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Guidance actor impersonation forbidden.', $e->getMessage());
        }

        // Counselor A creates a legitimate case and session
        $pkg = $this->caseService->createCase($this->studentPersonId, $this->counsellorAUserId, 'Legit Case', 'personal');
        $caseId = (int) $pkg['case']['id'];
        $session = $this->caseService->openSession($caseId, $this->counsellorAUserId, 'regular');
        $sessionId = (int) $session['id'];

        // Counselor A tries to save draft notes with author = Counselor B
        try {
            $this->caseService->saveDraftNotes($sessionId, 'Notes by A claiming to be B', $this->counsellorBUserId);
            $this->fail('Forged author in saveDraftNotes must be rejected');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Guidance actor impersonation forbidden.', $e->getMessage());
        }

        // Counselor A tries to finalize notes with author = Counselor B
        try {
            $this->caseService->finalizeSessionNotes($sessionId, 'Final notes by A claiming to be B', $this->counsellorBUserId);
            $this->fail('Forged author in finalizeSessionNotes must be rejected');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Guidance actor impersonation forbidden.', $e->getMessage());
        }
    }

    public function testInactiveUserIsDenied(): void
    {
        $identity = $this->signInUser($this->counsellorAUserId, 1);

        // Suspend Counselor A in database
        $this->userModel->update($this->counsellorAUserId, ['status' => 'suspended']);

        // Without hint: transport session re-check fails (401)
        try {
            $this->caseService->createCase($this->studentPersonId, $this->counsellorAUserId, 'Should Fail', 'personal');
            $this->fail('Inactive user must be denied guidance writes');
        } catch (RuntimeException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertSame('Please sign in to continue.', $e->getMessage());
        }

        // With cached identity hint: DB query inside transaction verifies user is not active (403)
        // Simulate transport returning the cached identity
        $policyWithCachedTransport = new \App\Services\Guidance\GuidanceAccessPolicy(
            identitySource: static fn () => $identity,
            db: $this->caseModel->db
        );
        $this->db->transBegin();
        try {
            $policyWithCachedTransport->current('guidance.cases.create', true);
            $this->fail('Policy re-check inside transaction must reject inactive user even if transport was cached');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('An active same-tenant guidance account is required.', $e->getMessage());
        } finally {
            $this->db->transRollback();
        }
    }

    public function testRevokedRoleIsDenied(): void
    {
        $this->signInUser($this->counsellorAUserId, 1);

        // Change Counselor A role in database to student (student has no guidance write role)
        $this->userModel->update($this->counsellorAUserId, ['role' => 'student']);

        try {
            $this->caseService->createCase($this->studentPersonId, $this->counsellorAUserId, 'Should Fail', 'personal');
            $this->fail('User with revoked guidance role must be denied guidance writes');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('An active same-tenant guidance account is required.', $e->getMessage());
        }
    }

    public function testInactiveTenantIsDenied(): void
    {
        $identity = $this->signInUser($this->counsellorAUserId, 1);

        // Deactivate Tenant 1 in database
        $this->db->table('tenants')->where('id', 1)->update(['is_active' => 0]);

        try {
            // Without hint: transport session re-check fails (401)
            try {
                $this->caseService->createCase($this->studentPersonId, $this->counsellorAUserId, 'Should Fail', 'personal');
                $this->fail('Inactive tenant must be denied guidance writes');
            } catch (RuntimeException $e) {
                $this->assertSame(401, $e->getCode());
                $this->assertSame('Please sign in to continue.', $e->getMessage());
            }

            // With cached transport identity: DB query inside transaction verifies tenant is active (403)
            $policyWithCachedTransport = new \App\Services\Guidance\GuidanceAccessPolicy(
                identitySource: static fn () => $identity,
                db: $this->caseModel->db
            );
            $this->db->transBegin();
            try {
                $policyWithCachedTransport->current('guidance.cases.create', true);
                $this->fail('Policy re-check inside transaction must reject inactive tenant');
            } catch (DomainException $e) {
                $this->assertSame(403, $e->getCode());
                $this->assertSame('An active same-tenant guidance account is required.', $e->getMessage());
            } finally {
                $this->db->transRollback();
            }
        } finally {
            // Restore tenant 1 active status
            $this->db->table('tenants')->where('id', 1)->update(['is_active' => 1]);
        }
    }

    public function testCrossTenantAccessIsDenied(): void
    {
        // Tenant 2 counselor creates a case in Tenant 2
        $this->signInUser($this->tenant2CounsellorUserId, 2);
        $pkg = $this->caseService->createCase($this->tenant2StudentPersonId, $this->tenant2CounsellorUserId, 'Tenant 2 Case', 'personal');
        $t2CaseId = (int) $pkg['case']['id'];

        // Tenant 1 counselor logs in under Tenant 1
        $this->signInUser($this->counsellorAUserId, 1);

        try {
            $this->caseService->openSession($t2CaseId, $this->counsellorAUserId, 'regular');
            $this->fail('Tenant 1 counselor must not mutate Tenant 2 case');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('Counseling case not found.', $e->getMessage());
        }
    }

    public function testTransactionalRollbackVerifiesZeroSideEffectsOnRejection(): void
    {
        $this->signInUser($this->counsellorAUserId, 1);
        $pkg = $this->caseService->createCase($this->studentPersonId, $this->counsellorAUserId, 'Case for Rollback Check', 'personal');
        $caseId = (int) $pkg['case']['id'];

        $initialSessionsCount = (int) $this->sessionModel->where('case_id', $caseId)->countAllResults();
        $initialFollowupCount = (int) $this->followupModel->where('case_id', $caseId)->countAllResults();
        $initialNotesCount = (int) $this->noteModel->countAllResults();
        $caseBefore = $this->caseModel->find($caseId);

        // Counselor B attempts unauthorized session open
        $this->signInUser($this->counsellorBUserId, 1);
        try {
            $this->caseService->openSession($caseId, $this->counsellorBUserId, 'regular');
        } catch (DomainException $e) {
            // Expected
        }

        // Counselor B attempts unauthorized followup
        try {
            $this->caseService->addFollowup($caseId, null, date('Y-m-d', strtotime('+5 days')), 'Malicious followup');
        } catch (DomainException $e) {
            // Expected
        }

        // Verify database state is untouched
        $sessionsCountAfter = (int) $this->sessionModel->where('case_id', $caseId)->countAllResults();
        $followupCountAfter = (int) $this->followupModel->where('case_id', $caseId)->countAllResults();
        $notesCountAfter = (int) $this->noteModel->countAllResults();
        $caseAfter = $this->caseModel->find($caseId);

        $this->assertSame($initialSessionsCount, $sessionsCountAfter);
        $this->assertSame($initialFollowupCount, $followupCountAfter);
        $this->assertSame($initialNotesCount, $notesCountAfter);
        $this->assertSame($caseBefore['status'], $caseAfter['status']);
    }
}

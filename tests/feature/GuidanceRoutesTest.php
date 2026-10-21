<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CounsellingCaseModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class GuidanceRoutesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private int $counsellorUserId;
    private int $studentPersonId;
    private int $caseId;

    protected function setUp(): void
    {
        parent::setUp();

        $userModel = new UserModel();
        $unique = uniqid();
        $this->counsellorUserId = (int) $userModel->insert([
            'tenant_id' => 1,
            'username'  => 'counsellor_feat_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        $personModel = new PersonModel();
        $this->studentPersonId = (int) $personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'FEAT_STU_' . $unique,
            'first_name' => 'Guidance',
            'last_name'  => 'Student',
        ]);

        $caseModel = new CounsellingCaseModel();
        $this->caseId = (int) $caseModel->insert([
            'tenant_id'          => 1,
            'person_id'          => $this->studentPersonId,
            'counsellor_user_id' => $this->counsellorUserId,
            'case_number'        => 'GC-2026-' . $unique,
            'title'              => 'Exam Anxiety Case',
            'category'           => 'academic',
            'status'             => 'open',
            'opened_at'          => date('Y-m-d H:i:s'),
        ]);
    }

    private function authSession(): array
    {
        return [
            'user' => [
                'id'          => $this->counsellorUserId,
                'name'        => 'Staff Counsellor',
                'username'    => 'counsellor_test',
                'role'        => 'counsellor',
                'permissions' => [
                    'guidance.cases.read', 'guidance.cases.create', 'guidance.cases.write',
                    'referrals.read', 'referrals.create', 'referrals.acknowledge', 'referrals.close',
                ],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
        ];
    }

    public function testCasesListRenders(): void
    {
        $res = $this->withSession($this->authSession())->get('guidance/cases');

        $res->assertStatus(200);
        $res->assertSee('Student Counseling Cases');
        $res->assertSee('Exam Anxiety Case');
    }

    public function testCaseWorkspaceRenders(): void
    {
        $res = $this->withSession($this->authSession())->get('guidance/cases/' . $this->caseId);

        $res->assertStatus(200);
        $res->assertSee('Exam Anxiety Case');
        $res->assertSee('AES-256-GCM Note Encryption Active');
    }

    public function testReferralsInboxRenders(): void
    {
        $res = $this->withSession($this->authSession())->get('referrals?target=guidance');

        $res->assertStatus(200);
        $res->assertSee('Care Coordination');
        $res->assertSee('Guidance Inbox');
    }

    public function testCreateReferralSubmission(): void
    {
        $res = $this->withSession($this->authSession())->post('referrals/store', [
            'patient_person_id'  => (string) $this->studentPersonId,
            'source_module'      => 'clinic',
            'target_module'      => 'guidance',
            'priority'           => 'urgent',
            'reason_code'        => 'academic_distress',
            'reason_description' => 'Referred from routine clinic intake.',
        ]);

        $res->assertRedirectTo('/referrals?target=guidance');

        $refModel = new \App\Models\ReferralModel();
        $ref = $refModel->where('patient_person_id', $this->studentPersonId)->first();
        $this->assertNotNull($ref);
        $this->assertSame('urgent', $ref['priority']);
    }
}

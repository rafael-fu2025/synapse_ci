<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CounsellingCaseModel;
use App\Models\PersonModel;
use App\Models\ReferralModel;
use App\Models\UserModel;
use App\Services\Guidance\GuidanceCaseService;
use App\Services\Referrals\ReferralService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ReferralServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private ReferralService $referralService;
    private ReferralModel $referralModel;
    private PersonModel $personModel;
    private int $patientId;
    private int $nurseUserId;
    private int $counsellorUserId;

    protected function setUp(): void
    {
        \Tests\Support\InventoryTestDatabase::verify();
        parent::setUp();

        \App\Services\CurrentTenant::set(1);
        $this->referralModel = new ReferralModel();
        $this->personModel = new PersonModel();
        $this->referralService = new ReferralService(
            $this->referralModel,
            $this->personModel,
            new GuidanceCaseService()
        );

        $unique = uniqid();
        $userModel = new UserModel();

        $staffPersonId = (int) $this->personModel->insert([
            'tenant_id' => 1, 'kind' => 'employee', 'mis_id' => 'REF_STAFF_' . $unique,
            'first_name' => 'Referral', 'last_name' => 'Staff',
        ]);
        $this->nurseUserId = (int) $userModel->insert([
            'person_id' => $staffPersonId,
            'tenant_id' => 1,
            'username'  => 'nurse_ref_' . $unique,
            'role'      => 'clinic_staff',
            'status'    => 'active',
        ]);

        $counsellorPersonId = (int) $this->personModel->insert([
            'tenant_id' => 1, 'kind' => 'employee', 'mis_id' => 'REF_COUNSELLOR_' . $unique,
            'first_name' => 'Referral', 'last_name' => 'Counsellor',
        ]);
        $this->counsellorUserId = (int) $userModel->insert([
            'person_id' => $counsellorPersonId,
            'tenant_id' => 1,
            'username'  => 'counsellor_ref_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        $this->patientId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'REF_STU_' . $unique,
            'first_name' => 'Michael',
            'last_name'  => 'Tan',
        ]);
    }

    protected function tearDown(): void
    {
        \App\Services\CurrentTenant::reset();
        parent::tearDown();
    }

    public function testCreateReferralFromClinicToGuidance(): void
    {
        $ref = $this->referralService->createReferral([
            'patient_person_id'  => $this->patientId,
            'source_module'      => 'clinic',
            'target_module'      => 'guidance',
            'issuer_user_id'     => $this->nurseUserId,
            'priority'           => 'urgent',
            'reason_code'        => 'anxiety_stress',
            'reason_description' => 'Student experienced hyperventilation during finals review.',
        ]);

        $this->assertNotNull($ref);
        $this->assertStringStartsWith('REF-' . date('Y') . '-', $ref['referral_code']);
        $this->assertSame('submitted', $ref['status']);
        $this->assertSame('urgent', $ref['priority']);
        $this->assertSame('guidance', $ref['target_module']);

        // Must appear in Guidance inbox
        $inbox = $this->referralModel->getInbox('guidance');
        $found = array_filter($inbox, fn ($r) => (int) $r['id'] === (int) $ref['id']);
        $this->assertNotEmpty($found);
    }

    public function testAcknowledgeAndConvertToCase(): void
    {
        $ref = $this->referralService->createReferral([
            'patient_person_id'  => $this->patientId,
            'source_module'      => 'clinic',
            'target_module'      => 'guidance',
            'issuer_user_id'     => $this->nurseUserId,
            'priority'           => 'routine',
            'reason_code'        => 'academic_distress',
            'reason_description' => 'Student requested counseling support.',
        ]);

        $refId = (int) $ref['id'];

        // 1. Acknowledge
        $ack = $this->referralService->acknowledge($refId, $this->counsellorUserId);
        $this->assertSame('acknowledged', $ack['status']);
        $this->assertNotNull($ack['acknowledged_at']);

        // 2. Convert to Guidance Case
        $result = $this->referralService->convertToCase(
            $refId,
            $this->counsellorUserId,
            'Academic Overwhelm Referral',
            'academic'
        );

        $this->assertSame('converted_to_case', $result['referral']['status']);
        $this->assertNotNull($result['case']['case']);
        $this->assertSame('Academic Overwhelm Referral', $result['case']['case']['title']);

        // Verify case exists in DB
        $case = (new CounsellingCaseModel())->find($result['case']['case']['id']);
        $this->assertNotNull($case);
        $this->assertSame($this->patientId, (int) $case['person_id']);
    }

    public function testZeroCrossModuleSqlJoinEnforcement(): void
    {
        // Enforces House Rule: referrals table must NEVER JOIN clinic_encounters with counselling_*
        $db = \Config\Database::connect();
        $schema = $db->query("SHOW CREATE TABLE referrals")->getRowArray();
        $createSql = strtolower((string) array_values($schema)[1]);

        $this->assertStringNotContainsString('clinic_encounters', $createSql, 'Referrals must not have a foreign key to clinic_encounters.');
        $this->assertStringNotContainsString('counselling_sessions', $createSql, 'Referrals must not have a foreign key to counselling_sessions.');
        $this->assertStringNotContainsString('counselling_cases', $createSql, 'Referrals must not have a foreign key to counselling_cases.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\CounsellingCaseModel;
use App\Models\CounsellingSessionModel;
use App\Models\GuidancePractitionerProfileModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceClinicalFormService;
use App\Services\Guidance\GuidanceCumulativeService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use App\Services\Guidance\GuidancePractitionerService;
use App\Services\Guidance\GuidanceTransferService;
use App\Services\Guidance\SurveyService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceTranscriptEnhancementsTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private int $tenantId = 1;
    private int $studentPersonId;
    private int $associateUserId;
    private int $licensedUserId;
    private int $unsharedCounselorUserId;
    private array $associateActor;
    private array $licensedActor;
    private array $unsharedActor;
    private string $prevSurveyKeyVersion = '';
    private string $prevSurveyKey        = '';

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set($this->tenantId);
        AuthenticatedUser::clear();

        $this->prevSurveyKeyVersion = (string) (getenv('SURVEY_KEY_VERSION') ?: '');
        $this->prevSurveyKey        = (string) (getenv('SURVEY_KEY') ?: '');
        $keyHex = str_repeat('cd', 32);
        putenv('SURVEY_KEY_VERSION=1');
        $_ENV['SURVEY_KEY_VERSION'] = $_SERVER['SURVEY_KEY_VERSION'] = '1';
        putenv('SURVEY_KEY=' . $keyHex);
        $_ENV['SURVEY_KEY'] = $_SERVER['SURVEY_KEY'] = $keyHex;

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();
        if ($this->db->table('tenants')->where('id', $this->tenantId)->countAllResults() === 0) {
            $this->db->table('tenants')->insert([
                'id'        => $this->tenantId,
                'slug'      => 'fu-main',
                'name'      => 'Foundation University',
                'is_active' => 1,
            ]);
        }

        $personModel = new PersonModel($this->db);
        $userModel   = new UserModel($this->db);
        $suffix      = bin2hex(random_bytes(4));
        $this->studentMisId = 'TR-2026-' . $suffix;

        $this->studentPersonId = (int) $personModel->insert([
            'tenant_id'     => $this->tenantId,
            'kind'          => 'student',
            'mis_id'        => $this->studentMisId,
            'first_name'    => 'Klara',
            'last_name'     => 'Mendoza',
            'email'         => "klara.{$suffix}@foundationu.edu",
            'phone'         => '09171234567',
            'address'       => 'Dorm B, Dumaguete City',
            'gender'        => 'female',
            'date_of_birth' => '2004-05-10',
        ]);
        (new StudentProfileModel($this->db))->insert([
            'tenant_id'  => $this->tenantId,
            'person_id'  => $this->studentPersonId,
            'program'    => 'BS Psychology',
            'year_level' => 3,
            'section'    => 'A',
            'department' => 'CAS',
        ]);

        $assocPersonId = (int) $personModel->insert([
            'tenant_id'  => $this->tenantId,
            'kind'       => 'employee',
            'mis_id'     => 'EMP-ASSOC-' . $suffix,
            'first_name' => 'Ana',
            'last_name'  => 'Associate',
            'email'      => "ana.{$suffix}@foundationu.edu",
        ]);
        $this->associateUserId = (int) $userModel->insert([
            'tenant_id'     => $this->tenantId,
            'person_id'     => $assocPersonId,
            'username'      => 'assoc_' . $suffix,
            'email'         => "ana.{$suffix}@foundationu.edu",
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'role'          => 'counsellor',
            'status'        => 'active',
            'auth_version'  => 1,
        ]);

        $licPersonId = (int) $personModel->insert([
            'tenant_id'  => $this->tenantId,
            'kind'       => 'employee',
            'mis_id'     => 'EMP-LIC-' . $suffix,
            'first_name' => 'Ramon',
            'last_name'  => 'RGC',
            'email'      => "ramon.{$suffix}@foundationu.edu",
        ]);
        $this->licensedUserId = (int) $userModel->insert([
            'tenant_id'     => $this->tenantId,
            'person_id'     => $licPersonId,
            'username'      => 'lic_' . $suffix,
            'email'         => "ramon.{$suffix}@foundationu.edu",
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'role'          => 'counsellor',
            'status'        => 'active',
            'auth_version'  => 1,
        ]);

        $otherPersonId = (int) $personModel->insert([
            'tenant_id'  => $this->tenantId,
            'kind'       => 'employee',
            'mis_id'     => 'EMP-OTH-' . $suffix,
            'first_name' => 'Marco',
            'last_name'  => 'Unshared',
            'email'      => "marco.{$suffix}@foundationu.edu",
        ]);
        $this->unsharedCounselorUserId = (int) $userModel->insert([
            'tenant_id'     => $this->tenantId,
            'person_id'     => $otherPersonId,
            'username'      => 'other_' . $suffix,
            'email'         => "marco.{$suffix}@foundationu.edu",
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'role'          => 'counsellor',
            'status'        => 'active',
            'auth_version'  => 1,
        ]);

        $profileModel = new GuidancePractitionerProfileModel($this->db);
        $now = date('Y-m-d H:i:s');
        $profileModel->insert([
            'tenant_id'             => $this->tenantId,
            'user_id'               => $this->associateUserId,
            'person_id'             => $assocPersonId,
            'credential_tier'       => 'counselor_associate',
            'service_scope_json'    => json_encode(['psychosocial_processing', 'routine_interview']),
            'professional_standing' => 'active',
            'is_active'             => 1,
            'verified_at'           => $now,
        ]);
        $profileModel->insert([
            'tenant_id'             => $this->tenantId,
            'user_id'               => $this->licensedUserId,
            'person_id'             => $licPersonId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'RGC-2026-88',
            'service_scope_json'    => json_encode(['counseling', 'psychosocial_processing']),
            'professional_standing' => 'active',
            'is_active'             => 1,
            'verified_at'           => $now,
        ]);

        $policy = new GuidanceAccessPolicy(db: $this->db);
        $this->associateActor = $policy->authorize(['id' => $this->associateUserId, 'tenant_id' => $this->tenantId]);
        $this->licensedActor  = $policy->authorize(['id' => $this->licensedUserId, 'tenant_id' => $this->tenantId]);
        $this->unsharedActor  = $policy->authorize(['id' => $this->unsharedCounselorUserId, 'tenant_id' => $this->tenantId]);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        if ($this->prevSurveyKeyVersion === '') {
            putenv('SURVEY_KEY_VERSION');
            unset($_ENV['SURVEY_KEY_VERSION'], $_SERVER['SURVEY_KEY_VERSION']);
        } else {
            putenv('SURVEY_KEY_VERSION=' . $this->prevSurveyKeyVersion);
        }
        if ($this->prevSurveyKey === '') {
            putenv('SURVEY_KEY');
            unset($_ENV['SURVEY_KEY'], $_SERVER['SURVEY_KEY']);
        } else {
            putenv('SURVEY_KEY=' . $this->prevSurveyKey);
        }
        parent::tearDown();
    }

    private string $studentMisId = '';
    private function actAs(array $actor): void
    {
        AuthenticatedUser::clear();
        session()->set([
            'tenant_id' => $this->tenantId,
            'user'      => $actor + ['logged_in' => true],
            '_auth'     => [
                'started_at'   => time(),
                'last_seen_at' => time(),
                'version'      => (int) ($actor['auth_version'] ?? 1),
                'session_id'   => session_id(),
            ],
        ]);
    }

    public function testAcceptedConsultationGrantsReadOnlyAccessAndDistinguishesPspFromCounseling(): void
    {
        $caseModel = new CounsellingCaseModel($this->db);
        $caseId = (int) $caseModel->insert([
            'tenant_id'          => $this->tenantId,
            'person_id'          => $this->studentPersonId,
            'counsellor_user_id' => $this->associateUserId,
            'case_number'        => 'GC-2026-TR1',
            'title'              => 'PSP Support Case',
            'category'           => 'academic',
            'status'             => 'open',
            'opened_at'          => date('Y-m-d H:i:s'),
        ]);
        $case = $caseModel->find($caseId);

        $transferService = new GuidanceTransferService(db: $this->db);
        $transfer = $transferService->requestTransfer(
            $caseId,
            $this->licensedUserId,
            'consultation',
            'Refer for licensed counseling opinion on academic burnout.',
            $this->associateActor
        );
        $transferService->respondTransfer((int) $transfer['id'], true, 'Accepted for clinical review.', $this->licensedActor);

        $policy = new GuidanceAccessPolicy(db: $this->db);
        $this->actAs($this->licensedActor);
        $resolvedReader = $policy->readCase($case, $this->licensedActor, 'guidance.cases.read');
        self::assertSame($this->licensedUserId, (int) $resolvedReader['id']);
        self::assertTrue($policy->isConsultationPartner($case, $this->licensedActor));
        self::assertFalse($policy->isConsultationPartner($case, $this->associateActor));

        // Consultation share remains strictly read-only on primary handler notes
        try {
            $policy->writeCase($this->licensedActor, $case, 'guidance.cases.write', null, false);
            self::fail('Consultation partner must not write to primary owner case.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }

        // Unshared same-tenant counselor still receives 403
        $this->actAs($this->unsharedActor);
        try {
            $policy->readCase($case, $this->unsharedActor, 'guidance.cases.read');
            self::fail('Unshared counselor must be denied case read access.');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getCode());
        }
        AuthenticatedUser::clear();

        // Verify PSP vs. Counseling tier & service distinction labels
        $directory = (new GuidancePractitionerService(db: $this->db))->getPractitionerDirectory($this->tenantId);
        self::assertSame('Counselor Associate', $directory[$this->associateUserId]['tier_label']);
        self::assertSame('Psychosocial Processing (PSP)', $directory[$this->associateUserId]['service_label']);
        self::assertTrue($directory[$this->associateUserId]['is_associate']);

        self::assertSame('Licensed Counselor', $directory[$this->licensedUserId]['tier_label']);
        self::assertSame('Counseling', $directory[$this->licensedUserId]['service_label']);
        self::assertFalse($directory[$this->licensedUserId]['is_associate']);
    }

    public function testClinicalPrefillUsesRealMisPhoneProgramAndAge(): void
    {
        (new GuidanceFormDefinitionService())->syncManifestToDatabase($this->tenantId);

        $clinicalService = new GuidanceClinicalFormService(db: $this->db);
        $instance = $clinicalService->createClinicalDraft(
            GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            ['person_id' => $this->studentPersonId],
            $this->tenantId,
            $this->associateActor
        );

        $data = $instance['data'] ?? [];
        self::assertSame($this->studentMisId, $data['student_id']);
        self::assertSame('BS Psychology - Year 3', $data['course_year']);
        self::assertSame('09171234567', $data['demographics']['contact']);
        $expectedAge = (new \DateTimeImmutable('2004-05-10'))->diff(new \DateTimeImmutable('today'))->y;
        self::assertSame($expectedAge, $data['demographics']['age']);
    }

    public function testCumulativeRecordAndSurveyStaffInterviewAttribution(): void
    {
        // Create second unshared counselor case to confirm boundary counting without leakage
        $caseModel = new CounsellingCaseModel($this->db);
        $ownedCaseId = (int) $caseModel->insert([
            'tenant_id'          => $this->tenantId,
            'person_id'          => $this->studentPersonId,
            'counsellor_user_id' => $this->associateUserId,
            'case_number'        => 'GC-2026-CUM1',
            'title'              => 'Owned PSP Case',
            'category'           => 'academic',
            'status'             => 'open',
            'opened_at'          => date('Y-m-d H:i:s'),
        ]);
        $caseModel->insert([
            'tenant_id'          => $this->tenantId,
            'person_id'          => $this->studentPersonId,
            'counsellor_user_id' => $this->unsharedCounselorUserId,
            'case_number'        => 'GC-2026-CUM2',
            'title'              => 'Confidential Other Case',
            'category'           => 'personal',
            'status'             => 'open',
            'opened_at'          => date('Y-m-d H:i:s'),
        ]);

        // Add longitudinal MI submission
        $this->db->table('guidance_mi_assignments')->insert([
            'tenant_id'     => $this->tenantId,
            'person_id'     => $this->studentPersonId,
            'status'        => 'completed',
            'academic_term' => '1st Semester',
            'academic_year' => '2026-2027',
            'assigned_at'   => date('Y-m-d H:i:s'),
            'completed_at'  => date('Y-m-d H:i:s'),
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $assignId = (int) $this->db->insertID();
        $this->db->table('guidance_mi_submissions')->insert([
            'tenant_id'          => $this->tenantId,
            'assignment_id'      => $assignId,
            'person_id'          => $this->studentPersonId,
            'status'             => 'finalized',
            'domain_scores_json' => json_encode(['linguistic' => 31, 'logical' => 28]),
            'attested_at'        => date('Y-m-d H:i:s'),
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        // Create published survey + student response, then record staff-only interview remarks
        $this->db->table('surveys')->insert([
            'tenant_id'      => $this->tenantId,
            'title'          => 'Needs Assessment',
            'description'    => 'Annual Guidance Check-in',
            'audience'       => 'students',
            'status'         => 'published',
            'questions_json' => json_encode([['type' => 'text', 'label' => 'Primary concern?', 'required' => true]]),
            'version'        => 1,
            'published_at'   => gmdate('Y-m-d H:i:s'),
            'created_by'     => $this->licensedUserId,
            'created_at'     => gmdate('Y-m-d H:i:s'),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        $surveyId = (int) $this->db->insertID();

        $key = hex2bin(str_repeat('cd', 32));
        $nonce = random_bytes(12);
        $tag = '';
        $payloadCipher = openssl_encrypt(
            json_encode([['question' => 'Primary concern?', 'type' => 'text', 'value' => 'Exam stress']]),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'humus:survey:v1:' . $this->tenantId . ':' . $surveyId . ':' . $this->studentPersonId,
            16
        );
        $this->db->table('survey_responses')->insert([
            'tenant_id'           => $this->tenantId,
            'survey_id'           => $surveyId,
            'person_id'           => $this->studentPersonId,
            'submitted_by'        => $this->associateUserId,
            'payload_cipher'      => $payloadCipher,
            'payload_nonce'       => $nonce,
            'payload_tag'         => $tag,
            'payload_key_version' => 1,
            'submitted_at'        => gmdate('Y-m-d H:i:s'),
        ]);
        $responseId = (int) $this->db->insertID();

        // Promote licensed practitioner user to guidance_supervisor so role holds guidance.responses.read
        $this->db->table('users')->where('id', $this->licensedUserId)->update(['role' => 'guidance_supervisor']);
        $supervisorActor = (new GuidanceAccessPolicy(db: $this->db))->authorize([
            'id'        => $this->licensedUserId,
            'tenant_id' => $this->tenantId,
        ]);
        $this->actAs($supervisorActor);
        $surveyService = new SurveyService();
        $surveyService->recordInterview($surveyId, $responseId, [
            'interview_date' => '2026-11-10',
            'staff_remarks'  => 'Student attended routine interview; scheduled follow-up.',
        ]);

        $detail = $surveyService->responseDetail($surveyId, $responseId);
        self::assertSame('Ramon RGC', $detail['response']['interviewer_name']);
        self::assertSame('Licensed Counselor', $detail['response']['interviewer_tier']);
        self::assertSame('Student attended routine interview; scheduled follow-up.', $detail['response']['staff_remarks']);

        // The client overview counts only authorized cases and loads no content-bearing histories.
        $this->actAs($this->associateActor);
        $service = new GuidanceCumulativeService(db: $this->db);
        $cumulative = $service->getStudentCumulativeRecord($this->studentPersonId, $this->associateActor);

        self::assertSame('09171234567', $cumulative['person']['phone']);
        self::assertSame('Dorm B, Dumaguete City', $cumulative['person']['address']);
        self::assertSame(1, $cumulative['metrics']['cases_total']);
        self::assertSame([], $cumulative['authorizedCases']);
        self::assertSame([], $cumulative['miAttempts']);
        self::assertSame([], $cumulative['surveys']);
        self::assertArrayNotHasKey('restrictedCasesCount', $cumulative);

        $cases = $service->getStudentCumulativeRecord($this->studentPersonId, $this->associateActor, ['tab' => 'cases']);
        self::assertCount(1, $cases['authorizedCases']);
        self::assertSame($ownedCaseId, (int) $cases['authorizedCases'][0]['id']);
        self::assertStringNotContainsString('Confidential Other Case', json_encode($cases, JSON_THROW_ON_ERROR));

        $assessments = $service->getStudentCumulativeRecord($this->studentPersonId, $this->associateActor, ['tab' => 'assessments']);
        self::assertCount(1, $assessments['miAttempts']);
        self::assertSame(31, $assessments['miAttempts'][0]['domain_scores']['linguistic']);
        self::assertSame([], $assessments['surveys']);
        self::assertFalse($assessments['canViewResponses']);

        $this->actAs($supervisorActor);
        $supervisorRecord = $service->getStudentCumulativeRecord($this->studentPersonId, $supervisorActor, ['tab' => 'assessments']);
        self::assertCount(1, $supervisorRecord['surveys']);
        self::assertSame('Ramon RGC', $supervisorRecord['surveys'][0]['interviewer_name']);
        self::assertArrayNotHasKey('staff_remarks', $supervisorRecord['surveys'][0]);
    }
}

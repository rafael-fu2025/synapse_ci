<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\CounsellingCaseModel;
use App\Models\CounsellingSessionModel;
use App\Models\GuidanceAppointmentModel;
use App\Models\GuidanceCallSlipModel;
use App\Models\GuidanceEpisodeModel;
use App\Models\GuidanceFormDefinitionModel;
use App\Models\GuidanceFormDefinitionRevisionModel;
use App\Models\GuidanceFormInstanceModel;
use App\Models\GuidanceIntakeReferralModel;
use App\Models\GuidancePractitionerProfileModel;
use App\Models\GuidanceSupervisionAssignmentModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceEpisodeService;
use App\Services\Guidance\GuidancePractitionerService;
use App\Services\Guidance\GuidanceTransferService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceEpisodeServiceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidanceEpisodeService $episodeService;
    private GuidanceEpisodeModel $episodeModel;
    private GuidanceAppointmentModel $appointmentModel;
    private GuidanceCallSlipModel $callSlipModel;
    private GuidanceIntakeReferralModel $intakeReferralModel;
    private CounsellingCaseModel $caseModel;
    private CounsellingSessionModel $sessionModel;
    private GuidanceFormInstanceModel $formInstanceModel;
    private GuidancePractitionerProfileModel $profileModel;
    private GuidanceSupervisionAssignmentModel $supervisionModel;
    private PersonModel $personModel;
    private UserModel $userModel;
    private GuidancePractitionerService $practitionerService;
    private GuidanceTransferService $transferService;
    private GuidanceAccessPolicy $accessPolicy;
    private EncryptionService $encryptionService;

    private int $counselorAUserId;
    private int $counselorBUserId;
    private int $unqualifiedUserId;
    private int $expiredPractitionerUserId;
    private int $supervisorUserId;
    private int $studentPersonId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        $this->episodeModel = new GuidanceEpisodeModel($this->db);
        $this->appointmentModel = new GuidanceAppointmentModel($this->db);
        $this->callSlipModel = new GuidanceCallSlipModel($this->db);
        $this->intakeReferralModel = new GuidanceIntakeReferralModel($this->db);
        $this->caseModel = new CounsellingCaseModel($this->db);
        $this->sessionModel = new CounsellingSessionModel($this->db);
        $this->formInstanceModel = new GuidanceFormInstanceModel($this->db);
        $this->profileModel = new GuidancePractitionerProfileModel($this->db);
        $this->supervisionModel = new GuidanceSupervisionAssignmentModel($this->db);
        $this->personModel = new PersonModel($this->db);
        $this->userModel = new UserModel($this->db);
        $this->accessPolicy = new GuidanceAccessPolicy(db: $this->db);
        $this->encryptionService = new EncryptionService();

        $this->practitionerService = new GuidancePractitionerService(
            profileModel: $this->profileModel,
            supervisionModel: $this->supervisionModel,
            userModel: $this->userModel,
            accessPolicy: $this->accessPolicy,
            db: $this->db
        );

        $this->transferService = new GuidanceTransferService(
            caseModel: $this->caseModel,
            userModel: $this->userModel,
            accessPolicy: $this->accessPolicy,
            db: $this->db
        );

        $this->episodeService = new GuidanceEpisodeService(
            episodeModel: $this->episodeModel,
            appointmentModel: $this->appointmentModel,
            callSlipModel: $this->callSlipModel,
            intakeReferralModel: $this->intakeReferralModel,
            caseModel: $this->caseModel,
            sessionModel: $this->sessionModel,
            formInstanceModel: $this->formInstanceModel,
            personModel: $this->personModel,
            userModel: $this->userModel,
            practitionerProfileModel: $this->profileModel,
            practitionerService: $this->practitionerService,
            transferService: $this->transferService,
            accessPolicy: $this->accessPolicy,
            encryptionService: $this->encryptionService,
            db: $this->db
        );

        $unique = bin2hex(random_bytes(4));

        // 1. Student Person
        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'STU_' . $unique,
            'first_name' => 'Maria',
            'last_name'  => 'Santos',
        ]);

        // 2. Counselor A (Licensed Counselor, active)
        $counsAPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_A_' . $unique,
            'first_name' => 'Alice',
            'last_name'  => 'Counselor',
        ]);
        $this->counselorAUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $counsAPersonId,
            'username'  => 'counselor_a_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $counsAPersonId,
            'user_id'               => $this->counselorAUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'PRC-LIC-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+1 year')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'intake', 'assessment']),
            'is_active'             => 1,
        ]);

        // 3. Counselor B (Licensed Counselor, active)
        $counsBPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_B_' . $unique,
            'first_name' => 'Bob',
            'last_name'  => 'Counselor',
        ]);
        $this->counselorBUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $counsBPersonId,
            'username'  => 'counselor_b_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $counsBPersonId,
            'user_id'               => $this->counselorBUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'PRC-LIC-B-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+1 year')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'intake']),
            'is_active'             => 1,
        ]);

        // 4. Supervisor User
        $supPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_SUP_' . $unique,
            'first_name' => 'Sarah',
            'last_name'  => 'Supervisor',
        ]);
        $this->supervisorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $supPersonId,
            'username'  => 'supervisor_' . $unique,
            'role'      => 'guidance_supervisor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $supPersonId,
            'user_id'               => $this->supervisorUserId,
            'credential_tier'       => 'guidance_supervisor',
            'license_number'        => 'PRC-SUP-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+2 years')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'intake', 'supervision']),
            'is_active'             => 1,
        ]);

        // 5. Unqualified User (no practitioner profile, staff role)
        $unqPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_UNQ_' . $unique,
            'first_name' => 'Una',
            'last_name'  => 'Staff',
        ]);
        $this->unqualifiedUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $unqPersonId,
            'username'  => 'unq_staff_' . $unique,
            'role'      => 'staff',
            'status'    => 'active',
        ]);

        // 6. Expired Practitioner User
        $expPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_EXP_' . $unique,
            'first_name' => 'Evan',
            'last_name'  => 'Expired',
        ]);
        $this->expiredPractitionerUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $expPersonId,
            'username'  => 'exp_couns_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $expPersonId,
            'user_id'               => $this->expiredPractitionerUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'PRC-EXP-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('-30 days')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling']),
            'is_active'             => 1,
        ]);
    }

    public function testOpenEpisodeSuccess(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
                'status' => 'open',
            ]
        );

        self::assertNotNull($episode);
        self::assertGreaterThan(0, (int) $episode['id']);
        self::assertSame('open', $episode['status']);
        self::assertSame($this->studentPersonId, (int) $episode['person_id']);
        self::assertSame($this->counselorAUserId, (int) $episode['primary_counselor_user_id']);
        self::assertNotEmpty($episode['opened_at']);
        self::assertNull($episode['closed_at']);
    }

    public function testOpenEpisodeWithOptionalLinks(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        // Create intake referral
        $referralId = (int) $this->intakeReferralModel->insert([
            'tenant_id'                      => 1,
            'referral_number'                => 'GIR-' . bin2hex(random_bytes(4)),
            'source_type'                    => 'self',
            'subject_person_id'              => $this->studentPersonId,
            'referrer_user_id'               => $this->counselorAUserId,
            'referrer_name_snapshot'         => 'Self Referred',
            'referrer_designation_snapshot'  => 'Student',
            'referrer_department_snapshot'   => 'Guidance',
            'status'                         => 'triaged',
            'concern_catalog_version'        => '1.0',
            'selections_cipher'              => 'test',
            'selections_nonce'               => random_bytes(12),
            'selections_tag'                 => random_bytes(16),
            'selections_key_version'         => 1,
            'narrative_cipher'               => 'test',
            'narrative_nonce'                => random_bytes(12),
            'narrative_tag'                  => random_bytes(16),
            'narrative_key_version'          => 1,
            'created_at'                     => date('Y-m-d H:i:s'),
            'updated_at'                     => date('Y-m-d H:i:s'),
        ]);

        // Create counseling case
        $caseId = (int) $this->caseModel->insert([
            'tenant_id'          => 1,
            'person_id'          => $this->studentPersonId,
            'counsellor_user_id' => $this->counselorAUserId,
            'case_number'        => 'CAS-' . bin2hex(random_bytes(4)),
            'title'              => 'Academic Counseling Case',
            'category'           => 'academic',
            'status'             => 'open',
            'opened_at'          => date('Y-m-d H:i:s'),
        ]);

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor'                     => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
                'intake_referral_id'        => $referralId,
                'case_id'                   => $caseId,
            ]
        );

        self::assertSame($referralId, (int) $episode['intake_referral_id']);
        self::assertSame($caseId, (int) $episode['case_id']);
    }

    public function testOpenEpisodeFailsWithoutPermission(): void
    {
        $actor = [
            'id' => $this->unqualifiedUserId,
            'tenant_id' => 1,
            'role' => 'staff',
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);

        $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
    }

    public function testOpenEpisodeFailsWithUnqualifiedCounselor(): void
    {
        $actor = [
            'id' => $this->supervisorUserId,
            'tenant_id' => 1,
            'role' => 'guidance_supervisor',
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);

        $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->unqualifiedUserId,
            ]
        );
    }

    public function testOpenEpisodeFailsWithExpiredPractitioner(): void
    {
        $actor = [
            'id' => $this->supervisorUserId,
            'tenant_id' => 1,
            'role' => 'guidance_supervisor',
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);

        $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->expiredPractitionerUserId,
            ]
        );
    }

    public function testOpenEpisodeFailsWithNonexistentPerson(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(404);

        $this->episodeService->openEpisode(
            999999,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
    }

    public function testGetEpisodeDetailsSuccessWithLinkedRecords(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        // 1. Add appointment linked to episode
        $this->appointmentModel->insert([
            'tenant_id'          => 1,
            'episode_id'         => $episodeId,
            'person_id'          => $this->studentPersonId,
            'counselor_user_id'  => $this->counselorAUserId,
            'appointment_number' => 'APT-' . bin2hex(random_bytes(4)),
            'scheduled_date'     => date('Y-m-d', strtotime('+2 days')),
            'scheduled_time'     => '10:00:00',
            'purpose'            => 'routine_interview',
            'status'             => 'scheduled',
        ]);

        // 2. Add call slip linked to episode
        $this->callSlipModel->insert([
            'tenant_id'                  => 1,
            'episode_id'                 => $episodeId,
            'person_id'                  => $this->studentPersonId,
            'issuing_counselor_user_id'  => $this->counselorAUserId,
            'call_slip_number'           => 'CS-' . bin2hex(random_bytes(4)),
            'call_date'                  => date('Y-m-d', strtotime('+3 days')),
            'call_time'                  => '14:00:00',
            'purpose'                    => 'routine_interview',
            'attendance_status'          => 'pending',
            'parent_guardian_delivery'   => 0,
        ]);

        $details = $this->episodeService->getEpisodeDetails($episodeId, 1, $actor);

        self::assertNotNull($details);
        self::assertSame($episodeId, (int) $details['id']);
        self::assertNotEmpty($details['person']);
        self::assertSame('Maria', $details['person']['first_name']);
        self::assertNotEmpty($details['primary_counselor']);
        self::assertSame($this->counselorAUserId, (int) $details['primary_counselor']['id']);
        self::assertCount(1, $details['appointments']);
        self::assertCount(1, $details['call_slips']);
        self::assertIsArray($details['evaluations']);
    }

    public function testGetEpisodeDetailsAccessControl(): void
    {
        $actorA = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actorA,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        // Counselor B (not assigned, no read_any) should be denied
        $actorB = [
            'id' => $this->counselorBUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);

        $this->episodeService->getEpisodeDetails($episodeId, 1, $actorB);
    }

    public function testSupervisorCanReadAnyEpisode(): void
    {
        $actorA = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actorA,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        // Supervisor can read
        $supActor = [
            'id' => $this->supervisorUserId,
            'tenant_id' => 1,
            'role' => 'guidance_supervisor',
        ];

        $details = $this->episodeService->getEpisodeDetails($episodeId, 1, $supActor);
        self::assertNotNull($details);
        self::assertSame($episodeId, (int) $details['id']);
    }

    public function testCloseEpisodeSuccessWithEncryptedNotes(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $confidentialNotes = 'Student has successfully improved academic focus and resolved anxiety symptoms.';
        $closed = $this->episodeService->closeEpisode(
            $episodeId,
            'Counseling completed satisfactorily',
            $confidentialNotes,
            1,
            $actor
        );

        self::assertSame('closed', $closed['status']);
        self::assertNotEmpty($closed['closed_at']);
        self::assertSame('Counseling completed satisfactorily', $closed['closure_reason']);
        self::assertNotEmpty($closed['closure_notes_cipher']);

        // Verify decrypted notes
        $decrypted = $this->episodeService->getDecryptedClosureNotes($closed);
        self::assertSame($confidentialNotes, $decrypted);

        // Verify decrypted notes via episode ID
        $decryptedById = $this->episodeService->getDecryptedClosureNotes($episodeId, $actor, 1);
        self::assertSame($confidentialNotes, $decryptedById);
    }

    public function testCloseEpisodeFailsForUnauthorizedActor(): void
    {
        $actorA = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actorA,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $actorB = [
            'id' => $this->counselorBUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);

        $this->episodeService->closeEpisode(
            $episodeId,
            'Unauthorized attempt',
            null,
            1,
            $actorB
        );
    }

    public function testCloseEpisodeFailsWithoutReason(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        $this->episodeService->closeEpisode(
            $episodeId,
            '   ',
            null,
            1,
            $actor
        );
    }

    public function testCloseAlreadyClosedEpisodeFails(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $this->episodeService->closeEpisode($episodeId, 'Completed', null, 1, $actor);

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(409);

        $this->episodeService->closeEpisode($episodeId, 'Trying to close again', null, 1, $actor);
    }

    public function testTransferEpisodeSuccess(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        // Create episode with linked case
        $caseId = (int) $this->caseModel->insert([
            'tenant_id'          => 1,
            'person_id'          => $this->studentPersonId,
            'counsellor_user_id' => $this->counselorAUserId,
            'case_number'        => 'CAS-' . bin2hex(random_bytes(4)),
            'title'              => 'Transferrable Case',
            'category'           => 'personal',
            'status'             => 'open',
            'opened_at'          => date('Y-m-d H:i:s'),
        ]);

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor'                     => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
                'case_id'                   => $caseId,
            ]
        );
        $episodeId = (int) $episode['id'];

        // Transfer episode to Counselor B
        $transferred = $this->episodeService->transferEpisode(
            $episodeId,
            $this->counselorBUserId,
            'Caseload rebalancing and specialization referral',
            1,
            $actor
        );

        self::assertSame('active', $transferred['status']);
        self::assertSame($this->counselorBUserId, (int) $transferred['primary_counselor_user_id']);

        // Verify linked case was updated by GuidanceTransferService
        $updatedCase = $this->caseModel->find($caseId);
        self::assertSame($this->counselorBUserId, (int) $updatedCase['counsellor_user_id']);
    }

    public function testTransferEpisodeFailsToUnqualifiedCounselor(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);

        $this->episodeService->transferEpisode(
            $episodeId,
            $this->unqualifiedUserId,
            'Invalid transfer target',
            1,
            $actor
        );
    }

    public function testTransferEpisodeFailsToSameCounselor(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        $this->episodeService->transferEpisode(
            $episodeId,
            $this->counselorAUserId,
            'Transferring to self',
            1,
            $actor
        );
    }

    public function testTransferClosedEpisodeFails(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $this->episodeService->closeEpisode($episodeId, 'Completed', null, 1, $actor);

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(409);

        $this->episodeService->transferEpisode(
            $episodeId,
            $this->counselorBUserId,
            'Attempt transfer of closed episode',
            1,
            $actor
        );
    }
}

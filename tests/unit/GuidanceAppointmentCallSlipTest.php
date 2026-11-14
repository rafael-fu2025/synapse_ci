<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\GuidanceAppointmentModel;
use App\Models\GuidanceCallSlipModel;
use App\Models\GuidancePractitionerProfileModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceAppointmentService;
use App\Services\Guidance\GuidanceCallSlipService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use App\Services\Guidance\GuidanceFormService;
use App\Services\Guidance\GuidancePractitionerService;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceAppointmentCallSlipTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidanceAppointmentService $appointmentService;
    private GuidanceCallSlipService $callSlipService;
    private GuidanceAppointmentModel $appointmentModel;
    private GuidanceCallSlipModel $callSlipModel;
    private PersonModel $personModel;
    private StudentProfileModel $studentProfileModel;
    private UserModel $userModel;
    private GuidancePractitionerProfileModel $profileModel;
    private GuidanceAccessPolicy $accessPolicy;
    private GuidanceFormService $formService;
    private EncryptionService $encryptionService;

    private int $counselorUserId;
    private int $supervisorUserId;
    private int $adminUserId;
    private int $unauthorizedUserId;
    private int $studentPersonId;
    private string $studentMisId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        // Synchronize form manifest to database so form definitions exist
        $definitionService = new GuidanceFormDefinitionService();
        $definitionService->syncManifestToDatabase(1);

        $this->appointmentModel = new GuidanceAppointmentModel($this->db);
        $this->callSlipModel = new GuidanceCallSlipModel($this->db);
        $this->personModel = new PersonModel($this->db);
        $this->studentProfileModel = new StudentProfileModel($this->db);
        $this->userModel = new UserModel($this->db);
        $this->profileModel = new GuidancePractitionerProfileModel($this->db);
        $this->encryptionService = new EncryptionService();
        $this->accessPolicy = new GuidanceAccessPolicy(db: $this->db);
        $this->formService = new GuidanceFormService(accessPolicy: $this->accessPolicy, db: $this->db);

        $this->appointmentService = new GuidanceAppointmentService(
            appointmentModel: $this->appointmentModel,
            accessPolicy: $this->accessPolicy,
            formService: $this->formService,
            personModel: $this->personModel,
            studentProfileModel: $this->studentProfileModel,
            userModel: $this->userModel,
            encryptionService: $this->encryptionService,
            db: $this->db
        );

        $this->callSlipService = new GuidanceCallSlipService(
            callSlipModel: $this->callSlipModel,
            accessPolicy: $this->accessPolicy,
            formService: $this->formService,
            personModel: $this->personModel,
            studentProfileModel: $this->studentProfileModel,
            userModel: $this->userModel,
            profileModel: $this->profileModel,
            db: $this->db
        );

        $unique = bin2hex(random_bytes(4));

        // 1. Student person + profile
        $this->studentMisId = 'STU_APT_' . $unique;
        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => $this->studentMisId,
            'first_name' => 'Maria',
            'last_name'  => 'Santos',
            'email'      => 'maria_' . $unique . '@university.edu',
        ]);

        $this->studentProfileModel->insert([
            'tenant_id'  => 1,
            'person_id'  => $this->studentPersonId,
            'program'    => 'BS Psychology',
            'year_level' => 3,
            'section'    => '3-B',
            'department' => 'College of Arts and Sciences',
        ]);

        // 2. Licensed Counselor User + Practitioner Profile
        $counselorPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_CNS_' . $unique,
            'first_name' => 'Ana',
            'last_name'  => 'Reyes',
            'email'      => 'ana_' . $unique . '@university.edu',
        ]);

        $this->counselorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $counselorPersonId,
            'username'  => 'cns_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);

        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $counselorPersonId,
            'user_id'               => $this->counselorUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'PRC-00' . random_int(10000, 99999),
            'credential_expiry'     => date('Y-m-d', strtotime('+2 years')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'intake', 'psychosocial']),
            'is_active'             => 1,
        ]);

        // 3. Supervisor User
        $supPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_SUP_' . $unique,
            'first_name' => 'Sofia',
            'last_name'  => 'Supervisor',
        ]);

        $this->supervisorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $supPersonId,
            'username'  => 'sup_' . $unique,
            'role'      => 'guidance_supervisor',
            'status'    => 'active',
        ]);

        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $supPersonId,
            'user_id'               => $this->supervisorUserId,
            'credential_tier'       => 'guidance_supervisor',
            'license_number'        => 'PRC-SUP-001',
            'credential_expiry'     => date('Y-m-d', strtotime('+3 years')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'intake', 'psychosocial', 'supervision']),
            'is_active'             => 1,
        ]);

        // 4. Admin User (no clinical practitioner profile)
        $adminPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_ADM_' . $unique,
            'first_name' => 'George',
            'last_name'  => 'Admin',
        ]);

        $this->adminUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $adminPersonId,
            'username'  => 'adm_' . $unique,
            'role'      => 'guidance_admin',
            'status'    => 'active',
        ]);

        // 5. Unauthorized User (General faculty/employee)
        $unauthPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_UNAUTH_' . $unique,
            'first_name' => 'John',
            'last_name'  => 'Faculty',
        ]);

        $this->unauthorizedUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $unauthPersonId,
            'username'  => 'unauth_' . $unique,
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

    public function testAppointmentSchedulingAndSequentialNumbering(): void
    {
        $actor = $this->signInUser($this->counselorUserId);

        $today = date('Y-m-d');
        $dateCode = date('Ymd');

        // Schedule first appointment
        $data1 = [
            'person_id'        => $this->studentPersonId,
            'scheduled_date'   => $today,
            'scheduled_time'   => '09:30:00',
            'purpose'          => 'counseling',
            'notes'            => 'Initial counseling session note',
            'queue_ticket_id'  => 42,
            'actor'            => $actor,
        ];

        $apt1 = $this->appointmentService->scheduleAppointment($data1);

        $this->assertNotNull($apt1['id']);
        $this->assertSame("APT-{$dateCode}-0001", $apt1['appointment_number']);
        $this->assertSame('scheduled', $apt1['status']);
        $this->assertSame($this->studentPersonId, $apt1['person_id']);
        $this->assertSame($this->counselorUserId, $apt1['counselor_user_id']);
        $this->assertSame(42, $apt1['queue_ticket_id']);
        $this->assertSame('Initial counseling session note', $apt1['notes']);
        $this->assertNotNull($apt1['form_instance_id']);

        // Check controlled form instance linkage
        $this->assertNotNull($apt1['form_instance']);
        $formInstance = $apt1['form_instance'];
        $this->assertSame('draft', $formInstance['instance']['status']);
        $this->assertSame('guidance_appointment_form', $formInstance['instance']['form_definition_id'] > 0 ? 'guidance_appointment_form' : '');
        $this->assertSame("APT-{$dateCode}-0001", $formInstance['data']['appointment_number']);
        $this->assertSame('Maria Santos', $formInstance['data']['student_name']);
        $this->assertSame($this->studentMisId, $formInstance['data']['student_id']);
        $this->assertSame('BS Psychology - 3', $formInstance['data']['course_year']);

        // Schedule second appointment on same date
        $data2 = [
            'person_id'      => $this->studentPersonId,
            'scheduled_date' => $today,
            'scheduled_time' => '11:00:00',
            'purpose'        => 'routine_interview',
            'actor'          => $actor,
        ];

        $apt2 = $this->appointmentService->scheduleAppointment($data2);

        $this->assertSame("APT-{$dateCode}-0002", $apt2['appointment_number']);
        $this->assertSame('routine_interview', $apt2['purpose']);
        $this->assertNotSame($apt1['form_instance_id'], $apt2['form_instance_id']);
    }

    public function testAppointmentStatusProgressionAndAudit(): void
    {
        $actor = $this->signInUser($this->counselorUserId);

        $apt = $this->appointmentService->scheduleAppointment([
            'person_id'      => $this->studentPersonId,
            'scheduled_date' => date('Y-m-d', strtotime('+1 day')),
            'scheduled_time' => '14:00:00',
            'purpose'        => 'follow_up',
            'actor'          => $actor,
        ]);

        $aptId = (int) $apt['id'];
        $this->assertSame('scheduled', $apt['status']);

        // 1. confirmed
        $updated = $this->appointmentService->updateAppointmentStatus($aptId, 'confirmed', 'Confirmed via phone', 1, $actor);
        $this->assertSame('confirmed', $updated['status']);
        $this->assertSame('Confirmed via phone', $updated['notes']);

        // 2. attended
        $updated = $this->appointmentService->updateAppointmentStatus($aptId, 'attended', 'Student attended on time', 1, $actor);
        $this->assertSame('attended', $updated['status']);
        $this->assertSame('Student attended on time', $updated['notes']);

        // 3. rescheduled
        $rescheduled = $this->appointmentService->updateAppointmentStatus($aptId, 'rescheduled', 'Rescheduled to next week', 1, $actor);
        $this->assertSame('rescheduled', $rescheduled['status']);
        $this->assertSame('Rescheduled to next week', $rescheduled['notes']);

        // 4. cancelled (on a separate appointment)
        $cancelledApt = $this->appointmentService->scheduleAppointment([
            'person_id'      => $this->studentPersonId,
            'scheduled_date' => date('Y-m-d', strtotime('+2 days')),
            'scheduled_time' => '10:00:00',
            'purpose'        => 'inquiry',
            'actor'          => $actor,
        ]);
        $updatedCancelled = $this->appointmentService->updateAppointmentStatus((int) $cancelledApt['id'], 'cancelled', 'Cancelled by student', 1, $actor);
        $this->assertSame('cancelled', $updatedCancelled['status']);
        $this->assertSame('Cancelled by student', $updatedCancelled['notes']);

        // 5. no_show (on a separate appointment)
        $noShowApt = $this->appointmentService->scheduleAppointment([
            'person_id'      => $this->studentPersonId,
            'scheduled_date' => date('Y-m-d', strtotime('+3 days')),
            'scheduled_time' => '11:00:00',
            'purpose'        => 'routine_interview',
            'actor'          => $actor,
        ]);
        $updatedNoShow = $this->appointmentService->updateAppointmentStatus((int) $noShowApt['id'], 'no_show', 'Student failed to appear', 1, $actor);
        $this->assertSame('no_show', $updatedNoShow['status']);
        $this->assertSame('Student failed to appear', $updatedNoShow['notes']);
    }

    public function testAppointmentStatusInvalidThrowsException(): void
    {
        $actor = $this->signInUser($this->counselorUserId);
        $apt = $this->appointmentService->scheduleAppointment([
            'person_id'      => $this->studentPersonId,
            'scheduled_date' => date('Y-m-d', strtotime('+1 day')),
            'scheduled_time' => '14:00:00',
            'purpose'        => 'follow_up',
            'actor'          => $actor,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->appointmentService->updateAppointmentStatus((int) $apt['id'], 'invalid_status', null, 1, $actor);
    }

    public function testAppointmentValidationAndAccessControl(): void
    {
        // 1. Unauthorized user cannot schedule appointment
        $unauthActor = $this->signInUser($this->unauthorizedUserId);

        $this->expectException(DomainException::class);
        $this->appointmentService->scheduleAppointment([
            'person_id'      => $this->studentPersonId,
            'scheduled_date' => date('Y-m-d'),
            'scheduled_time' => '10:00:00',
            'purpose'        => 'counseling',
            'actor'          => $unauthActor,
        ]);
    }

    public function testAppointmentOthersPurposeValidation(): void
    {
        $actor = $this->signInUser($this->counselorUserId);

        // purpose 'others' without purpose_other must fail
        $this->expectException(InvalidArgumentException::class);
        $this->appointmentService->scheduleAppointment([
            'person_id'      => $this->studentPersonId,
            'scheduled_date' => date('Y-m-d'),
            'scheduled_time' => '10:00:00',
            'purpose'        => 'others',
            'purpose_other'  => '',
            'actor'          => $actor,
        ]);
    }

    public function testCallSlipIssuanceAndSequentialNumbering(): void
    {
        $actor = $this->signInUser($this->counselorUserId);

        $today = date('Y-m-d');
        $dateCode = date('Ymd');

        // Issue first call slip
        $data1 = [
            'person_id'                => $this->studentPersonId,
            'call_date'                => $today,
            'call_time'                => '08:30:00',
            'purpose'                  => 'personal_counseling',
            'parent_guardian_delivery' => true,
            'delivery_notes'           => 'Please give to class adviser',
            'actor'                    => $actor,
        ];

        $cs1 = $this->callSlipService->issueCallSlip($data1);

        $this->assertNotNull($cs1['id']);
        $this->assertSame("CS-{$dateCode}-0001", $cs1['call_slip_number']);
        $this->assertSame('pending', $cs1['attendance_status']);
        $this->assertSame($this->studentPersonId, $cs1['person_id']);
        $this->assertSame($this->counselorUserId, $cs1['issuing_counselor_user_id']);
        $this->assertTrue($cs1['parent_guardian_delivery']);
        $this->assertSame('Please give to class adviser', $cs1['delivery_notes']);
        $this->assertNotNull($cs1['form_instance_id']);

        // Check controlled form instance linkage
        $this->assertNotNull($cs1['form_instance']);
        $formInstance = $cs1['form_instance'];
        $this->assertSame('draft', $formInstance['instance']['status']);
        $this->assertSame("CS-{$dateCode}-0001", $formInstance['data']['call_slip_number']);
        $this->assertSame('Maria Santos', $formInstance['data']['student_name']);
        $this->assertSame($this->studentMisId, $formInstance['data']['student_id']);
        $this->assertSame('BS Psychology - 3', $formInstance['data']['course_year']);
        $this->assertSame('Ana Reyes', $formInstance['data']['issuing_counselor_name']);

        // Issue second call slip on same date
        $data2 = [
            'person_id' => $this->studentPersonId,
            'call_date' => $today,
            'call_time' => '13:30:00',
            'purpose'   => 'academic_counseling',
            'actor'     => $actor,
        ];

        $cs2 = $this->callSlipService->issueCallSlip($data2);

        $this->assertSame("CS-{$dateCode}-0002", $cs2['call_slip_number']);
        $this->assertSame('academic_counseling', $cs2['purpose']);
        $this->assertNotSame($cs1['form_instance_id'], $cs2['form_instance_id']);
    }

    public function testCallSlipPractitionerEligibilityEnforcement(): void
    {
        // Admin user has role guidance_admin with permission guidance.call_slips.issue,
        // but NO clinical practitioner profile. Must throw 403.
        $adminActor = $this->signInUser($this->adminUserId);

        $this->expectException(DomainException::class);
        $this->callSlipService->issueCallSlip([
            'person_id' => $this->studentPersonId,
            'call_date' => date('Y-m-d'),
            'call_time' => '10:00:00',
            'purpose'   => 'career_counseling',
            'actor'     => $adminActor,
        ]);
    }

    public function testCallSlipAttendanceProgressionAndTimestamps(): void
    {
        $actor = $this->signInUser($this->counselorUserId);

        $cs = $this->callSlipService->issueCallSlip([
            'person_id' => $this->studentPersonId,
            'call_date' => date('Y-m-d'),
            'call_time' => '14:00:00',
            'purpose'   => 'routine_interview',
            'actor'     => $actor,
        ]);

        $csId = (int) $cs['id'];
        $this->assertSame('pending', $cs['attendance_status']);
        $this->assertNull($cs['acknowledged_at']);
        $this->assertNull($cs['attended_at']);

        // 1. Acknowledged
        $acknowledged = $this->callSlipService->recordCallSlipStatus($csId, 'acknowledged', 1, $actor);
        $this->assertSame('acknowledged', $acknowledged['attendance_status']);
        $this->assertNotNull($acknowledged['acknowledged_at']);
        $this->assertNull($acknowledged['attended_at']);

        // 2. Attended
        $attended = $this->callSlipService->recordCallSlipStatus($csId, 'attended', 1, $actor);
        $this->assertSame('attended', $attended['attendance_status']);
        $this->assertNotNull($attended['acknowledged_at']);
        $this->assertNotNull($attended['attended_at']);

        // 3. Missed (on a separate call slip)
        $missedCs = $this->callSlipService->issueCallSlip([
            'person_id' => $this->studentPersonId,
            'call_date' => date('Y-m-d'),
            'call_time' => '10:00:00',
            'purpose'   => 'academic_counseling',
            'actor'     => $actor,
        ]);
        $recordedMissed = $this->callSlipService->recordCallSlipStatus((int) $missedCs['id'], 'missed', 1, $actor);
        $this->assertSame('missed', $recordedMissed['attendance_status']);
        $this->assertNull($recordedMissed['attended_at']);

        // 4. Cancelled (on a separate call slip)
        $cancelledCs = $this->callSlipService->issueCallSlip([
            'person_id' => $this->studentPersonId,
            'call_date' => date('Y-m-d'),
            'call_time' => '11:00:00',
            'purpose'   => 'career_counseling',
            'actor'     => $actor,
        ]);
        $recordedCancelled = $this->callSlipService->recordCallSlipStatus((int) $cancelledCs['id'], 'cancelled', 1, $actor);
        $this->assertSame('cancelled', $recordedCancelled['attendance_status']);
    }

    public function testCallSlipStatusInvalidThrowsException(): void
    {
        $actor = $this->signInUser($this->counselorUserId);
        $cs = $this->callSlipService->issueCallSlip([
            'person_id' => $this->studentPersonId,
            'call_date' => date('Y-m-d'),
            'call_time' => '14:00:00',
            'purpose'   => 'routine_interview',
            'actor'     => $actor,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->callSlipService->recordCallSlipStatus((int) $cs['id'], 'unknown_status', 1, $actor);
    }

    public function testEpisodeIntegrationAndControlledFormLinking(): void
    {
        $actor = $this->signInUser($this->counselorUserId);

        $episodeModel = new \App\Models\GuidanceEpisodeModel($this->db);
        $episodeId = (int) $episodeModel->insert([
            'tenant_id'                 => 1,
            'person_id'                 => $this->studentPersonId,
            'status'                    => 'open',
            'opened_at'                 => date('Y-m-d H:i:s'),
            'primary_counselor_user_id' => $this->counselorUserId,
        ]);

        // Schedule appointment with episode_id
        $apt = $this->appointmentService->scheduleAppointment([
            'person_id'      => $this->studentPersonId,
            'episode_id'     => $episodeId,
            'scheduled_date' => date('Y-m-d', strtotime('+1 day')),
            'scheduled_time' => '09:00:00',
            'purpose'        => 'counseling',
            'actor'          => $actor,
        ]);

        $this->assertSame($episodeId, $apt['episode_id']);
        $this->assertNotNull($apt['form_instance_id']);

        // Issue call slip with episode_id
        $cs = $this->callSlipService->issueCallSlip([
            'person_id'  => $this->studentPersonId,
            'episode_id' => $episodeId,
            'call_date'  => date('Y-m-d', strtotime('+1 day')),
            'call_time'  => '08:45:00',
            'purpose'    => 'personal_counseling',
            'actor'      => $actor,
        ]);

        $this->assertSame($episodeId, $cs['episode_id']);
        $this->assertNotNull($cs['form_instance_id']);
    }

    public function testGetAppointmentAndGetCallSlipRetrieval(): void
    {
        $actor = $this->signInUser($this->counselorUserId);

        $apt = $this->appointmentService->scheduleAppointment([
            'person_id'      => $this->studentPersonId,
            'scheduled_date' => date('Y-m-d'),
            'scheduled_time' => '15:00:00',
            'purpose'        => 'inquiry',
            'actor'          => $actor,
        ]);

        $retrievedApt = $this->appointmentService->getAppointment((int) $apt['id'], 1, $actor);
        $this->assertSame($apt['id'], $retrievedApt['id']);
        $this->assertSame('Maria Santos', $retrievedApt['student']['full_name']);
        $this->assertSame($this->studentMisId, $retrievedApt['student']['mis_id']);
        $this->assertSame('Ana Reyes', $retrievedApt['counselor']['full_name']);
        $this->assertNotNull($retrievedApt['form_instance']);

        $cs = $this->callSlipService->issueCallSlip([
            'person_id' => $this->studentPersonId,
            'call_date' => date('Y-m-d'),
            'call_time' => '15:30:00',
            'purpose'   => 'follow_up',
            'actor'     => $actor,
        ]);

        $retrievedCs = $this->callSlipService->getCallSlip((int) $cs['id'], 1, $actor);
        $this->assertSame($cs['id'], $retrievedCs['id']);
        $this->assertSame('Maria Santos', $retrievedCs['student']['full_name']);
        $this->assertSame('BS Psychology - 3', $retrievedCs['student']['course_year']);
        $this->assertSame('Ana Reyes', $retrievedCs['counselor']['full_name']);
        $this->assertNotNull($retrievedCs['form_instance']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Libraries\TablePage;
use App\Models\ClinicEncounterModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\AnnouncementService;
use App\Services\AuthenticatedUser;
use App\Services\Clinic\EncounterService;
use App\Services\Clinic\InventoryService;
use App\Services\Clinic\IsolationTrackingService;
use App\Services\Clinic\PatientDirectoryService;
use App\Services\Clinic\PatientHistoryService;
use App\Services\CurrentTenant;
use App\Services\Reporting\ClinicMedicalCertificateLatex;
use App\Services\Reporting\ClinicSummaryReportLatex;
use App\Services\Reporting\ClinicSummaryReportService;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\FreshDatabaseTestCase;

final class ClinicTranscriptEnhancementsTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private int $tenantId = 1;
    private int $studentId;
    private int $secondStudentId;
    private int $internUserId;
    private int $physicianUserId;
    private int $clinicAdminUserId;
    private int $studentUserId;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set($this->tenantId);
        AuthenticatedUser::clear();

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

        $this->studentId = (int) $personModel->insert([
            'tenant_id'     => $this->tenantId,
            'kind'          => 'student',
            'mis_id'        => 'CL-2026-' . $suffix,
            'first_name'    => 'Marco',
            'last_name'     => 'Villanueva',
            'email'         => "marco.{$suffix}@foundationu.edu",
            'gender'        => 'male',
            'date_of_birth' => '2004-03-15',
        ]);
        (new StudentProfileModel($this->db))->insert([
            'tenant_id'  => $this->tenantId,
            'person_id'  => $this->studentId,
            'program'    => 'BS Nursing',
            'year_level' => 3,
        ]);

        $this->secondStudentId = (int) $personModel->insert([
            'tenant_id'     => $this->tenantId,
            'kind'          => 'student',
            'mis_id'        => 'CL2-2026-' . $suffix,
            'first_name'    => 'Lia',
            'last_name'     => 'Navarro',
            'email'         => "lia.{$suffix}@foundationu.edu",
            'gender'        => 'female',
            'date_of_birth' => '2005-07-22',
        ]);
        (new StudentProfileModel($this->db))->insert([
            'tenant_id'  => $this->tenantId,
            'person_id'  => $this->secondStudentId,
            'program'    => 'BS Nursing',
            'year_level' => 2,
        ]);

        $hash = password_hash('SecretPass123!', PASSWORD_BCRYPT);
        $this->internUserId = (int) $userModel->insert([
            'tenant_id'     => $this->tenantId,
            'username'      => 'intern_' . $suffix,
            'email'         => "intern.{$suffix}@foundationu.edu",
            'password_hash' => $hash,
            'role'          => 'clinic_intern',
            'status'        => 'active',
        ]);
        $this->physicianUserId = (int) $userModel->insert([
            'tenant_id'     => $this->tenantId,
            'username'      => 'doc_' . $suffix,
            'email'         => "doc.{$suffix}@foundationu.edu",
            'password_hash' => $hash,
            'role'          => 'clinic_physician',
            'status'        => 'active',
        ]);
        $staffPersonId = (int) $personModel->insert([
            'tenant_id'  => $this->tenantId,
            'kind'       => 'employee',
            'mis_id'     => 'EMP-2026-' . $suffix,
            'first_name' => 'Elena',
            'last_name'  => 'Reyes',
            'email'      => "elena.{$suffix}@foundationu.edu",
        ]);
        $this->clinicAdminUserId = (int) $userModel->insert([
            'tenant_id'     => $this->tenantId,
            'person_id'     => $staffPersonId,
            'username'      => 'cadmin_' . $suffix,
            'email'         => "cadmin.{$suffix}@foundationu.edu",
            'password_hash' => $hash,
            'role'          => 'clinic_admin',
            'status'        => 'active',
        ]);
        $this->studentUserId = (int) $userModel->insert([
            'tenant_id'     => $this->tenantId,
            'person_id'     => $this->studentId,
            'username'      => 'stu_' . $suffix,
            'email'         => "stu.{$suffix}@foundationu.edu",
            'password_hash' => $hash,
            'role'          => 'student',
            'status'        => 'active',
        ]);
    }

    public function testStudentNurseAndPhysicianRbacPermissions(): void
    {
        $internPerms = array_column(
            $this->db->table('role_permissions rp')
                ->select('p.code')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role', 'clinic_intern')
                ->get()->getResultArray(),
            'code'
        );
        self::assertContains('clinic.encounters.create', $internPerms);
        self::assertContains('clinic.encounters.write', $internPerms);
        self::assertContains('clinic.patients.read', $internPerms);
        self::assertNotContains('clinic.encounters.seal', $internPerms);
        self::assertNotContains('clinic.inventory.write', $internPerms);
        self::assertNotContains('clinic.encounters.addendum', $internPerms);

        $physicianPerms = array_column(
            $this->db->table('role_permissions rp')
                ->select('p.code')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role', 'clinic_physician')
                ->get()->getResultArray(),
            'code'
        );
        self::assertContains('clinic.encounters.seal', $physicianPerms);
        self::assertContains('clinic.encounters.addendum', $physicianPerms);
        self::assertContains('clinic.isolation.write', $physicianPerms);
        self::assertContains('clinic.clearances.write', $physicianPerms);
        self::assertContains('clinic.reports.export', $physicianPerms);

        $inventory = new InventoryService($this->db);
        $inventory->assertAccess($this->physicianUserId, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);
        $inventory->assertAccess($this->internUserId, true);
    }

    public function testStructuredComplaintVisitedAtDraftAttributionAndSealedAddenda(): void
    {
        $service = new EncounterService(db: $this->db);
        $started = $service->startEncounter($this->studentId, $this->internUserId);
        $encId   = (int) $started['encounter']['id'];

        $yesterdayManila = (new \DateTimeImmutable('-1 day 02:15', new \DateTimeZone('Asia/Manila')))->format('Y-m-d\TH:i');
        $service->saveDraft($encId, [
            'visited_at'          => $yesterdayManila,
            'complaint_category'  => 'headache',
            'complaint_qualifier' => 'Frontal',
            'bp_systolic'         => 110,
            'bp_diastolic'        => 70,
        ], $this->internUserId);

        $service->recordAssessment(
            $encId,
            '',
            null,
            'Tension headache',
            null,
            null,
            'headache',
            'Frontal'
        );

        // Sealed encounters accept addenda; open encounters reject with 409.
        try {
            $service->appendAddendum($encId, $this->physicianUserId, 'clinical_note', 'Premature note');
            self::fail('Expected 409 on open encounter addendum.');
        } catch (\RuntimeException $e) {
            self::assertSame(409, $e->getCode());
        }

        $sealed = $service->completeEncounter($encId, 'discharged', 'Rest advised', null, null, $this->physicianUserId);
        self::assertSame('completed', $sealed['encounter']['status']);
        self::assertSame($this->physicianUserId, (int) $sealed['encounter']['attending_user_id']);
        self::assertSame($this->internUserId, (int) $sealed['encounter']['encoded_by_user_id']);
        self::assertSame('Headache (Frontal)', $sealed['encounter']['chief_complaint']);

        $service->appendAddendum($encId, $this->physicianUserId, 'clinical_note', 'Issued PE excuse slip.');
        $history = new PatientHistoryService();
        $history->recordLabResult($this->studentId, [
            'encounter_id'   => $encId,
            'test_name'      => 'CBC',
            'result_status'  => 'normal',
            'result_summary' => 'WBC 6.8',
            'context_type'   => 'encounter_followup',
            'school_year'    => PatientHistoryService::currentSchoolYear(),
        ], $this->physicianUserId);

        $details = $service->getEncounterDetails($encId);
        self::assertGreaterThanOrEqual(2, count($details['addenda']));
        self::assertCount(1, $details['lab_results']);

        $manilaDate = substr($yesterdayManila, 0, 10);
        $list = (new ClinicEncounterModel($this->db))->getListPage(
            TablePage::forList(['page' => 1, 'per_page' => 20]),
            null,
            null,
            ['from' => $manilaDate, 'to' => $manilaDate, 'complaint' => 'headache', 'disposition' => 'discharged']
        );
        self::assertCount(1, $list['encounters']);
        self::assertSame($encId, (int) $list['encounters'][0]['id']);

        $certTex = ClinicMedicalCertificateLatex::forEncounter($details, 'Dr. Santos');
        self::assertStringContainsString('\\pdfminorversion=4', $certTex);
        self::assertStringContainsString('Villanueva', $certTex);
    }

    public function testEnrollmentClearancesIsolationAlertsExtendedReportsAndPinnedAdvisories(): void
    {
        $history = new PatientHistoryService();
        $clrPast = $history->upsertEnrollmentClearance($this->studentId, [
            'school_year'        => '2025-2026',
            'semester'           => '1st Semester',
            'applicant_category' => 'freshman',
            'clearance_status'   => 'cleared',
            'checklist'          => ['physical_exam' => '1', 'cbc' => '1', 'chest_xray' => '1'],
            'remarks'            => 'Fit for AY 2025-2026',
        ], $this->physicianUserId);
        $clrCurr = $history->upsertEnrollmentClearance($this->studentId, [
            'school_year'        => '2026-2027',
            'semester'           => '1st Semester',
            'applicant_category' => 'returning',
            'clearance_status'   => 'hold_followup',
            'checklist'          => ['physical_exam' => '1'],
            'remarks'            => 'Pending repeat CXR',
        ], $this->physicianUserId);
        self::assertNotSame((int) $clrPast['id'], (int) $clrCurr['id']);

        $person = (new PersonModel($this->db))->scoped()->find($this->studentId);
        $clrTex = ClinicMedicalCertificateLatex::forClearance($person, $clrPast, 'Dr. Santos');
        self::assertStringContainsString('\\pdfminorversion=4', $clrTex);
        self::assertStringContainsString('2025-2026', $clrTex);

        // Log 2 chickenpox isolation cases to trigger anonymized threshold alert
        $isoService = new IsolationTrackingService($this->db);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $case1 = $isoService->logCase([
            'person_id'            => $this->studentId,
            'condition_code'       => 'chickenpox',
            'isolation_start_date' => $today,
            'notes'                => 'Vesicular rash',
        ], $this->physicianUserId);
        $isoService->logCase([
            'person_id'            => $this->secondStudentId,
            'condition_code'       => 'chickenpox',
            'isolation_start_date' => $today,
        ], $this->physicianUserId);

        $expectedEnd = (new \DateTimeImmutable($today))->modify('+6 days')->format('Y-m-d');
        self::assertSame($expectedEnd, $case1['isolation_end_date']);

        $alerts = $isoService->anonymizedThresholdAlerts(14, 2);
        self::assertCount(1, $alerts);
        self::assertSame('chickenpox', $alerts[0]['condition_code']);
        self::assertSame(2, $alerts[0]['case_count']);
        $encodedAlert = json_encode($alerts, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Marco', $encodedAlert);
        self::assertStringNotContainsString('Lia', $encodedAlert);
        self::assertStringNotContainsString('CL-2026-', $encodedAlert);

        $clearedCase = $isoService->clearCase((int) $case1['id'], $this->physicianUserId, 'cleared', 'Lesions dried');
        self::assertSame('cleared', $clearedCase['clearance_status']);

        // Extended (>31d) summary report & LaTeX compilation source check
        (new EncounterService(db: $this->db))->startEncounter($this->studentId, $this->physicianUserId, null, null, 'Checkup');
        $reportService = new ClinicSummaryReportService($this->db);
        $syReport = $reportService->buildSummary(['preset' => 'school_year'], $this->tenantId);
        self::assertGreaterThanOrEqual(1, $syReport['totals']['encounters']);
        self::assertGreaterThanOrEqual(2, $syReport['totals']['isolation']);

        $customFrom = (new \DateTimeImmutable('-120 days', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $customReport = $reportService->buildSummary(['preset' => 'custom', 'from' => $customFrom, 'to' => $today], $this->tenantId);
        self::assertSame($customFrom, $customReport['period']['from']);
        $reportTex = ClinicSummaryReportLatex::source($customReport);
        self::assertStringContainsString('\\pdfminorversion=4', $reportTex);
        self::assertStringNotContainsString('Marco', $reportTex);

        // Frequent visitor & directory aggregation check
        $dir = (new PatientDirectoryService($this->db))->paginatePatients(TablePage::fromQuery(['q' => (string) $person['mis_id'], 'page' => 1, 'per_page' => 25]));
        self::assertSame(1, $dir['patients'][0]['visit_count']);
        $chart = $history->getPatientChart($this->studentId, includeAppointments: false);
        self::assertSame(1, $chart['visit_summary']['total_visits']);
        self::assertCount(2, $chart['enrollment_clearances']);

        // Pinned portal Clinic advisory visibility check
        $adminUser = (new UserModel($this->db))->find($this->clinicAdminUserId);
        $adminPerms = array_column(
            $this->db->table('role_permissions rp')->select('p.code')->join('permissions p', 'p.id=rp.permission_id')->where('rp.role', 'clinic_admin')->get()->getResultArray(),
            'code'
        );
        $now = time();
        session()->set([
            'tenant_id' => $this->tenantId,
            '_auth'     => [
                'started_at'   => $now - 10,
                'last_seen_at' => $now,
                'version'      => (int) ($adminUser['auth_version'] ?? 1),
                'session_id'   => session_id(),
            ],
            'user' => [
                'id'          => (int) $adminUser['id'],
                'tenant_id'   => $this->tenantId,
                'person_id'   => (int) $adminUser['person_id'],
                'username'    => $adminUser['username'],
                'role'        => 'clinic_admin',
                'status'      => 'active',
                'logged_in'   => true,
                'permissions' => $adminPerms,
            ],
        ]);
        $announcements = new AnnouncementService();
        $draft = $announcements->create('clinic', [
            'title'              => 'Enrollment Chest X-Ray Advisory',
            'body'               => 'Please submit X-ray films at the University Clinic.',
            'audience'           => 'students',
            'is_pinned_advisory' => '1',
        ]);
        $announcements->publish((int) $draft['id'], 'clinic', $draft['version']);

        $stuUser = (new UserModel($this->db))->find($this->studentUserId);
        session()->set([
            'tenant_id' => $this->tenantId,
            '_auth'     => [
                'started_at'   => $now - 10,
                'last_seen_at' => $now,
                'version'      => (int) ($stuUser['auth_version'] ?? 1),
                'session_id'   => session_id(),
            ],
            'user' => [
                'id'          => (int) $stuUser['id'],
                'tenant_id'   => $this->tenantId,
                'person_id'   => $this->studentId,
                'username'    => $stuUser['username'],
                'role'        => 'student',
                'status'      => 'active',
                'logged_in'   => true,
                'permissions' => [],
            ],
        ]);
        $pinned = $announcements->pinnedAdvisories();
        self::assertCount(1, $pinned);
        self::assertSame('Enrollment Chest X-Ray Advisory', $pinned[0]['title']);
    }
}


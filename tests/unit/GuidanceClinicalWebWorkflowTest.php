<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAppointmentService;
use App\Services\Guidance\GuidanceCaseService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use Config\Database;
use Config\Services;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceClinicalWebWorkflowTest extends FreshDatabaseTestCase
{
    use FeatureTestTrait;

    private string $suffix;
    private array $studentUser;
    private array $associateUser;
    private array $counselorUser;
    private array $supervisorUser;
    private array $superadminUser;
    private array $guidanceAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db     = Database::connect();
        $this->suffix = bin2hex(random_bytes(4));
        (new PermissionsAndRolesSeeder(new Database()))->run();
        (new GuidanceFormDefinitionService())->syncManifestToDatabase(1);
        $this->resetActivationGates();
        CurrentTenant::set(1);

        $this->studentUser       = $this->createUserFixture('student', 'student');
        $this->associateUser     = $this->createUserFixture('associate', 'counsellor', 'counselor_associate');
        $this->counselorUser     = $this->createUserFixture('counselor', 'counsellor', 'licensed_counselor');
        $this->supervisorUser    = $this->createUserFixture('supervisor', 'guidance_supervisor', 'guidance_supervisor');
        $this->superadminUser    = $this->createUserFixture('superadmin', 'superadmin', null);
        $this->guidanceAdminUser = $this->createUserFixture('gadmin', 'guidance_admin', null);

        // Assign supervisor to associate for co-sign governance
        $this->db->table('guidance_supervision_assignments')->insert([
            'tenant_id'          => 1,
            'associate_user_id'  => $this->associateUser['id'],
            'supervisor_user_id' => $this->supervisorUser['id'],
            'status'             => 'active',
            'co_sign_required'   => 1,
            'starts_at'          => date('Y-m-d H:i:s', strtotime('-7 days')),
            'created_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->resetActivationGates();
        $_SESSION = [];
        CurrentTenant::reset();
        parent::tearDown();
    }

    public function testPortalIndexSmokeAndClinicalWebWorkflow(): void
    {
        // 1. Portal index smoke check as student
        $portalResp = $this->withSession($this->sessionFor($this->studentUser))
            ->get('portal');
        $portalResp->assertStatus(200);
        $portalResp->assertSee('Guidance');

        // 2. Episodes workflow: index -> open -> view -> transfer -> close
        $epIndex = $this->withSession($this->sessionFor($this->counselorUser))
            ->get('guidance/episodes');
        $epIndex->assertStatus(200);

        $epOpenResp = $this->postAs($this->counselorUser, 'guidance/episodes/open', [
            'person_id'                 => $this->studentUser['person_id'],
            'primary_counselor_user_id' => $this->counselorUser['id'],
        ], true);
        $epOpenResp->assertStatus(200);
        $epOpenJson = json_decode((string) $epOpenResp->getJSON(), true);
        $this->assertTrue((bool) ($epOpenJson['success'] ?? false));

        $episode = $this->db->table('guidance_episodes')
            ->where('person_id', $this->studentUser['person_id'])
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $this->assertNotNull($episode);
        $episodeId = (int) $episode['id'];

        $epView = $this->withSession($this->sessionFor($this->counselorUser))
            ->get('guidance/episodes/' . $episodeId);
        $epView->assertStatus(200);
        $epView->assertSee('Care Episode EP-');

        // Transfer episode to supervisor, then open a second episode and close it
        $epTransfer = $this->postAs($this->counselorUser, "guidance/episodes/{$episodeId}/transfer", [
            'new_counselor_user_id' => $this->supervisorUser['id'],
            'reason'                => 'Senior review handoff',
        ], true);
        $epTransfer->assertStatus(200);

        $transferredRow = $this->db->table('guidance_episodes')->where('id', $episodeId)->get()->getRowArray();
        $this->assertSame('active', $transferredRow['status']);
        $this->assertSame($this->supervisorUser['id'], (int) $transferredRow['primary_counselor_user_id']);

        $epOpen2 = $this->postAs($this->counselorUser, 'guidance/episodes/open', [
            'person_id'                 => $this->studentUser['person_id'],
            'primary_counselor_user_id' => $this->counselorUser['id'],
        ], true);
        $epOpen2->assertStatus(200);
        $episode2 = $this->db->table('guidance_episodes')
            ->where('person_id', $this->studentUser['person_id'])
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $episode2Id = (int) $episode2['id'];

        $epClose = $this->postAs($this->counselorUser, "guidance/episodes/{$episode2Id}/close", [
            'closure_reason' => 'Goals met',
            'closure_notes'  => 'Follow-up completed',
        ], true);
        $epClose->assertStatus(200);
        $closedRow = $this->db->table('guidance_episodes')->where('id', $episode2Id)->get()->getRowArray();
        $this->assertSame('closed', $closedRow['status']);

        // 3. Call Slips & Controlled Slip PDFs
        $csIndex = $this->withSession($this->sessionFor($this->counselorUser))
            ->get('guidance/call-slips');
        $csIndex->assertStatus(200);

        $csIssue = $this->postAs($this->counselorUser, 'guidance/call-slips/issue', [
            'person_id'  => $this->studentUser['person_id'],
            'episode_id' => $episodeId,
            'call_date'  => date('Y-m-d', strtotime('+2 days')),
            'call_time'  => '09:30',
            'purpose'    => 'routine_interview',
        ], true);
        $csIssue->assertStatus(200);

        $callSlip = $this->db->table('guidance_call_slips')
            ->where('person_id', $this->studentUser['person_id'])
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $this->assertNotNull($callSlip);
        $callSlipId = (int) $callSlip['id'];

        // Student acknowledges call slip via portal route
        $csAck = $this->postAs($this->studentUser, "portal/guidance/call-slips/{$callSlipId}/acknowledge", [], true);
        $csAck->assertStatus(200);
        $ackedRow = $this->db->table('guidance_call_slips')->where('id', $callSlipId)->get()->getRowArray();
        $this->assertNotEmpty($ackedRow['acknowledged_at']);

        // Counselor marks attendance status
        $csStatus = $this->postAs($this->counselorUser, "guidance/call-slips/{$callSlipId}/status", [
            'attendance_status' => 'attended',
        ], true);
        $csStatus->assertStatus(200);
        $attendedRow = $this->db->table('guidance_call_slips')->where('id', $callSlipId)->get()->getRowArray();
        $this->assertSame('attended', $attendedRow['attendance_status']);

        // Controlled Slip PDF preview (quad)
        $csPdf = $this->withSession($this->sessionFor($this->counselorUser))
            ->get("guidance/call-slips/{$callSlipId}/pdf?layout=quad&mode=preview");
        $csPdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $csPdf->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $csPdf->response()->getBody());

        // Appointment slip PDF preview (single)
        $apptService = new GuidanceAppointmentService(db: $this->db);
        $appt = $apptService->scheduleAppointment([
            'person_id'         => $this->studentUser['person_id'],
            'scheduled_date'    => date('Y-m-d', strtotime('+3 days')),
            'scheduled_time'    => '10:00',
            'purpose'           => 'counseling',
            'counselor_user_id' => $this->counselorUser['id'],
            'actor'             => $this->counselorUser,
        ], 1);
        $apptId = (int) $appt['id'];

        $apptPdf = $this->withSession($this->sessionFor($this->counselorUser))
            ->get("guidance/appointments/{$apptId}/pdf?layout=single&mode=preview");
        $apptPdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $apptPdf->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $apptPdf->response()->getBody());

        // 4. Case creation, Case view, Clinical Forms & Co-Sign, and Case Transfers
        AuthenticatedUser::clear();
        $_SESSION = $this->sessionFor($this->associateUser);
        $caseService = new GuidanceCaseService(db: $this->db);
        $case = $caseService->createCase(
            (int) $this->studentUser['person_id'],
            (int) $this->associateUser['id'],
            'Initial academic transition support',
            'academic',
            null,
            $this->associateUser
        );
        $_SESSION = [];
        AuthenticatedUser::clear();
        $caseId = (int) ($case['case']['id'] ?? $case['id']);

        $caseViewResp = $this->withSession($this->sessionFor($this->associateUser))
            ->get("guidance/cases/{$caseId}");
        $caseViewResp->assertStatus(200);

        // Supervisor views co-sign queue
        $queueResp = $this->withSession($this->sessionFor($this->supervisorUser))
            ->get('guidance/clinical/cosign-queue');
        $queueResp->assertStatus(200);

        // Associate opens new psychosocial_support_interview form
        $newFormResp = $this->withSession($this->sessionFor($this->associateUser))
            ->get("guidance/clinical/forms/new/psychosocial_support_interview?case_id={$caseId}");
        $newFormResp->assertStatus(200);
        $newFormResp->assertSee('Psychosocial');

        $formFields = [
            'student_name'                => 'Student ' . $this->suffix,
            'student_id'                  => 'STU-' . strtoupper($this->suffix),
            'course_year'                 => 'BSCS 2',
            'interview_date'              => date('Y-m-d'),
            'demographics'                => ['age' => '20', 'gender' => 'Prefer not to say', 'contact' => '09170000001'],
            'presenting_concerns'         => 'Adjustment difficulties with coursework.',
            'counselor_assessment'        => 'Alert, oriented, cooperative.',
            'intervention_plan'           => 'Bi-weekly study coaching and check-ins.',
            'privacy_consent_acknowledged'=> '1',
        ];

        // Non-clinical superadmin and guidance_admin MUST NOT author clinical forms (fail-closed 403)
        foreach ([$this->superadminUser, $this->guidanceAdminUser] as $nonClinicalUser) {
            $deniedCreate = $this->postAs($nonClinicalUser, 'guidance/clinical/forms/create', [
                'form_key'  => 'psychosocial_support_interview',
                'person_id' => $this->studentUser['person_id'],
                'case_id'   => $caseId,
                'fields'    => $formFields,
            ], true);
            $this->assertSame(403, $deniedCreate->response()->getStatusCode(), 'Non-clinical role should receive 403 on clinical form create');
        }

        // Associate creates the clinical form
        $createFormResp = $this->postAs($this->associateUser, 'guidance/clinical/forms/create', [
            'form_key'  => 'psychosocial_support_interview',
            'person_id' => $this->studentUser['person_id'],
            'case_id'   => $caseId,
            'fields'    => $formFields,
        ], true);
        $createFormResp->assertStatus(200);
        $createJson = json_decode((string) $createFormResp->getJSON(), true);
        $this->assertTrue((bool) ($createJson['success'] ?? false));
        $createdFormRow = $this->db->table('guidance_form_instances')
            ->where('person_id', $this->studentUser['person_id'])
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $this->assertNotNull($createdFormRow);
        $instanceId = (int) $createdFormRow['id'];
        $this->assertGreaterThan(0, $instanceId);

        // Associate saves draft update
        $formFields['counselor_assessment'] = 'Updated observation: responsive to intervention.';
        $saveDraftResp = $this->postAs($this->associateUser, "guidance/clinical/forms/{$instanceId}/save", [
            'fields' => $formFields,
        ], true);
        $saveDraftResp->assertStatus(200);

        // Associate cannot self-finalize (must obtain supervisor co-sign) -> 403
        $associateFinalize = $this->postAs($this->associateUser, "guidance/clinical/forms/{$instanceId}/finalize", [
            'attestation_confirmed' => '1',
        ], true);
        $this->assertSame(403, $associateFinalize->response()->getStatusCode());

        // Non-clinical superadmin/admin cannot finalize -> 403
        foreach ([$this->superadminUser, $this->guidanceAdminUser] as $nonClinicalUser) {
            $deniedFinalize = $this->postAs($nonClinicalUser, "guidance/clinical/forms/{$instanceId}/finalize", [
                'attestation_confirmed' => '1',
            ], true);
            $this->assertSame(403, $deniedFinalize->response()->getStatusCode());
        }

        // Associate submits for co-sign
        $submitCosignResp = $this->postAs($this->associateUser, "guidance/clinical/forms/{$instanceId}/submit-cosign", [
            'submission_notes' => 'Ready for supervisory review',
        ], true);
        $submitCosignResp->assertStatus(200);

        // Supervisor sees it in queue and co-signs
        $queueAfterSubmit = $this->withSession($this->sessionFor($this->supervisorUser))
            ->get('guidance/clinical/cosign-queue');
        $queueAfterSubmit->assertStatus(200);
        $queueAfterSubmit->assertSee('Ready for supervisory review');

        $cosignResp = $this->postAs($this->supervisorUser, "guidance/clinical/forms/{$instanceId}/cosign", [
            'attestation_confirmed' => '1',
        ], true);
        $cosignResp->assertStatus(200);
        $cosignedRow = $this->db->table('guidance_form_instances')->where('id', $instanceId)->get()->getRowArray();
        $this->assertSame('finalized', $cosignedRow['status']);

        // Controlled clinical form PDF preview
        $formPdf = $this->withSession($this->sessionFor($this->supervisorUser))
            ->get("guidance/clinical/forms/{$instanceId}/pdf?mode=preview");
        $formPdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $formPdf->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $formPdf->response()->getBody());

        // Supervisor directly creates & finalizes a clinical form via /finalize endpoint
        $directCreateResp = $this->postAs($this->supervisorUser, 'guidance/clinical/forms/create', [
            'form_key'  => 'psychosocial_support_interview',
            'person_id' => $this->studentUser['person_id'],
            'case_id'   => $caseId,
            'fields'    => $formFields,
        ], true);
        $directCreateResp->assertStatus(200);
        $directRow = $this->db->table('guidance_form_instances')
            ->where('person_id', $this->studentUser['person_id'])
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $directInstanceId = (int) $directRow['id'];
        $this->assertGreaterThan($instanceId, $directInstanceId);

        $finalizeResp = $this->postAs($this->supervisorUser, "guidance/clinical/forms/{$directInstanceId}/finalize", [
            'attestation_confirmed' => '1',
        ], true);
        $finalizeResp->assertStatus(200);
        $finalizedRow = $this->db->table('guidance_form_instances')->where('id', $directInstanceId)->get()->getRowArray();
        $this->assertSame('finalized', $finalizedRow['status']);

        // Case Transfer Request -> Respond -> Reassign
        $transferReqResp = $this->postAs($this->associateUser, "guidance/clinical/cases/{$caseId}/transfer/request", [
            'to_counsellor_user_id' => $this->counselorUser['id'],
            'transfer_type'         => 'full_transfer',
            'reason'                => 'Caseload balancing',
        ], true);
        $transferReqResp->assertStatus(200);
        $transferRow = $this->db->table('guidance_case_transfers')
            ->where('case_id', $caseId)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $this->assertNotNull($transferRow);
        $transferId = (int) $transferRow['id'];

        $respondResp = $this->postAs($this->counselorUser, "guidance/clinical/transfers/{$transferId}/respond", [
            'decision'       => 'accept',
            'response_notes' => 'Accepted into caseload',
        ], true);
        $respondResp->assertStatus(200);

        $caseAfterAccept = $this->db->table('counselling_cases')->where('id', $caseId)->get()->getRowArray();
        $this->assertSame($this->counselorUser['id'], (int) $caseAfterAccept['counsellor_user_id']);

        $reassignResp = $this->postAs($this->supervisorUser, "guidance/clinical/cases/{$caseId}/reassign", [
            'new_counsellor_user_id' => $this->supervisorUser['id'],
            'reason'                 => 'Supervisor escalation takeover',
        ], true);
        $reassignResp->assertStatus(200);

        $caseAfterReassign = $this->db->table('counselling_cases')->where('id', $caseId)->get()->getRowArray();
        $this->assertSame($this->supervisorUser['id'], (int) $caseAfterReassign['counsellor_user_id']);
    }

    private function postAs(array $user, string $uri, array $params = [], bool $ajaxJson = false)
    {
        $security = Services::security();
        $hash     = $security->generateHash();
        $cookie   = config('Cookie')->prefix . config('Security')->cookieName;
        service('superglobals')->setCookie($cookie, $hash);
        Services::resetSingle('security');

        $headers = [
            'X-CSRF-TOKEN' => $hash,
        ];
        if ($ajaxJson) {
            $headers['Accept']           = 'application/json';
            $headers['X-Requested-With'] = 'XMLHttpRequest';
        }

        $params[config('Security')->tokenName] = $hash;

        return $this->withSession($this->sessionFor($user))
            ->withHeaders($headers)
            ->post($uri, $params);
    }

    private function sessionFor(array $user): array
    {
        $perms = array_column(
            $this->db->table('role_permissions rp')
                ->select('p.code')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role', $user['role'])
                ->get()
                ->getResultArray(),
            'code'
        );

        return [
            'user' => [
                'id'          => $user['id'],
                'tenant_id'   => 1,
                'person_id'   => $user['person_id'],
                'username'    => $user['username'],
                'role'        => $user['role'],
                'permissions' => $perms,
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
            '_auth'     => [
                'started_at'   => time(),
                'last_seen_at' => time(),
                'version'      => (int) $user['auth_version'],
                'session_id'   => session_id(),
            ],
        ];
    }

    private function createUserFixture(string $prefix, string $role, ?string $tier = null): array
    {
        $tag = $prefix . '_' . $this->suffix;
        $now = date('Y-m-d H:i:s');

        $this->db->table('people')->insert([
            'tenant_id'  => 1,
            'kind'       => $role === 'student' ? 'student' : 'employee',
            'mis_id'     => 'MIS-' . strtoupper($tag),
            'first_name' => ucfirst($prefix),
            'last_name'  => 'Fixture_' . $this->suffix,
            'email'      => $tag . '@test.local',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $personId = (int) $this->db->insertID();

        if ($role === 'student') {
            $this->db->table('student_profiles')->insert([
                'tenant_id'  => 1,
                'person_id'  => $personId,
                'program'    => 'BSCS',
                'year_level' => '2',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->db->table('users')->insert([
            'tenant_id'     => 1,
            'person_id'     => $personId,
            'username'      => $tag,
            'email'         => $tag . '@test.local',
            'password_hash' => password_hash('SecretPass123!', PASSWORD_BCRYPT),
            'role'          => $role,
            'status'        => 'active',
            'auth_version'  => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $userId = (int) $this->db->insertID();

        if ($tier !== null) {
            $this->db->table('guidance_practitioner_profiles')->insert([
                'tenant_id'             => 1,
                'person_id'             => $personId,
                'user_id'               => $userId,
                'credential_tier'       => $tier,
                'license_number'        => 'LIC-' . strtoupper($tag),
                'credential_expiry'     => date('Y-m-d', strtotime('+1 year')),
                'professional_standing' => 'active',
                'service_scope_json'    => json_encode(['counseling', 'psychosocial', 'cosign', 'assessments']),
                'is_active'             => 1,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        }

        return [
            'id'           => $userId,
            'tenant_id'    => 1,
            'person_id'    => $personId,
            'username'     => $tag,
            'role'         => $role,
            'auth_version' => 1,
        ];
    }

    private function resetActivationGates(): void
    {
        if ($this->db->tableExists('guidance_activation_gates')) {
            $this->db->table('guidance_activation_gates')
                ->where('tenant_id', 1)
                ->update([
                    'is_enabled'             => 0,
                    'recorded_signoffs_json' => '{}',
                    'approved_at'            => null,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
        }
    }
}

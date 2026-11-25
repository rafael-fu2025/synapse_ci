<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceActivationGateService;
use App\Services\Guidance\GuidanceCallSlipService;
use App\Services\Guidance\GuidanceEpisodeService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use App\Services\Guidance\GuidanceServiceEvaluationService;
use Config\Database;
use Config\Services;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceMiAndEvalWebWorkflowTest extends FreshDatabaseTestCase
{
    use FeatureTestTrait;

    private string $suffix;
    private array $studentUser;
    private array $counselorUser;
    private array $supervisorUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db     = Database::connect();
        $this->suffix = bin2hex(random_bytes(4));
        (new PermissionsAndRolesSeeder(new Database()))->run();
        (new GuidanceFormDefinitionService())->syncManifestToDatabase(1);
        $this->resetActivationGates();
        CurrentTenant::set(1);

        $this->studentUser    = $this->createUserFixture('mistudent', 'student');
        $this->counselorUser  = $this->createUserFixture('micounselor', 'counsellor', 'licensed_counselor');
        $this->supervisorUser = $this->createUserFixture('misupervisor', 'guidance_supervisor', 'guidance_supervisor');
    }

    protected function tearDown(): void
    {
        $this->resetActivationGates();
        $_SESSION = [];
        CurrentTenant::reset();
        parent::tearDown();
    }

    public function testMultipleIntelligenceWebWorkflowAndGateEnforcement(): void
    {
        // Counselor views MI index and assigns assessment to student
        $miIndex = $this->withSession($this->sessionFor($this->counselorUser))
            ->get('guidance/assessments/mi');
        $miIndex->assertStatus(200);

        $assignResp = $this->postAs($this->counselorUser, 'guidance/assessments/mi/assign', [
            'person_id' => $this->studentUser['person_id'],
        ], true);
        $assignResp->assertStatus(201);
        $assignJson = json_decode((string) $assignResp->getJSON(), true);
        $assignmentId = (int) ($assignJson['assignment']['id'] ?? 0);
        $this->assertGreaterThan(0, $assignmentId);

        // Student sees the assignment inside the combined My Surveys hub; the old list redirects there.
        $surveyHub = $this->withSession($this->sessionFor($this->studentUser))
            ->get('portal/surveys');
        $surveyHub->assertStatus(200);
        $surveyHub->assertSee('Multiple Intelligence');
        $surveyHub->assertSee('Start Assessment');

        $portalList = $this->withSession($this->sessionFor($this->studentUser))
            ->get('portal/assessments/mi');
        $this->assertSame(302, $portalList->response()->getStatusCode());
        $this->assertSame(site_url('/portal/surveys#multiple-intelligence'), $portalList->response()->getHeaderLine('Location'));

        $portalRunner = $this->withSession($this->sessionFor($this->studentUser))
            ->get("portal/assessments/mi/{$assignmentId}");
        $portalRunner->assertStatus(200);

        // Save draft responses
        $draftResp = $this->postAs($this->studentUser, "portal/assessments/mi/{$assignmentId}/draft", [
            'responses' => [1 => 4, 2 => 3, 3 => 2],
        ], true);
        $draftResp->assertStatus(200);
        $draftJson = json_decode((string) $draftResp->getJSON(), true);
        $this->assertTrue((bool) ($draftJson['success'] ?? false));
        $this->assertSame(3, (int) ($draftJson['saved_count'] ?? 0));

        // Build all 80 valid responses (0..4)
        $responses = [];
        for ($i = 1; $i <= 80; $i++) {
            $responses[$i] = ($i % 5);
        }

        // Gate OFF -> finalize MUST fail closed with 403
        $blockedFinalize = $this->postAs($this->studentUser, "portal/assessments/mi/{$assignmentId}/finalize", [
            'signer_capacity'       => 'student',
            'attestation_confirmed' => '1',
            'attestation_statement' => 'I attest these responses are my own.',
            'responses'             => $responses,
        ], true);
        $this->assertSame(403, $blockedFinalize->response()->getStatusCode());

        // Enable MI Reproduction gate (3 required authority signoffs)
        $this->enableGate(GuidanceActivationGateService::GATE_MI_REPRODUCTION);

        // Gate ON -> finalize succeeds
        $finalizeResp = $this->postAs($this->studentUser, "portal/assessments/mi/{$assignmentId}/finalize", [
            'signer_capacity'       => 'student',
            'attestation_confirmed' => '1',
            'attestation_statement' => 'I attest these responses are my own.',
            'responses'             => $responses,
        ], true);
        $finalizeResp->assertStatus(200);
        $finalizeJson = json_decode((string) $finalizeResp->getJSON(), true);
        $this->assertTrue((bool) ($finalizeJson['success'] ?? false));
        $submissionId = (int) ($finalizeJson['submission_id'] ?? 0);
        $this->assertGreaterThan(0, $submissionId);

        // Student views results page & PDF
        $studentResult = $this->withSession($this->sessionFor($this->studentUser))
            ->get("portal/assessments/mi/results/{$submissionId}");
        $studentResult->assertStatus(200);

        $studentPdf = $this->withSession($this->sessionFor($this->studentUser))
            ->get("portal/assessments/mi/results/{$submissionId}/pdf");
        $studentPdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $studentPdf->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $studentPdf->response()->getBody());

        // Counselor views MI results page
        $counselorResult = $this->withSession($this->sessionFor($this->counselorUser))
            ->get("guidance/assessments/mi/results/{$submissionId}");
        $counselorResult->assertStatus(200);
    }

    public function testMultipleIntelligenceMultiStudentAndCohortAssignment(): void
    {
        $cohortProgram = 'BSIT-' . $this->suffix;
        $stuBsit1 = $this->createUserFixture('mibsit1', 'student');
        $stuBsit2 = $this->createUserFixture('mibsit2', 'student');
        $stuBsn1  = $this->createUserFixture('mibsn1', 'student');
        $stuBsa4  = $this->createUserFixture('mibsa4', 'student');

        $this->db->table('student_profiles')->where('person_id', $this->studentUser['person_id'])->update(['program' => $cohortProgram, 'year_level' => '3']);
        $this->db->table('student_profiles')->where('person_id', $stuBsit1['person_id'])->update(['program' => $cohortProgram, 'year_level' => '1']);
        $this->db->table('student_profiles')->where('person_id', $stuBsit2['person_id'])->update(['program' => $cohortProgram, 'year_level' => '2']);
        $this->db->table('student_profiles')->where('person_id', $stuBsn1['person_id'])->update(['program' => 'BSN', 'year_level' => '1']);
        $this->db->table('student_profiles')->where('person_id', $stuBsa4['person_id'])->update(['program' => 'BSA', 'year_level' => '4']);

        $miIndex = $this->withSession($this->sessionFor($this->counselorUser))
            ->get('guidance/assessments/mi');
        $miIndex->assertStatus(200);
        $miIndex->assertSee('Specific Students');
        $miIndex->assertSee('Cohort / Group');
        $miIndex->assertSee('person_ids[]');
        $miIndex->assertSee('assign_all_students');
        $miIndex->assertSee('target_programs[]');
        $miIndex->assertSee('target_year_levels[]');

        // 1. Multi-student assignment via person_ids[]
        $multiAssign = $this->postAs($this->counselorUser, 'guidance/assessments/mi/assign', [
            'assign_scope' => 'individual',
            'person_ids'   => [$stuBsit1['person_id'], $stuBsit2['person_id']],
            'academic_term'=> '1st Trimester',
            'academic_year'=> '2026-2027',
        ], true);
        $multiAssign->assertStatus(201);
        $multiJson = json_decode((string) $multiAssign->getJSON(), true);
        $this->assertSame(2, (int) ($multiJson['assigned_count'] ?? 0));
        $this->assertSame(0, (int) ($multiJson['skipped_active_count'] ?? -1));
        $this->assertCount(2, $multiJson['assignments'] ?? []);

        // 2. Multi-student assignment via comma-separated mis_id skips already-active student
        $mis1 = 'MIS-' . strtoupper($stuBsit1['username']);
        $misBsn1 = 'MIS-' . strtoupper($stuBsn1['username']);
        $multiMisAssign = $this->postAs($this->counselorUser, 'guidance/assessments/mi/assign', [
            'mis_id' => $mis1 . ', ' . $misBsn1,
        ], true);
        $multiMisAssign->assertStatus(201);
        $multiMisJson = json_decode((string) $multiMisAssign->getJSON(), true);
        $this->assertSame(1, (int) ($multiMisJson['assigned_count'] ?? 0));
        $this->assertSame(1, (int) ($multiMisJson['skipped_active_count'] ?? 0));
        $this->assertSame((int) $stuBsn1['person_id'], (int) ($multiMisJson['assignment']['person_id'] ?? 0));

        // 3. Single-student duplicate active assignment still returns 409
        $dupSingle = $this->postAs($this->counselorUser, 'guidance/assessments/mi/assign', [
            'person_id' => $stuBsit1['person_id'],
        ], true);
        $this->assertSame(409, $dupSingle->response()->getStatusCode());

        // 4. Unknown MIS ID in batch input returns 422
        $badMis = $this->postAs($this->counselorUser, 'guidance/assessments/mi/assign', [
            'mis_id' => $mis1 . ', UNKNOWN-MIS-' . $this->suffix,
        ], true);
        $this->assertSame(422, $badMis->response()->getStatusCode());

        // 5. Cohort assignment by Program + Year Level targets only matching students (studentUser = BSIT-$suffix Year 3)
        $cohortAssign = $this->postAs($this->counselorUser, 'guidance/assessments/mi/assign', [
            'assign_scope'       => 'cohort',
            'target_programs'    => [$cohortProgram],
            'target_year_levels' => ['3'],
        ], true);
        $cohortAssign->assertStatus(201);
        $cohortJson = json_decode((string) $cohortAssign->getJSON(), true);
        $this->assertSame(1, (int) ($cohortJson['assigned_count'] ?? 0));
        $this->assertSame((int) $this->studentUser['person_id'], (int) ($cohortJson['assignment']['person_id'] ?? 0));

        // Confirm BSA Year 4 student was not assigned yet
        $bsa4Existing = $this->db->table('guidance_mi_assignments')
            ->where('tenant_id', 1)
            ->where('person_id', $stuBsa4['person_id'])
            ->countAllResults();
        $this->assertSame(0, $bsa4Existing);

        // 6. Cohort assignment for All Active Students assigns remaining student(s) and skips already-active ones
        $allAssign = $this->postAs($this->counselorUser, 'guidance/assessments/mi/assign', [
            'assign_scope'        => 'cohort',
            'assign_all_students' => '1',
        ], true);
        $allAssign->assertStatus(201);
        $allJson = json_decode((string) $allAssign->getJSON(), true);
        $this->assertGreaterThanOrEqual(1, (int) ($allJson['assigned_count'] ?? 0));
        $this->assertGreaterThanOrEqual(4, (int) ($allJson['skipped_active_count'] ?? 0));

        $bsa4After = $this->db->table('guidance_mi_assignments')
            ->where('tenant_id', 1)
            ->where('person_id', $stuBsa4['person_id'])
            ->whereIn('status', ['assigned', 'in_progress'])
            ->countAllResults();
        $this->assertSame(1, $bsa4After);

        // 7. Re-running All Active Students when every student already has an active assignment returns 409
        $allDup = $this->postAs($this->counselorUser, 'guidance/assessments/mi/assign', [
            'assign_scope'        => 'cohort',
            'assign_all_students' => '1',
        ], true);
        $this->assertSame(409, $allDup->response()->getStatusCode());
    }

    public function testServiceEvaluationCohortSuppressionModerationAndAggregatePdf(): void
    {
        $evalService = new GuidanceServiceEvaluationService(db: $this->db);

        // Create 5 distinct students and issue one-time tokens tied to this counselor
        $students = [];
        $tokens   = [];
        for ($i = 1; $i <= 5; $i++) {
            $stu = $this->createUserFixture('evalstu' . $i, 'student');
            $students[] = $stu;
            $issued = $evalService->issueEvaluationToken(
                (int) $stu['person_id'],
                GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
                (int) $this->counselorUser['id'],
                ['academic_term' => '1st Semester', 'academic_year' => '2026-2027'],
                1
            );
            $tokens[] = $issued['raw_token'];
        }

        // Redeem token #1 via portal GET and submit first 2 evaluations (cohort < 5)
        $redeemPage = $this->withSession($this->sessionFor($students[0]))
            ->get('portal/evaluations/redeem?token=' . urlencode($tokens[0]));
        $redeemPage->assertStatus(200);

        $suppressedComment = 'SecretEarlyComment_' . $this->suffix;
        for ($idx = 0; $idx < 2; $idx++) {
            $sub = $this->postAs($students[$idx], 'portal/evaluations/submit', [
                'raw_token'                => $tokens[$idx],
                'visit_type'               => 'walk_in',
                'rating_welcoming'         => 5,
                'rating_empathy'           => 4,
                'rating_clarity'           => 5,
                'rating_helpfulness'       => 5,
                'rating_environment'       => 4,
                'future_visit_willingness' => '1',
                'qualitative_comments'     => $idx === 0 ? $suppressedComment : 'Another early comment',
            ], true);
            $sub->assertStatus(201);
        }

        // Dashboard MUST suppress averages & comments while cohort < 5 and leak zero student PII
        $dashSmall = $this->withSession($this->sessionFor($this->counselorUser))
            ->get('guidance/evaluations/dashboard');
        $dashSmall->assertStatus(200);
        $dashSmall->assertSee('Cohort Threshold Suppression Active');
        $dashSmall->assertSee('2/5');
        $dashSmall->assertDontSee($suppressedComment);
        $dashSmall->assertDontSee($students[0]['username']);
        $dashSmall->assertDontSee('MIS-');

        // Submit remaining 3 evaluations to meet MIN_COHORT_SIZE = 5
        $approvedComment = 'ApprovedCohortFeedback_' . $this->suffix;
        $evalId          = 0;
        for ($idx = 2; $idx < 5; $idx++) {
            $sub = $this->postAs($students[$idx], 'portal/evaluations/submit', [
                'raw_token'                => $tokens[$idx],
                'visit_type'               => 'called',
                'rating_welcoming'         => 5,
                'rating_empathy'           => 5,
                'rating_clarity'           => 5,
                'rating_helpfulness'       => 5,
                'rating_environment'       => 5,
                'future_visit_willingness' => '1',
                'qualitative_comments'     => $idx === 2 ? $approvedComment : '',
            ], true);
            $sub->assertStatus(201);
            if ($idx === 2) {
                $subJson = json_decode((string) $sub->getJSON(), true);
                $evalId  = (int) ($subJson['evaluation_id'] ?? 0);
            }
        }

        $this->assertGreaterThan(0, $evalId);

        $modQueue = $this->withSession($this->sessionFor($this->supervisorUser))
            ->get('guidance/evaluations/moderate');
        $modQueue->assertStatus(200);
        $modQueue->assertSee($approvedComment);

        $modPost = $this->postAs($this->supervisorUser, "guidance/evaluations/moderate/{$evalId}", [
            'status'           => 'approved',
            'moderation_notes' => 'Constructive feedback cleared for aggregate view',
        ], true);
        $modPost->assertStatus(200);

        // Dashboard now meets cohort threshold for psychosocial, displays approved comment, and still hides student PII
        $dashFull = $this->withSession($this->sessionFor($this->counselorUser))
            ->get('guidance/evaluations/dashboard');
        $dashFull->assertStatus(200);
        $dashFull->assertSee('Overall Mean');
        $dashFull->assertSee($approvedComment);
        $dashFull->assertDontSee($suppressedComment); // still unmoderated/pending
        foreach ($students as $stu) {
            $dashFull->assertDontSee($stu['username']);
        }

        // Aggregate PDF export
        $aggPdf = $this->withSession($this->sessionFor($this->counselorUser))
            ->get('guidance/evaluations/aggregate-pdf?evaluation_type=' . GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL);
        $aggPdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $aggPdf->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $aggPdf->response()->getBody());
    }

    public function testControlledDocumentVerifyEndpointHtmlAndJsonNonPii(): void
    {
        // Issue a call slip and issue its PDF to create a guidance_document_issuances record
        $epService = new GuidanceEpisodeService(db: $this->db);
        $episode = $epService->openEpisode(
            (int) $this->studentUser['person_id'],
            [
                'primary_counselor_user_id' => (int) $this->counselorUser['id'],
                'actor'                     => $this->counselorUser,
            ],
            1
        );

        $csService = new GuidanceCallSlipService(db: $this->db);
        $slip = $csService->issueCallSlip([
            'person_id' => $this->studentUser['person_id'],
            'episode_id'=> (int) $episode['id'],
            'call_date' => date('Y-m-d', strtotime('+1 day')),
            'call_time' => '14:00',
            'purpose'   => 'routine_interview',
            'actor'     => $this->counselorUser,
        ], 1);

        $issuePdf = $this->withSession($this->sessionFor($this->counselorUser))
            ->get("guidance/call-slips/{$slip['id']}/pdf?layout=single&mode=issue");
        $issuePdf->assertStatus(200);

        $issuance = $this->db->table('guidance_document_issuances')
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $this->assertNotNull($issuance);
        $code = (string) $issuance['verification_code'];
        $this->assertNotEmpty($code);

        // Public HTML verify (no PII leaked)
        $htmlVerify = $this->get("guidance/verify/{$code}");
        $htmlVerify->assertStatus(200);
        $htmlVerify->assertSee($code);
        $htmlVerify->assertDontSee($this->studentUser['username']);
        $htmlVerify->assertDontSee('MIS-');

        // Public JSON verify (no PII leaked)
        $jsonVerify = $this->withHeaders(['Accept' => 'application/json'])
            ->get("guidance/verify/{$code}");
        $jsonVerify->assertStatus(200);
        $payload = json_decode((string) $jsonVerify->getJSON(), true);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertSame($code, $payload['verification']['verification_code'] ?? null);
        $this->assertArrayNotHasKey('person_id', $payload['verification'] ?? []);
        $this->assertArrayNotHasKey('student_name', $payload['verification'] ?? []);

        // Unknown verification code -> 404
        $missing = $this->withHeaders(['Accept' => 'application/json'])
            ->get('guidance/verify/NONEXISTENT-' . $this->suffix);
        $missing->assertStatus(404);
    }

    public function testStaffTokenIssuanceAndPortalRedemptionWorkflow(): void
    {
        $epService = new GuidanceEpisodeService(db: $this->db);
        $episode = $epService->openEpisode(
            (int) $this->studentUser['person_id'],
            [
                'primary_counselor_user_id' => (int) $this->counselorUser['id'],
                'actor'                     => $this->counselorUser,
            ],
            1
        );

        // 1. Visiting redeem screen without a token renders neutral prompt (not an error)
        $emptyRedeem = $this->withSession($this->sessionFor($this->studentUser))
            ->get('portal/evaluations/redeem');
        $emptyRedeem->assertStatus(200);
        $emptyRedeem->assertSee('Enter Your Evaluation Token');
        $emptyRedeem->assertDontSee('Evaluation Token Not Recognized');

        // 2. Submitting an unrecognized token reports invalid state and preserves the typed value
        $badCode = 'invalid-sample-' . $this->suffix;
        $badRedeem = $this->withSession($this->sessionFor($this->studentUser))
            ->get('portal/evaluations/redeem?token=' . urlencode($badCode));
        $badRedeem->assertStatus(200);
        $badRedeem->assertSee('Evaluation Token Not Recognized');
        $badRedeem->assertSee($badCode);

        // 3. Students are forbidden from issuing evaluation tokens (403)
        $forbidden = $this->postAs($this->studentUser, 'guidance/evaluations/issue', [
            'person_id'       => (int) $this->studentUser['person_id'],
            'episode_id'      => (int) $episode['id'],
            'evaluation_type' => GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
        ], true);
        $this->assertSame(403, $forbidden->response()->getStatusCode());

        // 4. Episode page renders the staff "Issue Evaluation Token" button
        $epPage = $this->withSession($this->sessionFor($this->counselorUser))
            ->get('guidance/episodes/' . (int) $episode['id']);
        $epPage->assertStatus(200);
        $epPage->assertSee('Issue Evaluation Token');

        // 5. Counselor issues a one-time token for the student + episode (201)
        $issued = $this->postAs($this->counselorUser, 'guidance/evaluations/issue', [
            'person_id'       => (int) $this->studentUser['person_id'],
            'episode_id'      => (int) $episode['id'],
            'evaluation_type' => GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
        ], true);
        $issued->assertStatus(201);
        $issuedJson = json_decode((string) $issued->getJSON(), true);
        $this->assertTrue((bool) ($issuedJson['success'] ?? false));
        $rawToken = (string) ($issuedJson['raw_token'] ?? '');
        $redeemUrl = (string) ($issuedJson['redeem_url'] ?? '');
        $this->assertNotEmpty($rawToken);
        $this->assertStringContainsString('/portal/evaluations/redeem/' . $rawToken, $redeemUrl);
        $this->assertSame(GuidanceServiceEvaluationService::FORM_CODE_PSYCHOSOCIAL, $issuedJson['form_code'] ?? null);

        // 6. Duplicate issuance for the same student + episode + type returns 409
        $dup = $this->postAs($this->counselorUser, 'guidance/evaluations/issue', [
            'person_id'       => (int) $this->studentUser['person_id'],
            'episode_id'      => (int) $episode['id'],
            'evaluation_type' => GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
        ], true);
        $this->assertSame(409, $dup->response()->getStatusCode());

        // 7. Student can redeem via segment route OR by pasting the full link into ?token=...
        $directPage = $this->withSession($this->sessionFor($this->studentUser))
            ->get('portal/evaluations/redeem/' . urlencode($rawToken));
        $directPage->assertStatus(200);
        $directPage->assertSee('Submit Pseudonymous Evaluation');

        $pastedUrlPage = $this->withSession($this->sessionFor($this->studentUser))
            ->get('portal/evaluations/redeem?token=' . urlencode($redeemUrl));
        $pastedUrlPage->assertStatus(200);
        $pastedUrlPage->assertSee('Submit Pseudonymous Evaluation');

        // 8. Submitting consumes the token and subsequent visits report it already redeemed
        $submitted = $this->postAs($this->studentUser, 'portal/evaluations/submit', [
            'raw_token'                => $rawToken,
            'visit_type'               => 'walk_in',
            'rating_welcoming'         => 5,
            'rating_empathy'           => 5,
            'rating_clarity'           => 4,
            'rating_helpfulness'       => 5,
            'rating_environment'       => 5,
            'future_visit_willingness' => '1',
            'qualitative_comments'     => '',
        ], true);
        $submitted->assertStatus(201);

        $usedPage = $this->withSession($this->sessionFor($this->studentUser))
            ->get('portal/evaluations/redeem/' . urlencode($rawToken));
        $usedPage->assertStatus(200);
        $usedPage->assertSee('Evaluation Token Already Redeemed');
    }

    public function testMiStudentSearchEndpointSupportsMultiTokenFullDirectoryAndAuthorization(): void
    {
        $zoe = $this->createUserFixture('zoe', 'student');
        $zeke = $this->createUserFixture('zeke', 'student');

        $this->db->table('people')->where('id', (int) $zoe['person_id'])->update([
            'last_name' => 'Zebra_' . $this->suffix,
        ]);
        $this->db->table('student_profiles')->where('person_id', (int) $zoe['person_id'])->update([
            'program'    => 'BSBA',
            'year_level' => '2',
        ]);

        $this->db->table('people')->where('id', (int) $zeke['person_id'])->update([
            'last_name' => 'Zulu_' . $this->suffix,
        ]);
        $this->db->table('student_profiles')->where('person_id', (int) $zeke['person_id'])->update([
            'program'    => 'BSIT',
            'year_level' => '4',
        ]);

        // Assign active MI survey only to Zoe so has_active differs between Zoe and Zeke.
        $assignResp = $this->postAs($this->counselorUser, 'guidance/assessments/mi/assign', [
            'person_id' => (int) $zoe['person_id'],
        ], true);
        $assignResp->assertStatus(201);

        // 1. Students are forbidden from calling the counselor student search endpoint (403)
        $forbidden = $this->withSession($this->sessionFor($this->studentUser))
            ->withHeaders(['Accept' => 'application/json'])
            ->get('guidance/assessments/mi/students/search?q=Zoe');
        $this->assertSame(403, $forbidden->response()->getStatusCode());

        // 2. Counselor searches "First Last" (reverse of display order "Last, First")
        $byFullName = $this->withSession($this->sessionFor($this->counselorUser))
            ->withHeaders(['Accept' => 'application/json'])
            ->get('guidance/assessments/mi/students/search?q=' . urlencode('Zoe Zebra_' . $this->suffix));
        $byFullName->assertStatus(200);
        $byFullNameJson = json_decode((string) $byFullName->getJSON(), true);
        $this->assertTrue((bool) ($byFullNameJson['success'] ?? false));
        $this->assertSame(1, (int) ($byFullNameJson['total_matches'] ?? 0));
        $this->assertSame((int) $zoe['person_id'], (int) ($byFullNameJson['results'][0]['id'] ?? 0));
        $this->assertTrue((bool) ($byFullNameJson['results'][0]['has_active'] ?? false));
        $this->assertSame('BSBA', $byFullNameJson['results'][0]['program'] ?? null);

        // 3. Counselor searches "First Last Program Y4" multi-token query
        $byProgramAndYear = $this->withSession($this->sessionFor($this->counselorUser))
            ->withHeaders(['Accept' => 'application/json'])
            ->get('guidance/assessments/mi/students/search?q=' . urlencode('Zeke Zulu_' . $this->suffix . ' BSIT Y4'));
        $byProgramAndYear->assertStatus(200);
        $byProgramJson = json_decode((string) $byProgramAndYear->getJSON(), true);
        $this->assertSame(1, (int) ($byProgramJson['total_matches'] ?? 0));
        $this->assertSame((int) $zeke['person_id'], (int) ($byProgramJson['results'][0]['id'] ?? 0));
        $this->assertFalse((bool) ($byProgramJson['results'][0]['has_active'] ?? true));

        // 4. Counselor searches by exact MIS ID
        $zekeRow = $this->db->table('people')->select('mis_id')->where('id', (int) $zeke['person_id'])->get()->getRowArray();
        $byMisId = $this->withSession($this->sessionFor($this->counselorUser))
            ->withHeaders(['Accept' => 'application/json'])
            ->get('guidance/assessments/mi/students/search?q=' . urlencode((string) ($zekeRow['mis_id'] ?? '')));
        $byMisId->assertStatus(200);
        $byMisJson = json_decode((string) $byMisId->getJSON(), true);
        $this->assertSame(1, (int) ($byMisJson['total_matches'] ?? 0));
        $this->assertSame((int) $zeke['person_id'], (int) ($byMisJson['results'][0]['id'] ?? 0));

        // 5. Empty result set for unmatched query
        $noMatch = $this->withSession($this->sessionFor($this->counselorUser))
            ->withHeaders(['Accept' => 'application/json'])
            ->get('guidance/assessments/mi/students/search?q=' . urlencode('NoSuchStudent_' . $this->suffix));
        $noMatch->assertStatus(200);
        $noMatchJson = json_decode((string) $noMatch->getJSON(), true);
        $this->assertSame(0, (int) ($noMatchJson['total_matches'] ?? -1));
        $this->assertSame([], $noMatchJson['results'] ?? null);

        // 6. Query > 150 chars is rejected with 422
        $tooLong = $this->withSession($this->sessionFor($this->counselorUser))
            ->withHeaders(['Accept' => 'application/json'])
            ->get('guidance/assessments/mi/students/search?q=' . str_repeat('a', 151));
        $this->assertSame(422, $tooLong->response()->getStatusCode());
    }

    private function enableGate(string $gateKey): void
    {
        $svc = new GuidanceActivationGateService(db: $this->db);
        foreach (GuidanceActivationGateService::VALID_AUTHORITY_ROLES as $role) {
            $svc->recordSignoff($gateKey, $role, 'Approved in web workflow test', 1, $this->supervisorUser);
        }
        $this->assertTrue($svc->isGateEnabled($gateKey, 1));
    }

    private function postAs(array $user, string $uri, array $params = [], bool $ajaxJson = false)
    {
        $security = Services::security();
        $hash     = $security->generateHash();
        $cookie   = config('Cookie')->prefix . config('Security')->cookieName;
        service('superglobals')->setCookie($cookie, $hash);
        Services::resetSingle('security');

        $headers = ['X-CSRF-TOKEN' => $hash];
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
                'program'    => 'BSIT',
                'year_level' => '3',
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

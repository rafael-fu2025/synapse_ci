<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\Api\ApiContext;
use App\Services\Api\TokenService;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceActivationGateService;
use App\Services\Guidance\GuidanceAppointmentService;
use App\Services\Guidance\GuidanceCallSlipService;
use App\Services\Guidance\GuidanceEpisodeService;
use App\Services\Guidance\GuidanceFormDefinitionService;
use App\Services\Guidance\GuidanceMultipleIntelligenceService;
use App\Services\Guidance\GuidanceServiceEvaluationService;
use Config\Database;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceMobileApiV1Test extends FreshDatabaseTestCase
{
    use FeatureTestTrait;

    private string $suffix;
    private array $studentUser;
    private array $counselorUser;
    private array $supervisorUser;
    private string $studentToken;
    private string $counselorToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db     = Database::connect();
        $this->suffix = bin2hex(random_bytes(4));
        (new PermissionsAndRolesSeeder(new Database()))->run();
        (new GuidanceFormDefinitionService())->syncManifestToDatabase(1);
        $this->resetActivationGates();
        ApiContext::clear();
        $_SESSION = [];
        $_COOKIE  = [];
        CurrentTenant::set(1);

        $this->studentUser    = $this->createUserFixture('mobstu', 'student');
        $this->counselorUser  = $this->createUserFixture('mobcoun', 'counsellor', 'licensed_counselor');
        $this->supervisorUser = $this->createUserFixture('mobsup', 'guidance_supervisor', 'guidance_supervisor');

        $tokenService = new TokenService();
        $authUsers    = new AuthenticatedUser();

        $studentIssued       = $tokenService->issue($authUsers->forIdentity($this->studentUser['id'], 1), 'mobile-student-test', '127.0.0.1');
        $counselorIssued     = $tokenService->issue($authUsers->forIdentity($this->counselorUser['id'], 1), 'mobile-counselor-test', '127.0.0.1');
        $this->studentToken  = $studentIssued['access_token'];
        $this->counselorToken = $counselorIssued['access_token'];
    }

    protected function tearDown(): void
    {
        $this->resetActivationGates();
        ApiContext::clear();
        $_SESSION = [];
        $_COOKIE  = [];
        CurrentTenant::reset();
        parent::tearDown();
    }

    public function testMobileApiV1StudentWorkflowsAndClinicalBoundaryRejections(): void
    {
        // Seed student dashboard data: appointment, call slip, MI assignment, evaluation token
        $epService = new GuidanceEpisodeService(db: $this->db);
        $episode   = $epService->openEpisode(
            (int) $this->studentUser['person_id'],
            [
                'primary_counselor_user_id' => (int) $this->counselorUser['id'],
                'actor'                     => $this->counselorUser,
            ],
            1
        );

        $apptService = new GuidanceAppointmentService(db: $this->db);
        $appt        = $apptService->scheduleAppointment([
            'person_id'         => $this->studentUser['person_id'],
            'scheduled_date'    => date('Y-m-d', strtotime('+2 days')),
            'scheduled_time'    => '11:00',
            'purpose'           => 'counseling',
            'counselor_user_id' => $this->counselorUser['id'],
            'actor'             => $this->counselorUser,
        ], 1);

        $csService = new GuidanceCallSlipService(db: $this->db);
        $slip      = $csService->issueCallSlip([
            'person_id' => $this->studentUser['person_id'],
            'episode_id'=> (int) $episode['id'],
            'call_date' => date('Y-m-d', strtotime('+1 day')),
            'call_time' => '13:30',
            'purpose'   => 'routine_interview',
            'actor'     => $this->counselorUser,
        ], 1);
        $callSlipId = (int) $slip['id'];

        $miService    = new GuidanceMultipleIntelligenceService(db: $this->db);
        $assignment   = $miService->assignSurvey((int) $this->studentUser['person_id'], ['actor' => $this->counselorUser], 1, $this->counselorUser);
        $assignmentId = (int) $assignment['id'];

        $evalService = new GuidanceServiceEvaluationService(db: $this->db);
        $issuedEval  = $evalService->issueEvaluationToken(
            (int) $this->studentUser['person_id'],
            GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
            (int) $this->counselorUser['id'],
            ['episode_id' => (int) $episode['id'], 'academic_term' => '1st Semester', 'academic_year' => '2026-2027'],
            1
        );

        // 1. GET api/v1/guidance/dashboard
        $dashResp = $this->apiGet($this->studentToken, 'api/v1/guidance/dashboard');
        $dashResp->assertStatus(200);
        $dashJson = json_decode((string) $dashResp->getJSON(), true);
        $dashData = $dashJson['data'] ?? [];
        $this->assertNotEmpty($dashData['appointments'] ?? []);
        $this->assertNotEmpty($dashData['call_slips'] ?? []);
        $this->assertNotEmpty($dashData['mi_assignments'] ?? []);
        $this->assertNotEmpty($dashData['evaluation_tokens'] ?? []);

        $rawDashJson = (string) $dashResp->getJSON();
        $this->assertStringNotContainsString('clinical_notes', $rawDashJson);
        $this->assertStringNotContainsString('safety_details', $rawDashJson);
        $this->assertStringNotContainsString('notes_cipher', $rawDashJson);
        $this->assertStringNotContainsString('triage_level', $rawDashJson);

        // 2. POST api/v1/guidance/call-slips/{id}/acknowledge
        $ackResp = $this->apiPost($this->studentToken, "api/v1/guidance/call-slips/{$callSlipId}/acknowledge", []);
        $ackResp->assertStatus(200);
        $ackedSlip = $this->db->table('guidance_call_slips')->where('id', $callSlipId)->get()->getRowArray();
        $this->assertNotEmpty($ackedSlip['acknowledged_at']);

        // 3. POST api/v1/guidance/self-referral
        $this->enableGate(GuidanceActivationGateService::GATE_CANONICAL_FORMS);
        AuthenticatedUser::clear();
        $refResp = $this->apiPost($this->studentToken, 'api/v1/guidance/self-referral', [
            'selections'    => ['emotional_distress'],
            'narrative'     => 'Requesting guidance check-in for workload balance.',
            'priority_hint' => 'routine',
        ]);
        $this->assertSame(201, $refResp->response()->getStatusCode(), 'self-referral response: ' . (string) $refResp->getJSON());
        $refJson = json_decode((string) $refResp->getJSON(), true);
        $this->assertNotEmpty($refJson['data']['id'] ?? null);

        // 4. GET api/v1/guidance/mi, POST draft, POST finalize, GET results
        $miListResp = $this->apiGet($this->studentToken, 'api/v1/guidance/mi');
        $miListResp->assertStatus(200);
        $miListJson = json_decode((string) $miListResp->getJSON(), true);
        $this->assertNotEmpty($miListJson['data']['assignments'] ?? []);

        $draftResp = $this->apiPost($this->studentToken, "api/v1/guidance/mi/{$assignmentId}/draft", [
            'responses' => [1 => 3, 2 => 4],
        ]);
        $this->assertSame(200, $draftResp->response()->getStatusCode(), 'mi draft response: ' . (string) $draftResp->getJSON());

        $responses = [];
        for ($i = 1; $i <= 80; $i++) {
            $responses[$i] = ($i % 5);
        }

        // Gate OFF -> finalize returns 403
        $blockedFinalize = $this->apiPost($this->studentToken, "api/v1/guidance/mi/{$assignmentId}/finalize", [
            'responses'             => $responses,
            'attestation_statement' => 'I attest these responses are my own.',
            'attestation_confirmed' => true,
        ]);
        $this->assertSame(403, $blockedFinalize->response()->getStatusCode());

        // Gate ON -> finalize returns 200 and results are readable
        $this->enableGate(GuidanceActivationGateService::GATE_MI_REPRODUCTION);
        $finalizeResp = $this->apiPost($this->studentToken, "api/v1/guidance/mi/{$assignmentId}/finalize", [
            'responses'             => $responses,
            'attestation_statement' => 'I attest these responses are my own.',
            'attestation_confirmed' => true,
        ]);
        $finalizeResp->assertStatus(200);
        $finJson      = json_decode((string) $finalizeResp->getJSON(), true);
        $submissionId = (int) ($finJson['data']['submission']['id'] ?? $finJson['data']['submission_id'] ?? 0);
        $this->assertGreaterThan(0, $submissionId);

        $resResp = $this->apiGet($this->studentToken, "api/v1/guidance/mi/results/{$submissionId}");
        $resResp->assertStatus(200);

        // 5. POST api/v1/guidance/evaluations/submit
        $evalResp = $this->apiPost($this->studentToken, 'api/v1/guidance/evaluations/submit', [
            'raw_token'                => $issuedEval['raw_token'],
            'visit_type'               => 'walk_in',
            'rating_welcoming'         => 5,
            'rating_empathy'           => 5,
            'rating_clarity'           => 4,
            'rating_helpfulness'       => 5,
            'rating_environment'       => 5,
            'future_visit_willingness' => true,
            'qualitative_comments'     => 'Mobile evaluation feedback ' . $this->suffix,
        ]);
        $evalResp->assertStatus(201);

        // 6. Hard 403 mobile boundaries even with counselor Bearer token
        foreach ([$this->studentToken, $this->counselorToken] as $token) {
            $this->assertSame(403, $this->apiPost($token, 'api/v1/guidance/clinical/author', ['note' => 'blocked'])->response()->getStatusCode());
            $this->assertSame(403, $this->apiPost($token, 'api/v1/guidance/safety/assess', ['risk' => 'blocked'])->response()->getStatusCode());
            $this->assertSame(403, $this->apiPost($token, 'api/v1/guidance/cosign/1', ['attest' => true])->response()->getStatusCode());
            $this->assertSame(403, $this->apiGet($token, 'api/v1/guidance/cases')->response()->getStatusCode());
        }
    }

    private function apiGet(string $token, string $uri)
    {
        service('superglobals')->setCookie(config('Session')->cookieName, '');
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])->get($uri);
    }

    private function apiPost(string $token, string $uri, array $payload)
    {
        service('superglobals')->setCookie(config('Session')->cookieName, '');
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ])->withBodyFormat('json')->post($uri, $payload);
    }

    private function enableGate(string $gateKey): void
    {
        $svc = new GuidanceActivationGateService(db: $this->db);
        foreach (GuidanceActivationGateService::VALID_AUTHORITY_ROLES as $role) {
            $svc->recordSignoff($gateKey, $role, 'Approved in mobile API test', 1, $this->supervisorUser);
        }
        $this->assertTrue($svc->isGateEnabled($gateKey, 1));
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
                'program'    => 'BSECE',
                'year_level' => '1',
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

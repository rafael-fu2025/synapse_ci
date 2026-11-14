<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\GuidanceGovernanceController;
use App\Controllers\GuidanceIntakeController;
use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceActivationGateService;
use App\Services\Guidance\GuidanceIntakeService;
use App\Services\Guidance\GuidancePractitionerService;
use App\Services\Guidance\GuidanceSafetyService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use DomainException;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceGovernanceIntakeControllerTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private PersonModel $personModel;
    private StudentProfileModel $studentModel;
    private UserModel $userModel;
    private GuidanceActivationGateService $gateService;
    private GuidancePractitionerService $practitionerService;
    private GuidanceIntakeService $intakeService;
    private GuidanceSafetyService $safetyService;

    private int $supervisorUserId;
    private int $associateUserId;
    private int $facultyUserId;
    private int $studentPersonId;
    private string $studentMisId = '2026-9001';

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        $this->personModel         = new PersonModel($this->db);
        $this->studentModel        = new StudentProfileModel($this->db);
        $this->userModel           = new UserModel($this->db);
        $this->gateService         = new GuidanceActivationGateService(db: $this->db);
        $this->practitionerService = new GuidancePractitionerService(db: $this->db);
        $this->intakeService       = new GuidanceIntakeService(db: $this->db);
        $this->safetyService       = new GuidanceSafetyService(db: $this->db);

        // Ensure clean gate state between test methods when $refresh = false
        if ($this->db->tableExists('guidance_activation_gates')) {
            $this->db->table('guidance_activation_gates')->where('tenant_id', 1)->update([
                'recorded_signoffs_json' => '[]',
                'is_enabled'             => 0,
                'approved_at'            => null,
            ]);
        }

        // Seed tenant 1 if missing
        if ($this->db->table('tenants')->where('id', 1)->countAllResults() === 0) {
            $this->db->table('tenants')->insert([
                'id'         => 1,
                'name'       => 'Foundation University',
                'slug'       => 'fu-main',
                'is_active'  => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $suffix = strtoupper(bin2hex(random_bytes(3)));
        $this->studentMisId = '2026-' . $suffix;

        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'mis_id'     => $this->studentMisId,
            'kind'       => 'student',
            'first_name' => 'Maria',
            'last_name'  => 'Santos',
            'email'      => 'maria.santos.' . $suffix . '@example.edu',
        ], true);

        $this->studentModel->insert([
            'tenant_id'  => 1,
            'person_id'  => $this->studentPersonId,
            'program'    => 'BS Psychology',
            'year_level' => 3,
            'department' => 'CAS',
        ]);

        $this->supervisorUserId = $this->createUser('sup_guidance_' . $suffix, 'guidance_supervisor', 'Elena', 'Reyes', 'SUP-' . $suffix);
        $this->associateUserId  = $this->createUser('assoc_guidance_' . $suffix, 'counsellor', 'Carlo', 'Mendoza', 'ASC-' . $suffix);
        $this->facultyUserId    = $this->createUser('fac_teacher_' . $suffix, 'employee', 'Rosa', 'Cruz', 'FAC-' . $suffix);
    }

    private function createUser(string $username, string $role, string $first, string $last, string $misId): int
    {
        $personId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'mis_id'     => $misId,
            'kind'       => 'employee',
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $username . '@example.edu',
        ], true);

        return (int) $this->userModel->insert([
            'tenant_id'     => 1,
            'person_id'     => $personId,
            'username'      => $username,
            'email'         => $username . '@example.edu',
            'password_hash' => password_hash('Secret123!', PASSWORD_BCRYPT),
            'role'          => $role,
            'status'        => 'active',
            'auth_version'  => 1,
        ], true);
    }

    private function buildRequest(string $method, array $post = [], array $get = [], bool $ajax = true): array
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod($method);
        foreach (['HX-Request', 'X-Requested-With', 'Accept', 'Content-Type'] as $h) {
            $request->removeHeader($h);
        }
        if ($ajax) {
            $request->setHeader('X-Requested-With', 'XMLHttpRequest');
            $request->setHeader('Accept', 'application/json');
        }
        $request->setGlobal('post', $post);
        $request->setGlobal('get', $get);
        Services::injectMock('request', $request);

        $response = new Response(config('App'));
        Services::injectMock('response', $response);

        return [$request, $response];
    }

    private function actorResolverFor(int $userId): callable
    {
        $policy = new GuidanceAccessPolicy(
            identitySource: fn (): array => ['id' => $userId, 'tenant_id' => 1],
            db: $this->db
        );

        return static fn (?string $permission = null): array => $policy->current($permission);
    }

    private function portalActorFor(int $userId): callable
    {
        $user = $this->userModel->find($userId);
        $perms = $this->userModel->getPermissionsForRole((string) $user['role']);
        return static fn (): array => [
            'id'          => (int) $user['id'],
            'person_id'   => (int) $user['person_id'],
            'tenant_id'   => 1,
            'username'    => (string) $user['username'],
            'role'        => (string) $user['role'],
            'permissions' => $perms,
            'logged_in'   => true,
        ];
    }

    private function makeGovernanceController(int $userId, string $method, array $post = [], bool $ajax = true): GuidanceGovernanceController
    {
        [$request, $response] = $this->buildRequest($method, $post, [], $ajax);
        $controller = new GuidanceGovernanceController(
            gateService: $this->gateService,
            practitionerService: $this->practitionerService,
            accessPolicy: new GuidanceAccessPolicy(db: $this->db),
            db: $this->db,
            actorResolver: $this->actorResolverFor($userId)
        );
        $controller->initController($request, $response, new NullLogger());
        return $controller;
    }

    private function makeIntakeController(int $guidanceUserId, int $portalUserId, string $method, array $post = [], array $get = [], bool $ajax = true): GuidanceIntakeController
    {
        [$request, $response] = $this->buildRequest($method, $post, $get, $ajax);
        $controller = new GuidanceIntakeController(
            intakeService: $this->intakeService,
            safetyService: $this->safetyService,
            gateService: $this->gateService,
            accessPolicy: new GuidanceAccessPolicy(db: $this->db),
            db: $this->db,
            actorResolver: $this->actorResolverFor($guidanceUserId),
            portalActorResolver: $this->portalActorFor($portalUserId)
        );
        $controller->initController($request, $response, new NullLogger());
        return $controller;
    }

    public function testGovernanceGatesAndPractitionerWorkflow(): void
    {
        // 1. GET gates sets private, no-store and lists the 6 default gates
        $gov = $this->makeGovernanceController($this->supervisorUserId, 'GET', [], false);
        $html = $gov->gates();
        $this->assertIsString($html);
        $this->assertStringContainsString('canonical_forms_signoff', $html);
        $this->assertStringContainsString('safety_escalation_sop_signoff', $html);

        // 2. Signoff requires confirmed=1
        $gov = $this->makeGovernanceController($this->supervisorUserId, 'POST', [
            'authority_role' => 'guidance_head',
            'notes'          => 'Approved',
        ]);
        $res = $gov->signOffGate('canonical_forms_signoff');
        $this->assertSame(422, $res->getStatusCode());

        // 3. Multi-authority signoffs enable gate only on 3rd authority
        foreach (['guidance_head', 'qa_office', 'dpo'] as $idx => $role) {
            $gov = $this->makeGovernanceController($this->supervisorUserId, 'POST', [
                'confirmed'      => '1',
                'authority_role' => $role,
                'notes'          => 'Signed by ' . $role,
            ]);
            $res = $gov->signOffGate('canonical_forms_signoff');
            $this->assertSame(200, $res->getStatusCode());
            $this->assertStringContainsString('no-store', $res->getHeaderLine('Cache-Control'));
            $json = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue($json['success']);
            $this->assertArrayHasKey('csrf', $json);

            if ($idx < 2) {
                $this->assertFalse($this->gateService->isGateEnabled('canonical_forms_signoff', 1));
            } else {
                $this->assertTrue($this->gateService->isGateEnabled('canonical_forms_signoff', 1));
            }
        }

        // 4. Register practitioners and assign supervision
        $gov = $this->makeGovernanceController($this->supervisorUserId, 'POST', [
            'user_id'         => $this->supervisorUserId,
            'credential_tier' => 'guidance_supervisor',
            'license_number'  => 'RGC-001234',
            'service_scope'   => ['intake', 'counseling', 'safety_triage'],
        ]);
        $this->assertSame(200, $gov->registerPractitioner()->getStatusCode());

        $gov = $this->makeGovernanceController($this->supervisorUserId, 'POST', [
            'user_id'         => $this->associateUserId,
            'credential_tier' => 'counselor_associate',
            'service_scope'   => ['intake', 'counseling'],
        ]);
        $this->assertSame(200, $gov->registerPractitioner()->getStatusCode());

        $gov = $this->makeGovernanceController($this->supervisorUserId, 'POST', [
            'associate_user_id'  => $this->associateUserId,
            'supervisor_user_id' => $this->supervisorUserId,
            'co_sign_required'   => '1',
        ]);
        $this->assertSame(200, $gov->assignSupervisor()->getStatusCode());

        $gov = $this->makeGovernanceController($this->supervisorUserId, 'GET', [], false);
        $pracHtml = $gov->practitioners();
        $this->assertStringContainsString('RGC-001234', $pracHtml);
        $this->assertStringContainsString('sup_guidance', $pracHtml);
        $this->assertStringContainsString('assoc_guidance', $pracHtml);
    }

    public function testPortalReferralExactMisResolutionGateAndSafetyTriage(): void
    {
        // Register supervisor as qualified practitioner
        $this->practitionerService->registerProfile(
            $this->supervisorUserId,
            'guidance_supervisor',
            'RGC-00999',
            null,
            ['intake', 'counseling', 'safety_triage'],
            ['id' => $this->supervisorUserId, 'tenant_id' => 1]
        );

        // 1. Wildcard lookup strictly rejected
        $ctrl = $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'POST', [
            'mis_id' => '2026-%',
        ]);
        $res = $ctrl->resolveSubject();
        $this->assertSame(404, $res->getStatusCode());

        // 2. Exact MIS ID resolves person
        $ctrl = $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'POST', [
            'mis_id' => $this->studentMisId,
        ]);
        $res = $ctrl->resolveSubject();
        $this->assertSame(200, $res->getStatusCode());
        $payload = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $this->assertSame($this->studentPersonId, $payload['person']['person_id']);

        // 3. Live portal submission blocked when canonical_forms_signoff gate is disabled
        $ctrl = $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'POST', [
            'source_type'       => 'employee_teacher',
            'subject_person_id' => $this->studentPersonId,
            'reasons'           => ['emotional_distress'],
            'reasons_narrative' => 'Observed severe distress during exam week.',
            'priority_hint'     => 'urgent',
            'action'            => 'submit',
        ]);
        $blocked = $ctrl->portalStore();
        $this->assertSame(403, $blocked->getStatusCode());

        // 4. Enable canonical_forms_signoff and submit live referral
        foreach (['guidance_head', 'qa_office', 'dpo'] as $role) {
            $this->gateService->recordSignoff('canonical_forms_signoff', $role, 'OK', 1, $this->supervisorUserId);
        }
        $ctrl = $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'POST', [
            'source_type'       => 'employee_teacher',
            'subject_person_id' => $this->studentPersonId,
            'reasons'           => ['emotional_distress'],
            'reasons_narrative' => 'Observed severe distress during exam week.',
            'priority_hint'     => 'urgent',
            'action'            => 'submit',
        ]);
        $submitted = $ctrl->portalStore();
        $this->assertSame(200, $submitted->getStatusCode());

        $row = $this->db->table('guidance_intake_referrals')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertNotNull($row);
        $refId = (int) $row['id'];
        $this->assertSame('submitted', $row['status']);

        // 5. Guidance intake index & show render decrypted referral
        $indexHtml = $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'GET', [], [], false)->index();
        $this->assertStringContainsString($row['referral_number'], $indexHtml);

        $viewHtml = $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'GET', [], [], false)->show($refId);
        $this->assertStringContainsString('Observed severe distress during exam week.', $viewHtml);

        // 6. Receive -> Triage -> Accept
        $this->assertSame(200, $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'POST')->receive($refId)->getStatusCode());
        $this->assertSame(200, $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'POST', [
            'priority_hint' => 'crisis',
            'triage_notes'  => 'Immediate check-in scheduled',
        ])->triage($refId)->getStatusCode());
        $this->assertSame(200, $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'POST', [
            'assigned_counsellor_user_id' => $this->supervisorUserId,
        ])->accept($refId)->getStatusCode());

        // 7. Safety assessment requires practitioner eligibility (associate without profile is rejected)
        $unregRes = $this->makeIntakeController($this->associateUserId, $this->facultyUserId, 'POST', [
            'person_id'               => $this->studentPersonId,
            'intake_referral_id'      => $refId,
            'risk_level'              => 'high',
            'self_harm_risk'          => '1',
            'indicators'              => 'Acute distress',
            'immediate_actions_taken' => 'Walked student to guidance office',
        ])->recordSafetyAssessment();
        $this->assertSame(403, $unregRes->getStatusCode());

        // Qualified supervisor succeeds
        $assessRes = $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'POST', [
            'person_id'               => $this->studentPersonId,
            'intake_referral_id'      => $refId,
            'risk_level'              => 'high',
            'self_harm_risk'          => '1',
            'indicators'              => 'Acute distress',
            'immediate_actions_taken' => 'Immediate stabilization interview conducted',
            'safety_plan'             => 'Daily check-in with supervisor',
        ])->recordSafetyAssessment();
        $this->assertSame(200, $assessRes->getStatusCode());

        $assessment = $this->db->table('guidance_safety_assessments')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertNotNull($assessment);
        $assessmentId = (int) $assessment['id'];

        // 8. Safety escalation blocked until safety_escalation_sop_signoff gate is enabled
        $escBlocked = $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'POST', [
            'intake_referral_id'         => $refId,
            'safety_assessment_id'       => $assessmentId,
            'escalation_channel'         => 'internal_crisis_team',
            'recipient_agency_or_person' => 'Campus Crisis Response Team',
            'summary_notes'              => 'High risk student requiring crisis team handoff',
        ])->recordEscalation();
        $this->assertSame(403, $escBlocked->getStatusCode());

        foreach (['guidance_head', 'qa_office', 'dpo'] as $role) {
            $this->gateService->recordSignoff('safety_escalation_sop_signoff', $role, 'SOP approved', 1, $this->supervisorUserId);
        }

        $escOk = $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'POST', [
            'intake_referral_id'         => $refId,
            'safety_assessment_id'       => $assessmentId,
            'escalation_channel'         => 'internal_crisis_team',
            'recipient_agency_or_person' => 'Campus Crisis Response Team',
            'summary_notes'              => 'High risk student requiring crisis team handoff',
        ])->recordEscalation();
        $this->assertSame(200, $escOk->getStatusCode());

        $escRow = $this->db->table('guidance_safety_escalations')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertNotNull($escRow);
        $ackRes = $this->makeIntakeController($this->supervisorUserId, $this->facultyUserId, 'POST', [
            'intake_referral_id' => $refId,
            'acknowledged_by'    => 'Dr. Elena Reyes',
        ])->acknowledgeEscalation((int) $escRow['id']);
        $this->assertSame(200, $ackRes->getStatusCode());
    }
}

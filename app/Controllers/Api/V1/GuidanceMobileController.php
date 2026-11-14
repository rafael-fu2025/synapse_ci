<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Guidance\GuidanceActivationGateService;
use App\Services\Guidance\GuidanceIntakeService;
use App\Services\Guidance\GuidanceMobileContractService;
use App\Services\Guidance\GuidanceMultipleIntelligenceService;
use App\Services\Guidance\GuidanceServiceEvaluationService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DomainException;

/**
 * Mobile API V1 Guidance Student Self-Service & Boundary Controller.
 *
 * Strictly exposes student-safe mobile contracts and blocks workstation-only
 * clinical authoring, co-signing, safety triage, and case transfer actions with 403.
 */
final class GuidanceMobileController extends ApiV1Controller
{
    private GuidanceMobileContractService $mobileContractService;
    private GuidanceIntakeService $intakeService;
    private GuidanceMultipleIntelligenceService $miService;
    private GuidanceServiceEvaluationService $evaluationService;
    private GuidanceActivationGateService $gateService;

    public function __construct(
        ?GuidanceMobileContractService $mobileContractService = null,
        ?GuidanceIntakeService $intakeService = null,
        ?GuidanceMultipleIntelligenceService $miService = null,
        ?GuidanceServiceEvaluationService $evaluationService = null,
        ?GuidanceActivationGateService $gateService = null
    ) {
        $this->mobileContractService = $mobileContractService ?? new GuidanceMobileContractService();
        $this->intakeService = $intakeService ?? new GuidanceIntakeService();
        $this->miService = $miService ?? new GuidanceMultipleIntelligenceService();
        $this->evaluationService = $evaluationService ?? new GuidanceServiceEvaluationService();
        $this->gateService = $gateService ?? new GuidanceActivationGateService();
    }

    /**
     * GET api/v1/guidance/dashboard
     */
    public function dashboard(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $this->mobileContractService->assertMobileActionPermitted('view_appointments');
            $identity = $this->requireStudentIdentity();

            $payload = $this->mobileContractService->getStudentDashboardPayload(
                (int) $identity['person_id'],
                (int) $identity['tenant_id'],
                $identity
            );

            return $this->ok(['data' => $payload]);
        });
    }

    /**
     * POST api/v1/guidance/call-slips/(:num)/acknowledge
     */
    public function acknowledgeCallSlip(int $callSlipId): ResponseInterface
    {
        return $this->run(function () use ($callSlipId): ResponseInterface {
            $this->mobileContractService->assertMobileActionPermitted('acknowledge_call_slip');
            $identity = $this->requireStudentIdentity();

            $acknowledged = $this->mobileContractService->acknowledgeCallSlipFromMobile(
                $callSlipId,
                (int) $identity['person_id'],
                (int) $identity['tenant_id'],
                $identity
            );

            return $this->ok(['data' => $acknowledged]);
        });
    }

    /**
     * POST api/v1/guidance/referrals/self
     */
    public function submitSelfReferral(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $identity = $this->requireStudentIdentity();
            $payload = $this->jsonBody();
            $this->assertNoBlockedActionInPayload($payload);
            $this->mobileContractService->assertMobileActionPermitted('submit_self_referral');

            $fields = [];
            $narrative = $payload['narrative'] ?? null;
            if (!is_string($narrative) || trim($narrative) === '') {
                $fields['narrative'] = ['Please describe the reason for your self-referral.'];
            }

            $selections = $payload['selections'] ?? [];
            if (!is_array($selections)) {
                $fields['selections'] = ['Concern selections must be an array.'];
            }

            $priorityHint = isset($payload['priority_hint']) && is_string($payload['priority_hint'])
                ? strtolower(trim($payload['priority_hint']))
                : 'routine';
            if (!in_array($priorityHint, GuidanceIntakeService::PRIORITY_HINTS, true)) {
                $fields['priority_hint'] = ['Priority hint must be routine, urgent, or crisis.'];
            }

            $priorActions = $payload['prior_actions'] ?? null;
            if ($priorActions !== null && !is_string($priorActions)) {
                $fields['prior_actions'] = ['Prior actions must be a string.'];
            }

            if ($fields !== []) {
                return $this->fail(422, 'validation_failed', 'Please review the highlighted fields.', $fields);
            }

            $draft = $this->intakeService->createDraft(
                (int) $identity['person_id'],
                'self',
                $selections,
                trim((string) $narrative),
                $priorActions !== null ? trim((string) $priorActions) : null,
                $priorityHint,
                $identity
            );

            $submitted = $this->intakeService->submitReferral((int) $draft['id'], $identity);

            return $this->ok([
                'data' => [
                    'id'                      => (int) ($submitted['id'] ?? 0),
                    'referral_number'         => (string) ($submitted['referral_number'] ?? ''),
                    'source_type'             => (string) ($submitted['source_type'] ?? 'self'),
                    'subject_person_id'       => (int) ($submitted['subject_person_id'] ?? 0),
                    'priority_hint'           => (string) ($submitted['priority_hint'] ?? 'routine'),
                    'status'                  => (string) ($submitted['status'] ?? 'submitted'),
                    'concern_catalog_version' => (string) ($submitted['concern_catalog_version'] ?? ''),
                    'selections'              => $submitted['selections'] ?? [],
                    'narrative'               => (string) ($submitted['narrative'] ?? ''),
                    'prior_actions'           => $submitted['prior_actions'] ?? null,
                    'submitted_at'            => $submitted['submitted_at'] ?? null,
                    'created_at'              => $submitted['created_at'] ?? null,
                ],
            ], 201);
        });
    }

    /**
     * GET api/v1/guidance/assessments/mi
     */
    public function miIndex(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $this->mobileContractService->assertMobileActionPermitted('take_mi_survey');
            $identity = $this->requireStudentIdentity();
            $tenantId = (int) $identity['tenant_id'];
            $personId = (int) $identity['person_id'];

            $assignments = Database::connect()->table('guidance_mi_assignments a')
                ->select('a.id, a.person_id, a.status, a.academic_term, a.academic_year, a.assigned_at, a.due_at, a.completed_at, s.id AS submission_id, s.status AS submission_status, s.attested_at')
                ->join('guidance_mi_submissions s', 's.assignment_id = a.id AND s.tenant_id = a.tenant_id AND s.deleted_at IS NULL', 'left')
                ->where('a.tenant_id', $tenantId)
                ->where('a.person_id', $personId)
                ->where('a.deleted_at', null)
                ->orderBy('a.id', 'DESC')
                ->get()
                ->getResultArray();

            return $this->ok([
                'data' => [
                    'assignments'                  => $assignments,
                    'catalog'                      => $this->miService->getSurveyCatalog(),
                    'mi_reproduction_gate_enabled' => $this->gateService->isGateEnabled(
                        GuidanceActivationGateService::GATE_MI_REPRODUCTION,
                        $tenantId
                    ),
                ],
            ]);
        });
    }

    /**
     * GET|POST api/v1/guidance/assessments/mi/(:num)/draft
     */
    public function miDraft(int $assignmentId): ResponseInterface
    {
        return $this->run(function () use ($assignmentId): ResponseInterface {
            $this->mobileContractService->assertMobileActionPermitted('take_mi_survey');
            $identity = $this->requireStudentIdentity();
            $tenantId = (int) $identity['tenant_id'];

            if (strtolower($this->request->getMethod()) === 'get') {
                $draft = $this->miService->getDraft($assignmentId, $tenantId, $identity) ?? [];
                return $this->ok([
                    'data' => [
                        'assignment_id'   => $assignmentId,
                        'saved_count'     => count($draft),
                        'draft_responses' => $draft,
                        'catalog'         => $this->miService->getSurveyCatalog(),
                    ],
                ]);
            }

            $payload = $this->jsonBody(32768);
            $this->assertNoBlockedActionInPayload($payload);

            $responses = $this->normalizeResponsesInput($payload['responses'] ?? null);
            if (!is_array($responses)) {
                return $this->fail(422, 'validation_failed', 'Survey draft responses are required.', [
                    'responses' => ['Provide an object mapping item numbers 1..80 to scores 0..4.'],
                ]);
            }

            $saved = $this->miService->saveDraft($assignmentId, $responses, $tenantId, $identity);
            $draftResponses = $saved['draft_responses'] ?? [];

            return $this->ok([
                'data' => [
                    'assignment_id'   => $assignmentId,
                    'saved_count'     => count($draftResponses),
                    'draft_responses' => $draftResponses,
                ],
            ]);
        });
    }

    /**
     * POST api/v1/guidance/assessments/mi/(:num)/finalize
     */
    public function miFinalize(int $assignmentId): ResponseInterface
    {
        return $this->run(function () use ($assignmentId): ResponseInterface {
            $this->mobileContractService->assertMobileActionPermitted('take_mi_survey');
            $identity = $this->requireStudentIdentity();
            $tenantId = (int) $identity['tenant_id'];

            $this->gateService->assertGateEnabled(GuidanceActivationGateService::GATE_MI_REPRODUCTION, $tenantId);

            $payload = $this->jsonBody(32768);
            $this->assertNoBlockedActionInPayload($payload);

            $signerCapacity = strtolower(trim((string) ($payload['signer_capacity'] ?? 'student')));
            $this->gateService->assertSignerCapacityAllowed($signerCapacity);
            if ($signerCapacity !== 'student') {
                throw new DomainException('Student mobile MI attestation must be executed by the student account holder.', 403);
            }

            $fields = [];
            $responses = $this->normalizeResponsesInput($payload['responses'] ?? null);
            if (!is_array($responses)) {
                $fields['responses'] = ['All 80 item responses (0..4) are required.'];
            }

            $statement = trim((string) ($payload['attestation_statement'] ?? ''));
            if ($statement === '') {
                $fields['attestation_statement'] = ['Student digital attestation statement is required.'];
            }

            if (array_key_exists('attestation_confirmed', $payload) && !$payload['attestation_confirmed']) {
                $fields['attestation_confirmed'] = ['Student digital attestation confirmation is required.'];
            }

            if ($fields !== []) {
                return $this->fail(422, 'validation_failed', 'Please complete all 80 items and digital attestation.', $fields);
            }

            $submission = $this->miService->finalizeAssessment(
                $assignmentId,
                $responses,
                $statement,
                $tenantId,
                $identity
            );

            return $this->ok([
                'data' => [
                    'submission_id' => (int) $submission['id'],
                    'assignment_id' => $assignmentId,
                    'status'        => (string) $submission['status'],
                    'domain_scores' => $submission['domain_scores'],
                    'attested_at'   => $submission['attested_at'] ?? null,
                    'snapshot_hash' => $submission['snapshot_hash'] ?? null,
                ],
            ]);
        });
    }

    /**
     * GET api/v1/guidance/assessments/mi/results/(:num)
     */
    public function miResults(int $submissionId): ResponseInterface
    {
        return $this->run(function () use ($submissionId): ResponseInterface {
            $this->mobileContractService->assertMobileActionPermitted('view_mi_results');
            $identity = $this->requireStudentIdentity();

            $results = $this->miService->getResults(
                $submissionId,
                (int) $identity['tenant_id'],
                $identity
            );

            return $this->ok(['data' => $results]);
        });
    }

    /**
     * POST api/v1/guidance/evaluations/submit
     */
    public function submitEvaluation(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $identity = $this->requireStudentIdentity();
            $payload = $this->jsonBody();
            $this->assertNoBlockedActionInPayload($payload);
            $this->mobileContractService->assertMobileActionPermitted('submit_service_evaluation');

            $rawToken = trim((string) ($payload['raw_token'] ?? $payload['token'] ?? ''));
            if ($rawToken === '') {
                return $this->fail(422, 'validation_failed', 'Evaluation redemption token is required.', [
                    'token' => ['Provide a valid evaluation redemption token.'],
                ]);
            }

            $record = $this->evaluationService->submitEvaluation(
                $rawToken,
                $payload,
                (int) $identity['tenant_id'],
                $identity
            );

            return $this->ok([
                'data' => [
                    'evaluation_id'   => (int) ($record['evaluation_id'] ?? $record['id'] ?? 0),
                    'evaluation_type' => (string) ($record['evaluation_type'] ?? ''),
                    'form_code'       => (string) ($record['form_code'] ?? ''),
                    'submitted_date'  => (string) ($record['submitted_date'] ?? ''),
                ],
            ], 201);
        });
    }

    /**
     * Explicit boundary guard for any workstation-only action paths routed to mobile.
     * Rejects clinical_author, clinical_cosign, safety_triage, case_transfer with 403.
     */
    public function rejectWorkstationAction(string $action = 'clinical_author'): ResponseInterface
    {
        return $this->run(function () use ($action): ResponseInterface {
            $this->identity();
            $requested = trim($action);
            $body = (string) $this->request->getBody();
            if ($body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded) && !empty($decoded['action']) && is_string($decoded['action'])) {
                    $requested = trim($decoded['action']);
                }
            }
            $this->mobileContractService->assertMobileActionPermitted($requested);
            return $this->fail(403, 'forbidden', 'Clinical authoring and safety triage workflows are restricted to the web workstation.');
        });
    }

    /** Convert shallow-decoded stdClass objects (e.g. {"1": 3}) into associative arrays. */
    private function normalizeResponsesInput(mixed $responses): mixed
    {
        return $responses instanceof \stdClass ? (array) $responses : $responses;
    }

    /** @return array<string, mixed> */
    private function requireStudentIdentity(): array
    {
        $identity = $this->identity();
        if (empty($identity['person_id']) || (int) $identity['person_id'] <= 0) {
            throw new DomainException('An identified student profile is required for guidance mobile self-service.', 403);
        }
        return $identity;
    }

    /** @param array<string, mixed> $payload */
    private function assertNoBlockedActionInPayload(array $payload): void
    {
        if (isset($payload['action']) && is_string($payload['action']) && trim($payload['action']) !== '') {
            $this->mobileContractService->assertMobileActionPermitted($payload['action']);
        }
    }
}

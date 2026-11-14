<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\GuidanceMultipleIntelligenceAssignmentModel;
use App\Models\GuidanceMultipleIntelligenceSubmissionModel;
use App\Services\AuthenticatedUser;
use App\Services\Guidance\GuidanceActivationGateService;
use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Guidance\GuidanceMultipleIntelligenceService;
use App\Services\PortalContext;
use Closure;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GuidanceMultipleIntelligenceController extends BaseController
{
    public const DEFAULT_STUDENT_ATTESTATION = 'I attest under penalty of academic integrity that these 80 responses accurately reflect my own self-assessment.';

    private GuidanceMultipleIntelligenceService $miService;
    private GuidanceDocumentService $documentService;
    private GuidanceActivationGateService $gateService;
    private GuidanceMultipleIntelligenceAssignmentModel $assignmentModel;
    private GuidanceMultipleIntelligenceSubmissionModel $submissionModel;
    private ?Closure $actorResolver;

    public function __construct(
        ?GuidanceMultipleIntelligenceService $miService = null,
        ?GuidanceDocumentService $documentService = null,
        ?GuidanceActivationGateService $gateService = null,
        ?GuidanceMultipleIntelligenceAssignmentModel $assignmentModel = null,
        ?GuidanceMultipleIntelligenceSubmissionModel $submissionModel = null,
        ?callable $actorResolver = null
    ) {
        $this->miService = $miService ?? new GuidanceMultipleIntelligenceService();
        $this->documentService = $documentService ?? new GuidanceDocumentService();
        $this->gateService = $gateService ?? new GuidanceActivationGateService();
        $this->assignmentModel = $assignmentModel ?? new GuidanceMultipleIntelligenceAssignmentModel();
        $this->submissionModel = $submissionModel ?? new GuidanceMultipleIntelligenceSubmissionModel();
        $this->actorResolver = $actorResolver !== null ? Closure::fromCallable($actorResolver) : null;
    }

    /**
     * Guidance Counselor Console: list MI assignments, assign new survey, link results & PDF.
     */
    public function index()
    {
        $actor = $this->resolveWebActor('guidance.assessments.view', 'guidance');
        if (!is_array($actor)) {
            return $actor;
        }

        $tenantId = (int) $actor['tenant_id'];
        $db = Database::connect();

        $statusFilter = trim((string) ($this->request->getGet('status') ?? ''));
        if (!in_array($statusFilter, ['assigned', 'in_progress', 'completed', 'cancelled'], true)) {
            $statusFilter = '';
        }

        $builder = $db->table('guidance_mi_assignments a')
            ->select('a.*, p.first_name, p.middle_name, p.last_name, p.mis_id, sp.program, sp.year_level, s.id AS submission_id, s.status AS submission_status, s.attested_at')
            ->join('people p', 'p.id = a.person_id AND p.tenant_id = a.tenant_id')
            ->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = p.tenant_id', 'left')
            ->join('guidance_mi_submissions s', 's.assignment_id = a.id AND s.tenant_id = a.tenant_id AND s.deleted_at IS NULL', 'left')
            ->where('a.tenant_id', $tenantId)
            ->where('a.deleted_at', null)
            ->where('p.deleted_at', null);

        if ($statusFilter !== '') {
            $builder->where('a.status', $statusFilter);
        }

        $assignments = $builder->orderBy('a.id', 'DESC')->get()->getResultArray();

        $students = $db->table('people p')
            ->select('p.id, p.mis_id, p.first_name, p.middle_name, p.last_name, sp.program, sp.year_level')
            ->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = p.tenant_id', 'left')
            ->where('p.tenant_id', $tenantId)
            ->where('p.kind', 'student')
            ->where('p.deleted_at', null)
            ->orderBy('p.last_name', 'ASC')
            ->orderBy('p.first_name', 'ASC')
            ->limit(250)
            ->get()
            ->getResultArray();

        $miGate = $this->gateService->getGate(GuidanceActivationGateService::GATE_MI_REPRODUCTION, $tenantId);
        $miGateEnabled = $miGate !== null && (int) ($miGate['is_enabled'] ?? 0) === 1;

        $this->response->setHeader('Cache-Control', 'private, no-store');

        return view('guidance/assessments/mi_index', [
            'title'         => 'Multiple Intelligence Assessments',
            'pageTitle'     => 'Multiple Intelligence (80-Item) Registry',
            'assignments'   => $assignments,
            'students'      => $students,
            'statusFilter'  => $statusFilter,
            'miGate'        => $miGate,
            'miGateEnabled' => $miGateEnabled,
            'canAssign'     => $actor['role'] === 'superadmin' || in_array('guidance.assessments.assign', $actor['permissions'] ?? [], true),
            'breadcrumbs'   => [
                ['label' => 'Guidance', 'url' => site_url('/guidance')],
                ['label' => 'Multiple Intelligence', 'url' => ''],
            ],
        ]);
    }

    /**
     * Guidance Counselor Console: assign a new 80-item MI survey to a student.
     */
    public function assign()
    {
        $actor = $this->resolveWebActor('guidance.assessments.assign', 'guidance');
        if (!is_array($actor)) {
            return $actor;
        }

        $tenantId = (int) $actor['tenant_id'];

        try {
            $input = $this->requestInput();
            $personId = isset($input['person_id']) && is_numeric($input['person_id']) ? (int) $input['person_id'] : 0;

            if ($personId <= 0 && !empty($input['mis_id']) && is_string($input['mis_id'])) {
                $person = Database::connect()->table('people')
                    ->where('tenant_id', $tenantId)
                    ->where('mis_id', trim($input['mis_id']))
                    ->where('deleted_at', null)
                    ->get()
                    ->getRowArray();
                if ($person !== null) {
                    $personId = (int) $person['id'];
                }
            }

            if ($personId <= 0) {
                throw new InvalidArgumentException('Please select a valid student (person_id or exact MIS ID).', 422);
            }

            $options = [
                'academic_term'       => isset($input['academic_term']) ? trim((string) $input['academic_term']) : null,
                'academic_year'       => isset($input['academic_year']) ? trim((string) $input['academic_year']) : null,
                'due_at'              => !empty($input['due_at']) ? trim((string) $input['due_at']) : null,
                'assigned_by_user_id' => (int) $actor['id'],
            ];

            $created = $this->miService->assignSurvey($personId, $options, $tenantId, $actor);
            $message = 'Multiple Intelligence survey assigned successfully.';

            if ($this->wantsJson()) {
                return $this->response->setStatusCode(201)
                    ->setHeader('Cache-Control', 'private, no-store')
                    ->setJSON([
                        'success'    => true,
                        'message'    => $message,
                        'assignment' => $created,
                        'redirect'   => site_url('/guidance/assessments/mi'),
                        'csrf'       => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }

            return redirect()->to(site_url('/guidance/assessments/mi'))->with('success', $message);
        } catch (Throwable $e) {
            return $this->handleFailure($e, site_url('/guidance/assessments/mi'));
        }
    }

    /**
     * Guidance Counselor Console: view finalized 8-domain MI results.
     */
    public function counselorResults(int $submissionId)
    {
        $actor = $this->resolveWebActor('guidance.assessments.view', 'guidance');
        if (!is_array($actor)) {
            return $actor;
        }

        return $this->renderResultsView($submissionId, $actor, 'counselor');
    }

    /**
     * Guidance Counselor Console: download/stream MI Report PDF.
     */
    public function counselorPdf(int $submissionId)
    {
        $actor = $this->resolveWebActor('guidance.assessments.view', 'guidance');
        if (!is_array($actor)) {
            return $actor;
        }

        return $this->streamPdf($submissionId, $actor);
    }

    /**
     * Student Portal: list active and completed MI assignments for the signed-in student.
     */
    public function portalList()
    {
        $actor = $this->resolvePortalStudentActor('guidance.assessments.take');
        if (!is_array($actor)) {
            return $actor;
        }

        $tenantId = (int) $actor['tenant_id'];
        $personId = (int) ($actor['person_id'] ?? 0);
        if ($personId <= 0) {
            return AuthenticatedUser::denied($this->request, true, 'An identified student profile is required.', '/portal');
        }

        $db = Database::connect();
        $assignments = $db->table('guidance_mi_assignments a')
            ->select('a.*, s.id AS submission_id, s.status AS submission_status, s.attested_at')
            ->join('guidance_mi_submissions s', 's.assignment_id = a.id AND s.tenant_id = a.tenant_id AND s.deleted_at IS NULL', 'left')
            ->where('a.tenant_id', $tenantId)
            ->where('a.person_id', $personId)
            ->where('a.deleted_at', null)
            ->orderBy('a.id', 'DESC')
            ->get()
            ->getResultArray();

        foreach ($assignments as &$row) {
            $row['draft_count'] = 0;
            if (($row['status'] ?? '') !== 'completed') {
                $draft = $this->miService->getDraft((int) $row['id'], $tenantId, $actor);
                $row['draft_count'] = is_array($draft) ? count($draft) : 0;
            }
        }
        unset($row);

        $miGate = $this->gateService->getGate(GuidanceActivationGateService::GATE_MI_REPRODUCTION, $tenantId);
        $miGateEnabled = $miGate !== null && (int) ($miGate['is_enabled'] ?? 0) === 1;

        $this->response->setHeader('Cache-Control', 'private, no-store');

        return view('portal/assessments/mi_list', [
            'title'         => 'My Multiple Intelligence Assessments',
            'pageTitle'     => 'Multiple Intelligence Surveys',
            'assignments'   => $assignments,
            'miGate'        => $miGate,
            'miGateEnabled' => $miGateEnabled,
            'breadcrumbs'   => [
                ['label' => 'Campus Portal', 'url' => site_url('/portal')],
                ['label' => 'Multiple Intelligence', 'url' => ''],
            ],
        ]);
    }

    /**
     * Student Portal: render the 80-item Multiple Intelligence survey form.
     */
    public function portalTake(int $assignmentId)
    {
        $actor = $this->resolvePortalStudentActor('guidance.assessments.take');
        if (!is_array($actor)) {
            return $actor;
        }

        $tenantId = (int) $actor['tenant_id'];
        $personId = (int) ($actor['person_id'] ?? 0);

        $assignment = $this->assignmentModel->scoped($tenantId)->find($assignmentId);
        if ($assignment === null) {
            return $this->notFoundResponse('Multiple Intelligence assignment not found.');
        }

        if ($personId <= 0 || (int) $assignment['person_id'] !== $personId) {
            return AuthenticatedUser::denied($this->request, true, 'You may only access your own Multiple Intelligence assignment.', '/portal/assessments/mi');
        }

        if (($assignment['status'] ?? '') === 'completed') {
            $sub = $this->submissionModel->findFinalizedByAssignmentId($assignmentId, $tenantId);
            if ($sub !== null) {
                return redirect()->to(site_url('/portal/assessments/mi/results/' . (int) $sub['id']));
            }
        }

        if (($assignment['status'] ?? '') === 'cancelled') {
            return redirect()->to(site_url('/portal/assessments/mi'))->with('error', 'This assessment assignment has been cancelled.');
        }

        $catalog = $this->miService->getSurveyCatalog();
        $draft = $this->miService->getDraft($assignmentId, $tenantId, $actor) ?? [];
        $miGate = $this->gateService->getGate(GuidanceActivationGateService::GATE_MI_REPRODUCTION, $tenantId);
        $miGateEnabled = $miGate !== null && (int) ($miGate['is_enabled'] ?? 0) === 1;

        $this->response->setHeader('Cache-Control', 'private, no-store');

        return view('portal/assessments/mi_survey', [
            'title'               => 'Multiple Intelligences Survey (80 Items)',
            'pageTitle'           => 'Multiple Intelligences Survey',
            'assignment'          => $assignment,
            'catalog'             => $catalog,
            'draft'               => $draft,
            'miGate'              => $miGate,
            'miGateEnabled'       => $miGateEnabled,
            'attestationDefault'  => self::DEFAULT_STUDENT_ATTESTATION,
            'draftUrl'            => site_url('/portal/assessments/mi/' . $assignmentId . '/draft'),
            'finalizeUrl'         => site_url('/portal/assessments/mi/' . $assignmentId . '/finalize'),
            'backUrl'             => site_url('/portal/assessments/mi'),
            'breadcrumbs'         => [
                ['label' => 'Campus Portal', 'url' => site_url('/portal')],
                ['label' => 'Multiple Intelligence', 'url' => site_url('/portal/assessments/mi')],
                ['label' => 'Survey #' . $assignmentId, 'url' => ''],
            ],
        ]);
    }

    /**
     * Student Portal: AJAX save encrypted draft responses.
     */
    public function portalSaveDraft(int $assignmentId)
    {
        $actor = $this->resolvePortalStudentActor('guidance.assessments.take');
        if (!is_array($actor)) {
            return $actor;
        }

        $tenantId = (int) $actor['tenant_id'];

        try {
            $input = $this->requestInput();
            $rawResponses = $input['responses'] ?? $input;
            if (!is_array($rawResponses)) {
                throw new InvalidArgumentException('Responses payload must be an array.', 422);
            }

            $responses = $this->extractFilteredResponses($rawResponses);
            $saved = $this->miService->saveDraft($assignmentId, $responses, $tenantId, $actor);

            return $this->response->setStatusCode(200)
                ->setHeader('Cache-Control', 'private, no-store')
                ->setJSON([
                    'success'     => true,
                    'saved_count' => count($saved['draft_responses'] ?? $responses),
                    'csrf'        => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
        } catch (Throwable $e) {
            return $this->handleFailure($e, site_url('/portal/assessments/mi/' . $assignmentId), true);
        }
    }

    /**
     * Student Portal: finalize 80-item MI assessment with explicit student digital attestation.
     */
    public function portalFinalize(int $assignmentId)
    {
        $actor = $this->resolvePortalStudentActor('guidance.assessments.take');
        if (!is_array($actor)) {
            return $actor;
        }

        $tenantId = (int) $actor['tenant_id'];

        try {
            // Enforce mi_reproduction_signoff activation gate for live production finalization
            $this->gateService->assertGateEnabled(GuidanceActivationGateService::GATE_MI_REPRODUCTION, $tenantId);

            $input = $this->requestInput();

            // Enforce v1 signer capacity policy: never permit simulated guardian signatures
            $signerCapacity = strtolower(trim((string) ($input['signer_capacity'] ?? 'student')));
            $this->gateService->assertSignerCapacityAllowed($signerCapacity);
            if ($signerCapacity !== 'student') {
                throw new DomainException('Multiple Intelligence student attestation must be submitted by the student account holder.', 403);
            }

            $confirmed = $input['attestation_confirmed'] ?? null;
            $isConfirmed = $confirmed === '1' || $confirmed === 1 || $confirmed === true || $confirmed === 'true' || $confirmed === 'on';
            if (!$isConfirmed) {
                throw new InvalidArgumentException('You must confirm the student digital attestation checkbox before finalizing.', 422);
            }

            $statement = trim((string) ($input['attestation_statement'] ?? ''));
            if ($statement === '') {
                throw new InvalidArgumentException('Student digital attestation statement is required.', 422);
            }

            $rawResponses = $input['responses'] ?? $input;
            if (!is_array($rawResponses)) {
                throw new InvalidArgumentException('All 80 survey responses are required.', 422);
            }

            $responses = $this->extractFilteredResponses($rawResponses);
            $submission = $this->miService->finalizeAssessment($assignmentId, $responses, $statement, $tenantId, $actor);
            $submissionId = (int) $submission['id'];
            $redirectUrl = site_url('/portal/assessments/mi/results/' . $submissionId);
            $message = 'Your Multiple Intelligence survey has been finalized and attested.';

            if ($this->wantsJson()) {
                return $this->response->setStatusCode(200)
                    ->setHeader('Cache-Control', 'private, no-store')
                    ->setJSON([
                        'success'       => true,
                        'message'       => $message,
                        'submission_id' => $submissionId,
                        'redirect'      => $redirectUrl,
                        'csrf'          => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }

            return redirect()->to($redirectUrl)->with('success', $message);
        } catch (Throwable $e) {
            return $this->handleFailure($e, site_url('/portal/assessments/mi/' . $assignmentId));
        }
    }

    /**
     * Student Portal: view finalized 8-domain MI results.
     */
    public function portalResults(int $submissionId)
    {
        $actor = $this->resolvePortalStudentActor('guidance.assessments.view');
        if (!is_array($actor)) {
            return $actor;
        }

        return $this->renderResultsView($submissionId, $actor, 'portal');
    }

    /**
     * Student Portal: download/stream MI Report PDF.
     */
    public function portalPdf(int $submissionId)
    {
        $actor = $this->resolvePortalStudentActor('guidance.assessments.view');
        if (!is_array($actor)) {
            return $actor;
        }

        return $this->streamPdf($submissionId, $actor);
    }

    private function renderResultsView(int $submissionId, array $actor, string $mode)
    {
        $tenantId = (int) $actor['tenant_id'];

        try {
            $results = $this->miService->getResults($submissionId, $tenantId, $actor);
            $studentMeta = $this->loadStudentMetadata($results, $tenantId);

            $isCounselor = $mode === 'counselor';
            $pdfUrl = $isCounselor
                ? site_url('/guidance/assessments/mi/results/' . $submissionId . '/pdf')
                : site_url('/portal/assessments/mi/results/' . $submissionId . '/pdf');
            $backUrl = $isCounselor
                ? site_url('/guidance/assessments/mi')
                : site_url('/portal/assessments/mi');

            $breadcrumbs = $isCounselor
                ? [
                    ['label' => 'Guidance', 'url' => site_url('/guidance')],
                    ['label' => 'Multiple Intelligence', 'url' => site_url('/guidance/assessments/mi')],
                    ['label' => 'Results #' . $submissionId, 'url' => ''],
                ]
                : [
                    ['label' => 'Campus Portal', 'url' => site_url('/portal')],
                    ['label' => 'Multiple Intelligence', 'url' => site_url('/portal/assessments/mi')],
                    ['label' => 'Results #' . $submissionId, 'url' => ''],
                ];

            $this->response->setHeader('Cache-Control', 'private, no-store');

            return view('portal/assessments/mi_results', [
                'title'       => 'Multiple Intelligences Profile Report',
                'pageTitle'   => 'Multiple Intelligences Profile (All 8 Domains)',
                'mode'        => $mode,
                'results'     => $results,
                'studentMeta' => $studentMeta,
                'pdfUrl'      => $pdfUrl,
                'backUrl'     => $backUrl,
                'breadcrumbs' => $breadcrumbs,
            ]);
        } catch (Throwable $e) {
            $fallback = $mode === 'counselor' ? site_url('/guidance/assessments/mi') : site_url('/portal/assessments/mi');
            return $this->handleFailure($e, $fallback);
        }
    }

    private function streamPdf(int $submissionId, array $actor)
    {
        $tenantId = (int) $actor['tenant_id'];

        try {
            $results = $this->miService->getResults($submissionId, $tenantId, $actor);
            $studentMeta = $this->loadStudentMetadata($results, $tenantId);
            $submission = $results['submission'];
            $assignment = $results['assignment'] ?? [];

            $formCode = 'PROVISIONAL (PENDING QA SIGN-OFF)';
            $def = Database::connect()->table('guidance_form_definitions')
                ->where('tenant_id', $tenantId)
                ->where('internal_key', 'guidance_multiple_intelligence')
                ->where('is_active', 1)
                ->get()
                ->getRowArray();
            if ($def !== null && !empty($def['form_code'])) {
                $formCode = (string) $def['form_code'];
            }

            $verificationCode = 'PROVISIONAL-MI-' . $submissionId;
            if (!empty($submission['form_instance_id'])) {
                $issuance = Database::connect()->table('guidance_document_issuances')
                    ->where('tenant_id', $tenantId)
                    ->where('form_instance_id', (int) $submission['form_instance_id'])
                    ->orderBy('id', 'DESC')
                    ->get()
                    ->getRowArray();
                if ($issuance !== null && !empty($issuance['verification_code'])) {
                    $verificationCode = (string) $issuance['verification_code'];
                }
            }

            $snapshot = [
                'student_name'      => $studentMeta['full_name'],
                'student_id'        => $studentMeta['mis_id'],
                'course_year'       => $studentMeta['course_year'],
                'assessment_date'   => (string) ($assignment['completed_at'] ?? $submission['attested_at'] ?? date('Y-m-d')),
                'form_code'         => $formCode,
                'verification_code' => $verificationCode,
                'domain_scores'     => $results['domain_scores'],
                'attestation'       => [
                    'signer_name'   => $studentMeta['full_name'],
                    'signer_id'     => $studentMeta['mis_id'],
                    'signer_role'   => 'Student',
                    'attested_at'   => (string) ($results['attestation']['attested_at'] ?? ''),
                    'statement'     => self::DEFAULT_STUDENT_ATTESTATION,
                    'snapshot_hash' => (string) ($results['attestation']['snapshot_hash'] ?? ''),
                ],
                'is_draft'          => false,
            ];

            $pdfBytes = $this->documentService->renderMultipleIntelligencePdf($snapshot);

            return $this->response
                ->setStatusCode(200)
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'inline; filename="mi-report-' . $submissionId . '.pdf"')
                ->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setBody($pdfBytes);
        } catch (Throwable $e) {
            return $this->handleFailure($e, site_url('/portal/assessments/mi'));
        }
    }

    /**
     * @param array<string, mixed> $results
     * @return array{full_name: string, mis_id: string, course_year: string}
     */
    private function loadStudentMetadata(array $results, int $tenantId): array
    {
        $personId = (int) ($results['submission']['person_id'] ?? 0);
        if ($personId <= 0) {
            return [
                'full_name'   => 'Student Record',
                'mis_id'      => 'N/A',
                'course_year' => 'N/A',
            ];
        }

        $db = Database::connect();
        $row = $db->table('people p')
            ->select('p.first_name, p.middle_name, p.last_name, p.mis_id, sp.program, sp.year_level, sp.section')
            ->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = p.tenant_id', 'left')
            ->where('p.id', $personId)
            ->where('p.tenant_id', $tenantId)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return [
                'full_name'   => 'Student #' . $personId,
                'mis_id'      => 'N/A',
                'course_year' => 'N/A',
            ];
        }

        $fullName = trim(implode(' ', array_filter([
            trim((string) ($row['first_name'] ?? '')),
            trim((string) ($row['middle_name'] ?? '')),
            trim((string) ($row['last_name'] ?? '')),
        ])));

        $courseParts = [];
        if (!empty($row['program'])) {
            $courseParts[] = (string) $row['program'];
        }
        if (!empty($row['year_level'])) {
            $courseParts[] = 'Year ' . (string) $row['year_level'];
        }
        if (!empty($row['section'])) {
            $courseParts[] = 'Sec ' . (string) $row['section'];
        }

        return [
            'full_name'   => $fullName !== '' ? $fullName : 'Student #' . $personId,
            'mis_id'      => (string) ($row['mis_id'] ?? 'N/A'),
            'course_year' => $courseParts !== [] ? implode(' · ', $courseParts) : 'N/A',
        ];
    }

    /**
     * @param array<mixed, mixed> $raw
     * @return array<int, int>
     */
    private function extractFilteredResponses(array $raw): array
    {
        $filtered = [];
        foreach ($raw as $key => $val) {
            if (!is_numeric((string) $key)) {
                continue;
            }
            $itemNum = (int) $key;
            if ($itemNum < 1 || $itemNum > 80) {
                continue;
            }
            if ($val === '' || $val === null) {
                continue;
            }
            if (!is_numeric($val)) {
                throw new InvalidArgumentException("Response for item {$itemNum} must be an integer between 0 and 4.", 422);
            }
            $intVal = (int) $val;
            if ($intVal < 0 || $intVal > 4) {
                throw new InvalidArgumentException("Response for item {$itemNum} must be between 0 and 4.", 422);
            }
            $filtered[$itemNum] = $intVal;
        }
        return $filtered;
    }

    private function resolveWebActor(string $permission, string $portalUnit)
    {
        if ($this->actorResolver !== null) {
            return ($this->actorResolver)($permission);
        }

        $user = (new AuthenticatedUser())->current();
        if ($user === null) {
            return AuthenticatedUser::denied($this->request, false, 'Please sign in to continue.', '/auth/login');
        }

        if (!PortalContext::isRolePermitted((string) ($user['role'] ?? ''), $portalUnit)) {
            return AuthenticatedUser::denied($this->request, true, 'Guidance department access forbidden.', '/portal');
        }

        if (($user['role'] ?? '') !== 'superadmin' && !in_array($permission, $user['permissions'] ?? [], true)) {
            return AuthenticatedUser::denied($this->request, true, 'Permission required: ' . $permission, '/guidance');
        }

        return $user;
    }

    private function resolvePortalStudentActor(string $permission)
    {
        if ($this->actorResolver !== null) {
            return ($this->actorResolver)($permission);
        }

        $user = (new AuthenticatedUser())->current();
        if ($user === null) {
            return AuthenticatedUser::denied($this->request, false, 'Please sign in to continue.', '/auth/login');
        }

        if (($user['role'] ?? '') !== 'superadmin' && !in_array($permission, $user['permissions'] ?? [], true)) {
            return AuthenticatedUser::denied($this->request, true, 'Permission required: ' . $permission, '/portal');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function requestInput(): array
    {
        $contentType = strtolower($this->request->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $json = $this->request->getJSON(true);
            return is_array($json) ? $json : [];
        }

        $post = $this->request->getPost();
        return is_array($post) ? $post : [];
    }

    private function wantsJson(): bool
    {
        return $this->request->isAJAX()
            || strtolower($this->request->getHeaderLine('HX-Request')) === 'true'
            || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'application/json')
            || str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'application/json')
            || $this->request->is('json');
    }

    private function handleFailure(Throwable $e, string $redirectUrl, bool $forceJson = false)
    {
        $code = (int) $e->getCode();
        $status = in_array($code, [400, 401, 403, 404, 409, 422], true) ? $code : 500;
        $message = $status === 500
            ? 'Unable to process the Multiple Intelligence request right now. Please try again.'
            : $e->getMessage();

        if ($status === 500) {
            log_message('error', 'MI controller error: {class} {msg}', [
                'class' => get_class($e),
                'msg'   => $e->getMessage(),
            ]);
        }

        if ($forceJson || $this->wantsJson()) {
            return $this->response->setStatusCode($status)
                ->setHeader('Cache-Control', 'private, no-store')
                ->setJSON([
                    'success' => false,
                    'message' => $message,
                    'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
        }

        return redirect()->to($redirectUrl)->with('error', $message);
    }

    private function notFoundResponse(string $message)
    {
        if ($this->wantsJson()) {
            return $this->response->setStatusCode(404)
                ->setHeader('Cache-Control', 'private, no-store')
                ->setJSON(['success' => false, 'message' => $message]);
        }

        return $this->response->setStatusCode(404)
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody(esc($message));
    }
}

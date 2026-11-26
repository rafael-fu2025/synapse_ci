<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\LikePattern;
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
        $programFilter = trim((string) ($this->request->getGet('program') ?? ''));

        $totalAssignments = (int) $db->table('guidance_mi_assignments a')
            ->join('people p', 'p.id = a.person_id AND p.tenant_id = a.tenant_id')
            ->where('a.tenant_id', $tenantId)
            ->where('a.deleted_at', null)
            ->where('p.deleted_at', null)
            ->countAllResults();

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
        if ($programFilter !== '') {
            $builder->where('sp.program', $programFilter);
        }

        $filteredAssignments = (int) (clone $builder)->countAllResults();
        $perPage = (int) ($this->request->getGet('per_page') ?? 25);
        $perPage = in_array($perPage, [20, 25, 50, 100], true) ? $perPage : 25;
        $totalPages = max(1, (int) ceil($filteredAssignments / $perPage));
        $page = max(1, min($totalPages, (int) ($this->request->getGet('page') ?? 1)));

        $assignments = $builder
            ->orderBy("CASE WHEN sp.program IS NULL OR sp.program = '' THEN 1 ELSE 0 END", 'ASC', false)
            ->orderBy('sp.program', 'ASC')
            ->orderBy('a.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        $totalStudents = (int) $db->table('people')
            ->where('tenant_id', $tenantId)
            ->where('kind', 'student')
            ->where('deleted_at', null)
            ->countAllResults();

        $students = $db->table('people p')
            ->select('p.id, p.mis_id, p.first_name, p.middle_name, p.last_name, sp.program, sp.year_level')
            ->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = p.tenant_id', 'left')
            ->where('p.tenant_id', $tenantId)
            ->where('p.kind', 'student')
            ->where('p.deleted_at', null)
            ->orderBy('p.last_name', 'ASC')
            ->orderBy('p.first_name', 'ASC')
            ->limit(500)
            ->get()
            ->getResultArray();

        $programRows = $db->table('people p')
            ->distinct()
            ->select('sp.program')
            ->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = p.tenant_id')
            ->where('p.tenant_id', $tenantId)
            ->where('p.kind', 'student')
            ->where('p.deleted_at', null)
            ->where('sp.program IS NOT NULL', null, false)
            ->where('sp.program !=', '')
            ->get()
            ->getResultArray();
        $programs = [];
        foreach ($programRows as $row) {
            $prog = trim((string) ($row['program'] ?? ''));
            if ($prog !== '') {
                $programs[$prog] = $prog;
            }
        }
        $programs = array_values($programs);
        sort($programs, SORT_NATURAL | SORT_FLAG_CASE);

        $yearRows = $db->table('people p')
            ->distinct()
            ->select('sp.year_level')
            ->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = p.tenant_id')
            ->where('p.tenant_id', $tenantId)
            ->where('p.kind', 'student')
            ->where('p.deleted_at', null)
            ->where('sp.year_level IS NOT NULL', null, false)
            ->where('sp.year_level !=', '')
            ->get()
            ->getResultArray();
        $yearMap = ['1' => '1', '2' => '2', '3' => '3', '4' => '4'];
        foreach ($yearRows as $row) {
            $yr = trim((string) ($row['year_level'] ?? ''));
            if ($yr !== '') {
                $yearMap[$yr] = $yr;
            }
        }
        $yearLevels = array_values($yearMap);
        sort($yearLevels, SORT_NATURAL);

        $activeRows = $db->table('guidance_mi_assignments')
            ->select('person_id')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->get()
            ->getResultArray();
        $activePersonIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['person_id'],
            $activeRows
        )));

        $miGate = $this->gateService->getGate(GuidanceActivationGateService::GATE_MI_REPRODUCTION, $tenantId);
        $miGateEnabled = $miGate !== null && (int) ($miGate['is_enabled'] ?? 0) === 1;

        $this->response->setHeader('Cache-Control', 'private, no-store');

        return view('guidance/assessments/mi_index', [
            'title'           => 'Multiple Intelligence Assessments',
            'pageTitle'       => 'Multiple Intelligence (80-Item) Registry',
            'assignments'     => $assignments,
            'students'        => $students,
            'totalStudents'   => $totalStudents,
            'programs'        => $programs,
            'yearLevels'      => $yearLevels,
            'activePersonIds' => $activePersonIds,
            'statusFilter'    => $statusFilter,
            'programFilter'   => $programFilter,
            'pagination'      => [
                'per_page' => $perPage,
                'filtered' => $filteredAssignments,
                'total'    => $totalAssignments,
                'pages'    => $totalPages,
                'page'     => $page,
            ],
            'miGate'          => $miGate,
            'miGateEnabled'   => $miGateEnabled,
            'canAssign'       => $actor['role'] === 'superadmin' || in_array('guidance.assessments.assign', $actor['permissions'] ?? [], true),
            'breadcrumbs'     => [
                ['label' => 'Guidance', 'url' => site_url('/guidance')],
                ['label' => 'Multiple Intelligence', 'url' => ''],
            ],
        ]);
    }

    /**
     * Guidance Counselor Console: live multi-token search across all active students in the tenant.
     */
    public function searchStudents()
    {
        $actor = $this->resolveWebActor('guidance.assessments.assign', 'guidance');
        if (!is_array($actor)) {
            return $actor;
        }

        $q = trim((string) ($this->request->getGet('q') ?? ''));
        if (mb_strlen($q) > 150) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Search query is too long.',
            ]);
        }

        $tenantId = (int) $actor['tenant_id'];
        $db = Database::connect();

        $builder = $db->table('people p')
            ->select('p.id, p.mis_id, p.first_name, p.middle_name, p.last_name, sp.program, sp.year_level')
            ->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = p.tenant_id', 'left')
            ->where('p.tenant_id', $tenantId)
            ->where('p.kind', 'student')
            ->where('p.deleted_at', null);

        if ($q !== '') {
            $rawTokens = preg_split('/[\s,]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_slice($rawTokens, 0, 6);
            $searchFields = ['p.first_name', 'p.last_name', 'p.middle_name', 'p.mis_id', 'sp.program', 'sp.year_level'];

            foreach ($tokens as $token) {
                if (preg_match('/^y(\d+)$/i', $token, $yearMatch) === 1) {
                    $builder->groupStart();
                    LikePattern::applyGroup($builder, $searchFields, $token);
                    $builder->orLike('sp.year_level', LikePattern::literal($yearMatch[1]), 'both', true);
                    $builder->groupEnd();
                } else {
                    LikePattern::applyGroup($builder, $searchFields, $token);
                }
            }
        }

        $totalMatches = (int) (clone $builder)->countAllResults();

        $rows = $builder
            ->orderBy('p.last_name', 'ASC')
            ->orderBy('p.first_name', 'ASC')
            ->limit(50)
            ->get()
            ->getResultArray();

        $activeLookup = [];
        if ($rows !== []) {
            $personIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $activeRows = $db->table('guidance_mi_assignments')
                ->select('person_id')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->whereIn('status', ['assigned', 'in_progress'])
                ->whereIn('person_id', $personIds)
                ->get()
                ->getResultArray();
            foreach ($activeRows as $activeRow) {
                $activeLookup[(int) $activeRow['person_id']] = true;
            }
        }

        $results = [];
        foreach ($rows as $stu) {
            $stuId = (int) $stu['id'];
            $first = trim((string) ($stu['first_name'] ?? ''));
            $middle = trim((string) ($stu['middle_name'] ?? ''));
            $last = trim((string) ($stu['last_name'] ?? ''));
            $misId = trim((string) ($stu['mis_id'] ?? ''));
            $program = trim((string) ($stu['program'] ?? ''));
            $yearLevel = trim((string) ($stu['year_level'] ?? ''));

            $name = trim($last . ', ' . $first . ($middle !== '' ? ' ' . $middle : ''));
            $meta = trim($misId . ($program !== '' ? ' · ' . $program : '') . ($yearLevel !== '' ? ' Y' . $yearLevel : ''));

            $results[] = [
                'id'          => $stuId,
                'name'        => $name,
                'first_name'  => $first,
                'middle_name' => $middle,
                'last_name'   => $last,
                'mis_id'      => $misId,
                'program'     => $program,
                'year_level'  => $yearLevel,
                'meta'        => $meta,
                'has_active'  => isset($activeLookup[$stuId]),
            ];
        }

        $this->response->setHeader('Cache-Control', 'private, no-store');

        return $this->response->setJSON([
            'success'       => true,
            'query'         => $q,
            'total_matches' => $totalMatches,
            'results'       => $results,
        ]);
    }

    /**
     * Guidance Counselor Console: assign a new 80-item MI survey to one or more students.
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
            [$targetPersonIds, $isCohortRequest] = $this->resolveAssignTargets($input, $tenantId);

            $options = [
                'academic_term'       => isset($input['academic_term']) ? trim((string) $input['academic_term']) : null,
                'academic_year'       => isset($input['academic_year']) ? trim((string) $input['academic_year']) : null,
                'due_at'              => !empty($input['due_at']) ? trim((string) $input['due_at']) : null,
                'assigned_by_user_id' => (int) $actor['id'],
                'allow_skip_active'   => $isCohortRequest || count($targetPersonIds) > 1,
            ];

            $batch = $this->miService->assignSurveyBatch($targetPersonIds, $options, $tenantId, $actor);
            if ($batch['assigned_count'] === 1 && $batch['skipped_active_count'] === 0) {
                $message = 'Multiple Intelligence survey assigned successfully.';
            } elseif ($batch['skipped_active_count'] > 0) {
                $message = sprintf(
                    'Multiple Intelligence survey assigned to %d student(s) (%d skipped with an existing active assignment).',
                    $batch['assigned_count'],
                    $batch['skipped_active_count']
                );
            } else {
                $message = sprintf('Multiple Intelligence survey assigned to %d students.', $batch['assigned_count']);
            }

            if ($this->wantsJson()) {
                return $this->response->setStatusCode(201)
                    ->setHeader('Cache-Control', 'private, no-store')
                    ->setJSON([
                        'success'              => true,
                        'message'              => $message,
                        'assignment'           => $batch['created'][0] ?? null,
                        'assignments'          => $batch['created'],
                        'assigned_count'       => $batch['assigned_count'],
                        'skipped_active_count' => $batch['skipped_active_count'],
                        'redirect'             => site_url('/guidance/assessments/mi'),
                        'csrf'                 => ['name' => csrf_token(), 'hash' => csrf_hash()],
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
     * Compatibility route: MI assignments now live in the combined My Surveys hub.
     */
    public function portalList()
    {
        $actor = $this->resolvePortalStudentActor('guidance.assessments.take');
        if (!is_array($actor)) {
            return $actor;
        }

        if ((int) ($actor['person_id'] ?? 0) <= 0) {
            return AuthenticatedUser::denied($this->request, true, 'An identified profile is required.', '/portal');
        }

        return redirect()->to(site_url('/portal/surveys#multiple-intelligence'));
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
            return AuthenticatedUser::denied($this->request, true, 'You may only access your own Multiple Intelligence assignment.', '/portal/surveys#multiple-intelligence');
        }

        if (($assignment['status'] ?? '') === 'completed') {
            $sub = $this->submissionModel->findFinalizedByAssignmentId($assignmentId, $tenantId);
            if ($sub !== null) {
                return redirect()->to(site_url('/portal/assessments/mi/results/' . (int) $sub['id']));
            }
        }

        if (($assignment['status'] ?? '') === 'cancelled') {
            return redirect()->to(site_url('/portal/surveys#multiple-intelligence'))->with('error', 'This assessment assignment has been cancelled.');
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
            'backUrl'             => site_url('/portal/surveys#multiple-intelligence'),
            'breadcrumbs'         => [
                ['label' => 'Campus Portal', 'url' => site_url('/portal')],
                ['label' => 'My Surveys', 'url' => site_url('/portal/surveys#multiple-intelligence')],
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
                : site_url('/portal/surveys#multiple-intelligence');

            $breadcrumbs = $isCounselor
                ? [
                    ['label' => 'Guidance', 'url' => site_url('/guidance')],
                    ['label' => 'Multiple Intelligence', 'url' => site_url('/guidance/assessments/mi')],
                    ['label' => 'Results #' . $submissionId, 'url' => ''],
                ]
                : [
                    ['label' => 'Campus Portal', 'url' => site_url('/portal')],
                    ['label' => 'My Surveys', 'url' => site_url('/portal/surveys#multiple-intelligence')],
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
            $fallback = $mode === 'counselor' ? site_url('/guidance/assessments/mi') : site_url('/portal/surveys#multiple-intelligence');
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
            return $this->handleFailure($e, site_url('/portal/surveys#multiple-intelligence'));
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

        if (\App\Services\ErrorPageRenderer::wantsHtml($this->request)) {
            return \App\Services\ErrorPageRenderer::htmlResponse($this->response, 404, $message);
        }

        return $this->response->setStatusCode(404)
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody(esc($message));
    }

    /**
     * Resolve deduplicated target student person IDs and whether cohort filtering was requested.
     *
     * @param array<string, mixed> $input
     * @return array{0: list<int>, 1: bool}
     */
    private function resolveAssignTargets(array $input, int $tenantId): array
    {
        $db = Database::connect();
        $targetPersonIds = array_merge(
            $this->collectNumericIds($input['person_id'] ?? null, false),
            $this->collectNumericIds($input['person_ids'] ?? null, true)
        );

        $misTokens = $this->collectTokenList([$input['mis_id'] ?? null, $input['mis_ids'] ?? null], true);
        if ($misTokens !== []) {
            $matchedRows = $db->table('people')
                ->select('id, mis_id')
                ->where('tenant_id', $tenantId)
                ->whereIn('mis_id', $misTokens)
                ->where('deleted_at', null)
                ->get()
                ->getResultArray();
            $byMis = [];
            foreach ($matchedRows as $row) {
                $byMis[(string) $row['mis_id']] = (int) $row['id'];
            }
            $unmatchedMisIds = [];
            foreach ($misTokens as $token) {
                if (isset($byMis[$token])) {
                    $targetPersonIds[] = $byMis[$token];
                } else {
                    $unmatchedMisIds[] = $token;
                }
            }
            if ($unmatchedMisIds !== []) {
                if (count($misTokens) === 1 && $targetPersonIds === []) {
                    throw new InvalidArgumentException('Please select a valid student (person_id or exact MIS ID).', 422);
                }
                throw new InvalidArgumentException('Unknown Student MIS ID(s): ' . implode(', ', $unmatchedMisIds), 422);
            }
        }

        $assignAllStudents = !empty($input['assign_all_students'])
            && !in_array(strtolower((string) $input['assign_all_students']), ['0', 'false'], true);
        $targetPrograms = $this->collectTokenList([$input['target_programs'] ?? null], false);
        $targetYearLevels = $this->collectTokenList([$input['target_year_levels'] ?? null], false);
        $isCohortRequest = (($input['assign_scope'] ?? '') === 'cohort')
            || $assignAllStudents
            || $targetPrograms !== []
            || $targetYearLevels !== [];

        if ($isCohortRequest) {
            if (!$assignAllStudents && $targetPrograms === [] && $targetYearLevels === [] && $targetPersonIds === []) {
                throw new InvalidArgumentException(
                    'Please select at least one cohort filter (Program, Year Level, or All Active Students) or choose specific students.',
                    422
                );
            }

            if ($assignAllStudents || $targetPrograms !== [] || $targetYearLevels !== []) {
                $cohortQuery = $db->table('people p')
                    ->select('p.id')
                    ->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = p.tenant_id', 'left')
                    ->where('p.tenant_id', $tenantId)
                    ->where('p.kind', 'student')
                    ->where('p.deleted_at', null);

                if (!$assignAllStudents) {
                    if ($targetPrograms !== []) {
                        $cohortQuery->whereIn('sp.program', $targetPrograms);
                    }
                    if ($targetYearLevels !== []) {
                        $cohortQuery->whereIn('sp.year_level', $targetYearLevels);
                    }
                }

                $cohortIds = array_map(
                    static fn (array $row): int => (int) $row['id'],
                    $cohortQuery->get()->getResultArray()
                );
                if ($cohortIds === [] && $targetPersonIds === []) {
                    throw new InvalidArgumentException('No active students matched the selected cohort filters.', 422);
                }
                $targetPersonIds = array_merge($targetPersonIds, $cohortIds);
            }
        }

        $targetPersonIds = array_values(array_unique(array_filter(
            $targetPersonIds,
            static fn (int $pid): bool => $pid > 0
        )));

        if ($targetPersonIds === []) {
            throw new InvalidArgumentException('Please select a valid student (person_id or exact MIS ID).', 422);
        }

        return [$targetPersonIds, $isCohortRequest];
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function collectNumericIds(mixed $raw, bool $splitStrings): array
    {
        $items = is_array($raw) ? $raw : [$raw];
        $ids = [];
        foreach ($items as $item) {
            if (is_string($item) && $splitStrings) {
                foreach (preg_split('/[\s,;]+/', trim($item)) ?: [] as $part) {
                    if ($part !== '' && is_numeric($part) && (int) $part > 0) {
                        $ids[] = (int) $part;
                    }
                }
                continue;
            }
            if (is_numeric($item) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }
        return $ids;
    }

    /**
     * @param list<mixed> $sources
     * @return list<string>
     */
    private function collectTokenList(array $sources, bool $splitStrings): array
    {
        $tokens = [];
        foreach ($sources as $raw) {
            if ($raw === null || $raw === '') {
                continue;
            }
            $items = is_array($raw) ? $raw : [$raw];
            foreach ($items as $item) {
                if (!is_string($item) && !is_numeric($item)) {
                    continue;
                }
                $value = trim((string) $item);
                if ($value === '') {
                    continue;
                }
                $parts = $splitStrings ? (preg_split('/[\s,;]+/', $value) ?: []) : [$value];
                foreach ($parts as $part) {
                    $trimmed = trim($part);
                    if ($trimmed !== '') {
                        $tokens[$trimmed] = $trimmed;
                    }
                }
            }
        }
        return array_values($tokens);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CounsellingCaseModel;
use App\Models\CounsellingSessionModel;
use App\Models\GuidanceEpisodeModel;
use App\Models\UserModel;
use App\Services\DashboardCache;
use App\Services\Guidance\GuidanceCaseService;
use App\Services\Guidance\GuidanceClinicalFormService;
use App\Services\Guidance\GuidancePractitionerService;
use App\Services\Guidance\GuidanceSafetyService;
use App\Services\Guidance\GuidanceTransferService;
use App\Services\Scheduling\SchedulingClock;

final class GuidanceController extends BaseController
{
    private GuidanceCaseService $caseService;
    private CounsellingCaseModel $caseModel;
    private CounsellingSessionModel $sessionModel;
    private ?\Closure $actorResolver = null;

    public function __construct()
    {
        $this->caseService = new GuidanceCaseService();
        $this->caseModel = new CounsellingCaseModel();
        $this->sessionModel = new CounsellingSessionModel();
    }

    /**
     * Guidance Workstation Main Dashboard.
     */
    public function dashboard()
    {
        $user = (new \App\Services\AuthenticatedUser())->current();
        if ($user === null) {
            return \App\Services\AuthenticatedUser::denied($this->request, false, 'Please sign in to continue.', '/auth/login');
        }
        $tenantId = $user['tenant_id'];
        $db = \Config\Database::connect();
        $today = SchedulingClock::today();

        // Case counts are scoped to the counsellor unless the actor holds oversight,
        // so the cache key must carry that discriminator: one shared key would leak
        // a caseload's numbers into another counsellor's dashboard.
        $scope = 'guidance.dashboard.' . ($user['role'] === 'superadmin'
            || in_array('guidance.cases.read_any', $user['permissions'], true) ? '0' : (string) $user['id']);

        $metrics = DashboardCache::remember($scope, DashboardCache::TTL, static function () use ($db, $tenantId, $today, $user): array {
            // Share the ownership and live-person boundary across every case-derived dataset.
            $cases = $db->table('counselling_cases')
                ->join('people', 'people.id = counselling_cases.person_id AND people.tenant_id = counselling_cases.tenant_id')
                ->where('counselling_cases.tenant_id', $tenantId)
                ->where('counselling_cases.deleted_at', null)->where('people.deleted_at', null);
            if ($user['role'] !== 'superadmin' && !in_array('guidance.cases.read_any', $user['permissions'], true)) {
                $cases->where('counselling_cases.counsellor_user_id', $user['id']);
            }
            $sessions = (clone $cases)
                ->join('counselling_sessions', 'counselling_sessions.case_id = counselling_cases.id AND counselling_sessions.tenant_id = counselling_cases.tenant_id')
                ->where('counselling_sessions.deleted_at', null);

            // 1. Case counts
            $activeCases = (clone $cases)->whereIn('counselling_cases.status', ['open', 'active', 'monitoring'])->countAllResults();
            $closedCases = (clone $cases)->where('counselling_cases.status', 'closed')->countAllResults();

            // 2. Queue today
            $queueWaiting = $db->table('queue_entries')
                ->where('tenant_id', $tenantId)->where('unit', 'guidance')->where('queue_date', $today)
                ->where('status', 'waiting')->countAllResults();
            $queueCalled = $db->table('queue_entries')
                ->where('tenant_id', $tenantId)->where('unit', 'guidance')->where('queue_date', $today)
                ->where('status', 'called')->countAllResults();

            // 3. Pending incoming referrals to guidance
            $pendingReferrals = $db->table('referrals')
                ->where('tenant_id', $tenantId)->where('target_module', 'guidance')
                ->whereIn('status', ['submitted', 'acknowledged'])->countAllResults();

            // 4. Completed sessions
            $completedSessions = (clone $sessions)->where('counselling_sessions.status', 'completed')->countAllResults();

            // 5. Category breakdown
            $categoryCounts = array_fill_keys(['academic', 'personal', 'career', 'crisis', 'behavioral', 'family'], 0);
            foreach ((clone $cases)->select('counselling_cases.category, COUNT(*) AS total')
                ->groupBy('counselling_cases.category')->get()->getResultArray() as $categoryRow) {
                if (isset($categoryCounts[$categoryRow['category']])) {
                    $categoryCounts[$categoryRow['category']] = (int) $categoryRow['total'];
                }
            }

            // 6. Recent cases
            $recentCases = (clone $cases)
                ->join('users', 'users.id = counselling_cases.counsellor_user_id AND users.tenant_id = counselling_cases.tenant_id')
                ->select('counselling_cases.id, counselling_cases.case_number, counselling_cases.category, counselling_cases.status')
                ->select('people.first_name, people.last_name, people.mis_id, users.username as counsellor_name')
                ->orderBy('counselling_cases.opened_at', 'DESC')->limit(6)->get()->getResultArray();

            // 7. Last 7 days session activity
            // One bounded range read, bucketed on the Manila business day in PHP:
            // started_at is stored as a UTC instant, so the Manila day is a UTC range.
            $ranges = SchedulingClock::businessDayRanges(7);
            $days = array_map(static fn (string $businessDay): string => date('M j', strtotime($businessDay)), array_keys($ranges));
            $dailySessions = array_fill(0, count($ranges), 0);
            foreach ((clone $sessions)
                ->where('counselling_sessions.started_at >=', $ranges[array_key_first($ranges)][0])
                ->where('counselling_sessions.started_at <', $ranges[array_key_last($ranges)][1])
                ->select('counselling_sessions.started_at')->get()->getResultArray() as $sessionRow) {
                $bucket = SchedulingClock::businessDayIndex((string) $sessionRow['started_at'], $ranges);
                if ($bucket !== null) {
                    $dailySessions[$bucket]++;
                }
            }

            return [
                'activeCases'       => $activeCases,
                'closedCases'       => $closedCases,
                'queueToday'        => $queueWaiting + $queueCalled,
                'pendingReferrals'  => $pendingReferrals,
                'completedSessions' => $completedSessions,
                'categoryCounts'    => $categoryCounts,
                'recentCases'       => $recentCases,
                'chartDays'         => $days,
                'chartSeries'       => $dailySessions,
            ];
        });

        return view('guidance/dashboard', $metrics + [
            'title'       => 'Guidance Dashboard',
            'pageTitle'   => 'Guidance Workstation Dashboard',
            'user'        => $user,
            'breadcrumbs' => [
                ['label' => 'Guidance', 'url' => site_url('/guidance')],
                ['label' => 'Dashboard', 'url' => ''],
            ],
        ]);
    }

    /**
     * Cases list view.
     */
    public function cases()
    {
        $status = (string) $this->request->getGet('status');
        $category = (string) $this->request->getGet('category');

        $actor = \App\Services\EngagementAccess::actor('guidance.cases.read', 'guidance');
        $db = \Config\Database::connect();
        $actorId = (int) ($actor['id'] ?? 0);
        $tenantId = (int) ($actor['tenant_id'] ?? \App\Services\CurrentTenant::id());
        $practitionerDirectory = [];
        try {
            $practitionerDirectory = (new GuidancePractitionerService(db: $db))->getPractitionerDirectory($tenantId);
        } catch (\Throwable) {
            $practitionerDirectory = [];
        }

        if (($actor['role'] ?? '') !== 'superadmin' && !in_array('guidance.cases.read_any', $actor['permissions'] ?? [], true)) {
            $consultCaseIds = [];
            if ($db->tableExists('guidance_case_transfers')) {
                $consultCaseIds = array_column(
                    $db->table('guidance_case_transfers')
                        ->select('case_id')
                        ->where('tenant_id', $tenantId)
                        ->where('to_counsellor_user_id', $actorId)
                        ->where('transfer_type', 'consultation')
                        ->where('status', 'accepted')
                        ->get()->getResultArray(),
                    'case_id'
                );
            }

            $this->caseModel->groupStart()
                ->where('counselling_cases.counsellor_user_id', $actorId);
            if ($consultCaseIds !== []) {
                $this->caseModel->orWhereIn('counselling_cases.id', array_map('intval', $consultCaseIds));
            }
            $this->caseModel->groupEnd();
        }
        $page = filter_var($this->request->getGet('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $cases = $this->caseModel->getListWithPatients($status, $category, 25, $page);

        return view('guidance/cases/index', [
            'title'                 => 'Counseling Cases',
            'pageTitle'             => 'Counseling Cases',
            'cases'                 => $cases,
            'actor'                 => $actor,
            'practitionerDirectory' => $practitionerDirectory,
            'pager'                 => $this->caseModel->pager,
            'status'                => $status,
            'category'              => $category,
            'breadcrumbs'           => [
                ['label' => 'Guidance', 'url' => site_url('/guidance/cases')],
                ['label' => 'Cases', 'url' => ''],
            ],
        ]);
    }

    /**
     * Case Workspace view.
     */
    public function caseView(int $id)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $actor = $this->freshActor('guidance.cases.read');
            $package = $this->caseService->getCaseDetails($id);
            $tenantId = (int) $package['case']['tenant_id'];

            // Find if there is an in_progress active session
            $activeSession = $this->sessionModel->scoped()
                ->where('case_id', $id)
                ->where('status', 'in_progress')
                ->orderBy('id', 'DESC')
                ->first();

            $decryptedNote = null;
            if ($activeSession !== null) {
                $decryptedNote = $this->caseService->getDecryptedSessionNote((int) $activeSession['id']);
            }

            // Outcome plaintext is fetched only through its fresh, audited read path.
            $outcomes = $this->caseService->getDecryptedCaseOutcomes($id);

            $clinicalHistory = [
                'controlled_forms'     => [],
                'interviews'           => [],
                'contact_forms'        => [],
                'contact_sheets'       => [],
                'legacy_session_notes' => [],
                'timeline'             => [],
            ];
            try {
                $clinicalHistory = (new GuidanceClinicalFormService())->getClinicalHistoryForCase($id, $tenantId, $actor);
            } catch (\Throwable) {
                // Keep case workspace accessible even if clinical forms history is unavailable
            }

            $db = \Config\Database::connect();

            $episode = null;
            if ($db->tableExists('guidance_episodes')) {
                try {
                    $episode = (new GuidanceEpisodeModel($db))->scoped($tenantId)
                        ->where('case_id', $id)
                        ->orderBy('id', 'DESC')
                        ->first();
                } catch (\Throwable) {
                    $episode = null;
                }
            }

            $assignments = [];
            $transfers = [];
            if ($db->tableExists('guidance_case_assignments')) {
                try {
                    $transferService = new GuidanceTransferService();
                    $assignments = $transferService->getAssignmentHistory($id, $tenantId);
                    if ($db->tableExists('guidance_case_transfers')) {
                        $transfers = $transferService->getCaseTransfers($id, $tenantId);
                    }
                } catch (\Throwable) {
                    $assignments = [];
                    $transfers = [];
                }
            }

            $safetyHistory = null;
            if ($db->tableExists('guidance_safety_assessments')) {
                try {
                    $safetyHistory = (new GuidanceSafetyService())->getSafetyHistory((int) $package['case']['person_id'], $actor);
                } catch (\Throwable) {
                    $safetyHistory = null;
                }
            }

            $practitionerProfile = null;
            $canFinalizeDirectly = false;
            if ($db->tableExists('guidance_practitioner_profiles')) {
                try {
                    $practitionerService = new GuidancePractitionerService();
                    $practitionerProfile = $practitionerService->getPractitionerProfile((int) $actor['id'], $tenantId);
                    $canFinalizeDirectly = $practitionerService->canFinalizeDirectly((int) $actor['id'], $tenantId);
                } catch (\Throwable) {
                    $practitionerProfile = null;
                    $canFinalizeDirectly = false;
                }
            }

            $counselorRows = [];
            $userDirectory = [];
            try {
                $counselorRows = $db->table('users u')
                    ->select('u.id, u.username, u.role, p.first_name, p.last_name')
                    ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id AND p.deleted_at IS NULL', 'left')
                    ->where('u.tenant_id', $tenantId)
                    ->where('u.deleted_at', null)
                    ->where('u.status', 'active')
                    ->whereIn('u.role', ['counsellor', 'guidance_supervisor', 'guidance_admin'])
                    ->orderBy('p.last_name', 'ASC')
                    ->orderBy('u.username', 'ASC')
                    ->get()->getResultArray();

                foreach ($db->table('users u')
                    ->select('u.id, u.username, p.first_name, p.last_name')
                    ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id', 'left')
                    ->where('u.tenant_id', $tenantId)
                    ->get()->getResultArray() as $uRow) {
                    $full = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
                    $userDirectory[(int) $uRow['id']] = $full !== '' ? $full : (string) ($uRow['username'] ?? 'Staff #' . $uRow['id']);
                }
            } catch (\Throwable) {
                $counselorRows = [];
                $userDirectory = [];
            }

            $practitionerDirectory = [];
            try {
                $practitionerDirectory = (new GuidancePractitionerService(db: $db))->getPractitionerDirectory($tenantId);
            } catch (\Throwable) {
                $practitionerDirectory = [];
            }

            $isConsultationPartner = false;
            try {
                $isConsultationPartner = (new \App\Services\Guidance\GuidanceAccessPolicy(db: $db))
                    ->isConsultationPartner($package['case'], $actor);
            } catch (\Throwable) {
                $isConsultationPartner = false;
            }

            return view('guidance/cases/view', [
                'title'                 => 'Case: ' . $package['case']['case_number'],
                'pageTitle'             => $package['case']['title'],
                'case'                  => $package['case'],
                'package'               => $package,
                'actor'                 => $actor,
                'activeSession'         => $activeSession,
                'decryptedNote'         => $decryptedNote,
                'noteReadAudited'       => $decryptedNote !== null,
                'outcomes'              => $outcomes,
                'clinicalHistory'       => $clinicalHistory,
                'episode'               => $episode,
                'assignments'           => $assignments,
                'transfers'             => $transfers,
                'safetyHistory'         => $safetyHistory,
                'practitionerProfile'   => $practitionerProfile,
                'practitionerDirectory' => $practitionerDirectory,
                'canFinalizeDirectly'   => $canFinalizeDirectly,
                'isConsultationPartner' => $isConsultationPartner,
                'counselors'            => $counselorRows,
                'userDirectory'         => $userDirectory,
                'oversightMode'         => $package['oversight_mode'],
                'breadcrumbs'           => [
                    ['label' => 'Guidance', 'url' => site_url('/guidance/cases')],
                    ['label' => $package['case']['case_number'], 'url' => ''],
                ],
            ]);
        } catch (\Throwable $error) {
            [$status, $message] = $this->safeFailure($error, 'caseView', $id);
            return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')
                ->setContentType('text/html')->setBody('<div class="alert alert-danger">' . esc($message) . '</div>');
        }
    }

    /** Case access filters protect the subject's private photo just like the workspace. */
    public function casePhoto(int $id)
    {
        $package = $this->caseService->getCaseDetails($id);
        $patient = $package['patient'];
        $photos = new \App\Services\ProfilePhotoService();
        $path = $photos->path((int) $package['case']['tenant_id'], (int) $patient['id']);
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        $type = 'image/webp';
        if ($bytes === false) {
            $bytes = \App\Services\ProfileAvatar::svg($patient);
            $type = 'image/svg+xml';
        }
        return $this->response->setHeader('Content-Type', $type)
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cross-Origin-Resource-Policy', 'same-origin')->setBody($bytes);
    }

    /**
     * Create case form.
     */
    public function newCase()
    {
        return view('guidance/cases/create', [
            'title'         => 'Open Counseling Case',
            'pageTitle'     => 'New Counseling Case',
            'cancelUrl'     => site_url('/guidance/cases'),
            'formModalPage' => true,
            'breadcrumbs'   => [
                ['label' => 'Guidance', 'url' => site_url('/guidance/cases')],
                ['label' => 'New Case', 'url' => ''],
            ],
        ]);
    }

    /**
     * Store new case.
     */
    public function storeCase()
    {
        return $this->mutate(function (array $actor): array {
            return $this->createCaseResponse($actor);
        }, permission: 'guidance.cases.create');
    }

    private function createCaseResponse(array $actor): array
    {
        $personId = (int) $this->request->getPost('person_id');
        $title = (string) $this->request->getPost('title');
        $category = (string) ($this->request->getPost('category') ?? 'personal');
        $summaryNotes = (string) $this->request->getPost('summary_notes');

        if ($personId <= 0 || trim($title) === '') {
            throw new \InvalidArgumentException('Case title and person are required.', 422);
        }

        $case = $this->caseService->createCase($personId, (int) $actor['id'], $title, $category, $summaryNotes, $actor);
        $redirect = '/guidance/cases/' . $case['case']['id'];
        $successMsg = "Case {$case['case']['case_number']} opened successfully.";

        return [$redirect, $successMsg, $this->navigationRefresh($redirect, 'guidance.case-list')];
    }

    /**
     * View decrypted session note dialog.
     */
    public function readSessionNote(int $sessionId)
    {
        try {
            $this->response->setHeader('Cache-Control', 'private, no-store');
            $this->freshActor('guidance.cases.read');
            $session = $this->caseService->getSession($sessionId);

            $package = $this->caseService->getCaseDetails((int) $session['case_id']);
            $note = $this->caseService->getDecryptedSessionNote($sessionId);

            $author = null;
            if ($note !== null && !empty($note['author_id'])) {
                $user = (new UserModel())->scoped()->find($note['author_id']);
                $author = $user['username'] ?? null;
            }

            return view('guidance/sessions/read', [
                'title'         => 'Session #' . $session['session_number'] . ' Note (' . ucfirst($session['session_type']) . ')',
                'session'       => $session,
                'case'          => $package['case'],
                'patient'       => $package['patient'],
                'student'       => $package['student'] ?? null,
                'employee'      => $package['employee'] ?? null,
                'note'          => $note,
                'noteReadAudited' => $note !== null,
                'oversightMode' => $package['oversight_mode'],
                'author'        => $author,
                'cancelUrl'     => site_url('/guidance/cases/' . $session['case_id']),
                'formModalPage' => true,
            ]);
        } catch (\Throwable $e) {
            return $this->noteFailure($e, $sessionId);
        }
    }

    /**
     * Start a new session under a case.
     */
    public function startSession(int $caseId)
    {
        return $this->mutate(function (array $actor) use ($caseId): array {
            $this->caseService->openSession($caseId, (int) $actor['id'], $this->postedSessionType(), actor: $actor);
            return ['/guidance/cases/' . $caseId, 'Counseling session opened.', $this->caseRefresh($caseId)];
        }, permission: 'guidance.cases.create');
    }

    public function closeCase(int $caseId)
    {
        return $this->mutate(function (array $actor) use ($caseId): array {
            $this->requireConfirmation();
            $this->caseService->closeCase($caseId, confirmed: true,
                closureDisposition: $this->postedOutcome('closure_disposition'), actor: $actor);
            return ['/guidance/cases/' . $caseId, 'Case closed.', $this->caseRefresh($caseId)];
        });
    }

    public function monitorCase(int $caseId)
    {
        return $this->mutate(function (array $actor) use ($caseId): array {
            $this->requireConfirmation();
            $this->caseService->monitorCase($caseId, $actor);
            return ['/guidance/cases/' . $caseId, 'Case moved to monitoring.', $this->caseRefresh($caseId)];
        });
    }

    public function readSessionNoteHistory(int $sessionId)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $actor = $this->freshActor('guidance.cases.read');
            $history = $this->caseService->getDecryptedSessionNoteHistory($sessionId);
            $session = $this->caseService->getSession($sessionId);
            $case = $this->caseModel->scoped()->find((int) $session['case_id']);
            return view('guidance/sessions/history', [
                'title' => 'Finalized Note Version History', 'history' => $history,
                'historyReadAudited' => true,
                'oversightMode' => (int) $case['counsellor_user_id'] !== (int) $actor['id'],
                'cancelUrl' => site_url('/guidance/sessions/' . $sessionId . '/read'),
                'formModalPage' => true,
            ]);
        } catch (\Throwable $e) {
            return $this->noteFailure($e, $sessionId);
        }
    }

    private function requireConfirmation(): void
    {
        if ($this->request->getPost('confirmed') !== '1') {
            throw new \InvalidArgumentException('Explicit confirmation is required.', 422);
        }
    }

    /**
     * Required, bounded outcome text for the web forms.
     *
     * Service callers may still omit these arguments for legacy internal use;
     * the interactive workflow always records an explicit outcome.
     */
    private function postedOutcome(string $field): string
    {
        $value = $this->request->getPost($field);
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Enter the required outcome notes.', 422);
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 2000) {
            throw new \InvalidArgumentException('Outcome notes must be between 1 and 2000 characters.', 422);
        }
        return $value;
    }

    private function postedSessionType(): string
    {
        $type = $this->request->getPost('session_type');
        if (!is_string($type) || !in_array($type, GuidanceCaseService::SESSION_TYPES, true)) {
            throw new \InvalidArgumentException('Select a valid session type.', 422);
        }
        return $type;
    }

    /**
     * Save session notes (draft or finalize).
     */
    public function saveSessionNotes(int $sessionId)
    {
        return $this->mutate(function (array $actor) use ($sessionId): array {
            $session = $this->caseService->getSession($sessionId);
            $notes = (string) $this->request->getPost('notes');
            $action = (string) $this->request->getPost('action');

            if ($action === 'finalize') {
                $this->caseService->finalizeSessionNotes($sessionId, $notes, (int) $actor['id'], $actor);
                $msg = 'Session notes finalized and encrypted into version history.';
            } else {
                $this->caseService->saveDraftNotes($sessionId, $notes, (int) $actor['id'], $actor);
                $msg = 'Draft notes autosaved.';
            }

            return ['/guidance/cases/' . $session['case_id'], $msg, $this->caseRefresh((int) $session['case_id'])];
        });
    }

    /**
     * Close a session, optionally attaching a Counseling Contact Sheet (F-OGC-GCS-CCF-009-00).
     */
    public function closeSession(int $sessionId)
    {
        return $this->mutate(function (array $actor) use ($sessionId): array {
            $session = $this->caseService->getSession($sessionId);
            $attachSheet = $this->request->getPost('attach_contact_sheet') === '1';
            $tenantId = (int) $actor['tenant_id'];

            if ($attachSheet) {
                $perms = $actor['permissions'] ?? [];
                if (!in_array('guidance.clinical.author', $perms, true)
                    && !in_array('guidance.counseling.author', $perms, true)
                    && !in_array('guidance.psychosocial.author', $perms, true)) {
                    throw new \DomainException('Actor lacks clinical author permission.', 403);
                }
                $profile = (new GuidancePractitionerService())->getPractitionerProfile((int) $actor['id'], $tenantId);
                if ($profile === null || (int) ($profile['is_active'] ?? 0) !== 1 || ($profile['professional_standing'] ?? '') !== 'active') {
                    throw new \DomainException('Attaching a controlled contact sheet requires an active practitioner profile.', 403);
                }
            }

            $this->caseService->closeSession($sessionId, $actor);
            $message = 'Session closed successfully.';

            if ($attachSheet) {
                $ccfPayload = [];
                foreach ([
                    'time_start',
                    'time_end',
                    'session_type',
                    'concerns_discussed',
                    'counseling_goals',
                    'interventions_and_responses',
                    'homework_and_commitments',
                    'next_schedule',
                    'submission_notes',
                    'attestation_statement',
                ] as $field) {
                    $val = $this->request->getPost($field);
                    if (is_string($val) && trim($val) !== '') {
                        $ccfPayload[$field] = trim($val);
                    }
                }
                if ($this->request->getPost('finalize') === '1') {
                    $ccfPayload['finalize'] = true;
                    if (empty($ccfPayload['attestation_statement'])) {
                        $ccfPayload['attestation_statement'] = 'Counseling contact sheet verified and digitally attested upon session closure.';
                    }
                }

                $attached = (new GuidanceClinicalFormService())->attachContactSheetToSession(
                    $sessionId,
                    $ccfPayload,
                    $tenantId,
                    $actor
                );
                $statusLabel = (string) ($attached['status'] ?? 'draft');
                $message = match ($statusLabel) {
                    'finalized'      => 'Session closed and Counseling Contact Sheet (CCF-009) finalized.',
                    'pending_cosign' => 'Session closed and Counseling Contact Sheet (CCF-009) submitted for supervisor co-sign.',
                    default          => 'Session closed and Counseling Contact Sheet (CCF-009) draft attached.',
                };
            }

            return ['/guidance/cases/' . $session['case_id'], $message, $this->caseRefresh((int) $session['case_id'])];
        });
    }

    /**
     * Add a follow-up commitment.
     */
    public function addFollowup(int $caseId)
    {
        return $this->mutate(function (array $actor) use ($caseId): array {
            $dueDate = (string) $this->request->getPost('due_date');
            $actionPlan = (string) $this->request->getPost('action_plan');
            if ($dueDate === '' || trim($actionPlan) === '') {
                throw new \InvalidArgumentException('Due date and action plan are required.', 422);
            }
            $this->caseService->addFollowup($caseId, null, $dueDate, $actionPlan, $actor);
            return ['/guidance/cases/' . $caseId, 'Action item assigned.', $this->caseRefresh($caseId)];
        });
    }

    /**
     * Mark follow-up as complete.
     */
    public function completeFollowup(int $followupId)
    {
        return $this->mutate(function (array $actor) use ($followupId): array {
            $followup = (new \App\Models\GuidanceFollowupModel())->scoped()->find($followupId);
            if ($followup === null) {
                throw new \RuntimeException('Action item not found.', 404);
            }
            $this->caseService->completeFollowup($followupId, $this->postedOutcome('completion_notes'), $actor);
            return ['/guidance/cases/' . $followup['case_id'], 'Commitment marked as completed.', $this->caseRefresh((int) $followup['case_id'])];
        });
    }

    public function missFollowup(int $followupId)
    {
        return $this->mutate(function (array $actor) use ($followupId): array {
            $this->requireConfirmation();
            $followup = $this->caseService->missFollowup($followupId, $actor);
            return ['/guidance/cases/' . $followup['case_id'], 'Commitment explicitly marked missed.', $this->caseRefresh((int) $followup['case_id'])];
        });
    }

    /**
     * Helper: Start/resume session directly from Guidance Queue ticket.
     */
    public function startForTicket(int $queueEntryId)
    {
        return $this->mutate(function (array $actor) use ($queueEntryId): array {
            // Keep existing queue forms and JSON redirect handling: the first POST
            // only opens a picker, without changing the ticket, case or session.
            if ($this->request->getPost('session_type') === null) {
                $this->caseService->getTicketForSessionSelection($queueEntryId, $actor);
                return ['/guidance/cases/choose-for-ticket/' . $queueEntryId, 'Choose a session type to start or resume.',
                    $this->navigationRefresh('/guidance/cases/choose-for-ticket/' . $queueEntryId, 'guidance.case-list')];
            }
            // The service scopes the guidance ticket and selects an owned case atomically.
            $session = $this->caseService->openSessionForTicket($queueEntryId, (int) $actor['id'],
                $this->postedSessionType(), $actor);
            return ['/guidance/cases/' . $session['case_id'], 'Counseling session opened.',
                $this->navigationRefresh('/guidance/cases/' . $session['case_id'], 'guidance.case.' . $session['case_id'])];
        }, '/guidance/queue', 'guidance.cases.create');
    }

    public function chooseSessionForTicket(int $queueEntryId)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $actor = $this->freshActor('guidance.cases.create');
            $this->caseService->getTicketForSessionSelection($queueEntryId, $actor);
            return view('guidance/sessions/start', [
                'title' => 'Choose Counseling Session Type', 'queueEntryId' => $queueEntryId,
                'formAction' => site_url('/guidance/cases/start-for-ticket/' . $queueEntryId),
                'cancelUrl' => site_url('/guidance/queue'), 'formModalPage' => true,
            ]);
        } catch (\Throwable $error) {
            [$status, $message] = $this->safeFailure($error, 'chooseSessionForTicket', $queueEntryId);
            return $this->response->setStatusCode($status)->setContentType('text/html')->setBody(
                '<div class="alert alert-danger">' . esc($message) . '</div>'
            );
        }
    }

    private function freshActor(?string $permission = null): array
    {
        return $this->actorResolver === null
            ? $this->caseService->currentActor($permission)
            : ($this->actorResolver)($permission);
    }

    private function wantsJson(): bool
    {
        return $this->request->isAJAX()
            || strtolower($this->request->getHeaderLine('HX-Request')) === 'true'
            || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'application/json')
            || $this->request->is('json');
    }

    /** Never expose service exception messages, including coded storage/crypto failures. */
    private function safeFailure(\Throwable $error, string $operation, int $recordId = 0): array
    {
        $status = (int) $error->getCode();
        if (!in_array($status, [403, 404, 409, 422], true)) {
            $status = 500;
            log_message('error', 'Guidance technical error: operation={operation} record_id={record_id} exception={exception_class} code={error_code}', [
                'operation' => $operation,
                'record_id' => $recordId,
                'exception_class' => get_class($error),
                'error_code' => $error->getCode(),
            ]);
        }
        return [$status, match ($status) {
            403 => 'You are not authorized to perform this action.',
            404 => 'The requested guidance record was not found.',
            409 => 'The record could not be updated. Refresh and try again.',
            422 => 'Please check the required fields and try again.',
            default => 'Unable to complete the guidance request. Please try again later.',
        }];
    }

    private function caseRefresh(int $caseId): ?array
    {
        $owner = 'guidance.case.' . $caseId;
        if (!config(\Config\RefreshRegions::class)->enables($owner)) {
            return null;
        }

        return $this->refreshRegions($owner, ['workspace'], site_url('/guidance/cases/' . $caseId));
    }

    private function navigationRefresh(string $url, string $rolloutOwner): ?array
    {
        if (!config(\Config\RefreshRegions::class)->enables($rolloutOwner)) {
            return null;
        }

        return $this->refreshNavigation(site_url($url));
    }

    private function mutate(
        callable $operation,
        string $fallback = '/guidance/cases',
        string $permission = 'guidance.cases.write'
    ) {
        try {
            [$url, $message, $refresh] = array_pad($operation($this->freshActor($permission)), 3, null);
        } catch (\Throwable $error) {
            [$status, $message] = $this->safeFailure($error, 'mutation');
            if ($this->wantsJson()) {
                return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')
                    ->setJSON(['success' => false, 'message' => $message,
                        'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
            }
            // Do not flash request input: it can contain confidential note plaintext.
            return ($status === 422 ? redirect()->back() : redirect()->to($fallback))->with('error', $message);
        }
        $url = site_url($url);
        if ($this->wantsJson()) {
            if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
                session()->setFlashdata('success', $message);
            }
            $payload = ['success' => true, 'message' => $message, 'redirect' => $url,
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]];
            if ($refresh !== null) {
                $payload['refresh'] = $refresh;
            }
            return $this->response->setStatusCode(200)->setHeader('Cache-Control', 'no-store')
                ->setJSON($payload);
        }
        return redirect()->to($url)->with('success', $message);
    }

    /**
     * GET guidance/students/(:num)/cumulative
     * Consolidated read-only Guidance cumulative profile (demographics, MI history, survey interview status, visits).
     */
    public function cumulativeRecord(int $personId)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $actor = $this->freshActor('guidance.cases.read');
            $service = new \App\Services\Guidance\GuidanceCumulativeService();
            $record = $service->getStudentCumulativeRecord($personId, $actor);
            $person = $record['person'];
            $fullName = trim((string) ($person['last_name'] ?? '') . ', ' . (string) ($person['first_name'] ?? ''));

            return view('guidance/students/cumulative', $record + [
                'title'       => 'Cumulative Guidance Record — ' . $fullName,
                'pageTitle'   => 'Cumulative Guidance Record',
                'actor'       => $actor,
                'breadcrumbs' => [
                    ['label' => 'Guidance', 'url' => site_url('/guidance')],
                    ['label' => 'Cumulative Record', 'url' => ''],
                ],
            ]);
        } catch (\Throwable $error) {
            [$status, $message] = $this->safeFailure($error, 'cumulativeRecord', $personId);
            return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')
                ->setContentType('text/html')->setBody('<div class="alert alert-danger">' . esc($message) . '</div>');
        }
    }

    /**
     * GET guidance/reports/summary
     * Non-PII periodic Guidance aggregate counts for monthly, semester, school-year, and CHED reporting.
     */
    public function periodicSummary()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $actor    = $this->freshActor('guidance.cases.read');
            $tenantId = (int) $actor['tenant_id'];
            $db       = \Config\Database::connect();

            $defaultFrom = date('Y-m-01');
            $defaultTo   = date('Y-m-d');
            $from = trim((string) ($this->request->getGet('from') ?? $defaultFrom));
            $to   = trim((string) ($this->request->getGet('to') ?? $defaultTo));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => 'Use valid YYYY-MM-DD start and end dates.',
                ]);
            }

            $startLocal = new \DateTimeImmutable($from . ' 00:00:00', new \DateTimeZone('Asia/Manila'));
            $endLocal   = new \DateTimeImmutable($to . ' 23:59:59', new \DateTimeZone('Asia/Manila'));
            if ($endLocal < $startLocal) {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => 'End date cannot precede start date.',
                ]);
            }
            if ($startLocal->diff($endLocal)->days > 366) {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => 'Periodic summary supports ranges up to 366 days per query.',
                ]);
            }

            $fromUtc = $startLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $toUtc   = $endLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            $casesOpened = (int) $db->table('counselling_cases')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at IS NULL')
                ->where('opened_at >=', $fromUtc)
                ->where('opened_at <=', $toUtc)
                ->countAllResults();

            $casesClosed = (int) $db->table('counselling_cases')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at IS NULL')
                ->where('status', 'closed')
                ->where('closed_at >=', $fromUtc)
                ->where('closed_at <=', $toUtc)
                ->countAllResults();

            $sessionsCompleted = (int) $db->table('counselling_sessions s')
                ->join('counselling_cases c', 'c.id = s.case_id AND c.tenant_id = s.tenant_id', 'inner')
                ->where('s.tenant_id', $tenantId)
                ->where('c.deleted_at IS NULL')
                ->where('s.deleted_at IS NULL')
                ->where('s.status', 'completed')
                ->where('s.started_at >=', $fromUtc)
                ->where('s.started_at <=', $toUtc)
                ->countAllResults();

            $pspSessionsCompleted = 0;
            $counselingSessionsCompleted = $sessionsCompleted;
            if ($db->tableExists('guidance_practitioner_profiles')) {
                $pspSessionsCompleted = (int) $db->table('counselling_sessions s')
                    ->join('counselling_cases c', 'c.id = s.case_id AND c.tenant_id = s.tenant_id', 'inner')
                    ->join('guidance_practitioner_profiles gp', 'gp.user_id = s.counsellor_user_id AND gp.tenant_id = s.tenant_id AND gp.is_active = 1', 'inner')
                    ->where('s.tenant_id', $tenantId)
                    ->where('c.deleted_at IS NULL')
                    ->where('s.deleted_at IS NULL')
                    ->where('s.status', 'completed')
                    ->where('gp.credential_tier', 'counselor_associate')
                    ->where('s.started_at >=', $fromUtc)
                    ->where('s.started_at <=', $toUtc)
                    ->countAllResults();
                $counselingSessionsCompleted = max(0, $sessionsCompleted - $pspSessionsCompleted);
            }

            $intakesSubmitted = $db->tableExists('guidance_intake_referrals')
                ? (int) $db->table('guidance_intake_referrals')
                    ->where('tenant_id', $tenantId)
                    ->where('status !=', 'draft')
                    ->where('submitted_at >=', $fromUtc)
                    ->where('submitted_at <=', $toUtc)
                    ->countAllResults()
                : 0;

            $callSlipsIssued = $db->tableExists('guidance_call_slips')
                ? (int) $db->table('guidance_call_slips')
                    ->where('tenant_id', $tenantId)
                    ->where('deleted_at IS NULL')
                    ->where('call_date >=', $from)
                    ->where('call_date <=', $to)
                    ->countAllResults()
                : 0;

            $miCompleted = $db->tableExists('guidance_mi_submissions')
                ? (int) $db->table('guidance_mi_submissions')
                    ->where('tenant_id', $tenantId)
                    ->where('deleted_at IS NULL')
                    ->where('status', 'finalized')
                    ->where('created_at >=', $fromUtc)
                    ->where('created_at <=', $toUtc)
                    ->countAllResults()
                : 0;

            $surveysSubmitted = $db->tableExists('survey_responses')
                ? (int) $db->table('survey_responses')
                    ->where('tenant_id', $tenantId)
                    ->where('submitted_at >=', $fromUtc)
                    ->where('submitted_at <=', $toUtc)
                    ->countAllResults()
                : 0;

            $summary = [
                'from'                          => $from,
                'to'                            => $to,
                'cases_opened'                  => $casesOpened,
                'cases_closed'                  => $casesClosed,
                'sessions_completed'            => $sessionsCompleted,
                'psp_sessions_completed'        => $pspSessionsCompleted,
                'counseling_sessions_completed' => $counselingSessionsCompleted,
                'intakes_submitted'             => $intakesSubmitted,
                'call_slips_issued'             => $callSlipsIssued,
                'mi_assessments_completed'      => $miCompleted,
                'surveys_submitted'             => $surveysSubmitted,
            ];

            if ($this->request->getGet('format') === 'csv') {
                $csvLines = [
                    ['period_from_manila', 'period_to_manila', 'metric', 'count'],
                    [$from, $to, 'cases_opened', (string) $casesOpened],
                    [$from, $to, 'cases_closed', (string) $casesClosed],
                    [$from, $to, 'sessions_completed_total', (string) $sessionsCompleted],
                    [$from, $to, 'psp_sessions_completed', (string) $pspSessionsCompleted],
                    [$from, $to, 'counseling_sessions_completed', (string) $counselingSessionsCompleted],
                    [$from, $to, 'intakes_submitted', (string) $intakesSubmitted],
                    [$from, $to, 'call_slips_issued', (string) $callSlipsIssued],
                    [$from, $to, 'mi_assessments_completed', (string) $miCompleted],
                    [$from, $to, 'surveys_submitted', (string) $surveysSubmitted],
                ];
                $fh = fopen('php://temp', 'r+');
                foreach ($csvLines as $line) {
                    fputcsv($fh, $line);
                }
                rewind($fh);
                $csv = (string) stream_get_contents($fh);
                fclose($fh);
                return \App\Services\Reporting\ExportCsv::response(
                    $this->response,
                    $csv,
                    'guidance_periodic_summary_' . $from . '_to_' . $to . '.csv'
                );
            }

            return $this->response->setJSON([
                'success' => true,
                'summary' => $summary,
            ]);
        } catch (\Throwable $error) {
            [$status, $message] = $this->safeFailure($error, 'periodicSummary');
            return $this->response->setStatusCode($status)->setJSON([
                'success' => false,
                'message' => $message,
            ]);
        }
    }

    private function noteFailure(\Throwable $error, int $sessionId)
    {
        [$status, $message] = $this->safeFailure($error, 'readSessionNote', $sessionId);
        $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store');
        if ($this->request->getGet('fragment') !== '1' && $this->wantsJson()) {
            return $this->response->setJSON(['success' => false, 'message' => $message]);
        }
        return $this->response->setContentType('text/html')->setBody(
            '<div class="modal-body p-4"><div class="alert alert-danger mb-0">' . esc($message) . '</div></div>'
        );
    }
}

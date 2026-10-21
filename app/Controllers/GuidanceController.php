<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CounsellingCaseModel;
use App\Models\CounsellingSessionModel;

use App\Models\UserModel;
use App\Services\Guidance\GuidanceCaseService;
use App\Services\Scheduling\SchedulingClock;

final class GuidanceController extends BaseController
{
    private GuidanceCaseService $caseService;
    private CounsellingCaseModel $caseModel;
    private CounsellingSessionModel $sessionModel;

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
        $categories = ['academic', 'personal', 'career', 'crisis', 'behavioral', 'family'];
        $categoryCounts = [];
        foreach ($categories as $cat) {
            $categoryCounts[$cat] = (clone $cases)->where('counselling_cases.category', $cat)->countAllResults();
        }

        // 6. Recent cases
        $recentCases = (clone $cases)
            ->join('users', 'users.id = counselling_cases.counsellor_user_id AND users.tenant_id = counselling_cases.tenant_id')
            ->select('counselling_cases.id, counselling_cases.case_number, counselling_cases.category, counselling_cases.status')
            ->select('people.first_name, people.last_name, people.mis_id, users.username as counsellor_name')
            ->orderBy('counselling_cases.opened_at', 'DESC')->limit(6)->get()->getResultArray();

        // 7. Last 7 days session activity
        $days = [];
        $dailySessions = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $days[] = date('M j', strtotime($d));
            $dailySessions[] = (clone $sessions)
                ->where('counselling_sessions.started_at >=', $d . ' 00:00:00')
                ->where('counselling_sessions.started_at <', date('Y-m-d', strtotime($d . ' +1 day')) . ' 00:00:00')
                ->countAllResults();
        }

        return view('guidance/dashboard', [
            'title'             => 'Guidance Dashboard',
            'pageTitle'         => 'Guidance Workstation Dashboard',
            'user'              => $user,
            'activeCases'       => $activeCases,
            'closedCases'       => $closedCases,
            'queueToday'        => $queueWaiting + $queueCalled,
            'pendingReferrals'  => $pendingReferrals,
            'completedSessions' => $completedSessions,
            'categoryCounts'    => $categoryCounts,
            'recentCases'       => $recentCases,
            'chartDays'         => $days,
            'chartSeries'       => $dailySessions,
            'breadcrumbs'       => [
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
        if (($actor['role'] ?? '') !== 'superadmin' && !in_array('guidance.cases.read_any', $actor['permissions'] ?? [], true)) {
            $this->caseModel->where('counselling_cases.counsellor_user_id', (int) ($actor['id'] ?? 0));
        }
        $page = filter_var($this->request->getGet('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $cases = $this->caseModel->getListWithPatients($status, $category, 25, $page);

        return view('guidance/cases/index', [
            'title'       => 'Counseling Cases',
            'pageTitle'   => 'Counseling Cases',
            'cases'       => $cases,
            'pager'       => $this->caseModel->pager,
            'status'      => $status,
            'category'    => $category,
            'breadcrumbs' => [
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
        $package = $this->caseService->getCaseDetails($id);

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

        return view('guidance/cases/view', [
            'title'         => 'Case: ' . $package['case']['case_number'],
            'pageTitle'     => $package['case']['title'],
            'case'          => $package['case'],
            'package'       => $package,
            'activeSession' => $activeSession,
            'decryptedNote' => $decryptedNote,
            'breadcrumbs'   => [
                ['label' => 'Guidance', 'url' => site_url('/guidance/cases')],
                ['label' => $package['case']['case_number'], 'url' => ''],
            ],
        ]);
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
        return $this->mutate(function (int $userId): array {
            return $this->createCaseResponse($userId);
        });
    }

    private function createCaseResponse(int $userId): array
    {
        $personId = (int) $this->request->getPost('person_id');
        $title = (string) $this->request->getPost('title');
        $category = (string) ($this->request->getPost('category') ?? 'personal');
        $summaryNotes = (string) $this->request->getPost('summary_notes');

        if ($personId <= 0 || trim($title) === '') {
            throw new \InvalidArgumentException('Case title and person are required.', 422);
        }

        $case = $this->caseService->createCase($personId, $userId, $title, $category, $summaryNotes);
        $redirect = site_url('/guidance/cases/' . $case['case']['id']);
        $successMsg = "Case {$case['case']['case_number']} opened successfully.";

        return [$redirect, $successMsg];
    }

    /**
     * View decrypted session note dialog.
     */
    public function readSessionNote(int $sessionId)
    {
        try {
            $this->response->setHeader('Cache-Control', 'private, no-store');
            $this->actorId();
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
        return $this->mutate(function (int $userId) use ($caseId): array {
            $this->caseService->openSession($caseId, $userId, $this->postedSessionType());
            return ['/guidance/cases/' . $caseId, 'Counseling session opened.'];
        });
    }

    public function closeCase(int $caseId)
    {
        return $this->mutate(function (int $userId) use ($caseId): array {
            $this->requireConfirmation();
            $this->caseService->closeCase($caseId, confirmed: true);
            return ['/guidance/cases/' . $caseId, 'Case closed.'];
        });
    }

    public function monitorCase(int $caseId)
    {
        return $this->mutate(function (int $userId) use ($caseId): array {
            $this->requireConfirmation();
            $this->caseService->monitorCase($caseId);
            return ['/guidance/cases/' . $caseId, 'Case moved to monitoring.'];
        });
    }

    public function readSessionNoteHistory(int $sessionId)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $this->actorId();
            $history = $this->caseService->getDecryptedSessionNoteHistory($sessionId);
            return view('guidance/sessions/history', [
                'title' => 'Finalized Note Version History', 'history' => $history,
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
        return $this->mutate(function (int $userId) use ($sessionId): array {
            $session = $this->caseService->getSession($sessionId);
            $notes = (string) $this->request->getPost('notes');
            $action = (string) $this->request->getPost('action');

            if ($action === 'finalize') {
                $this->caseService->finalizeSessionNotes($sessionId, $notes, $userId);
                $msg = 'Session notes finalized and encrypted into version history.';
            } else {
                $this->caseService->saveDraftNotes($sessionId, $notes, $userId);
                $msg = 'Draft notes autosaved.';
            }

            return ['/guidance/cases/' . $session['case_id'], $msg];
        });
    }

    /**
     * Close a session.
     */
    public function closeSession(int $sessionId)
    {
        return $this->mutate(function (int $userId) use ($sessionId): array {
            $session = $this->caseService->getSession($sessionId);
            $this->caseService->closeSession($sessionId);
            return ['/guidance/cases/' . $session['case_id'], 'Session closed successfully.'];
        });
    }

    /**
     * Add a follow-up commitment.
     */
    public function addFollowup(int $caseId)
    {
        return $this->mutate(function (int $userId) use ($caseId): array {
            $dueDate = (string) $this->request->getPost('due_date');
            $actionPlan = (string) $this->request->getPost('action_plan');
            if ($dueDate === '' || trim($actionPlan) === '') {
                throw new \InvalidArgumentException('Due date and action plan are required.', 422);
            }
            $this->caseService->getCaseDetails($caseId);
            $this->caseService->addFollowup($caseId, null, $dueDate, $actionPlan);
            return ['/guidance/cases/' . $caseId, 'Action item assigned.'];
        });
    }

    /**
     * Mark follow-up as complete.
     */
    public function completeFollowup(int $followupId)
    {
        return $this->mutate(function (int $userId) use ($followupId): array {
            $followup = (new \App\Models\GuidanceFollowupModel())->scoped()->find($followupId);
            if ($followup === null) {
                throw new \RuntimeException('Action item not found.', 404);
            }
            $this->caseService->getCaseDetails((int) $followup['case_id']);
            $this->caseService->completeFollowup($followupId);
            return ['/guidance/cases/' . $followup['case_id'], 'Commitment marked as completed.'];
        });
    }

    public function missFollowup(int $followupId)
    {
        return $this->mutate(function (int $userId) use ($followupId): array {
            $this->requireConfirmation();
            $followup = $this->caseService->missFollowup($followupId);
            return ['/guidance/cases/' . $followup['case_id'], 'Commitment explicitly marked missed.'];
        });
    }

    /**
     * Helper: Start/resume session directly from Guidance Queue ticket.
     */
    public function startForTicket(int $queueEntryId)
    {
        return $this->mutate(function (int $userId) use ($queueEntryId): array {
            // Keep existing queue forms and JSON redirect handling: the first POST
            // only opens a picker, without changing the ticket, case or session.
            if ($this->request->getPost('session_type') === null) {
                $this->caseService->getTicketForSessionSelection($queueEntryId);
                return ['/guidance/cases/choose-for-ticket/' . $queueEntryId, 'Choose a session type to start or resume.'];
            }
            // The service scopes the guidance ticket and selects an owned case atomically.
            $session = $this->caseService->openSessionForTicket($queueEntryId, $userId, $this->canReadAnyCase(), $this->postedSessionType());
            return ['/guidance/cases/' . $session['case_id'], 'Counseling session opened.'];
        }, '/guidance/queue');
    }

    public function chooseSessionForTicket(int $queueEntryId)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $this->caseService->getTicketForSessionSelection($queueEntryId);
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

    private function actorId(): int
    {
        $user = session()->get('user');
        $id = is_array($user) ? ($user['id'] ?? null) : null;
        if ((!is_int($id) && !is_string($id)) || !ctype_digit((string) $id) || (int) $id <= 0) {
            throw new \DomainException('An authenticated staff account is required.', 403);
        }
        return (int) $id;
    }

    private function canReadAnyCase(): bool
    {
        $user = session()->get('user');
        return is_array($user) && (($user['role'] ?? '') === 'superadmin'
            || in_array('guidance.cases.read_any', $user['permissions'] ?? [], true));
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

    private function mutate(callable $operation, string $fallback = '/guidance/cases')
    {
        try {
            [$url, $message] = $operation($this->actorId());
        } catch (\Throwable $error) {
            [$status, $message] = $this->safeFailure($error, 'mutation');
            if ($this->wantsJson()) {
                return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')
                    ->setJSON(['success' => false, 'message' => $message]);
            }
            // Do not flash request input: it can contain confidential note plaintext.
            return ($status === 422 ? redirect()->back() : redirect()->to($fallback))->with('error', $message);
        }
        $url = site_url($url);
        if ($this->wantsJson()) {
            session()->setFlashdata('success', $message);
            return $this->response->setStatusCode(200)->setHeader('Cache-Control', 'no-store')
                ->setJSON(['success' => true, 'message' => $message, 'redirect' => $url]);
        }
        return redirect()->to($url)->with('success', $message);
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

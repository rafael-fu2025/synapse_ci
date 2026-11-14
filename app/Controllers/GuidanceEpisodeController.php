<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceEpisodeService;
use App\Services\Guidance\GuidancePractitionerService;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GuidanceEpisodeController extends BaseController
{
    private GuidanceEpisodeService $episodeService;
    private GuidanceAccessPolicy $accessPolicy;

    public function __construct(
        ?GuidanceEpisodeService $episodeService = null,
        ?GuidanceAccessPolicy $accessPolicy = null
    ) {
        $this->episodeService = $episodeService ?? new GuidanceEpisodeService();
        $this->accessPolicy   = $accessPolicy ?? new GuidanceAccessPolicy();
    }

    /**
     * List guidance episodes with subject person and primary counselor details.
     */
    public function index()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $actor = $this->resolveStaffActor();
            $tenantId = (int) $actor['tenant_id'];
            $status = trim((string) ($this->request->getGet('status') ?? ''));
            $allowedStatuses = ['open', 'active', 'closed', 'transferred'];
            if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
                $status = '';
            }

            $db = Database::connect();
            $builder = $db->table('guidance_episodes ge')
                ->select('ge.*, p.first_name, p.last_name, p.mis_id, p.kind')
                ->select('cu.username AS counselor_username, cp.first_name AS counselor_first_name, cp.last_name AS counselor_last_name')
                ->select('cc.case_number, cc.title AS case_title')
                ->join('people p', 'p.id = ge.person_id AND p.tenant_id = ge.tenant_id')
                ->join('users cu', 'cu.id = ge.primary_counselor_user_id AND cu.tenant_id = ge.tenant_id', 'left')
                ->join('people cp', 'cp.id = cu.person_id AND cp.tenant_id = cu.tenant_id', 'left')
                ->join('counselling_cases cc', 'cc.id = ge.case_id AND cc.tenant_id = ge.tenant_id', 'left')
                ->where('ge.tenant_id', $tenantId)
                ->where('ge.deleted_at', null)
                ->where('p.deleted_at', null);

            $perms = $actor['permissions'] ?? [];
            $hasOversight = ($actor['role'] ?? '') === 'superadmin'
                || in_array('guidance.cases.read_any', $perms, true)
                || in_array($actor['role'] ?? '', ['guidance_admin', 'guidance_supervisor'], true);
            if (!$hasOversight) {
                $builder->where('ge.primary_counselor_user_id', (int) $actor['id']);
            }

            if ($status !== '') {
                $builder->where('ge.status', $status);
            }

            $episodes = $builder->orderBy('ge.opened_at', 'DESC')->orderBy('ge.id', 'DESC')->limit(100)->get()->getResultArray();

            $qualifiedCounselors = $this->loadQualifiedCounselors($tenantId);

            return view('guidance/episodes/index', [
                'title'               => 'Guidance Episodes',
                'pageTitle'           => 'Guidance Episodes',
                'episodes'            => $episodes,
                'status'              => $status,
                'qualifiedCounselors' => $qualifiedCounselors,
                'actor'               => $actor,
                'breadcrumbs'         => [
                    ['label' => 'Guidance', 'url' => site_url('/guidance')],
                    ['label' => 'Episodes', 'url' => ''],
                ],
            ]);
        } catch (Throwable $e) {
            [$code, $message] = $this->safeFailure($e, 'episodes.index');
            return $this->response->setStatusCode($code)->setContentType('text/html')
                ->setBody('<div class="alert alert-danger m-3">' . esc($message) . '</div>');
        }
    }

    /**
     * Show unified longitudinal timeline for a single guidance episode.
     */
    public function show(int $id)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $actor = $this->resolveStaffActor();
            $tenantId = (int) $actor['tenant_id'];
            $details = $this->episodeService->getEpisodeDetails($id, $tenantId, $actor);

            // Enrich controlled form evaluations with definition metadata
            $db = Database::connect();
            $defsById = [];
            foreach ($db->table('guidance_form_definitions')->where('tenant_id', $tenantId)->get()->getResultArray() as $d) {
                $defsById[(int) $d['id']] = $d;
            }
            $enrichedEvaluations = [];
            foreach ($details['evaluations'] ?? [] as $ev) {
                $def = $defsById[(int) ($ev['form_definition_id'] ?? 0)] ?? [];
                $ev['form_code']       = $def['form_code'] ?? '';
                $ev['internal_key']    = $def['internal_key'] ?? '';
                $ev['canonical_title'] = $def['canonical_title'] ?? 'Controlled Form';
                $enrichedEvaluations[] = $ev;
            }
            $details['evaluations'] = $enrichedEvaluations;

            $timeline = $this->buildTimeline($details);
            $qualifiedCounselors = $this->loadQualifiedCounselors($tenantId);

            return view('guidance/episodes/view', [
                'title'               => 'Episode #' . $id,
                'pageTitle'           => 'Guidance Episode #' . $id,
                'details'             => $details,
                'episode'             => $details['episode'],
                'person'              => $details['person'],
                'primaryCounselor'    => $details['primary_counselor'],
                'timeline'            => $timeline,
                'qualifiedCounselors' => $qualifiedCounselors,
                'actor'               => $actor,
                'breadcrumbs'         => [
                    ['label' => 'Guidance', 'url' => site_url('/guidance')],
                    ['label' => 'Episodes', 'url' => site_url('/guidance/episodes')],
                    ['label' => 'Episode #' . $id, 'url' => ''],
                ],
            ]);
        } catch (Throwable $e) {
            [$code, $message] = $this->safeFailure($e, 'episodes.show', $id);
            return $this->response->setStatusCode($code)->setContentType('text/html')
                ->setBody('<div class="alert alert-danger m-3">' . esc($message) . '</div>');
        }
    }

    /**
     * Open a new guidance episode (POST).
     */
    public function open()
    {
        return $this->mutate(function (array $actor): array {
            $tenantId = (int) $actor['tenant_id'];
            $personId = (int) $this->request->getPost('person_id');
            if ($personId <= 0) {
                throw new InvalidArgumentException('Select a valid person to open an episode.', 422);
            }

            $counselorId = (int) ($this->request->getPost('primary_counselor_user_id') ?: $actor['id']);
            $intakeReferralId = (int) ($this->request->getPost('intake_referral_id') ?: 0);
            $caseId = (int) ($this->request->getPost('case_id') ?: 0);
            $status = (string) ($this->request->getPost('status') ?? 'open');

            $episode = $this->episodeService->openEpisode($personId, [
                'primary_counselor_user_id' => $counselorId,
                'intake_referral_id'        => $intakeReferralId > 0 ? $intakeReferralId : null,
                'case_id'                   => $caseId > 0 ? $caseId : null,
                'status'                    => $status,
                'actor'                     => $actor,
            ], $tenantId);

            $episodeId = (int) ($episode['id'] ?? 0);
            return ['/guidance/episodes/' . $episodeId, 'Guidance episode #' . $episodeId . ' opened.'];
        }, '/guidance/episodes');
    }

    /**
     * Close a guidance episode (POST).
     */
    public function close(int $id)
    {
        return $this->mutate(function (array $actor) use ($id): array {
            $tenantId = (int) $actor['tenant_id'];
            $reason = trim((string) $this->request->getPost('closure_reason'));
            $notes = $this->request->getPost('closure_notes');
            $notes = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;

            $this->episodeService->closeEpisode($id, $reason, $notes, $tenantId, $actor);

            return ['/guidance/episodes/' . $id, 'Guidance episode #' . $id . ' closed.'];
        }, '/guidance/episodes/' . $id);
    }

    /**
     * Transfer a guidance episode to another qualified counselor (POST).
     */
    public function transfer(int $id)
    {
        return $this->mutate(function (array $actor) use ($id): array {
            $tenantId = (int) $actor['tenant_id'];
            $newCounselorId = (int) $this->request->getPost('new_counselor_user_id');
            $reason = trim((string) $this->request->getPost('reason'));

            if ($newCounselorId <= 0) {
                throw new InvalidArgumentException('Select a target counselor.', 422);
            }

            $this->episodeService->transferEpisode($id, $newCounselorId, $reason, $tenantId, $actor);

            return ['/guidance/episodes/' . $id, 'Guidance episode #' . $id . ' transferred.'];
        }, '/guidance/episodes/' . $id);
    }

    /**
     * Build chronological longitudinal timeline across referral, appointments, call slips, cases, sessions, and forms.
     *
     * @param array<string, mixed> $details
     * @return list<array<string, mixed>>
     */
    private function buildTimeline(array $details): array
    {
        $events = [];
        $episode = $details['episode'] ?? [];

        if (!empty($episode['opened_at'])) {
            $events[] = [
                'timestamp' => (string) $episode['opened_at'],
                'kind'      => 'episode_opened',
                'title'     => 'Episode Opened',
                'status'    => (string) ($episode['status'] ?? 'open'),
                'detail'    => 'Longitudinal guidance episode initiated.',
                'record'    => $episode,
            ];
        }

        if (!empty($details['intake_referral'])) {
            $ref = $details['intake_referral'];
            $events[] = [
                'timestamp' => (string) ($ref['submitted_at'] ?? $ref['created_at'] ?? $episode['opened_at'] ?? ''),
                'kind'      => 'referral',
                'title'     => 'Intake Referral ' . ($ref['referral_number'] ?? '#' . $ref['id']),
                'status'    => (string) ($ref['status'] ?? 'submitted'),
                'detail'    => 'Source: ' . ucwords(str_replace('_', ' ', (string) ($ref['source_type'] ?? 'intake')))
                    . (!empty($ref['priority_hint']) ? ' · Hint: ' . ucfirst((string) $ref['priority_hint']) : ''),
                'record'    => $ref,
            ];
        }

        foreach ($details['appointments'] ?? [] as $apt) {
            $events[] = [
                'timestamp' => trim((string) ($apt['scheduled_date'] ?? '') . ' ' . (string) ($apt['scheduled_time'] ?? '')),
                'kind'      => 'appointment',
                'title'     => 'Appointment ' . ($apt['appointment_number'] ?? '#' . $apt['id']),
                'status'    => (string) ($apt['status'] ?? 'scheduled'),
                'detail'    => 'Purpose: ' . ucwords(str_replace('_', ' ', (string) ($apt['purpose'] ?? 'counseling'))),
                'record'    => $apt,
            ];
        }

        foreach ($details['call_slips'] ?? [] as $slip) {
            $events[] = [
                'timestamp' => trim((string) ($slip['call_date'] ?? '') . ' ' . (string) ($slip['call_time'] ?? '')),
                'kind'      => 'call_slip',
                'title'     => 'Call Slip ' . ($slip['call_slip_number'] ?? '#' . $slip['id']),
                'status'    => (string) ($slip['attendance_status'] ?? 'pending'),
                'detail'    => 'Purpose: ' . ucwords(str_replace('_', ' ', (string) ($slip['purpose'] ?? 'counseling')))
                    . (!empty($slip['parent_guardian_delivery']) ? ' · Guardian delivery flagged' : ''),
                'record'    => $slip,
            ];
        }

        if (!empty($details['case'])) {
            $c = $details['case'];
            $events[] = [
                'timestamp' => (string) ($c['opened_at'] ?? $c['created_at'] ?? ''),
                'kind'      => 'case',
                'title'     => 'Counseling Case ' . ($c['case_number'] ?? '#' . $c['id']) . ': ' . ($c['title'] ?? ''),
                'status'    => (string) ($c['status'] ?? 'open'),
                'detail'    => 'Category: ' . ucwords((string) ($c['category'] ?? 'personal')),
                'record'    => $c,
            ];
        }

        foreach ($details['sessions'] ?? [] as $sess) {
            $events[] = [
                'timestamp' => (string) ($sess['started_at'] ?? $sess['created_at'] ?? ''),
                'kind'      => 'session',
                'title'     => 'Counseling Session #' . ($sess['session_number'] ?? '') . ' (' . ucwords(str_replace('_', ' ', (string) ($sess['session_type'] ?? 'regular'))) . ')',
                'status'    => (string) ($sess['status'] ?? 'in_progress'),
                'detail'    => !empty($sess['ended_at']) ? 'Ended: ' . $sess['ended_at'] : 'Session active / recorded',
                'record'    => $sess,
            ];
        }

        foreach ($details['evaluations'] ?? [] as $ev) {
            $events[] = [
                'timestamp' => (string) ($ev['created_at'] ?? ''),
                'kind'      => 'form',
                'title'     => ($ev['canonical_title'] ?? 'Controlled Form') . ' (' . ($ev['form_code'] ?? '') . ')',
                'status'    => (string) ($ev['status'] ?? 'draft'),
                'detail'    => 'Instance: ' . ($ev['instance_number'] ?? '#' . $ev['id']),
                'record'    => $ev,
            ];
        }

        if (!empty($episode['closed_at'])) {
            $events[] = [
                'timestamp' => (string) $episode['closed_at'],
                'kind'      => 'episode_closed',
                'title'     => 'Episode Closed',
                'status'    => 'closed',
                'detail'    => (string) ($episode['closure_reason'] ?? 'Closed'),
                'record'    => $episode,
            ];
        }

        usort($events, static fn (array $a, array $b): int => strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? '')));

        return $events;
    }

    /**
     * Load active qualified guidance practitioners for open/transfer modals.
     *
     * @return list<array<string, mixed>>
     */
    private function loadQualifiedCounselors(int $tenantId): array
    {
        $db = Database::connect();
        $today = date('Y-m-d');

        $rows = $db->table('guidance_practitioner_profiles gpp')
            ->select('gpp.user_id, gpp.credential_tier, gpp.credential_expiry, u.username, u.role, p.first_name, p.last_name')
            ->join('users u', 'u.id = gpp.user_id AND u.tenant_id = gpp.tenant_id')
            ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id AND p.deleted_at IS NULL', 'left')
            ->where('gpp.tenant_id', $tenantId)
            ->where('gpp.is_active', 1)
            ->where('gpp.professional_standing', 'active')
            ->whereIn('gpp.credential_tier', GuidancePractitionerService::QUALIFIED_TIERS)
            ->where('u.status', 'active')
            ->where('u.deleted_at', null)
            ->orderBy('p.last_name', 'ASC')
            ->orderBy('u.username', 'ASC')
            ->get()->getResultArray();

        return array_values(array_filter($rows, static function (array $row) use ($today): bool {
            return empty($row['credential_expiry']) || (string) $row['credential_expiry'] >= $today;
        }));
    }

    private function resolveStaffActor(): array
    {
        try {
            $actor = $this->accessPolicy->current();
        } catch (DomainException|RuntimeException $e) {
            $user = (new AuthenticatedUser())->current();
            if ($user === null) {
                throw new RuntimeException(AuthenticatedUser::AUTHENTICATION_MESSAGE, 401, $e);
            }
            $actor = $this->accessPolicy->authorize([
                'id'        => (int) $user['id'],
                'tenant_id' => (int) $user['tenant_id'],
            ]);
        }

        $perms = $actor['permissions'] ?? [];
        $allowed = in_array('guidance.cases.read', $perms, true)
            || in_array('guidance.cases.read_any', $perms, true)
            || in_array('guidance.intake.manage', $perms, true)
            || in_array('guidance.intake.process', $perms, true)
            || ($actor['role'] ?? '') === 'superadmin';
        if (!$allowed) {
            throw new DomainException('Guidance permission forbidden.', 403);
        }

        return $actor;
    }

    private function mutate(callable $operation, string $fallback)
    {
        try {
            $actor = $this->resolveStaffActor();
            [$url, $message] = $operation($actor);
        } catch (Throwable $error) {
            [$status, $message] = $this->safeFailure($error, 'episodes.mutation');
            if ($this->wantsJson()) {
                return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')
                    ->setJSON([
                        'success' => false,
                        'message' => $message,
                        'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }
            return ($status === 422 ? redirect()->back() : redirect()->to($fallback))->with('error', $message);
        }

        $targetUrl = site_url($url);
        if ($this->wantsJson()) {
            if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
                session()->setFlashdata('success', $message);
            }
            return $this->response->setStatusCode(200)->setHeader('Cache-Control', 'no-store')
                ->setJSON([
                    'success'  => true,
                    'message'  => $message,
                    'redirect' => $targetUrl,
                    'csrf'     => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
        }

        return redirect()->to($targetUrl)->with('success', $message);
    }

    private function wantsJson(): bool
    {
        return $this->request->isAJAX()
            || strtolower($this->request->getHeaderLine('HX-Request')) === 'true'
            || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'application/json')
            || $this->request->is('json');
    }

    private function safeFailure(Throwable $error, string $operation, int $recordId = 0): array
    {
        $status = (int) $error->getCode();
        if (!in_array($status, [401, 403, 404, 409, 422], true)) {
            $status = 500;
            log_message('error', 'Guidance episode error: operation={operation} record_id={record_id} exception={exception_class} code={error_code}', [
                'operation'       => $operation,
                'record_id'       => $recordId,
                'exception_class' => get_class($error),
                'error_code'      => $error->getCode(),
            ]);
        }

        $message = match ($status) {
            401     => AuthenticatedUser::AUTHENTICATION_MESSAGE,
            403     => $error->getMessage() !== '' ? $error->getMessage() : 'You are not authorized to perform this action.',
            404     => 'The requested guidance episode was not found.',
            409     => $error->getMessage() !== '' ? $error->getMessage() : 'Episode state conflict.',
            422     => $error->getMessage() !== '' ? $error->getMessage() : 'Please check the required fields and try again.',
            default => 'Unable to complete the episode request. Please try again later.',
        };

        return [$status, $message];
    }
}

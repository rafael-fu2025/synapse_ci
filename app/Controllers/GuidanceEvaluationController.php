<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\GuidanceEvaluationTokenModel;
use App\Models\GuidanceServiceEvaluationModel;
use App\Services\AuthenticatedUser;
use App\Services\Guidance\GuidanceActivationGateService;
use App\Services\Guidance\GuidanceDocumentService;
use App\Services\Guidance\GuidanceServiceEvaluationService;
use App\Services\PortalContext;
use Closure;
use Config\Database;
use InvalidArgumentException;
use Throwable;

final class GuidanceEvaluationController extends BaseController
{
    public const PSYCHOSOCIAL_DIMENSIONS = [
        'rating_welcoming'   => 'The guidance personnel were approachable and welcoming.',
        'rating_empathy'     => 'The guidance personnel understood and empathized with me.',
        'rating_clarity'     => 'The goal of the psychosocial support session was clear and achieved.',
        'rating_helpfulness' => 'The psychosocial support session was helpful.',
        'rating_environment' => 'Confidentiality and a safe, supportive environment were maintained.',
    ];

    public const COUNSELING_DIMENSIONS = [
        'rating_rapport'                 => 'The counselor was approachable, welcoming, and established good rapport.',
        'rating_understanding'           => 'The counselor understood and empathized with me.',
        'rating_goal_progress'           => 'The goal of the counseling session was achieved and progress was made.',
        'rating_confidentiality_comfort' => 'Confidentiality was kept and I felt comfortable during the session.',
        'rating_overall_service'         => 'Overall, the counseling session was helpful and effective.',
    ];

    public const VISIT_TYPE_LABELS = [
        'walk_in'   => 'Voluntarily came / Walk-in',
        'called'    => 'Called by Guidance Personnel / Counselor',
        'referred'  => 'Referred',
        'follow_up' => 'Follow-up',
    ];

    public const RATING_SCALE = [
        1 => 'Not at all',
        2 => 'To a lesser extent',
        3 => 'To a moderate extent',
        4 => 'To a large extent',
        5 => 'To the full extent',
    ];

    private GuidanceServiceEvaluationService $evaluationService;
    private GuidanceDocumentService $documentService;
    private GuidanceActivationGateService $gateService;
    private GuidanceEvaluationTokenModel $tokenModel;
    private GuidanceServiceEvaluationModel $evaluationModel;
    private ?Closure $actorResolver;

    public function __construct(
        ?GuidanceServiceEvaluationService $evaluationService = null,
        ?GuidanceDocumentService $documentService = null,
        ?GuidanceActivationGateService $gateService = null,
        ?GuidanceEvaluationTokenModel $tokenModel = null,
        ?GuidanceServiceEvaluationModel $evaluationModel = null,
        ?callable $actorResolver = null
    ) {
        $this->evaluationService = $evaluationService ?? new GuidanceServiceEvaluationService();
        $this->documentService = $documentService ?? new GuidanceDocumentService();
        $this->gateService = $gateService ?? new GuidanceActivationGateService();
        $this->tokenModel = $tokenModel ?? new GuidanceEvaluationTokenModel();
        $this->evaluationModel = $evaluationModel ?? new GuidanceServiceEvaluationModel();
        $this->actorResolver = $actorResolver !== null ? Closure::fromCallable($actorResolver) : null;
    }

    /**
     * Student Portal: redeem a one-time pseudonymous service evaluation token.
     */
    public function redeem(string $token = '')
    {
        $actor = $this->resolvePortalActor('guidance.evaluations.submit');
        if (!is_array($actor)) {
            return $actor;
        }

        $tenantId = (int) $actor['tenant_id'];
        $rawToken = trim($token !== '' ? $token : (string) ($this->request->getGet('token') ?? ''));

        $tokenState = 'not_found';
        $evaluationType = GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL;
        $formCode = GuidanceServiceEvaluationService::FORM_CODE_PSYCHOSOCIAL;

        if ($rawToken !== '') {
            $tokenHash = hash('sha256', $rawToken);
            $tokenRow = $this->tokenModel->findByTokenHash($tokenHash, $tenantId);

            if ($tokenRow !== null) {
                $evaluationType = (string) $tokenRow['evaluation_type'];
                $formCode = GuidanceServiceEvaluationService::FORM_CODES[$evaluationType]
                    ?? GuidanceServiceEvaluationService::FORM_CODE_PSYCHOSOCIAL;

                if ($tokenRow['status'] === 'redeemed') {
                    $tokenState = 'redeemed';
                } elseif (
                    $tokenRow['status'] === 'expired'
                    || (!empty($tokenRow['expires_at']) && strtotime((string) $tokenRow['expires_at']) < time())
                ) {
                    $tokenState = 'expired';
                } elseif ($tokenRow['status'] === 'issued') {
                    $tokenState = 'valid';
                }
            }
        }

        $isPsychosocial = $evaluationType === GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL;

        $this->response->setHeader('Cache-Control', 'private, no-store');

        return view('portal/evaluations/submit', [
            'title'            => $isPsychosocial ? 'Psychosocial Support Evaluation' : 'Counseling Evaluation',
            'pageTitle'        => $isPsychosocial ? 'Psychosocial Support Evaluation Form' : 'Counseling Evaluation Form',
            'tokenState'       => $tokenState,
            'rawToken'         => $tokenState === 'valid' ? $rawToken : '',
            'evaluationType'   => $evaluationType,
            'formCode'         => $formCode,
            'isPsychosocial'   => $isPsychosocial,
            'dimensions'       => $isPsychosocial ? self::PSYCHOSOCIAL_DIMENSIONS : self::COUNSELING_DIMENSIONS,
            'visitTypes'       => self::VISIT_TYPE_LABELS,
            'ratingScale'      => self::RATING_SCALE,
            'booleanField'     => $isPsychosocial ? 'future_visit_willingness' : 'recommend_service',
            'booleanQuestion'  => $isPsychosocial
                ? 'Would you still wish to come and visit your guidance personnel in the future?'
                : 'Would you recommend this counseling service / wish to visit your counselor in the future?',
            'commentField'     => $isPsychosocial ? 'qualitative_comments' : 'qualitative_feedback',
            'submitUrl'        => site_url('/portal/evaluations/submit'),
            'breadcrumbs'      => [
                ['label' => 'Campus Portal', 'url' => site_url('/portal')],
                ['label' => 'Service Evaluation', 'url' => ''],
            ],
        ]);
    }

    /**
     * Student Portal: submit pseudonymous service evaluation using one-time token.
     */
    public function submit()
    {
        $actor = $this->resolvePortalActor('guidance.evaluations.submit');
        if (!is_array($actor)) {
            return $actor;
        }

        $tenantId = (int) $actor['tenant_id'];

        try {
            $input = $this->requestInput();
            $rawToken = trim((string) ($input['raw_token'] ?? $input['token'] ?? ''));
            if ($rawToken === '') {
                throw new InvalidArgumentException('Evaluation redemption token is required.', 422);
            }

            // Never store person_id, session_id, episode_id, or token_id in evaluation payload
            $payload = [
                'visit_type'               => $input['visit_type'] ?? null,
                'academic_term'            => $input['academic_term'] ?? null,
                'academic_year'            => $input['academic_year'] ?? null,
                'ratings'                  => is_array($input['ratings'] ?? null) ? $input['ratings'] : [],
                'future_visit_willingness' => $input['future_visit_willingness'] ?? null,
                'recommend_service'        => $input['recommend_service'] ?? null,
                'boolean_response'         => $input['boolean_response'] ?? null,
                'qualitative_comments'     => $input['qualitative_comments'] ?? null,
                'qualitative_feedback'     => $input['qualitative_feedback'] ?? null,
            ];

            foreach (array_merge(array_keys(self::PSYCHOSOCIAL_DIMENSIONS), array_keys(self::COUNSELING_DIMENSIONS)) as $field) {
                if (array_key_exists($field, $input)) {
                    $payload[$field] = $input[$field];
                }
            }

            $record = $this->evaluationService->submitEvaluation($rawToken, $payload, $tenantId, $actor);
            $message = 'Thank you. Your pseudonymous service evaluation has been recorded with zero student identity linkage.';
            $redirectUrl = site_url('/portal');

            if ($this->wantsJson()) {
                return $this->response->setStatusCode(201)
                    ->setHeader('Cache-Control', 'private, no-store')
                    ->setJSON([
                        'success'       => true,
                        'message'       => $message,
                        'evaluation_id' => (int) ($record['evaluation_id'] ?? $record['id'] ?? 0),
                        'form_code'     => (string) ($record['form_code'] ?? ''),
                        'redirect'      => $redirectUrl,
                        'csrf'          => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }

            return redirect()->to($redirectUrl)->with('success', $message);
        } catch (Throwable $e) {
            $fallback = !empty($input['raw_token'])
                ? site_url('/portal/evaluations/redeem/' . rawurlencode((string) $input['raw_token']))
                : site_url('/portal');
            return $this->handleFailure($e, $fallback);
        }
    }

    /**
     * Guidance Staff Console: threshold-safe provider aggregate dashboard.
     */
    public function dashboard()
    {
        $actor = $this->resolveGuidanceActor('guidance.evaluations.view_aggregates');
        if (!is_array($actor)) {
            return $actor;
        }

        $tenantId = (int) $actor['tenant_id'];
        $canViewAnyProvider = $this->canViewAnyProvider($actor);

        $requestedProviderId = (int) ($this->request->getGet('provider_user_id') ?? 0);
        $providerUserId = ($canViewAnyProvider && $requestedProviderId > 0)
            ? $requestedProviderId
            : (int) $actor['id'];

        $filters = $this->extractAggregateFilters();

        try {
            $psychosocial = $this->evaluationService->getProviderAggregates(
                $providerUserId,
                GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
                $filters,
                $tenantId,
                $actor
            );
            $counseling = $this->evaluationService->getProviderAggregates(
                $providerUserId,
                GuidanceServiceEvaluationService::TYPE_COUNSELING,
                $filters,
                $tenantId,
                $actor
            );
        } catch (Throwable $e) {
            return $this->handleFailure($e, site_url('/guidance'));
        }

        $providers = [];
        if ($canViewAnyProvider) {
            $providers = Database::connect()->table('users u')
                ->select('u.id, u.username, u.role, p.first_name, p.last_name')
                ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id', 'left')
                ->where('u.tenant_id', $tenantId)
                ->where('u.status', 'active')
                ->where('u.deleted_at', null)
                ->whereIn('u.role', ['counsellor', 'guidance_supervisor', 'guidance_admin'])
                ->orderBy('p.last_name', 'ASC')
                ->orderBy('u.username', 'ASC')
                ->get()
                ->getResultArray();
        }

        $cohortGate = $this->gateService->getGate(GuidanceActivationGateService::GATE_EVALUATION_COHORT_POLICY, $tenantId);
        $cohortGateEnabled = $cohortGate !== null && (int) ($cohortGate['is_enabled'] ?? 0) === 1;

        $canModerate = $actor['role'] === 'superadmin'
            || in_array('guidance.evaluations.moderate', $actor['permissions'] ?? [], true);

        $this->response->setHeader('Cache-Control', 'private, no-store');

        return view('guidance/evaluations/dashboard', [
            'title'                => 'Pseudonymous Service Evaluation Aggregates',
            'pageTitle'            => 'Service Evaluation Aggregates',
            'psychosocial'         => $psychosocial,
            'counseling'           => $counseling,
            'psychosocialLabels'   => self::PSYCHOSOCIAL_DIMENSIONS,
            'counselingLabels'     => self::COUNSELING_DIMENSIONS,
            'visitTypeLabels'      => self::VISIT_TYPE_LABELS,
            'providerUserId'       => $providerUserId,
            'providerName'         => $this->resolveProviderDisplayName($providerUserId, $tenantId),
            'providers'            => $providers,
            'canViewAnyProvider'   => $canViewAnyProvider,
            'canModerate'          => $canModerate,
            'filters'              => $filters,
            'cohortGate'           => $cohortGate,
            'cohortGateEnabled'    => $cohortGateEnabled,
            'breadcrumbs'          => [
                ['label' => 'Guidance', 'url' => site_url('/guidance')],
                ['label' => 'Evaluation Aggregates', 'url' => ''],
            ],
        ]);
    }

    /**
     * Guidance Moderator Console: review pending encrypted qualitative comments (zero student identifiers).
     */
    public function moderate()
    {
        $actor = $this->resolveGuidanceActor('guidance.evaluations.moderate');
        if (!is_array($actor)) {
            return $actor;
        }

        $tenantId = (int) $actor['tenant_id'];

        $rows = $this->evaluationModel->scoped($tenantId)
            ->select('id, evaluation_type, form_code, academic_term, academic_year, visit_type, comments_cipher, comment_moderation_status, submitted_date')
            ->where('comment_moderation_status', 'pending_review')
            ->where('comments_cipher IS NOT NULL', null, false)
            ->orderBy('id', 'ASC')
            ->findAll();

        $pendingComments = [];
        foreach ($rows as $row) {
            if (empty($row['comments_cipher'])) {
                continue;
            }
            $plaintext = $this->evaluationService->decryptComment((string) $row['comments_cipher']);
            if ($plaintext === null || trim($plaintext) === '') {
                continue;
            }

            $pendingComments[] = [
                'id'              => (int) $row['id'],
                'evaluation_type' => (string) $row['evaluation_type'],
                'form_code'       => (string) $row['form_code'],
                'academic_term'   => $row['academic_term'] ?? null,
                'academic_year'   => $row['academic_year'] ?? null,
                'visit_type'      => $row['visit_type'] ?? null,
                'submitted_date'  => (string) $row['submitted_date'],
                'comment_text'    => $plaintext,
            ];
        }

        $this->response->setHeader('Cache-Control', 'private, no-store');

        return view('guidance/evaluations/moderate', [
            'title'           => 'Moderate Evaluation Comments',
            'pageTitle'       => 'Qualitative Comment Moderation Queue',
            'pendingComments' => $pendingComments,
            'breadcrumbs'     => [
                ['label' => 'Guidance', 'url' => site_url('/guidance')],
                ['label' => 'Evaluation Aggregates', 'url' => site_url('/guidance/evaluations/dashboard')],
                ['label' => 'Comment Moderation', 'url' => ''],
            ],
        ]);
    }

    /**
     * Guidance Moderator Console: approve or redact a pending qualitative comment.
     */
    public function moderateAction(int $evaluationId)
    {
        $actor = $this->resolveGuidanceActor('guidance.evaluations.moderate');
        if (!is_array($actor)) {
            return $actor;
        }

        $tenantId = (int) $actor['tenant_id'];

        try {
            $input = $this->requestInput();
            $status = strtolower(trim((string) ($input['status'] ?? '')));
            if (!in_array($status, ['approved', 'redacted'], true)) {
                throw new InvalidArgumentException("Moderation status must be 'approved' or 'redacted'.", 422);
            }

            $updated = $this->evaluationService->moderateComment($evaluationId, $status, $tenantId, $actor);
            $message = $status === 'approved'
                ? 'Qualitative comment approved for threshold-safe aggregate reporting.'
                : 'Qualitative comment redacted and excluded from aggregate reporting.';

            if ($this->wantsJson()) {
                return $this->response->setStatusCode(200)
                    ->setHeader('Cache-Control', 'private, no-store')
                    ->setJSON([
                        'success'       => true,
                        'message'       => $message,
                        'evaluation_id' => (int) ($updated['id'] ?? $evaluationId),
                        'status'        => (string) ($updated['comment_moderation_status'] ?? $status),
                        'redirect'      => site_url('/guidance/evaluations/moderate'),
                        'csrf'          => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }

            return redirect()->to(site_url('/guidance/evaluations/moderate'))->with('success', $message);
        } catch (Throwable $e) {
            return $this->handleFailure($e, site_url('/guidance/evaluations/moderate'));
        }
    }

    /**
     * Guidance Staff Console: stream compiled aggregate evaluation PDF (threshold-safe).
     */
    public function aggregatePdf()
    {
        $actor = $this->resolveGuidanceActor('guidance.evaluations.view_aggregates');
        if (!is_array($actor)) {
            return $actor;
        }

        $tenantId = (int) $actor['tenant_id'];
        $canViewAnyProvider = $this->canViewAnyProvider($actor);

        $requestedProviderId = (int) ($this->request->getGet('provider_user_id') ?? 0);
        $providerUserId = ($canViewAnyProvider && $requestedProviderId > 0)
            ? $requestedProviderId
            : (int) $actor['id'];

        $evaluationTypeRaw = trim((string) ($this->request->getGet('evaluation_type') ?? GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL));
        $filters = $this->extractAggregateFilters();

        try {
            $evaluationType = $this->evaluationService->resolveEvaluationType($evaluationTypeRaw);
            $aggregates = $this->evaluationService->getProviderAggregates(
                $providerUserId,
                $evaluationType,
                $filters,
                $tenantId,
                $actor
            );

            $snapshot = array_merge($aggregates, [
                'provider_name' => $this->resolveProviderDisplayName($providerUserId, $tenantId),
                'report_date'   => date('Y-m-d'),
            ]);

            $pdfBytes = $evaluationType === GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL
                ? $this->documentService->renderPsychosocialEvaluationPdf($snapshot)
                : $this->documentService->renderCounselingEvaluationPdf($snapshot);

            $filename = $evaluationType === GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL
                ? 'psychosocial-evaluation-aggregate.pdf'
                : 'counseling-evaluation-aggregate.pdf';

            return $this->response
                ->setStatusCode(200)
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
                ->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setBody($pdfBytes);
        } catch (Throwable $e) {
            return $this->handleFailure($e, site_url('/guidance/evaluations/dashboard'));
        }
    }

    /** @return array<string, string> */
    private function extractAggregateFilters(): array
    {
        $filters = [];
        $term = trim((string) ($this->request->getGet('academic_term') ?? ''));
        $year = trim((string) ($this->request->getGet('academic_year') ?? ''));
        if ($term !== '') {
            $filters['academic_term'] = substr($term, 0, 64);
        }
        if ($year !== '') {
            $filters['academic_year'] = substr($year, 0, 32);
        }
        return $filters;
    }

    private function canViewAnyProvider(array $actor): bool
    {
        $role = (string) ($actor['role'] ?? '');
        if (in_array($role, ['guidance_supervisor', 'guidance_admin', 'superadmin'], true)) {
            return true;
        }
        $perms = $actor['permissions'] ?? [];
        return in_array('guidance.evaluations.moderate', $perms, true)
            || in_array('guidance.cases.read_any', $perms, true);
    }

    private function resolveProviderDisplayName(int $userId, int $tenantId): string
    {
        $row = Database::connect()->table('users u')
            ->select('u.username, p.first_name, p.last_name')
            ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id', 'left')
            ->where('u.id', $userId)
            ->where('u.tenant_id', $tenantId)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return 'Guidance Personnel #' . $userId;
        }

        $name = trim(trim((string) ($row['first_name'] ?? '')) . ' ' . trim((string) ($row['last_name'] ?? '')));
        return $name !== '' ? $name : (string) ($row['username'] ?? ('Guidance Personnel #' . $userId));
    }

    private function resolvePortalActor(string $permission)
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

    private function resolveGuidanceActor(string $permission)
    {
        if ($this->actorResolver !== null) {
            return ($this->actorResolver)($permission);
        }

        $user = (new AuthenticatedUser())->current();
        if ($user === null) {
            return AuthenticatedUser::denied($this->request, false, 'Please sign in to continue.', '/auth/login');
        }

        if (!PortalContext::isRolePermitted((string) ($user['role'] ?? ''), 'guidance')) {
            return AuthenticatedUser::denied($this->request, true, 'Guidance department access forbidden.', '/portal');
        }

        $perms = $user['permissions'] ?? [];
        $allowed = ($user['role'] ?? '') === 'superadmin'
            || in_array($permission, $perms, true)
            || ($permission === 'guidance.evaluations.view_aggregates' && in_array('guidance.evaluations.view_aggregate', $perms, true));

        if (!$allowed) {
            return AuthenticatedUser::denied($this->request, true, 'Permission required: ' . $permission, '/guidance');
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

    private function handleFailure(Throwable $e, string $redirectUrl)
    {
        $code = (int) $e->getCode();
        $status = in_array($code, [400, 401, 403, 404, 409, 422], true) ? $code : 500;
        $message = $status === 500
            ? 'Unable to process the evaluation request right now. Please try again.'
            : $e->getMessage();

        if ($status === 500) {
            log_message('error', 'GuidanceEvaluationController error: {class} {msg}', [
                'class' => get_class($e),
                'msg'   => $e->getMessage(),
            ]);
        }

        if ($this->wantsJson()) {
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
}

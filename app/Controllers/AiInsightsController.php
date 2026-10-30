<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Ai\AiAccess;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AnalysisCatalog;
use App\Services\Ai\InsightApplication;
use App\Services\AuthenticatedUser;
use Throwable;

class AiInsightsController extends BaseController
{
    public function index()
    {
        try {
            $actor = $this->actor('ai.insights.read');
            return $this->response->setHeader('Cache-Control', 'private, no-store')->setBody(view('ai/index',
                ['title' => 'Department Insights & Program Planning'] + $this->application()->panel($actor)));
        } catch (Throwable $error) { return $this->failure($error); }
    }

    public function panel()
    {
        try {
            $data = $this->application()->panel($this->actor('ai.insights.read'));
            return $this->json(['success' => true, 'html' => view('ai/panel', $data)]);
        } catch (Throwable $error) { return $this->failure($error); }
    }

    public function prepare()
    {
        return $this->mutation(function (array $actor): array {
            $analysisCode = $this->analysisCode();
            $payload = $this->application()->prepare($actor, $analysisCode);
            return ['message' => 'Local summary ready. No AI service was contacted.', 'baseline' => view('ai/evidence', ['evidence' => $payload])];
        });
    }

    public function generate()
    {
        return $this->mutation(function (array $actor): array {
            $analysisCode = $this->analysisCode();
            $job = $this->application()->generate($actor, $analysisCode);
            return ['job_id' => $job['id'], 'status' => $job['status'], 'reused' => $job['reused'],
                'message' => $job['reused'] ? 'An existing request was found. No duplicate was created. Check its status below.'
                    : 'Draft request queued for a separate worker. No AI service was contacted by this action.'];
        }, 202);
    }

    public function review(int $id)
    {
        return $this->mutation(function (array $actor) use ($id): array {
            $input = $this->input(['version', 'decision']);
            $version = $this->positiveId($input['version'] ?? null);
            $decision = $input['decision'] ?? null;
            if (!is_string($decision) || !in_array($decision, ['accept', 'reject', 'approve'], true) || $id < 1) {
                throw new AiFailure('invalid_review', 422);
            }
            $this->application()->review($actor, $id, $version, $decision);
            return ['message' => $decision === 'approve' ? 'Program draft approved. Nothing was published, funded, scheduled, or sent to anyone.' : 'Review decision saved.'];
        }, permission: 'ai.insights.read');
    }

    public function cancel(int $id)
    {
        return $this->mutation(function (array $actor) use ($id): array {
            $this->input([]);
            if ($id < 1) { throw new AiFailure('invalid_job', 422); }
            $this->application()->cancel($actor, $id);
            return ['message' => 'Waiting request cancelled. Data already sent cannot be recalled.'];
        });
    }

    protected function actor(string $permission): array { return AiAccess::current($permission, $this->unit()); }
    protected function application(): InsightApplication { return new InsightApplication(unit: $this->unit()); }

    /** Scope comes from the workstation path dispatched by the server, never form/query fields. */
    protected function unit(): string
    {
        $path = $this->request->getUri()->getPath();
        if (preg_match('~(?:^|/)(clinic|guidance|facilities)/insights(?:/|$)~', $path, $match)) {
            return $match[1] === 'facilities' ? 'bmg' : $match[1];
        }
        // The original clinic controller is also used by legacy isolated fixtures.
        return 'clinic';
    }

    private function analysisCode(): string
    {
        $input = $this->input(['analysis_code']);
        $analyses = AnalysisCatalog::forUnit($this->unit());
        $code = array_key_exists('analysis_code', $input) ? $input['analysis_code'] : $analyses[0]['code'];
        if (!is_string($code) || !in_array($code, array_column($analyses, 'code'), true)) {
            throw new AiFailure('invalid_request', 422);
        }
        return $code;
    }

    private function mutation(callable $action, int $status = 200, string $permission = 'ai.insights.generate')
    {
        if (strtoupper($this->request->getMethod()) !== 'POST') {
            return $this->response->setStatusCode(405)->setHeader('Allow', 'POST')->setHeader('Cache-Control', 'private, no-store')
                ->setJSON(['success' => false, 'feedback_type' => 'error', 'message' => 'Use POST for this action.']);
        }
        try { return $this->json(['success' => true] + $action($this->actor($permission)), $status); }
        catch (Throwable $error) { return $this->failure($error); }
    }

    /** Only fixed form fields; no dates, patient/cohort selectors, prompt, model, or URL. */
    private function input(array $allowed): array
    {
        $type = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'), 2)[0]));
        $input = $this->request->getPost();
        if (($type !== '' && $type !== 'application/x-www-form-urlencoded') || !is_array($input)
            || array_diff(array_keys($input), [...$allowed, csrf_token()]) !== [] || $this->request->getGet() !== []) {
            throw new AiFailure('invalid_request', 422);
        }
        return $input;
    }

    private function positiveId(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]*\z/D', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT) === false) { throw new AiFailure('invalid_review', 422); }
        return (int) $value;
    }

    private function json(array $payload, int $status = 200)
    {
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')
            ->setJSON($payload + ['csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
    }

    private function failure(Throwable $error)
    {
        if ($error instanceof AiFailure && in_array($error->reason, ['authentication_required', 'access_denied'], true)) {
            return AuthenticatedUser::denied($this->request, $error->reason === 'access_denied',
                $error->reason === 'access_denied' ? 'You do not have access to this AI action.' : AuthenticatedUser::AUTHENTICATION_MESSAGE,
                site_url($error->reason === 'access_denied' ? ($this->unit() === 'bmg' ? 'facilities' : $this->unit()) : 'auth/login'));
        }
        $reason = $error instanceof AiFailure ? $error->reason : 'store_unavailable';
        $message = match ($reason) {
            'insufficient_data' => match ($this->unit()) {
                'guidance' => 'No comparison is available yet. Both completed months need enough different people with completed counselling sessions and valid linked records to meet the privacy rules. Check that eligible sessions are recorded as completed. Hidden or unavailable values are not zero. This is not an AI connection error.',
                'bmg' => 'No comparison is available yet. Both completed months need eligible recorded events for this facilities comparison. Check the batch records or accepted rotation reports for the selected analysis. Unavailable values are not zero. This is not an AI connection error.',
                default => 'No comparison is available yet. Both completed months need enough different people with completed clinic visits and valid linked records to meet the privacy rules. Check that eligible visits are recorded as completed. Hidden or unavailable values are not zero. This is not an AI connection error.',
            },
            'snapshot_changed_requires_review' => 'Records changed after a monthly summary was shared. A privacy review is needed before corrected values can be shown.',
            'daily_budget_exceeded' => 'The daily AI request limit has been reached.',
            'review_conflict', 'invalid_review_transition' => 'The review changed or this decision is no longer available. Refresh the page before continuing.',
            'invalid_request', 'invalid_review', 'invalid_job' => 'The submitted action is not valid.',
            'disabled' => 'AI requests are turned off. Local summaries still require separate approval and privacy checks.',
            'dispatch_paused' => 'AI requests are paused. Ask an administrator to review the setup and resume requests. This action sent no AI request.',
            'store_unavailable', 'encryption_unavailable' => 'Secure storage for insights is not ready. Ask an administrator to check storage and encryption.',
            'snapshot_expired' => 'This summary has expired. An administrator must review previously shared summaries before another can be shared.',
            'library_not_approved', 'compatibility_required', 'revision_not_approved', 'governance_required', 'tenant_not_approved', 'unit_not_approved', 'endpoint_changed' => 'The current setup still needs technical or privacy approval. Ask an administrator for help.',
            default => 'This action is unavailable. It was not retried automatically.',
        };
        return $this->json(['success' => false, 'reason' => $reason, 'message' => $message,
            'feedback_type' => $reason === 'insufficient_data' ? 'warning' : 'error'], $error instanceof AiFailure ? $error->getCode() : 503);
    }
}

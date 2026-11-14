<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EngagementAccess;
use App\Services\Guidance\SurveyDraftAiExtractor;
use App\Services\Guidance\SurveyService;
use App\Services\Reporting\ExportCsv;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class SurveyController extends BaseController
{
    public function index()
    {
        try {
            $data = (new SurveyService())->listing($this->request->getGet());
            $this->response->setHeader('Cache-Control', 'private, no-store');
            return view('surveys/index', $data + ['title' => 'Guidance Surveys', 'baseUrl' => site_url('guidance/surveys')]);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function create()
    {
        try {
            $actor = EngagementAccess::actor('guidance.surveys.manage', 'guidance');
            if (!in_array($actor['role'], ['superadmin', 'guidance_admin', 'guidance_supervisor'], true)) {
                throw new RuntimeException('Guidance survey administrator access required.', 403);
            }
            return $this->editor(null);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function edit(int $id)
    {
        try {
            $survey = (new SurveyService())->get($id);
            if ($survey['status'] !== 'draft') { throw new RuntimeException('Published surveys cannot be edited.', 409); }
            foreach (['starts_at', 'ends_at'] as $field) {
                $survey[$field] = $survey[$field] === null ? '' : (new DateTimeImmutable($survey[$field], new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d\TH:i');
            }
            return $this->editor($survey);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    private function editor(?array $survey)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        $aiStatus = ['available' => false, 'reason' => 'AI assistance is currently unavailable for Guidance.'];
        try {
            $aiStatus = (new SurveyDraftAiExtractor())->status();
        } catch (Throwable) {
        }
        return view('surveys/form', ['title' => $survey === null ? 'Create Survey' : 'Edit Draft Survey',
            'survey' => $survey, 'cancelUrl' => site_url('guidance/surveys'), 'formModalPage' => true,
            'aiPrefillStatus' => $aiStatus,
            'aiPrefillUrl' => site_url('guidance/surveys/ai-prefill'),
            'formAction' => site_url('guidance/surveys/' . ($survey === null ? 'store' : $survey['id'] . '/update'))]);
    }

    public function aiPrefill()
    {
        $rawText = is_string($this->request->getPost('raw_text')) ? $this->request->getPost('raw_text') : null;
        $file    = $this->request->getFile('survey_file');
        $clarifications = [];
        $rawClarifications = $this->request->getPost('clarifications_json');
        if (is_string($rawClarifications) && trim($rawClarifications) !== '') {
            try {
                $decoded = json_decode($rawClarifications, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $clarifications = $decoded;
                }
            } catch (Throwable) {
                $clarifications = [];
            }
        }

        $wantsSse = str_contains(strtolower($this->request->getHeaderLine('Accept')), 'text/event-stream');
        if (! $wantsSse) {
            try {
                $result = (new SurveyDraftAiExtractor())->extract($file, $rawText, true, $clarifications);
                return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON([
                    'success' => true,
                    'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ] + $result);
            } catch (Throwable $e) {
                return $this->failure($e);
            }
        }

        $csrfPayload = ['name' => csrf_token(), 'hash' => csrf_hash()];
        $started = false;
        $sendSse = static function (string $event, array $payload) use (&$started): void {
            if (! $started) {
                $started = true;
                if (session_status() === PHP_SESSION_ACTIVE) {
                    @session_write_close();
                }
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                if (! headers_sent()) {
                    http_response_code(200);
                    header('Content-Type: text/event-stream; charset=utf-8');
                    header('Cache-Control: no-cache, private, no-store');
                    header('X-Accel-Buffering: no');
                }
            }
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                return;
            }
            echo 'event: ' . $event . "\n";
            echo 'data: ' . $encoded . "\n\n";
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();
        };

        try {
            $result = (new SurveyDraftAiExtractor())->extractStream($file, $rawText, $sendSse, true, $clarifications);
            $sendSse('stage', [
                'step'    => 4,
                'total'   => 4,
                'percent' => 100,
                'stage'   => 'done',
                'label'   => 'Questions ready for review.',
            ]);
            $sendSse('result', [
                'success' => true,
                'csrf'    => $csrfPayload,
            ] + $result);
        } catch (Throwable $e) {
            if (! $started) {
                return $this->failure($e);
            }
            $code = (int) $e->getCode();
            $status = in_array($code, [403, 404, 409, 422, 429, 503], true) ? $code : 422;
            $sendSse('failure', [
                'success' => false,
                'status'  => $status,
                'message' => $status === 422 || $status === 403 || $status === 429 || $status === 503
                    ? $e->getMessage()
                    : 'Unable to extract survey questions cleanly.',
                'csrf'    => $csrfPayload,
            ]);
        }

        return $this->response->setStatusCode(200)->setBody('');
    }

    public function store()
    {
        try {
            (new SurveyService())->create($this->payload());
            return $this->success('Survey draft created.', $this->staffRefresh());
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function update(int $id)
    {
        try {
            (new SurveyService())->update($id, $this->payload());
            return $this->success('Survey draft updated.', $this->staffRefresh());
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function publish(int $id)
    {
        try {
            (new SurveyService())->publish($id, $this->request->getPost('version'));
            return $this->success('Survey published. Questions and targeting are now immutable.', $this->staffRefresh());
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function close(int $id)
    {
        try {
            (new SurveyService())->close($id, $this->request->getPost('version'));
            return $this->success('Survey closed. Existing responses remain available.', $this->staffRefresh());
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function responses(int $id)
    {
        try {
            $data = (new SurveyService())->responses($id, $this->request->getGet());
            $this->response->setHeader('Cache-Control', 'private, no-store');
            return view('surveys/responses', $data + ['title' => 'Survey Responses',
                'baseUrl' => site_url('guidance/surveys/' . $id . '/responses')]);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function exportResponses(int $id)
    {
        try {
            $export = (new SurveyService())->exportResponses($id, $this->request->getPost());
            return ExportCsv::response($this->response, $export['csv'], $export['filename']);
        } catch (Throwable $e) {
            // No exception logging here: driver messages and traces may contain decrypted data.
            $status = $e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 404, 422], true) ? $e->getCode() : 503;
            return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')
                ->setJSON(['message' => $status === 503 ? 'Survey export unavailable. No download was produced.' : $e->getMessage()]);
        }
    }

    public function responseDetail(int $id, int $responseId)
    {
        try {
            $data = (new SurveyService())->responseDetail($id, $responseId);
            $this->response->setHeader('Cache-Control', 'private, no-store');
            return view('surveys/response', $data + ['title' => 'Identified Survey Response']);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    /**
     * Records staff-only interview attribution and encrypted personnel remarks on a student's survey response.
     */
    public function recordInterview(int $id, int $responseId)
    {
        try {
            (new SurveyService())->recordInterview($id, $responseId, $this->payload());
            $redirect = site_url('guidance/surveys/' . $id . '/responses/' . $responseId);
            $message = 'Staff interview attribution and remarks saved.';
            if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
                session()->setFlashdata('success', $message);
            }
            if ($this->request->isAJAX()) {
                return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON([
                    'success'  => true,
                    'message'  => $message,
                    'redirect' => $redirect,
                    'csrf'     => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
            }
            return redirect()->to($redirect)->setHeader('Cache-Control', 'private, no-store');
        } catch (Throwable $e) { return $this->failure($e); }
    }

    private function payload(): array
    {
        // Never accept client-controlled identity, state, timestamps or ciphertext.
        $post = $this->request->getPost();
        unset($post[csrf_token()]);
        return $post;
    }

    private function success(string $message, ?array $refresh = null)
    {
        if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
            session()->setFlashdata('success', $message);
        }
        if ($this->request->isAJAX()) {
            $payload = [
                'success' => true, 'message' => $message, 'redirect' => site_url('guidance/surveys'),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ];
            if ($refresh !== null) { $payload['refresh'] = $refresh; }
            return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON($payload);
        }
        return redirect()->to(site_url('guidance/surveys'))->setHeader('Cache-Control', 'private, no-store');
    }

    private function staffRefresh(): ?array
    {
        $owner = 'surveys.staff';
        if (!config(\Config\RefreshRegions::class)->enables($owner)
            || $this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') { return null; }

        return $this->refreshRegions($owner, ['surveys.list']);
    }

    private function failure(Throwable $e)
    {
        log_message('error', 'SurveyController exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        $status = $e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 404, 409, 422, 429, 503], true) ? $e->getCode() : 503;
        $message = $status === 503 ? 'Surveys are temporarily unavailable. Contact the administrator.' : $e->getMessage();
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')->setJSON([
            'success' => false, 'message' => $message, 'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }
}

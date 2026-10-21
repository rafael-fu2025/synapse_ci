<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EngagementAccess;
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
        return view('surveys/form', ['title' => $survey === null ? 'Create Survey' : 'Edit Draft Survey',
            'survey' => $survey, 'cancelUrl' => site_url('guidance/surveys'), 'formModalPage' => true,
            'formAction' => site_url('guidance/surveys/' . ($survey === null ? 'store' : $survey['id'] . '/update'))]);
    }

    public function store()
    {
        try {
            (new SurveyService())->create($this->payload());
            return $this->success('Survey draft created.');
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function update(int $id)
    {
        try {
            (new SurveyService())->update($id, $this->payload());
            return $this->success('Survey draft updated.');
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function publish(int $id)
    {
        try {
            (new SurveyService())->publish($id);
            return $this->success('Survey published. Questions and targeting are now immutable.');
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function close(int $id)
    {
        try {
            (new SurveyService())->close($id);
            return $this->success('Survey closed. Existing responses remain available.');
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

    private function payload(): array
    {
        // Never accept client-controlled identity, state, timestamps or ciphertext.
        $post = $this->request->getPost();
        unset($post[csrf_token()]);
        return $post;
    }

    private function success(string $message)
    {
        session()->setFlashdata('success', $message);
        if ($this->request->isAJAX()) {
            return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON([
                'success' => true, 'message' => $message, 'redirect' => site_url('guidance/surveys'),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        }
        return redirect()->to(site_url('guidance/surveys'))->setHeader('Cache-Control', 'private, no-store');
    }

    private function failure(Throwable $e)
    {
        log_message('error', 'SurveyController exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        $status = $e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 404, 409, 422, 503], true) ? $e->getCode() : 503;
        $message = $status === 503 ? 'Surveys are temporarily unavailable. Contact the administrator.' : $e->getMessage();
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')->setJSON([
            'success' => false, 'message' => $message, 'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }
}

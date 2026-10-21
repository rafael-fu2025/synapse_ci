<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Guidance\SurveyService;
use RuntimeException;
use Throwable;

final class PortalSurveyController extends BaseController
{
    public function index()
    {
        try {
            $data = (new SurveyService())->available($this->request->getGet());
            $this->response->setHeader('Cache-Control', 'private, no-store');
            return view('surveys/portal_index', $data + ['title' => 'My Surveys', 'baseUrl' => site_url('portal/surveys')]);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function show(int $id)
    {
        try {
            $survey = (new SurveyService())->form($id);
            $this->response->setHeader('Cache-Control', 'private, no-store');
            return view('surveys/take', ['title' => $survey['title'], 'survey' => $survey]);
        } catch (Throwable $e) { return $this->failure($e); }
    }

    public function submit(int $id)
    {
        try {
            $post = $this->request->getPost();
            unset($post[csrf_token()]);
            if (array_diff(array_keys($post), ['answers']) !== []) { throw new RuntimeException('Unexpected submission fields.', 422); }
            (new SurveyService())->submit($id, $post['answers'] ?? []);
            // No withInput(): answers must never enter session old-input or flash storage.
            $message = 'Your identified survey response has been submitted.';
            session()->setFlashdata('success', $message);
            if ($this->request->isAJAX()) {
                return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON([
                    'success' => true, 'message' => $message, 'redirect' => site_url('portal/surveys'),
                    'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
            }
            return redirect()->to(site_url('portal/surveys'))->setHeader('Cache-Control', 'private, no-store');
        } catch (Throwable $e) { return $this->failure($e); }
    }

    private function failure(Throwable $e)
    {
        $status = $e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 404, 409, 422, 503], true) ? $e->getCode() : 503;
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')->setJSON([
            'success' => false,
            'message' => $status === 503 ? 'Surveys are temporarily unavailable. Contact the administrator.' : $e->getMessage(),
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }
}

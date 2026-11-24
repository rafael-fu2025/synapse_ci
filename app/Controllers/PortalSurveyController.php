<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\Guidance\GuidanceActivationGateService;
use App\Services\Guidance\GuidanceMultipleIntelligenceService;
use App\Services\Guidance\SurveyService;
use RuntimeException;
use Throwable;

final class PortalSurveyController extends BaseController
{
    public function index()
    {
        try {
            $query = $this->request->getGet();
            $miPage = \App\Libraries\TablePage::forList($query, 'mi_page', 'mi_per_page');
            if (!in_array($miPage->perPage, [25, 50, 100], true)) {
                throw new \InvalidArgumentException('MI per page must be 25, 50 or 100.', 422);
            }
            $data = (new SurveyService())->available($query);
            $data['filters']['page'] = $data['pagination']['page'];
            $data['filters']['per_page'] = $data['pagination']['per_page'];
            $miAssignments = [];
            $miCounts = array_fill_keys(['all', 'available', 'submitted', 'closed'], 0);
            $miPagination = $miPage->metadata(0, 0);
            $miGateEnabled = false;
            $actor = (new AuthenticatedUser())->current();
            if ($actor !== null && (($actor['role'] ?? '') === 'superadmin'
                || in_array('guidance.assessments.take', $actor['permissions'] ?? [], true))) {
                try {
                    $miResult = (new GuidanceMultipleIntelligenceService())->listPortalAssignmentsPage($actor, $query);
                    $miAssignments = $miResult['assignments'];
                    $miCounts = $miResult['counts'];
                    $miPagination = $miResult['pagination'];
                    $miGateEnabled = (new GuidanceActivationGateService())->isGateEnabled(
                        GuidanceActivationGateService::GATE_MI_REPRODUCTION,
                        (int) $actor['tenant_id']
                    );
                } catch (Throwable $error) {
                    log_message('error', 'Portal MI assignments unavailable: {class} code={code}', [
                        'class' => get_class($error),
                        'code'  => $error->getCode(),
                    ]);
                    $miAssignments = [];
                    $data['miAssignmentsUnavailable'] = true;
                }
            }
            $data['filters']['mi_page'] = $miPagination['page'];
            $data['filters']['mi_per_page'] = $miPagination['per_page'];
            $this->response->setHeader('Cache-Control', 'private, no-store');
            return view('surveys/portal_index', $data + [
                'title' => 'My Surveys',
                'baseUrl' => site_url('portal/surveys'),
                'miAssignments' => $miAssignments,
                'miCounts' => $miCounts,
                'miPagination' => $miPagination,
                'miGateEnabled' => $miGateEnabled,
            ]);
        } catch (\InvalidArgumentException $error) {
            return $this->failure(new RuntimeException($error->getMessage(), 422));
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
            if (array_diff(array_keys($post), ['answers', 'answers_other']) !== []) { throw new RuntimeException('Unexpected submission fields.', 422); }
            (new SurveyService())->submit($id, $post['answers'] ?? [], $post['answers_other'] ?? []);
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

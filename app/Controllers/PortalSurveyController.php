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
            $filters = \App\Services\PortalContent::filters($query, ['all', 'available', 'submitted', 'closed']);
            $surveyService = new SurveyService();
            $miService = new GuidanceMultipleIntelligenceService();

            $generalSummary = $surveyService->available($query, 0, 0);
            $genCounts = $generalSummary['counts'] ?? array_fill_keys(['all', 'available', 'submitted', 'closed'], 0);
            $hasProfile = (bool) ($generalSummary['hasProfile'] ?? false);

            $miCounts = array_fill_keys(['all', 'available', 'submitted', 'closed'], 0);
            $miGateEnabled = false;
            $miUnavailable = false;
            $actor = (new AuthenticatedUser())->current();
            $canTakeMi = $actor !== null && (($actor['role'] ?? '') === 'superadmin'
                || in_array('guidance.assessments.take', $actor['permissions'] ?? [], true));

            if ($canTakeMi) {
                try {
                    $miSummary = $miService->listPortalAssignmentsPage($actor, $query, 0, 0);
                    $miCounts = $miSummary['counts'] ?? $miCounts;
                    $miGateEnabled = (new GuidanceActivationGateService())->isGateEnabled(
                        GuidanceActivationGateService::GATE_MI_REPRODUCTION,
                        (int) $actor['tenant_id']
                    );
                } catch (Throwable $error) {
                    log_message('error', 'Portal MI assignments unavailable: {class} code={code}', [
                        'class' => get_class($error),
                        'code'  => $error->getCode(),
                    ]);
                    $miUnavailable = true;
                }
            }

            $combinedCounts = [];
            foreach (['all', 'available', 'submitted', 'closed'] as $state) {
                $combinedCounts[$state] = (int) ($genCounts[$state] ?? 0) + (int) ($miCounts[$state] ?? 0);
            }

            $status = $filters['status'];
            $miFiltered = (int) ($miCounts[$status] ?? 0);
            $genFiltered = (int) ($genCounts[$status] ?? 0);
            $combinedFiltered = $miFiltered + $genFiltered;

            $perPage = $filters['per_page'];
            $totalPages = max(1, (int) ceil($combinedFiltered / $perPage));
            $page = max(1, min($totalPages, $filters['page']));
            $offset = ($page - 1) * $perPage;

            $miLimit = ($offset < $miFiltered) ? min($perPage, $miFiltered - $offset) : 0;
            $genLimit = $perPage - $miLimit;
            $genOffset = max(0, $offset - $miFiltered);

            $miAssignments = [];
            if ($canTakeMi && !$miUnavailable && $miLimit > 0) {
                try {
                    $miResult = $miService->listPortalAssignmentsPage($actor, $query, $offset, $miLimit);
                    $miAssignments = $miResult['assignments'] ?? [];
                } catch (Throwable $error) {
                    log_message('error', 'Portal MI slice unavailable: {class} code={code}', [
                        'class' => get_class($error),
                        'code'  => $error->getCode(),
                    ]);
                    $miAssignments = [];
                    $miUnavailable = true;
                }
            }

            $items = [];
            if ($hasProfile && $genLimit > 0) {
                $items = $surveyService->available($query, $genOffset, $genLimit)['items'] ?? [];
            }

            $filters['page'] = $page;
            $pagination = [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $combinedCounts['all'],
                'filtered' => $combinedFiltered,
                'pages'    => $totalPages,
            ];

            $this->response->setHeader('Cache-Control', 'private, no-store');
            $viewData = [
                'title'         => 'My Surveys',
                'baseUrl'       => site_url('portal/surveys'),
                'items'         => $items,
                'miAssignments' => $miAssignments,
                'counts'        => $combinedCounts,
                'pagination'    => $pagination,
                'filters'       => $filters,
                'hasProfile'    => $hasProfile,
                'nowUtc'        => $generalSummary['nowUtc'] ?? gmdate('Y-m-d H:i:s'),
                'miGateEnabled' => $miGateEnabled,
            ];
            if ($miUnavailable) {
                $viewData['miAssignmentsUnavailable'] = true;
            }
            return view('surveys/portal_index', $viewData);
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

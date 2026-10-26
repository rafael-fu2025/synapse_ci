<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\PortalVisitHistoryService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PortalVisitHistoryController extends BaseController
{
    public function index()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $query = $this->request->getGet();
            // Live filtering adds transport metadata; identity never comes from GET.
            $filters = array_intersect_key($query, array_flip(['service', 'page']));
            $history = (new PortalVisitHistoryService())->snapshot($filters);
            return view('portal/visit_history', $history + [
                'title' => 'My Visit History', 'pageTitle' => 'My Visit History',
                'baseUrl' => site_url('portal/visits'),
                'breadcrumbs' => [
                    ['label' => 'My Health Portal', 'url' => site_url('portal')],
                    ['label' => 'My Visit History', 'url' => ''],
                ],
            ]);
        } catch (Throwable $error) {
            if ($error instanceof RuntimeException && in_array($error->getCode(), [401, 403], true)) {
                return AuthenticatedUser::denied($this->request, $error->getCode() === 403, $error->getMessage(), $error->getCode() === 401 ? '/auth/login' : '/');
            }
            $invalid = $error instanceof InvalidArgumentException;
            if (!$invalid) log_message('error', 'Personal visit history unavailable ({type}).', ['type' => get_class($error)]);
            return $this->response->setStatusCode($invalid ? 422 : 503)->setJSON([
                'message' => $invalid ? $error->getMessage() : 'Visit history is temporarily unavailable. Please try again later.',
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\QueueDashboardService;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

final class QueueDashboardController extends BaseController
{
    public function clinic(): ResponseInterface
    {
        return $this->counts('clinic');
    }

    public function guidance(): ResponseInterface
    {
        return $this->counts('guidance');
    }

    private function counts(string $unit): ResponseInterface
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        if (strtoupper($this->request->getMethod()) !== 'GET') {
            return $this->response->setStatusCode(405)->setHeader('Allow', 'GET')
                ->setJSON(['message' => 'Method not allowed.']);
        }
        try {
            return $this->response->setJSON((new QueueDashboardService())->snapshot($unit));
        } catch (Throwable $failure) {
            $status = in_array($failure->getCode(), [401, 403], true) ? (int) $failure->getCode() : 503;
            return $this->response->setStatusCode($status)->setJSON([
                'message' => $status === 401 ? 'Please sign in to continue.'
                    : ($status === 403 ? 'Dashboard access forbidden.' : 'Queue counts are unavailable.'),
            ]);
        }
    }
}

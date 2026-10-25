<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PortalQueueService;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

final class PortalQueueController extends BaseController
{
    public function index(): ResponseInterface
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        if (strtoupper($this->request->getMethod()) !== 'GET') {
            return $this->response->setStatusCode(405)->setHeader('Allow', 'GET')->setJSON(['message' => 'Method not allowed.']);
        }
        try {
            return $this->response->setJSON((new PortalQueueService())->snapshot());
        } catch (Throwable $failure) {
            $status = in_array($failure->getCode(), [401, 403], true) ? (int) $failure->getCode() : 503;
            return $this->response->setStatusCode($status)->setJSON(['message' => $status === 401
                ? 'Please sign in to continue.' : ($status === 403 ? 'Personal queue access forbidden.' : 'Your ticket status is unavailable.')]);
        }
    }
}

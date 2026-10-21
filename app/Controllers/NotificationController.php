<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\NotificationService;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

final class NotificationController extends BaseController
{
    public function index(): ResponseInterface
    {
        return $this->respond(false);
    }

    public function read(int $id): ResponseInterface
    {
        return $this->respond(true, $id);
    }

    public function readAll(): ResponseInterface
    {
        return $this->respond(true);
    }

    private function respond(bool $write, ?int $id = null): ResponseInterface
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        $method = $write ? 'POST' : 'GET';
        if (strtoupper($this->request->getMethod()) !== $method) {
            return $this->response->setStatusCode(405)->setHeader('Allow', $method)->setJSON(['message' => 'Method not allowed.']);
        }
        try {
            $service = new NotificationService();
            if ($write) {
                $id === null ? $service->markAllRead() : $service->markRead($id);
            }
            $payload = $service->ownInbox();
            if ($write) {
                $payload['csrf'] = ['name' => csrf_token(), 'hash' => csrf_hash()];
            }
            return $this->response->setJSON($payload);
        } catch (Throwable $e) {
            $status = in_array($e->getCode(), [401, 403, 404], true) ? (int) $e->getCode() : 503;
            $message = match ($status) {
                401 => 'Please sign in to continue.',
                403 => 'Access forbidden.',
                404 => 'Notification not found.',
                default => 'Notifications are temporarily unavailable.',
            };
            return $this->response->setStatusCode($status)->setJSON(['message' => $message]);
        }
    }
}

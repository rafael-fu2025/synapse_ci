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

    public function inbox(): string
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        return view('notifications/index', ['title' => 'Notifications', 'breadcrumbs' => [['label' => 'Notifications']]]);
    }

    public function history(): ResponseInterface
    {
        return $this->respond(false, null, true);
    }

    public function read(int $id): ResponseInterface
    {
        return $this->respond(true, $id);
    }

    public function readAll(): ResponseInterface
    {
        return $this->respond(true);
    }

    private function respond(bool $write, ?int $id = null, bool $history = false): ResponseInterface
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
            if ($history) {
                $page = $this->request->getGet('page') ?? '1';
                $filter = $this->request->getGet('filter') ?? 'all';
                if (!is_string($page) || !preg_match('/\A[1-9][0-9]{0,6}\z/D', $page)
                    || (int) $page > 1000000 || !is_string($filter) || !in_array($filter, ['all', 'unread'], true)) {
                    throw new \InvalidArgumentException('Invalid notification page or filter.', 422);
                }
                $payload = $service->ownPage((int) $page, $filter);
            } else {
                $payload = $service->ownInbox();
            }
            if ($write) {
                $payload['csrf'] = ['name' => csrf_token(), 'hash' => csrf_hash()];
            }
            return $this->response->setJSON($payload);
        } catch (Throwable $e) {
            $status = in_array($e->getCode(), [401, 403, 404, 422], true) ? (int) $e->getCode() : 503;
            $message = match ($status) {
                401 => 'Please sign in to continue.',
                403 => 'Access forbidden.',
                404 => 'Notification not found.',
                422 => 'Invalid notification page or filter.',
                default => 'Notifications are temporarily unavailable.',
            };
            return $this->response->setStatusCode($status)->setJSON(['message' => $message]);
        }
    }
}

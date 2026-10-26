<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\NotificationService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/** Personal notification inbox; the same person-owned authority as the web bell. */
final class NotificationsApiController extends ApiV1Controller
{
    public function index(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            return $this->ok(['data' => (new NotificationService())->ownInbox()]);
        });
    }

    public function history(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $page = $this->request->getGet('page');
            $filter = $this->request->getGet('filter');
            if (($page !== null && (!is_string($page) || preg_match('/\A[1-9][0-9]{0,6}\z/D', $page) !== 1 || (int) $page > 1000000))
                || ($filter !== null && (!is_string($filter) || !in_array($filter, ['all', 'unread'], true)))) {
                throw new InvalidArgumentException('Invalid notification page or filter.', 422);
            }
            return $this->ok(['data' => (new NotificationService())->ownPage(
                $page === null ? 1 : (int) $page,
                is_string($filter) ? $filter : 'all'
            )]);
        });
    }

    public function read(int $id): ResponseInterface
    {
        return $this->run(function () use ($id): ResponseInterface {
            (new NotificationService())->markRead($id);
            return $this->noContent();
        });
    }

    public function readAll(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            (new NotificationService())->markAllRead();
            return $this->noContent();
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\AnnouncementService;
use CodeIgniter\HTTP\ResponseInterface;

/** Targeted campus announcements: list, read, and mark-as-read. */
final class AnnouncementsApiController extends ApiV1Controller
{
    public function index(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $query = $this->query();
            $result = (new AnnouncementService())->portalListing($query['page'], $query);
            return $this->ok([
                'data' => $result['announcements'],
                'meta' => $result['pagination'],
                'filters' => $result['filters'],
                'counts' => $result['counts'],
            ]);
        });
    }

    public function show(int $id): ResponseInterface
    {
        return $this->run(function () use ($id): ResponseInterface {
            return $this->ok(['data' => (new AnnouncementService())->show($id)]);
        });
    }

    public function markRead(int $id): ResponseInterface
    {
        return $this->run(function () use ($id): ResponseInterface {
            return $this->ok(['data' => (new AnnouncementService())->read($id)]);
        });
    }

    /** @return array<string, mixed> */
    private function query(): array
    {
        $query = ['page' => $this->page()];
        foreach (['unit' => ['all', 'clinic', 'guidance'], 'status' => ['all', 'unread', 'read']] as $key => $allowed) {
            $value = $this->request->getGet($key);
            if (is_string($value) && in_array(strtolower(trim($value)), $allowed, true)) {
                $query[$key] = strtolower(trim($value));
            }
        }
        $search = $this->request->getGet('q');
        if (is_string($search) && mb_strlen(trim($search)) > 0 && mb_strlen($search) <= 100) {
            $query['q'] = trim($search);
        }
        return $query;
    }

    private function page(): string
    {
        $page = $this->request->getGet('page');
        return is_string($page) && preg_match('/\A[1-9][0-9]{0,6}\z/D', $page) === 1 && (int) $page <= 1000000
            ? $page : '1';
    }
}

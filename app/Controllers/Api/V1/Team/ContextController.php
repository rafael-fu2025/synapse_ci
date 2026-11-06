<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Team;

use App\Services\TeamTaskService;
use CodeIgniter\HTTP\ResponseInterface;

final class ContextController extends TeamApiController
{
    public function show(string $unit): ResponseInterface
    {
        return $this->teamRun(function () use ($unit): ResponseInterface {
            $context = $this->teamAccess()->context($unit);
            return $this->teamOk(['data' => [
                'unit' => $unit,
                'label' => match ($unit) {
                    'clinic' => 'Clinic', 'guidance' => 'Guidance', 'facilities' => 'BMG Facilities',
                },
                'can_manage' => (bool) $context['canManage'],
                'actor' => TeamPresenter::member($context['actor']),
            ]]);
        });
    }

    public function members(string $unit): ResponseInterface
    {
        return $this->teamRun(function () use ($unit): ResponseInterface {
            $filters = $this->query(['q', 'page', 'limit']);
            $q = $filters['q'] ?? '';
            if (!is_string($q) || !mb_check_encoding($q, 'UTF-8') || mb_strlen($q, 'UTF-8') > 100) {
                throw new \RuntimeException('q must be at most 100 text characters.', 422);
            }
            $page = $this->boundedInteger($filters['page'] ?? '1', 'page', 1, 100000);
            $limit = $this->boundedInteger($filters['limit'] ?? '50', 'limit', 1, 100);
            $context = (new TeamTaskService($this->teamAccess()))->context($unit);
            $members = $context['members'];
            if (trim($q) !== '') {
                $needle = mb_strtolower(trim($q), 'UTF-8');
                $members = array_values(array_filter($members, static fn (array $member): bool =>
                    str_contains(mb_strtolower((string) $member['name'], 'UTF-8'), $needle)));
            }
            $total = count($members);
            $pages = max(1, (int) ceil($total / $limit));
            $page = min($page, $pages);
            $members = array_slice($members, ($page - 1) * $limit, $limit);
            return $this->teamOk([
                'data' => array_map([TeamPresenter::class, 'member'], $members),
                'meta' => ['unit' => $unit, 'q' => trim($q), 'total' => $total,
                    'page' => $page, 'pages' => $pages, 'limit' => $limit],
            ]);
        });
    }

    private function boundedInteger(mixed $value, string $field, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]*\z/', (string) $value)) {
            throw new \RuntimeException('Invalid ' . $field . '.', 422);
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new \RuntimeException('Invalid ' . $field . '.', 422);
        }
        return $number;
    }
}

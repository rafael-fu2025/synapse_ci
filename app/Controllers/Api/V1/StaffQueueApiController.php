<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Api\StaffQueuePresenter;
use App\Services\Scheduling\QueueService;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/** Read-only staff queue monitoring. No staff action exists on the mobile API by design. */
final class StaffQueueApiController extends ApiV1Controller
{
    public function index(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $identity = $this->identity();
            $permissions = is_array($identity['permissions'] ?? null) ? $identity['permissions'] : [];
            $unit = $this->request->getGet('unit');
            $requested = is_string($unit) ? strtolower(trim($unit)) : 'all';
            if ($requested !== 'all' && !in_array($requested, ['clinic', 'guidance'], true)) {
                return $this->fail(404, 'not_found', 'Queue summary not found.');
            }
            $units = [];
            foreach (['clinic', 'guidance'] as $candidate) {
                if ($requested !== 'all' && $candidate !== $requested) {
                    continue;
                }
                // Same board gate as the web queue page: queue read (or manage) grant.
                if (($identity['role'] ?? '') === 'superadmin'
                    || in_array($candidate . '.queue.read', $permissions, true)
                    || in_array($candidate . '.queue.manage', $permissions, true)) {
                    $units[] = $candidate;
                }
            }
            if ($units === []) {
                throw new RuntimeException('Queue monitoring access forbidden.', 403);
            }

            $now = SchedulingClock::now();
            $date = $now->format('Y-m-d');
            $service = new QueueService();
            $presenter = new StaffQueuePresenter();
            $summaries = [];
            foreach ($units as $staffUnit) {
                $board = $service->getBoardSummary($staffUnit, $date);
                $summaries[] = $presenter->present($staffUnit, $board, $now);
            }
            return $this->ok([
                'data' => $summaries,
                'meta' => ['date' => $date, 'generated_at' => $now->format(DATE_ATOM)],
            ]);
        });
    }
}

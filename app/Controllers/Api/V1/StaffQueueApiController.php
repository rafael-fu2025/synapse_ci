<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Scheduling\QueueService;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/** Read-only staff queue monitoring. No staff action exists on the mobile API by design. */
final class StaffQueueApiController extends ApiV1Controller
{
    private const ACTIVE_BUCKETS = ['waiting', 'called', 'in_consultation'];

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

            $service = new QueueService();
            $summaries = [];
            foreach ($units as $staffUnit) {
                $board = $service->getBoardSummary($staffUnit);
                $tickets = [];
                foreach (self::ACTIVE_BUCKETS as $bucket) {
                    $tickets[$bucket] = array_map(
                        $this->presentTicket(...),
                        is_array($board[$bucket] ?? null) ? $board[$bucket] : []
                    );
                }
                $summaries[] = [
                    'unit' => $staffUnit,
                    'counts' => $board['counts'],
                    'tickets' => $tickets,
                ];
            }
            return $this->ok(['data' => $summaries]);
        });
    }

    private function presentTicket(array $item): array
    {
        return [
            'id' => (int) $item['id'],
            'ticket_code' => (string) $item['ticket_code'],
            'status' => (string) $item['status'],
            'priority' => (string) $item['priority'],
            'patient_name' => trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? '')),
            'patient_kind' => $item['kind'] ?? null,
            'called_at' => $item['called_at'] ?? null,
            'completed_at' => $item['completed_at'] ?? null,
            'created_at' => $item['created_at'] ?? null,
        ];
    }
}

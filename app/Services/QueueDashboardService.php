<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Scheduling\SchedulingClock;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/** Read-only dashboard projection; never returns queue entries or patient fields. */
final class QueueDashboardService
{
    public function __construct(
        private ?BaseConnection $db = null,
        private ?Closure $identity = null,
        private ?Closure $tenant = null,
    ) {
    }

    public function snapshot(string $unit): array
    {
        if (!in_array($unit, ['clinic', 'guidance'], true)) {
            throw new RuntimeException('Dashboard not found.', 404);
        }
        $actor = $this->identity !== null ? ($this->identity)() : (new AuthenticatedUser())->current();
        if ($actor === null) {
            throw new RuntimeException('Please sign in to continue.', 401);
        }
        $tenantId = $this->tenant !== null ? ($this->tenant)() : CurrentTenant::id();
        if ($tenantId <= 0 || ($actor['tenant_id'] ?? null) !== $tenantId
            || ($actor['logged_in'] ?? false) !== true
            || !PortalContext::isRolePermitted($actor['role'] ?? '', $unit)) {
            throw new RuntimeException('Dashboard access forbidden.', 403);
        }
        // Match the dashboard's combined-data access gate, not just the queue board gate.
        $permissions = $unit === 'clinic'
            ? ['clinic.queue.read', 'clinic.encounters.read', 'clinic.appointments.read', 'clinic.inventory.read']
            : ['guidance.cases.read', 'guidance.queue.read', 'referrals.read'];
        foreach ($permissions as $permission) {
            if ($actor['role'] !== 'superadmin' && !in_array($permission, $actor['permissions'] ?? [], true)) {
                throw new RuntimeException('Dashboard access forbidden.', 403);
            }
        }

        $db = $this->db ?? Database::connect();
        $today = SchedulingClock::today();
        $count = static fn (string $status): int => $db->table('queue_entries')
            ->where('tenant_id', $tenantId)->where('unit', $unit)->where('queue_date', $today)
            ->where('status', $status)->countAllResults();
        $waiting = $count('waiting');
        $called = $count('called');
        $counts = $unit === 'clinic'
            ? ['queueWaiting' => $waiting, 'queueCalled' => $called, 'queueCompleted' => $count('done'), 'queueToday' => $waiting + $called]
            : ['queueToday' => $waiting + $called];
        return ['unit' => $unit, 'date' => $today, 'counts' => $counts];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Scheduling\SchedulingClock;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/** The caller's own active tickets; no request-supplied person or tenant selector. */
final class PortalQueueService
{
    public function __construct(private ?BaseConnection $db = null, private ?Closure $identity = null, private ?Closure $tenant = null)
    {
    }

    public function snapshot(): array
    {
        $actor = $this->identity !== null ? ($this->identity)() : (new AuthenticatedUser())->current();
        if ($actor === null) throw new RuntimeException('Please sign in to continue.', 401);
        $tenant = $this->tenant !== null ? ($this->tenant)() : CurrentTenant::id();
        $person = (int) ($actor['person_id'] ?? 0);
        if ($tenant < 1 || ($actor['tenant_id'] ?? null) !== $tenant || $person < 1
            || ($actor['logged_in'] ?? false) !== true
            || !PortalContext::isRolePermitted($actor['role'] ?? '', PortalContext::PORTAL_MAIN)
            || !in_array('portal.appointments.read', $actor['permissions'] ?? [], true)
            || !in_array('portal.records.read', $actor['permissions'] ?? [], true)) {
            throw new RuntimeException('Personal queue access forbidden.', 403);
        }
        $date = SchedulingClock::today();
        $rows = ($this->db ?? Database::connect())->table('queue_entries')
            ->select('unit,ticket_code,status,priority')->where('tenant_id', $tenant)->where('person_id', $person)
            ->where('queue_date', $date)->whereIn('unit', ['clinic', 'guidance'])
            ->whereIn('status', ['waiting', 'called', 'in_consultation', 'skipped'])
            ->orderBy('id', 'DESC')->limit(2)->get()->getResultArray();
        return ['date' => $date, 'tickets' => $rows];
    }
}

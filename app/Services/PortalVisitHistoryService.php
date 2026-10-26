<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;

/** Patient-safe metadata for the current caller's completed visits, including walk-ins. */
final class PortalVisitHistoryService
{
    private const PER_PAGE = 25;

    public function __construct(private ?BaseConnection $db = null, private ?Closure $identity = null, private ?Closure $tenant = null)
    {
    }

    public function snapshot(array $filters = []): array
    {
        $actor = $this->identity !== null ? ($this->identity)() : (new AuthenticatedUser())->current();
        if ($actor === null) throw new RuntimeException('Please sign in to continue.', 401);
        $tenant = $this->tenant !== null ? ($this->tenant)() : CurrentTenant::id();
        $person = is_array($actor) ? $this->positiveId($actor['person_id'] ?? null) : null;
        $user = is_array($actor) ? $this->positiveId($actor['id'] ?? null) : null;
        if (!is_array($actor) || !is_int($tenant) || $tenant < 1 || ($actor['tenant_id'] ?? null) !== $tenant
            || $person === null || $user === null || ($actor['logged_in'] ?? false) !== true
            || !is_string($actor['role'] ?? null)
            || !PortalContext::isRolePermitted($actor['role'], PortalContext::PORTAL_MAIN)
            || !is_array($actor['permissions'] ?? null)
            || !in_array('portal.records.read', $actor['permissions'], true)) {
            throw new RuntimeException('Personal visit history access forbidden.', 403);
        }

        [$service, $page] = $this->filters($filters);
        $db = $this->db ?? Database::connect();
        // Also fail closed for stale/deleted people or a mismatched user/person binding.
        $current = $db->table('users u')->select('u.id')
            ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id')
            ->where('u.id', $user)->where('u.tenant_id', $tenant)->where('u.person_id', $person)
            ->where('u.status', 'active')->where('u.deleted_at', null)->where('p.deleted_at', null)
            ->limit(1)->get()->getRowArray();
        if ($current === null) throw new RuntimeException('Personal visit history access forbidden.', 403);

        $table = static fn (string $name): string => $db->escapeIdentifiers($db->prefixTable($name));
        $branches = [];
        $bindings = [];
        if ($service !== 'guidance') {
            $branches[] = [
                'from' => 'FROM ' . $table('clinic_encounters') . ' e WHERE e.tenant_id = ? AND e.person_id = ?'
                    . " AND e.deleted_at IS NULL AND e.status IN ('completed', 'referred') AND e.completed_at IS NOT NULL",
                'select' => "SELECT 'clinic' AS unit, e.id AS source_id, e.started_at, e.completed_at,"
                    . ' e.attending_user_id AS staff_id, e.appointment_id',
            ];
        }
        if ($service !== 'clinic') {
            $branches[] = [
                'from' => 'FROM ' . $table('counselling_sessions') . ' s JOIN ' . $table('counselling_cases')
                    . ' c ON c.id = s.case_id AND c.tenant_id = s.tenant_id'
                    . ' WHERE s.tenant_id = ? AND c.person_id = ? AND c.deleted_at IS NULL'
                    . " AND s.deleted_at IS NULL AND s.status = 'completed' AND s.ended_at IS NOT NULL",
                'select' => "SELECT 'guidance' AS unit, s.id AS source_id, s.started_at, s.ended_at AS completed_at,"
                    . ' s.counsellor_user_id AS staff_id, s.appointment_id',
            ];
        }
        $total = 0;
        $queries = [];
        foreach ($branches as $branch) {
            $total += (int) $db->query('SELECT COUNT(*) AS total ' . $branch['from'], [$tenant, $person])->getRowArray()['total'];
            $queries[] = $branch['select'] . ' ' . $branch['from'];
            array_push($bindings, $tenant, $person);
        }
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;
        $visits = [];
        if ($total > 0) {
            // Bound the union before staff resolution. Source IDs are ordering keys only;
            // UNION ALL deliberately preserves distinct visits sharing an appointment.
            $union = implode(' UNION ALL ', $queries);
            $limit = self::PER_PAGE;
            $rows = $db->query("SELECT v.unit, v.started_at, v.completed_at, v.appointment_id,
                    p.first_name, p.last_name, u.username
                FROM (SELECT * FROM ({$union}) history
                    ORDER BY started_at DESC, unit ASC, source_id DESC LIMIT {$limit} OFFSET {$offset}) v
                LEFT JOIN " . $table('users') . " u ON u.id = v.staff_id AND u.tenant_id = ? AND u.deleted_at IS NULL
                    AND (u.person_id IS NULL OR EXISTS (SELECT 1 FROM " . $table('people') . " staff_person
                        WHERE staff_person.id = u.person_id AND staff_person.tenant_id = u.tenant_id AND staff_person.deleted_at IS NULL))
                LEFT JOIN " . $table('people') . " p ON p.id = u.person_id AND p.tenant_id = u.tenant_id AND p.deleted_at IS NULL
                ORDER BY v.started_at DESC, v.unit ASC, v.source_id DESC", [...$bindings, $tenant])->getResultArray();
            foreach ($rows as $row) {
                $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                if ($name === '') $name = $row['username'] ?? '';
                $name = trim((string) preg_replace('/\s+/u', ' ', $name));
                // Clinical timestamps are Manila-local DATETIME values, not UTC instants.
                $visits[] = [
                    'unit' => $row['unit'],
                    'started_at' => $row['started_at'],
                    'completed_at' => $row['completed_at'],
                    'staff_name' => $name !== '' ? $name : 'Not recorded',
                    'attendance' => $row['appointment_id'] !== null ? 'appointment' : 'walk_in',
                    'summary' => $row['unit'] === 'clinic' ? 'Completed clinic visit' : 'Completed guidance session',
                    'status' => 'completed',
                ];
            }
        }
        return [
            'visits' => $visits,
            'filters' => ['service' => $service],
            'pagination' => ['page' => $page, 'pages' => $pages, 'per_page' => self::PER_PAGE, 'total' => $total,
                'from' => $total > 0 ? $offset + 1 : 0, 'to' => $total > 0 ? $offset + count($visits) : 0],
        ];
    }

    private function filters(array $filters): array
    {
        $service = $filters['service'] ?? 'all';
        $page = $filters['page'] ?? 1;
        if (array_diff(array_keys($filters), ['service', 'page']) !== []
            || (array_key_exists('service', $filters) && $filters['service'] === null)
            || !in_array($service, ['all', 'clinic', 'guidance'], true)
            || (!is_int($page) && !is_string($page))
            || !preg_match('/^[1-9][0-9]{0,6}$/D', (string) $page) || (int) $page > 1000000
            || (array_key_exists('page', $filters) && $filters['page'] === null)) {
            throw new InvalidArgumentException('Invalid visit history filters.', 422);
        }
        return [$service, (int) $page];
    }

    private function positiveId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}

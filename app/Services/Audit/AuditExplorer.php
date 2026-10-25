<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/** Read-only investigation queries, independent of the ledger writer and verifier. */
final class AuditExplorer
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    public function page(AuditExplorerQuery $query): array
    {
        if ($query->errors !== []) {
            throw new InvalidArgumentException('Cannot query audit events with invalid filters.');
        }
        $tenantId = CurrentTenant::id();
        $builder = $this->filtered($query, $tenantId)
            ->select('ae.*, u.username AS actor_name')
            ->join('users u', 'u.id = ae.actor_user_id AND u.tenant_id = ae.tenant_id', 'left');
        $newer = $query->direction === 'newer';
        if ($query->cursor !== null) {
            $builder->where($newer ? 'ae.id >' : 'ae.id <', $query->cursor);
        }
        $rows = $builder->orderBy('ae.id', $newer ? 'ASC' : 'DESC')->get($query->limit + 1)->getResultArray();
        $more = count($rows) > $query->limit;
        if ($more) {
            array_pop($rows);
        }
        if ($newer) {
            $rows = array_reverse($rows);
        }
        $olderCursor = null;
        $newerCursor = null;
        if ($rows !== []) {
            $first = (string) $rows[0]['id'];
            $last = (string) $rows[count($rows) - 1]['id'];
            // Lookahead covers one direction; a bounded existence query covers the other.
            $opposite = $this->filtered($query, $tenantId)->select('ae.id')
                ->where($newer ? 'ae.id <' : 'ae.id >', $newer ? $last : $first)
                ->get(1)->getRowArray() !== null;
            $olderCursor = ($newer ? $opposite : $more) ? $last : null;
            $newerCursor = ($newer ? $more : $opposite) ? $first : null;
        }
        return ['events' => $rows, 'older_cursor' => $olderCursor, 'newer_cursor' => $newerCursor];
    }

    private function filtered(AuditExplorerQuery $query, int $tenantId): BaseBuilder
    {
        $builder = $this->db->table('audit_events ae')->where('ae.tenant_id', $tenantId);
        foreach (['event_type', 'action', 'entity_type', 'entity_id', 'actor_user_id'] as $key) {
            if (($query->filters[$key] ?? '') !== '') {
                $builder->where('ae.' . $key, $query->filters[$key]);
            }
        }
        if (($query->filters['from'] ?? '') !== '') {
            $builder->where('ae.occurred_at >=', $query->filters['from'] . ' 00:00:00');
        }
        if (($query->filters['to'] ?? '') !== '') {
            // The existing ledger stores DATETIME values with second precision, in server time.
            $builder->where('ae.occurred_at <=', $query->filters['to'] . ' 23:59:59');
        }
        return $builder;
    }
}

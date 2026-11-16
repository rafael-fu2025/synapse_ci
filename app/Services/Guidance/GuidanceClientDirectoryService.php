<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Libraries\LikePattern;
use App\Libraries\TablePage;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

final class GuidanceClientDirectoryService
{
    private BaseConnection $db;
    private GuidanceAccessPolicy $accessPolicy;

    public function __construct(?BaseConnection $db = null, ?GuidanceAccessPolicy $accessPolicy = null)
    {
        $this->db = $db ?? Database::connect();
        $this->accessPolicy = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
    }

    public function paginateClients(TablePage $page, ?array $actor = null): array
    {
        $authority = $this->accessPolicy->resolve($actor, 'guidance.cases.read');
        if (!in_array($page->kind, ['all', 'student', 'employee'], true) || $page->status !== 'all') {
            throw new InvalidArgumentException('Invalid Guidance client filter.', 422);
        }

        $builder = $this->db->table('people p')
            ->where('p.tenant_id', (int) $authority['tenant_id'])
            ->where('p.deleted_at', null)
            ->whereIn('p.kind', ['student', 'employee']);
        $total = (clone $builder)->countAllResults(false);
        if ($page->kind !== 'all') {
            $builder->where('p.kind', $page->kind);
        }
        if ($page->q !== '') {
            $builder->groupStart();
            LikePattern::apply($builder, 'p.mis_id', $page->q);
            $tokens = preg_split('/\s+/u', $page->q, -1, PREG_SPLIT_NO_EMPTY);
            if ($tokens !== []) {
                $builder->orGroupStart();
                foreach ($tokens as $token) {
                    LikePattern::applyGroup($builder, ['p.first_name', 'p.last_name', 'p.middle_name'], $token);
                }
                $builder->groupEnd();
            }
            $builder->groupEnd();
        }

        $filtered = (clone $builder)->countAllResults(false);
        $pagination = $page->metadata($total, $filtered);
        $clients = $builder
            ->select('p.id, p.kind, p.mis_id, p.first_name, p.middle_name, p.last_name, sp.program, sp.year_level, ep.department')
            ->join('student_profiles sp', "sp.person_id = p.id AND sp.tenant_id = p.tenant_id AND p.kind = 'student'", 'left')
            ->join('employee_profiles ep', "ep.person_id = p.id AND ep.tenant_id = p.tenant_id AND p.kind = 'employee'", 'left')
            ->orderBy('p.last_name')->orderBy('p.first_name')->orderBy('p.id')
            ->get($page->perPage, ($pagination['page'] - 1) * $page->perPage)->getResultArray();

        return [
            'clients' => $clients,
            'pagination' => $pagination,
            'filters' => ['q' => $page->q, 'kind' => $page->kind, 'per_page' => $page->perPage],
        ];
    }
}

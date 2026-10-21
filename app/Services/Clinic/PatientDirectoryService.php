<?php

declare(strict_types=1);

namespace App\Services\Clinic;

use App\Libraries\TablePage;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

final class PatientDirectoryService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** Authorizes the directory and returns the fresh Start Visit capability. */
    public function assertAccess(int $actorId): bool
    {
        $actor = $this->db->table('users')->select('role, status')
            ->where('id', $actorId)->where('tenant_id', CurrentTenant::id())
            ->where('deleted_at', null)->get()->getRowArray();
        if ($actor === null || $actor['status'] !== 'active') {
            throw new RuntimeException('Active clinic account required.', 403);
        }
        if ($actor['role'] === 'superadmin') {
            return true;
        }
        if (!in_array($actor['role'], ['clinic_admin', 'clinic_staff'], true)) {
            throw new RuntimeException('Clinic directory role not permitted.', 403);
        }
        $grants = $this->db->table('role_permissions rp')->select('p.code')
            ->join('permissions p', 'p.id=rp.permission_id')->where('rp.role', $actor['role'])
            ->whereIn('p.code', ['clinic.patients.read', 'clinic.encounters.create'])->get()->getResultArray();
        $codes = array_column($grants, 'code');
        if (!in_array('clinic.patients.read', $codes, true)) {
            throw new RuntimeException('Missing permission: clinic.patients.read', 403);
        }
        return in_array('clinic.encounters.create', $codes, true);
    }

    /** HTTP readers must call assertAccess before querying the directory. */
    public function paginatePatients(TablePage $page): array
    {
        $builder = $this->db->table('people p')->where('p.tenant_id', CurrentTenant::id())
            ->where('p.deleted_at', null);
        $total = (clone $builder)->countAllResults();
        if ($page->kind !== 'all') {
            $builder->where('p.kind', $page->kind);
        }
        if ($page->q !== '') {
            // Escape LIKE metacharacters without SQL-escaping the bound value a second time.
            $literal = static fn (string $value): string => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
            // IDs retain their original whitespace/punctuation; only names are tokenized.
            $builder->groupStart()->like('p.mis_id', $literal($page->q));
            $tokens = preg_split('/\s+/u', $page->q, -1, PREG_SPLIT_NO_EMPTY);
            if ($tokens !== []) {
                $builder->orGroupStart();
                foreach ($tokens as $token) {
                    $token = $literal($token);
                    $builder->groupStart()->like('p.first_name', $token)->orLike('p.last_name', $token)
                        ->orLike('p.middle_name', $token)->groupEnd();
                }
                $builder->groupEnd();
            }
            $builder->groupEnd();
        }
        $filtered = (clone $builder)->countAllResults();
        $pagination = $page->metadata($total, $filtered);
        $patients = $builder->select('p.id, p.kind, p.mis_id, p.first_name, p.last_name, p.middle_name, p.email, p.gender, sp.program, sp.year_level, ep.department AS emp_dept, hp.blood_type')
            ->join('student_profiles sp', 'sp.person_id=p.id AND sp.tenant_id=p.tenant_id', 'left')
            ->join('employee_profiles ep', 'ep.person_id=p.id AND ep.tenant_id=p.tenant_id', 'left')
            ->join('health_profiles hp', 'hp.person_id=p.id AND hp.tenant_id=p.tenant_id', 'left')
            ->orderBy('p.last_name')->orderBy('p.first_name')->orderBy('p.id')
            ->get($page->perPage, ($pagination['page'] - 1) * $page->perPage)->getResultArray();
        foreach ($patients as &$patient) {
            if (empty($patient['email']) && !empty($patient['first_name']) && !empty($patient['last_name'])) {
                $patient['email'] = \App\Services\UniversityEmail::derive(
                    (string) $patient['first_name'],
                    (string) $patient['last_name']
                );
            }
            $patient['program_or_dept'] = !empty($patient['program'])
                ? $patient['program'] . '-' . $patient['year_level'] : ($patient['emp_dept'] ?? '—');
        }
        unset($patient);
        return [
            'patients' => $patients, 'pagination' => $pagination,
            'filters' => ['q' => $page->q, 'kind' => $page->kind, 'per_page' => $page->perPage],
        ];
    }
}

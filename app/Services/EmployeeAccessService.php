<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Audit\AuditService;
use Config\Database;
use RuntimeException;
use Throwable;

/** Employee access only: never provisions identities, passwords or provider appointments. */
final class EmployeeAccessService
{
    public const ROLES = [
        'employee' => 'Employee — personal portal only',
        'clinic_staff' => 'Clinic staff — clinical records and operations',
        'clinic_admin' => 'Clinic administrator — Clinic publishing and access management',
        'counsellor' => 'Counsellor — assigned cases and Guidance operations',
        'guidance_supervisor' => 'Guidance supervisor — all cases, publishing and surveys',
        'guidance_admin' => 'Guidance administrator — all cases, publishing, surveys and access management',
        'facilities_op' => 'Facilities operator — BMG operations',
        'bmg_admin' => 'BMG administrator — operations, configuration and reporting',
        'audit_reader' => 'Audit reader — audit ledger and reports',
        'report_viewer' => 'Report viewer — operational reports and exports',
        'superadmin' => 'Superadmin — platform-wide administrative authority',
    ];

    public function actor(): array
    {
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) throw new RuntimeException('Please sign in to continue.', 401);
        if (!in_array($actor['role'], ['superadmin', 'clinic_admin', 'guidance_admin'], true)
            || ($actor['role'] !== 'superadmin' && !in_array('rbac.manage', $actor['permissions'], true))) {
            throw new RuntimeException('Employee access management is not permitted.', 403);
        }
        return $actor;
    }

    public static function options(string $role): array
    {
        $keys = match ($role) {
            'superadmin' => array_keys(self::ROLES),
            'clinic_admin' => ['employee', 'clinic_staff'],
            'guidance_admin' => ['employee', 'counsellor'],
            default => [],
        };
        return array_intersect_key(self::ROLES, array_flip($keys));
    }

    private function employees(array $actor)
    {
        $query = Database::connect()->table('users u')
            ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id')
            ->join('employee_profiles ep', 'ep.person_id = p.id AND ep.tenant_id = p.tenant_id')
            ->where('u.tenant_id', $actor['tenant_id'])->where('u.deleted_at', null)
            ->where('p.deleted_at', null)->where('p.kind', 'employee');
        if ($actor['role'] !== 'superadmin') {
            $department = $actor['role'] === 'clinic_admin' ? 'health services' : 'guidance';
            $query->where('LOWER(TRIM(ep.department))', $department)
                ->whereIn('u.role', array_keys(self::options($actor['role'])));
        }
        return $query;
    }

    public function listing(array $input): array
    {
        $actor = $this->actor();
        $search = $input['q'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 100) throw new RuntimeException('Search must be at most 100 characters.', 422);
        $search = trim($search);
        $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false) throw new RuntimeException('Invalid page.', 422);
        $perPage = filter_var($input['per_page'] ?? 25, FILTER_VALIDATE_INT);
        if ($perPage === false || !in_array($perPage, [25, 50, 100], true)) {
            throw new RuntimeException('Per page must be 25, 50 or 100.', 422);
        }
        $query = $this->employees($actor);
        if ($search !== '') {
            $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
            if ($terms === false) throw new RuntimeException('Search must contain valid text.', 422);
            $escape = Database::connect()->likeEscapeChar;
            foreach ($terms as $term) {
                // Bindings quote SQL values; separately escape LIKE pattern metacharacters.
                $term = strtr($term, [$escape => $escape . $escape, '%' => $escape . '%', '_' => $escape . '_']);
                $query->groupStart()->like('p.first_name', $term)->orLike('p.last_name', $term)
                    ->orLike('p.mis_id', $term)->orLike('u.username', $term)->groupEnd();
            }
        }
        $total = $query->countAllResults(false);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $employees = $query->select('u.id, u.username, u.role, u.status, p.first_name, p.last_name, p.mis_id, ep.department, ep.employment_status')
            ->orderBy('p.last_name')->orderBy('p.first_name')->orderBy('u.id')->get($perPage, ($page - 1) * $perPage)->getResultArray();
        $pagination = [
            'page' => $page, 'per_page' => $perPage, 'total' => $total, 'filtered' => $total, 'pages' => $pages,
            'from' => $total === 0 ? 0 : ($page - 1) * $perPage + 1,
            'to' => min($page * $perPage, $total),
        ];
        return compact('actor', 'employees', 'search', 'page', 'total', 'perPage', 'pagination') + ['pages' => $pages];
    }

    public function detail(int $id): array
    {
        $actor = $this->actor();
        $employee = $this->target($actor, $id);
        $options = self::options($actor['role']);
        $permissions = [];
        $db = Database::connect();
        foreach (array_unique(array_merge(array_keys($options), [$employee['role']])) as $role) {
            $permissions[$role] = $db->table('role_permissions rp')->select('p.code, p.name')
                ->join('permissions p', 'p.id = rp.permission_id')->where('rp.role', $role)
                ->orderBy('p.code')->get()->getResultArray();
        }
        return compact('actor', 'employee', 'options', 'permissions');
    }

    private function target(array $actor, int $id): array
    {
        $row = $this->employees($actor)->select('u.id, u.username, u.role, u.status, u.auth_version, p.first_name, p.last_name, p.mis_id, ep.department, ep.employment_status')
            ->where('u.id', $id)->get()->getRowArray();
        if ($row === null) throw new RuntimeException('Employee account not found or outside your assignment scope.', 404);
        return $row;
    }

    public function assign(int $id, array $input): void
    {
        $actor = $this->actor();
        $role = $input['role'] ?? null;
        $reason = $input['reason'] ?? null;
        $version = filter_var($input['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_string($role) || !isset(self::options($actor['role'])[$role])) throw new RuntimeException('This role cannot be assigned by your account.', 403);
        if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 500) throw new RuntimeException('Provide a reason of at most 500 characters.', 422);
        if ($version === false) throw new RuntimeException('Reload the employee account before changing access.', 422);
        if ($id === $actor['id']) throw new RuntimeException('You cannot change your own access.', 403);

        $db = Database::connect();
        $audit = new AuditService(null, $db);
        $db->resetTransStatus();
        if (!$db->transBegin()) throw new RuntimeException('Unable to start access change.', 503);
        try {
            // The same tenant mutex protects last-admin checks and the audit append.
            $audit->lockTenant($actor['tenant_id']);
            $fresh = (new AuthenticatedUser())->current();
            if ($fresh === null || $fresh['id'] !== $actor['id'] || $fresh['role'] !== $actor['role']
                || ($fresh['role'] !== 'superadmin' && !in_array('rbac.manage', $fresh['permissions'], true))) {
                throw new RuntimeException('Your access changed. Sign in again.', 403);
            }
            $employee = $this->target($fresh, $id);
            if ((int) $employee['auth_version'] !== $version) throw new RuntimeException('This account changed. Reload before trying again.', 409);
            if ($employee['role'] === $role) throw new RuntimeException('Choose a different role to change access.', 422);
            if ($role !== 'employee' && ($employee['status'] !== 'active' || strtolower(trim((string) $employee['employment_status'])) !== 'active')) {
                throw new RuntimeException('Only active employee accounts can receive workstation or administrative roles.', 422);
            }
            if ($employee['role'] === 'superadmin' && $employee['status'] === 'active') {
                $others = $db->table('users')->where('tenant_id', $actor['tenant_id'])->where('role', 'superadmin')
                    ->where('status', 'active')->where('deleted_at', null)->where('id !=', $id)->countAllResults();
                if ($others < 1) throw new RuntimeException('The last active superadmin cannot be removed.', 409);
            }
            $db->table('users')->where('id', $id)->where('tenant_id', $actor['tenant_id'])->where('auth_version', $version)
                ->set('role', $role)->set('auth_version', 'auth_version + 1', false)->set('updated_at', date('Y-m-d H:i:s'))->update();
            if ($db->affectedRows() !== 1) throw new RuntimeException('This account changed. Reload before trying again.', 409);
            $audit->log('access.role_changed', 'user', (string) $id, 'assign_role', [
                'previous_role' => $employee['role'], 'new_role' => $role, 'reason' => trim($reason),
                'previous_auth_version' => $version, 'auth_version' => $version + 1,
            ], $actor['id'], $actor['role']);
            if (!$db->transStatus() || !$db->transCommit()) throw new RuntimeException('Unable to save access change.', 503);
        } catch (Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();
            throw $e;
        }
    }
}

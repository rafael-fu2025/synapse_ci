<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\LikePattern;
use App\Libraries\TablePage;
use App\Services\Api\TokenService;
use App\Services\Audit\AuditService;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Production governance for time-bounded student intern placements across Clinic, Guidance, and BMG.
 * Preserves student identity (`people.kind = 'student'`) while strictly scoping staff workstation capabilities.
 */
final class StudentInternService
{
    public const UNITS = [
        'clinic'   => 'University Clinic',
        'guidance' => 'Guidance & Counseling',
        'bmg'      => 'Facilities (BMG)',
    ];

    public const UNIT_ROLES = [
        'clinic'   => 'clinic_intern',
        'guidance' => 'guidance_intern',
        'bmg'      => 'bmg_intern',
    ];

    public const INTERN_ROLES = [
        'clinic_intern',
        'guidance_intern',
        'bmg_intern',
    ];

    public const ROLE_LABELS = [
        'clinic_intern'   => 'Clinic Intern — queue, vital signs & encounter drafts (no seal/dispense)',
        'guidance_intern' => 'Guidance Intern — intake assist, queue & co-signed form drafts',
        'bmg_intern'      => 'Facilities (BMG) Intern — supervised batch logging & monitoring',
    ];

    public const ROLE_TO_UNIT = [
        'clinic_intern'   => 'clinic',
        'guidance_intern' => 'guidance',
        'bmg_intern'      => 'bmg',
    ];

    public const SUPERVISOR_ROLES = [
        'clinic'   => ['clinic_admin', 'clinic_physician', 'clinic_staff'],
        'guidance' => ['guidance_admin', 'guidance_supervisor', 'counsellor'],
        'bmg'      => ['bmg_admin', 'facilities_op'],
    ];

    public const STATUSES = ['scheduled', 'active', 'completed', 'revoked', 'expired'];
    public const OPEN_STATUSES = ['scheduled', 'active'];
    public const MAX_PLACEMENT_DAYS = 366;

    private const PLACEMENT_COLUMNS = 'sip.id, sip.tenant_id, sip.user_id, sip.person_id, sip.unit, sip.intern_role, sip.supervisor_user_id, sip.status, sip.starts_at, sip.ends_at, sip.academic_term, sip.assignment_reason, sip.activated_at, sip.ended_at, sip.revoked_by_user_id, sip.revoke_reason, sip.created_by_user_id, sip.updated_by_user_id, sip.version, sip.created_at, sip.updated_at';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return list<string> */
    public static function allowedUnitsForRole(string $role): array
    {
        return match ($role) {
            'superadmin'     => ['clinic', 'guidance', 'bmg'],
            'clinic_admin'   => ['clinic'],
            'guidance_admin' => ['guidance'],
            'bmg_admin'      => ['bmg'],
            default          => [],
        };
    }

    /** Authenticate and authorize an intern-management actor (optionally requiring a specific unit). */
    public function actor(?string $requiredUnit = null): array
    {
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) {
            throw new RuntimeException('Please sign in to continue.', 401);
        }

        $allowedUnits = self::allowedUnitsForRole((string) ($actor['role'] ?? ''));
        if ($allowedUnits === []
            || ($actor['role'] !== 'superadmin' && !in_array('interns.manage', $actor['permissions'] ?? [], true))) {
            throw new RuntimeException('Student intern management is not permitted.', 403);
        }

        if ($requiredUnit !== null && !in_array($requiredUnit, $allowedUnits, true)) {
            throw new RuntimeException('You are not authorized to manage interns for this unit.', 403);
        }

        return $actor;
    }

    /** List placements in the actor's allowed unit scope, auto-reconciling due activations/expiries first. */
    public function listing(array $input): array
    {
        $actor = $this->actor();
        $tenantId = (int) $actor['tenant_id'];
        $allowedUnits = self::allowedUnitsForRole((string) $actor['role']);

        $this->reconcileTenant($tenantId);

        $search = $input['q'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 100) {
            throw new RuntimeException('Search must be at most 100 characters.', 422);
        }
        $search = trim($search);

        $unitFilter = $input['unit'] ?? 'all';
        if (!is_string($unitFilter) || ($unitFilter !== 'all' && !array_key_exists($unitFilter, self::UNITS))) {
            throw new RuntimeException('Invalid unit filter.', 422);
        }
        if ($unitFilter !== 'all' && !in_array($unitFilter, $allowedUnits, true)) {
            throw new RuntimeException('Unit filter is outside your management scope.', 403);
        }

        $statusFilter = $input['status'] ?? 'all';
        if (!is_string($statusFilter) || ($statusFilter !== 'all' && !in_array($statusFilter, self::STATUSES, true))) {
            throw new RuntimeException('Invalid status filter.', 422);
        }

        $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false) {
            throw new RuntimeException('Invalid page.', 422);
        }

        $perPage = filter_var($input['per_page'] ?? 25, FILTER_VALIDATE_INT);
        if ($perPage === false || !in_array($perPage, [25, 50, 100], true)) {
            throw new RuntimeException('Per page must be 25, 50 or 100.', 422);
        }

        $scopeBuilder = function () use ($tenantId, $allowedUnits, $unitFilter) {
            $b = $this->db->table('student_intern_placements sip')
                ->join('users iu', 'iu.id = sip.user_id AND iu.tenant_id = sip.tenant_id')
                ->join('people ip', 'ip.id = sip.person_id AND ip.tenant_id = sip.tenant_id')
                ->join('users su', 'su.id = sip.supervisor_user_id AND su.tenant_id = sip.tenant_id', 'left')
                ->join('people sp', 'sp.id = su.person_id AND sp.tenant_id = su.tenant_id', 'left')
                ->where('sip.tenant_id', $tenantId)
                ->whereIn('sip.unit', $unitFilter === 'all' ? $allowedUnits : [$unitFilter]);

            if ($this->db->tableExists('student_profiles')) {
                $b->join('student_profiles stp', 'stp.person_id = ip.id AND stp.tenant_id = ip.tenant_id', 'left');
            }
            return $b;
        };

        $counts = ['all' => 0, 'active' => 0, 'scheduled' => 0, 'expired' => 0, 'revoked' => 0, 'completed' => 0];
        $statusRows = $this->db->table('student_intern_placements')
            ->select('status')
            ->selectCount('id', 'total')
            ->where('tenant_id', $tenantId)
            ->whereIn('unit', $unitFilter === 'all' ? $allowedUnits : [$unitFilter])
            ->groupBy('status')
            ->get()->getResultArray();
        foreach ($statusRows as $sr) {
            $s = (string) $sr['status'];
            $c = (int) $sr['total'];
            if (array_key_exists($s, $counts)) {
                $counts[$s] = $c;
            }
            $counts['all'] += $c;
        }

        $total = $counts['all'];
        $query = $scopeBuilder();
        if ($statusFilter !== 'all') {
            $query->where('sip.status', $statusFilter);
        }
        if ($search !== '') {
            $tokens = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($tokens ?: [$search] as $token) {
                LikePattern::applyGroup($query, [
                    'ip.first_name',
                    'ip.last_name',
                    'ip.mis_id',
                    'iu.username',
                    'sip.academic_term',
                    'sp.first_name',
                    'sp.last_name',
                ], $token);
            }
        }

        $filtered = $query->countAllResults(false);
        $pagination = TablePage::forList(['page' => $page, 'per_page' => $perPage])->metadata($total, $filtered);
        $offset = ($pagination['page'] - 1) * $pagination['per_page'];

        $select = self::PLACEMENT_COLUMNS . ', iu.username AS student_username, iu.role AS current_user_role, iu.status AS account_status, '
            . 'ip.first_name AS student_first_name, ip.last_name AS student_last_name, ip.mis_id AS student_mis_id, '
            . 'su.username AS supervisor_username, sp.first_name AS supervisor_first_name, sp.last_name AS supervisor_last_name';
        if ($this->db->tableExists('student_profiles')) {
            $select .= ', stp.program AS student_program, stp.year_level AS student_year_level, stp.department AS student_department';
        }

        $placements = $query->select($select)
            ->orderBy("CASE sip.status WHEN 'active' THEN 1 WHEN 'scheduled' THEN 2 WHEN 'revoked' THEN 3 ELSE 4 END", 'ASC', false)
            ->orderBy('sip.starts_at', 'DESC')
            ->orderBy('sip.id', 'DESC')
            ->limit($pagination['per_page'], $offset)
            ->get()->getResultArray();

        return [
            'actor'        => $actor,
            'allowedUnits' => array_intersect_key(self::UNITS, array_flip($allowedUnits)),
            'placements'   => $placements,
            'pagination'   => $pagination,
            'counts'       => $counts,
            'search'       => $search,
            'unitFilter'   => $unitFilter,
            'statusFilter' => $statusFilter,
            'perPage'      => $perPage,
        ];
    }

    /** Load one scoped placement with student, supervisor, and catalogue context. */
    public function detail(int $id): array
    {
        $actor = $this->actor();
        $tenantId = (int) $actor['tenant_id'];
        $allowedUnits = self::allowedUnitsForRole((string) $actor['role']);

        $this->reconcileTenant($tenantId);

        $placement = $this->findScopedPlacement($id, $tenantId, $allowedUnits);
        $supervisors = $this->eligibleSupervisorsByUnit($tenantId, [$placement['unit']]);

        return [
            'actor'       => $actor,
            'placement'   => $placement,
            'supervisors' => $supervisors[$placement['unit']] ?? [],
            'permissions' => $this->internRolePermissionsCatalogue(),
        ];
    }

    /** Data needed for rendering the new-placement form modal. */
    public function createContext(): array
    {
        $actor = $this->actor();
        $tenantId = (int) $actor['tenant_id'];
        $allowedUnits = self::allowedUnitsForRole((string) $actor['role']);
        $now = SchedulingClock::now();

        return [
            'actor'        => $actor,
            'allowedUnits' => array_intersect_key(self::UNITS, array_flip($allowedUnits)),
            'supervisors'  => $this->eligibleSupervisorsByUnit($tenantId, $allowedUnits),
            'defaultStart' => $now->format('Y-m-d\TH:i'),
            'defaultEnd'   => $now->modify('+30 days')->setTime(17, 0)->format('Y-m-d\TH:i'),
            'permissions'  => $this->internRolePermissionsCatalogue(),
        ];
    }

    /** Search active enrolled `student` users who do not currently hold an open placement. */
    public function searchEligibleStudents(string $query): array
    {
        $actor = $this->actor();
        $tenantId = (int) $actor['tenant_id'];
        $this->reconcileTenant($tenantId);

        $q = trim($query);
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            throw new RuntimeException('Search query must be between 2 and 100 characters.', 422);
        }

        $builder = $this->db->table('users u')
            ->select('u.id AS user_id, u.username, u.role, p.id AS person_id, p.first_name, p.last_name, p.mis_id')
            ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id')
            ->where('u.tenant_id', $tenantId)
            ->where('u.status', 'active')
            ->where('u.deleted_at', null)
            ->where('u.role', 'student')
            ->where('p.kind', 'student')
            ->where('p.deleted_at', null);

        if ($this->db->tableExists('student_profiles')) {
            $builder->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = p.tenant_id', 'left')
                ->select('sp.program, sp.year_level, sp.department');
        }

        if ($this->db->tableExists('student_intern_placements')) {
            $table = $this->db->protectIdentifiers($this->db->prefixTable('student_intern_placements'));
            $builder->where(
                "NOT EXISTS (SELECT 1 FROM {$table} sip WHERE sip.tenant_id = u.tenant_id AND sip.user_id = u.id AND sip.status IN ('scheduled', 'active'))",
                null,
                false
            );
        }

        $tokens = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($tokens ?: [$q] as $token) {
            LikePattern::applyGroup($builder, ['p.first_name', 'p.last_name', 'p.mis_id', 'u.username'], $token);
        }

        $rows = $builder->orderBy('p.last_name', 'ASC')->orderBy('p.first_name', 'ASC')->limit(15)->get()->getResultArray();
        return array_map(static function (array $row): array {
            $program = trim((string) ($row['program'] ?? ''));
            $year = trim((string) ($row['year_level'] ?? ''));
            return [
                'user_id'    => (int) $row['user_id'],
                'person_id'  => (int) $row['person_id'],
                'name'       => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
                'mis_id'     => (string) ($row['mis_id'] ?? $row['username']),
                'username'   => (string) $row['username'],
                'program'    => $program,
                'year_level' => $year,
                'department' => (string) ($row['department'] ?? ''),
            ];
        }, $rows);
    }

    /** Create a new student intern placement, promoting immediately when starts_at <= now. */
    public function createPlacement(array $input): array
    {
        $unit = is_string($input['unit'] ?? null) ? trim($input['unit']) : '';
        if (!array_key_exists($unit, self::UNITS)) {
            throw new RuntimeException('Choose a valid internship unit.', 422);
        }
        $actor = $this->actor($unit);
        $tenantId = (int) $actor['tenant_id'];

        $userId = $this->positiveInt($input['user_id'] ?? null, 'Select an enrolled student account.');
        $supervisorUserId = $this->positiveInt($input['supervisor_user_id'] ?? null, 'Select an assigned unit supervisor.');
        if ($userId === $supervisorUserId) {
            throw new RuntimeException('A student cannot supervise themselves.', 422);
        }

        [$startsAt, $endsAt] = $this->parseWindow($input['starts_at'] ?? null, $input['ends_at'] ?? null, true);
        $academicTerm = $this->optionalText($input['academic_term'] ?? null, 64, 'Academic term');
        $reason = $this->requiredText($input['reason'] ?? null, 500, 'Assignment reason');
        $internRole = self::UNIT_ROLES[$unit];

        return $this->inTenantTransaction($tenantId, function () use (
            $actor,
            $tenantId,
            $userId,
            $unit,
            $internRole,
            $supervisorUserId,
            $startsAt,
            $endsAt,
            $academicTerm,
            $reason
        ): array {
            $nowDt = SchedulingClock::now();
            $nowStr = $nowDt->format('Y-m-d H:i:s');

            $student = $this->db->table('users u')
                ->select('u.*, p.kind AS person_kind, p.first_name, p.last_name, p.mis_id')
                ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id')
                ->where('u.id', $userId)
                ->where('u.tenant_id', $tenantId)
                ->where('u.deleted_at', null)
                ->where('p.deleted_at', null)
                ->get()->getRowArray();

            if ($student === null) {
                throw new RuntimeException('Student account was not found in this tenant.', 404);
            }
            if (($student['person_kind'] ?? '') !== 'student') {
                throw new RuntimeException('Only enrolled student accounts can be assigned as student interns. Use Employees & Access for employees.', 422);
            }
            if (($student['status'] ?? '') !== 'active') {
                throw new RuntimeException('Only active student accounts can be placed as interns.', 422);
            }
            if (($student['role'] ?? '') !== 'student') {
                throw new RuntimeException('This account already holds an elevated role and cannot be assigned an overlapping placement.', 409);
            }

            $openExisting = $this->db->table('student_intern_placements')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->whereIn('status', self::OPEN_STATUSES)
                ->countAllResults();
            if ($openExisting > 0) {
                throw new RuntimeException('This student already has a scheduled or active internship placement.', 409);
            }

            $supervisor = $this->assertValidSupervisor($supervisorUserId, $unit, $tenantId);
            $activateNow = $startsAt <= $nowDt && $endsAt > $nowDt;
            $status = $activateNow ? 'active' : 'scheduled';

            $insert = [
                'tenant_id'          => $tenantId,
                'user_id'            => $userId,
                'person_id'          => (int) $student['person_id'],
                'unit'               => $unit,
                'intern_role'        => $internRole,
                'supervisor_user_id' => $supervisorUserId,
                'status'             => $status,
                'starts_at'          => $startsAt->format('Y-m-d H:i:s'),
                'ends_at'            => $endsAt->format('Y-m-d H:i:s'),
                'academic_term'      => $academicTerm,
                'assignment_reason'  => $reason,
                'activated_at'       => $activateNow ? $nowStr : null,
                'ended_at'           => null,
                'revoked_by_user_id' => null,
                'revoke_reason'      => null,
                'created_by_user_id' => (int) $actor['id'],
                'updated_by_user_id' => (int) $actor['id'],
                'version'            => 1,
                'created_at'         => $nowStr,
                'updated_at'         => $nowStr,
            ];

            $this->db->table('student_intern_placements')->insert($insert);
            $placementId = (int) $this->db->insertID();

            if ($activateNow) {
                $this->applyUserRoleChange($userId, $tenantId, $internRole, $nowStr);
                if ($unit === 'guidance') {
                    $this->provisionGuidanceSupervision($tenantId, $userId, $supervisorUserId, $endsAt->format('Y-m-d H:i:s'));
                }
            }

            $this->recordAudit(
                'intern.placement_created',
                $placementId,
                'create',
                [
                    'user_id'            => $userId,
                    'person_id'          => (int) $student['person_id'],
                    'student_name'       => trim((string) $student['first_name'] . ' ' . (string) $student['last_name']),
                    'unit'               => $unit,
                    'intern_role'        => $internRole,
                    'supervisor_user_id' => $supervisorUserId,
                    'supervisor_name'    => trim((string) ($supervisor['first_name'] ?? '') . ' ' . (string) ($supervisor['last_name'] ?? '')),
                    'status'             => $status,
                    'starts_at'          => $insert['starts_at'],
                    'ends_at'            => $insert['ends_at'],
                    'academic_term'      => $academicTerm,
                    'reason'             => $reason,
                ],
                (int) $actor['id'],
                (string) $actor['role']
            );

            return $this->findScopedPlacement($placementId, $tenantId, self::allowedUnitsForRole((string) $actor['role']));
        });
    }

    /** Update supervisor, schedule window (extension/adjustment), or term for an open placement. */
    public function updatePlacement(int $id, array $input): array
    {
        $actor = $this->actor();
        $tenantId = (int) $actor['tenant_id'];
        $allowedUnits = self::allowedUnitsForRole((string) $actor['role']);
        $version = $this->positiveInt($input['version'] ?? null, 'Invalid placement version.');
        $reason = $this->requiredText($input['reason'] ?? null, 500, 'Reason for update');

        return $this->inTenantTransaction($tenantId, function () use ($actor, $tenantId, $allowedUnits, $id, $version, $reason, $input): array {
            $existing = $this->findScopedPlacement($id, $tenantId, $allowedUnits);
            if ((int) $existing['version'] !== $version) {
                throw new RuntimeException('Placement record changed since it was loaded. Refresh and try again.', 409);
            }
            if (!in_array($existing['status'], self::OPEN_STATUSES, true)) {
                throw new RuntimeException('Only active or scheduled placements can be modified.', 409);
            }

            $unit = (string) $existing['unit'];
            $supervisorUserId = $this->positiveInt($input['supervisor_user_id'] ?? null, 'Select an assigned unit supervisor.');
            if ($supervisorUserId === (int) $existing['user_id']) {
                throw new RuntimeException('A student cannot supervise themselves.', 422);
            }
            $supervisor = $this->assertValidSupervisor($supervisorUserId, $unit, $tenantId);

            $startInput = $existing['status'] === 'scheduled'
                ? ($input['starts_at'] ?? $existing['starts_at'])
                : $existing['starts_at'];
            [$startsAt, $endsAt] = $this->parseWindow($startInput, $input['ends_at'] ?? null, true);
            $academicTerm = $this->optionalText($input['academic_term'] ?? null, 64, 'Academic term');

            $nowDt = SchedulingClock::now();
            $nowStr = $nowDt->format('Y-m-d H:i:s');
            $nextStatus = (string) $existing['status'];
            $activatedAt = $existing['activated_at'];
            $shouldActivate = $existing['status'] === 'scheduled' && $startsAt <= $nowDt && $endsAt > $nowDt;
            if ($shouldActivate) {
                $nextStatus = 'active';
                $activatedAt = $nowStr;
            }

            $this->db->table('student_intern_placements')
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('version', $version)
                ->update([
                    'supervisor_user_id' => $supervisorUserId,
                    'starts_at'          => $startsAt->format('Y-m-d H:i:s'),
                    'ends_at'            => $endsAt->format('Y-m-d H:i:s'),
                    'academic_term'      => $academicTerm,
                    'assignment_reason'  => $reason,
                    'status'             => $nextStatus,
                    'activated_at'       => $activatedAt,
                    'updated_by_user_id' => (int) $actor['id'],
                    'version'            => $version + 1,
                    'updated_at'         => $nowStr,
                ]);
            if ($this->db->affectedRows() !== 1) {
                throw new RuntimeException('Placement record changed concurrently.', 409);
            }

            if ($shouldActivate) {
                $this->applyUserRoleChange((int) $existing['user_id'], $tenantId, (string) $existing['intern_role'], $nowStr);
            }

            if ($unit === 'guidance' && $nextStatus === 'active') {
                $this->provisionGuidanceSupervision(
                    $tenantId,
                    (int) $existing['user_id'],
                    $supervisorUserId,
                    $endsAt->format('Y-m-d H:i:s')
                );
            }

            $this->recordAudit(
                'intern.placement_updated',
                $id,
                'update',
                [
                    'user_id'                 => (int) $existing['user_id'],
                    'unit'                    => $unit,
                    'previous_supervisor_id'  => (int) $existing['supervisor_user_id'],
                    'new_supervisor_id'       => $supervisorUserId,
                    'supervisor_name'         => trim((string) ($supervisor['first_name'] ?? '') . ' ' . (string) ($supervisor['last_name'] ?? '')),
                    'previous_ends_at'        => $existing['ends_at'],
                    'new_ends_at'             => $endsAt->format('Y-m-d H:i:s'),
                    'previous_status'         => $existing['status'],
                    'new_status'              => $nextStatus,
                    'academic_term'           => $academicTerm,
                    'reason'                  => $reason,
                ],
                (int) $actor['id'],
                (string) $actor['role']
            );

            return $this->findScopedPlacement($id, $tenantId, $allowedUnits);
        });
    }

    /** Immediately revoke an open placement, revert role to student, invalidate sessions, and tear down supervision. */
    public function revokePlacement(int $id, array $input): array
    {
        $actor = $this->actor();
        $tenantId = (int) $actor['tenant_id'];
        $allowedUnits = self::allowedUnitsForRole((string) $actor['role']);
        $version = $this->positiveInt($input['version'] ?? null, 'Invalid placement version.');
        $reason = $this->requiredText($input['reason'] ?? null, 500, 'Revocation reason');

        return $this->inTenantTransaction($tenantId, function () use ($actor, $tenantId, $allowedUnits, $id, $version, $reason): array {
            $existing = $this->findScopedPlacement($id, $tenantId, $allowedUnits);
            if ((int) $existing['version'] !== $version) {
                throw new RuntimeException('Placement record changed since it was loaded. Refresh and try again.', 409);
            }
            if (!in_array($existing['status'], self::OPEN_STATUSES, true)) {
                throw new RuntimeException('Only active or scheduled placements can be revoked.', 409);
            }

            $nowStr = SchedulingClock::now()->format('Y-m-d H:i:s');
            $this->db->table('student_intern_placements')
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('version', $version)
                ->update([
                    'status'             => 'revoked',
                    'ended_at'           => $nowStr,
                    'revoked_by_user_id' => (int) $actor['id'],
                    'revoke_reason'      => $reason,
                    'updated_by_user_id' => (int) $actor['id'],
                    'version'            => $version + 1,
                    'updated_at'         => $nowStr,
                ]);
            if ($this->db->affectedRows() !== 1) {
                throw new RuntimeException('Placement record changed concurrently.', 409);
            }

            $userId = (int) $existing['user_id'];
            $user = $this->db->table('users')->where('id', $userId)->where('tenant_id', $tenantId)->get()->getRowArray();
            if ($user !== null && in_array($user['role'] ?? '', self::INTERN_ROLES, true)) {
                $this->applyUserRoleChange($userId, $tenantId, 'student', $nowStr);
            }

            if ($existing['unit'] === 'guidance') {
                $this->teardownGuidanceSupervision($tenantId, $userId);
            }

            $this->recordAudit(
                'intern.placement_revoked',
                $id,
                'revoke',
                [
                    'user_id'         => $userId,
                    'unit'            => $existing['unit'],
                    'previous_status' => $existing['status'],
                    'reason'          => $reason,
                ],
                (int) $actor['id'],
                (string) $actor['role']
            );

            return $this->findScopedPlacement($id, $tenantId, $allowedUnits);
        });
    }

    /**
     * Enforce placement window for one student/intern account fail-closed.
     * Auto-expires overdue active placements, activates due scheduled placements, and reverts orphan intern roles.
     *
     * @return array{changed: bool, placement: ?array<string, mixed>}
     */
    public function reconcileForUser(int $userId, int $tenantId): array
    {
        if ($userId <= 0 || $tenantId <= 0 || !$this->db->tableExists('student_intern_placements')) {
            return ['changed' => false, 'placement' => null];
        }

        $user = $this->db->table('users')->where('id', $userId)->where('tenant_id', $tenantId)
            ->where('deleted_at', null)->get()->getRowArray();
        if ($user === null) {
            return ['changed' => false, 'placement' => null];
        }

        $person = !empty($user['person_id'])
            ? $this->db->table('people')->where('id', $user['person_id'])->where('tenant_id', $tenantId)
                ->where('deleted_at', null)->get()->getRowArray()
            : null;
        if ($person === null || ($person['kind'] ?? null) !== 'student') {
            return ['changed' => false, 'placement' => null];
        }

        $nowStr = SchedulingClock::now()->format('Y-m-d H:i:s');
        $changed = false;

        // 1. Expire overdue scheduled placements cleanly.
        $this->db->table('student_intern_placements')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', 'scheduled')
            ->where('ends_at <=', $nowStr)
            ->update([
                'status'     => 'expired',
                'ended_at'   => $nowStr,
                'updated_at' => $nowStr,
            ]);

        // 2. Expire overdue active placements (auto-revert to student + bump auth_version).
        $overdueActive = $this->db->table('student_intern_placements')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where('ends_at <=', $nowStr)
            ->get()->getResultArray();
        foreach ($overdueActive as $row) {
            $changed = $this->expirePlacementRow($row, $nowStr) || $changed;
        }

        // 3. Re-read current active in-window placement.
        $active = $this->activeInWindowRow($userId, $tenantId, $nowStr);

        // 4. Auto-activate due scheduled placement if none is currently active.
        if ($active === null) {
            $dueScheduled = $this->db->table('student_intern_placements')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('status', 'scheduled')
                ->where('starts_at <=', $nowStr)
                ->where('ends_at >', $nowStr)
                ->orderBy('starts_at', 'ASC')
                ->get()->getRowArray();
            if ($dueScheduled !== null) {
                $changed = $this->activateScheduledRow($dueScheduled, $nowStr) || $changed;
                $active = $this->activeInWindowRow($userId, $tenantId, $nowStr);
            }
        }

        // 5. Fail closed: a student holding an intern role without an active in-window placement reverts immediately.
        $freshUser = $this->db->table('users')->where('id', $userId)->where('tenant_id', $tenantId)->get()->getRowArray();
        $currentRole = (string) ($freshUser['role'] ?? 'student');
        if ($active === null && in_array($currentRole, self::INTERN_ROLES, true)) {
            $this->applyUserRoleChange($userId, $tenantId, 'student', $nowStr);
            if ($currentRole === 'guidance_intern') {
                $this->teardownGuidanceSupervision($tenantId, $userId);
            }
            $this->recordAuditBestEffort(
                $tenantId,
                'intern.placement_expired',
                '0',
                'expire',
                ['user_id' => $userId, 'previous_role' => $currentRole, 'reason' => 'no_active_placement']
            );
            $changed = true;
        } elseif ($active !== null && $currentRole !== $active['intern_role']) {
            // Keep role synced to the active placement when still student/another intern role.
            if ($currentRole === 'student' || in_array($currentRole, self::INTERN_ROLES, true)) {
                $this->applyUserRoleChange($userId, $tenantId, (string) $active['intern_role'], $nowStr);
                if ($active['unit'] === 'guidance') {
                    $this->provisionGuidanceSupervision($tenantId, $userId, (int) $active['supervisor_user_id'], (string) $active['ends_at']);
                }
                $changed = true;
            }
        }

        return [
            'changed'   => $changed,
            'placement' => $active !== null ? $this->decoratePlacementSummary($active) : null,
        ];
    }

    /** Batch-reconcile due activations and overdue expirations across a tenant (used by listing & Spark CLI). */
    public function reconcileTenant(int $tenantId): array
    {
        if ($tenantId <= 0 || !$this->db->tableExists('student_intern_placements')) {
            return ['activated' => 0, 'expired' => 0];
        }

        $nowStr = SchedulingClock::now()->format('Y-m-d H:i:s');
        $activated = 0;
        $expired = 0;

        $overdueActive = $this->db->table('student_intern_placements')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('ends_at <=', $nowStr)
            ->get()->getResultArray();
        foreach ($overdueActive as $row) {
            if ($this->expirePlacementRow($row, $nowStr)) {
                $expired++;
            }
        }

        $missedScheduled = $this->db->table('student_intern_placements')
            ->where('tenant_id', $tenantId)
            ->where('status', 'scheduled')
            ->where('ends_at <=', $nowStr)
            ->get()->getResultArray();
        foreach ($missedScheduled as $row) {
            $this->db->table('student_intern_placements')
                ->where('id', (int) $row['id'])
                ->where('tenant_id', $tenantId)
                ->where('version', (int) $row['version'])
                ->update([
                    'status'     => 'expired',
                    'ended_at'   => $nowStr,
                    'version'    => (int) $row['version'] + 1,
                    'updated_at' => $nowStr,
                ]);
            if ($this->db->affectedRows() === 1) {
                $expired++;
            }
        }

        $dueScheduled = $this->db->table('student_intern_placements')
            ->where('tenant_id', $tenantId)
            ->where('status', 'scheduled')
            ->where('starts_at <=', $nowStr)
            ->where('ends_at >', $nowStr)
            ->get()->getResultArray();
        foreach ($dueScheduled as $row) {
            if ($this->activateScheduledRow($row, $nowStr)) {
                $activated++;
            }
        }

        return ['activated' => $activated, 'expired' => $expired];
    }

    /** Lightweight active-placement lookup with supervisor details (read-only). */
    public function activePlacementForUser(int $userId, int $tenantId): ?array
    {
        if ($userId <= 0 || $tenantId <= 0 || !$this->db->tableExists('student_intern_placements')) {
            return null;
        }
        $nowStr = SchedulingClock::now()->format('Y-m-d H:i:s');
        $row = $this->activeInWindowRow($userId, $tenantId, $nowStr);
        return $row !== null ? $this->decoratePlacementSummary($row) : null;
    }

    /** Live database permissions granted to the 3 intern roles (for modal review). */
    public function internRolePermissionsCatalogue(): array
    {
        if (!$this->db->tableExists('role_permissions') || !$this->db->tableExists('permissions')) {
            return [];
        }

        $rows = $this->db->table('role_permissions rp')
            ->select('rp.role, p.code, p.name')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->whereIn('rp.role', self::INTERN_ROLES)
            ->orderBy('p.code', 'ASC')
            ->get()->getResultArray();

        $catalogue = array_fill_keys(self::INTERN_ROLES, []);
        foreach ($rows as $row) {
            $catalogue[$row['role']][] = ['code' => $row['code'], 'name' => $row['name']];
        }
        return $catalogue;
    }

    private function activeInWindowRow(int $userId, int $tenantId, string $nowStr): ?array
    {
        $row = $this->db->table('student_intern_placements sip')
            ->select(self::PLACEMENT_COLUMNS . ', su.username AS supervisor_username, sp.first_name AS supervisor_first_name, sp.last_name AS supervisor_last_name')
            ->join('users su', 'su.id = sip.supervisor_user_id AND su.tenant_id = sip.tenant_id', 'left')
            ->join('people sp', 'sp.id = su.person_id AND sp.tenant_id = su.tenant_id', 'left')
            ->where('sip.tenant_id', $tenantId)
            ->where('sip.user_id', $userId)
            ->where('sip.status', 'active')
            ->where('sip.starts_at <=', $nowStr)
            ->where('sip.ends_at >', $nowStr)
            ->orderBy('sip.id', 'DESC')
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return array<string, mixed> */
    private function decoratePlacementSummary(array $row): array
    {
        $supervisorName = trim((string) ($row['supervisor_first_name'] ?? '') . ' ' . (string) ($row['supervisor_last_name'] ?? ''));
        if ($supervisorName === '') {
            $supervisorName = (string) ($row['supervisor_username'] ?? 'Assigned Supervisor');
        }

        return [
            'id'                 => (int) $row['id'],
            'unit'               => (string) $row['unit'],
            'unit_label'         => self::UNITS[$row['unit']] ?? (string) $row['unit'],
            'intern_role'        => (string) $row['intern_role'],
            'supervisor_user_id' => (int) $row['supervisor_user_id'],
            'supervisor_name'    => $supervisorName,
            'starts_at'          => (string) $row['starts_at'],
            'ends_at'            => (string) $row['ends_at'],
            'academic_term'      => $row['academic_term'] ?? null,
        ];
    }

    private function expirePlacementRow(array $row, string $nowStr): bool
    {
        $tenantId = (int) $row['tenant_id'];
        $userId = (int) $row['user_id'];
        $placementId = (int) $row['id'];
        $version = (int) $row['version'];

        $this->db->table('student_intern_placements')
            ->where('id', $placementId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('version', $version)
            ->update([
                'status'     => 'expired',
                'ended_at'   => $nowStr,
                'version'    => $version + 1,
                'updated_at' => $nowStr,
            ]);
        if ($this->db->affectedRows() !== 1) {
            return false;
        }

        $user = $this->db->table('users')->where('id', $userId)->where('tenant_id', $tenantId)->get()->getRowArray();
        if ($user !== null && in_array($user['role'] ?? '', self::INTERN_ROLES, true)) {
            $this->applyUserRoleChange($userId, $tenantId, 'student', $nowStr);
        }

        if (($row['unit'] ?? '') === 'guidance') {
            $this->teardownGuidanceSupervision($tenantId, $userId);
        }

        $this->recordAuditBestEffort(
            $tenantId,
            'intern.placement_expired',
            (string) $placementId,
            'expire',
            [
                'user_id'     => $userId,
                'unit'        => $row['unit'],
                'intern_role' => $row['intern_role'],
                'ends_at'     => $row['ends_at'],
            ]
        );

        return true;
    }

    private function activateScheduledRow(array $row, string $nowStr): bool
    {
        $tenantId = (int) $row['tenant_id'];
        $userId = (int) $row['user_id'];
        $placementId = (int) $row['id'];
        $version = (int) $row['version'];

        $user = $this->db->table('users u')
            ->select('u.role, u.status, p.kind')
            ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id')
            ->where('u.id', $userId)
            ->where('u.tenant_id', $tenantId)
            ->where('u.deleted_at', null)
            ->where('p.deleted_at', null)
            ->get()->getRowArray();
        if ($user === null || ($user['status'] ?? '') !== 'active' || ($user['kind'] ?? '') !== 'student') {
            return false;
        }
        if (($user['role'] ?? '') !== 'student' && !in_array($user['role'] ?? '', self::INTERN_ROLES, true)) {
            return false;
        }

        $this->db->table('student_intern_placements')
            ->where('id', $placementId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'scheduled')
            ->where('version', $version)
            ->update([
                'status'       => 'active',
                'activated_at' => $nowStr,
                'version'      => $version + 1,
                'updated_at'   => $nowStr,
            ]);
        if ($this->db->affectedRows() !== 1) {
            return false;
        }

        $this->applyUserRoleChange($userId, $tenantId, (string) $row['intern_role'], $nowStr);
        if (($row['unit'] ?? '') === 'guidance') {
            $this->provisionGuidanceSupervision($tenantId, $userId, (int) $row['supervisor_user_id'], (string) $row['ends_at']);
        }

        $this->recordAuditBestEffort(
            $tenantId,
            'intern.placement_activated',
            (string) $placementId,
            'activate',
            [
                'user_id'            => $userId,
                'unit'               => $row['unit'],
                'intern_role'        => $row['intern_role'],
                'supervisor_user_id' => (int) $row['supervisor_user_id'],
            ]
        );

        return true;
    }

    private function applyUserRoleChange(int $userId, int $tenantId, string $nextRole, string $nowStr): void
    {
        $user = $this->db->table('users')->where('id', $userId)->where('tenant_id', $tenantId)->get()->getRowArray();
        if ($user === null) {
            return;
        }

        $this->db->table('users')
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->update([
                'role'         => $nextRole,
                'auth_version' => ((int) ($user['auth_version'] ?? 1)) + 1,
                'updated_at'   => $nowStr,
            ]);

        if ($this->db->tableExists('api_tokens')) {
            (new TokenService(db: $this->db))->revokeAllForUser($userId, $tenantId);
        }
    }

    private function provisionGuidanceSupervision(int $tenantId, int $userId, int $supervisorUserId, string $endsAt): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $expiryDate = substr($endsAt, 0, 10);

        if ($this->db->tableExists('guidance_practitioner_profiles')) {
            $user = $this->db->table('users')->select('person_id, role')->where('id', $userId)->where('tenant_id', $tenantId)->get()->getRowArray();
            $personId = !empty($user['person_id']) ? (int) $user['person_id'] : null;
            $scopeList = ['intake', 'psychosocial', 'counseling'];
            $scopeJson = json_encode($scopeList, JSON_UNESCAPED_SLASHES);

            $existing = $this->db->table('guidance_practitioner_profiles')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->get()->getRowArray();
            if ($existing !== null) {
                $internProfileId = (int) $existing['id'];
                $this->db->table('guidance_practitioner_profiles')
                    ->where('id', $internProfileId)
                    ->where('tenant_id', $tenantId)
                    ->update([
                        'person_id'             => $personId,
                        'credential_tier'       => 'counselor_associate',
                        'credential_expiry'     => $expiryDate,
                        'professional_standing' => 'active',
                        'service_scope_json'    => $scopeJson,
                        'is_active'             => 1,
                        'updated_at'            => $nowUtc,
                    ]);
            } else {
                $this->db->table('guidance_practitioner_profiles')->insert([
                    'tenant_id'             => $tenantId,
                    'person_id'             => $personId,
                    'user_id'               => $userId,
                    'credential_tier'       => 'counselor_associate',
                    'license_number'        => null,
                    'credential_expiry'     => $expiryDate,
                    'professional_standing' => 'active',
                    'service_scope_json'    => $scopeJson,
                    'is_active'             => 1,
                    'created_at'            => $nowUtc,
                    'updated_at'            => $nowUtc,
                ]);
                $internProfileId = (int) $this->db->insertID();
            }
            $this->syncPractitionerScopes($tenantId, $internProfileId, $scopeList);

            // Ensure the assigned Guidance supervisor holds an active co-signing practitioner profile.
            $supProfile = $this->db->table('guidance_practitioner_profiles')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $supervisorUserId)
                ->get()->getRowArray();
            if ($supProfile === null) {
                $supUser = $this->db->table('users')->select('person_id, role')->where('id', $supervisorUserId)->where('tenant_id', $tenantId)->get()->getRowArray();
                $supTier = in_array($supUser['role'] ?? '', ['guidance_admin', 'guidance_supervisor'], true)
                    ? 'guidance_supervisor'
                    : 'licensed_counselor';
                $supScopes = ['intake', 'psychosocial', 'counseling', 'assessment_interpretation'];
                $this->db->table('guidance_practitioner_profiles')->insert([
                    'tenant_id'             => $tenantId,
                    'person_id'             => !empty($supUser['person_id']) ? (int) $supUser['person_id'] : null,
                    'user_id'               => $supervisorUserId,
                    'credential_tier'       => $supTier,
                    'license_number'        => null,
                    'credential_expiry'     => null,
                    'professional_standing' => 'active',
                    'service_scope_json'    => json_encode($supScopes, JSON_UNESCAPED_SLASHES),
                    'is_active'             => 1,
                    'created_at'            => $nowUtc,
                    'updated_at'            => $nowUtc,
                ]);
                $this->syncPractitionerScopes($tenantId, (int) $this->db->insertID(), $supScopes);
            }
        }

        if ($this->db->tableExists('guidance_supervision_assignments')) {
            $activeRow = $this->db->table('guidance_supervision_assignments')
                ->where('tenant_id', $tenantId)
                ->where('associate_user_id', $userId)
                ->where('status', 'active')
                ->orderBy('id', 'DESC')
                ->get()->getRowArray();

            if ($activeRow !== null && (int) $activeRow['supervisor_user_id'] === $supervisorUserId) {
                return;
            }

            if ($activeRow !== null) {
                $this->db->table('guidance_supervision_assignments')
                    ->where('tenant_id', $tenantId)
                    ->where('associate_user_id', $userId)
                    ->where('status', 'active')
                    ->update([
                        'status'  => 'completed',
                        'ends_at' => $nowUtc,
                    ]);
            }

            $this->db->table('guidance_supervision_assignments')->insert([
                'tenant_id'          => $tenantId,
                'associate_user_id'  => $userId,
                'supervisor_user_id' => $supervisorUserId,
                'status'             => 'active',
                'co_sign_required'   => 1,
                'starts_at'          => $nowUtc,
                'ends_at'            => null,
                'created_at'         => $nowUtc,
            ]);
        }
    }

    private function teardownGuidanceSupervision(int $tenantId, int $userId): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');

        if ($this->db->tableExists('guidance_practitioner_profiles')) {
            $this->db->table('guidance_practitioner_profiles')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('credential_tier', 'counselor_associate')
                ->update([
                    'is_active'  => 0,
                    'updated_at' => $nowUtc,
                ]);
        }

        if ($this->db->tableExists('guidance_supervision_assignments')) {
            $this->db->table('guidance_supervision_assignments')
                ->where('tenant_id', $tenantId)
                ->where('associate_user_id', $userId)
                ->where('status', 'active')
                ->update([
                    'status'  => 'completed',
                    'ends_at' => $nowUtc,
                ]);
        }
    }

    /**
     * @param list<string> $units
     * @return array<string, list<array<string, mixed>>>
     */
    private function eligibleSupervisorsByUnit(int $tenantId, array $units): array
    {
        $result = [];
        foreach ($units as $unit) {
            $roles = self::SUPERVISOR_ROLES[$unit] ?? [];
            if ($roles === []) {
                $result[$unit] = [];
                continue;
            }
            $builder = $this->db->table('users u')
                ->select('u.id AS user_id, u.role, u.username, p.first_name, p.last_name, p.mis_id')
                ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id')
                ->where('u.tenant_id', $tenantId)
                ->where('u.status', 'active')
                ->where('u.deleted_at', null)
                ->where('p.kind', 'employee')
                ->where('p.deleted_at', null)
                ->whereIn('u.role', $roles);

            if ($this->db->tableExists('employee_profiles')) {
                $builder->join('employee_profiles ep', 'ep.person_id = p.id AND ep.tenant_id = p.tenant_id', 'left')
                    ->where('(ep.employment_status IS NULL OR LOWER(TRIM(ep.employment_status)) = \'active\')', null, false);
            }

            $rows = $builder->orderBy('p.last_name', 'ASC')->orderBy('p.first_name', 'ASC')->get()->getResultArray();
            $result[$unit] = array_map(static fn (array $r): array => [
                'user_id' => (int) $r['user_id'],
                'name'    => trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')),
                'role'    => (string) $r['role'],
                'mis_id'  => (string) ($r['mis_id'] ?? $r['username']),
            ], $rows);
        }
        return $result;
    }

    private function assertValidSupervisor(int $supervisorUserId, string $unit, int $tenantId): array
    {
        $roles = self::SUPERVISOR_ROLES[$unit] ?? [];
        $builder = $this->db->table('users u')
            ->select('u.id, u.role, u.status, p.kind, p.first_name, p.last_name')
            ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id')
            ->where('u.id', $supervisorUserId)
            ->where('u.tenant_id', $tenantId)
            ->where('u.status', 'active')
            ->where('u.deleted_at', null)
            ->where('p.kind', 'employee')
            ->where('p.deleted_at', null)
            ->whereIn('u.role', $roles);

        if ($this->db->tableExists('employee_profiles')) {
            $builder->join('employee_profiles ep', 'ep.person_id = p.id AND ep.tenant_id = p.tenant_id', 'left')
                ->where('(ep.employment_status IS NULL OR LOWER(TRIM(ep.employment_status)) = \'active\')', null, false);
        }

        $row = $builder->get()->getRowArray();
        if ($row === null) {
            throw new RuntimeException('Assigned supervisor must be an active employee authorized in the selected unit.', 422);
        }
        return $row;
    }

    /** @param list<string> $allowedUnits */
    private function findScopedPlacement(int $id, int $tenantId, array $allowedUnits): array
    {
        $select = self::PLACEMENT_COLUMNS . ', iu.username AS student_username, iu.role AS current_user_role, iu.status AS account_status, '
            . 'ip.first_name AS student_first_name, ip.last_name AS student_last_name, ip.mis_id AS student_mis_id, '
            . 'su.username AS supervisor_username, sp.first_name AS supervisor_first_name, sp.last_name AS supervisor_last_name';

        $builder = $this->db->table('student_intern_placements sip')
            ->join('users iu', 'iu.id = sip.user_id AND iu.tenant_id = sip.tenant_id')
            ->join('people ip', 'ip.id = sip.person_id AND ip.tenant_id = sip.tenant_id')
            ->join('users su', 'su.id = sip.supervisor_user_id AND su.tenant_id = sip.tenant_id', 'left')
            ->join('people sp', 'sp.id = su.person_id AND sp.tenant_id = su.tenant_id', 'left')
            ->where('sip.id', $id)
            ->where('sip.tenant_id', $tenantId);

        if ($this->db->tableExists('student_profiles')) {
            $builder->join('student_profiles stp', 'stp.person_id = ip.id AND stp.tenant_id = ip.tenant_id', 'left');
            $select .= ', stp.program AS student_program, stp.year_level AS student_year_level, stp.department AS student_department';
        }

        $row = $builder->select($select)->get()->getRowArray();
        if ($row === null) {
            throw new RuntimeException('Intern placement record was not found.', 404);
        }
        if (!in_array($row['unit'], $allowedUnits, true)) {
            throw new RuntimeException('You are not authorized to access placements for this unit.', 403);
        }
        return $row;
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} */
    private function parseWindow(mixed $startRaw, mixed $endRaw, bool $requireFutureEnd): array
    {
        $tz = new DateTimeZone('Asia/Manila');
        $startsAt = $this->parseManilaDateTime($startRaw, 'Start date and time', $tz);
        $endsAt = $this->parseManilaDateTime($endRaw, 'End date and time', $tz);

        if ($endsAt <= $startsAt) {
            throw new RuntimeException('Placement end date/time must be after the start date/time.', 422);
        }

        $maxEnd = $startsAt->modify('+' . self::MAX_PLACEMENT_DAYS . ' days');
        if ($endsAt > $maxEnd) {
            throw new RuntimeException('Placement duration cannot exceed ' . self::MAX_PLACEMENT_DAYS . ' days.', 422);
        }

        if ($requireFutureEnd && $endsAt <= SchedulingClock::now()) {
            throw new RuntimeException('Placement end date/time must be in the future.', 422);
        }

        return [$startsAt, $endsAt];
    }

    private function parseManilaDateTime(mixed $raw, string $label, DateTimeZone $tz): DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException($label . ' is required.', 422);
        }
        $normalized = str_replace('T', ' ', trim($raw));
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $normalized, $tz);
            $errors = DateTimeImmutable::getLastErrors();
            if ($dt !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $dt;
            }
        }
        throw new RuntimeException($label . ' must be a valid date and time.', 422);
    }

    private function positiveInt(mixed $value, string $message): int
    {
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/\A[1-9][0-9]*\z/D', (string) $value)
            || ($parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
            throw new RuntimeException($message, 422);
        }
        return (int) $parsed;
    }

    private function requiredText(mixed $value, int $max, string $label): string
    {
        if (!is_string($value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException($label . ' contains unsupported characters.', 422);
        }
        $trimmed = trim($value);
        if ($trimmed === '' || mb_strlen($trimmed, 'UTF-8') > $max) {
            throw new RuntimeException($label . ' must be between 1 and ' . $max . ' characters.', 422);
        }
        return $trimmed;
    }

    private function optionalText(mixed $value, int $max, string $label): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        return $this->requiredText($value, $max, $label);
    }

    private function inTenantTransaction(int $tenantId, callable $operation): array
    {
        $audit = new AuditService(db: $this->db);
        if (!$this->db->transBegin()) {
            throw new RuntimeException('Intern placement transaction unavailable.', 503);
        }

        try {
            $audit->lockTenant($tenantId);
            $result = $operation();
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Intern placement changes could not be saved.', 503);
            }
            return $result;
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    private function recordAudit(
        string $eventType,
        int $placementId,
        string $action,
        array $payload,
        int $actorUserId,
        string $actorRole
    ): void {
        $event = (new AuditService(db: $this->db))->log(
            $eventType,
            'student_intern_placement',
            (string) $placementId,
            $action,
            $payload,
            $actorUserId,
            $actorRole
        );
        if (empty($event['id'])) {
            throw new RuntimeException('Intern governance audit event missing.', 503);
        }
    }

    private function recordAuditBestEffort(
        int $tenantId,
        string $eventType,
        string $entityId,
        string $action,
        array $payload
    ): void {
        if (!$this->db->tableExists('audit_events')) {
            return;
        }
        $prevTenant = CurrentTenant::id();
        try {
            CurrentTenant::set($tenantId);
            (new AuditService(db: $this->db))->log(
                $eventType,
                'student_intern_placement',
                $entityId,
                $action,
                $payload,
                null,
                'system'
            );
        } catch (Throwable $e) {
            log_message('warning', 'System intern reconciliation audit skipped: {error}', ['error' => $e->getMessage()]);
        } finally {
            CurrentTenant::set($prevTenant);
        }
    }

    /** @param list<string> $scopes */
    private function syncPractitionerScopes(int $tenantId, int $profileId, array $scopes): void
    {
        if ($profileId <= 0 || !$this->db->tableExists('guidance_practitioner_scopes')) {
            return;
        }
        $this->db->table('guidance_practitioner_scopes')
            ->where('tenant_id', $tenantId)
            ->where('profile_id', $profileId)
            ->delete();
        $clean = array_values(array_unique(array_filter(array_map('trim', $scopes), static fn (string $s): bool => $s !== '')));
        if ($clean === []) {
            return;
        }
        $batch = array_map(static fn (string $code): array => [
            'tenant_id'  => $tenantId,
            'profile_id' => $profileId,
            'scope_code' => substr($code, 0, 64),
        ], $clean);
        $this->db->table('guidance_practitioner_scopes')->insertBatch($batch);
    }
}

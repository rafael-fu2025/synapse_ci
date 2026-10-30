<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Audit\AuditService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/** Tenant-local AI capabilities only. Source-data authority stays in the existing RBAC system. */
final class AiGrantStore
{
    public const ROLES = [
        'clinic' => ['clinic_admin', 'clinic_staff', 'superadmin'],
        'guidance' => ['guidance_admin', 'guidance_supervisor', 'counsellor', 'superadmin'],
        'bmg' => ['bmg_admin', 'facilities_op', 'superadmin'],
    ];
    public const PERMISSIONS = ['ai.insights.read', 'ai.insights.generate', 'ai.insights.review', 'ai.insights.approve'];
    private const TABLE = 'ai_department_access';
    private const MERGED = '_ai_scoped_permissions';

    public function __construct(private ?BaseConnection $db = null) {}

    public function ready(): bool
    {
        try {
            $this->db ??= db_connect();
            if (!in_array($this->db->DBDriver, ['MySQLi', 'SQLite3'], true)
                || !$this->db->tableExists(self::TABLE)
                || array_diff(['tenant_id', 'unit', 'role', 'permission', 'created_by', 'created_at'], $this->db->getFieldNames(self::TABLE)) !== []) {
                return false;
            }
            foreach ($this->db->getIndexData(self::TABLE) as $index) {
                if (in_array(strtoupper($index->type), ['PRIMARY', 'UNIQUE'], true)
                    && $index->fields === ['tenant_id', 'unit', 'role', 'permission']) { return true; }
            }
        } catch (Throwable) {}
        return false;
    }

    /** Runtime entry point: no cached/session grants, including when the actor was already merged. */
    public static function mergeActor(array $actor, string $unit): array
    {
        return (new self())->merge($actor, $unit);
    }

    public function merge(array $actor, string $unit): array
    {
        $permissions = $actor['permissions'] ?? null;
        if (!is_array($permissions) || !array_is_list($permissions)
            || count(array_filter($permissions, 'is_string')) !== count($permissions)) { return $actor; }
        // Strip only capabilities added by us, never pre-existing explicit RBAC permissions.
        $previous = is_array($actor[self::MERGED] ?? null) ? array_intersect(array_filter($actor[self::MERGED], 'is_string'), self::PERMISSIONS) : [];
        $permissions = array_values(array_diff($permissions, $previous));
        unset($actor[self::MERGED]);
        $actor['permissions'] = $permissions;
        $tenant = filter_var($actor['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $id = filter_var($actor['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (($actor['logged_in'] ?? null) !== true || $tenant === false || $id === false
            || (!is_int($actor['tenant_id']) && !is_string($actor['tenant_id']))
            || (!is_int($actor['id']) && !is_string($actor['id']))
            || !in_array($actor['role'] ?? null, self::ROLES[$unit] ?? [], true)) { return $actor; }
        try {
            $this->assertReady();
            $result = $this->db->table(self::TABLE)->select('permission')->where([
                'tenant_id' => $tenant, 'unit' => $unit, 'role' => $actor['role'],
            ])->get();
            if ($result === false) { return $actor; }
            $grants = array_values(array_intersect(self::PERMISSIONS, array_column($result->getResultArray(), 'permission')));
            $added = array_values(array_diff($grants, $permissions));
            if ($added !== []) { $actor[self::MERGED] = $added; }
            $actor['permissions'] = array_values(array_unique([...$permissions, ...$grants]));
        } catch (Throwable) {
            // Unavailable or missing schema supplies no additional authority.
        }
        return $actor;
    }

    public function summary(int $tenantId): array
    {
        try {
            if ($tenantId < 1) { throw new AiFailure('access_denied', 403); }
            $this->assertReady();
            $result = $this->db->table(self::TABLE)->select('unit, role, permission')
                ->where('tenant_id', $tenantId)->orderBy('unit')->orderBy('role')->orderBy('permission')->get();
            if ($result === false) { throw new AiFailure('store_unavailable'); }
            $grants = array_values(array_filter($result->getResultArray(), static fn (array $row): bool =>
                in_array($row['role'], self::ROLES[$row['unit']] ?? [], true) && in_array($row['permission'], self::PERMISSIONS, true)));
            return ['ready' => $this->auditReady(), 'grants' => $grants];
        } catch (Throwable) {
            return ['ready' => false, 'grants' => []];
        }
    }

    public function grant(array $actor, string $unit, string $role, string $permission): void
    {
        $this->change($actor, $unit, $role, $permission, true);
    }

    public function revoke(array $actor, string $unit, string $role, string $permission): void
    {
        $this->change($actor, $unit, $role, $permission, false);
    }

    private function change(array $actor, string $unit, string $role, string $permission, bool $grant): void
    {
        AiAccess::assertActor($actor, (int) ($actor['tenant_id'] ?? 0), 'ai.configuration.write', 'main');
        if (!in_array($role, self::ROLES[$unit] ?? [], true) || !in_array($permission, self::PERMISSIONS, true)) {
            throw new AiFailure('invalid_request', 422);
        }
        $tenantId = (int) $actor['tenant_id'];
        // AuditService resolves its ledger tenant from this context; never silently audit another tenant.
        if (CurrentTenant::id() !== $tenantId) { throw new AiFailure('access_denied', 403); }
        $started = false;
        try {
            $this->assertReady();
            if ($this->db->transDepth !== 0) { throw new AiFailure('independent_transaction_required', 409); }
            if (!$this->auditReady()) { throw new AiFailure('store_unavailable'); }
            $this->db->resetTransStatus();
            if (($this->db->DBDriver === 'MySQLi' && !$this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'))
                || !$this->db->transBegin()) { throw new AiFailure('store_unavailable'); }
            $started = true;
            $audit = new AuditService(null, $this->db);
            // Match the ledger's lock order before any grant write or foreign-key check.
            $audit->lockTenant($tenantId);
            $scope = ['tenant_id' => $tenantId, 'unit' => $unit, 'role' => $role, 'permission' => $permission];
            $result = $this->db->table(self::TABLE)->where($scope)->get();
            if ($result === false) { throw new AiFailure('store_unavailable'); }
            $existed = $result->getRowArray() !== null;
            $changed = $grant !== $existed;
            if ($changed) {
                $builder = $this->db->table(self::TABLE);
                $ok = $grant ? $builder->insert($scope + ['created_by' => (int) $actor['id'], 'created_at' => gmdate('Y-m-d H:i:s')])
                    : $builder->where($scope)->delete();
                if (!$ok || $this->db->affectedRows() !== 1) { throw new AiFailure('store_unavailable'); }
            }
            $action = $grant ? 'grant' : 'revoke';
            $event = $audit->log('ai.access.' . $action, self::TABLE, $unit . ':' . $role . ':' . $permission, $action,
                ['unit' => $unit, 'role' => $role, 'permission' => $permission, 'changed' => $changed],
                (int) $actor['id'], $actor['role']);
            if (empty($event['id']) || (int) $event['tenant_id'] !== $tenantId
                || !$this->db->transStatus() || !$this->db->transCommit()) { throw new AiFailure('store_unavailable'); }
            $started = false;
        } catch (Throwable $error) {
            if ($started) {
                try { $this->db->transRollback(); }
                catch (Throwable) { /* Report only the controlled storage failure below. */ }
                $this->db->resetTransStatus();
            }
            if ($error instanceof AiFailure && $error->reason === 'independent_transaction_required') { throw $error; }
            throw new AiFailure('store_unavailable');
        }
    }

    /** Read-only preflight: old ledgers without v2 metadata cannot safely accept access changes. */
    private function auditReady(): bool
    {
        try {
            foreach (['tenants' => ['id'], 'audit_events' => ['id', 'tenant_id', 'event_type', 'entity_type', 'entity_id',
                'actor_user_id', 'actor_role', 'actor_ip', 'action', 'payload_json', 'prev_hash', 'curr_hash', 'hash_version', 'occurred_at']] as $table => $columns) {
                if (!$this->db->tableExists($table) || array_diff($columns, $this->db->getFieldNames($table)) !== []) { return false; }
            }
            if ($this->db->DBDriver === 'MySQLi') {
                // Rollback cannot protect mixed/nontransactional storage or views.
                foreach ([self::TABLE, 'audit_events', 'tenants'] as $table) {
                    $result = $this->db->query('SELECT ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                        [$this->db->prefixTable($table)]);
                    $rows = $result === false ? [] : $result->getResultArray();
                    if (count($rows) !== 1 || strtoupper($rows[0]['ENGINE'] ?? '') !== 'INNODB' || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE') { return false; }
                }
            }
            return true;
        } catch (Throwable) { return false; }
    }

    private function assertReady(): void
    {
        if (!$this->ready()) { throw new AiFailure('store_unavailable'); }
    }
}

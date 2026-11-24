<?php

declare(strict_types=1);

namespace App\Services\Archive;

use App\Services\Audit\AuditService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Configures and resolves institutional retention schedules (reversible soft-archive only).
 */
final class RetentionPolicyService
{
    private BaseConnection $db;
    private AuditService $audit;
    private ArchiveAccessPolicy $policy;

    public function __construct(
        ?BaseConnection $db = null,
        ?AuditService $audit = null,
        ?ArchiveAccessPolicy $policy = null
    ) {
        $this->db     = $db ?? Database::connect();
        $this->audit  = $audit ?? new AuditService(db: $this->db);
        $this->policy = $policy ?? new ArchiveAccessPolicy();
    }

    /** List tenant retention policies visible to the actor's allowed modules. */
    public function listPolicies(array $actor): array
    {
        if (!$this->db->tableExists('archive_retention_policies')) {
            return [];
        }

        $tenantId = (int) $actor['tenant_id'];
        $scope = $this->policy->allowedScope($actor);
        $modules = array_unique(array_merge(['all'], $scope['modules']));

        return $this->db->table('archive_retention_policies')
            ->where('tenant_id', $tenantId)
            ->whereIn('module', $modules)
            ->orderBy('is_active', 'DESC')
            ->orderBy('module', 'ASC')
            ->orderBy('policy_code', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** Resolve the most specific active retention policy for (module, entityType). */
    public function resolvePolicy(int $tenantId, string $module, string $entityType): ?array
    {
        if (!$this->db->tableExists('archive_retention_policies')) {
            return null;
        }

        $candidates = $this->db->table('archive_retention_policies')
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->whereIn('module', [$module, 'all'])
            ->whereIn('entity_type', [$entityType, '*'])
            ->get()
            ->getResultArray();

        $best = null;
        $bestScore = -1;
        foreach ($candidates as $row) {
            $score = 0;
            if ($row['module'] === $module) {
                $score += 2;
            }
            if ($row['entity_type'] === $entityType) {
                $score += 1;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return $best;
    }

    public function computeDueDate(?array $policy, string $archivedAtUtc): ?string
    {
        if ($policy === null) {
            return null;
        }
        $months = (int) ($policy['duration_months'] ?? 0);
        if ($months < 1) {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($archivedAtUtc, new DateTimeZone('UTC'));
            return $dt->modify('+' . $months . ' months')->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /** Create or update a retention policy; purge_enabled is unconditionally forced to 0. */
    public function savePolicy(?int $id, array $input): array
    {
        $actor = $this->policy->requireActor('archive.policies.manage');
        $tenantId = (int) $actor['tenant_id'];

        $code = strtoupper(trim((string) ($input['policy_code'] ?? '')));
        if (preg_match('/\A[A-Z0-9_-]{3,64}\z/D', $code) !== 1) {
            throw new InvalidArgumentException('Policy code must be 3-64 characters using A-Z, 0-9, underscore, or hyphen.', 422);
        }

        $module = strtolower(trim((string) ($input['module'] ?? '')));
        $allowedModules = array_merge(['all'], array_keys(ArchiveEntityRegistry::MODULES));
        if (!in_array($module, $allowedModules, true)) {
            throw new InvalidArgumentException('Select a valid module scope for the policy.', 422);
        }

        $entityType = trim((string) ($input['entity_type'] ?? '*'));
        if ($entityType !== '*' && !isset(ArchiveEntityRegistry::ENTITIES[$entityType])) {
            throw new InvalidArgumentException('Select a valid entity type or wildcard (*).', 422);
        }

        $title = $this->cleanText($input['title'] ?? '', 3, 255, 'Policy title');
        $legalBasis = $this->cleanText($input['legal_basis'] ?? '', 3, 255, 'Legal / institutional basis');

        $durationMonths = filter_var($input['duration_months'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 360],
        ]);
        if ($durationMonths === false) {
            throw new InvalidArgumentException('Retention duration must be between 1 and 360 months.', 422);
        }

        $rawAuto = trim((string) ($input['auto_archive_after_months'] ?? ''));
        $autoArchive = null;
        if ($rawAuto !== '') {
            $parsedAuto = filter_var($rawAuto, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 360],
            ]);
            if ($parsedAuto === false) {
                throw new InvalidArgumentException('Auto-archive recommendation window must be empty or between 1 and 360 months.', 422);
            }
            $autoArchive = (int) $parsedAuto;
        }

        $isActive = isset($input['is_active']) ? ((int) $input['is_active'] === 1 ? 1 : 0) : 1;

        return $this->inTransaction($tenantId, function () use (
            $tenantId,
            $actor,
            $id,
            $code,
            $module,
            $entityType,
            $title,
            $durationMonths,
            $legalBasis,
            $autoArchive,
            $isActive
        ): array {
            $now = gmdate('Y-m-d H:i:s');
            $duplicate = $this->db->table('archive_retention_policies')
                ->where('tenant_id', $tenantId)
                ->where('policy_code', $code);
            if ($id !== null) {
                $duplicate->where('id !=', $id);
            }
            if ($duplicate->countAllResults() > 0) {
                throw new RuntimeException('A retention policy with that code already exists.', 409);
            }

            $payload = [
                'policy_code'               => $code,
                'module'                    => $module,
                'entity_type'               => $entityType,
                'title'                     => $title,
                'duration_months'           => (int) $durationMonths,
                'legal_basis'               => $legalBasis,
                'auto_archive_after_months' => $autoArchive,
                'purge_enabled'             => 0, // Policy decision: reversible soft-archive only.
                'is_active'                 => $isActive,
                'updated_by_user_id'        => (int) $actor['id'],
                'updated_at'                => $now,
            ];

            if ($id === null) {
                $this->db->table('archive_retention_policies')->insert($payload + [
                    'tenant_id'          => $tenantId,
                    'created_by_user_id' => (int) $actor['id'],
                    'created_at'         => $now,
                ]);
                $savedId = (int) $this->db->insertID();
                $action = 'policy_created';
            } else {
                $existing = $this->db->table('archive_retention_policies')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $id)
                    ->get()
                    ->getRowArray();
                if ($existing === null) {
                    throw new RuntimeException('Retention policy not found.', 404);
                }
                $this->db->table('archive_retention_policies')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $id)
                    ->update($payload);
                $savedId = $id;
                $action = 'policy_updated';
            }

            $this->audit->log(
                'archive',
                'retention_policy',
                (string) $savedId,
                $action,
                [
                    'policy_code'     => $code,
                    'module'          => $module,
                    'entity_type'     => $entityType,
                    'duration_months' => (int) $durationMonths,
                    'is_active'       => $isActive,
                ],
                (int) $actor['id'],
                (string) $actor['role']
            );

            return $this->db->table('archive_retention_policies')
                ->where('tenant_id', $tenantId)
                ->where('id', $savedId)
                ->get()
                ->getRowArray();
        });
    }

    public function togglePolicy(int $id): array
    {
        $actor = $this->policy->requireActor('archive.policies.manage');
        $tenantId = (int) $actor['tenant_id'];

        return $this->inTransaction($tenantId, function () use ($tenantId, $actor, $id): array {
            $existing = $this->db->table('archive_retention_policies')
                ->where('tenant_id', $tenantId)
                ->where('id', $id)
                ->get()
                ->getRowArray();
            if ($existing === null) {
                throw new RuntimeException('Retention policy not found.', 404);
            }

            $nextActive = ((int) $existing['is_active'] === 1) ? 0 : 1;
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('archive_retention_policies')
                ->where('tenant_id', $tenantId)
                ->where('id', $id)
                ->update([
                    'is_active'          => $nextActive,
                    'purge_enabled'      => 0,
                    'updated_by_user_id' => (int) $actor['id'],
                    'updated_at'         => $now,
                ]);

            $this->audit->log(
                'archive',
                'retention_policy',
                (string) $id,
                $nextActive === 1 ? 'policy_activated' : 'policy_deactivated',
                ['policy_code' => $existing['policy_code'], 'is_active' => $nextActive],
                (int) $actor['id'],
                (string) $actor['role']
            );

            return $this->db->table('archive_retention_policies')
                ->where('tenant_id', $tenantId)
                ->where('id', $id)
                ->get()
                ->getRowArray();
        });
    }

    private function cleanText(mixed $raw, int $min, int $max, string $label): string
    {
        if (!is_string($raw)) {
            throw new InvalidArgumentException("{$label} is required.", 422);
        }
        $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $raw) ?? '');
        $len = mb_strlen($clean);
        if ($len < $min || $len > $max) {
            throw new InvalidArgumentException("{$label} must be between {$min} and {$max} characters.", 422);
        }
        return $clean;
    }

    private function inTransaction(int $tenantId, callable $fn): array
    {
        $owns = $this->db->transDepth === 0;
        if ($owns) {
            if ($this->db->DBDriver === 'MySQLi') {
                $this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }
            if (!$this->db->transBegin()) {
                throw new RuntimeException('Unable to start archive retention transaction.', 503);
            }
        }
        try {
            $this->audit->lockTenant($tenantId);
            $res = $fn();
            if ($owns && (!$this->db->transStatus() || !$this->db->transCommit())) {
                throw new RuntimeException('Unable to commit retention policy change.', 503);
            }
            return $res;
        } catch (Throwable $e) {
            if ($owns) {
                $this->db->transRollback();
            }
            throw $e;
        }
    }
}

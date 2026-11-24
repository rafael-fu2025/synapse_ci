<?php

declare(strict_types=1);

namespace App\Services\Archive;

use App\Libraries\LikePattern;
use App\Libraries\TablePage;
use App\Services\Audit\AuditService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Institutional Legal Hold governance: active holds block archiving across modules
 * and cross-check existing guidance_legal_holds records when present.
 */
final class LegalHoldService
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

    /** Check both archive_legal_holds and legacy guidance_legal_holds tables safely. */
    public function hasActiveHold(int $tenantId, string $entityType, int $entityId): bool
    {
        if ($entityId < 1) {
            return false;
        }

        if ($this->db->tableExists('archive_legal_holds')) {
            $count = $this->db->table('archive_legal_holds')
                ->where('tenant_id', $tenantId)
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('is_active', 1)
                ->countAllResults();
            if ($count > 0) {
                return true;
            }
        }

        $legacyTypes = $this->legacyHoldTypes($entityType);
        if ($legacyTypes !== [] && $this->db->tableExists('guidance_legal_holds')) {
            $count = $this->db->table('guidance_legal_holds')
                ->where('tenant_id', $tenantId)
                ->whereIn('entity_type', $legacyTypes)
                ->where('entity_id', $entityId)
                ->where('is_active', 1)
                ->countAllResults();
            if ($count > 0) {
                return true;
            }
        }

        return false;
    }

    /** Throws HTTP 409 if an active legal hold prevents archiving or disposition. */
    public function assertNotHeld(int $tenantId, string $entityType, int $entityId): void
    {
        if ($this->hasActiveHold($tenantId, $entityType, $entityId)) {
            throw new RuntimeException(
                'This record is under an active legal hold and cannot be archived until the hold is lifted.',
                409
            );
        }
    }

    /**
     * Batch map "entity_type:entity_id" => hold row for N+1-free UI badges.
     *
     * @param list<array{entity_type:string,entity_id:int}> $pairs
     * @return array<string, array<string, mixed>>
     */
    public function activeHoldKeys(int $tenantId, array $pairs): array
    {
        if ($pairs === [] || !$this->db->tableExists('archive_legal_holds')) {
            return [];
        }

        $entityIds = array_values(array_unique(array_map(
            static fn (array $p): int => (int) ($p['entity_id'] ?? 0),
            $pairs
        )));
        $entityIds = array_values(array_filter($entityIds, static fn (int $id): bool => $id > 0));
        if ($entityIds === []) {
            return [];
        }

        $rows = $this->db->table('archive_legal_holds')
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->whereIn('entity_id', $entityIds)
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['entity_type'] . ':' . (int) $row['entity_id']] = $row;
        }

        if ($this->db->tableExists('guidance_legal_holds')) {
            $legacyRows = $this->db->table('guidance_legal_holds')
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->whereIn('entity_id', $entityIds)
                ->get()
                ->getResultArray();
            foreach ($legacyRows as $row) {
                $canonical = $this->canonicalHoldType((string) $row['entity_type']);
                if ($canonical !== null && !isset($map[$canonical . ':' . (int) $row['entity_id']])) {
                    $map[$canonical . ':' . (int) $row['entity_id']] = $row;
                }
            }
        }

        return $map;
    }

    public function placeHold(string $entityType, int $entityId, string $reason, ?string $referenceCode = null): array
    {
        $def = ArchiveEntityRegistry::assertSupported($entityType);
        $actor = $this->policy->requireActor('archive.legal_hold.manage');
        $tenantId = (int) $actor['tenant_id'];

        $cleanReason = $this->cleanText($reason, 5, 1000, 'Legal hold reason');
        $cleanRef = $referenceCode !== null ? trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $referenceCode) ?? '') : '';
        if (mb_strlen($cleanRef) > 64) {
            throw new InvalidArgumentException('Reference code must be 64 characters or fewer.', 422);
        }

        return $this->inTransaction($tenantId, function () use ($tenantId, $actor, $def, $entityType, $entityId, $cleanReason, $cleanRef): array {
            $row = $this->db->table($def['table'])
                ->where('tenant_id', $tenantId)
                ->where('id', $entityId)
                ->get()
                ->getRowArray();
            if ($row === null) {
                throw new RuntimeException('Target record not found.', 404);
            }

            [$module, $unit] = ArchiveEntityRegistry::resolveModuleAndUnit($entityType, $row);
            $this->policy->assertModuleUnitAllowed($actor, $module, $unit);

            if ($this->hasActiveHold($tenantId, $entityType, $entityId)) {
                throw new RuntimeException('An active legal hold already exists on this record.', 409);
            }

            $now = gmdate('Y-m-d H:i:s');
            $recordRef = ArchiveEntityRegistry::safeRecordRef($entityType, $row);
            $this->db->table('archive_legal_holds')->insert([
                'tenant_id'         => $tenantId,
                'module'            => $module,
                'entity_type'       => $entityType,
                'entity_id'         => $entityId,
                'record_ref'        => $recordRef,
                'reason'            => $cleanReason,
                'reference_code'    => $cleanRef !== '' ? $cleanRef : null,
                'placed_by_user_id' => (int) $actor['id'],
                'placed_at'         => $now,
                'lifted_by_user_id' => null,
                'lifted_at'         => null,
                'lift_reason'       => null,
                'is_active'         => 1,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $holdId = (int) $this->db->insertID();

            $this->audit->log(
                'archive',
                'legal_hold',
                (string) $holdId,
                'hold_placed',
                [
                    'module'         => $module,
                    'entity_type'    => $entityType,
                    'entity_id'      => $entityId,
                    'record_ref'     => $recordRef,
                    'reference_code' => $cleanRef !== '' ? $cleanRef : null,
                ],
                (int) $actor['id'],
                (string) $actor['role']
            );

            return $this->db->table('archive_legal_holds')
                ->where('tenant_id', $tenantId)
                ->where('id', $holdId)
                ->get()
                ->getRowArray();
        });
    }

    public function liftHold(int $holdId, string $liftReason): array
    {
        $actor = $this->policy->requireActor('archive.legal_hold.manage');
        $tenantId = (int) $actor['tenant_id'];
        $cleanReason = $this->cleanText($liftReason, 5, 500, 'Lift hold reason');

        return $this->inTransaction($tenantId, function () use ($tenantId, $actor, $holdId, $cleanReason): array {
            $hold = $this->db->table('archive_legal_holds')
                ->where('tenant_id', $tenantId)
                ->where('id', $holdId)
                ->get()
                ->getRowArray();
            if ($hold === null) {
                throw new RuntimeException('Legal hold not found.', 404);
            }

            $unit = null;
            if ($this->db->tableExists(ArchiveEntityRegistry::ENTITIES[$hold['entity_type']]['table'] ?? '')) {
                $target = $this->db->table(ArchiveEntityRegistry::ENTITIES[$hold['entity_type']]['table'])
                    ->where('tenant_id', $tenantId)
                    ->where('id', (int) $hold['entity_id'])
                    ->get()
                    ->getRowArray();
                if ($target !== null) {
                    [, $unit] = ArchiveEntityRegistry::resolveModuleAndUnit((string) $hold['entity_type'], $target);
                }
            }
            $this->policy->assertModuleUnitAllowed($actor, (string) $hold['module'], $unit);

            if ((int) $hold['is_active'] !== 1) {
                throw new RuntimeException('This legal hold has already been lifted.', 409);
            }

            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('archive_legal_holds')
                ->where('tenant_id', $tenantId)
                ->where('id', $holdId)
                ->update([
                    'is_active'         => 0,
                    'lifted_by_user_id' => (int) $actor['id'],
                    'lifted_at'         => $now,
                    'lift_reason'       => $cleanReason,
                    'updated_at'        => $now,
                ]);

            $this->audit->log(
                'archive',
                'legal_hold',
                (string) $holdId,
                'hold_lifted',
                [
                    'module'      => $hold['module'],
                    'entity_type' => $hold['entity_type'],
                    'entity_id'   => (int) $hold['entity_id'],
                    'record_ref'  => $hold['record_ref'],
                ],
                (int) $actor['id'],
                (string) $actor['role']
            );

            return $this->db->table('archive_legal_holds')
                ->where('tenant_id', $tenantId)
                ->where('id', $holdId)
                ->get()
                ->getRowArray();
        });
    }

    public function listHolds(array $actor, array $filters = []): array
    {
        $page = TablePage::forList($filters, 'holds_page', 'per_page', 25);
        if (!$this->db->tableExists('archive_legal_holds')) {
            return ['items' => [], 'pagination' => $page->metadata(0, 0), 'filters' => []];
        }

        $tenantId = (int) $actor['tenant_id'];
        $status = (string) ($filters['status'] ?? 'active');
        if (!in_array($status, ['active', 'lifted', 'all'], true)) {
            $status = 'active';
        }
        $module = (string) ($filters['module'] ?? '');
        $q = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($filters['q'] ?? '')) ?? ''), 0, 120);

        $base = $this->db->table('archive_legal_holds h')
            ->where('h.tenant_id', $tenantId);
        // Reuse scope filter over archive_legal_holds: map allowed modules directly.
        $scope = $this->policy->allowedScope($actor);
        if ($scope['modules'] === []) {
            $base->where('1 = 0', null, false);
        } elseif (!in_array($actor['role'] ?? '', ['superadmin', 'audit_reader'], true)) {
            $nonTeams = array_values(array_diff($scope['modules'], ['teams']));
            $base->groupStart();
            if ($nonTeams !== []) {
                $base->whereIn('h.module', $nonTeams);
            }
            if (in_array('teams', $scope['modules'], true)) {
                $method = $nonTeams !== [] ? 'orGroupStart' : 'groupStart';
                $base->{$method}()
                    ->where('h.module', 'teams')
                    ->where('h.placed_by_user_id', (int) $actor['id'])
                    ->groupEnd();
            }
            $base->groupEnd();
        }
        $total = (clone $base)->countAllResults();

        if ($status === 'active') {
            $base->where('h.is_active', 1);
        } elseif ($status === 'lifted') {
            $base->where('h.is_active', 0);
        }
        if ($module !== '' && isset(ArchiveEntityRegistry::MODULES[$module])) {
            $base->where('h.module', $module);
        }
        if ($q !== '') {
            LikePattern::applyGroup($base, ['h.record_ref', 'h.reason', 'h.reference_code'], $q);
        }

        $filtered = (clone $base)->countAllResults();
        $pagination = $page->metadata($total, $filtered);

        $items = $base
            ->select('h.*, pu.username as placed_by_username, lu.username as lifted_by_username')
            ->join('users pu', 'pu.id = h.placed_by_user_id AND pu.tenant_id = h.tenant_id', 'left')
            ->join('users lu', 'lu.id = h.lifted_by_user_id AND lu.tenant_id = h.tenant_id', 'left')
            ->orderBy('h.is_active', 'DESC')
            ->orderBy('h.placed_at', 'DESC')
            ->orderBy('h.id', 'DESC')
            ->limit($page->perPage, ($pagination['page'] - 1) * $page->perPage)
            ->get()
            ->getResultArray();

        return [
            'items'      => $items,
            'pagination' => $pagination,
            'filters'    => ['status' => $status, 'module' => $module, 'q' => $q],
        ];
    }

    /** @return list<string> */
    private function legacyHoldTypes(string $entityType): array
    {
        return match ($entityType) {
            'counselling_case'       => ['counselling_case', 'case'],
            'guidance_episode'       => ['guidance_episode', 'episode'],
            'guidance_form_instance' => ['guidance_form_instance', 'form_instance'],
            default                  => [],
        };
    }

    private function canonicalHoldType(string $legacyType): ?string
    {
        return match ($legacyType) {
            'counselling_case', 'case'           => 'counselling_case',
            'guidance_episode', 'episode'        => 'guidance_episode',
            'guidance_form_instance', 'form_instance' => 'guidance_form_instance',
            default                              => null,
        };
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
                throw new RuntimeException('Unable to start legal hold transaction.', 503);
            }
        }
        try {
            $this->audit->lockTenant($tenantId);
            $res = $fn();
            if ($owns && (!$this->db->transStatus() || !$this->db->transCommit())) {
                throw new RuntimeException('Unable to commit legal hold update.', 503);
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

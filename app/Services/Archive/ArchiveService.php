<?php

declare(strict_types=1);

namespace App\Services\Archive;

use App\Libraries\LikePattern;
use App\Libraries\TablePage;
use App\Services\Audit\AuditService;
use App\Services\Carousel\CarouselAccessPolicy;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * System-wide reversible archive orchestrator across all campus modules.
 *
 * Enforces:
 * - Tenant-first locking + atomic fail-closed SHA-256 audit ledger entries
 * - Role & department scope isolation via ArchiveAccessPolicy
 * - Active legal-hold blocking across archive_legal_holds and guidance_legal_holds
 * - Business-state guards (active/open workflows cannot be archived prematurely)
 * - Strictly non-PHI record references in central archive governance tables
 */
final class ArchiveService
{
    private BaseConnection $db;
    private AuditService $audit;
    private ArchiveAccessPolicy $policy;
    private RetentionPolicyService $retention;
    private LegalHoldService $holds;

    public function __construct(
        ?BaseConnection $db = null,
        ?AuditService $audit = null,
        ?ArchiveAccessPolicy $policy = null,
        ?RetentionPolicyService $retention = null,
        ?LegalHoldService $holds = null
    ) {
        $this->db        = $db ?? Database::connect();
        $this->audit     = $audit ?? new AuditService(db: $this->db);
        $this->policy    = $policy ?? new ArchiveAccessPolicy();
        $this->retention = $retention ?? new RetentionPolicyService($this->db, $this->audit, $this->policy);
        $this->holds     = $holds ?? new LegalHoldService($this->db, $this->audit, $this->policy);
    }

    /**
     * Archive a record across any registered module after validating scope, legal holds, and business state.
     *
     * @param array<string, mixed> $options
     */
    public function archive(string $entityType, int $entityId, string $reason, array $options = []): array
    {
        $def = ArchiveEntityRegistry::assertSupported($entityType);
        $actor = $this->policy->requireActor('archive.manage');
        $tenantId = (int) $actor['tenant_id'];
        if ($entityId < 1) {
            throw new InvalidArgumentException('Invalid target record ID.', 422);
        }
        $cleanReason = $this->cleanReason($reason, 5, 500, 'Archive reason');

        return $this->inTransaction($tenantId, function () use ($tenantId, $actor, $def, $entityType, $entityId, $cleanReason, $options): array {
            $row = $this->db->table($def['table'])
                ->where('tenant_id', $tenantId)
                ->where('id', $entityId)
                ->get()
                ->getRowArray();
            if ($row === null) {
                throw new RuntimeException(ArchiveEntityRegistry::label($entityType) . ' not found.', 404);
            }

            [$module, $unit] = ArchiveEntityRegistry::resolveModuleAndUnit($entityType, $row);
            $this->policy->assertModuleUnitAllowed($actor, $module, $unit);

            $this->holds->assertNotHeld($tenantId, $entityType, $entityId);

            if (ArchiveEntityRegistry::isNativelyArchived($entityType, $row)) {
                throw new RuntimeException('This record is already archived.', 409);
            }

            $nowUtc = gmdate('Y-m-d H:i:s');
            $this->applyNativeArchive($entityType, $def['table'], $tenantId, $entityId, $row, $actor, $nowUtc, $options);

            $archivedRecord = $this->persistArchiveLedger(
                $tenantId,
                $module,
                $unit,
                $entityType,
                $entityId,
                $row,
                $cleanReason,
                (int) $actor['id'],
                $nowUtc
            );

            $this->audit->log(
                'archive',
                $entityType,
                (string) $entityId,
                'archived',
                [
                    'module'              => $module,
                    'unit'                => $unit,
                    'record_ref'          => $archivedRecord['record_ref'],
                    'retention_policy_id' => $archivedRecord['retention_policy_id'],
                    'retention_due_at'    => $archivedRecord['retention_due_at'],
                    'reason'              => $cleanReason,
                ],
                (int) $actor['id'],
                (string) $actor['role']
            );

            return $archivedRecord;
        });
    }

    /**
     * Restore a previously archived record into a safe non-live/operational state.
     *
     * @param array<string, mixed> $options
     */
    public function restore(string $entityType, int $entityId, string $reason, array $options = []): array
    {
        $def = ArchiveEntityRegistry::assertSupported($entityType);
        $actor = $this->policy->requireActor('archive.manage');
        $tenantId = (int) $actor['tenant_id'];
        if ($entityId < 1) {
            throw new InvalidArgumentException('Invalid target record ID.', 422);
        }
        $cleanReason = $this->cleanReason($reason, 3, 500, 'Restore reason');

        return $this->inTransaction($tenantId, function () use ($tenantId, $actor, $def, $entityType, $entityId, $cleanReason): array {
            $row = $this->db->table($def['table'])
                ->where('tenant_id', $tenantId)
                ->where('id', $entityId)
                ->get()
                ->getRowArray();
            if ($row === null) {
                throw new RuntimeException(ArchiveEntityRegistry::label($entityType) . ' not found.', 404);
            }

            [$module, $unit] = ArchiveEntityRegistry::resolveModuleAndUnit($entityType, $row);
            $this->policy->assertModuleUnitAllowed($actor, $module, $unit);

            if (!ArchiveEntityRegistry::isNativelyArchived($entityType, $row)) {
                throw new RuntimeException('This record is not currently archived.', 409);
            }

            $nowUtc = gmdate('Y-m-d H:i:s');
            $this->applyNativeRestore($entityType, $def['table'], $tenantId, $entityId, $row, $actor, $nowUtc);

            $restored = $this->persistRestoreLedger(
                $tenantId,
                $module,
                $unit,
                $entityType,
                $entityId,
                $row,
                $cleanReason,
                (int) $actor['id'],
                $nowUtc
            );

            $this->audit->log(
                'archive',
                $entityType,
                (string) $entityId,
                'restored',
                [
                    'module'     => $module,
                    'unit'       => $unit,
                    'record_ref' => $restored['record_ref'],
                    'reason'     => $cleanReason,
                ],
                (int) $actor['id'],
                (string) $actor['role']
            );

            return $restored;
        });
    }

    /**
     * Synchronization hook for existing module services (AnnouncementService, QuestionBankService, BmgRotationService, InventoryService)
     * that perform archiving inside their own existing transactions.
     */
    public function recordExternalArchive(
        int $tenantId,
        string $entityType,
        int $entityId,
        array $row,
        int $actorUserId,
        string $reason = 'Archived via module workflow'
    ): void {
        if (!$this->db->tableExists('archive_records')) {
            return;
        }
        $this->holds->assertNotHeld($tenantId, $entityType, $entityId);
        [$module, $unit] = ArchiveEntityRegistry::resolveModuleAndUnit($entityType, $row);
        $this->persistArchiveLedger(
            $tenantId,
            $module,
            $unit,
            $entityType,
            $entityId,
            $row,
            mb_substr(trim($reason) !== '' ? trim($reason) : 'Archived via module workflow', 0, 500),
            $actorUserId,
            gmdate('Y-m-d H:i:s')
        );
    }

    /** Synchronization hook when an existing module service restores a record inside its own transaction. */
    public function recordExternalRestore(
        int $tenantId,
        string $entityType,
        int $entityId,
        array $row,
        int $actorUserId,
        string $reason = 'Restored via module workflow'
    ): void {
        if (!$this->db->tableExists('archive_records')) {
            return;
        }
        [$module, $unit] = ArchiveEntityRegistry::resolveModuleAndUnit($entityType, $row);
        $this->persistRestoreLedger(
            $tenantId,
            $module,
            $unit,
            $entityType,
            $entityId,
            $row,
            mb_substr(trim($reason) !== '' ? trim($reason) : 'Restored via module workflow', 0, 500),
            $actorUserId,
            gmdate('Y-m-d H:i:s')
        );
    }

    /** Central Archive Center scoped listing & KPI metrics. */
    public function listing(array $filters = []): array
    {
        $actor = $this->policy->requireActor('archive.read');
        $scope = $this->policy->allowedScope($actor);
        $tenantId = (int) $actor['tenant_id'];
        $page = TablePage::forList($filters, 'page', 'per_page', 25);

        $state = (string) ($filters['state'] ?? 'archived');
        if (!in_array($state, ['archived', 'restored', 'all'], true)) {
            $state = 'archived';
        }
        $module = (string) ($filters['module'] ?? '');
        if ($module !== '' && !in_array($module, $scope['modules'], true)) {
            $module = '';
        }
        $allowedTypes = ArchiveEntityRegistry::allowedEntityTypes($scope['modules']);
        $entityType = (string) ($filters['entity_type'] ?? '');
        if ($entityType !== '' && !isset($allowedTypes[$entityType])) {
            $entityType = '';
        }
        $retentionFilter = (string) ($filters['retention'] ?? 'all');
        if (!in_array($retentionFilter, ['all', 'past_due', 'held'], true)) {
            $retentionFilter = 'all';
        }
        $q = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($filters['q'] ?? '')) ?? ''), 0, 120);

        $nowUtc = gmdate('Y-m-d H:i:s');
        $thirtyDaysAgo = gmdate('Y-m-d H:i:s', strtotime('-30 days'));

        if (!$this->db->tableExists('archive_records')) {
            return [
                'actor'         => $actor,
                'scope'         => $scope,
                'items'         => [],
                'pagination'    => $page->metadata(0, 0),
                'filters'       => compact('state', 'module', 'entityType', 'retentionFilter', 'q'),
                'allowedTypes'  => $allowedTypes,
                'kpis'          => ['archived_total' => 0, 'active_holds' => 0, 'past_retention_count' => 0, 'restored_30d' => 0, 'by_module' => []],
                'activeHoldMap' => [],
            ];
        }

        // Compute scoped KPIs
        $scopedArchived = $this->db->table('archive_records')->where('tenant_id', $tenantId)->where('state', 'archived');
        $this->policy->applyScopeFilter($scopedArchived, $actor);
        $archivedTotal = (clone $scopedArchived)->countAllResults();

        $pastRetentionCount = (clone $scopedArchived)
            ->where('retention_due_at IS NOT NULL', null, false)
            ->where('retention_due_at <=', $nowUtc)
            ->countAllResults();

        $scopedRestored = $this->db->table('archive_records')
            ->where('tenant_id', $tenantId)
            ->where('state', 'restored')
            ->where('restored_at >=', $thirtyDaysAgo);
        $this->policy->applyScopeFilter($scopedRestored, $actor);
        $restored30d = $scopedRestored->countAllResults();

        $byModuleRows = (clone $scopedArchived)->select('module, COUNT(*) as cnt', false)->groupBy('module')->get()->getResultArray();
        $byModule = [];
        foreach ($byModuleRows as $mr) {
            $byModule[(string) $mr['module']] = (int) $mr['cnt'];
        }

        $activeHoldsCount = 0;
        if ($this->db->tableExists('archive_legal_holds')) {
            $holdsBuilder = $this->db->table('archive_legal_holds')
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1);
            if (!in_array($actor['role'] ?? '', ['superadmin', 'audit_reader'], true)) {
                $nonTeams = array_values(array_diff($scope['modules'], ['teams']));
                $holdsBuilder->groupStart();
                if ($nonTeams !== []) {
                    $holdsBuilder->whereIn('module', $nonTeams);
                }
                if (in_array('teams', $scope['modules'], true)) {
                    $method = $nonTeams !== [] ? 'orGroupStart' : 'groupStart';
                    $holdsBuilder->{$method}()
                        ->where('module', 'teams')
                        ->where('placed_by_user_id', (int) $actor['id'])
                        ->groupEnd();
                }
                $holdsBuilder->groupEnd();
            }
            $activeHoldsCount = $holdsBuilder->countAllResults();
        }

        // Filtered query
        $base = $this->db->table('archive_records r')->where('r.tenant_id', $tenantId);
        $this->policy->applyScopeFilter($base, $actor, 'r');
        $total = (clone $base)->countAllResults();

        if ($state !== 'all') {
            $base->where('r.state', $state);
        }
        if ($module !== '') {
            $base->where('r.module', $module);
        }
        if ($entityType !== '') {
            $base->where('r.entity_type', $entityType);
        }
        if ($retentionFilter === 'past_due') {
            $base->where('r.state', 'archived')
                ->where('r.retention_due_at IS NOT NULL', null, false)
                ->where('r.retention_due_at <=', $nowUtc);
        } elseif ($retentionFilter === 'held' && $this->db->tableExists('archive_legal_holds')) {
            $heldRows = $this->db->table('archive_legal_holds')
                ->select('entity_type, entity_id')
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->get()
                ->getResultArray();
            if ($heldRows === []) {
                $base->where('1 = 0', null, false);
            } else {
                $base->groupStart();
                foreach ($heldRows as $idx => $hr) {
                    $m = $idx === 0 ? 'groupStart' : 'orGroupStart';
                    $base->{$m}()
                        ->where('r.entity_type', $hr['entity_type'])
                        ->where('r.entity_id', (int) $hr['entity_id'])
                        ->groupEnd();
                }
                $base->groupEnd();
            }
        }
        if ($q !== '') {
            LikePattern::applyGroup($base, ['r.record_ref', 'r.reason', 'r.restore_reason'], $q);
        }

        $filtered = (clone $base)->countAllResults();
        $pagination = $page->metadata($total, $filtered);

        $rows = $base
            ->select('r.*, au.username as archived_by_username, ru.username as restored_by_username, p.policy_code, p.title as policy_title')
            ->join('users au', 'au.id = r.archived_by_user_id AND au.tenant_id = r.tenant_id', 'left')
            ->join('users ru', 'ru.id = r.restored_by_user_id AND ru.tenant_id = r.tenant_id', 'left')
            ->join('archive_retention_policies p', 'p.id = r.retention_policy_id AND p.tenant_id = r.tenant_id', 'left')
            ->orderBy('r.archived_at', 'DESC')
            ->orderBy('r.id', 'DESC')
            ->limit($page->perPage, ($pagination['page'] - 1) * $page->perPage)
            ->get()
            ->getResultArray();

        $pairs = array_map(static fn (array $r): array => [
            'entity_type' => (string) $r['entity_type'],
            'entity_id'   => (int) $r['entity_id'],
        ], $rows);
        $activeHoldMap = $this->holds->activeHoldKeys($tenantId, $pairs);

        foreach ($rows as &$item) {
            $key = $item['entity_type'] . ':' . (int) $item['entity_id'];
            $item['has_active_hold'] = isset($activeHoldMap[$key]);
            $item['hold'] = $activeHoldMap[$key] ?? null;
            $item['past_retention'] = $item['state'] === 'archived'
                && !empty($item['retention_due_at'])
                && $item['retention_due_at'] <= $nowUtc;
            $item['entity_label'] = ArchiveEntityRegistry::label((string) $item['entity_type']);
            $item['module_label'] = ArchiveEntityRegistry::moduleLabel((string) $item['module']);
        }
        unset($item);

        return [
            'actor'        => $actor,
            'scope'        => $scope,
            'items'        => $rows,
            'pagination'   => $pagination,
            'allowedTypes' => $allowedTypes,
            'filters'      => [
                'state'       => $state,
                'module'      => $module,
                'entity_type' => $entityType,
                'retention'   => $retentionFilter,
                'q'           => $q,
                'per_page'    => $page->perPage,
            ],
            'kpis' => [
                'archived_total'       => $archivedTotal,
                'active_holds'         => $activeHoldsCount,
                'past_retention_count' => $pastRetentionCount,
                'restored_30d'         => $restored30d,
                'by_module'            => $byModule,
            ],
            'activeHoldMap' => $activeHoldMap,
        ];
    }

    /**
     * Bounded list of active/closed records eligible for archiving inside the actor's allowed scope.
     * Supports selecting a specific entity type or a curated sample across permitted entities.
     */
    public function eligibleListing(array $filters = []): array
    {
        $actor = $this->policy->requireActor('archive.read');
        $scope = $this->policy->allowedScope($actor);
        $tenantId = (int) $actor['tenant_id'];
        $allowedTypes = ArchiveEntityRegistry::allowedEntityTypes($scope['modules']);
        $selectedType = (string) ($filters['entity_type'] ?? '');
        if ($selectedType === '' || !isset($allowedTypes[$selectedType])) {
            $selectedType = (string) array_key_first($allowedTypes);
        }

        $q = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($filters['q'] ?? '')) ?? ''), 0, 120);
        if ($selectedType === '' || !isset(ArchiveEntityRegistry::ENTITIES[$selectedType])) {
            return ['items' => [], 'entityType' => '', 'allowedTypes' => $allowedTypes, 'q' => $q];
        }

        $def = ArchiveEntityRegistry::ENTITIES[$selectedType];
        $table = $def['table'];
        if (!$this->db->tableExists($table)) {
            return ['items' => [], 'entityType' => $selectedType, 'allowedTypes' => $allowedTypes, 'q' => $q];
        }

        $builder = $this->db->table($table)->where('tenant_id', $tenantId);
        // Apply native non-archived + eligible workflow state constraints
        switch ($selectedType) {
            case 'announcement':
                $builder->whereIn('status', ['draft', 'published']);
                if (!in_array($actor['role'] ?? '', ['superadmin', 'audit_reader'], true)) {
                    $builder->whereIn('unit', $scope['units']);
                }
                break;
            case 'question_bank':
                $builder->where('status', 'active');
                break;
            case 'clinic_medicine':
                $builder->where('is_active', 1);
                break;
            case 'survey':
                if ($this->db->fieldExists('deleted_at', 'surveys')) {
                    $builder->where('deleted_at', null);
                }
                $builder->whereIn('status', ['draft', 'closed']);
                break;
            case 'clinic_encounter':
                $builder->where('deleted_at', null)->whereIn('status', ['completed', 'referred']);
                break;
            case 'clinic_isolation':
                $builder->where('deleted_at', null)->where('clearance_status', 'cleared');
                break;
            case 'clinic_clearance':
                $builder->where('deleted_at', null);
                break;
            case 'counselling_case':
                $builder->where('deleted_at', null)->where('status', 'closed');
                break;
            case 'guidance_episode':
                $builder->where('deleted_at', null)->whereIn('status', ['closed', 'transferred']);
                break;
            case 'guidance_form_instance':
                if ($this->db->fieldExists('deleted_at', 'guidance_form_instances')) {
                    $builder->where('deleted_at', null);
                }
                $builder->whereIn('status', ['finalized', 'superseded']);
                break;
            case 'bmg_unit':
                $builder->where('deleted_at', null);
                break;
            case 'team_task':
                if ($this->db->fieldExists('deleted_at', 'team_tasks')) {
                    $builder->where('deleted_at', null);
                }
                $builder->where('status', 'done');
                if (!in_array($actor['role'] ?? '', ['superadmin', 'audit_reader'], true)) {
                    $builder->whereIn('unit', $scope['units']);
                }
                break;
            case 'team_event':
                if ($this->db->fieldExists('deleted_at', 'team_events')) {
                    $builder->where('deleted_at', null);
                }
                if (!in_array($actor['role'] ?? '', ['superadmin', 'audit_reader'], true)) {
                    $builder->whereIn('unit', $scope['units']);
                }
                $nowUtc = gmdate('Y-m-d H:i:s');
                $builder->groupStart()->where('status', 'cancelled')->orWhere('ends_at <', $nowUtc)->groupEnd();
                break;
            case 'carousel_slide':
                if ($this->db->fieldExists('deleted_at', 'carousel_slides')) {
                    $builder->where('deleted_at', null);
                }
                break;
        }

        if ($q !== '') {
            $searchCols = match ($selectedType) {
                'counselling_case'       => ['case_number'],
                'guidance_form_instance' => ['instance_number'],
                'bmg_unit'               => ['unit_code', 'name'],
                'clinic_medicine'        => ['name', 'generic_name'],
                'announcement', 'survey', 'team_task', 'team_event', 'carousel_slide' => ['title'],
                'clinic_clearance'       => ['school_year', 'semester'],
                default                  => ['id'],
            };
            if ($searchCols === ['id'] && ctype_digit($q)) {
                $builder->where('id', (int) $q);
            } elseif ($searchCols !== ['id']) {
                LikePattern::applyGroup($builder, $searchCols, $q);
            }
        }

        $rows = $builder->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();
        $pairs = array_map(static fn (array $r): array => ['entity_type' => $selectedType, 'entity_id' => (int) $r['id']], $rows);
        $holdMap = $this->holds->activeHoldKeys($tenantId, $pairs);

        $items = [];
        foreach ($rows as $row) {
            [$module, $unit] = ArchiveEntityRegistry::resolveModuleAndUnit($selectedType, $row);
            $id = (int) $row['id'];
            $key = $selectedType . ':' . $id;
            $status = (string) ($row['status'] ?? $row['clearance_status'] ?? ($row['is_active'] ?? 1 ? 'active' : 'archived'));
            $items[] = [
                'entity_type'     => $selectedType,
                'entity_label'    => $def['label'],
                'entity_id'       => $id,
                'module'          => $module,
                'module_label'    => ArchiveEntityRegistry::moduleLabel($module),
                'unit'            => $unit,
                'record_ref'      => ArchiveEntityRegistry::safeRecordRef($selectedType, $row),
                'status'          => $status,
                'updated_at'      => (string) ($row['updated_at'] ?? $row['created_at'] ?? ''),
                'has_active_hold' => isset($holdMap[$key]),
                'hold'            => $holdMap[$key] ?? null,
                'confidential'    => !empty($def['confidential']),
                'version'         => $row['version'] ?? null,
            ];
        }

        return [
            'items'        => $items,
            'entityType'   => $selectedType,
            'allowedTypes' => $allowedTypes,
            'q'            => $q,
        ];
    }

    private function applyNativeArchive(
        string $entityType,
        string $table,
        int $tenantId,
        int $entityId,
        array $row,
        array $actor,
        string $nowUtc,
        array $options
    ): void {
        switch ($entityType) {
            case 'announcement':
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'status'     => 'archived',
                    'version'    => (int) ($row['version'] ?? 1) + 1,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'question_bank':
                if (($row['status'] ?? '') !== 'active') {
                    throw new RuntimeException('Bank entry is already archived.', 409);
                }
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'status'     => 'archived',
                    'version'    => (int) ($row['version'] ?? 1) + 1,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'clinic_medicine':
                if ((int) ($row['is_active'] ?? 1) !== 1) {
                    throw new RuntimeException('Medicine is already archived.', 409);
                }
                $update = ['is_active' => 0, 'updated_at' => $nowUtc];
                if ($this->db->fieldExists('version', $table)) {
                    $update['version'] = (int) ($row['version'] ?? 1) + 1;
                }
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update($update);
                return;

            case 'survey':
                if (!in_array($row['status'] ?? '', ['draft', 'closed'], true)) {
                    throw new RuntimeException('Close this published survey before archiving it.', 409);
                }
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'clinic_encounter':
                if (!in_array($row['status'] ?? '', ['completed', 'referred'], true)) {
                    throw new RuntimeException('Complete or refer this encounter before archiving it.', 409);
                }
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'clinic_isolation':
                if (($row['clearance_status'] ?? '') !== 'cleared') {
                    throw new RuntimeException('Clear this isolation case before archiving it.', 409);
                }
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'clinic_clearance':
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'counselling_case':
                if (($row['status'] ?? '') !== 'closed') {
                    throw new RuntimeException('Close this counseling case before archiving it.', 409);
                }
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'guidance_episode':
                if (!in_array($row['status'] ?? '', ['closed', 'transferred'], true)) {
                    throw new RuntimeException('Close or transfer this episode before archiving it.', 409);
                }
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'guidance_form_instance':
                if (!in_array($row['status'] ?? '', ['finalized', 'superseded'], true)) {
                    throw new RuntimeException('Finalize or supersede this controlled form before archiving it.', 409);
                }
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'bmg_unit':
                $perms = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];
                if (($actor['role'] ?? '') !== 'superadmin' && !in_array('facilities.configure', $perms, true)) {
                    throw new RuntimeException('Facilities configuration access is required to archive a BMG drum.', 403);
                }
                if ($this->db->tableExists('bmg_batches')) {
                    $activeBatches = $this->db->table('bmg_batches')
                        ->where('tenant_id', $tenantId)
                        ->where('unit_id', $entityId)
                        ->whereIn('status', ['processing', 'awaiting_output', 'curing'])
                        ->countAllResults();
                    if ($activeBatches > 0) {
                        throw new RuntimeException('Units with active batches cannot be archived.', 409);
                    }
                }
                $manilaNow = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
                if ($this->db->tableExists('bmg_devices')) {
                    $this->db->table('bmg_devices')
                        ->where('tenant_id', $tenantId)
                        ->where('unit_id', $entityId)
                        ->update(['status' => 'revoked', 'unit_id' => null, 'revoked_at' => $manilaNow, 'updated_at' => $manilaNow]);
                }
                if ($this->db->tableExists('bmg_rotation_schedules')) {
                    $sched = $this->db->table('bmg_rotation_schedules')->where('tenant_id', $tenantId)->where('unit_id', $entityId)->get()->getRowArray();
                    if ($sched !== null) {
                        $this->db->table('bmg_rotation_schedules')
                            ->where('tenant_id', $tenantId)
                            ->where('unit_id', $entityId)
                            ->update([
                                'enabled'            => 0,
                                'version'            => (int) $sched['version'] + 1,
                                'updated_by_user_id' => (int) $actor['id'],
                                'updated_at'         => $manilaNow,
                            ]);
                    }
                }
                if ($this->db->tableExists('bmg_rotation_commands')) {
                    $this->db->table('bmg_rotation_commands')
                        ->where('tenant_id', $tenantId)
                        ->where('unit_id', $entityId)
                        ->where('status', 'pending')
                        ->update(['status' => 'cancelled', 'cancelled_at' => $manilaNow, 'updated_at' => $manilaNow]);
                }
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'status'     => 'offline',
                    'deleted_at' => $manilaNow,
                    'updated_at' => $manilaNow,
                ]);
                return;

            case 'team_task':
                if (($row['status'] ?? '') !== 'done') {
                    throw new RuntimeException('Complete this team task before archiving it.', 409);
                }
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => $nowUtc,
                    'version'    => (int) ($row['version'] ?? 1) + 1,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'team_event':
                $isCancelled = ($row['status'] ?? '') === 'cancelled';
                $endsAt = (string) ($row['ends_at'] ?? '');
                $isPast = $endsAt !== '' && $endsAt < $nowUtc;
                if (!$isCancelled && !$isPast) {
                    throw new RuntimeException('Only cancelled or past team calendar events can be archived.', 409);
                }
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => $nowUtc,
                    'version'    => (int) ($row['version'] ?? 1) + 1,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'carousel_slide':
                CarouselAccessPolicy::assertDeletable($actor, $row);
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'is_active'  => 0,
                    'deleted_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
                return;
        }
    }

    private function applyNativeRestore(
        string $entityType,
        string $table,
        int $tenantId,
        int $entityId,
        array $row,
        array $actor,
        string $nowUtc
    ): void {
        switch ($entityType) {
            case 'announcement':
                // Restored announcements always return to draft, never auto-publishing.
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'status'       => 'draft',
                    'published_at' => null,
                    'version'      => (int) ($row['version'] ?? 1) + 1,
                    'updated_at'   => $nowUtc,
                ]);
                return;

            case 'question_bank':
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'status'     => 'active',
                    'version'    => (int) ($row['version'] ?? 1) + 1,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'clinic_medicine':
                $update = ['is_active' => 1, 'updated_at' => $nowUtc];
                if ($this->db->fieldExists('version', $table)) {
                    $update['version'] = (int) ($row['version'] ?? 1) + 1;
                }
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update($update);
                return;

            case 'bmg_unit':
                $perms = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];
                if (($actor['role'] ?? '') !== 'superadmin' && !in_array('facilities.configure', $perms, true)) {
                    throw new RuntimeException('Facilities configuration access is required to restore a BMG drum.', 403);
                }
                $dup = $this->db->table($table)
                    ->where('tenant_id', $tenantId)
                    ->where('unit_code', $row['unit_code'])
                    ->where('id !=', $entityId)
                    ->countAllResults();
                if ($dup > 0) {
                    throw new RuntimeException('Unit code already exists.', 409);
                }
                $manilaNow = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => null,
                    'status'     => 'offline', // Always offline until physically inspected & re-bound
                    'updated_at' => $manilaNow,
                ]);
                return;

            case 'carousel_slide':
                // Keep hidden on restore until admin explicitly activates it.
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => null,
                    'is_active'  => 0,
                    'updated_at' => $nowUtc,
                ]);
                return;

            case 'team_task':
            case 'team_event':
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => null,
                    'version'    => (int) ($row['version'] ?? 1) + 1,
                    'updated_at' => $nowUtc,
                ]);
                return;

            default:
                $this->db->table($table)->where('tenant_id', $tenantId)->where('id', $entityId)->update([
                    'deleted_at' => null,
                    'updated_at' => $nowUtc,
                ]);
                return;
        }
    }

    private function persistArchiveLedger(
        int $tenantId,
        string $module,
        ?string $unit,
        string $entityType,
        int $entityId,
        array $row,
        string $reason,
        int $actorUserId,
        string $nowUtc
    ): array {
        $policy = $this->retention->resolvePolicy($tenantId, $module, $entityType);
        $dueAt = $this->retention->computeDueDate($policy, $nowUtc);
        $recordRef = ArchiveEntityRegistry::safeRecordRef($entityType, $row);
        $meta = ArchiveEntityRegistry::safeMetadata($entityType, $row);

        // Close any stale archived row on the same entity if one somehow exists before inserting new entry
        $this->db->table('archive_records')
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('state', 'archived')
            ->update([
                'state'              => 'restored',
                'restored_by_user_id' => $actorUserId,
                'restored_at'        => $nowUtc,
                'restore_reason'     => 'Superseded by new archive event',
                'updated_at'         => $nowUtc,
            ]);

        $this->db->table('archive_records')->insert([
            'tenant_id'           => $tenantId,
            'module'              => $module,
            'entity_type'         => $entityType,
            'entity_id'           => $entityId,
            'record_ref'          => $recordRef,
            'unit'                => $unit,
            'state'               => 'archived',
            'reason'              => $reason,
            'archived_by_user_id' => $actorUserId,
            'archived_at'         => $nowUtc,
            'restored_by_user_id' => null,
            'restored_at'         => null,
            'restore_reason'      => null,
            'retention_policy_id' => $policy['id'] ?? null,
            'retention_due_at'    => $dueAt,
            'metadata_json'       => json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at'          => $nowUtc,
            'updated_at'          => $nowUtc,
        ]);
        $id = (int) $this->db->insertID();

        return $this->db->table('archive_records')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->get()
            ->getRowArray();
    }

    private function persistRestoreLedger(
        int $tenantId,
        string $module,
        ?string $unit,
        string $entityType,
        int $entityId,
        array $row,
        string $reason,
        int $actorUserId,
        string $nowUtc
    ): array {
        $existing = $this->db->table('archive_records')
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('state', 'archived')
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        $recordRef = ArchiveEntityRegistry::safeRecordRef($entityType, $row);

        if ($existing !== null) {
            $this->db->table('archive_records')
                ->where('tenant_id', $tenantId)
                ->where('id', (int) $existing['id'])
                ->update([
                    'state'               => 'restored',
                    'restored_by_user_id' => $actorUserId,
                    'restored_at'         => $nowUtc,
                    'restore_reason'      => $reason,
                    'updated_at'          => $nowUtc,
                ]);
            $id = (int) $existing['id'];
        } else {
            $this->db->table('archive_records')->insert([
                'tenant_id'           => $tenantId,
                'module'              => $module,
                'entity_type'         => $entityType,
                'entity_id'           => $entityId,
                'record_ref'          => $recordRef,
                'unit'                => $unit,
                'state'               => 'restored',
                'reason'              => 'Archived prior to archive tracking',
                'archived_by_user_id' => $actorUserId,
                'archived_at'         => (string) ($row['deleted_at'] ?? $row['updated_at'] ?? $nowUtc),
                'restored_by_user_id' => $actorUserId,
                'restored_at'         => $nowUtc,
                'restore_reason'      => $reason,
                'retention_policy_id' => null,
                'retention_due_at'    => null,
                'metadata_json'       => json_encode(ArchiveEntityRegistry::safeMetadata($entityType, $row), JSON_THROW_ON_ERROR),
                'created_at'          => $nowUtc,
                'updated_at'          => $nowUtc,
            ]);
            $id = (int) $this->db->insertID();
        }

        return $this->db->table('archive_records')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->get()
            ->getRowArray();
    }

    private function cleanReason(mixed $raw, int $min, int $max, string $label): string
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
                throw new RuntimeException('Unable to start archive transaction.', 503);
            }
        }
        try {
            $this->audit->lockTenant($tenantId);
            $result = $fn();
            if ($owns && (!$this->db->transStatus() || !$this->db->transCommit())) {
                throw new RuntimeException('Unable to commit archive operation.', 503);
            }
            return $result;
        } catch (Throwable $e) {
            if ($owns) {
                $this->db->transRollback();
            }
            throw $e;
        }
    }
}

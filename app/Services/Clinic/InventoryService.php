<?php

declare(strict_types=1);

namespace App\Services\Clinic;

use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Medicine-only inventory. All stock writers must use the medicine row mutex. */
final class InventoryService
{
    private BaseConnection $db;
    private const MAX_STOCK = 4294967295;
    private ?bool $installed = null;
    public const SETUP_PENDING_MESSAGE = 'Clinic inventory setup is pending. Administrator must complete the inventory migration; no stock operations are available.';

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** Read-only, per-instance preflight; the history marker also certifies completed cutover. */
    public function isInstalled(): bool
    {
        if ($this->installed !== null) {
            return $this->installed;
        }
        $this->installed = false;
        try {
            // Shared connections may retain pre-migration table and field metadata.
            $this->db->resetDataCache();
            $history = config('Migrations')->table;
            $required = [
                'clinic_medicines' => ['id', 'tenant_id', 'name', 'generic_name', 'category', 'dosage_form', 'strength', 'unit_of_measure', 'minimum_stock_level', 'total_stock', 'is_active', 'version', 'created_at', 'updated_at'],
                'clinic_medicine_batches' => ['id', 'tenant_id', 'medicine_id', 'batch_number', 'expiration_date', 'initial_quantity', 'remaining_quantity', 'status', 'created_at', 'updated_at'],
                'clinic_dispensations' => ['id', 'tenant_id', 'encounter_id', 'medicine_id', 'batch_id', 'quantity', 'instructions', 'dispensed_by_user_id', 'created_at', 'operation_id', 'snapshot_name', 'snapshot_generic_name', 'snapshot_strength', 'snapshot_dosage_form', 'snapshot_unit', 'snapshot_batch_number', 'snapshot_expiration_date', 'snapshot_reconstructed'],
                'inventory_operations' => ['id', 'tenant_id', 'medicine_id', 'encounter_id', 'actor_id', 'idempotency_key', 'payload_hash', 'type', 'reason', 'supplier', 'reference', 'reversal_of', 'reversal_mode', 'created_at'],
                'stock_movements' => ['id', 'tenant_id', 'medicine_id', 'batch_id', 'operation_id', 'actor_id', 'delta', 'reason', 'original_movement_id', 'created_at'],
                $history => ['version', 'namespace'],
            ];
            foreach ($required as $table => $columns) {
                if (!$this->db->tableExists($table)
                    || array_diff($columns, $this->db->getFieldNames($table)) !== []) {
                    return false;
                }
            }
            $this->installed = $this->db->table($history)
                ->where('version', '2026-10-15-000002')->where('namespace', 'App')->countAllResults() > 0;
        } catch (Throwable $e) {
            // An unreadable or partially deployed schema must never enable stock operations.
            $this->installed = false;
        }
        return $this->installed;
    }

    public function assertInstalled(): void
    {
        if (!$this->isInstalled()) {
            throw new RuntimeException(self::SETUP_PENDING_MESSAGE, 503);
        }
    }

    /** No session permission cache: role, account state and grants are read afresh. */
    public function assertAccess(int $actorId, bool $write = false, bool $manager = false): void
    {
        $actor = $this->db->table('users')->where('id', $actorId)->where('tenant_id', CurrentTenant::id())->where('deleted_at', null)->get()->getRowArray();
        if (!$actor || $actor['status'] !== 'active') {
            throw new RuntimeException('Active inventory account required.', 403);
        }
        if ($actor['role'] === 'superadmin') {
            return;
        }
        if (!in_array($actor['role'], ['clinic_admin', 'clinic_staff'], true) || ($manager && $actor['role'] !== 'clinic_admin')) {
            throw new RuntimeException('Inventory role not permitted.', 403);
        }
        $required = ['clinic.inventory.read'];
        if ($write || $manager) {
            $required[] = 'clinic.inventory.write';
        }
        foreach ($required as $code) {
            $grant = $this->db->table('role_permissions rp')->join('permissions p', 'p.id=rp.permission_id')->where('rp.role', $actor['role'])->where('p.code', $code)->countAllResults();
            if (!$grant) {
                throw new RuntimeException('Missing permission: ' . $code, 403);
            }
        }
    }

    /** HTTP readers must call assertAccess before these tenant-scoped query methods. */
    public function listMedicines(string $query = '', string $status = 'all'): array
    {
        if (!in_array($status, ['all', 'active', 'archived', 'low_stock'], true)) {
            throw new InvalidArgumentException('Invalid medicine status.', 422);
        }
        $builder = $this->db->table('clinic_medicines')->where('tenant_id', CurrentTenant::id());
        if ($query !== '') {
            $builder->groupStart()->like('name', trim($query))->orLike('generic_name', trim($query))->groupEnd();
        }
        if ($status === 'active' || $status === 'archived') {
            $builder->where('is_active', $status === 'active' ? 1 : 0);
        }
        $rows = $builder->orderBy('name')->orderBy('id')->get()->getResultArray();
        $totals = $rows === [] ? [] : $this->stockTotals();
        foreach ($rows as &$row) {
            $row = $this->stockSummary($row, $totals[(int) $row['id']] ?? []);
        }
        unset($row);
        return $status === 'low_stock' ? array_values(array_filter($rows, fn ($row) => $row['low_stock'])) : $rows;
    }

    /** Three bounded read queries; alert counts cover the complete filtered catalog. */
    public function paginateMedicines(\App\Libraries\TablePage $page): array
    {
        $tenant = CurrentTenant::id();
        $total = $this->db->table('clinic_medicines')->where('tenant_id', $tenant)->countAllResults();
        $today = $this->db->escape(date('Y-m-d'));
        $near = $this->db->escape(date('Y-m-d', strtotime('+90 days')));
        $usable = "status='active' AND expiration_date >= {$today}";
        $batches = "SELECT tenant_id, medicine_id, SUM(remaining_quantity) AS physical_stock,
            SUM(CASE WHEN {$usable} THEN remaining_quantity ELSE 0 END) AS usable_stock,
            SUM(CASE WHEN expiration_date < {$today} THEN remaining_quantity ELSE 0 END) AS expired_stock,
            SUM(CASE WHEN status='quarantined' THEN remaining_quantity ELSE 0 END) AS quarantined_stock,
            SUM(CASE WHEN {$usable} AND expiration_date <= {$near} THEN remaining_quantity ELSE 0 END) AS near_expiry_stock
            FROM " . $this->t('clinic_medicine_batches') . ' WHERE tenant_id=? GROUP BY tenant_id, medicine_id';
        $catalog = 'SELECT m.*, COALESCE(b.physical_stock,0) AS batch_total_stock,
            CASE WHEN m.is_active=1 THEN COALESCE(b.usable_stock,0) ELSE 0 END AS usable_stock,
            COALESCE(b.expired_stock,0) AS expired_stock, COALESCE(b.quarantined_stock,0) AS quarantined_stock,
            CASE WHEN m.is_active=1 THEN COALESCE(b.near_expiry_stock,0) ELSE 0 END AS near_expiry_stock
            FROM ' . $this->t('clinic_medicines') . " m LEFT JOIN ({$batches}) b
            ON b.tenant_id=m.tenant_id AND b.medicine_id=m.id WHERE m.tenant_id=?";
        $params = [$tenant, $tenant];
        $where = ' WHERE 1=1';
        if ($page->q !== '') {
            $where .= " AND (name LIKE ? ESCAPE '!' OR generic_name LIKE ? ESCAPE '!')";
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $page->q) . '%';
            array_push($params, $term, $term);
        }
        if ($page->status === 'active' || $page->status === 'archived') {
            $where .= ' AND is_active=?';
            $params[] = $page->status === 'active' ? 1 : 0;
        } elseif ($page->status === 'low_stock') {
            $where .= ' AND usable_stock <= minimum_stock_level';
        }
        $source = " FROM ({$catalog}) catalog{$where}";
        $stats = $this->db->query('SELECT COUNT(*) AS filtered,
            COALESCE(SUM(CASE WHEN usable_stock <= minimum_stock_level THEN 1 ELSE 0 END),0) AS low,
            COALESCE(SUM(CASE WHEN expired_stock > 0 THEN 1 ELSE 0 END),0) AS expired,
            COALESCE(SUM(CASE WHEN quarantined_stock > 0 THEN 1 ELSE 0 END),0) AS quarantined,
            COALESCE(SUM(CASE WHEN near_expiry_stock > 0 THEN 1 ELSE 0 END),0) AS near' . $source, $params)->getRowArray();
        $pagination = $page->metadata($total, (int) $stats['filtered']);
        $offset = ($pagination['page'] - 1) * $page->perPage;
        $rows = $this->db->query('SELECT *' . $source . ' ORDER BY name ASC, id ASC LIMIT '
            . $page->perPage . ' OFFSET ' . $offset, $params)->getResultArray();
        foreach ($rows as &$row) {
            $totals = $row;
            $totals['total_stock'] = $row['batch_total_stock'];
            unset($row['batch_total_stock']);
            $row = $this->stockSummary($row, $totals);
        }
        unset($row);
        $counts = [];
        foreach (['low', 'expired', 'quarantined', 'near'] as $key) {
            $counts[$key] = (int) $stats[$key];
        }
        return [
            'medicines' => $rows, 'pagination' => $pagination, 'counts' => $counts,
            'filters' => ['q' => $page->q, 'status' => $page->status, 'per_page' => $page->perPage],
        ];
    }

    public function details(int $medicineId, int $movementPage = 1, int $movementPageSize = 100): array
    {
        if ($movementPage < 1 || $movementPage > 1000000) {
            throw new InvalidArgumentException('Invalid movement page.', 422);
        }
        if (!in_array($movementPageSize, [20, 25, 50, 100], true)) {
            throw new InvalidArgumentException('Invalid movement page size.', 422);
        }
        $medicine = $this->stockSummary($this->medicine($medicineId));
        $batches = $this->db->table('clinic_medicine_batches')->where('tenant_id', CurrentTenant::id())->where('medicine_id', $medicineId)->orderBy('expiration_date')->orderBy('id')->get()->getResultArray();
        $movementQuery = $this->db->table('stock_movements')->where('tenant_id', CurrentTenant::id())->where('medicine_id', $medicineId);
        $total = (clone $movementQuery)->countAllResults();
        $pages = max(1, (int) ceil($total / $movementPageSize));
        $movementPage = min($movementPage, $pages);
        $movements = $movementQuery->orderBy('id', 'DESC')->limit($movementPageSize, ($movementPage - 1) * $movementPageSize)->get()->getResultArray();
        $pagination = [
            'page' => $movementPage, 'per_page' => $movementPageSize, 'total' => $total, 'pages' => $pages,
            'from' => $total === 0 ? 0 : ($movementPage - 1) * $movementPageSize + 1,
            'to' => min($movementPage * $movementPageSize, $total),
        ];
        $operations = $this->db->query('SELECT o.*, r.id AS reversed_by_operation_id, r.reversal_mode AS correction_mode FROM ' . $this->t('inventory_operations') . ' o LEFT JOIN ' . $this->t('inventory_operations') . ' r ON r.reversal_of=o.id AND r.tenant_id=o.tenant_id WHERE o.tenant_id=? AND o.medicine_id=? ORDER BY o.id DESC LIMIT 100', [CurrentTenant::id(), $medicineId])->getResultArray();
        return ['medicine' => $medicine, 'batches' => $batches, 'movements' => $movements, 'operations' => $operations, 'movement_page' => $movementPage, 'movement_page_size' => $movementPageSize, 'movementPagination' => $pagination];
    }

    /** HTTP creates must supply a key; null is reserved for trusted internal catalog setup. Updates use version checks. */
    public function saveMedicine(?int $id, array $data, int $actorId, ?string $key = null): array
    {
        if ($key !== null) {
            $this->validateKey($key);
        }
        $this->assertAccess($actorId, true, true);
        $this->only($data, ['name','generic_name','category','dosage_form','strength','unit_of_measure','minimum_stock_level','is_active','version']);
        $fields = [];
        foreach (['name'=>150, 'generic_name'=>150, 'category'=>100, 'dosage_form'=>50, 'strength'=>50, 'unit_of_measure'=>20] as $field => $length) {
            if (array_key_exists($field, $data) || $id === null) {
                $nullable = in_array($field, ['generic_name','strength'], true);
                $value = $data[$field] ?? null;
                $fields[$field] = $this->text($nullable ? $this->normalizeNullableIdentity($value) : $value, $field, $length, $nullable);
            }
        }
        if (array_key_exists('minimum_stock_level', $data) || $id === null) {
            $fields['minimum_stock_level'] = $this->integer($data['minimum_stock_level'] ?? 0, 'minimum_stock_level', 0, 1000000);
        }
        if (array_key_exists('is_active', $data)) {
            $fields['is_active'] = $this->integer($data['is_active'], 'is_active', 0, 1);
        }
        if ($id === null) {
            $fields['is_active'] ??= 1;
        }
        $version = $id === null ? 1 : $this->integer($data['version'] ?? null, 'version', 1, self::MAX_STOCK - 1);
        $hash = $id === null && $key !== null
            ? hash('sha256', json_encode(['type'=>'catalog', 'actor_id'=>$actorId, 'data'=>$fields], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null;
        return $this->transaction(function () use ($id, $fields, $version, $actorId, $key, $hash) {
            $this->assertAccess($actorId, true, true);
            $now = date('Y-m-d H:i:s');
            if ($id === null) {
                if ($key !== null) {
                    $original = $this->replayOperation($key, $hash, $actorId);
                    if ($original !== null) {
                        if ($original['type'] !== 'catalog') {
                            throw new RuntimeException('Idempotency key already used for a different operation type.', 409);
                        }
                        return $this->medicine((int) $original['medicine_id']);
                    }
                }
                $newId = $this->insert('clinic_medicines', $fields + ['tenant_id'=>CurrentTenant::id(), 'total_stock'=>0, 'version'=>1, 'created_at'=>$now, 'updated_at'=>$now]);
                if ($key !== null) {
                    // The tenant-wide unique key arbitrates concurrent creates; any losing medicine insert rolls back.
                    $this->insert('inventory_operations', [
                        'tenant_id'=>CurrentTenant::id(), 'medicine_id'=>$newId, 'actor_id'=>$actorId,
                        'idempotency_key'=>$key, 'payload_hash'=>$hash, 'type'=>'catalog',
                        'reason'=>'catalog', 'created_at'=>$now,
                    ]);
                }
                return $this->medicine($newId);
            }
            $medicine = $this->medicine($id, true);
            $this->verifyStock($medicine);
            if ((int) $medicine['version'] !== $version) {
                throw new RuntimeException('Medicine was changed; reload before saving.', 409);
            }
            $hasHistory = $this->db->table('clinic_medicine_batches')->where('tenant_id', CurrentTenant::id())->where('medicine_id', $id)->countAllResults() > 0;
            if ($hasHistory) {
                foreach (['generic_name','dosage_form','strength','unit_of_measure'] as $field) {
                    $stored = in_array($field, ['generic_name','strength'], true)
                        ? $this->normalizeNullableIdentity($medicine[$field]) : $medicine[$field];
                    if (array_key_exists($field, $fields) && $fields[$field] !== $stored) {
                        throw new RuntimeException('Medicine identity and unit are immutable once batches exist; create a new catalog entry.', 409);
                    }
                }
            }
            $ok = $this->db->table('clinic_medicines')->where('tenant_id', CurrentTenant::id())->where('id', $id)->where('version', $version)->update($fields + ['version'=>$version + 1, 'updated_at'=>$now]);
            $this->affected($ok);
            return $this->medicine($id);
        });
    }

    public function receive(int $medicineId, array $data, int $actorId, string $key): array
    {
        $this->only($data, ['batch_number','expiration_date','quantity','supplier','reference']);
        $payload = [
            'batch_number'=>$this->text($data['batch_number'] ?? null, 'batch_number', 50),
            'expiration_date'=>$this->date($data['expiration_date'] ?? null),
            'quantity'=>$this->integer($data['quantity'] ?? null, 'quantity', 1, 1000000),
            'supplier'=>$this->text($data['supplier'] ?? null, 'supplier', 150, true),
            'reference'=>$this->text($data['reference'] ?? null, 'reference', 150, true),
        ];
        return $this->operate('receipt', $medicineId, null, $payload, $actorId, $key, false, function ($medicine, $op) use ($payload) {
            $this->active($medicine);
            if ($payload['expiration_date'] < date('Y-m-d')) {
                throw new InvalidArgumentException('Cannot receive an expired lot.', 422);
            }
            $batch = $this->db->table('clinic_medicine_batches')->where('tenant_id', CurrentTenant::id())->where('medicine_id', $medicine['id'])->where('batch_number', $payload['batch_number'])->get()->getRowArray();
            $qty = $payload['quantity'];
            if ($batch) {
                if ($batch['expiration_date'] !== $payload['expiration_date'] || $batch['status'] !== 'active') {
                    throw new RuntimeException('Lot already exists with a different expiry or is quarantined.', 409);
                }
                if ((int) $batch['initial_quantity'] + $qty > self::MAX_STOCK) {
                    throw new RuntimeException('Lot receipt quantity exceeds storage range.', 409);
                }
                $ok = $this->db->query('UPDATE ' . $this->t('clinic_medicine_batches') . ' SET initial_quantity=initial_quantity+?, remaining_quantity=remaining_quantity+?, updated_at=? WHERE tenant_id=? AND id=?', [$qty,$qty,date('Y-m-d H:i:s'),CurrentTenant::id(),$batch['id']]);
                $this->affected($ok);
                $batchId = (int) $batch['id'];
            } else {
                $batchId = $this->insert('clinic_medicine_batches', ['tenant_id'=>CurrentTenant::id(), 'medicine_id'=>$medicine['id'], 'batch_number'=>$payload['batch_number'], 'expiration_date'=>$payload['expiration_date'], 'initial_quantity'=>$qty, 'remaining_quantity'=>$qty, 'status'=>'active', 'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s')]);
            }
            $this->movement($op, $batchId, $qty, 'receipt');
        });
    }

    public function dispense(int $encounterId, int $medicineId, mixed $quantity, ?string $instructions, int $actorId, string $key): array
    {
        $payload = ['quantity'=>$this->integer($quantity, 'quantity', 1, 1000000), 'instructions'=>$this->text($instructions, 'instructions', 255, true)];
        return $this->operate('dispense', $medicineId, $encounterId, $payload, $actorId, $key, false, function ($medicine, $op, $encounter) use ($payload) {
            $this->active($medicine);
            if ($encounter['status'] !== 'in_progress' || $encounter['deleted_at'] !== null) {
                throw new RuntimeException('Encounter must be open.', 409);
            }
            $patient = $this->db->query('SELECT id FROM ' . $this->t('people') . ' WHERE id=? AND tenant_id=? AND deleted_at IS NULL LOCK IN SHARE MODE', [$encounter['person_id'],CurrentTenant::id()])->getRowArray();
            if (!$patient) {
                throw new RuntimeException('Encounter patient unavailable.', 409);
            }
            $batches = $this->db->table('clinic_medicine_batches')->where('tenant_id', CurrentTenant::id())->where('medicine_id', $medicine['id'])->where('status', 'active')->where('expiration_date >=', date('Y-m-d'))->where('remaining_quantity >', 0)->orderBy('expiration_date')->orderBy('id')->get()->getResultArray();
            if (array_sum(array_column($batches, 'remaining_quantity')) < $payload['quantity']) {
                throw new RuntimeException('Insufficient usable stock.', 409);
            }
            $left = $payload['quantity'];
            foreach ($batches as $batch) {
                if ($left === 0) {
                    break;
                }
                $take = min($left, (int) $batch['remaining_quantity']);
                $this->changeBatch((int) $batch['id'], -$take);
                $this->insert('clinic_dispensations', [
                    'tenant_id'=>CurrentTenant::id(), 'encounter_id'=>$op['encounter_id'], 'medicine_id'=>$medicine['id'], 'batch_id'=>$batch['id'], 'quantity'=>$take,
                    'instructions'=>$payload['instructions'], 'dispensed_by_user_id'=>$op['actor_id'], 'operation_id'=>$op['id'],
                    'snapshot_name'=>$medicine['name'], 'snapshot_generic_name'=>$medicine['generic_name'], 'snapshot_strength'=>$medicine['strength'],
                    'snapshot_dosage_form'=>$medicine['dosage_form'], 'snapshot_unit'=>$medicine['unit_of_measure'],
                    'snapshot_batch_number'=>$batch['batch_number'], 'snapshot_expiration_date'=>$batch['expiration_date'], 'snapshot_reconstructed'=>0, 'created_at'=>date('Y-m-d H:i:s'),
                ]);
                $this->movement($op, (int) $batch['id'], -$take, 'dispense');
                $left -= $take;
            }
        });
    }

    public function adjust(int $batchId, array $data, int $actorId, string $key): array
    {
        $this->assertAccess($actorId, true, true);
        $this->only($data, ['quantity','reason','note']);
        $reason = $this->text($data['reason'] ?? null, 'reason', 50);
        if (!in_array($reason, ['damaged','expired','count_correction'], true)) {
            throw new InvalidArgumentException('Invalid removal reason.', 422);
        }
        $payload = ['batch_id'=>$batchId, 'quantity'=>$this->integer($data['quantity'] ?? null, 'quantity', 1, 1000000), 'reason'=>$reason, 'note'=>$this->text($data['note'] ?? null, 'note', 900)];
        $batch = $this->batch($batchId);
        return $this->operate('adjustment', (int) $batch['medicine_id'], null, $payload, $actorId, $key, true, function ($medicine, $op) use ($payload) {
            $this->changeBatch($payload['batch_id'], -$payload['quantity']);
            $this->movement($op, $payload['batch_id'], -$payload['quantity'], $payload['reason'] . ': ' . $payload['note']);
        });
    }

    public function setBatchStatus(int $batchId, string $status, string $reason, int $actorId, string $key): array
    {
        $this->assertAccess($actorId, true, true);
        if (!in_array($status, ['active','quarantined'], true)) {
            throw new InvalidArgumentException('Invalid batch status.', 422);
        }
        $payload = ['batch_id'=>$batchId, 'status'=>$status, 'reason'=>$this->text($reason, 'reason', 1000)];
        $batch = $this->batch($batchId);
        return $this->operate('quarantine', (int) $batch['medicine_id'], null, $payload, $actorId, $key, true, function ($medicine, $op) use ($payload) {
            $batch = $this->batch($payload['batch_id']);
            if ($batch['status'] === $payload['status']) {
                throw new RuntimeException('Batch already has requested status.', 409);
            }
            if ($payload['status'] === 'active' && $batch['expiration_date'] < date('Y-m-d')) {
                throw new RuntimeException('Expired batches cannot be released.', 409);
            }
            $this->affected($this->db->table('clinic_medicine_batches')->where('tenant_id', CurrentTenant::id())->where('id', $batch['id'])->update(['status'=>$payload['status'], 'updated_at'=>date('Y-m-d H:i:s')]));
            $this->movement($op, (int) $batch['id'], 0, $payload['reason']);
        });
    }

    /** restore_unissued explicitly affirms stock never left clinic custody; never use for patient returns. */
    public function reverse(int $operationId, string $mode, string $reason, int $actorId, string $key): array
    {
        $this->assertAccess($actorId, true, true);
        if (!in_array($mode, ['record_only','restore_unissued'], true)) {
            throw new InvalidArgumentException('Choose record_only or affirm never-issued clinic custody with restore_unissued.', 422);
        }
        $original = $this->db->table('inventory_operations')->where('tenant_id', CurrentTenant::id())->where('id', $operationId)->get()->getRowArray();
        if (!$original || $original['type'] !== 'dispense') {
            throw new InvalidArgumentException('Only a complete ledger dispensation can be reversed.', 422);
        }
        $payload = ['operation_id'=>$operationId, 'mode'=>$mode, 'reason'=>$this->text($reason, 'reason', 1000)];
        return $this->operate('reversal', (int) $original['medicine_id'], (int) $original['encounter_id'], $payload, $actorId, $key, true, function ($medicine, $op) use ($original, $payload) {
            if ($payload['mode'] === 'restore_unissued') {
                $this->active($medicine);
            }
            $movements = $this->db->table('stock_movements')->where('tenant_id', CurrentTenant::id())->where('operation_id', $original['id'])->orderBy('batch_id')->get()->getResultArray();
            if (!$movements) {
                throw new RuntimeException('Original allocation ledger missing.', 409);
            }
            foreach ($movements as $movement) {
                $delta = 0;
                if ($payload['mode'] === 'restore_unissued') {
                    $batch = $this->batch((int) $movement['batch_id']);
                    if ($batch['status'] !== 'active' || $batch['expiration_date'] < date('Y-m-d')) {
                        throw new RuntimeException('Unissued stock can only be restored to active, unexpired batches.', 409);
                    }
                    $delta = -(int) $movement['delta'];
                    $this->changeBatch((int) $batch['id'], $delta);
                }
                $this->movement($op, (int) $movement['batch_id'], $delta, $payload['reason'], (int) $movement['id']);
            }
        });
    }

    private function operate(string $type, int $medicineId, ?int $encounterId, array $payload, int $actorId, string $key, bool $manager, callable $write): array
    {
        if ($this->db->transDepth !== 0) {
            throw new RuntimeException('Inventory operations cannot nest transactions.');
        }
        $this->assertAccess($actorId, true, $manager);
        $this->validateKey($key);
        $canonical = ['type'=>$type, 'medicine_id'=>$medicineId, 'encounter_id'=>$encounterId, 'actor_id'=>$actorId, 'data'=>$payload];
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $this->transaction(function () use ($type, $medicineId, $encounterId, $payload, $actorId, $key, $manager, $hash, $write) {
            // Global lock order: encounter (when applicable), medicine, then batches in FEFO/id order.
            $encounter = $encounterId === null ? null : $this->db->query('SELECT * FROM ' . $this->t('clinic_encounters') . ' WHERE tenant_id=? AND id=? FOR UPDATE', [CurrentTenant::id(),$encounterId])->getRowArray();
            if ($encounterId !== null && !$encounter) {
                throw new RuntimeException('Encounter not found.', 404);
            }
            $medicine = $this->medicine($medicineId, true);
            $this->assertAccess($actorId, true, $manager);
            // Replay precedes lifecycle checks: a retry after completion/archive remains a retry.
            $existing = $this->replay($key, $hash, $actorId);
            if ($existing !== null) {
                return $existing;
            }
            $this->verifyStock($medicine);
            if ($type === 'reversal' && $this->db->table('inventory_operations')->where('tenant_id', CurrentTenant::id())->where('reversal_of', $payload['operation_id'])->countAllResults()) {
                throw new RuntimeException('Dispensation already corrected.', 409);
            }
            $op = ['tenant_id'=>CurrentTenant::id(), 'medicine_id'=>$medicineId, 'encounter_id'=>$encounterId, 'actor_id'=>$actorId, 'idempotency_key'=>$key, 'payload_hash'=>$hash, 'type'=>$type,
                'reason'=>$payload['reason'] ?? $type,
                'supplier'=>$payload['supplier'] ?? null, 'reference'=>$payload['reference'] ?? null,
                'reversal_of'=>$type === 'reversal' ? $payload['operation_id'] : null, 'reversal_mode'=>$type === 'reversal' ? $payload['mode'] : null, 'created_at'=>date('Y-m-d H:i:s')];
            if ($type === 'adjustment') {
                $op['reason'] = $payload['reason'] . ': ' . $payload['note'];
            }
            $op['id'] = $this->insert('inventory_operations', $op);
            $write($medicine, $op, $encounter);
            $physical = $this->physical($medicineId);
            if ($physical < 0 || $physical > self::MAX_STOCK) {
                throw new RuntimeException('Physical stock exceeds storage range.', 409);
            }
            // A header version also invalidates stale catalog forms after inventory writes.
            if ((int) $medicine['version'] >= self::MAX_STOCK) {
                throw new RuntimeException('Medicine version exhausted.', 409);
            }
            $this->affected($this->db->table('clinic_medicines')->where('tenant_id', CurrentTenant::id())->where('id', $medicineId)->update(['total_stock'=>$physical, 'version'=>(int) $medicine['version'] + 1, 'updated_at'=>date('Y-m-d H:i:s')]));
            $this->verifyStock($this->medicine($medicineId));
            return $this->result($op, false);
        });
    }

    private function replay(string $key, string $hash, int $actorId): ?array
    {
        $op = $this->replayOperation($key, $hash, $actorId);
        return $op === null ? null : $this->result($op, true);
    }

    private function replayOperation(string $key, string $hash, int $actorId): ?array
    {
        $op = $this->db->table('inventory_operations')->where('tenant_id', CurrentTenant::id())->where('idempotency_key', $key)->get()->getRowArray();
        if (!$op) {
            return null;
        }
        if ((int) $op['actor_id'] !== $actorId || !hash_equals($op['payload_hash'], $hash)) {
            throw new RuntimeException('Idempotency key already used for a different payload or actor.', 409);
        }
        return $op;
    }

    private function result(array $op, bool $replayed): array
    {
        return ['operation'=>$op,
            'allocations'=>$this->db->table('clinic_dispensations')->where('tenant_id', CurrentTenant::id())->where('operation_id', $op['id'])->orderBy('id')->get()->getResultArray(),
            'movements'=>$this->db->table('stock_movements')->where('tenant_id', CurrentTenant::id())->where('operation_id', $op['id'])->orderBy('id')->get()->getResultArray(),
            'replayed'=>$replayed];
    }

    private function transaction(callable $work): array
    {
        // Do not silently nest CI transactions: an outer rollback would invalidate a reported success.
        if ($this->db->DBDriver !== 'MySQLi' || $this->db->transDepth !== 0) {
            throw new RuntimeException('Inventory requires its own MySQL/InnoDB transaction.');
        }
        $this->db->resetTransStatus();
        if ($this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || !$this->db->transBegin()) {
            throw new RuntimeException('Could not begin inventory transaction.');
        }
        try {
            $result = $work();
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Inventory transaction failed.');
            }
            return $result;
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
            throw $e;
        }
    }

    private function medicine(int $id, bool $lock = false): array
    {
        $row = $this->db->query('SELECT * FROM ' . $this->t('clinic_medicines') . ' WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [CurrentTenant::id(),$id])->getRowArray();
        if (!$row) {
            throw new RuntimeException('Medicine not found.', 404);
        }
        return $row;
    }

    private function batch(int $id): array
    {
        $row = $this->db->table('clinic_medicine_batches')->where('tenant_id', CurrentTenant::id())->where('id', $id)->get()->getRowArray();
        if (!$row) {
            throw new RuntimeException('Batch not found.', 404);
        }
        return $row;
    }

    /** One grouped batch query for a tenant's entire catalog, or one medicine's details. */
    private function stockTotals(?int $medicineId = null): array
    {
        $today = $this->db->escape(date('Y-m-d'));
        $near = $this->db->escape(date('Y-m-d', strtotime('+90 days')));
        $usable = "status='active' AND expiration_date >= {$today}";
        $builder = $this->db->table('clinic_medicine_batches')
            ->select("medicine_id, SUM(remaining_quantity) AS total_stock,
                SUM(CASE WHEN {$usable} THEN remaining_quantity ELSE 0 END) AS usable_stock,
                SUM(CASE WHEN expiration_date < {$today} THEN remaining_quantity ELSE 0 END) AS expired_stock,
                SUM(CASE WHEN status='quarantined' THEN remaining_quantity ELSE 0 END) AS quarantined_stock,
                SUM(CASE WHEN {$usable} AND expiration_date <= {$near} THEN remaining_quantity ELSE 0 END) AS near_expiry_stock", false)
            ->where('tenant_id', CurrentTenant::id());
        if ($medicineId !== null) {
            $builder->where('medicine_id', $medicineId);
        }
        $totals = [];
        foreach ($builder->groupBy('medicine_id')->get()->getResultArray() as $row) {
            $totals[(int) $row['medicine_id']] = $row;
        }
        return $totals;
    }

    private function stockSummary(array $medicine, ?array $totals = null): array
    {
        $totals ??= $this->stockTotals((int) $medicine['id'])[(int) $medicine['id']] ?? [];
        // Expired and quarantined remain independent physical subsets and can overlap, even when archived.
        foreach (['total_stock','usable_stock','expired_stock','quarantined_stock','near_expiry_stock'] as $field) {
            $medicine[$field] = (int) ($totals[$field] ?? 0);
        }
        if ((int) $medicine['is_active'] !== 1) {
            $medicine['usable_stock'] = 0;
            $medicine['near_expiry_stock'] = 0;
        }
        $medicine['low_stock'] = $medicine['usable_stock'] <= (int) $medicine['minimum_stock_level'];
        return $medicine;
    }

    private function physical(int $medicineId): int
    {
        return (int) $this->db->table('clinic_medicine_batches')->selectSum('remaining_quantity', 'physical')->where('tenant_id', CurrentTenant::id())->where('medicine_id', $medicineId)->get()->getRow()->physical;
    }

    private function verifyStock(array $medicine): void
    {
        if ((int) $medicine['total_stock'] !== $this->physical((int) $medicine['id'])) {
            throw new RuntimeException('Inventory invariant failed: cache differs from physical batch total. Investigation required.', 409);
        }
        // Check each batch: offsetting drift can leave both medicine-wide totals apparently balanced.
        // Missing cutover balances must be investigated, never fabricated by a subsequent receipt.
        $mismatch = $this->db->query('SELECT b.id FROM ' . $this->t('clinic_medicine_batches') . ' b
            LEFT JOIN ' . $this->t('stock_movements') . ' s ON s.tenant_id=b.tenant_id AND s.medicine_id=b.medicine_id AND s.batch_id=b.id
            WHERE b.tenant_id=? AND b.medicine_id=?
            GROUP BY b.id, b.remaining_quantity
            HAVING COALESCE(SUM(s.delta),0) <> b.remaining_quantity
                OR (COUNT(s.id)=0 AND b.remaining_quantity>0)
            LIMIT 1', [CurrentTenant::id(), $medicine['id']])->getRowArray();
        if ($mismatch !== null) {
            throw new RuntimeException('Inventory invariant failed: batch ' . $mismatch['id'] . ' ledger differs from remaining stock or lacks an opening balance. Investigation required.', 409);
        }
    }

    private function active(array $medicine): void
    {
        if ((int) $medicine['is_active'] !== 1) {
            throw new RuntimeException('Medicine is archived.', 409);
        }
    }

    private function changeBatch(int $batchId, int $delta): void
    {
        $ok = $this->db->query('UPDATE ' . $this->t('clinic_medicine_batches') . ' SET remaining_quantity=CAST(remaining_quantity AS SIGNED)+?, updated_at=? WHERE tenant_id=? AND id=? AND CAST(remaining_quantity AS SIGNED)+? BETWEEN 0 AND initial_quantity', [$delta,date('Y-m-d H:i:s'),CurrentTenant::id(),$batchId,$delta]);
        $this->affected($ok);
    }

    private function movement(array $op, int $batchId, int $delta, string $reason, ?int $original = null): void
    {
        $this->insert('stock_movements', ['tenant_id'=>CurrentTenant::id(), 'medicine_id'=>$op['medicine_id'], 'batch_id'=>$batchId, 'operation_id'=>$op['id'], 'actor_id'=>$op['actor_id'], 'delta'=>$delta, 'reason'=>$reason, 'original_movement_id'=>$original, 'created_at'=>date('Y-m-d H:i:s')]);
    }

    private function insert(string $table, array $data): int
    {
        if (!$this->db->table($table)->insert($data)) {
            if ($table === 'inventory_operations' && (int) $this->db->error()['code'] === 1062) {
                // Different medicine mutexes can race on a tenant-wide key; the unique index wins.
                throw new RuntimeException('Idempotency key already used or dispensation already corrected.', 409);
            }
            throw new RuntimeException('Could not insert inventory record: ' . $table);
        }
        return (int) $this->db->insertID();
    }

    private function affected(mixed $ok): void
    {
        if ($ok === false || $this->db->affectedRows() !== 1) {
            throw new RuntimeException('Inventory arithmetic update failed; stock or version conflict.', 409);
        }
    }

    private function t(string $table): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable($table));
    }

    private function validateKey(string $key): void
    {
        if (!preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $key)) {
            throw new InvalidArgumentException('A 1–128 character ASCII idempotency key is required.', 422);
        }
    }

    private function only(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed)) {
            throw new InvalidArgumentException('Unsupported inventory fields; stock cannot be edited directly.', 422);
        }
    }

    private function integer(mixed $raw, string $field, int $min, int $max): int
    {
        if ((!is_int($raw) && !is_string($raw)) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string) $raw) || strlen((string) $raw) > 10 || (int) $raw < $min || (int) $raw > $max) {
            throw new InvalidArgumentException($field . ' must be an integer between ' . $min . ' and ' . $max . '.', 422);
        }
        return (int) $raw;
    }

    private function normalizeNullableIdentity(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    private function text(mixed $raw, string $field, int $max, bool $nullable = false): ?string
    {
        if ($nullable && ($raw === null || $raw === '')) {
            return null;
        }
        if (!is_string($raw) || trim($raw) === '' || mb_strlen(trim($raw)) > $max || str_contains($raw, "\0")) {
            throw new InvalidArgumentException($field . ' is required and must be at most ' . $max . ' characters.', 422);
        }
        return trim($raw);
    }

    private function date(mixed $raw): string
    {
        if (!is_string($raw)) {
            throw new InvalidArgumentException('expiration_date must be YYYY-MM-DD.', 422);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (!$date || $date->format('Y-m-d') !== $raw || $raw < '1000-01-01') {
            throw new InvalidArgumentException('expiration_date must be a valid YYYY-MM-DD.', 422);
        }
        return $raw;
    }
}

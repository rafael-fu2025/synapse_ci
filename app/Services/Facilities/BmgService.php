<?php

declare(strict_types=1);

namespace App\Services\Facilities;

use App\Models\AuditEventModel;
use App\Services\Audit\AuditService;
use App\Services\AuthenticatedUser;
use App\Services\PortalContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Sensor-free batch bookkeeping; no sanitation or quality assessment. */
final class BmgService
{
    private const ACTIVE_STATUSES = ['processing', 'awaiting_output', 'curing'];

    private BaseConnection $db;
    private AuditService $auditService;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        // The ledger must participate in the batch transaction, not another connection.
        $this->auditService = new AuditService(new AuditEventModel($this->db));
    }

    public function createBatch(int $unitId, mixed $inputWeightKg, ?string $notes = null): array
    {
        $actor = $this->actor('facilities.manage');
        $input = $this->weight($inputWeightKg, false);
        $notes = $this->notes($notes);

        return $this->locked($actor, $unitId, function (array $unit) use ($actor, $unitId, $input, $notes): array {
            if ($unit['status'] !== 'active') {
                throw new RuntimeException('Creating a batch requires an active unit.', 409);
            }
            if ($this->cents($input) > $this->cents($this->weight($unit['drum_capacity_kg'], false))) {
                throw new InvalidArgumentException('Input weight exceeds drum capacity.');
            }
            if ($this->batches((int) $actor['tenant_id'], $unitId)->whereIn('status', self::ACTIVE_STATUSES)->countAllResults() > 0) {
                throw new RuntimeException('Unit is already running an active batch.', 409);
            }
            $now = date('Y-m-d H:i:s');
            $values = [
                'tenant_id' => (int) $actor['tenant_id'],
                'unit_id' => $unitId,
                'batch_code' => 'BATCH-' . date('Y') . '-' . bin2hex(random_bytes(10)),
                'status' => 'processing',
                'total_input_weight_kg' => $input,
                'quality_grade' => 'unclassified',
                'notes' => $notes,
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (!$this->db->table('bmg_batches')->insert($values)) {
                throw new RuntimeException('Batch could not be created.', 503);
            }
            $id = (int) $this->db->insertID();
            $this->audit($actor, $id, 'created', 'create', [
                'unit' => $unit['unit_code'], 'batch_code' => $values['batch_code'],
                'input_kg' => $input, 'notes' => $notes,
            ]);
            return $this->findBatch((int) $actor['tenant_id'], $id);
        });
    }

    public function releaseBatch(
        int $batchId,
        mixed $outputWeightKg,
        string $qualityGrade = 'unclassified',
        ?string $notes = null
    ): array {
        $actor = $this->actor('facilities.manage');
        if ($qualityGrade !== 'unclassified') {
            throw new InvalidArgumentException('Only unclassified quality is supported; no quality assessment is performed.');
        }
        $output = $outputWeightKg === null || (is_string($outputWeightKg) && trim($outputWeightKg) === '')
            ? null : $this->weight($outputWeightKg, true);
        $notes = $this->notes($notes);
        return $this->finish($actor, $batchId, 'released', $output, $notes);
    }

    public function cancelBatch(int $batchId, string $reason): array
    {
        $actor = $this->actor('facilities.manage');
        $reason = $this->notes($reason);
        if ($reason === null) {
            throw new InvalidArgumentException('A cancellation reason is required.');
        }
        return $this->finish($actor, $batchId, 'voided', null, $reason);
    }

    /** @return array{activeBatch: ?array, pastBatches: array} */
    public function batchOverview(int $unitId): array
    {
        $actor = $this->actor('facilities.read');
        $tenant = (int) $actor['tenant_id'];
        $this->findUnit($tenant, $unitId);
        return [
            'activeBatch' => $this->batches($tenant, $unitId)->whereIn('status', self::ACTIVE_STATUSES)
                ->orderBy('started_at', 'DESC')->orderBy('id', 'DESC')->get()->getRowArray(),
            'pastBatches' => $this->batches($tenant, $unitId)->whereNotIn('status', self::ACTIVE_STATUSES)
                ->orderBy('started_at', 'DESC')->orderBy('id', 'DESC')->limit(50)->get()->getResultArray(),
        ];
    }

    public function getUnitsOverview(): array
    {
        $actor = $this->actor('facilities.read');
        $tenant = (int) $actor['tenant_id'];
        $units = $this->db->table('bmg_units')->where('tenant_id', $tenant)->where('deleted_at', null)
            ->orderBy('unit_code')->get()->getResultArray();
        foreach ($units as &$unit) {
            $unit['active_batch'] = $this->batches($tenant, (int) $unit['id'])->whereIn('status', self::ACTIVE_STATUSES)
                ->orderBy('started_at', 'DESC')->orderBy('id', 'DESC')->get()->getRowArray();
        }
        return $units;
    }

    private function finish(array $actor, int $batchId, string $status, ?string $output, ?string $notes): array
    {
        $tenant = (int) $actor['tenant_id'];
        $batch = $this->findBatch($tenant, $batchId);
        return $this->locked($actor, (int) $batch['unit_id'], function (array $unit) use ($actor, $tenant, $batchId, $status, $output, $notes): array {
            // Re-read after the unit lock: another operator may already have finished it.
            $batch = $this->findBatch($tenant, $batchId);
            if ((int) $batch['unit_id'] !== (int) $unit['id'] || !in_array($batch['status'], self::ACTIVE_STATUSES, true)) {
                throw new RuntimeException('Only an active batch can be completed or cancelled.', 409);
            }
            if ($output !== null && $this->cents($output) > $this->cents($this->weight($batch['total_input_weight_kg'], false))) {
                throw new InvalidArgumentException('Output weight cannot exceed total input weight.');
            }
            $now = date('Y-m-d H:i:s');
            if (!$this->batches($tenant, (int) $unit['id'])->where('id', $batchId)->update([
                'status' => $status, 'output_weight_kg' => $output, 'quality_grade' => 'unclassified',
                'completed_at' => $now, 'release_notes' => $notes, 'updated_at' => $now,
            ])) {
                throw new RuntimeException('Batch could not be updated.', 503);
            }
            $this->audit($actor, $batchId, $status === 'released' ? 'released' : 'cancelled', $status === 'released' ? 'release' : 'cancel', [
                'batch_code' => $batch['batch_code'], 'input_kg' => $batch['total_input_weight_kg'],
                'output_kg' => $output, 'grade' => 'unclassified', 'notes' => $notes,
            ]);
            return $this->findBatch($tenant, $batchId);
        });
    }

    private function actor(string $permission): array
    {
        $request = service('request');
        if ($request->getHeaderLine('X-Device-Token') !== '' || $request->getHeaderLine('Authorization') !== '') {
            throw new RuntimeException('Human session authentication required; tokens cannot act as users.', 403);
        }
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) {
            throw new RuntimeException('Authentication required.', 401);
        }
        if (!PortalContext::isRolePermitted($actor['role'], PortalContext::PORTAL_BMG)
            || !in_array($permission, $actor['permissions'], true)) {
            throw new RuntimeException('Facilities permission required: ' . $permission, 403);
        }
        return $actor;
    }

    private function findUnit(int $tenant, int $id, bool $lock = false): array
    {
        $table = $this->db->protectIdentifiers($this->db->prefixTable('bmg_units'));
        $suffix = $lock && $this->db->DBDriver !== 'SQLite3' ? ' FOR UPDATE' : '';
        $unit = $this->db->query('SELECT * FROM ' . $table . ' WHERE tenant_id = ? AND id = ? AND deleted_at IS NULL' . $suffix, [$tenant, $id])->getRowArray();
        if ($unit === null) {
            throw new RuntimeException('Unit not found.', 404);
        }
        return $unit;
    }

    private function findBatch(int $tenant, int $id): array
    {
        $batch = $this->db->table('bmg_batches')->where('tenant_id', $tenant)->where('id', $id)->get()->getRowArray();
        if ($batch === null) {
            throw new RuntimeException('Batch not found.', 404);
        }
        return $batch;
    }

    private function batches(int $tenant, int $unitId): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('bmg_batches')->where('tenant_id', $tenant)->where('unit_id', $unitId);
    }

    private function locked(array $actor, int $unitId, callable $operation): array
    {
        if (!$this->db->transBegin()) {
            throw new RuntimeException('Batch transaction unavailable.', 503);
        }
        try {
            // Tenant first matches rotation operations' lock order and serializes the audit chain.
            $table = $this->db->protectIdentifiers($this->db->prefixTable('tenants'));
            if ($this->db->DBDriver === 'SQLite3') {
                // SQLite has no row locks: acquire its writer lock before reading batch state.
                $this->db->query('UPDATE ' . $table . ' SET id = id WHERE id = ?', [$actor['tenant_id']]);
            }
            $suffix = $this->db->DBDriver === 'SQLite3' ? '' : ' FOR UPDATE';
            $tenant = $this->db->query('SELECT id FROM ' . $table . ' WHERE id = ? AND is_active = 1 AND deleted_at IS NULL' . $suffix, [$actor['tenant_id']])->getRowArray();
            if ($tenant === null) {
                throw new RuntimeException('Tenant unavailable.', 401);
            }
            $result = $operation($this->findUnit((int) $actor['tenant_id'], $unitId, true));
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Batch transaction could not be committed.', 503);
            }
            return $result;
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    private function audit(array $actor, int $id, string $event, string $action, array $payload): void
    {
        $this->auditService->log('bmg.batch.' . $event, 'bmg_batch', (string) $id, $action, $payload, (int) $actor['id'], $actor['role']);
    }

    /** Exact DECIMAL(10,2) input, never floating point or silently rounded. */
    private function weight(mixed $value, bool $allowZero): string
    {
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/D', (string) $value)) {
            throw new InvalidArgumentException('Weight must be a fixed decimal with at most two decimal places.');
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
        $normalized = (string) (int) $whole . '.' . str_pad($fraction, 2, '0');
        if (!$allowZero && $this->cents($normalized) === 0) {
            throw new InvalidArgumentException('Input weight and capacity must be greater than zero.');
        }
        return $normalized;
    }

    private function cents(string $weight): int
    {
        return (int) str_replace('.', '', $weight);
    }

    private function notes(?string $value): ?string
    {
        if ($value !== null && mb_strlen($value) > 2000) {
            throw new InvalidArgumentException('Notes must not exceed 2000 characters.');
        }
        $value = $value === null ? '' : trim($value);
        return $value === '' ? null : $value;
    }
}

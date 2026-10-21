<?php

declare(strict_types=1);

namespace App\Models;

final class BmgUnitModel extends TenantModel
{
    protected $table = 'bmg_units';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'unit_code',
        'name',
        'status',
        'drum_capacity_kg',
        'default_category',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    /**
     * Get units list with active batch information.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnitsWithActiveBatches(): array
    {
        $units = $this->scoped()->orderBy('unit_code', 'ASC')->findAll();
        $batchModel = new BmgBatchModel();

        foreach ($units as &$u) {
            $activeBatch = $batchModel->scoped()
                ->where('unit_id', $u['id'])
                ->whereIn('status', ['processing', 'awaiting_output', 'curing'])
                ->orderBy('started_at', 'DESC')
                ->first();

            $u['active_batch'] = $activeBatch;
        }

        return $units;
    }
}

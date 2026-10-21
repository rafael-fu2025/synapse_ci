<?php

declare(strict_types=1);

namespace App\Models;

final class ClinicMedicineBatchModel extends TenantModel
{
    protected $table = 'clinic_medicine_batches';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'medicine_id',
        'batch_number',
        'expiration_date',
        // Quantities and status are written only by InventoryService.
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Get available batches for a medicine ordered by FEFO (First-Expired, First-Out).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFefoBatches(int $medicineId): array
    {
        return $this->scoped()
            ->where('medicine_id', $medicineId)
            ->where('status', 'active')
            ->where('remaining_quantity >', 0)
            ->where('expiration_date >=', date('Y-m-d'))
            ->orderBy('expiration_date', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }
}

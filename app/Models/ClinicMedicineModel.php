<?php

declare(strict_types=1);

namespace App\Models;

final class ClinicMedicineModel extends TenantModel
{
    protected $table = 'clinic_medicines';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'name',
        'generic_name',
        'category',
        'dosage_form',
        'strength',
        'unit_of_measure',
        'minimum_stock_level',
        'is_active',
        'version',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Get all active medicines with available stock.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInStockList(): array
    {
        $medicineTable = $this->db->protectIdentifiers($this->db->prefixTable($this->table));
        $batchTable = $this->db->protectIdentifiers($this->db->prefixTable('clinic_medicine_batches'));
        $usable = '(SELECT COALESCE(SUM(b.remaining_quantity),0) FROM ' . $batchTable
            . ' b WHERE b.medicine_id=' . $medicineTable . '.id AND b.tenant_id=' . $medicineTable
            . '.tenant_id AND b.status=\'active\' AND b.expiration_date>=' . $this->db->escape(date('Y-m-d')) . ')';
        return $this->scoped()
            ->select($medicineTable . '.*, ' . $usable . ' AS usable_stock', false)
            ->where('is_active', 1)
            ->having('usable_stock >', 0)
            ->orderBy('name', 'ASC')
            ->findAll();
    }

    /** Active medicines with usable stock count and earliest non-expired active batch expiration date. */
    public function getDispenseCatalogList(): array
    {
        $medicineTable = $this->db->protectIdentifiers($this->db->prefixTable($this->table));
        $batchTable = $this->db->protectIdentifiers($this->db->prefixTable('clinic_medicine_batches'));
        $todayEsc = $this->db->escape(\App\Services\Scheduling\SchedulingClock::today());
        $usable = "(SELECT COALESCE(SUM(b.remaining_quantity),0) FROM {$batchTable} b WHERE b.medicine_id={$medicineTable}.id AND b.tenant_id={$medicineTable}.tenant_id AND b.status='active' AND b.expiration_date>={$todayEsc})";
        $nextExp = "(SELECT MIN(b2.expiration_date) FROM {$batchTable} b2 WHERE b2.medicine_id={$medicineTable}.id AND b2.tenant_id={$medicineTable}.tenant_id AND b2.status='active' AND b2.remaining_quantity>0 AND b2.expiration_date>={$todayEsc})";
        return $this->scoped()
            ->select("{$medicineTable}.*, {$usable} AS usable_stock, {$nextExp} AS next_expiration_date", false)
            ->where('is_active', 1)
            ->orderBy('name', 'ASC')
            ->findAll();
    }
}

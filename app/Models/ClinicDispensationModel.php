<?php

declare(strict_types=1);

namespace App\Models;

final class ClinicDispensationModel extends TenantModel
{
    protected $table = 'clinic_dispensations';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'encounter_id',
        'medicine_id',
        'batch_id',
        'quantity',
        'instructions',
        'dispensed_by_user_id',
        'created_at',
        'operation_id',
        'snapshot_name',
        'snapshot_generic_name',
        'snapshot_strength',
        'snapshot_dosage_form',
        'snapshot_unit',
        'snapshot_batch_number',
        'snapshot_expiration_date',
        'snapshot_reconstructed',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';

    /**
     * Get all dispensations logged for an encounter with medicine and batch names.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForEncounter(int $encounterId): array
    {
        if (!$this->db->fieldExists('operation_id', $this->table)) {
            return $this->scoped()
                ->select('clinic_dispensations.*, m.name AS medicine_name, m.generic_name AS generic_name, m.strength AS strength, m.dosage_form AS dosage_form, m.unit_of_measure AS unit_of_measure, b.batch_number AS batch_number, b.expiration_date AS expiration_date, NULL AS operation_id, 1 AS snapshot_reconstructed, NULL AS reversed_by_operation_id, NULL AS correction_mode', false)
                ->join('clinic_medicines m', 'm.id = clinic_dispensations.medicine_id AND m.tenant_id=clinic_dispensations.tenant_id')
                ->join('clinic_medicine_batches b', 'b.id = clinic_dispensations.batch_id AND b.tenant_id=clinic_dispensations.tenant_id AND b.medicine_id=clinic_dispensations.medicine_id')
                ->where('clinic_dispensations.encounter_id', $encounterId)
                ->orderBy('clinic_dispensations.id', 'ASC')
                ->findAll();
        }
        return $this->scoped()
            ->select('clinic_dispensations.*, COALESCE(clinic_dispensations.snapshot_name,m.name) AS medicine_name, COALESCE(clinic_dispensations.snapshot_generic_name,m.generic_name) AS generic_name, COALESCE(clinic_dispensations.snapshot_strength,m.strength) AS strength, COALESCE(clinic_dispensations.snapshot_dosage_form,m.dosage_form) AS dosage_form, COALESCE(clinic_dispensations.snapshot_unit,m.unit_of_measure) AS unit_of_measure, COALESCE(clinic_dispensations.snapshot_batch_number,b.batch_number) AS batch_number, COALESCE(clinic_dispensations.snapshot_expiration_date,b.expiration_date) AS expiration_date, correction.id AS reversed_by_operation_id, correction.reversal_mode AS correction_mode', false)
            ->join('clinic_medicines m', 'm.id = clinic_dispensations.medicine_id AND m.tenant_id=clinic_dispensations.tenant_id')
            ->join('clinic_medicine_batches b', 'b.id = clinic_dispensations.batch_id AND b.tenant_id=clinic_dispensations.tenant_id AND b.medicine_id=clinic_dispensations.medicine_id')
            ->join('inventory_operations correction', 'correction.reversal_of=clinic_dispensations.operation_id AND correction.tenant_id=clinic_dispensations.tenant_id', 'left')
            ->where('clinic_dispensations.encounter_id', $encounterId)
            ->orderBy('clinic_dispensations.id', 'ASC')
            ->findAll();
    }
}

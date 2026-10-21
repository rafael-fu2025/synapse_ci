<?php

declare(strict_types=1);

namespace App\Models;

final class BmgProcessLogModel extends TenantModel
{
    protected $table = 'bmg_process_logs';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'batch_id',
        'recorded_at',
        'temperature_c',
        'moisture_pct',
        'oxygen_pct',
        'ph_level',
        'recorded_by_user_id',
        'notes',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';

    /**
     * Get process logs for a batch ordered chronologically.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForBatch(int $batchId): array
    {
        return $this->scoped()
            ->select('bmg_process_logs.*, u.username as recorded_by_name')
            ->join('users u', 'u.id = bmg_process_logs.recorded_by_user_id')
            ->where('bmg_process_logs.batch_id', $batchId)
            ->orderBy('bmg_process_logs.recorded_at', 'ASC')
            ->findAll();
    }
}

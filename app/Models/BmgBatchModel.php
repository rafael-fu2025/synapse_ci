<?php

declare(strict_types=1);

namespace App\Models;

final class BmgBatchModel extends TenantModel
{
    protected $table = 'bmg_batches';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'unit_id',
        'batch_code',
        'status',
        'total_input_weight_kg',
        'output_weight_kg',
        'quality_grade',
        'started_at',
        'completed_at',
        'release_notes',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Next sequential batch code (e.g. BATCH-2026-001).
     */
    public function nextBatchCode(): string
    {
        $year = date('Y');
        $prefix = "BATCH-{$year}-";

        $last = $this->scoped()
            ->like('batch_code', $prefix, 'after')
            ->orderBy('id', 'DESC')
            ->first();

        $nextSeq = 1;
        if ($last !== null) {
            $numPart = (int) substr($last['batch_code'], strlen($prefix));
            $nextSeq = $numPart + 1;
        }

        return sprintf('%s%03d', $prefix, $nextSeq);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Read-only historical accessor for the intentionally retained bmg_device_runs.
 * Current device-reported motor events live in bmg_rotation_runs and are ingested
 * by BmgRotationService. Do not redirect this model or write new events here:
 * legacy history is not evidence of current ingestion or verified drum rotation.
 */
final class BmgDeviceRunModel extends TenantModel
{
    protected $table = 'bmg_device_runs';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'device_id',
        'unit_id',
        'batch_id',
        'run_uid',
        'motor_state',
        'duration_seconds',
        'occurred_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';
}

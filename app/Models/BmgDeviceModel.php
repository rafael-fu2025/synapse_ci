<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Legacy device-record accessor, retained for historical reads only.
 * Not the rotation authentication/ingestion API: BmgRotationService owns current
 * credentials, binding and timestamped reports. Do not use this model for new writes.
 */
final class BmgDeviceModel extends TenantModel
{
    protected $table = 'bmg_devices';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'unit_id',
        'chip_mac',
        'device_token_hash',
        'firmware_version',
        'status',
        'last_seen_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Legacy hash lookup only; not authorization for current device endpoints.
     */
    public function findByRawToken(string $rawToken): ?array
    {
        $hash = hash('sha256', trim($rawToken));
        return $this->scoped()
            ->where('device_token_hash', $hash)
            ->where('status', 'active')
            ->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceActivationGateModel extends TenantModel
{
    protected $table            = 'guidance_activation_gates';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'tenant_id',
        'gate_key',
        'title',
        'description',
        'required_signoffs_json',
        'recorded_signoffs_json',
        'is_enabled',
        'approved_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Find an activation gate by its gate key within the given tenant.
     *
     * @param string $gateKey
     * @param int|null $tenantId
     * @return array<string, mixed>|null
     */
    public function findByGateKey(string $gateKey, ?int $tenantId = null): ?array
    {
        return $this->scoped($tenantId)
            ->where('gate_key', $gateKey)
            ->first();
    }

    /**
     * Retrieve all activation gates for the given tenant ordered by ID.
     *
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getAllGates(?int $tenantId = null): array
    {
        return $this->scoped($tenantId)
            ->orderBy('id', 'ASC')
            ->findAll();
    }
}

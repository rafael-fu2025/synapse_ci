<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\CurrentTenant;
use CodeIgniter\Model;

/**
 * TenantModel ensures automatic multi-tenant data isolation.
 * Automatically injects tenant_id on insert and provides tenant-scoped helper methods.
 */
abstract class TenantModel extends Model
{
    protected bool $scopedByTenant = true;

    protected $beforeInsert = ['injectTenantId'];

    /**
     * Injects the active tenant ID into the data array before insertion.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function injectTenantId(array $data): array
    {
        if ($this->scopedByTenant && isset($data['data']) && is_array($data['data'])) {
            if (!isset($data['data']['tenant_id'])) {
                $data['data']['tenant_id'] = CurrentTenant::id();
            }
        }
        return $data;
    }

    /**
     * Scope builder queries to the active tenant.
     *
     * @param int|null $tenantId
     * @return static
     */
    public function scoped(?int $tenantId = null): static
    {
        if ($this->scopedByTenant) {
            $tid = $tenantId ?? CurrentTenant::id();
            $this->where($this->table . '.tenant_id', $tid);
        }
        return $this;
    }
}

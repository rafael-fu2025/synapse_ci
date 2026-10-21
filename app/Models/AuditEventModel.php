<?php

declare(strict_types=1);

namespace App\Models;

final class AuditEventModel extends TenantModel
{
    protected $table = 'audit_events';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'event_type',
        'entity_type',
        'entity_id',
        'actor_user_id',
        'actor_role',
        'actor_ip',
        'action',
        'payload_json',
        'prev_hash',
        'curr_hash',
        'hash_version',
        'occurred_at',
    ];

    protected $useTimestamps = false;

    /**
     * Get latest audit record for hash chaining.
     */
    public function getLatest(): ?array
    {
        return $this->scoped()->orderBy('id', 'DESC')->first();
    }
}

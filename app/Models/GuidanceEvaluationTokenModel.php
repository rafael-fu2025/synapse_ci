<?php

declare(strict_types=1);

namespace App\Models;

final class GuidanceEvaluationTokenModel extends TenantModel
{
    protected $table            = 'guidance_evaluation_tokens';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'tenant_id',
        'episode_id',
        'session_id',
        'evaluation_type',
        'provider_user_id',
        'eligibility_hmac',
        'token_hash',
        'status',
        'issued_at',
        'redeemed_at',
        'expires_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Find an evaluation token record by its SHA-256 token hash.
     *
     * @param string $tokenHash
     * @param int|null $tenantId
     * @return array<string, mixed>|null
     */
    public function findByTokenHash(string $tokenHash, ?int $tenantId = null): ?array
    {
        return $this->scoped($tenantId)
            ->where('token_hash', $tokenHash)
            ->first();
    }

    /**
     * Find an evaluation token record by its deterministic eligibility HMAC.
     *
     * @param string $eligibilityHmac
     * @param int|null $tenantId
     * @return array<string, mixed>|null
     */
    public function findByEligibilityHmac(string $eligibilityHmac, ?int $tenantId = null): ?array
    {
        return $this->scoped($tenantId)
            ->where('eligibility_hmac', $eligibilityHmac)
            ->first();
    }
}


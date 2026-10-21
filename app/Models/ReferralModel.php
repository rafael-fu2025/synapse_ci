<?php

declare(strict_types=1);

namespace App\Models;

final class ReferralModel extends TenantModel
{
    protected $table = 'referrals';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'referral_code',
        'patient_person_id',
        'source_module',
        'target_module',
        'source_encounter_id',
                'target_encounter_id',
                'target_case_id',
                'request_key',
        'issuer_user_id',
        'assigned_user_id',
        'priority',
        'reason_code',
        'reason_description',
        'status',
        'acknowledged_at',
        'closed_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Get inbox for a module (e.g. Guidance receiving referrals from Clinic).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInbox(string $targetModule, ?string $status = null): array
    {
        $builder = $this->scoped()
            ->select('referrals.*, p.first_name, p.last_name, p.mis_id, p.kind, u.username as issuer_name')
            ->join('people p', 'p.id = referrals.patient_person_id AND p.tenant_id = referrals.tenant_id AND p.deleted_at IS NULL')
            ->join('users u', 'u.id = referrals.issuer_user_id AND u.tenant_id = referrals.tenant_id')
            ->where('referrals.target_module', $targetModule)
            ->orderBy("FIELD(referrals.priority, 'crisis', 'urgent', 'routine')", '', false)
            ->orderBy('referrals.created_at', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('referrals.status', $status);
        }

        return $builder->findAll();
    }

    public function lockTenant(): void
    {
        $db = \Config\Database::connect();
        if ($db->transDepth < 1) {
            throw new \RuntimeException('Referral mutex requires a transaction.', 409);
        }
        $table = $db->protectIdentifiers($db->prefixTable('tenants'));
        if ($db->query("SELECT id FROM {$table} WHERE id = ? FOR UPDATE", [\App\Services\CurrentTenant::id()])->getRowArray() === null) {
            throw new \RuntimeException('Referral tenant not found.', 403);
        }
    }

    /**
     * Next sequential referral code (e.g. REF-2026-001).
     */
    public function nextReferralCode(): string
    {
        // All callers must hold this mutex until their referral insert commits.
                $db = \Config\Database::connect();
                if ($db->transDepth < 1) {
                    throw new \RuntimeException('Referral numbering requires a transaction.', 409);
                }
                $this->lockTenant();
                $year = date('Y');
        $prefix = "REF-{$year}-";

        $table = $db->protectIdentifiers($db->prefixTable('referrals'));
        // A current read is required even if an earlier authorization read opened a snapshot.
        $last = $db->query("SELECT referral_code FROM {$table} WHERE tenant_id = ? AND referral_code LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE",
            [\App\Services\CurrentTenant::id(), $prefix . '%'])->getRowArray();

        $nextSeq = 1;
        if ($last !== null) {
            $numPart = (int) substr($last['referral_code'], strlen($prefix));
            $nextSeq = $numPart + 1;
        }

        return sprintf('%s%03d', $prefix, $nextSeq);
    }
}

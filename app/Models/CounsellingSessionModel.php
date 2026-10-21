<?php

declare(strict_types=1);

namespace App\Models;

final class CounsellingSessionModel extends TenantModel
{
    protected $table = 'counselling_sessions';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'case_id',
        'appointment_id',
        'queue_entry_id',
        'session_number',
        'session_type',
        'counsellor_user_id',
        'status',
        'started_at',
        'ended_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    /**
     * Get all sessions under a case ordered chronologically.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForCase(int $caseId): array
    {
        return $this->scoped()
            ->select('counselling_sessions.*, u.username as counsellor_name')
            ->join('users u', 'u.id = counselling_sessions.counsellor_user_id AND u.tenant_id = counselling_sessions.tenant_id')
            ->where('counselling_sessions.case_id', $caseId)
            ->orderBy('counselling_sessions.session_number', 'ASC')
            ->findAll();
    }
}

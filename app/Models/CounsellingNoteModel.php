<?php

declare(strict_types=1);

namespace App\Models;

final class CounsellingNoteModel extends TenantModel
{
    protected $table = 'counselling_notes';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'session_id',
        'version',
        'supersedes_note_id',
        'is_draft',
        'notes_cipher',
        'notes_nonce',
        'notes_tag',
        'notes_key_version',
        'created_by_user_id',
        'created_at',
    ];

    protected $useTimestamps = false;

    /**
     * Get the latest finalized note for a session.
     */
    public function getLatestFinalNote(int $sessionId): ?array
    {
        return $this->scoped()
            ->where('session_id', $sessionId)
            ->where('is_draft', 0)
            ->orderBy('version', 'DESC')
            ->first();
    }

    /**
     * Get the active draft note for a session if one exists.
     */
    public function getDraftNote(int $sessionId): ?array
    {
        return $this->scoped()
            ->where('session_id', $sessionId)
            ->where('is_draft', 1)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Get the full amendment chain for audit inspection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVersionHistory(int $sessionId): array
    {
        return $this->scoped()
            ->select('counselling_notes.*, u.username as author_name')
            ->join('users u', 'u.id = counselling_notes.created_by_user_id AND u.tenant_id = counselling_notes.tenant_id', 'left')
            ->where('counselling_notes.session_id', $sessionId)
            ->where('counselling_notes.is_draft', 0)
            ->orderBy('counselling_notes.version', 'ASC')
            ->findAll();
    }
}

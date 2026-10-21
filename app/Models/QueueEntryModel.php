<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\CurrentTenant;

final class QueueEntryModel extends TenantModel
{
    protected $table = 'queue_entries';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'unit',
        'queue_date',
        'ticket_no',
        'ticket_code',
        'person_id',
        'appointment_id',
        'status',
        'priority',
        'called_at',
        'called_by_user_id',
        'started_at',
        'completed_at',
        'skip_until',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Atomically issues the next ticket number and code for a unit on a given date.
     * Prevents race conditions during simultaneous walk-ins or appointment promotions.
     *
     * @return array{ticket_no: int, ticket_code: string}
     */
    public function issueNextTicket(string $unit, string $queueDate, ?int $tenantId = null): array
    {
        $db = \Config\Database::connect();
        $tid = $tenantId ?? CurrentTenant::id();

        // Atomic upsert with LAST_INSERT_ID to lock and get the sequence number
        $sql = "INSERT INTO daily_ticket_counters (tenant_id, unit, queue_date, last_ticket_no)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE last_ticket_no = LAST_INSERT_ID(last_ticket_no + 1)";

        $db->query($sql, [$tid, $unit, $queueDate]);
        $ticketNo = (int) $db->insertID();

        // If insertID was 0 (first row created), grab 1
        if ($ticketNo === 0) {
            $ticketNo = 1;
        }

        $prefix = ($unit === 'clinic') ? 'C' : 'G';
        $ticketCode = sprintf('%s-%03d', $prefix, $ticketNo);

        return [
            'ticket_no'   => $ticketNo,
            'ticket_code' => $ticketCode,
        ];
    }

    /**
     * Get active queue entries for a unit on a given date with person details.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDailyQueueWithPatients(string $unit, string $queueDate): array
    {
        return $this->scoped()
            ->select('queue_entries.*, p.first_name, p.middle_name, p.last_name, p.mis_id, p.kind, p.gender, sp.program, sp.year_level, ep.department as employee_dept')
            ->join('people p', 'p.id = queue_entries.person_id')
            ->join('student_profiles sp', 'sp.person_id = p.id', 'left')
            ->join('employee_profiles ep', 'ep.person_id = p.id', 'left')
            ->where('queue_entries.unit', $unit)
            ->where('queue_entries.queue_date', $queueDate)
            ->orderBy("FIELD(queue_entries.priority, 'emergency', 'urgent', 'routine')", '', false)
            ->orderBy('queue_entries.ticket_no', 'ASC')
            ->findAll();
    }
}

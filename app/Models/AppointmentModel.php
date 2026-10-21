<?php

declare(strict_types=1);

namespace App\Models;

final class AppointmentModel extends TenantModel
{
    protected $table = 'appointments';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'unit',
        'person_id',
        'provider_user_id',
        'staff_assignment_id',
        'starts_at',
        'ends_at',
        'local_date',
        'status',
        'type',
        'reason',
        'cancellation_reason',
        'booked_by_user_id',
        'confirmed_by_user_id',
        'confirmed_at',
        'assigned_by_user_id',
        'assigned_at',
        'version',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    private function withProviderName(): self
    {
        return $this->scoped()
            ->select('appointments.*, p.first_name, p.last_name, p.mis_id, p.kind')
                        ->select("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', provider.first_name, provider.last_name)), ''), u.username) AS provider_name", false)
            ->join('people p', 'p.id = appointments.person_id AND p.tenant_id = appointments.tenant_id')
            ->join('users u', 'u.id = appointments.provider_user_id AND u.tenant_id = appointments.tenant_id', 'left')
            ->join('department_staff staff', 'staff.id = appointments.staff_assignment_id AND staff.tenant_id = appointments.tenant_id AND staff.unit = appointments.unit', 'left')
            ->join('people provider', 'provider.id = staff.person_id AND provider.tenant_id = staff.tenant_id', 'left');
    }

    /** @return array<int, array<string, mixed>> */
    public function getForDay(string $unit, string $localDate): array
    {
        return $this->withProviderName()
            ->where('appointments.unit', $unit)
            ->where('appointments.local_date', $localDate)
            ->orderBy('appointments.starts_at', 'ASC')
            ->findAll();
    }

    /** Pending requests are intentionally independent of the calendar's date filter. */
    public function getPendingRequests(string $unit): array
    {
        return $this->withProviderName()
            ->where('appointments.unit', $unit)
            ->where('appointments.status', 'requested')
            ->orderBy('appointments.created_at', 'ASC')
            ->orderBy('appointments.id', 'ASC')
            ->findAll();
    }

    /** Check an assignment's person across all units, including unmigrated legacy bookings. */
    public function hasConflict(int $assignmentId, string $startsAt, string $endsAt, ?int $ignoreAppointmentId = null): bool
    {
        $builder = $this->scoped()
            ->join('department_staff target_staff', 'target_staff.tenant_id = appointments.tenant_id AND target_staff.id = ' . $assignmentId)
            ->join('department_staff booked_staff', 'booked_staff.id = appointments.staff_assignment_id AND booked_staff.tenant_id = appointments.tenant_id', 'left')
            ->join('users legacy_provider', 'legacy_provider.id = appointments.provider_user_id AND legacy_provider.tenant_id = appointments.tenant_id', 'left')
            ->groupStart()
                ->where('booked_staff.person_id = target_staff.person_id', null, false)
                ->orGroupStart()
                    ->where('appointments.staff_assignment_id', null)
                    ->where('legacy_provider.person_id = target_staff.person_id', null, false)
                ->groupEnd()
            ->groupEnd()
            ->whereIn('appointments.status', ['scheduled', 'confirmed', 'checked_in'])
            ->where('appointments.starts_at <', $endsAt)
            ->where('appointments.ends_at >', $startsAt);

        if ($ignoreAppointmentId !== null) {
            $builder->where('appointments.id !=', $ignoreAppointmentId);
        }

        return $builder->countAllResults() > 0;
    }
}

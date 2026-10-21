<?php

declare(strict_types=1);

namespace App\Models;

final class AvailabilityExceptionModel extends TenantModel
{
    protected $table = 'availability_exceptions';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'provider_user_id',
        'staff_assignment_id',
        'exception_date',
        'is_unavailable',
        'custom_start_time',
        'custom_end_time',
        'reason',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public static function snapshotToken(array $row): string
    {
        $values = [];
        foreach (['id', 'tenant_id', 'staff_assignment_id', 'exception_date', 'is_unavailable', 'custom_start_time', 'custom_end_time', 'reason', 'updated_at'] as $key) {
            $values[$key] = (string) ($row[$key] ?? '');
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public function forUnit(string $unit): array
    {
        return $this->scoped()->select('availability_exceptions.*, CONCAT_WS(\' \', p.first_name, p.last_name) AS provider_name')
            ->join('department_staff s', 's.id = availability_exceptions.staff_assignment_id AND s.tenant_id = availability_exceptions.tenant_id')
            ->join('people p', 'p.id = s.person_id AND p.tenant_id = s.tenant_id')
            ->where('s.unit', $unit)->orderBy('exception_date', 'DESC')->orderBy('availability_exceptions.id', 'DESC')->findAll();
    }

    /** The identifier is a department staff assignment ID, never a legacy user ID. */
    public function findException(int $assignmentId, string $date): ?array
    {
        return $this->scoped()
            ->where('staff_assignment_id', $assignmentId)
            ->where('exception_date', $date)
            ->first();
    }
}

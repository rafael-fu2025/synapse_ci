<?php

declare(strict_types=1);

namespace App\Models;

final class ProviderAvailabilityModel extends TenantModel
{
    protected $table = 'provider_availability';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id',
        'provider_user_id',
        'staff_assignment_id',
        'unit',
        'day_of_week',
        'start_time',
        'end_time',
        'slot_duration_minutes',
        'is_active',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public static function snapshotToken(array $row): string
    {
        $values = [];
        foreach (['id', 'tenant_id', 'unit', 'staff_assignment_id', 'day_of_week', 'start_time', 'end_time', 'slot_duration_minutes', 'is_active', 'updated_at'] as $key) {
            $values[$key] = (string) ($row[$key] ?? '');
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    /**
     * Get active templates for a department staff assignment, not a user account.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveForProvider(int $assignmentId, int $dayOfWeek): array
    {
        return $this->scoped()
            ->where('staff_assignment_id', $assignmentId)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', 1)
            ->orderBy('start_time', 'ASC')
            ->findAll();
    }
}

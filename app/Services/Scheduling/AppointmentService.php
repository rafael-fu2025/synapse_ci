<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\AppointmentModel;
use App\Models\AvailabilityExceptionModel;
use App\Models\DepartmentStaffModel;
use App\Models\PersonModel;
use App\Models\ProviderAvailabilityModel;
use App\Models\UserModel;
use App\Services\CurrentTenant;
use App\Services\PortalContext;

final class AppointmentService
{
    private AppointmentModel $appointmentModel;
    private ProviderAvailabilityModel $availabilityModel;
    private AvailabilityExceptionModel $exceptionModel;

    public function __construct(
        ?AppointmentModel $appointmentModel = null,
        ?ProviderAvailabilityModel $availabilityModel = null,
        ?AvailabilityExceptionModel $exceptionModel = null
    ) {
        $this->appointmentModel = $appointmentModel ?? new AppointmentModel();
        $this->availabilityModel = $availabilityModel ?? new ProviderAvailabilityModel();
        $this->exceptionModel = $exceptionModel ?? new AvailabilityExceptionModel();
    }

    public function assertUnit(string $unit): void
    {
        if (!in_array($unit, ['clinic', 'guidance'], true)) {
            throw new \InvalidArgumentException('Invalid appointment department.');
        }
    }

    public function assertStaff(string $unit, array $actor): void
    {
        $this->assertUnit($unit);
        if (empty($actor['id']) || !PortalContext::isRolePermitted((string) ($actor['role'] ?? ''), $unit)
            || (isset($actor['tenant_id']) && (int) $actor['tenant_id'] !== CurrentTenant::id())) {
            throw new \DomainException('Department staff access is required.', 403);
        }
        $user = $this->actor($actor);
        if (!PortalContext::isRolePermitted((string) $user['role'], $unit)) {
            throw new \DomainException('Department staff access is required.', 403);
        }
    }

    /** Personal authority uses current role grants, including only the actual employee portal baseline. */
    public function assertPersonalPermission(array $actor, string $permission): array
    {
        $tenant = CurrentTenant::id();
        $user = (new \App\Services\AuthenticatedUser())->forIdentity((int) ($actor['id'] ?? 0), $tenant);
        if ($user === null || (int) ($actor['tenant_id'] ?? 0) !== $tenant
            || empty($user['person_id']) || (int) ($actor['person_id'] ?? 0) !== $user['person_id']) {
            throw new \DomainException('Your account must have a valid linked person in this tenant.', 403);
        }
        // No role bypass and no actor/session permissions: forIdentity queries actual current grants.
        if (!in_array($permission, $user['permissions'], true)) {
            throw new \DomainException('Permission required: ' . $permission, 403);
        }
        return $user;
    }

    public function person(int $personId): array
    {
        $person = (new PersonModel())->scoped()->find($personId);
        if ($person === null) {
            throw new \InvalidArgumentException('A valid person in this tenant is required.');
        }
        return $person;
    }

    public function appointment(string $unit, int $id): array
    {
        $this->assertUnit($unit);
        $appointment = $this->appointmentModel->scoped()
            ->select('appointments.*, p.first_name, p.last_name, p.mis_id, p.kind')
            ->select("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', provider.first_name, provider.last_name)), ''), u.username) AS provider_name", false)
            ->join('department_staff staff', 'staff.id = appointments.staff_assignment_id AND staff.tenant_id = appointments.tenant_id AND staff.unit = appointments.unit', 'left')
            ->join('people provider', 'provider.id = staff.person_id AND provider.tenant_id = staff.tenant_id', 'left')
            ->join('people p', 'p.id = appointments.person_id AND p.tenant_id = appointments.tenant_id')
            ->join('users u', 'u.id = appointments.provider_user_id AND u.tenant_id = appointments.tenant_id', 'left')
            ->where('appointments.unit', $unit)->find($id);
        if ($appointment === null) {
            throw new \InvalidArgumentException('Appointment not found.', 404);
        }
        return $appointment;
    }

    public function validateDate(string $date): void
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)) {
            throw new \InvalidArgumentException('Use a valid appointment date (YYYY-MM-DD).');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, SchedulingClock::timezone());
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Use a valid appointment date (YYYY-MM-DD).');
        }
    }

    /**
     * Treat existing and new appointment strings as Manila wall-clock values;
     * store Y-m-d H:i:s without conversion, and use its date as local_date.
     * Request intervals are 30-minute preferences, not reserved provider capacity.
     */
    public function requestAppointment(string $unit, array $data, array $actor, bool $personal = false): array
    {
        $this->assertUnit($unit);
        $user = $personal ? $this->assertPersonalPermission($actor, 'portal.appointments.create') : $this->actor($actor);
        if (!$personal) {
            $this->assertStaff($unit, $user);
        }
        $personId = $personal ? (int) $user['person_id'] : (int) ($data['person_id'] ?? 0);
        if ($personal && ($personId <= 0 || $personId !== (int) ($user['person_id'] ?? 0))) {
            throw new \DomainException('Your account must have a valid linked person.', 403);
        }
        $this->person($personId);
        $date = (string) ($data['date'] ?? '');
        $this->validateDate($date);
        $time = (string) ($data['preferred_time'] ?? '');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            throw new \InvalidArgumentException('Use a valid preferred time (HH:mm).');
        }
        $types = $unit === 'clinic' ? ['routine', 'follow_up', 'referral_based'] : ['initial', 'follow_up', 'referral_based'];
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, $types, true)) {
            throw new \InvalidArgumentException('Choose a valid consultation type for this department.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if (mb_strlen($reason) > 255) {
            throw new \InvalidArgumentException('Reason must be at most 255 characters.');
        }
        $start = new \DateTimeImmutable($date . ' ' . $time . ':00', SchedulingClock::timezone());
        $this->assertNotPast($start->format('Y-m-d H:i:s'));
        $id = $this->appointmentModel->insert([
            'tenant_id' => CurrentTenant::id(), 'unit' => $unit, 'person_id' => $personId,
            'staff_assignment_id' => null, 'provider_user_id' => null, 'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'), 'local_date' => $date,
            'status' => 'requested', 'type' => $type, 'reason' => $reason !== '' ? $reason : null,
            'booked_by_user_id' => (int) $user['id'], 'version' => 1,
        ]);
        if ($id === false) {
            throw new \RuntimeException('Unable to save appointment request.');
        }
        return $this->appointment($unit, (int) $id);
    }

    /** Scheduling identity is independent of login accounts and authorization roles. */
    public function providers(string $unit): array
    {
        $this->assertUnit($unit);
        $providers = [];
        foreach ($this->roster($unit) as $row) {
            if ($row['eligible']) {
                $providers[] = ['id' => $row['staff_assignment_id'], 'name' => $row['name'], 'position' => $row['position']];
            }
        }
        return $providers;
    }

    public function assertAvailabilityManager(string $unit, array $actor): void
    {
        $this->assertStaff($unit, $actor);
        $user = $this->actor($actor);
        $roles = $unit === 'clinic' ? ['superadmin', 'clinic_admin'] : ['superadmin', 'guidance_admin', 'guidance_supervisor'];
        if (!in_array($user['role'], $roles, true)) {
            throw new \DomainException('Department administrator access is required to manage availability.', 403);
        }
    }

    /** Local employee roster only; department and job title never grant an account role. */
    public function departmentStaff(string $unit, array $actor): array
    {
        $this->assertAvailabilityManager($unit, $actor);
        return $this->roster($unit);
    }

    private function roster(string $unit): array
    {
        $department = $unit === 'clinic' ? 'health services' : 'guidance';
        $rows = (new PersonModel())->scoped()
            ->select('people.id AS person_id, people.first_name, people.last_name, ep.department, ep.position, ep.employment_status, staff.id AS staff_assignment_id, staff.status AS assignment_status')
            ->join('employee_profiles ep', 'ep.person_id = people.id AND ep.tenant_id = people.tenant_id')
            ->join('department_staff staff', 'staff.person_id = people.id AND staff.tenant_id = people.tenant_id AND staff.unit = ' . \Config\Database::connect()->escape($unit), 'left')
            ->where('people.kind', 'employee')
            ->orderBy('people.last_name')->orderBy('people.first_name')->orderBy('people.id')
            ->findAll();
        $staff = [];
        foreach ($rows as $row) {
            if (strtolower(trim((string) $row['department'])) !== $department) {
                continue;
            }
            $activeEmployee = strtolower(trim((string) $row['employment_status'])) === 'active';
            $eligible = $activeEmployee && $row['staff_assignment_id'] !== null && $row['assignment_status'] === 'active';
            $configured = $eligible && $this->availabilityModel->scoped()->where('unit', $unit)
                ->where('staff_assignment_id', $row['staff_assignment_id'])->countAllResults() > 0;
            $status = !$activeEmployee ? 'Inactive employee'
                : ($row['staff_assignment_id'] === null ? 'Not enrolled for scheduling'
                    : (!$eligible ? 'Inactive assignment' : ($configured ? 'Schedule configured' : 'Ready to schedule')));
            $staff[] = [
                'person_id' => (int) $row['person_id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'department' => $row['department'], 'position' => $row['position'],
                'staff_assignment_id' => $row['staff_assignment_id'] === null ? null : (int) $row['staff_assignment_id'],
                'eligible' => $eligible, 'can_enroll' => $activeEmployee && $row['staff_assignment_id'] === null, 'status' => $status,
            ];
        }
        return $staff;
    }

    /** @return array{created: int, skipped: int} */
    public function enrollDepartmentStaff(string $unit, array $personIds, array $actor): array
    {
        $this->assertAvailabilityManager($unit, $actor);
        $ids = $this->selectedIds($personIds);
        $user = $this->actor($actor);
        return $this->transaction(function () use ($unit, $ids, $user): array {
            foreach ($ids as $id) {
                $this->lockedPerson($id);
            }
            $roster = array_column($this->roster($unit), null, 'person_id');
            foreach ($ids as $id) {
                if (!isset($roster[$id]) || $roster[$id]['status'] === 'Inactive employee') {
                    throw new \InvalidArgumentException('Every selected person must be an active employee in this department and tenant.');
                }
            }
            $counts = ['created' => 0, 'skipped' => 0];
            foreach ($ids as $id) {
                if ($roster[$id]['staff_assignment_id'] !== null) {
                    ++$counts['skipped'];
                    continue;
                }
                if ((new DepartmentStaffModel())->insert(['tenant_id' => CurrentTenant::id(), 'person_id' => $id,
                    'unit' => $unit, 'status' => 'active', 'enrolled_by_user_id' => (int) $user['id']]) === false) {
                    throw new \RuntimeException('Unable to enroll department staff.');
                }
                ++$counts['created'];
            }
            return $counts;
        });
    }

    private function selectedIds(mixed $values): array
    {
        if (!is_array($values) || $values === []) {
            throw new \InvalidArgumentException('Select at least one staff member.');
        }
        $ids = [];
        foreach ($values as $value) {
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new \InvalidArgumentException('Select valid staff IDs.');
            }
            $ids[(int) $value] = (int) $value;
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** Counts are assignment/weekday pairs, not windows or ineligible roster members. */
    public function applyDepartmentTemplate(string $unit, array $data, array $actor): array
    {
        $this->assertAvailabilityManager($unit, $actor);
        $providerIds = $this->selectedIds($data['staff_assignment_ids'] ?? null);
        $weekdays = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        if (!isset($data['days']) || !is_array($data['days']) || $data['days'] === []) {
            throw new \InvalidArgumentException('Choose at least one weekday.');
        }
        $days = [];
        foreach ($data['days'] as $value) {
            $day = is_string($value) ? array_search(strtolower(trim($value)), $weekdays, true) : false;
            if ($day === false) {
                throw new \InvalidArgumentException('Choose valid weekday names.');
            }
            $days[$day] = $day;
        }
        sort($days);
        $duration = filter_var($data['slot_duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($duration, [15, 30, 45, 60], true)) {
            throw new \InvalidArgumentException('Choose a valid slot duration.');
        }
        $start = $data['start_time'] ?? null;
        $end = $data['end_time'] ?? null;
        foreach ([$start, $end] as $time) {
            if (!is_string($time) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
                throw new \InvalidArgumentException('Use valid availability times (HH:mm).');
            }
        }
        $minutes = static fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        if ($end <= $start || $minutes($end) - $minutes($start) < $duration) {
            throw new \InvalidArgumentException('Availability must end after its start and contain at least one slot.');
        }
        return $this->transaction(function () use ($unit, $providerIds, $days, $duration, $start, $end): array {
            $this->lockedAssignments($unit, $providerIds);
            $counts = ['created' => 0, 'skipped' => 0];
            foreach ($providerIds as $providerId) {
                foreach ($days as $day) {
                    if ($this->availabilityModel->scoped()->where('unit', $unit)->where('staff_assignment_id', $providerId)
                        ->where('day_of_week', $day)->countAllResults() > 0) {
                        ++$counts['skipped'];
                        continue;
                    }
                    $id = $this->availabilityModel->insert(['tenant_id' => CurrentTenant::id(), 'unit' => $unit,
                        'staff_assignment_id' => $providerId, 'provider_user_id' => null, 'day_of_week' => $day, 'start_time' => $start . ':00',
                        'end_time' => $end . ':00', 'slot_duration_minutes' => $duration, 'is_active' => 1]);
                    if ($id === false) {
                        throw new \RuntimeException('Unable to save department availability.');
                    }
                    ++$counts['created'];
                }
            }
            return $counts;
        });
    }

    public function getAvailability(string $unit, array $actor): array
    {
        $this->assertAvailabilityManager($unit, $actor);
        return $this->availabilityModel->scoped()
            ->select('provider_availability.*')
            ->select("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', provider.first_name, provider.last_name)), ''), u.username) AS provider_name", false)
            ->join('users u', 'u.id = provider_availability.provider_user_id AND u.tenant_id = provider_availability.tenant_id', 'left')
            ->join('department_staff staff', 'staff.id = provider_availability.staff_assignment_id AND staff.tenant_id = provider_availability.tenant_id AND staff.unit = provider_availability.unit', 'left')
            ->join('people provider', 'provider.id = staff.person_id AND provider.tenant_id = staff.tenant_id', 'left')
            ->where('provider_availability.unit', $unit)
            ->orderBy('provider_availability.staff_assignment_id', 'ASC')
            ->orderBy('provider_availability.day_of_week', 'ASC')
            ->orderBy('provider_availability.start_time', 'ASC')->findAll();
    }

    /** V1 manages one weekday window; refuse to collapse existing multi-window schedules. */
    public function saveAvailability(string $unit, array $data, array $actor): array
    {
        $this->assertAvailabilityManager($unit, $actor);
        $providerId = filter_var($data['staff_assignment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $day = filter_var($data['day_of_week'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
        $duration = filter_var($data['slot_duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        $active = filter_var($data['is_active'] ?? null, FILTER_VALIDATE_INT);
        if ($providerId === false || $day === false || !in_array($duration, [15, 30, 45, 60], true) || !in_array($active, [0, 1], true)) {
            throw new \InvalidArgumentException('Choose a valid provider, weekday, slot duration, and active flag.');
        }
        $start = (string) ($data['start_time'] ?? '');
        $end = (string) ($data['end_time'] ?? '');
        foreach ([$start, $end] as $time) {
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
                throw new \InvalidArgumentException('Use valid availability times (HH:mm).');
            }
        }
        $minutes = static fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        if ($end <= $start || $minutes($end) - $minutes($start) < $duration) {
            throw new \InvalidArgumentException('Availability must end after its start and contain at least one slot.');
        }
        return $this->allocate($unit, $providerId, function (array $provider) use ($unit, $day, $duration, $active, $start, $end): array {
            $rows = $this->availabilityModel->scoped()->where('unit', $unit)
                ->where('staff_assignment_id', $provider['id'])->where('day_of_week', $day)->findAll();
            if (count($rows) > 1) {
                throw new \RuntimeException('This weekday has multiple existing windows. They were preserved; resolve them before using the single-window editor.', 409);
            }
            $payload = ['start_time' => $start . ':00', 'end_time' => $end . ':00',
                'slot_duration_minutes' => $duration, 'is_active' => $active];
            if ($rows === []) {
                $id = $this->availabilityModel->insert($payload + ['tenant_id' => CurrentTenant::id(),
                    'unit' => $unit, 'staff_assignment_id' => (int) $provider['id'], 'provider_user_id' => null, 'day_of_week' => $day]);
                if ($id === false) {
                    throw new \RuntimeException('Unable to save provider availability.');
                }
            } else {
                $id = (int) $rows[0]['id'];
                if (!$this->availabilityModel->scoped()->where('unit', $unit)->where('staff_assignment_id', $provider['id'])->update($id, $payload)) {
                    throw new \RuntimeException('Unable to save provider availability.');
                }
            }
            return $this->availabilityModel->scoped()->where('unit', $unit)->find($id);
        });
    }

    public function getAvailableSlots(string $unit, int $staffAssignmentId, string $date): array
    {
        $this->assertUnit($unit);
        $this->validateDate($date);
        $this->staffAssignment($unit, $staffAssignmentId);
        $slots = [];
        $now = SchedulingClock::now();
        foreach ($this->windows($unit, $staffAssignmentId, $date) as $window) {
            foreach ($this->slotIntervals($date, $window) as [$current, $next]) {
                $startsAt = $current->format('Y-m-d H:i:s');
                $endsAt = $next->format('Y-m-d H:i:s');
                $slots[$startsAt] = ['slot_time' => $current->format('H:i'), 'starts_at' => $startsAt,
                    'ends_at' => $endsAt, 'available' => $current >= $now && !$this->appointmentModel->hasConflict($staffAssignmentId, $startsAt, $endsAt)];
            }
        }
        ksort($slots);
        return array_values($slots);
    }

    /** Shared grid for slot display and locked confirmation, including legacy minimum durations. */
    private function slotIntervals(string $date, array $window): \Generator
    {
        $current = new \DateTimeImmutable($date . ' ' . $window['start_time'], SchedulingClock::timezone());
        $end = new \DateTimeImmutable($date . ' ' . $window['end_time'], SchedulingClock::timezone());
        $step = max(10, (int) ($window['slot_duration_minutes'] ?? 30));
        while (($next = $current->modify('+' . $step . ' minutes')) <= $end) {
            yield [$current, $next];
            $current = $next;
        }
    }

    public function confirmAppointment(string $unit, int $id, array $data, array $actor): array
    {
        $this->assertStaff($unit, $actor);
        $user = $this->actor($actor);
        $this->assertStaff($unit, $user);
        [$start, $end] = $this->interval((string) ($data['starts_at'] ?? ''), (string) ($data['ends_at'] ?? ''));
        $providerId = $this->selectedIds([$data['staff_assignment_id'] ?? null])[0];
        $version = filter_var($data['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($version === false) {
            throw new \InvalidArgumentException('An appointment version is required.');
        }
        return $this->allocate($unit, $providerId, function (array $provider) use ($unit, $id, $start, $end, $version, $user): array {
            $appt = $this->lockedAppointment($unit, $id);
            if ($appt['status'] !== 'requested' || (int) $appt['version'] !== $version) {
                throw new \RuntimeException('Appointment changed. Reload before confirming.', 409);
            }
            $this->assertNotPast($start);
            $this->person((int) $appt['person_id']);
            $this->assertCurrentSlot($unit, (int) $provider['id'], $start, $end);
            $this->assertNoConflict((int) $provider['id'], $start, $end, $id);
            $now = SchedulingClock::timestamp();
            $this->save($unit, $id, [
                'staff_assignment_id' => (int) $provider['id'], 'starts_at' => $start, 'ends_at' => $end,
                'local_date' => substr($start, 0, 10), 'status' => 'confirmed',
                'confirmed_by_user_id' => (int) $user['id'], 'confirmed_at' => $now,
                'assigned_by_user_id' => (int) $user['id'], 'assigned_at' => $now, 'version' => $version + 1,
            ]);
            $confirmed = $this->appointment($unit, $id);
            (new \App\Services\NotificationService())->notifyConfirmation($confirmed);
            return $confirmed;
        });
    }

    /** Trusted service API; allocation uses the same assignment, grid, and person mutex as confirmation. */
    public function bookAppointment(array $data): array
    {
        $unit = (string) $data['unit'];
        $this->assertUnit($unit);
        $this->person((int) $data['person_id']);
        if (isset($data['booked_by_user_id'])) {
            $this->actor(['id' => $data['booked_by_user_id']]);
        }
        [$start, $end] = $this->interval($data['starts_at'], $data['ends_at']);
        $assignmentId = $this->selectedIds([$data['staff_assignment_id'] ?? null])[0];
        return $this->allocate($unit, $assignmentId, function (array $provider) use ($data, $unit, $start, $end): array {
            $this->assertNotPast($start);
            $this->assertCurrentSlot($unit, (int) $provider['id'], $start, $end);
            $this->assertNoConflict((int) $provider['id'], $start, $end);
            $id = $this->appointmentModel->insert([
                'tenant_id' => CurrentTenant::id(), 'unit' => $unit, 'person_id' => (int) $data['person_id'],
                'staff_assignment_id' => (int) $provider['id'], 'provider_user_id' => null, 'starts_at' => $start, 'ends_at' => $end,
                'local_date' => substr($start, 0, 10), 'status' => 'scheduled', 'type' => $data['type'] ?? 'routine',
                'reason' => $data['reason'] ?? null, 'booked_by_user_id' => $data['booked_by_user_id'] ?? null,
            ]);
            if ($id === false) {
                throw new \RuntimeException('Unable to book appointment.');
            }
            return $this->appointment($unit, (int) $id);
        });
    }

    /** Optional context is only omitted by trusted legacy callers, never HTTP handlers. */
    public function cancelAppointment(int $appointmentId, string $reason, ?string $unit = null, ?array $actor = null, bool $personal = false): bool
    {
        if (mb_strlen(trim($reason)) > 255) {
            throw new \InvalidArgumentException('Cancellation reason must be at most 255 characters.');
        }
        $user = $personal ? $this->assertPersonalPermission($actor ?? [], 'portal.appointments.create')
            : ($actor !== null ? $this->actor($actor) : null);
        if ($actor !== null && !$personal) {
            $this->assertStaff((string) $unit, $user);
        }
        if ($unit === null) {
            $appt = $this->appointmentModel->scoped()->find($appointmentId);
            if ($appt === null) {
                throw new \InvalidArgumentException('Appointment not found.', 404);
            }
            $unit = $appt['unit'];
        }
        return $this->transaction(function () use ($appointmentId, $reason, $unit, $user, $personal, $actor): bool {
            $appt = $this->lockedAppointment($unit, $appointmentId);
            if ($personal) {
                $user = $this->assertPersonalPermission($actor ?? [], 'portal.appointments.create');
            }
            if ($personal && ($user === null || empty($user['person_id']) || (int) $appt['person_id'] !== (int) $user['person_id'])) {
                throw new \DomainException('You may only cancel your own appointments.', 403);
            }
            if (!in_array($appt['status'], ['requested', 'scheduled', 'confirmed'], true)) {
                throw new \RuntimeException('This appointment can no longer be cancelled.', 409);
            }
            $this->person((int) $appt['person_id']);
            $this->save($unit, $appointmentId, ['status' => 'cancelled', 'cancellation_reason' => trim($reason), 'version' => (int) $appt['version'] + 1]);
            return true;
        });
    }

    /** Refresh grants from storage, never trust permissions carried in a submitted/session actor. */
    public function assertSchedulingPermission(string $unit, array $actor, bool $queue = false): void
    {
        $this->assertStaff($unit, $actor);
        $user = (new \App\Services\AuthenticatedUser())->forIdentity((int) ($actor['id'] ?? 0), CurrentTenant::id());
        $permission = $queue ? $unit . '.queue.manage' : ($unit === 'clinic' ? 'clinic.appointments.write' : 'guidance.schedule.manage');
        if ($user === null || ($user['role'] !== 'superadmin' && !in_array($permission, $user['permissions'], true))) {
            throw new \DomainException('Permission required: ' . $permission, 403);
        }
    }

    public function markNoShow(string $unit, int $id, int $version, array $actor): array
    {
        return (new QueueService())->markAppointmentNoShow($unit, $id, $version, $actor);
    }

    public function getAvailabilityExceptions(string $unit, array $actor): array
    {
        $this->assertAvailabilityManager($unit, $actor);
        $this->assertSchedulingPermission($unit, $actor);
        return $this->exceptionModel->forUnit($unit);
    }

    /** Explicit create/update/delete with a snapshot token; appointment reservations are untouched. */
    public function saveAvailabilityException(string $unit, array $data, array $actor, bool $remove = false): array
    {
        $this->assertAvailabilityManager($unit, $actor);
        $this->assertSchedulingPermission($unit, $actor);
        $providerId = $this->selectedIds([$data['staff_assignment_id'] ?? null])[0];
        $date = (string) ($data['exception_date'] ?? '');
        $this->validateDate($date);
        if ($date < SchedulingClock::today()) {
            throw new \InvalidArgumentException('Availability exceptions may only change today or future Manila dates.');
        }
        $payload = $remove ? [] : $this->exceptionPayload($data);
        $id = filter_var($data['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($id === false || ($remove && $id < 1)) {
            throw new \InvalidArgumentException('Choose a valid exception.');
        }
        return $this->allocate($unit, $providerId, function (array $provider) use ($unit, $actor, $date, $payload, $data, $id, $remove): array {
            $this->assertAvailabilityManager($unit, $actor);
            $this->assertSchedulingPermission($unit, $actor);
            if (!$remove && $payload['is_unavailable'] === 0 && $this->availabilityModel->scoped()
                ->where('unit', $unit)->where('staff_assignment_id', $provider['id'])->where('is_active', 1)->countAllResults() === 0) {
                throw new \InvalidArgumentException('Configure an active recurring schedule before adding custom hours.');
            }
            $row = $this->exceptionModel->findException((int) $provider['id'], $date);
            if (($id === 0 && $row !== null) || ($id > 0 && ($row === null || (int) $row['id'] !== $id
                || !hash_equals(AvailabilityExceptionModel::snapshotToken($row), (string) ($data['token'] ?? ''))))) {
                throw new \RuntimeException('Availability exception changed. Reload before saving.', 409);
            }
            if ($remove) {
                if (!$this->exceptionModel->scoped()->where('staff_assignment_id', $provider['id'])->delete($id)) {
                    throw new \RuntimeException('Unable to remove availability exception.');
                }
                return ['id' => $id];
            }
            if ($id === 0) {
                $savedId = $this->exceptionModel->insert($payload + ['tenant_id' => CurrentTenant::id(),
                    'staff_assignment_id' => (int) $provider['id'], 'provider_user_id' => null, 'exception_date' => $date]);
                if ($savedId === false) {
                    throw new \RuntimeException('Unable to create availability exception.');
                }
            } elseif (!$this->exceptionModel->scoped()->where('staff_assignment_id', $provider['id'])->update($id, $payload)) {
                throw new \RuntimeException('Unable to save availability exception.');
            }
            return $this->exceptionModel->findException((int) $provider['id'], $date);
        });
    }

    private function exceptionPayload(array $data): array
    {
        $type = (string) ($data['exception_type'] ?? '');
        $reason = trim((string) ($data['reason'] ?? ''));
        if (!in_array($type, ['leave', 'closed_day', 'custom_hours'], true) || $reason === '' || mb_strlen($reason) > 255) {
            throw new \InvalidArgumentException('Choose leave, closed day or custom hours and provide a reason of at most 255 characters.');
        }
        $start = null;
        $end = null;
        if ($type === 'custom_hours') {
            $start = (string) ($data['custom_start_time'] ?? '');
            $end = (string) ($data['custom_end_time'] ?? '');
            foreach ([$start, $end] as $time) {
                if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
                    throw new \InvalidArgumentException('Use valid custom hours (HH:mm).');
                }
            }
            $minutes = static fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
            if ($minutes($end) - $minutes($start) < 30) {
                throw new \InvalidArgumentException('Custom hours must contain at least one 30-minute slot on the same day.');
            }
            $start .= ':00';
            $end .= ':00';
        }
        // Both leave and closed-day are full-day unavailability in the existing schema.
        return ['is_unavailable' => $type === 'custom_hours' ? 0 : 1,
            'custom_start_time' => $start, 'custom_end_time' => $end, 'reason' => $reason];
    }

    private function actor(array $actor): array
    {
        $user = (new UserModel())->scoped()->where('status', 'active')->find((int) ($actor['id'] ?? 0));
        if ($user === null) {
            throw new \DomainException('A valid active account in this tenant is required.', 403);
        }
        return $user;
    }

    /** Resolve a schedulable employee without consulting a login account. */
    public function staffAssignment(string $unit, int $id): array
    {
        $this->assertUnit($unit);
        $assignment = (new DepartmentStaffModel())->scoped()->where('unit', $unit)->where('status', 'active')->find($id);
        if ($assignment !== null) {
            $employee = (new PersonModel())->scoped()
                ->select('people.id, ep.department, ep.employment_status')
                ->join('employee_profiles ep', 'ep.person_id = people.id AND ep.tenant_id = people.tenant_id')
                ->where('people.kind', 'employee')->find((int) $assignment['person_id']);
            if ($employee !== null && strtolower(trim((string) $employee['employment_status'])) === 'active'
                && strtolower(trim((string) $employee['department'])) === ($unit === 'clinic' ? 'health services' : 'guidance')) {
                return $assignment;
            }
        }
        throw new \InvalidArgumentException('An active enrolled employee in this department and tenant is required.');
    }

    private function assertCurrentSlot(string $unit, int $id, string $start, string $end): void
    {
        $date = substr($start, 0, 10);
        foreach ($this->windows($unit, $id, $date) as $window) {
            foreach ($this->slotIntervals($date, $window) as [$slotStart, $slotEnd]) {
                if ($start === $slotStart->format('Y-m-d H:i:s') && $end === $slotEnd->format('Y-m-d H:i:s')) {
                    return;
                }
            }
        }
        throw new \InvalidArgumentException('The selected interval is not a current provider slot. Reload availability and choose a slot.');
    }

    private function windows(string $unit, int $providerId, string $date): array
    {
        $templates = array_values(array_filter($this->availabilityModel->getActiveForProvider($providerId, (int) (new \DateTimeImmutable($date, SchedulingClock::timezone()))->format('w')),
            static fn (array $row): bool => $row['unit'] === $unit));
        $exception = $this->exceptionModel->findException($providerId, $date);
        if ($exception !== null && !empty($exception['is_unavailable'])) {
            return [];
        }
        if ($exception !== null && !empty($exception['custom_start_time']) && !empty($exception['custom_end_time'])) {
            // A provider/date exception must not grant membership in a different department.
            $configured = $this->availabilityModel->scoped()->where('staff_assignment_id', $providerId)
                ->where('unit', $unit)->where('is_active', 1)->countAllResults() > 0;
            return $configured ? [['start_time' => $exception['custom_start_time'], 'end_time' => $exception['custom_end_time'], 'slot_duration_minutes' => 30]] : [];
        }
        return $templates;
    }

    private function assertNotPast(string $start): void
    {
        // Persisted appointment strings are Manila wall-clock values, not UTC instants.
        if (new \DateTimeImmutable($start, SchedulingClock::timezone()) < SchedulingClock::now()) {
            throw new \InvalidArgumentException('Appointment time must not be in the past.');
        }
    }

    private function interval(string $start, string $end): array
    {
        $values = [];
        foreach ([$start, $end] as $value) {
            $value = str_replace('T', ' ', $value);
            if (strlen($value) === 16) {
                $value .= ':00';
            }
            if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value)) {
                throw new \InvalidArgumentException('Use valid appointment start and end timestamps.');
            }
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, SchedulingClock::timezone());
            if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
                throw new \InvalidArgumentException('Use valid appointment start and end timestamps.');
            }
            $values[] = $value;
        }
        if ($values[1] <= $values[0]) {
            throw new \InvalidArgumentException('Appointment end must be after its start.');
        }
        return $values;
    }

    private function assertNoConflict(int $providerId, string $start, string $end, ?int $ignore = null): void
    {
        if ($this->appointmentModel->hasConflict($providerId, $start, $end, $ignore)) {
            throw new \RuntimeException('The selected time slot is already booked.', 409);
        }
    }

    private function save(string $unit, int $id, array $data): void
    {
        if (!$this->appointmentModel->scoped()->where('unit', $unit)->update($id, $data)) {
            throw new \RuntimeException('Unable to update appointment.');
        }
    }

    private function lockedAppointment(string $unit, int $id): array
    {
        $this->assertUnit($unit);
        $db = \Config\Database::connect();
        $table = $db->prefixTable('appointments');
        $appt = $db->query("SELECT * FROM {$table} WHERE id = ? AND tenant_id = ? AND unit = ? AND deleted_at IS NULL FOR UPDATE", [$id, CurrentTenant::id(), $unit])->getRowArray();
        if ($appt === null) {
            throw new \InvalidArgumentException('Appointment not found.', 404);
        }
        return $appt;
    }

    private function allocate(string $unit, int $assignmentId, callable $operation): array
    {
        return $this->transaction(function () use ($unit, $assignmentId, $operation): array {
            return $operation($this->lockedAssignments($unit, [$assignmentId])[0]);
        });
    }

    private function lockedPerson(int $id): void
    {
        $db = \Config\Database::connect();
        $table = $db->prefixTable('people');
        if ($db->query("SELECT id FROM {$table} WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE",
            [$id, CurrentTenant::id()])->getRowArray() === null) {
            throw new \InvalidArgumentException('A valid person in this tenant is required.');
        }
    }

    private function lockedAssignments(string $unit, array $ids): array
    {
        $this->assertUnit($unit);
        $rows = (new DepartmentStaffModel())->scoped()->where('unit', $unit)->whereIn('id', $ids)
            ->orderBy('person_id')->orderBy('id')->findAll();
        if (count($rows) !== count($ids)) {
            throw new \InvalidArgumentException('Choose valid department staff assignments.');
        }
        // Acquire every person mutex before any assignment lock. Assignment IDs alone do not
        // serialize allocations across units, and interleaving both lock kinds can deadlock batches.
        foreach (array_unique(array_column($rows, 'person_id')) as $personId) {
            $this->lockedPerson((int) $personId);
        }
        $db = \Config\Database::connect();
        $table = $db->prefixTable('department_staff');
        foreach ($rows as $row) {
            $locked = $db->query("SELECT * FROM {$table} WHERE id = ? AND tenant_id = ? FOR UPDATE",
                [$row['id'], CurrentTenant::id()])->getRowArray();
            if ($locked === null || (int) $locked['person_id'] !== (int) $row['person_id']) {
                throw new \RuntimeException('Staff assignment changed. Reload before scheduling.', 409);
            }
            $this->staffAssignment($unit, (int) $row['id']);
        }
        return $rows;
    }

    private function transaction(callable $operation): mixed
    {
        $db = \Config\Database::connect();
        if ($db->transDepth !== 0) {
            throw new \RuntimeException('Appointment changes require an independent transaction.');
        }
        // Fresh overlap reads after waiting for the provider mutex, including under MySQL's default RR.
        $db->resetTransStatus();
        if ($db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || !$db->transBegin()) {
            throw new \RuntimeException('Unable to begin appointment changes.');
        }
        try {
            $result = $operation();
            if (!$db->transStatus() || !$db->transCommit()) {
                throw new \RuntimeException('Unable to save appointment changes.');
            }
            return $result;
        } catch (\Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();
            throw $e;
        }
    }
}

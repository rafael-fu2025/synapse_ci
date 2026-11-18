<?php

declare(strict_types=1);

namespace App\Integrations\Mis;

/**
 * Maps bare MIS profiles and login responses containing data or data.data.
 *
 * Omitted optional fields preserve existing values on partial updates. Audience
 * presence markers distinguish omission from explicit unknowns that revoke eligibility.
 * Callers creating a person must supply valid required names. raw_hash fingerprints
 * only mapped fields and source presence, never credentials or raw JSON.
 */
final class MisProfileMapper
{
    private const STUDENT_ID_KEYS = ['student_id', 'studentId', 'student_number', 'student_no', 'id'];
    private const EMPLOYEE_ID_KEYS = ['employee_id', 'employeeId', 'employee_number', 'employee_no', 'emp_id', 'empId', 'emp_no', 'staff_id', 'id_number', 'id'];
    private const ENTITY_WRAPPERS = ['employee', 'student', 'user', 'profile'];

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     mis_id: string,
     *     first_name: ?string,
     *     middle_name: ?string,
     *     last_name: ?string,
     *     program: ?string,
     *     year_level: ?int,
     *     section: ?string,
     *     department: ?string,
     *     enrollment_type: ?string,
     *     is_graduating: ?bool,
     *     enrollment_type_provided: bool,
     *     is_graduating_provided: bool,
     *     raw_hash: string
     * }
     * @throws MisUnavailableException When the profile is malformed or lacks an official identifier.
     */
    public function mapStudent(array $payload): array
    {
        $data = $this->profile($payload, self::STUDENT_ID_KEYS);
        $misId = $this->string($data, ...self::STUDENT_ID_KEYS);
        if ($misId === null) {
            throw new MisUnavailableException();
        }

        return $this->withHash([
            'mis_id'      => $misId,
            'first_name'  => $this->string($data, 'first_name', 'firstName', 'fname', 'firstname'),
            'middle_name' => $this->string($data, 'middle_name', 'middleName', 'middlename', 'mname'),
            'last_name'   => $this->string($data, 'last_name', 'lastName', 'lname', 'lastname'),
            'program'     => $this->string($data, 'program', 'course', 'program_name'),
            'year_level'  => $this->int($data, 'level', 'year_level', 'yearLevel'),
            'section'     => $this->string($data, 'section', 'block', 'block_section'),
            'department'  => $this->nestedString(
                $data,
                'department',
                ['department', 'department_name', 'departmentName', 'department_code'],
                ['name', 'department_name', 'title', 'code', 'department_code']
            ),
            'enrollment_type' => $this->enrollmentType($data),
            'is_graduating' => $this->bool($data, 'is_graduating'),
            'enrollment_type_provided' => array_key_exists('student_type', $data) || array_key_exists('enrollment_type', $data),
            'is_graduating_provided' => array_key_exists('is_graduating', $data),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     mis_id: string,
     *     first_name: ?string,
     *     middle_name: ?string,
     *     last_name: ?string,
     *     department: ?string,
     *     position: ?string,
     *     employment_status: ?string,
     *     is_teaching: ?bool,
     *     raw_hash: string
     * }
     * @throws MisUnavailableException When the profile is malformed or lacks an official identifier.
     */
    public function mapEmployee(array $payload): array
    {
        $data = $this->profile($payload, self::EMPLOYEE_ID_KEYS);
        $misId = $this->string($data, ...self::EMPLOYEE_ID_KEYS);
        if ($misId === null) {
            throw new MisUnavailableException();
        }

        $status = $this->string($data, 'employment_status', 'employmentStatus', 'status');
        $status = $status === null ? null : strtolower($status);

        return $this->withHash([
            'mis_id'            => $misId,
            'first_name'        => $this->string($data, 'first_name', 'firstName', 'fname', 'firstname'),
            'middle_name'       => $this->string($data, 'middle_name', 'middleName', 'middlename', 'mname'),
            'last_name'         => $this->string($data, 'last_name', 'lastName', 'lname', 'lastname'),
            'department'        => $this->nestedString(
                $data,
                'department',
                ['department', 'department_name', 'departmentName', 'department_code', 'dept_name', 'dept'],
                ['name', 'department_name', 'title', 'code', 'department_code']
            ),
            'position'          => $this->nestedString(
                $data,
                'position',
                ['position', 'job_title', 'position_name', 'designation', 'title'],
                ['name', 'position_name', 'title']
            ),
            'employment_status' => in_array($status, ['active', 'inactive', 'on_leave'], true) ? $status : null,
            'is_teaching'       => $this->bool($data, 'is_teaching', 'isTeaching', 'teaching', 'is_faculty'),
        ]);
    }

    /**
     * Extracts a valid email address from an MIS login response without altering canonical mapped hashes.
     *
     * @param array<string, mixed> $payload
     */
    public function extractEmail(array $payload): ?string
    {
        try {
            $data = $this->profile($payload, array_values(array_unique([...self::STUDENT_ID_KEYS, ...self::EMPLOYEE_ID_KEYS])));
        } catch (MisUnavailableException) {
            return null;
        }

        $candidate = $this->string($data, 'email_address', 'emailAddress', 'email', 'university_email', 'work_email');
        if ($candidate === null || mb_strlen($candidate, 'UTF-8') > 150) {
            return null;
        }
        $normalized = strtolower($candidate);

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) === false ? null : $normalized;
    }

    private function enrollmentType(array $data): ?string
    {
        // Explicit contract fields only; conflicting classifications are not authoritative.
        $types = [];
        foreach (['student_type', 'enrollment_type'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (! is_string($value)) {
                return null;
            }
            $type = strtolower(trim($value));
            if (! in_array($type, ['new', 'continuing', 'transferee'], true)) {
                return null;
            }
            $types[] = $type;
        }

        return count(array_unique($types)) === 1 ? $types[0] : null;
    }

    /** @param list<string> $idKeys */
    private function profile(array $payload, array $idKeys = []): array
    {
        // An explicit data envelope must be valid; never fall back to its tokens or id.
        for ($depth = 0; $depth < 2 && array_key_exists('data', $payload); $depth++) {
            if (! is_array($payload['data'])) {
                throw new MisUnavailableException();
            }
            $payload = $payload['data'];
        }

        if ($payload === [] || array_key_exists('data', $payload)) {
            throw new MisUnavailableException();
        }
        $this->assertAssociativePayload($payload);

        // Unwrap a nested entity wrapper (e.g., data.employee) only when no explicit ID exists at this level.
        if ($idKeys !== [] && ! $this->hasAnyKey($payload, array_values(array_diff($idKeys, ['id'])))) {
            foreach (self::ENTITY_WRAPPERS as $wrapper) {
                if (array_key_exists($wrapper, $payload)) {
                    $inner = $payload[$wrapper];
                    if (is_array($inner) && $inner !== [] && ! array_key_exists('data', $inner)) {
                        $this->assertAssociativePayload($inner);
                        return $inner;
                    }
                }
            }
        }

        return $payload;
    }

    private function assertAssociativePayload(array $payload): void
    {
        foreach (array_keys($payload) as $key) {
            if (! is_string($key)) {
                throw new MisUnavailableException();
            }
        }
    }

    /** @param list<string> $keys */
    private function hasAnyKey(array $payload, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $scalarKeys
     * @param list<string> $nestedKeys
     */
    private function nestedString(array $data, string $objectKey, array $scalarKeys, array $nestedKeys): ?string
    {
        $scalar = $this->string($data, ...$scalarKeys);
        if ($scalar !== null) {
            return $scalar;
        }
        $nested = $data[$objectKey] ?? null;
        if (is_array($nested) && ! array_is_list($nested)) {
            return $this->string($nested, ...$nestedKeys);
        }
        return null;
    }

    private function withHash(array $mapped): array
    {
        // Keyed serialization preserves field boundaries, nulls and scalar types.
        $mapped['raw_hash'] = hash('sha256', serialize($mapped));

        return $mapped;
    }

    private function string(array $data, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $val = $data[$key] ?? null;
            if (is_string($val)) {
                $trimmed = trim($val);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            } elseif (is_int($val) && $val > 0) {
                return (string) $val;
            }
        }
        return null;
    }

    private function int(array $data, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            $val = $data[$key] ?? null;
            if (is_int($val)) {
                return $val;
            }
            if (is_string($val) && preg_match('/^\d+$/', trim($val))) {
                return (int) trim($val);
            }
        }
        return null;
    }

    private function bool(array $data, string ...$keys): ?bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $val = $data[$key];
            if (is_bool($val)) {
                return $val;
            }
            if (is_int($val) && ($val === 0 || $val === 1)) {
                return $val === 1;
            }
            if (is_string($val)) {
                $norm = strtolower(trim($val));
                if (in_array($norm, ['true', 'yes', 'y', '1'], true)) {
                    return true;
                }
                if (in_array($norm, ['false', 'no', 'n', '0'], true)) {
                    return false;
                }
            }
        }
        return null;
    }
}

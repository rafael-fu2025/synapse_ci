<?php

declare(strict_types=1);

namespace App\Integrations\Mis;

final class FakeMisClient implements MisClientInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $students = [
        '20230001' => [
            'student_id'  => '20230001',
            'first_name'  => 'JUAN',
            'middle_name' => 'REYES ',
            'last_name'   => 'DELA CRUZ',
            'program'     => 'BSIT',
            'level'       => '3',
            'section'     => 'Section A',
            'department'  => 'CCS',
        ],
        '20240102' => [
            'student_id'  => '20240102',
            'first_name'  => 'MARIA CLARA',
            'middle_name' => 'DE LOS',
            'last_name'   => 'SANTOS',
            'program'     => 'BSN',
            'level'       => '2',
            'section'     => 'Block 1',
            'department'  => 'CON',
        ],
        '20250345' => [
            'student_id'  => '20250345',
            'first_name'  => 'PEDRO',
            'middle_name' => '',
            'last_name'   => 'PENDUKO',
            'program'     => 'BSBA',
            'level'       => '1',
            'section'     => 'Block C',
            'department'  => 'CBA',
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $employees = [
        '20269001' => [
            'employee_id'       => '20269001',
            'first_name'        => 'ROBERTO',
            'middle_name'       => 'M.',
            'last_name'         => 'HERNANDEZ',
            'department'        => 'College of Nursing',
            'position'          => 'Associate Professor',
            'employment_status' => 'active',
            'is_teaching'       => true,
        ],
        '20269002' => [
            'employee_id'       => '20269002',
            'first_name'        => 'ELENA',
            'middle_name'       => 'S.',
            'last_name'         => 'BAUTISTA',
            'department'        => 'University Health Services',
            'position'          => 'Head Nurse',
            'employment_status' => 'active',
            'is_teaching'       => false,
        ],
        '20269003' => [
            'employee_id'       => '20269003',
            'first_name'        => 'GABRIEL',
            'middle_name'       => 'A.',
            'last_name'         => 'REYES',
            'department'        => 'Guidance & Counseling Center',
            'position'          => 'Senior Guidance Counsellor',
            'employment_status' => 'active',
            'is_teaching'       => false,
        ],
    ];

    public function studentLogin(string $studentId, string $password): ?array
    {
        $id = trim($studentId);
        if (isset($this->students[$id])) {
            return [
                'data'          => $this->students[$id],
                'access_token'  => 'fake_access_token_' . bin2hex(random_bytes(16)),
                'refresh_token' => 'fake_refresh_token_' . bin2hex(random_bytes(16)),
                'expires_at'    => date('Y-m-d H:i:s', time() + 3600),
            ];
        }
        return null;
    }

    public function employeeLogin(string $employeeId, string $password): ?array
    {
        $id = trim($employeeId);
        if (isset($this->employees[$id])) {
            return [
                'data'          => $this->employees[$id],
                'access_token'  => 'fake_access_token_' . bin2hex(random_bytes(16)),
                'refresh_token' => 'fake_refresh_token_' . bin2hex(random_bytes(16)),
                'expires_at'    => date('Y-m-d H:i:s', time() + 3600),
            ];
        }
        return null;
    }

    public function searchStudents(string $query, int $limit = 25, int $page = 1): array
    {
        $q = strtolower(trim($query));
        $matches = [];
        foreach ($this->students as $student) {
            if ($q === ''
                || str_contains(strtolower($student['student_id']), $q)
                || str_contains(strtolower($student['first_name']), $q)
                || str_contains(strtolower($student['last_name']), $q)
            ) {
                $matches[] = $student;
            }
        }

        return [
            'data'         => array_slice($matches, ($page - 1) * $limit, $limit),
            'limit'        => $limit,
            'current_page' => $page,
            'max_page'     => (int) ceil(count($matches) / max(1, $limit)),
        ];
    }

    public function searchEmployees(string $query, int $limit = 25, int $page = 1): array
    {
        $q = strtolower(trim($query));
        $matches = [];
        foreach ($this->employees as $emp) {
            if ($q === ''
                || str_contains(strtolower($emp['employee_id']), $q)
                || str_contains(strtolower($emp['first_name']), $q)
                || str_contains(strtolower($emp['last_name']), $q)
            ) {
                $matches[] = $emp;
            }
        }

        return [
            'data'         => array_slice($matches, ($page - 1) * $limit, $limit),
            'limit'        => $limit,
            'current_page' => $page,
            'max_page'     => (int) ceil(count($matches) / max(1, $limit)),
        ];
    }

    public function fetchDepartments(): array
    {
        return [
            ['code' => 'CCS', 'name' => 'College of Computer Studies'],
            ['code' => 'CON', 'name' => 'College of Nursing'],
            ['code' => 'CBA', 'name' => 'College of Business Administration'],
            ['code' => 'CAS', 'name' => 'College of Arts and Sciences'],
            ['code' => 'UHS', 'name' => 'University Health Services'],
            ['code' => 'GCC', 'name' => 'Guidance & Counseling Center'],
            ['code' => 'FAC', 'name' => 'Facilities & Maintenance (BMG)'],
        ];
    }

    public function fetchPrograms(): array
    {
        return [
            ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology', 'department_code' => 'CCS'],
            ['code' => 'BSCS', 'name' => 'Bachelor of Science in Computer Science', 'department_code' => 'CCS'],
            ['code' => 'BSN',  'name' => 'Bachelor of Science in Nursing', 'department_code' => 'CON'],
            ['code' => 'BSBA', 'name' => 'Bachelor of Science in Business Administration', 'department_code' => 'CBA'],
            ['code' => 'BEED', 'name' => 'Bachelor of Elementary Education', 'department_code' => 'CAS'],
        ];
    }

    public function fetchYearLevels(): array
    {
        return [
            ['code' => '1', 'name' => '1st Year'],
            ['code' => '2', 'name' => '2nd Year'],
            ['code' => '3', 'name' => '3rd Year'],
            ['code' => '4', 'name' => '4th Year'],
            ['code' => 'SHS11', 'name' => 'Grade 11 (Senior High)'],
            ['code' => 'SHS12', 'name' => 'Grade 12 (Senior High)'],
        ];
    }
}

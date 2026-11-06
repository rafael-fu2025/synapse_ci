<?php

declare(strict_types=1);

namespace App\Integrations\Mis;

interface MisClientInterface
{
    /**
     * Authenticate student credentials against the MIS API.
     * Returns the raw payload on success, or null only for credential rejection.
     * Throws MisUnavailableException for configuration, transport, or upstream failures.
     *
     * @return array<string, mixed>|null
     */
    public function studentLogin(string $studentId, string $password): ?array;

    /**
     * Authenticate employee credentials against the MIS API.
     * Returns the raw payload on success, or null only for credential rejection.
     * Throws MisUnavailableException for configuration, transport, or upstream failures.
     *
     * @return array<string, mixed>|null
     */
    public function employeeLogin(string $employeeId, string $password): ?array;

    /**
     * Search student records from MIS directory.
     *
     * @return array<string, mixed>
     */
    public function searchStudents(string $query, int $limit = 25, int $page = 1): array;

    /**
     * Search employee records from MIS directory.
     *
     * @return array<string, mixed>
     */
    public function searchEmployees(string $query, int $limit = 25, int $page = 1): array;

    /**
     * Retrieve authoritative MIS department registry.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function fetchDepartments(): array;

    /** Unfiltered directory pages, never search results. Documented page size is 25.
     * @return array{data:array, current_page:int, max_page:int, limit:int, total?:int}
     * Implementations must throw on unavailable, malformed or incomplete responses.
     */
    public function fetchStudentsPage(int $page = 1, int $limit = 25): array;

    /** @return array{data:array, current_page:int, max_page:int, limit:int, total?:int} */
    public function fetchEmployeesPage(int $page = 1, int $limit = 25): array;

    /**
     * Retrieve authoritative MIS academic program registry.
     *
     * @return array<int, array{code: string, name: string, department_code?: string}>
     */
    public function fetchPrograms(): array;

    /**
     * Retrieve authoritative MIS year level registry.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function fetchYearLevels(): array;
}

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

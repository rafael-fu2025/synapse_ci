<?php

declare(strict_types=1);

namespace App\Integrations\Mis;

/** Fail closed when real MIS authentication and explicit development fixtures are disabled. */
final class DisabledMisClient implements MisClientInterface
{
    public function studentLogin(string $studentId, string $password): ?array
    {
        throw new MisUnavailableException();
    }

    public function employeeLogin(string $employeeId, string $password): ?array
    {
        throw new MisUnavailableException();
    }

    public function searchStudents(string $query, int $limit = 25, int $page = 1): array
    {
        throw new MisUnavailableException();
    }

    public function searchEmployees(string $query, int $limit = 25, int $page = 1): array
    {
        throw new MisUnavailableException();
    }

    public function fetchDepartments(): array
    {
        throw new MisUnavailableException();
    }

    public function fetchPrograms(): array
    {
        throw new MisUnavailableException();
    }

    public function fetchYearLevels(): array
    {
        throw new MisUnavailableException();
    }
}

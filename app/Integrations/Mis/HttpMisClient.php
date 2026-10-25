<?php

declare(strict_types=1);

namespace App\Integrations\Mis;

final class HttpMisClient implements MisClientInterface
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private \Closure $transport;

    public function __construct(
        ?string $baseUrl = null,
        ?string $apiKey = null,
        ?int $timeout = null,
        ?callable $transport = null
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (string) env('FUMIS_BASE_URL', 'https://mis.foundationu.com/sandbox'), '/');
        $this->apiKey = $apiKey ?? (string) env('FUMIS_API_KEY', '');
        $configuredTimeout = filter_var(env('FUMIS_TIMEOUT_SECONDS', 10), FILTER_VALIDATE_INT);
        $this->timeout = max(2, min(60, $timeout ?? ($configuredTimeout === false ? 10 : $configuredTimeout)));
        $this->transport = $transport === null
            ? $this->curlLoginRequest(...)
            : \Closure::fromCallable($transport);
    }

    public function studentLogin(string $studentId, string $password): ?array
    {
        return $this->login('/api/v1/students/login', [
            'student_id' => trim($studentId),
            'password'   => $password,
        ]);
    }

    public function employeeLogin(string $employeeId, string $password): ?array
    {
        return $this->login('/api/v1/employees/login', [
            'employee_id' => trim($employeeId),
            'password'    => $password,
        ]);
    }

    /**
     * The injectable transport receives method, URL, header lines, encoded
     * JSON body (null for GET) and timeout, and returns status and body.
     *
     * @param array<string, string> $body
     * @return array<string, mixed>|null
     */
    private function login(string $path, array $body): ?array
    {
        $url = parse_url($this->baseUrl);
        if (trim($this->apiKey) === '' || preg_match('/[\r\n]/', $this->apiKey)
            || !is_array($url) || ($url['scheme'] ?? null) !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new MisUnavailableException();
        }
        try {
            $headers = ['Content-Type: application/json', 'Accept: application/json'];
            if ($this->apiKey !== '') {
                $headers[] = 'API-Key: ' . $this->apiKey;
            }

            $response = ($this->transport)(
                'POST',
                $this->baseUrl . $path,
                $headers,
                json_encode($body, JSON_THROW_ON_ERROR),
                $this->timeout,
            );
        } catch (\Throwable) {
            // Do not chain transport errors: they can contain credentials or URLs.
            throw new MisUnavailableException();
        }

        if (! is_array($response)
            || ! is_int($response['status'] ?? null)
            || ! is_string($response['body'] ?? null)) {
            throw new MisUnavailableException();
        }

        $status = $response['status'];
        if ($status === 401) {
            return null;
        }

        $decoded = json_decode($response['body'], true);
        if ($status === 403 && is_array($decoded)) {
            foreach (['message', 'error'] as $key) {
                $message = $decoded[$key] ?? null;
                if (is_string($message)
                    && preg_match('/^(?:account not recognized|invalid credentials)[.!]?$/iD', trim($message))) {
                    return null;
                }
            }
        }

        if ($status < 200 || $status >= 300
            || ! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            throw new MisUnavailableException();
        }

        // Profile/envelope validation belongs to the mapper, not the transport.
        return $decoded;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    private function curlLoginRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new MisUnavailableException();
        }

        try {
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            if ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $raw = curl_exec($ch);
            if ($raw === false) {
                throw new MisUnavailableException();
            }

            return ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $raw];
        } finally {
            curl_close($ch);
        }
    }

    public function searchStudents(string $query, int $limit = 25, int $page = 1): array
    {
        $token = $this->getServiceAccessToken();
        if ($token === null) {
            return ['data' => [], 'limit' => $limit, 'current_page' => $page, 'max_page' => 0];
        }

        $params = http_build_query([
            'search' => trim($query),
            'limit'  => $limit,
            'page'   => $page,
        ]);

        $res = $this->getJson("/api/v1/students?{$params}", $token);
        return is_array($res) ? $res : ['data' => [], 'limit' => $limit, 'current_page' => $page, 'max_page' => 0];
    }

    public function searchEmployees(string $query, int $limit = 25, int $page = 1): array
    {
        $token = $this->getServiceAccessToken();
        if ($token === null) {
            return ['data' => [], 'limit' => $limit, 'current_page' => $page, 'max_page' => 0];
        }

        $params = http_build_query([
            'search' => trim($query),
            'limit'  => $limit,
            'page'   => $page,
        ]);

        $res = $this->getJson("/api/v1/employees?{$params}", $token);
        return is_array($res) ? $res : ['data' => [], 'limit' => $limit, 'current_page' => $page, 'max_page' => 0];
    }

    public function fetchDepartments(): array
    {
        return $this->fetchReference('/api/v1/employees/departments');
    }

    public function fetchPrograms(): array
    {
        return $this->fetchReference('/api/v1/students/programs', programs: true);
    }

    public function fetchYearLevels(): array
    {
        return $this->fetchReference('/api/v1/students/levels', levels: true);
    }

    /**
     * Obtains a valid service bearer token using the generate->refresh lifecycle.
     */
    private function getServiceAccessToken(): ?string
    {
        $cache = \Config\Services::cache();
        $cachedToken = $cache->get('fumis_service_access_token');
        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        // 1. Generate refresh token
        $genRes = $this->postJson('/api/tokens/generate', [], useApiKey: true);
        $refreshToken = $genRes['data']['refresh_token'] ?? $genRes['refresh_token'] ?? null;
        if (!is_string($refreshToken) || $refreshToken === '') {
            return null;
        }

        // 2. Refresh to get access_token
        $refRes = $this->postJson('/api/tokens/refresh', [], useApiKey: true, bearerToken: $refreshToken);
        $accessToken = $refRes['data']['access_token'] ?? $refRes['access_token'] ?? null;
        if (is_string($accessToken) && $accessToken !== '') {
            // Cache for 8 minutes (MIS tokens usually valid for 10-15 mins)
            $cache->save('fumis_service_access_token', $accessToken, 480);
            return $accessToken;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function postJson(string $path, array $body, bool $useApiKey = false, ?string $bearerToken = null): ?array
    {
        return $this->requestJson('POST', $path, $body, $useApiKey, $bearerToken);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getJson(string $path, string $bearerToken): ?array
    {
        return $this->requestJson('GET', $path, null, true, $bearerToken);
    }

    private function requestJson(string $method, string $path, ?array $body, bool $useApiKey, ?string $bearerToken): ?array
    {
        $url = parse_url($this->baseUrl);
        if (trim($this->apiKey) === '' || preg_match('/[\r\n]/', $this->apiKey)
            || !is_array($url) || ($url['scheme'] ?? null) !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ($bearerToken !== null && preg_match('/[\r\n]/', $bearerToken))) {
            return null;
        }
        try {
            $headers = ['Accept: application/json'];
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
            }
            if ($useApiKey) {
                $headers[] = 'API-Key: ' . $this->apiKey;
            }
            if ($bearerToken !== null) {
                $headers[] = 'Authorization: Bearer ' . $bearerToken;
            }
            $response = ($this->transport)($method, $this->baseUrl . $path, $headers,
                $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), $this->timeout);
            if (!is_array($response) || !is_int($response['status'] ?? null)
                || $response['status'] < 200 || $response['status'] >= 300 || !is_string($response['body'] ?? null)) {
                return null;
            }
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            // Upstream bodies and transport errors may contain credentials.
            return null;
        }
    }

    private function fetchReference(string $path, bool $programs = false, bool $levels = false): array
    {
        $token = $this->getServiceAccessToken();
        if ($token === null) {
            return [];
        }
        $rows = [];
        $limit = null;
        $maxPage = null;
        for ($page = 1; $page <= 400; ++$page) {
            $response = $this->getJson($path . ($page === 1 ? '' : '?page=' . $page . '&limit=' . $limit), $token);
            $data = $response['data'] ?? null;
            if (!is_array($data)) {
                return [];
            }
            if ($page === 1 && $levels && !array_is_list($data) && !array_key_exists('data', $data)) {
                return $this->normalizeGroupedLevels($data);
            }
            if ($page === 1 && array_is_list($data)) {
                return $this->normalizeReferenceRows($data, $programs);
            }
            $pageLimit = $this->paginationInteger($data['limit'] ?? null, 10000);
            $currentPage = $this->paginationInteger($data['current_page'] ?? null, 400);
            $pageMax = $this->paginationInteger($data['max_page'] ?? null, 400);
            if (!is_array($data['data'] ?? null) || !array_is_list($data['data'])
                || $pageLimit === null || $currentPage !== $page || $pageMax === null
                || $page > $pageMax) {
                return [];
            }
            $limit ??= $pageLimit;
            $maxPage ??= $pageMax;
            $count = count($data['data']);
            if ($limit !== $pageLimit || $maxPage !== $pageMax
                || $count > $limit || ($page < $maxPage && $count !== $limit)
                || ($page > 1 && $count === 0) || count($rows) + $count > 10000) {
                return [];
            }
            array_push($rows, ...$data['data']);
            if ($page === $maxPage) {
                return $this->normalizeReferenceRows($rows, $programs);
            }
        }
        return [];
    }

    private function paginationInteger(mixed $value, int $max): ?int
    {
        if (is_string($value) && strlen($value) <= strlen((string) $max)
            && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $value = (int) $value;
        }
        return is_int($value) && $value >= 1 && $value <= $max ? $value : null;
    }

    private function normalizeReferenceRows(array $rows, bool $programs): array
    {
        if (count($rows) > 10000) {
            return [];
        }
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return [];
            }
            $liveProgram = $programs && array_key_exists('course', $row);
            $code = $this->referenceText($row[$liveProgram ? 'course' : 'code'] ?? null);
            $name = $this->referenceText($row[$liveProgram ? 'coursedesc' : 'name'] ?? null);
            if ($code === null || $name === null) {
                return [];
            }
            $value = ['code' => $code, 'name' => $name];
            $academicGroup = null;
            $departmentKey = $liveProgram ? 'department' : 'department_code';
            if ($programs && ($liveProgram || array_key_exists($departmentKey, $row))) {
                $department = $this->referenceText($row[$departmentKey] ?? null);
                if ($department === null) {
                    return [];
                }
                if ($liveProgram) {
                    // Academic groups and employee departments are distinct taxonomies:
                    // retain the group for duplicate checks, never infer an employee department FK.
                    $academicGroup = $department;
                } else {
                    $value['department_code'] = $department;
                }
            }
            if (isset($seen[$code])) {
                // Only identical live program records may be repeated by MIS.
                if ($liveProgram && $seen[$code]['live'] && $seen[$code]['value'] === $value
                    && $seen[$code]['group'] === $academicGroup) {
                    continue;
                }
                return [];
            }
            $seen[$code] = ['live' => $liveProgram, 'value' => $value, 'group' => $academicGroup];
            $result[] = $value;
        }
        return $result;
    }

    private function normalizeGroupedLevels(array $groups): array
    {
        $rows = [];
        foreach ($groups as $group => $levels) {
            $group = $this->referenceText($group);
            if ($group === null || str_contains($group, ':') || !is_array($levels)) {
                return [];
            }
            foreach ($levels as $code => $label) {
                $code = $this->referenceText((string) $code);
                $label = $this->referenceText($label);
                if ($code === null || $label === null || count($rows) >= 10000) {
                    return [];
                }
                $rows[] = ['code' => $group . ':' . $code, 'name' => $group . ' — ' . $label];
            }
        }
        return $this->normalizeReferenceRows($rows, false);
    }

    private function referenceText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

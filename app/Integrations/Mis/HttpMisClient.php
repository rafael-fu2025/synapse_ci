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
     * The injectable login transport receives method, URL, header lines, encoded
     * JSON body and timeout, and returns ['status' => int, 'body' => string].
     * Directory requests deliberately retain their existing failure contract.
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
    private function curlLoginRequest(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new MisUnavailableException();
        }

        try {
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

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
        $token = $this->getServiceAccessToken();
        if ($token === null) {
            return [];
        }
        $res = $this->getJson('/api/v1/employees/departments', $token);
        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public function fetchPrograms(): array
    {
        $token = $this->getServiceAccessToken();
        if ($token === null) {
            return [];
        }
        $res = $this->getJson('/api/v1/students/programs', $token);
        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public function fetchYearLevels(): array
    {
        $token = $this->getServiceAccessToken();
        if ($token === null) {
            return [];
        }
        $res = $this->getJson('/api/v1/students/levels', $token);
        return is_array($res['data'] ?? null) ? $res['data'] : [];
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
        $refreshToken = $genRes['refresh_token'] ?? null;
        if (!is_string($refreshToken) || $refreshToken === '') {
            return null;
        }

        // 2. Refresh to get access_token
        $refRes = $this->postJson('/api/tokens/refresh', [], useApiKey: true, bearerToken: $refreshToken);
        $accessToken = $refRes['access_token'] ?? null;
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
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($useApiKey && $this->apiKey !== '') {
            $headers[] = 'API-Key: ' . $this->apiKey;
        }
        if ($bearerToken !== null && $bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getJson(string $path, string $bearerToken): ?array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $bearerToken,
        ];
        if ($this->apiKey !== '') {
            $headers[] = 'API-Key: ' . $this->apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

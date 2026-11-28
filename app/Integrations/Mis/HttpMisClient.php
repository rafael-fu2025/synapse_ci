<?php

declare(strict_types=1);

namespace App\Integrations\Mis;

final class HttpMisClient implements MisClientInterface
{
    private const CREDENTIAL_REJECTION_PATTERN = '/^(?:account not recognized|invalid credentials|invalid (?:id|username|student|employee)(?:\s+or\s+password)?|incorrect password|wrong password|(?:account|user|student|employee|record)\s+not\s+found|no\s+(?:matching\s+)?(?:account|user|student|employee)\s+found)(?:[.!]?(?:\s+please try again[.!]?)?)?$/iD';

    private const VALIDATION_ID_REJECTION_PATTERN = '/^(?:the\s+(?:selected\s+)?(?:student|employee)[_\s]?id\s+is\s+invalid|invalid\s+(?:student|employee)[_\s]?id)[.!]?$/iD';

    public const DIRECTORY_BATCH_SIZE = 6;

    private const DIRECTORY_MAX_RETRIES = 2;

    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private \Closure $transport;
    private bool $customTransport;
    private ?\CurlHandle $curlHandle = null;
    private ?\CurlMultiHandle $multiHandle = null;

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
        $this->customTransport = $transport !== null;
        $this->transport = $transport === null
            ? $this->curlLoginRequest(...)
            : \Closure::fromCallable($transport);
    }

    public function __destruct()
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
        if ($this->multiHandle !== null) {
            curl_multi_close($this->multiHandle);
            $this->multiHandle = null;
        }
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
            $this->logLoginFailure($path, 'config', 0);
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
        } catch (MisUnavailableException $e) {
            throw $e;
        } catch (\Throwable) {
            // Do not chain transport errors: they can contain credentials or URLs.
            $this->logLoginFailure($path, 'transport', 0);
            throw new MisUnavailableException();
        }

        if (! is_array($response)
            || ! is_int($response['status'] ?? null)
            || ! is_string($response['body'] ?? null)) {
            $this->logLoginFailure($path, 'malformed_transport_response', 0);
            throw new MisUnavailableException();
        }

        $status = $response['status'];
        if ($status === 401) {
            return null;
        }

        $decoded = json_decode($response['body'], true);
        if ($this->isCredentialRejection($status, $decoded)) {
            return null;
        }

        if ($status === 500) {
            // The upstream answered and then failed while resolving the account. The live
            // employee endpoint answers 500 for accounts whose state it cannot handle, so the
            // credential cannot be judged from this response. Report an unverifiable account
            // rather than a transport outage or a rejected password.
            $this->logLoginFailure($path, 'upstream_account_error', $status, is_array($decoded) ? $decoded : null, secret: $body['password'] ?? null);
            throw new MisAccountException();
        }

        if ($status < 200 || $status >= 300
            || ! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            $this->logLoginFailure($path, $status >= 200 && $status < 300 ? 'invalid_json_body' : 'http_error', $status, is_array($decoded) ? $decoded : null);
            throw new MisUnavailableException();
        }

        if ($this->isExplicitErrorEnvelope($decoded)) {
            $this->logLoginFailure($path, 'error_envelope', $status, $decoded);
            throw new MisUnavailableException();
        }

        // Profile/envelope validation belongs to the mapper, not the transport.
        return $decoded;
    }

    private function isExplicitErrorEnvelope(array $decoded): bool
    {
        $explicitError = ($decoded['status'] ?? null) === 'error' || ($decoded['success'] ?? null) === false;
        $hasProfileData = is_array($decoded['data'] ?? null) && $decoded['data'] !== [];
        return $explicitError && ! $hasProfileData;
    }

    private function isCredentialRejection(int $status, mixed $decoded): bool
    {
        if (! is_array($decoded) || array_is_list($decoded)) {
            return false;
        }

        if ($status === 403 || $status === 404) {
            return $this->matchesRejectionMessage($decoded, self::CREDENTIAL_REJECTION_PATTERN);
        }

        if ($status === 422) {
            if ($this->matchesRejectionMessage($decoded, self::CREDENTIAL_REJECTION_PATTERN)
                || $this->matchesRejectionMessage($decoded, self::VALIDATION_ID_REJECTION_PATTERN)) {
                return true;
            }
            $errors = $decoded['errors'] ?? null;
            if (is_array($errors)) {
                foreach (['student_id', 'employee_id', 'id'] as $field) {
                    $fieldErrors = $errors[$field] ?? null;
                    if (is_array($fieldErrors)) {
                        foreach ($fieldErrors as $msg) {
                            if (is_string($msg) && (
                                preg_match(self::CREDENTIAL_REJECTION_PATTERN, trim($msg)) === 1
                                || preg_match(self::VALIDATION_ID_REJECTION_PATTERN, trim($msg)) === 1
                            )) {
                                return true;
                            }
                        }
                    }
                }
            }
            return false;
        }

        if ($status >= 200 && $status < 300 && $this->isExplicitErrorEnvelope($decoded)) {
            return $this->matchesRejectionMessage($decoded, self::CREDENTIAL_REJECTION_PATTERN);
        }

        return false;
    }

    private function matchesRejectionMessage(array $decoded, string $pattern): bool
    {
        foreach (['message', 'error'] as $key) {
            $message = $decoded[$key] ?? null;
            if (is_string($message) && preg_match($pattern, trim($message)) === 1) {
                return true;
            }
        }
        return false;
    }

    /** Log only non-secret structural metadata (never keys, passwords, tokens, URLs, or payloads). */
    private function logLoginFailure(string $path, string $stage, int $status, ?array $decoded = null, ?int $curlErrno = null, ?string $secret = null): void
    {
        if (! function_exists('log_message')) {
            return;
        }
        $keys = [];
        if ($decoded !== null) {
            foreach (array_slice(array_keys($decoded), 0, 10) as $key) {
                if (is_string($key) && preg_match('/\A[A-Za-z0-9_.-]{1,40}\z/', $key) === 1) {
                    $keys[] = $key;
                }
            }
        }
        try {
            log_message('warning', 'MIS login unavailable [endpoint={endpoint}, stage={stage}, status={status}, curl_errno={curlErrno}, keys={keys}, detail={detail}]', [
                'endpoint'  => $path,
                'stage'     => $stage,
                'status'    => $status,
                'curlErrno' => $curlErrno ?? 0,
                'keys'      => $keys === [] ? 'none' : implode(',', $keys),
                'detail'    => $this->upstreamDetail($decoded, $secret),
            ]);
        } catch (\Throwable) {
            // Logging must never prevent fail-closed exception handling.
        }
    }

    /**
     * Upstream message for support diagnostics: control characters stripped, whitespace collapsed,
     * the submitted password removed and the text bounded. Carries no trace, path or line number.
     */
    private function upstreamDetail(?array $decoded, ?string $secret): string
    {
        if ($decoded === null) {
            return 'none';
        }
        $detail = null;
        foreach (['message', 'title'] as $key) {
            $value = $decoded[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $detail = $value;
                break;
            }
        }
        if ($detail === null) {
            return 'none';
        }
        $detail = preg_replace('/[\x00-\x1F\x7F]/', ' ', $detail) ?? '';
        $detail = trim(preg_replace('/\s+/', ' ', $detail) ?? '');
        if ($secret !== null && strlen($secret) >= 3) {
            $detail = str_replace($secret, '[redacted]', $detail);
        }
        return mb_substr($detail, 0, 160);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    private function curlLoginRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = $this->curlHandle ?? curl_init();
        if ($ch === false) {
            throw new MisUnavailableException();
        }
        $this->curlHandle = $ch;
        curl_reset($ch);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            curl_close($ch);
            $this->curlHandle = null;
            $path = parse_url($url, PHP_URL_PATH);
            $this->logLoginFailure(is_string($path) ? $path : 'unknown', 'curl_exec', 0, null, $errno);
            throw new MisUnavailableException();
        }

        return ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $raw];
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

    public function fetchStudentsPage(int $page = 1, int $limit = 25): array
    {
        return $this->fetchPeoplePage('/api/v1/students', $page, $limit);
    }

    public function fetchEmployeesPage(int $page = 1, int $limit = 25): array
    {
        return $this->fetchPeoplePage('/api/v1/employees', $page, $limit);
    }

    /**
     * Fetch a bounded batch of unfiltered student directory pages using persistent keep-alive
     * connections and bounded transient retries. Returns results keyed by page number in request order.
     *
     * @param list<int> $pages
     * @return array<int, array{limit:int, current_page:int, max_page:int, data:list<mixed>, total?:int}>
     */
    public function fetchStudentsPages(array $pages, int $limit = 25): array
    {
        return $this->fetchPeoplePages('/api/v1/students', $pages, $limit);
    }

    /**
     * Fetch a bounded batch of unfiltered employee directory pages using persistent keep-alive
     * connections and bounded transient retries. Returns results keyed by page number in request order.
     *
     * @param list<int> $pages
     * @return array<int, array{limit:int, current_page:int, max_page:int, data:list<mixed>, total?:int}>
     */
    public function fetchEmployeesPages(array $pages, int $limit = 25): array
    {
        return $this->fetchPeoplePages('/api/v1/employees', $pages, $limit);
    }

    /** Unfiltered listing only; never mistake a failed request for an empty directory. */
    private function fetchPeoplePage(string $path, int $page, int $limit): array
    {
        $batch = $this->fetchPeoplePages($path, [$page], $limit);
        return $batch[$page];
    }

    /**
     * @param list<int> $pages
     * @return array<int, array{limit:int, current_page:int, max_page:int, data:list<mixed>, total?:int}>
     */
    private function fetchPeoplePages(string $path, array $pages, int $limit): array
    {
        try {
            if ($pages === [] || count($pages) > self::DIRECTORY_BATCH_SIZE || $limit !== 25) {
                throw new MisUnavailableException();
            }
            $uniquePages = [];
            foreach ($pages as $page) {
                if (!is_int($page) || $page < 1 || $page > 400 || isset($uniquePages[$page])) {
                    throw new MisUnavailableException();
                }
                $uniquePages[$page] = true;
            }
            $this->assertValidServiceConfig();
            $token = $this->getServiceAccessToken();
            if ($token === null) {
                throw new MisUnavailableException();
            }

            $pending = array_values($pages);
            $results = [];
            $refreshedToken = false;

            for ($attempt = 0; $attempt <= self::DIRECTORY_MAX_RETRIES && $pending !== []; ++$attempt) {
                if ($attempt > 0 && !$this->customTransport) {
                    usleep($attempt * 250000);
                }

                $responses = $this->executeDirectoryGetBatch($path, $pending, $limit, $token);
                $retryPages = [];
                $unauthorizedPages = [];

                foreach ($pending as $page) {
                    $res = $responses[$page] ?? ['status' => 0, 'body' => null, 'errno' => 0];
                    $status = $res['status'];

                    if ($status === 401 && !$refreshedToken) {
                        $unauthorizedPages[] = $page;
                        continue;
                    }

                    if ($this->isRetryableDirectoryStatus($status)) {
                        $retryPages[] = $page;
                        continue;
                    }

                    if ($status < 200 || $status >= 300 || !is_string($res['body'])) {
                        throw new MisUnavailableException();
                    }

                    $decoded = json_decode($res['body'], true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($decoded)) {
                        throw new MisUnavailableException();
                    }

                    $results[$page] = $this->normalizePeoplePageResponse($decoded, $page, $limit);
                }

                if ($unauthorizedPages !== []) {
                    $refreshedToken = true;
                    $token = $this->refreshServiceAccessToken();
                    if ($token === null) {
                        throw new MisUnavailableException();
                    }
                    $pending = array_values(array_unique(array_merge($unauthorizedPages, $retryPages)));
                    // Allow the refreshed token attempt without burning a transient retry slot.
                    --$attempt;
                    continue;
                }

                $pending = $retryPages;
            }

            if ($pending !== []) {
                throw new MisUnavailableException();
            }

            $ordered = [];
            foreach ($pages as $page) {
                $ordered[$page] = $results[$page];
            }
            return $ordered;
        } catch (\Throwable) {
            // Never expose upstream response bodies, token/cache errors, or transport details.
            throw new MisUnavailableException();
        }
    }

    /**
     * @param list<int> $pages
     * @return array<int, array{status: int, body: ?string, errno: int}>
     */
    private function executeDirectoryGetBatch(string $path, array $pages, int $limit, string $token): array
    {
        if (preg_match('/[\r\n]/', $token)) {
            throw new MisUnavailableException();
        }
        $headers = [
            'Accept: application/json',
            'API-Key: ' . $this->apiKey,
            'Authorization: Bearer ' . $token,
        ];
        $timeout = $this->customTransport ? $this->timeout : max(15, $this->timeout);

        if ($this->customTransport || count($pages) === 1) {
            $out = [];
            foreach ($pages as $page) {
                $url = $this->baseUrl . $path . '?' . http_build_query(['limit' => $limit, 'page' => $page]);
                try {
                    $res = ($this->transport)('GET', $url, $headers, null, $timeout);
                    if (is_array($res) && is_int($res['status'] ?? null) && is_string($res['body'] ?? null)) {
                        $out[$page] = ['status' => $res['status'], 'body' => $res['body'], 'errno' => 0];
                    } else {
                        $out[$page] = ['status' => 0, 'body' => null, 'errno' => 0];
                    }
                } catch (\Throwable) {
                    $out[$page] = ['status' => 0, 'body' => null, 'errno' => 28];
                }
            }
            return $out;
        }

        $mh = $this->multiHandle ?? curl_multi_init();
        $this->multiHandle = $mh;
        $handles = [];
        $out = [];

        foreach ($pages as $page) {
            $url = $this->baseUrl . $path . '?' . http_build_query(['limit' => $limit, 'page' => $page]);
            $ch = curl_init($url);
            if ($ch === false) {
                foreach ($handles as $existing) {
                    curl_multi_remove_handle($mh, $existing);
                    curl_close($existing);
                }
                throw new MisUnavailableException();
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$page] = $ch;
        }

        try {
            $active = null;
            do {
                $mrc = curl_multi_exec($mh, $active);
                if ($active && $mrc === CURLM_OK) {
                    if (curl_multi_select($mh, 1.0) === -1) {
                        usleep(10000);
                    }
                }
            } while ($active && $mrc === CURLM_OK);

            foreach ($handles as $page => $ch) {
                $errno = curl_errno($ch);
                if ($errno !== 0) {
                    $this->logLoginFailure($path, 'curl_multi_exec', 0, null, $errno);
                    $out[$page] = ['status' => 0, 'body' => null, 'errno' => $errno];
                } else {
                    $body = curl_multi_getcontent($ch);
                    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $out[$page] = ['status' => $status, 'body' => is_string($body) ? $body : null, 'errno' => 0];
                }
            }
        } finally {
            foreach ($handles as $ch) {
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
        }

        return $out;
    }

    private function isRetryableDirectoryStatus(int $status): bool
    {
        return in_array($status, [0, 429, 502, 503, 504], true);
    }

    private function assertValidServiceConfig(): void
    {
        $url = parse_url($this->baseUrl);
        if (trim($this->apiKey) === '' || preg_match('/[\r\n]/', $this->apiKey)
            || !is_array($url) || ($url['scheme'] ?? null) !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new MisUnavailableException();
        }
    }

    /**
     * @param array<string, mixed> $response
     * @return array{limit:int, current_page:int, max_page:int, data:list<mixed>, total?:int}
     */
    private function normalizePeoplePageResponse(array $response, int $page, int $limit): array
    {
        // Accept the documented top-level page and the MIS data-wrapped page.
        if (is_array($response['data'] ?? null) && !array_is_list($response['data'])
            && array_key_exists('data', $response['data'])) {
            $nested = $response['data'];
            foreach (['limit', 'current_page', 'max_page', 'total'] as $field) {
                if (array_key_exists($field, $response)
                    && (!(is_int($response[$field]) || is_string($response[$field]))
                        || !array_key_exists($field, $nested)
                        || !(is_int($nested[$field]) || is_string($nested[$field]))
                        || (string) $response[$field] !== (string) $nested[$field])) {
                    throw new MisUnavailableException();
                }
            }
            $response = $nested;
        }
        $normalized = [];
        foreach (['limit' => 25, 'current_page' => 400, 'max_page' => 400] as $field => $bound) {
            $value = $response[$field] ?? null;
            // An empty directory may report max_page=0 on its first page.
            $normalized[$field] = $field === 'max_page' && ($value === 0 || $value === '0')
                ? 0 : $this->paginationInteger($value, $bound);
            if ($normalized[$field] === null) { throw new MisUnavailableException(); }
        }
        $data = $response['data'] ?? null;
        $max = $normalized['max_page'];
        $hasTotal = array_key_exists('total', $response);
        // Live MIS computes max_page via floor(total / limit) when total is omitted,
        // placing 1..(limit - 1) trailing records on page (max_page + 1), or 0 rows when exact.
        $isFloorOverflowPage = !$hasTotal && $max > 0 && $page === $max + 1;
        if (!is_array($data) || !array_is_list($data) || $normalized['limit'] !== $limit
            || $normalized['current_page'] !== $page || count($data) > $limit
            || ($max === 0 && ($page !== 1 || $data !== []))
            || ($max > 0 && (
                $isFloorOverflowPage
                    ? count($data) >= $limit
                    : ($page > $max || ($page < $max && count($data) !== $limit)
                        || ($page > 1 && $data === []))
            ))) {
            throw new MisUnavailableException();
        }
        $normalized['data'] = $data;
        if (array_key_exists('total', $response)) {
            $value = $response['total'];
            $total = $value === 0 || $value === '0' ? 0 : $this->paginationInteger($value, 10000);
            if ($total === null || max(1, $max) !== max(1, (int) ceil($total / $limit))
                || ($page === max(1, $max) && count($data) !== $total - ($page - 1) * $limit)) {
                throw new MisUnavailableException();
            }
            $normalized['total'] = $total;
        }
        return $normalized;
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
    private function getServiceAccessToken(bool $forceRefresh = false): ?string
    {
        $cache = \Config\Services::cache();
        if (!$forceRefresh) {
            $cachedToken = $cache->get('fumis_service_access_token');
            if (is_string($cachedToken) && $cachedToken !== '') {
                return $cachedToken;
            }
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

    private function refreshServiceAccessToken(): ?string
    {
        try {
            \Config\Services::cache()->delete('fumis_service_access_token');
        } catch (\Throwable) {
            // Best-effort cache invalidation before generating a fresh token.
        }
        return $this->getServiceAccessToken(true);
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

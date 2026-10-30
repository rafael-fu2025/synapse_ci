<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Config\Ai;

final class Profile
{
    public readonly Ai $config;
    private readonly Ai $snapshot;
    private readonly string $effectiveRevision;
    /** @var list<int> */
    private array $tenants = [];
    /** @var list<string> */
    private array $units = [];

    private function __construct(#[\SensitiveParameter] Ai $config)
    {
        $this->config = clone $config;
        $defaults = (new \ReflectionClass(Ai::class))->getDefaultProperties();
        foreach (get_object_vars($this->config) as $name => $value) {
            if (! array_key_exists($name, $defaults) || get_debug_type($value) !== get_debug_type($defaults[$name])
                || (is_string($value) && (strlen($value) > 65536 || ! mb_check_encoding($value, 'UTF-8')))) {
                throw new AiFailure('invalid_config');
            }
        }
        $c = $this->config;
        foreach (['enabled', 'governanceApproved', 'programLibraryApproved', 'allowLocalHttp', 'allowPrivateHttpDispatch'] as $flag) {
            if (! is_bool($c->$flag)) {
                throw new AiFailure('invalid_config');
            }
        }
        $ranges = [
            'connectTimeoutSeconds' => [1, 30], 'requestTimeoutSeconds' => [1, 90],
            'maxRequestBytes' => [256, 1048576], 'maxResponseBytes' => [256, 4194304],
            'maxInputTokens' => [512, 131072], 'maxOutputTokens' => [1, 32768], 'maxConcurrentRequests' => [1, 1],
            'maxAttempts' => [1, 1], 'dailyRequestLimit' => [1, 10000],
            'minimumContributors' => [10, 1000000], 'retentionDays' => [1, 365],
            'maxJobsPerRun' => [1, 100],
        ];
        foreach ($ranges as $name => [$min, $max]) {
            if (! is_int($c->$name) || $c->$name < $min || $c->$name > $max) {
                throw new AiFailure('invalid_config');
            }
        }
        if ($c->connectTimeoutSeconds > $c->requestTimeoutSeconds
            || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}\z/D', $c->profileId)
            || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}\z/D', $c->credentialVersion)
            || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $c->capabilityRevision)
            || $c->adapter !== 'openai_chat_completions'
            || ! in_array($c->authMode, ['bearer', 'api_key_header', 'none'], true)
            || ! in_array($c->maxOutputTokensField, ['max_tokens', 'max_completion_tokens'], true)
            || ! in_array($c->reasoningEffort, ['', 'none', 'minimal', 'low', 'medium', 'high', 'xhigh'], true)
            || ! in_array($c->thinkingMode, ['', 'disabled', 'enabled'], true)
            // Schema content is application-owned; this slice supports only the verified JSON-object capability.
            || ! in_array($c->structuredOutputMode, ['none', 'json_object'], true)
            || strlen($c->model) > 191 || preg_match('/[\p{Z}\p{C}\s]/u', $c->model)
            || strlen($c->apiKey) > 4096 || preg_match('/[^\x21-\x7e]/', $c->apiKey)
            || ($c->authMode === 'none' && $c->apiKey !== '')
            || ($c->approvedRevision !== '' && ! preg_match('/\A[a-f0-9]{64}\z/D', $c->approvedRevision))
            || ($c->compatibilityApprovedRevision !== '' && ! preg_match('/\A[a-f0-9]{64}\z/D', $c->compatibilityApprovedRevision))) {
            throw new AiFailure('invalid_config');
        }
        $headers = self::csv($c->apiKeyHeaderAllowlist);
        foreach (array_merge($headers, [$c->apiKeyHeader]) as $header) {
            // Routing, browser/session, standard authentication and hop-by-hop headers are never keys.
            if (! preg_match('/\A[A-Za-z][A-Za-z0-9-]{0,63}\z/D', $header)
                || in_array(strtolower($header), [
                    'host', 'authorization', 'proxy-authorization', 'cookie', 'cookie2', 'set-cookie',
                    'connection', 'proxy-connection', 'keep-alive', 'te', 'trailer', 'transfer-encoding',
                    'upgrade', 'content-length', 'content-type', 'accept', 'accept-encoding', 'expect',
                    'origin', 'referer', 'user-agent', 'forwarded', 'via', 'range', 'destination',
                ], true) || preg_match('/\A(?:proxy-|sec-|x-forwarded-|x-http-|x-original-|x-rewrite-|x-csrf|x-xsrf)/i', $header)) {
                throw new AiFailure('invalid_config');
            }
        }
        if ($headers === [] || ! in_array(strtolower($c->apiKeyHeader), array_map('strtolower', $headers), true)) {
            throw new AiFailure('invalid_config');
        }
        $allowed = EndpointPolicy::origins($c->allowedOrigins);
        $private = EndpointPolicy::origins($c->privateOrigins);
        if (array_diff($private, $allowed) !== []) {
            throw new AiFailure('invalid_config');
        }
        if ($c->baseUrl !== '') {
            $endpoint = EndpointPolicy::parseBaseUrl($c->baseUrl);
            if (! in_array($endpoint['origin'], $allowed, true)
                || ($endpoint['scheme'] === 'http' && (! $c->allowLocalHttp || ! in_array($endpoint['origin'], $private, true)))) {
                throw new AiFailure('endpoint_not_allowed');
            }
        }
        foreach (self::csv($c->approvedTenants) as $tenant) {
            if (! preg_match('/\A[1-9][0-9]*\z/D', $tenant) || filter_var($tenant, FILTER_VALIDATE_INT) === false) {
                throw new AiFailure('invalid_config');
            }
            $this->tenants[] = (int) $tenant;
        }
        if (preg_match('/[\p{C}]/u', $c->approvedUnits)) {
            throw new AiFailure('invalid_config');
        }
        $this->units = self::csv($c->approvedUnits);
        if (array_diff($this->units, ['clinic', 'guidance', 'bmg']) !== [] || ($c->enabled && $this->units === [])) {
            throw new AiFailure('invalid_config');
        }
        foreach ($this->units as $unit) {
            if (AnalysisCatalog::forUnit($unit) === []) { throw new AiFailure('invalid_config'); }
        }
        if ($c->resolverPhpBinary !== '') {
            BoundedResolver::phpBinary($c->resolverPhpBinary);
        }
        if ($c->caBundle !== '' && (strlen($c->caBundle) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $c->caBundle)
            || ! is_file($c->caBundle) || ! is_readable($c->caBundle))) {
            throw new AiFailure('invalid_config');
        }
        if ($c->encryptionKey !== '' && (($key = base64_decode($c->encryptionKey, true)) === false
            || strlen($key) !== 32 || base64_encode($key) !== $c->encryptionKey)) {
            throw new AiFailure('invalid_config');
        }
        $this->snapshot = clone $c;
        $revision = get_object_vars($c);
        // No secret hashes: operators bump credentialVersion when rotating credentials or storage keys.
        // Both acknowledgments refer to this fingerprint; neither can participate in its hash.
        unset($revision['apiKey'], $revision['encryptionKey'], $revision['approvedRevision'], $revision['compatibilityApprovedRevision']);
        // Provider default sends no new option; preserve approvals for pre-thinking profiles.
        if ($c->thinkingMode === '') { unset($revision['thinkingMode']); }
        ksort($revision);
        $this->effectiveRevision = hash('sha256', json_encode($revision, JSON_THROW_ON_ERROR));
        if ($c->enabled) {
            $this->assertCompletionConfigured();
        }
    }

    public static function fromConfig(#[\SensitiveParameter] ?Ai $config = null): self
    {
        return new self($config ?? SettingsStore::effectiveConfig());
    }

    /** @return list<string> */
    public static function csv(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $items = array_map('trim', explode(',', $value));
        if (in_array('', $items, true) || count($items) > 256 || count(array_unique($items)) !== count($items)) {
            throw new AiFailure('invalid_config');
        }

        return $items;
    }

    public function assertUnchanged(): void
    {
        if (get_object_vars($this->config) !== get_object_vars($this->snapshot)) {
            throw new AiFailure('invalid_config');
        }
    }

    public function revision(): string
    {
        $this->assertUnchanged();

        return $this->effectiveRevision;
    }

    /** @return list<string> */
    public function approvedUnits(): array
    {
        $this->assertUnchanged();

        return $this->units;
    }

    /** No URL, key, CA path, tenant list, provider response, or credential-derived fingerprints. */
    public function publicStatus(): array
    {
        $this->assertUnchanged();

        return [
            'profileId' => $this->config->profileId,
            'adapter' => $this->config->adapter,
            'enabled' => $this->config->enabled,
            'configured' => $this->config->baseUrl !== '' && $this->config->model !== ''
                && ($this->config->authMode === 'none' || $this->config->apiKey !== ''),
            'revision' => $this->effectiveRevision,
            'governanceApproved' => $this->config->governanceApproved,
            'revisionApproved' => hash_equals($this->effectiveRevision, $this->config->approvedRevision),
            'compatibilityVerified' => hash_equals($this->effectiveRevision, $this->config->compatibilityApprovedRevision),
        ];
    }

    /** Static readiness only; EndpointPolicy resolves and pins afresh on every request. */
    public function assertCompletionConfigured(bool $synthetic = false): void
    {
        $this->assertUnchanged();
        $c = $this->config;
        if ($c->baseUrl === '' || $c->model === '' || ($c->authMode !== 'none' && $c->apiKey === '')) {
            throw new AiFailure('invalid_config');
        }
        if (! $synthetic && EndpointPolicy::parseBaseUrl($c->baseUrl)['scheme'] === 'http' && ! $c->allowPrivateHttpDispatch) {
            throw new AiFailure('http_dispatch_not_approved');
        }
    }

    /** Called by the worker immediately before complete(); does not itself make network requests. */
    public function assertDispatchAllowed(int $tenantId, string $unit): void
    {
        $this->assertUnchanged();
        $c = $this->config;
        if (! $c->enabled) {
            throw new AiFailure('disabled');
        }
        if (! $c->governanceApproved) {
            throw new AiFailure('governance_required');
        }
        if (AnalysisCatalog::forUnit($unit) === [] || ! in_array($unit, $this->approvedUnits(), true)) {
            throw new AiFailure('unit_not_approved');
        }
        if ($tenantId < 1 || ! in_array($tenantId, $this->tenants, true)) {
            throw new AiFailure('tenant_not_approved');
        }
        if (! hash_equals($this->effectiveRevision, $c->approvedRevision)) {
            throw new AiFailure('revision_not_approved');
        }
        if (! hash_equals($this->effectiveRevision, $c->compatibilityApprovedRevision)) {
            throw new AiFailure('compatibility_required');
        }
        $this->assertCompletionConfigured();
        if ($c->encryptionKey === '') {
            throw new AiFailure('invalid_config');
        }
    }
}

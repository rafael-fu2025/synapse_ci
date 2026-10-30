<?php

declare(strict_types=1);

namespace Config;

use App\Services\Ai\AiFailure;
use CodeIgniter\Config\BaseConfig;

/** Deployment-owned scalar settings. Profile validates types and ranges before use. */
final class Ai extends BaseConfig
{
    // Deliberately untyped: PHP/CI's weak scalar coercion must not turn bad input into approval.
    public $enabled = false;
    public $profileId = 'clinic-reviewed';
    public $adapter = 'openai_chat_completions';
    public $baseUrl = '';
    public $model = '';
    public $apiKey = '';
    public $authMode = 'bearer';
    public $apiKeyHeader = 'X-API-Key';
    public $apiKeyHeaderAllowlist = 'X-API-Key,API-Key';
    public $credentialVersion = '1';
    public $capabilityRevision = 'department-programs-v2:prompt-v2';
    public $maxOutputTokensField = 'max_tokens';
    // Empty leaves the provider default untouched; explicit choices must be supported by the endpoint/model.
    public $reasoningEffort = '';
    public $thinkingMode = '';
    public $structuredOutputMode = 'none';
    public $resolverPhpBinary = '';
    public $connectTimeoutSeconds = 5;
    public $requestTimeoutSeconds = 45;
    public $maxRequestBytes = 65536;
    public $maxResponseBytes = 262144;
    public $maxInputTokens = 8192;
    public $maxOutputTokens = 2000;
    // The store serializes dispatch and ambiguous POSTs are never retried.
    public $maxConcurrentRequests = 1;
    public $maxAttempts = 1;
    public $dailyRequestLimit = 50;
    public $allowedOrigins = '';
    public $privateOrigins = '';
    public $allowLocalHttp = false;
    // allowLocalHttp alone permits only the synthetic probe, not ordinary completions.
    public $allowPrivateHttpDispatch = false;
    public $caBundle = '';
    public $governanceApproved = false;
    public $programLibraryApproved = false;
    public $approvedTenants = '';
    public $approvedUnits = 'clinic';
    public $approvedRevision = '';
    public $compatibilityApprovedRevision = '';
    public $minimumContributors = 10;
    public $retentionDays = 30;
    public $encryptionKey = '';
    public $maxJobsPerRun = 5;

    /** Keep CI4's environment precedence, but never use its lossy bool/int casts. */
    protected function initEnvValue(#[\SensitiveParameter] &$property, string $name, string $prefix, string $shortPrefix)
    {
        $value = $this->getEnvValue($name, $prefix, $shortPrefix);
        if ($value === null) {
            return;
        }
        if (is_bool($property)) {
            $property = match ($value) {
                true, 'true', '1' => true,
                false, 'false', '0' => false,
                default => throw new AiFailure('invalid_config'),
            };
        } elseif (is_int($property)) {
            if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value)
                || filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new AiFailure('invalid_config');
            }
            $property = (int) $value;
        } elseif (is_string($property) && is_string($value)) {
            $property = $value;
        } else {
            throw new AiFailure('invalid_config');
        }
    }
}

<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Mobile push delivery over Firebase Cloud Messaging.
 *
 * The service account key stays on the server; the client only ever receives an
 * opaque device token that it hands back to this API.
 */
final class Push extends BaseConfig
{
    /** Push delivery is opt-in. Nothing is sent while this is false. */
    public bool $enabled = false;

    /** Firebase project that owns the messaging service account. */
    public string $projectId = '';

    /** Explicit, stable deployment identity for LAN-scoped push; never inferred from a request host. */
    public string $deploymentId = '';

    /** Absolute path to the service account JSON key. Never served to a client. */
    public string $serviceAccountPath = '';

    /** OAuth2 scope requested for the FCM HTTP v1 API. */
    public string $tokenScope = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Seconds of transport budget for one publish request. */
    public int $timeout = 10;

    /** Simultaneously active device tokens per user; the oldest is revoked beyond the cap. */
    public int $maxActiveTokens = 10;

    public function __construct()
    {
        parent::__construct();
        // The environment uses dotted snake_case keys; the properties are camelCase.
        foreach ([
            'project_id' => 'projectId',
            'deployment_id' => 'deploymentId',
            'service_account_path' => 'serviceAccountPath',
            'token_scope' => 'tokenScope',
        ] as $key => $property) {
            $value = $this->getEnvValue($key, self::class, 'push');
            if (is_string($value) && trim($value) !== '') {
                $this->{$property} = trim($value);
            }
        }
        $enabled = $this->getEnvValue('enabled', self::class, 'push');
        if ($enabled !== null && $enabled !== '') {
            $this->enabled = in_array(strtolower((string) $enabled), ['1', 'true', 'yes', 'on'], true);
        }
        $timeout = $this->getEnvValue('timeout', self::class, 'push');
        if (is_numeric($timeout)) {
            $this->timeout = max(1, (int) $timeout);
        }
        $max = $this->getEnvValue('max_active_tokens', self::class, 'push');
        if (is_numeric($max)) {
            $this->maxActiveTokens = max(1, (int) $max);
        }
    }

    /** A scope is an opaque deployment name, not a URL, hostname discovery mechanism, or credential. */
    public function hasDeploymentId(): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/D', $this->deploymentId) === 1;
    }

    /** Push cannot be attempted without a project and a service account key. */
    public function isConfigured(): bool
    {
        return $this->enabled
            && $this->projectId !== ''
            && $this->serviceAccountPath !== '';
    }
}

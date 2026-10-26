<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/** Mobile bearer-token lifetimes. Tokens are opaque random values; only hashes are stored. */
final class ApiTokens extends BaseConfig
{
    /** Short-lived access credential, accepted for normal API requests. */
    public int $accessTtl = 900;

    /** Single-use refresh credential; every use rotates the token and revokes its predecessor. */
    public int $refreshTtl = 5184000;

    /** Simultaneously active token families per user; the oldest is revoked beyond the cap. */
    public int $maxActiveTokens = 10;

    public function __construct()
    {
        parent::__construct();
        foreach (['accessTtl' => 'access_ttl', 'refreshTtl' => 'refresh_ttl', 'maxActiveTokens' => 'max_active_tokens'] as $prop => $name) {
            $value = $this->getEnvValue($name, self::class, 'api_tokens');
            if (is_numeric($value)) {
                $this->$prop = max(1, (int) $value);
            }
        }
        if ($this->refreshTtl <= $this->accessTtl) {
            $this->refreshTtl = $this->accessTtl + 1;
        }
    }
}

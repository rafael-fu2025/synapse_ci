<?php

declare(strict_types=1);

namespace App\Services;

use Config\LoginSecurity;
use RuntimeException;

final class LoginRateLimiter
{
    private LoginAttemptStore $store;
    private LoginSecurity $config;

    public function __construct(?LoginAttemptStore $store = null, ?LoginSecurity $config = null)
    {
        $this->config = $config ?? config(LoginSecurity::class);
        foreach (['ipAttempts', 'identityAttempts'] as $name) {
            if ($this->config->$name < 1 || $this->config->$name > 10000) {
                throw new RuntimeException('Invalid login attempt limit.');
            }
        }
        foreach (['ipWindowSeconds', 'identityWindowSeconds'] as $name) {
            if ($this->config->$name < 1 || $this->config->$name > 86400) {
                throw new RuntimeException('Invalid login attempt window.');
            }
        }
        $this->store = $store ?? new DatabaseLoginAttemptStore();
    }

    public function consume(string $clientIp, mixed $identifier, ?int $now = null): int
    {
        $packed = @inet_pton($clientIp);
        if ($packed === false) {
            throw new RuntimeException('A valid client address is required for login limits.');
        }
        // IPv4-mapped IPv6 and equivalent IPv6 spellings must share budgets.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        $identity = is_string($identifier) && strlen($identifier) <= 400 && mb_check_encoding($identifier, 'UTF-8')
            ? mb_strtolower(trim($identifier), 'UTF-8') : '';
        if ($identity === '' || mb_strlen($identity, 'UTF-8') > 100 || preg_match('/[\x00-\x1f\x7f]/', $identity)) {
            $identity = '<invalid-identifier>';
        }
        // No passwords or raw identifiers/IPs are stored. Hashes are pseudonyms, not anonymization.
        return $this->store->consume([
            ['key' => hash('sha256', "synapse.login.ip\x00" . $packed),
                'limit' => $this->config->ipAttempts, 'seconds' => $this->config->ipWindowSeconds],
            ['key' => hash('sha256', "synapse.login.identity\x00" . $packed . "\x00" . $identity),
                'limit' => $this->config->identityAttempts, 'seconds' => $this->config->identityWindowSeconds],
        ], $now ?? time());
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Throwable;

/** Versioned AES-256-GCM envelope; the deployment key is base64 of exactly 32 bytes. */
final class PayloadCipher
{
    private ?string $key = null;

    public function __construct(?string $encryptionKey = null)
    {
        try {
            $encoded = $encryptionKey ?? (string) (SettingsStore::effectiveConfig()->encryptionKey ?? '');
            $key = base64_decode($encoded, true);
            if ($key !== false && strlen($key) === 32 && base64_encode($key) === $encoded) {
                $this->key = $key;
            }
        } catch (Throwable) {
            // Missing configuration must fail closed without exposing deployment details.
        }
    }

    public function ready(): bool
    {
        return $this->key !== null && function_exists('openssl_encrypt') && function_exists('openssl_decrypt');
    }

    /** Deterministic fingerprints use a derived key, never the AES key directly. */
    public function fingerprint(int $tenantId, string $unit, string $kind, string $value): string
    {
        if (!$this->ready()) {
            throw new AiFailure('encryption_unavailable', 503);
        }
        $key = hash_hmac('sha256', 'humus.ai.fingerprint.key.v1', $this->key, true);
        return hash_hmac('sha256', json_encode(['humus.ai.fingerprint.v1', $tenantId, $unit, $kind, $value],
            JSON_THROW_ON_ERROR), $key);
    }

    public function encrypt(int $tenantId, string $unit, string $kind, array $payload): string
    {
        if (!$this->ready()) {
            throw new AiFailure('encryption_unavailable', 503);
        }
        try {
            $nonce = random_bytes(12);
            $tag = '';
            $encrypted = openssl_encrypt(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 'aes-256-gcm',
                $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $this->aad($tenantId, $unit, $kind), 16);
            if ($encrypted === false) {
                throw new AiFailure('encryption_unavailable', 503);
            }
            return 'v1.' . base64_encode($nonce . $tag . $encrypted);
        } catch (Throwable) {
            throw new AiFailure('encryption_unavailable', 503);
        }
    }

    public function decrypt(int $tenantId, string $unit, string $kind, string $envelope): array
    {
        if (!$this->ready()) {
            throw new AiFailure('encryption_unavailable', 503);
        }
        try {
            $bytes = str_starts_with($envelope, 'v1.') ? base64_decode(substr($envelope, 3), true) : false;
            if ($bytes === false || strlen($bytes) < 30) {
                throw new AiFailure('payload_unavailable', 503);
            }
            $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA,
                substr($bytes, 0, 12), substr($bytes, 12, 16), $this->aad($tenantId, $unit, $kind));
            $payload = $plain === false ? null : json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new AiFailure('payload_unavailable', 503);
            }
            return $payload;
        } catch (Throwable) {
            throw new AiFailure('payload_unavailable', 503);
        }
    }

    private function aad(int $tenantId, string $unit, string $kind): string
    {
        return json_encode(['humus.ai.v1', $tenantId, $unit, $kind], JSON_THROW_ON_ERROR);
    }
}

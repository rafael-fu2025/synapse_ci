<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use CodeIgniter\Security\Exceptions\SecurityException;

final class EncryptionService
{
    private ?string $keyHex;
    private int $defaultVersion;

    public function __construct(?string $keyHex = null, int $defaultVersion = 1)
    {
        $this->keyHex = $keyHex;
        $version = env('COUNSELLING_KEY_VERSION', $defaultVersion);
        if ((!is_int($version) && !is_string($version))
            || !preg_match('/^[1-9][0-9]*$/D', (string) $version)
            || filter_var($version, FILTER_VALIDATE_INT) === false) {
            throw new SecurityException('COUNSELLING_KEY_VERSION must be a positive integer.');
        }

        $this->defaultVersion = (int) $version;
        $this->resolveKeyBinary($this->defaultVersion);
    }

    /**
     * Encrypts plaintext using AES-256-GCM with CSPRNG nonce and authentication tag.
     *
     * @return array{
     *     cipher: string,
     *     nonce: string,
     *     tag: string,
     *     key_version: int
     * }
     */
    public function encrypt(string $plaintext, ?int $version = null): array
    {
        $keyVersion = $version ?? $this->defaultVersion;
        $rawKey = $this->resolveKeyBinary($keyVersion);

        // 12-byte CSPRNG IV/nonce for GCM mode
        $nonce = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $rawKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );

        if ($cipher === false) {
            throw new SecurityException('Encryption failed.');
        }

        return [
            'cipher'      => $cipher,
            'nonce'       => $nonce,
            'tag'         => $tag,
            'key_version' => $keyVersion,
        ];
    }

    /**
     * Decrypts AES-256-GCM ciphertext, verifying authentication tag.
     *
     * @throws SecurityException If authentication tag fails (tampered or forged ciphertext).
     */
    public function decrypt(string $cipher, string $nonce, string $tag, int $keyVersion): string
    {
        $rawKey = $this->resolveKeyBinary($keyVersion);
        if (strlen($nonce) !== 12 || strlen($tag) !== 16) {
            throw new SecurityException('Decryption failed: invalid nonce or authentication tag length.');
        }

        $plaintext = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $rawKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new SecurityException('Decryption failed: authenticity tag verification rejected.');
        }

        return $plaintext;
    }

    private function resolveKeyBinary(int $version): string
    {
        if ($version < 1) {
            throw new SecurityException('Counseling key version must be a positive integer.');
        }

        $envKeyName = ($version > 1) ? "COUNSELLING_KEY_V{$version}" : 'COUNSELLING_KEY';
        // An explicitly supplied key is for version 1 only, never a fallback for other versions.
        $hex = $version === 1 && $this->keyHex !== null ? $this->keyHex : env($envKeyName);
        if (!is_string($hex) || !preg_match('/^[a-fA-F0-9]{64}$/D', $hex)) {
            throw new SecurityException("{$envKeyName} must contain exactly 64 hexadecimal characters.");
        }

        return hex2bin($hex);
    }
}

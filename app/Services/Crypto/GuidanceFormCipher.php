<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use CodeIgniter\Security\Exceptions\SecurityException;
use JsonException;

/**
 * Purpose-specific AES-256-GCM cipher envelope for controlled guidance forms.
 *
 * Cryptographically binds tenant_id, form_instance_id, definition_revision_id,
 * revision_number, and schema_version as Additional Authenticated Data (AAD).
 * Any tampering with ciphertext, nonce, tag, or bound context causes decryption failure.
 */
final class GuidanceFormCipher
{
    private ?string $keyHex;
    private int $defaultVersion;

    public function __construct(?string $keyHex = null, int $defaultVersion = 1)
    {
        $this->keyHex = $keyHex;
        $version = env('GUIDANCE_FORMS_KEY_VERSION', env('COUNSELLING_KEY_VERSION', $defaultVersion));
        if ((!is_int($version) && !is_string($version))
            || !preg_match('/^[1-9][0-9]*$/D', (string) $version)
            || filter_var($version, FILTER_VALIDATE_INT) === false) {
            throw new SecurityException('Guidance form key version must be a positive integer.');
        }

        $this->defaultVersion = (int) $version;
        $this->resolveKeyBinary($this->defaultVersion);
    }

    /**
     * Builds canonical deterministic AAD string for GCM authentication.
     */
    public static function buildAad(
        int $tenantId,
        int $formInstanceId,
        int $definitionRevisionId,
        int $revisionNumber,
        string $schemaVersion
    ): string {
        try {
            return json_encode([
                't' => $tenantId,
                'i' => $formInstanceId,
                'd' => $definitionRevisionId,
                'r' => $revisionNumber,
                's' => trim($schemaVersion),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new SecurityException('Failed to encode guidance form AAD: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Encrypts plaintext with AES-256-GCM and bound AAD.
     *
     * @return array{
     *     cipher: string,
     *     nonce: string,
     *     tag: string,
     *     key_version: int,
     *     aad: string
     * }
     */
    public function encrypt(
        string $plaintext,
        int $tenantId,
        int $formInstanceId,
        int $definitionRevisionId,
        int $revisionNumber,
        string $schemaVersion,
        ?int $version = null
    ): array {
        $keyVersion = $version ?? $this->defaultVersion;
        $rawKey = $this->resolveKeyBinary($keyVersion);
        $nonce = random_bytes(12);
        $aad = self::buildAad($tenantId, $formInstanceId, $definitionRevisionId, $revisionNumber, $schemaVersion);
        $tag = '';

        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $rawKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            16
        );

        if ($cipher === false) {
            throw new SecurityException('Guidance form encryption failed.');
        }

        return [
            'cipher'      => $cipher,
            'nonce'       => $nonce,
            'tag'         => $tag,
            'key_version' => $keyVersion,
            'aad'         => $aad,
        ];
    }

    /**
     * Decrypts AES-256-GCM ciphertext, verifying authentication tag against bound AAD.
     *
     * @throws SecurityException If authentication tag or AAD verification fails.
     */
    public function decrypt(
        string $cipher,
        string $nonce,
        string $tag,
        int $keyVersion,
        int $tenantId,
        int $formInstanceId,
        int $definitionRevisionId,
        int $revisionNumber,
        string $schemaVersion
    ): string {
        $rawKey = $this->resolveKeyBinary($keyVersion);
        if (strlen($nonce) !== 12 || strlen($tag) !== 16) {
            throw new SecurityException('Decryption failed: invalid nonce or authentication tag length.');
        }

        $aad = self::buildAad($tenantId, $formInstanceId, $definitionRevisionId, $revisionNumber, $schemaVersion);

        $plaintext = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $rawKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad
        );

        if ($plaintext === false) {
            throw new SecurityException('Decryption failed: authenticity tag or AAD verification rejected.');
        }

        return $plaintext;
    }

    private function resolveKeyBinary(int $version): string
    {
        if ($version < 1) {
            throw new SecurityException('Guidance form key version must be a positive integer.');
        }

        // Check explicit keyHex first (for version 1 in tests)
        if ($version === 1 && $this->keyHex !== null) {
            return hex2bin($this->keyHex);
        }

        // Try GUIDANCE_FORMS_KEY first, fallback to COUNSELLING_KEY
        $formsKeyName = ($version > 1) ? "GUIDANCE_FORMS_KEY_V{$version}" : 'GUIDANCE_FORMS_KEY';
        $counsellingKeyName = ($version > 1) ? "COUNSELLING_KEY_V{$version}" : 'COUNSELLING_KEY';

        $hex = env($formsKeyName, env($counsellingKeyName));
        if (!is_string($hex) || !preg_match('/^[a-fA-F0-9]{64}$/D', $hex)) {
            throw new SecurityException("{$formsKeyName} or {$counsellingKeyName} must contain exactly 64 hexadecimal characters.");
        }

        return hex2bin($hex);
    }
}


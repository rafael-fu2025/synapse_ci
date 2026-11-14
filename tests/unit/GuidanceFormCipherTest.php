<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Crypto\GuidanceFormCipher;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class GuidanceFormCipherTest extends CIUnitTestCase
{
    private string $keyHex;
    private GuidanceFormCipher $cipher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyHex = str_repeat('ab', 32);
        $this->cipher = new GuidanceFormCipher($this->keyHex, 1);
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $payload = json_encode(['student_name' => 'Carla Mendoza', 'narrative' => 'Private observation.'], JSON_THROW_ON_ERROR);
        $tenantId = 1;
        $instanceId = 42;
        $defRevId = 5;
        $revisionNum = 1;
        $schemaVersion = '1.0.0';

        $encrypted = $this->cipher->encrypt(
            $payload,
            $tenantId,
            $instanceId,
            $defRevId,
            $revisionNum,
            $schemaVersion
        );

        $this->assertNotSame($payload, $encrypted['cipher']);
        $this->assertSame(12, strlen($encrypted['nonce']));
        $this->assertSame(16, strlen($encrypted['tag']));
        $this->assertSame(1, $encrypted['key_version']);

        $decrypted = $this->cipher->decrypt(
            $encrypted['cipher'],
            $encrypted['nonce'],
            $encrypted['tag'],
            $encrypted['key_version'],
            $tenantId,
            $instanceId,
            $defRevId,
            $revisionNum,
            $schemaVersion
        );

        $this->assertSame($payload, $decrypted);
    }

    public function testAadTamperingFailsDecryption(): void
    {
        $payload = 'Confidential psychological evaluation details';
        $encrypted = $this->cipher->encrypt($payload, 1, 100, 2, 1, '1.0.0');

        // Mismatched tenant_id
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Decryption failed: authenticity tag or AAD verification rejected.');
        $this->cipher->decrypt(
            $encrypted['cipher'],
            $encrypted['nonce'],
            $encrypted['tag'],
            $encrypted['key_version'],
            2, // Tampered tenant
            100,
            2,
            1,
            '1.0.0'
        );
    }

    public function testInstanceIdTamperingFailsDecryption(): void
    {
        $payload = 'Confidential test payload';
        $encrypted = $this->cipher->encrypt($payload, 1, 100, 2, 1, '1.0.0');

        // Mismatched instance_id
        $this->expectException(SecurityException::class);
        $this->cipher->decrypt(
            $encrypted['cipher'],
            $encrypted['nonce'],
            $encrypted['tag'],
            $encrypted['key_version'],
            1,
            999, // Tampered instance_id
            2,
            1,
            '1.0.0'
        );
    }

    public function testCiphertextTamperingFailsDecryption(): void
    {
        $payload = 'Confidential test payload';
        $encrypted = $this->cipher->encrypt($payload, 1, 100, 2, 1, '1.0.0');

        $tamperedCipher = $encrypted['cipher'];
        $tamperedCipher[0] = chr(ord($tamperedCipher[0]) ^ 0xFF);

        $this->expectException(SecurityException::class);
        $this->cipher->decrypt(
            $tamperedCipher,
            $encrypted['nonce'],
            $encrypted['tag'],
            $encrypted['key_version'],
            1,
            100,
            2,
            1,
            '1.0.0'
        );
    }
}


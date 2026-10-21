<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Crypto\EncryptionService;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class EncryptionServiceTest extends CIUnitTestCase
{
    private const TEST_KEY = 'abababababababababababababababababababababababababababababababab';

    private EncryptionService $crypto;
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['COUNSELLING_KEY', 'COUNSELLING_KEY_VERSION', 'COUNSELLING_KEY_V2'] as $name) {
            $this->savedEnvironment[$name] = [
                'process' => getenv($name),
                'env' => $_ENV[$name] ?? null,
                'server' => $_SERVER[$name] ?? null,
                'has_env' => array_key_exists($name, $_ENV),
                'has_server' => array_key_exists($name, $_SERVER),
            ];
            $this->setEnvironment($name, null);
        }
        $this->crypto = new EncryptionService(self::TEST_KEY);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => $saved) {
            putenv($saved['process'] === false ? $name : $name . '=' . $saved['process']);
            unset($_ENV[$name], $_SERVER[$name]);
            if ($saved['has_env']) {
                $_ENV[$name] = $saved['env'];
            }
            if ($saved['has_server']) {
                $_SERVER[$name] = $saved['server'];
            }
        }
        parent::tearDown();
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($value === null ? $name : $name . '=' . $value);
        if ($value !== null) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    public function testEncryptAndDecryptRoundtrip(): void
    {
        $plaintext = 'Confidential counseling observations: Student expresses severe exam anxiety.';
        $encrypted = $this->crypto->encrypt($plaintext);

        $this->assertNotEmpty($encrypted['cipher']);
        $this->assertSame(12, strlen($encrypted['nonce']), 'AES-GCM nonce must be exactly 12 bytes.');
        $this->assertSame(16, strlen($encrypted['tag']), 'AES-GCM auth tag must be exactly 16 bytes.');
        $this->assertSame(1, $encrypted['key_version']);
        $this->assertNotSame($plaintext, $encrypted['cipher']);

        $decrypted = $this->crypto->decrypt(
            $encrypted['cipher'],
            $encrypted['nonce'],
            $encrypted['tag'],
            $encrypted['key_version']
        );

        $this->assertSame($plaintext, $decrypted);
    }

    public function testDecryptionFailsOnTamperedCiphertext(): void
    {
        $plaintext = 'Authentic clinical note.';
        $encrypted = $this->crypto->encrypt($plaintext);

        // Tamper with the ciphertext
        $tamperedCipher = $encrypted['cipher'];
        $tamperedCipher[0] = chr(ord($tamperedCipher[0]) ^ 0xFF);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('authenticity tag verification rejected');

        $this->crypto->decrypt(
            $tamperedCipher,
            $encrypted['nonce'],
            $encrypted['tag'],
            $encrypted['key_version']
        );
    }

    public function testMissingKeyFailsClosed(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('COUNSELLING_KEY must contain exactly 64 hexadecimal characters.');
        new EncryptionService();
    }

    public static function invalidKeys(): iterable
    {
        foreach (['', 'not-a-key', str_repeat('a', 63), str_repeat('a', 65), str_repeat('g', 64), ' ' . self::TEST_KEY] as $key) {
            yield [$key];
        }
    }

    #[DataProvider('invalidKeys')]
    public function testMalformedKeysAreRejected(string $key): void
    {
        $this->expectException(SecurityException::class);
        new EncryptionService($key);
    }

    public static function invalidVersions(): iterable
    {
        foreach (['', '0', '-1', '1.5', '2invalid', 'true', '999999999999999999999999999999'] as $version) {
            yield [$version];
        }
    }

    #[DataProvider('invalidVersions')]
    public function testMalformedDefaultVersionsAreRejected(string $version): void
    {
        $this->setEnvironment('COUNSELLING_KEY_VERSION', $version);
        $this->expectException(SecurityException::class);
        new EncryptionService(self::TEST_KEY);
    }

    public function testConfiguredVersionOneKeyRoundtrip(): void
    {
        $this->setEnvironment('COUNSELLING_KEY', strtoupper(self::TEST_KEY));
        $crypto = new EncryptionService();
        $encrypted = $crypto->encrypt('Configured key');
        $this->assertSame('Configured key', $crypto->decrypt($encrypted['cipher'], $encrypted['nonce'], $encrypted['tag'], 1));
    }

    public function testExplicitKeyIsNotOverriddenByEnvironment(): void
    {
        $this->setEnvironment('COUNSELLING_KEY', str_repeat('cd', 32));
        $encrypted = $this->crypto->encrypt('Explicit key');
        $this->assertSame('Explicit key', (new EncryptionService(self::TEST_KEY))->decrypt($encrypted['cipher'], $encrypted['nonce'], $encrypted['tag'], 1));
    }

    public function testMissingVersionedEncryptionKeyDoesNotFallBack(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('COUNSELLING_KEY_V2');
        $this->crypto->encrypt('Must not use version one', 2);
    }

    public function testMissingVersionedDecryptionKeyDoesNotFallBack(): void
    {
        $encrypted = $this->crypto->encrypt('Version one ciphertext');
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('COUNSELLING_KEY_V2');
        $this->crypto->decrypt($encrypted['cipher'], $encrypted['nonce'], $encrypted['tag'], 2);
    }

    public function testMissingActiveVersionKeyFailsAtConstruction(): void
    {
        $this->setEnvironment('COUNSELLING_KEY_VERSION', '2');
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('COUNSELLING_KEY_V2');
        new EncryptionService(self::TEST_KEY);
    }

    public function testMalformedVersionedKeyDoesNotFallBack(): void
    {
        $this->setEnvironment('COUNSELLING_KEY_V2', 'invalid');
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('COUNSELLING_KEY_V2');
        $this->crypto->encrypt('Must not derive a key', 2);
    }

    public function testVersionedKeySupportsNewWritesAndOldReads(): void
    {
        $legacy = $this->crypto->encrypt('Existing note');
        $this->setEnvironment('COUNSELLING_KEY', self::TEST_KEY);
        $this->setEnvironment('COUNSELLING_KEY_V2', str_repeat('cd', 32));
        $this->setEnvironment('COUNSELLING_KEY_VERSION', '2');
        $crypto = new EncryptionService();
        $encrypted = $crypto->encrypt('New note');
        $this->assertSame(2, $encrypted['key_version']);
        $this->assertSame('New note', $crypto->decrypt($encrypted['cipher'], $encrypted['nonce'], $encrypted['tag'], 2));
        $this->assertSame('Existing note', $crypto->decrypt($legacy['cipher'], $legacy['nonce'], $legacy['tag'], 1));
    }

    public function testExistingCiphertextFormatRemainsReadable(): void
    {
        $nonce = str_repeat("\x01", 12);
        $tag = '';
        $cipher = openssl_encrypt('Legacy note', 'aes-256-gcm', hex2bin(self::TEST_KEY), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        $this->assertNotFalse($cipher);
        $this->assertSame('Legacy note', $this->crypto->decrypt($cipher, $nonce, $tag, 1));
    }

    public static function invalidEnvelopeLengths(): iterable
    {
        yield 'empty tag' => [12, 0];
        yield 'one-byte valid tag prefix' => [12, 1];
        yield '15-byte valid tag prefix' => [12, 15];
        yield 'oversized tag' => [12, 17];
        yield 'empty nonce' => [0, 16];
        yield 'short nonce' => [11, 16];
        yield 'long nonce' => [13, 16];
    }

    #[DataProvider('invalidEnvelopeLengths')]
    public function testInvalidEnvelopeLengthsAreRejected(int $nonceLength, int $tagLength): void
    {
        $encrypted = $this->crypto->encrypt('Authenticated note');
        $nonce = substr($encrypted['nonce'] . "\x00", 0, $nonceLength);
        $tag = substr($encrypted['tag'] . "\x00", 0, $tagLength);
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('invalid nonce or authentication tag length');
        $this->crypto->decrypt($encrypted['cipher'], $nonce, $tag, 1);
    }

    public function testLegacyVersionFallbackRequiresExplicitMatchingKey(): void
    {
        $encrypted = $this->crypto->encrypt('Historically mislabeled version two');
        $this->setEnvironment('COUNSELLING_KEY_V2', self::TEST_KEY);
        $this->assertSame('Historically mislabeled version two', $this->crypto->decrypt($encrypted['cipher'], $encrypted['nonce'], $encrypted['tag'], 2));
    }

    public function testLegacyDerivedKeyRequiresExplicitRecoveredBinaryKey(): void
    {
        $legacyKey = 'synthetic-malformed-test-key';
        $nonce = str_repeat("\x02", 12);
        $tag = '';
        $cipher = openssl_encrypt('Legacy derived-key note', 'aes-256-gcm', hash('sha256', $legacyKey, true), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        $this->assertNotFalse($cipher);
        $crypto = new EncryptionService(hash('sha256', $legacyKey));
        $this->assertSame('Legacy derived-key note', $crypto->decrypt($cipher, $nonce, $tag, 1));
    }

    public function testInvalidEncryptionVersionIsRejected(): void
    {
        $this->expectException(SecurityException::class);
        $this->crypto->encrypt('Invalid version', 0);
    }

    public function testInvalidDecryptionVersionIsRejected(): void
    {
        $encrypted = $this->crypto->encrypt('Invalid version');
        $this->expectException(SecurityException::class);
        $this->crypto->decrypt($encrypted['cipher'], $encrypted['nonce'], $encrypted['tag'], -1);
    }

    public function testDecryptionFailsOnForgedTag(): void
    {
        $plaintext = 'Authentic clinical note.';
        $encrypted = $this->crypto->encrypt($plaintext);

        // Tamper with authentication tag
        $tamperedTag = random_bytes(16);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('authenticity tag verification rejected');

        $this->crypto->decrypt(
            $encrypted['cipher'],
            $encrypted['nonce'],
            $tamperedTag,
            $encrypted['key_version']
        );
    }
}

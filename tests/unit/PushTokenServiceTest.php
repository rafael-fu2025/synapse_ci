<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Push\PushTokenService;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use RuntimeException;

/**
 * No database access: only the boundary checks that run before any storage.
 */
final class PushTokenServiceTest extends CIUnitTestCase
{
    private const IDENTITY = ['id' => 5, 'tenant_id' => 7];

    public function testAMalformedDeviceTokenIsRejectedBeforeAnyStorage(): void
    {
        $service = new PushTokenService();
        foreach (['', '   ', 'short', 'token with spaces', str_repeat('a', 513), "token\nnewline"] as $token) {
            try {
                $service->register(self::IDENTITY, $token, 'android', null, null);
                self::fail('A malformed device token must be rejected: ' . $token);
            } catch (InvalidArgumentException $error) {
                self::assertSame(422, $error->getCode());
            }
        }
    }

    public function testAnUnknownPlatformIsRejectedBeforeAnyStorage(): void
    {
        $service = new PushTokenService();
        foreach (['web', 'desktop', 'ANDROIDX', 'android/ios', ''] as $platform) {
            try {
                $service->register(self::IDENTITY, str_repeat('t', 40), $platform, null, null);
                self::fail('An unknown device platform must be rejected: ' . $platform);
            } catch (InvalidArgumentException $error) {
                self::assertSame(422, $error->getCode());
            }
        }
    }

    public function testAnUnauthenticatedCallerCannotRegisterOrRevokeAToken(): void
    {
        $service = new PushTokenService();
        foreach ([[], ['id' => 0, 'tenant_id' => 7], ['id' => 5, 'tenant_id' => 0]] as $identity) {
            try {
                $service->register($identity, str_repeat('t', 40), 'android', null, null);
                self::fail('An unauthenticated caller must not register a token.');
            } catch (RuntimeException $error) {
                self::assertSame(401, $error->getCode());
            }
            try {
                $service->revoke($identity, str_repeat('t', 40));
                self::fail('An unauthenticated caller must not revoke a token.');
            } catch (RuntimeException $error) {
                self::assertSame(401, $error->getCode());
            }
        }
    }

    public function testRevokingWithoutATokenIsRejected(): void
    {
        $service = new PushTokenService();
        try {
            $service->revoke(self::IDENTITY, '   ');
            self::fail('Revoking without a token must be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertSame(422, $error->getCode());
        }
    }
}

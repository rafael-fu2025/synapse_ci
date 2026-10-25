<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Realtime\Channel;
use App\Services\Realtime\TicketIssuer;
use App\Services\Realtime\TicketSigner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Config\Factories;
use Config\Realtime;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class RealtimeTicketIssuerTest extends CIUnitTestCase
{
    private function settings(): Realtime
    {
        $config = new Realtime();
        $config->enabled = true;
        $config->signingKey = str_repeat('s', 32);
        $config->publishKey = str_repeat('p', 32);
        $config->allowedOrigins = 'https://portal.example.test';
        $config->publicUrl = 'wss://socket.example.test/realtime';
        return $config;
    }

    public function testOnlyOwnTenantUserChannelIsGrantedEvenToSuperadmin(): void
    {
        $config = $this->settings();
        $payload = (new TicketIssuer($config))->issue([
            'id' => 8, 'tenant_id' => 2, 'logged_in' => true, 'role' => 'superadmin',
            'channels' => ['private.tenant.1.user.1', 'private.bmg.unit.1'],
        ], 'https://portal.example.test');
        $claims = (new TicketSigner($config->signingKey))->verify($payload['ticket'], 'https://portal.example.test');
        $this->assertSame([Channel::user(2, 8)], $claims['channels']);
        $this->assertSame($claims['exp'], $payload['expires_at']);
        $this->assertSame($config->publicUrl, $payload['url']);
        $this->assertArrayNotHasKey('signingKey', $payload);
        $this->assertArrayNotHasKey('publishKey', $payload);
    }

    public static function invalidActors(): iterable
    {
        yield [[]];
        yield [['id' => 1, 'tenant_id' => 2]];
        yield [['id' => 0, 'tenant_id' => 2, 'logged_in' => true]];
        yield [['id' => '1', 'tenant_id' => 2, 'logged_in' => true]];
        yield [['id' => 1, 'tenant_id' => 0, 'logged_in' => true]];
        yield [['id' => 1, 'tenant_id' => 2, 'logged_in' => false]];
    }

    #[DataProvider('invalidActors')]
    public function testInvalidIdentityCannotObtainTicket(array $actor): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        (new TicketIssuer($this->settings()))->issue($actor, 'https://portal.example.test');
    }

    public function testDisabledDoesNotIssueTicketOrRequireSecrets(): void
    {
        $config = new Realtime();
        $config->enabled = false;
        $this->assertSame(['enabled' => false], (new TicketIssuer($config))->issue([
            'id' => 1, 'tenant_id' => 2, 'logged_in' => true,
        ], 'https://portal.example.test'));
    }

    public function testOtherOriginIsDenied(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        (new TicketIssuer($this->settings()))->issue([
            'id' => 1, 'tenant_id' => 2, 'logged_in' => true,
        ], 'https://attacker.example.test');
    }

    public function testHttpsPortalCannotReceiveInsecureWebSocketUrl(): void
    {
        $config = $this->settings();
        $config->publicUrl = 'ws://127.0.0.1:8081';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(503);
        (new TicketIssuer($config))->issue([
            'id' => 1, 'tenant_id' => 2, 'logged_in' => true,
        ], 'https://portal.example.test');
    }

    public function testEmptyPublicUrlIssuesSocketForTheRequestingLanOrigin(): void
    {
        $config = $this->settings();
        $config->allowedOrigins = 'http://192.168.1.8:8080';
        $config->publicUrl = '';
        $config->listen = 'websocket://0.0.0.0:8081';
        $payload = (new TicketIssuer($config))->issue([
            'id' => 1, 'tenant_id' => 2, 'logged_in' => true,
        ], 'http://192.168.1.8:8080');
        $this->assertSame('ws://192.168.1.8:8081', $payload['url']);
    }

    public function testCspAllowsOnlyConfiguredSocketOrigin(): void
    {
        Factories::injectMock('config', Realtime::class, $this->settings());
        try {
            $csp = new \Config\ContentSecurityPolicy();
            $this->assertSame(['self', 'wss://socket.example.test'], $csp->connectSrc);
            $invalid = $this->settings();
            $invalid->signingKey = '';
            Factories::injectMock('config', Realtime::class, $invalid);
            $this->assertSame('self', (new \Config\ContentSecurityPolicy())->connectSrc);
        } finally {
            Factories::reset('config');
        }
    }
}

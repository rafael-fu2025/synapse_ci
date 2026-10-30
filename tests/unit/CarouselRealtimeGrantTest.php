<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CurrentTenant;
use App\Services\Realtime\CarouselTicketIssuer;
use App\Services\Realtime\Channel;
use App\Services\Realtime\Hub;
use App\Services\Realtime\PublishEndpoint;
use App\Services\Realtime\TicketIssuer;
use App\Services\Realtime\TicketSigner;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use CodeIgniter\Throttle\Throttler;
use Config\Realtime;
use Config\Services;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class CarouselGrantConnection
{
    public array $sent = [];
    public bool $closed = false;

    public function send(string $message): void
    {
        $this->sent[] = json_decode($message, true, 32, JSON_THROW_ON_ERROR);
    }

    public function destroy(): void
    {
        $this->closed = true;
    }
}

/** Real public route and Hub, mocked throttle storage, no database or authenticated identity. */
final class CarouselRealtimeGrantTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const PATH = 'api/v1/auth/carousel/realtime';
    private const ORIGIN = 'http://127.0.0.1:8080';
    private Realtime $settings;
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSession = $_SESSION ?? [];
        $_SESSION = [];
        CurrentTenant::set(7);
        $this->settings = new Realtime();
        $this->settings->enabled = true;
        $this->settings->signingKey = str_repeat('s', 32);
        $this->settings->publishKey = str_repeat('p', 32);
        $this->settings->allowedOrigins = self::ORIGIN;
        $this->settings->listen = 'websocket://127.0.0.1:8081';
        $this->settings->publishListen = 'http://127.0.0.1:8082';
        $this->settings->publicUrl = '';
        $this->settings->ticketTtl = 60;
        $this->settings->authTimeout = 5;
        Factories::injectMock('config', Realtime::class, $this->settings);
        $throttler = $this->createMock(Throttler::class);
        $throttler->method('check')->willReturn(true);
        Services::injectMock('throttler', $throttler);
    }

    protected function tearDown(): void
    {
        CurrentTenant::reset();
        $_SESSION = $this->savedSession;
        Factories::reset('config');
        Services::resetSingle('throttler');
        parent::tearDown();
    }

    private function body(TestResponse $result, int $status = 200): array
    {
        $result->assertStatus($status);
        self::assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        return json_decode((string) $result->getJSON(), true, 32, JSON_THROW_ON_ERROR);
    }

    private function signer(): TicketSigner
    {
        return new TicketSigner($this->settings->signingKey, $this->settings->ticketTtl);
    }

    private function authenticate(Hub $hub, string $ticket, string $origin, int $now): CarouselGrantConnection
    {
        $client = new CarouselGrantConnection();
        $hub->open($client, $now);
        self::assertTrue($hub->handshake($client, $origin));
        $hub->message($client, json_encode(['type' => 'authenticate', 'ticket' => $ticket], JSON_THROW_ON_ERROR), $now);
        return $client;
    }

    public function testOnlyPublicGetRouteIsAddedAndPrivateRoutesRemainAuthenticated(): void
    {
        $routes = Services::routes();
        $routes->loadRoutes();
        self::assertStringEndsWith('LoginCarouselController::realtime', $routes->getRoutes('GET')[self::PATH]);
        self::assertSame([], $routes->getFiltersForRoute(self::PATH, 'GET'));
        self::assertArrayNotHasKey(self::PATH, $routes->getRoutes('POST'));
        self::assertStringEndsWith('LoginCarouselController::index', $routes->getRoutes('GET')['api/v1/auth/carousel']);
        self::assertContains('api_auth', $routes->getFiltersForRoute('api/v1/me', 'GET'));
        self::assertContains('auth', $routes->getFiltersForRoute('realtime/ticket', 'POST'));
    }

    public function testUnauthenticatedEndpointIgnoresAllTenantChannelAndOriginSelectors(): void
    {
        $before = time();
        $body = $this->body($this->withHeaders([
            'Origin' => self::ORIGIN, 'X-Tenant-ID' => '99', 'X-Channel' => 'private.tenant.99.user.1',
        ])->get(self::PATH . '?tenant=99&tenant_id=99&user_id=1&channel=private.tenant.99.user.1'
            . '&channels[]=private.user.1&channels[]=public.tenant.99.login-carousel&origin=https://attacker.test'));
        self::assertSame(['data'], array_keys($body));
        $grant = $body['data'];
        self::assertSame(['enabled', 'url', 'origin', 'channel', 'ticket', 'expires_at'], array_keys($grant));
        self::assertTrue($grant['enabled']);
        self::assertSame('ws://127.0.0.1:8081', $grant['url']);
        self::assertSame(self::ORIGIN, $grant['origin']);
        self::assertSame(Channel::loginCarousel(7), $grant['channel']);
        self::assertIsInt($grant['expires_at']);
        self::assertGreaterThanOrEqual($before + 60, $grant['expires_at']);
        self::assertLessThanOrEqual(time() + 60, $grant['expires_at']);
        $claims = $this->signer()->verify($grant['ticket'], self::ORIGIN);
        self::assertSame([Channel::loginCarousel(7)], $claims['channels']);
        self::assertSame($grant['expires_at'], $claims['exp']);
    }

    public function testTrustedTenantChangesGrantWithoutAcceptingRequestTenant(): void
    {
        CurrentTenant::set(12);
        $grant = $this->body($this->withHeaders(['Origin' => self::ORIGIN])->get(self::PATH . '?tenant_id=7'))['data'];
        self::assertSame([Channel::loginCarousel(12)], $this->signer()->verify($grant['ticket'], self::ORIGIN)['channels']);
    }

    public function testDisabledNeedsNeitherSecretsNorOriginAndReturnsNoGrant(): void
    {
        $this->settings->enabled = false;
        $this->settings->signingKey = '';
        $this->settings->publishKey = '';
        $this->settings->allowedOrigins = '';
        self::assertSame(['data' => ['enabled' => false]], $this->body($this->get(self::PATH)));
    }

    public static function forbiddenOrigins(): iterable
    {
        foreach (['', 'null', '*', 'http://localhost:8080', 'http://127.0.0.1:8080/',
            'http://127.0.0.1:8080.attacker.test', 'https://127.0.0.1:8080',
            'http://127.0.0.1:8080 http://attacker.test', 'http://127.0.0.1:8080, http://attacker.test'] as $origin) {
            yield [$origin];
        }
    }

    #[DataProvider('forbiddenOrigins')]
    public function testMissingMalformedAndNonExactOriginsAreForbidden(string $origin): void
    {
        self::assertSame(['error' => ['code' => 'forbidden', 'message' => 'Realtime origin not allowed.']],
            $this->body($this->withHeaders(['Origin' => $origin])->get(self::PATH), 403));
    }

    public static function invalidSettings(): iterable
    {
        yield 'missing signing key' => [['signingKey' => '']];
        yield 'missing publishing key' => [['publishKey' => '']];
        yield 'shared keys' => [['publishKey' => str_repeat('s', 32)]];
        yield 'no origins' => [['allowedOrigins' => '']];
        yield 'wildcard origin' => [['allowedOrigins' => '*']];
        yield 'unsafe public URL' => [['publicUrl' => 'wss://user:secret@socket.test']];
        yield 'zero TTL' => [['ticketTtl' => 0]];
        yield 'excessive TTL' => [['ticketTtl' => 301]];
        yield 'non-loopback publisher' => [['publishListen' => 'http://0.0.0.0:8082']];
        yield 'HTTPS without WSS' => [['allowedOrigins' => 'https://portal.test', 'publicUrl' => 'ws://socket.test']];
    }

    #[DataProvider('invalidSettings')]
    public function testMisconfigurationReturnsOnlyGenericUnavailable(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->settings->{$key} = $value;
        }
        $origin = str_starts_with($this->settings->allowedOrigins, 'https://')
            ? $this->settings->allowedOrigins : self::ORIGIN;
        self::assertSame(['error' => ['code' => 'service_unavailable', 'message' => 'Realtime is unavailable.']],
            $this->body($this->withHeaders(['Origin' => $origin])->get(self::PATH), 503));
    }

    public function testInvalidTrustedTenantFailsClosed(): void
    {
        foreach ([0, -1] as $tenantId) {
            CurrentTenant::set($tenantId);
            self::assertSame(['error' => ['code' => 'service_unavailable', 'message' => 'Realtime is unavailable.']],
                $this->body($this->withHeaders(['Origin' => self::ORIGIN])->get(self::PATH), 503));
        }
    }

    public function testHttpsUsesWssAndEchoesOnlyTheAllowedOrigin(): void
    {
        $this->settings->allowedOrigins = 'https://portal.test';
        $this->settings->publicUrl = 'wss://socket.test/realtime';
        $grant = (new CarouselTicketIssuer($this->settings))->issue(7, 'https://portal.test');
        self::assertSame('wss://socket.test/realtime', $grant['url']);
        self::assertSame('https://portal.test', $grant['origin']);
    }

    public function testIpThrottleIsBoundedHashedAndReturnsGenericNoStoreError(): void
    {
        $throttler = $this->createMock(Throttler::class);
        $throttler->expects(self::once())->method('check')->with(
            self::matchesRegularExpression('/\Acarousel-realtime-ip-[a-f0-9]{64}\z/D'), 120, 60
        )->willReturn(false);
        Services::injectMock('throttler', $throttler);
        $result = $this->withHeaders(['Origin' => self::ORIGIN])->get(self::PATH);
        self::assertSame(['error' => [
            'code' => 'rate_limited', 'message' => 'Too many realtime requests. Please try again shortly.',
        ]], $this->body($result, 429));
        self::assertSame('60', $result->response()->getHeaderLine('Retry-After'));
    }

    public static function invalidPublicChannels(): iterable
    {
        foreach (['public.news', 'public.tenant.0.login-carousel', 'public.tenant.-1.login-carousel',
            'public.tenant.01.login-carousel', 'public.tenant.*.login-carousel', 'public.tenant.1.*',
            'public.tenant.1.user.1', 'public.tenant.1.login-carousel.extra', 'public.tenant.1.login-carousel,private.user.1',
            "public.tenant.1.login-carousel\n", 'public.tenant.1.login-carousel|private.user.1',
            'public.tenant.1.login-carousel private.user.1', 'public.tenant.1.login-carousel/private.user.1'] as $channel) {
            yield [$channel];
        }
    }

    #[DataProvider('invalidPublicChannels')]
    public function testOnlyTheExactPublicChannelShapeCanBeSigned(string $channel): void
    {
        self::assertFalse(Channel::isValid($channel));
        $this->expectException(InvalidArgumentException::class);
        $this->signer()->issue([$channel], self::ORIGIN);
    }

    public function testChannelHelperRejectsNonpositiveTenantAndPreservesPrivateSyntax(): void
    {
        self::assertSame('public.tenant.7.login-carousel', Channel::loginCarousel(7));
        foreach (['private.user.1', 'private.tenant.7.user.2', 'private.bmg.unit.1', 'private.A-b_c.01'] as $channel) {
            self::assertTrue(Channel::isValid($channel));
        }
        foreach ([0, -1] as $tenantId) {
            try {
                Channel::loginCarousel($tenantId);
                self::fail('Nonpositive tenant accepted.');
            } catch (InvalidArgumentException) {
                self::assertFalse(Channel::isValid("public.tenant.{$tenantId}.login-carousel"));
            }
        }
    }

    public function testActualHubIsolatesPublicTenantFromPrivateAndOtherTenants(): void
    {
        $grant = $this->body($this->withHeaders(['Origin' => self::ORIGIN])->get(self::PATH))['data'];
        $otherGrant = (new CarouselTicketIssuer($this->settings))->issue(8, self::ORIGIN);
        $privateGrant = (new TicketIssuer($this->settings))->issue([
            'id' => 2, 'tenant_id' => 7, 'logged_in' => true,
        ], self::ORIGIN);
        // All grants must exist before choosing the verification clock: issuance can cross a second boundary.
        $now = time();
        $hub = new Hub($this->settings);
        $public = $this->authenticate($hub, $grant['ticket'], self::ORIGIN, $now);
        $other = $this->authenticate($hub, $otherGrant['ticket'], self::ORIGIN, $now);
        $private = $this->authenticate($hub, $privateGrant['ticket'], self::ORIGIN, $now);
        self::assertSame([Channel::user(7, 2)], $this->signer()->verify($privateGrant['ticket'], self::ORIGIN)['channels']);

        $hub->publish(Channel::loginCarousel(7), 'login.carousel.changed', [], $now);
        self::assertSame([
            ['type' => 'ready'],
            ['type' => 'event', 'channel' => Channel::loginCarousel(7), 'event' => 'login.carousel.changed', 'data' => []],
        ], $public->sent);
        self::assertSame([['type' => 'ready']], $other->sent);
        self::assertSame([['type' => 'ready']], $private->sent);
        $hub->publish(Channel::loginCarousel(8), 'login.carousel.changed', [], $now);
        foreach ([Channel::personal(2), Channel::user(7, 2), Channel::user(8, 2), 'private.bmg.unit.1'] as $channel) {
            $hub->publish($channel, 'changed', ['secret' => 'never public'], $now);
        }
        self::assertCount(2, $public->sent);
        self::assertCount(2, $other->sent);
        self::assertCount(2, $private->sent);
        $hub->message($public, '{"type":"ping"}', $now + 1);
        self::assertSame(['type' => 'pong'], $public->sent[2]);
        $hub->publish(Channel::loginCarousel(7), 'login.carousel.changed', [], $grant['expires_at']);
        self::assertTrue($public->closed);
        self::assertCount(3, $public->sent);
    }

    public function testPublicClientCannotPublishSubscribeReauthenticateOrUsePublishCredential(): void
    {
        $grant = (new CarouselTicketIssuer($this->settings))->issue(7, self::ORIGIN);
        $now = $grant['expires_at'] - $this->settings->ticketTtl;
        $hub = new Hub($this->settings);
        foreach ([
            ['type' => 'subscribe', 'channel' => Channel::user(7, 2)],
            ['type' => 'subscribe', 'channel' => Channel::loginCarousel(8)],
            ['type' => 'publish', 'channel' => Channel::loginCarousel(7), 'event' => 'login.carousel.changed', 'data' => []],
            ['type' => 'authenticate', 'ticket' => $grant['ticket']],
        ] as $message) {
            $client = $this->authenticate($hub, $grant['ticket'], self::ORIGIN, $now);
            self::assertSame([['type' => 'ready']], $client->sent);
            $hub->message($client, json_encode($message, JSON_THROW_ON_ERROR), $now);
            self::assertTrue($client->closed);
            self::assertCount(1, $client->sent);
        }
        $endpoint = new PublishEndpoint($this->settings, $hub);
        self::assertSame(403, $endpoint->handle('POST', '/publish', 'Bearer ' . $grant['ticket'], '',
            json_encode(['channel' => $grant['channel'], 'event' => 'login.carousel.changed', 'data' => []], JSON_THROW_ON_ERROR)));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        (new TicketIssuer($this->settings))->issue([], self::ORIGIN);
    }

    public function testActualHubRejectsExpiredTamperedAndWrongOriginPublicTickets(): void
    {
        $this->settings->allowedOrigins .= ',http://localhost:8080';
        $grant = (new CarouselTicketIssuer($this->settings))->issue(7, self::ORIGIN);
        $now = $grant['expires_at'] - $this->settings->ticketTtl;
        self::assertNotNull($this->signer()->verify($grant['ticket'], self::ORIGIN, $grant['expires_at'] - 1));
        self::assertNull($this->signer()->verify($grant['ticket'], self::ORIGIN, $grant['expires_at']));
        [$payload, $signature] = explode('.', $grant['ticket']);
        $claims = $this->signer()->verify($grant['ticket'], self::ORIGIN, $now);
        $claims['channels'][] = Channel::user(7, 2);
        $tampered = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=') . '.' . $signature;
        $hub = new Hub($this->settings);
        foreach ([[$tampered, self::ORIGIN, $now], [$grant['ticket'], 'http://localhost:8080', $now],
            [$grant['ticket'], self::ORIGIN, $grant['expires_at']]] as [$ticket, $origin, $authTime]) {
            self::assertNull($this->signer()->verify($ticket, $origin, $authTime));
            $client = $this->authenticate($hub, $ticket, $origin, $authTime);
            self::assertTrue($client->closed);
            self::assertSame([], $client->sent);
        }
        $pending = new CarouselGrantConnection();
        $hub->open($pending, $now);
        self::assertFalse($hub->handshake($pending, 'http://attacker.test'));
        $hub->message($pending, json_encode(['type' => 'authenticate', 'ticket' => $grant['ticket']], JSON_THROW_ON_ERROR), $now);
        self::assertTrue($pending->closed);
        self::assertSame([], $pending->sent);
    }
}

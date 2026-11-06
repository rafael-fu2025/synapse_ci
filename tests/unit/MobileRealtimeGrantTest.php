<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\Api\ApiContext;
use App\Services\Api\TokenService;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Realtime\Channel;
use App\Services\Realtime\Hub;
use App\Services\Realtime\TicketSigner;
use App\Services\Realtime\UserTicketIssuer;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use CodeIgniter\Throttle\Throttler;
use Config\Database;
use Config\Realtime;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

final class MobileGrantConnection
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

/** Real bearer-authenticated route and Hub, mocked throttle storage, one fixture identity per test. */
#[Group('mobile-realtime-grant')]
final class MobileRealtimeGrantTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\\Database\\Seeds\\PermissionsAndRolesSeeder';

    private const PATH = 'api/v1/realtime/grant';
    private const ORIGIN = 'http://127.0.0.1:8080';
    private Realtime $settings;
    private array $savedSession;
    private int $userId = 0;
    private int $personId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSession = $_SESSION ?? [];
        $_SESSION = [];
        CurrentTenant::set(1);
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
        AuthenticatedUser::clear();
        ApiContext::clear();
        CurrentTenant::reset();
        $db = Database::connect();
        if ($this->userId !== 0) {
            $db->table('api_tokens')->where('user_id', $this->userId)->delete();
            $db->table('users')->where('id', $this->userId)->delete();
        }
        if ($this->personId !== 0) {
            $db->table('people')->where('id', $this->personId)->delete();
        }
        $this->userId = 0;
        $this->personId = 0;
        $_SESSION = $this->savedSession;
        Factories::reset('config');
        Services::resetSingle('throttler');
        parent::tearDown();
    }

    private function account(): int
    {
        $unique = uniqid('mobile_realtime_', true);
        $this->personId = (int) (new PersonModel())->insert([
            'tenant_id' => 1,
            'kind' => 'student',
            'mis_id' => $unique,
            'first_name' => 'Realtime',
            'last_name' => 'Fixture',
        ]);
        $this->userId = (int) (new UserModel())->insert([
            'tenant_id' => 1,
            'person_id' => $this->personId,
            'username' => $unique,
            'role' => 'student',
            'status' => 'active',
        ]);
        return $this->userId;
    }

    private function accessToken(): string
    {
        $identity = (new AuthenticatedUser())->forIdentity($this->userId, 1);
        $this->assertNotNull($identity, 'fixture identity must validate');
        return (string) (new TokenService())->issue($identity, 'mobile-realtime-test', '127.0.0.1')['access_token'];
    }

    private function send(?string $token, array $headers = [], string $path = self::PATH): TestResponse
    {
        if ($token !== null) {
            $headers += ['Authorization' => 'Bearer ' . $token];
        }
        return $this->withHeaders($headers)->get($path);
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

    public function testGrantRouteIsBearerAuthenticatedWithinTheMobileApiGroup(): void
    {
        $routes = Services::routes();
        $routes->loadRoutes();
        self::assertStringEndsWith('RealtimeGrantController::show', $routes->getRoutes('GET')[self::PATH]);
        self::assertContains('api_auth', $routes->getFiltersForRoute(self::PATH, 'GET'));
        self::assertArrayNotHasKey(self::PATH, $routes->getRoutes('POST'));
        // The browser CSRF ticket contract remains untouched.
        self::assertContains('auth', $routes->getFiltersForRoute('realtime/ticket', 'POST'));
    }

    public function testMissingTokenIsRejectedBeforeAnyRealtimeWork(): void
    {
        foreach ([[], ['Origin' => self::ORIGIN]] as $headers) {
            $body = $this->body($this->send(null, $headers), 401);
            self::assertSame('invalid_token', $body['error']['code']);
        }
    }

    public function testGrantBindsOnlyTheCallerOwnPrivateUserChannel(): void
    {
        $userId = $this->account();
        $before = time();
        $body = $this->body($this->send($this->accessToken(), ['Origin' => self::ORIGIN]));
        self::assertSame(['data'], array_keys($body));
        $grant = $body['data'];
        self::assertSame(['enabled', 'url', 'origin', 'channel', 'ticket', 'expires_at'], array_keys($grant));
        self::assertTrue($grant['enabled']);
        self::assertSame('ws://127.0.0.1:8081', $grant['url']);
        self::assertSame(self::ORIGIN, $grant['origin']);
        self::assertSame(Channel::user(1, $userId), $grant['channel']);
        self::assertIsInt($grant['expires_at']);
        self::assertGreaterThanOrEqual($before + 60, $grant['expires_at']);
        self::assertLessThanOrEqual(time() + 60, $grant['expires_at']);
        $claims = $this->signer()->verify($grant['ticket'], self::ORIGIN);
        self::assertSame([Channel::user(1, $userId)], $claims['channels']);
        self::assertSame($grant['expires_at'], $claims['exp']);
    }

    public function testRequestSelectorsNeverInfluenceTheGrant(): void
    {
        $userId = $this->account();
        $grant = $this->body($this->send($this->accessToken(), [
            'Origin' => self::ORIGIN, 'X-Tenant-ID' => '99',
        ], self::PATH . '?tenant_id=99&user_id=99&channel=private.tenant.99.user.99'
            . '&channels[]=private.user.99&channels[]=public.tenant.99.login-carousel'))['data'];
        self::assertSame(Channel::user(1, $userId), $grant['channel']);
        self::assertSame([Channel::user(1, $userId)], $this->signer()->verify($grant['ticket'], self::ORIGIN)['channels']);
    }

    public function testDisabledConfigurationNeedsNoOriginAndReturnsNoGrant(): void
    {
        $this->account();
        $this->settings->enabled = false;
        $this->settings->signingKey = '';
        $this->settings->publishKey = '';
        $this->settings->allowedOrigins = '';
        self::assertSame(['data' => ['enabled' => false]], $this->body($this->send($this->accessToken())));
    }

    public static function forbiddenOrigins(): iterable
    {
        foreach (['', 'null', '*', 'http://localhost:8080', 'http://127.0.0.1:8080/',
            'https://127.0.0.1:8080', 'http://127.0.0.1:8080.attacker.test',
            'http://127.0.0.1:8080 http://attacker.test', 'http://127.0.0.1:8080, http://attacker.test'] as $origin) {
            yield [$origin];
        }
    }

    #[DataProvider('forbiddenOrigins')]
    public function testMissingMalformedAndNonExactOriginsAreForbidden(string $origin): void
    {
        $this->account();
        self::assertSame(['error' => ['code' => 'forbidden', 'message' => 'Realtime origin not allowed.']],
            $this->body($this->send($this->accessToken(), ['Origin' => $origin]), 403));
    }

    public function testMisconfigurationReturnsOnlyGenericUnavailable(): void
    {
        $this->account();
        foreach ([['signingKey' => ''], ['publishKey' => ''], ['ticketTtl' => 0], ['ticketTtl' => 301],
            ['allowedOrigins' => ''], ['publicUrl' => 'wss://user:secret@socket.test']] as $settings) {
            foreach ($settings as $key => $value) {
                $this->settings->{$key} = $value;
            }
            $origin = str_starts_with($this->settings->allowedOrigins, 'https://')
                ? $this->settings->allowedOrigins : self::ORIGIN;
            self::assertSame(['error' => ['code' => 'service_unavailable', 'message' => 'Realtime is unavailable.']],
                $this->body($this->send($this->accessToken(), ['Origin' => $origin]), 503),
                'Misconfigured key ' . $key . ' must fail closed.');
        }
    }

    public function testPerUserThrottleIsBoundedAndReturnsGenericNoStoreError(): void
    {
        $userId = $this->account();
        $captured = [];
        $throttler = $this->createMock(Throttler::class);
        $throttler->method('check')->willReturnCallback(
            function (string $key, int $capacity, int $seconds) use (&$captured): bool {
                $captured[] = [$key, $capacity, $seconds];
                // The two ApiAuthFilter budgets stay healthy; only the grant budget is exhausted.
                return ! str_starts_with($key, 'realtime-grant-user-');
            }
        );
        Services::injectMock('throttler', $throttler);
        $result = $this->send($this->accessToken(), ['Origin' => self::ORIGIN]);
        self::assertSame(['error' => [
            'code' => 'rate_limited', 'message' => 'Too many realtime requests. Please try again shortly.',
        ]], $this->body($result, 429));
        self::assertSame('60', $result->response()->getHeaderLine('Retry-After'));
        self::assertContains(['realtime-grant-user-1-' . $userId, 60, 60], $captured);
    }

    public function testHttpsOriginsRequireWssAndEchoOnlyTheAllowedOrigin(): void
    {
        $userId = $this->account();
        $this->settings->allowedOrigins = 'https://portal.test';
        $this->settings->publicUrl = 'wss://socket.test/realtime';
        $grant = (new UserTicketIssuer($this->settings))->issue(1, $userId, 'https://portal.test');
        self::assertSame('wss://socket.test/realtime', $grant['url']);
        self::assertSame('https://portal.test', $grant['origin']);
        self::assertSame([Channel::user(1, $userId)], $this->signer()->verify($grant['ticket'], 'https://portal.test')['channels']);
    }

    public function testActualHubDeliversPersonalHintsOnlyOnTheGrantedChannel(): void
    {
        $userId = $this->account();
        $grant = $this->body($this->send($this->accessToken(), ['Origin' => self::ORIGIN]))['data'];
        $otherGrant = (new UserTicketIssuer($this->settings))->issue(1, $userId + 1, self::ORIGIN);
        $now = time();
        $hub = new Hub($this->settings);
        $own = new MobileGrantConnection();
        $other = new MobileGrantConnection();
        foreach ([[$own, $grant], [$other, $otherGrant]] as [$client, $payload]) {
            $hub->open($client, $now);
            self::assertTrue($hub->handshake($client, self::ORIGIN));
            $hub->message($client, json_encode(['type' => 'authenticate', 'ticket' => $payload['ticket']], JSON_THROW_ON_ERROR), $now);
            self::assertSame([['type' => 'ready']], $client->sent);
        }

        $hub->publish(Channel::user(1, $userId), 'notifications.changed', [], $now);
        self::assertSame([
            ['type' => 'ready'],
            ['type' => 'event', 'channel' => Channel::user(1, $userId), 'event' => 'notifications.changed', 'data' => []],
        ], $own->sent);
        self::assertSame([['type' => 'ready']], $other->sent);
        $hub->publish(Channel::user(1, $userId + 1), 'portal.queue.changed', [], $now);
        $hub->publish(Channel::user(9, $userId), 'notifications.changed', [], $now);
        self::assertCount(2, $own->sent);
        self::assertCount(2, $other->sent);
        self::assertSame([
            ['type' => 'ready'],
            ['type' => 'event', 'channel' => Channel::user(1, $userId + 1), 'event' => 'portal.queue.changed', 'data' => []],
        ], $other->sent);
    }

    public function testGrantClientCannotSubscribePublishOrReuseThePublishCredential(): void
    {
        $this->account();
        $grant = $this->body($this->send($this->accessToken(), ['Origin' => self::ORIGIN]))['data'];
        $now = $grant['expires_at'] - $this->settings->ticketTtl;
        $hub = new Hub($this->settings);
        foreach ([
            ['type' => 'subscribe', 'channel' => Channel::user(1, $this->userId + 5)],
            ['type' => 'publish', 'channel' => $grant['channel'], 'event' => 'notifications.changed', 'data' => []],
            ['type' => 'authenticate', 'ticket' => $grant['ticket']],
        ] as $message) {
            $client = new MobileGrantConnection();
            $hub->open($client, $now);
            self::assertTrue($hub->handshake($client, self::ORIGIN));
            $hub->message($client, json_encode(['type' => 'authenticate', 'ticket' => $grant['ticket']], JSON_THROW_ON_ERROR), $now);
            self::assertSame([['type' => 'ready']], $client->sent);
            $hub->message($client, json_encode($message, JSON_THROW_ON_ERROR), $now);
            self::assertTrue($client->closed);
            self::assertCount(1, $client->sent);
        }
    }

    public function testExpiredTamperedAndWrongOriginGrantTicketsAreRejected(): void
    {
        $userId = $this->account();
        $this->settings->allowedOrigins .= ',http://localhost:8080';
        $grant = (new UserTicketIssuer($this->settings))->issue(1, $userId, self::ORIGIN);
        $now = $grant['expires_at'] - $this->settings->ticketTtl;
        self::assertNotNull($this->signer()->verify($grant['ticket'], self::ORIGIN, $grant['expires_at'] - 1));
        self::assertNull($this->signer()->verify($grant['ticket'], self::ORIGIN, $grant['expires_at']));
        [$payload, $signature] = explode('.', $grant['ticket']);
        $claims = $this->signer()->verify($grant['ticket'], self::ORIGIN, $now);
        $claims['channels'][] = Channel::user(1, $userId + 1);
        $tampered = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=') . '.' . $signature;
        $hub = new Hub($this->settings);
        foreach ([[$tampered, self::ORIGIN, $now], [$grant['ticket'], 'http://localhost:8080', $now],
            [$grant['ticket'], self::ORIGIN, $grant['expires_at']]] as [$ticket, $origin, $authTime]) {
            self::assertNull($this->signer()->verify($ticket, $origin, $authTime));
            $client = new MobileGrantConnection();
            $hub->open($client, $authTime);
            self::assertTrue($hub->handshake($client, $origin));
            $hub->message($client, json_encode(['type' => 'authenticate', 'ticket' => $ticket], JSON_THROW_ON_ERROR), $authTime);
            self::assertTrue($client->closed);
            self::assertSame([], $client->sent);
        }
    }

    public function testNonpositiveIdentityFailsClosed(): void
    {
        foreach ([[0, 1], [1, 0], [-1, 1]] as [$tenantId, $userId]) {
            try {
                (new UserTicketIssuer($this->settings))->issue($tenantId, $userId, self::ORIGIN);
                self::fail('Nonpositive identity accepted.');
            } catch (RuntimeException $error) {
                self::assertSame(503, $error->getCode());
            }
        }
    }
}

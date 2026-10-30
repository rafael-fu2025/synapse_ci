<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Realtime\Channel;
use App\Services\Realtime\Hub;
use App\Services\Realtime\PublishEndpoint;
use App\Services\Realtime\TicketSigner;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Realtime;

final class RealtimeTestConnection
{
    public array $sent = [];
    public bool $closed = false;
    public function send(string $message): void { $this->sent[] = json_decode($message, true, 32, JSON_THROW_ON_ERROR); }
    public function destroy(): void { $this->closed = true; }
}

final class RealtimeHubTest extends CIUnitTestCase
{
    private Realtime $settings;
    private Hub $hub;
    private const ORIGIN = 'https://portal.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new Realtime();
        $this->settings->enabled = true;
        $this->settings->signingKey = str_repeat('s', 32);
        $this->settings->publishKey = str_repeat('p', 32);
        $this->settings->allowedOrigins = self::ORIGIN;
        $this->settings->publicUrl = 'wss://portal.example.test/realtime';
        $this->hub = new Hub($this->settings);
    }

    private function connect(int $userId = 1, ?int $now = null): RealtimeTestConnection
    {
        $now ??= time();
        $connection = new RealtimeTestConnection();
        $this->hub->open($connection, $now);
        $this->assertTrue($this->hub->handshake($connection, self::ORIGIN));
        $ticket = (new TicketSigner($this->settings->signingKey))->issue([Channel::user(1, $userId)], self::ORIGIN, $now);
        $this->hub->message($connection, json_encode(['type' => 'authenticate', 'ticket' => $ticket]), $now);
        $this->assertSame([['type' => 'ready']], $connection->sent);
        return $connection;
    }

    public function testOnlyExplicitTicketChannelsReceiveEventsAndHeartbeatWorks(): void
    {
        $first = $this->connect(1, 1000);
        $second = $this->connect(2, 1000);
        $this->hub->publish(Channel::user(1, 1), 'notification.created', ['id' => 7], 1001);
        $this->assertSame(['type' => 'event', 'channel' => 'private.tenant.1.user.1', 'event' => 'notification.created', 'data' => ['id' => 7]], $first->sent[1]);
        $this->assertCount(1, $second->sent);
        $this->hub->publish('private.bmg.device.1', 'changed', [], 1001);
        $this->assertCount(2, $first->sent);
        $this->hub->message($first, '{"type":"ping"}', 1002);
        $this->assertSame(['type' => 'pong'], $first->sent[2]);
    }

    public function testBrowserCannotSubscribePublishOrReauthenticate(): void
    {
        foreach (['{"type":"subscribe","channel":"private.user.2"}', '{"type":"publish","channel":"private.user.1"}',
            '{"type":"authenticate","ticket":"anything"}', '{"type":"ping","extra":true}', 'null', 'not-json'] as $payload) {
            $client = $this->connect(1, 1000);
            $this->hub->message($client, $payload, 1001);
            $this->assertTrue($client->closed);
        }
    }

    public function testConnectionCapAndExpiredSlotCleanup(): void
    {
        $this->settings->maxConnections = 1;
        $first = $this->connect(1, 1000);
        $rejected = new RealtimeTestConnection();
        $this->hub->open($rejected, 1000);
        $this->assertTrue($rejected->closed);
        $this->hub->tick(1060);
        $this->assertTrue($first->closed);
        $replacement = $this->connect(1, 1060);
        $this->assertFalse($replacement->closed);
    }

    public function testHandshakeRejectsMissingAndNonExactOrigins(): void
    {
        foreach (['', 'null', 'https://attacker.test', self::ORIGIN . ':443', self::ORIGIN . '.attacker.test'] as $origin) {
            $client = new RealtimeTestConnection();
            $this->hub->open($client, 1000);
            $this->assertFalse($this->hub->handshake($client, $origin));
            $this->hub->message($client, '{"type":"ping"}', 1000);
            $this->assertTrue($client->closed);
        }
    }

    public function testTimeoutAndTicketExpiryRemoveSubscriptions(): void
    {
        $pending = new RealtimeTestConnection();
        $this->hub->open($pending, 1000);
        $this->hub->tick(1005);
        $this->assertTrue($pending->closed);
        $client = $this->connect(1, 1000);
        $this->hub->tick(1059);
        $this->assertFalse($client->closed);
        $this->hub->publish(Channel::user(1, 1), 'changed', [], 1060);
        $this->assertTrue($client->closed);
        $this->assertCount(1, $client->sent);
    }

    public function testUnauthenticatedAndOversizeMessagesClose(): void
    {
        $client = new RealtimeTestConnection();
        $this->hub->open($client, 1000);
        $this->hub->handshake($client, self::ORIGIN);
        $this->hub->message($client, '{"type":"ping"}', 1000);
        $this->assertTrue($client->closed);
        $client = $this->connect(1, 1000);
        $this->hub->message($client, str_repeat('x', $this->settings->maxPayloadBytes + 1), 1001);
        $this->assertTrue($client->closed);
    }

    public function testInvalidOrWrongOriginTicketCannotAuthenticate(): void
    {
        $signer = new TicketSigner($this->settings->signingKey);
        foreach (['tampered', $signer->issue([Channel::personal(1)], 'https://attacker.test', 1000),
            $signer->issue([Channel::personal(1)], self::ORIGIN, 900)] as $ticket) {
            $client = new RealtimeTestConnection();
            $this->hub->open($client, 1000);
            $this->hub->handshake($client, self::ORIGIN);
            $this->hub->message($client, json_encode(['type' => 'authenticate', 'ticket' => $ticket]), 1000);
            $this->assertTrue($client->closed);
            $this->assertSame([], $client->sent);
        }
    }

    public function testLateAuthenticationAndHeartbeatCannotExtendDeadline(): void
    {
        $client = new RealtimeTestConnection();
        $this->hub->open($client, 1000);
        $this->hub->handshake($client, self::ORIGIN);
        $ticket = (new TicketSigner($this->settings->signingKey))->issue([Channel::personal(1)], self::ORIGIN, 1000);
        $this->hub->message($client, json_encode(['type' => 'authenticate', 'ticket' => $ticket]), 1005);
        $this->assertTrue($client->closed);
        $client = $this->connect(1, 1000);
        $this->hub->message($client, '{"type":"ping"}', 1059);
        $this->hub->message($client, '{"type":"ping"}', 1060);
        $this->assertTrue($client->closed);
        $this->assertCount(2, $client->sent);
    }

    public function testPublishEndpointRequiresInternalCredentialAndValidatedBody(): void
    {
        $client = $this->connect();
        $endpoint = new PublishEndpoint($this->settings, $this->hub);
        $authorization = 'Bearer ' . $this->settings->publishKey;
        $body = json_encode(['channel' => Channel::user(1, 1), 'event' => 'changed', 'data' => ['id' => 1]]);
        $this->assertSame(404, $endpoint->handle('GET', '/publish', $authorization, '', $body));
        $this->assertSame(404, $endpoint->handle('POST', '/publish?x=1', $authorization, '', $body));
        $this->assertSame(403, $endpoint->handle('POST', '/publish', 'Bearer ' . $this->settings->signingKey, '', $body));
        $this->assertSame(403, $endpoint->handle('POST', '/publish', '', '', $body));
        $this->assertSame(403, $endpoint->handle('POST', '/publish', $authorization, self::ORIGIN, $body));
        $this->assertSame(202, $endpoint->handle('POST', '/publish', $authorization, '', $body));
        $this->assertCount(2, $client->sent);
        foreach (['{}', 'null', '{"channel":"private.user.*","event":"changed","data":[]}',
            '{"channel":"private.user.1","event":"*","data":[]}', '{"channel":"private.user.1","event":"ok","data":"text"}',
            json_encode(['channel' => 'private.user.1', 'event' => str_repeat('a', 97), 'data' => []])] as $invalid) {
            $this->assertSame(422, $endpoint->handle('POST', '/publish', $authorization, '', $invalid));
        }
        $this->assertSame(413, $endpoint->handle('POST', '/publish', $authorization, '', str_repeat('x', $this->settings->maxPayloadBytes + 1)));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\AddLanPushRegistrationScope;
use App\Services\Push\FcmClient;
use App\Services\Push\PushTokenService;
use App\Services\Push\PushTransport;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Push;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Real central fanout/FCM encoding with a fake HTTPS boundary and injected SQLite :memory: registry. */
final class PushLanTransportTest extends TestCase
{
    private const INSTALLATION = '11111111-1111-4111-8111-111111111111';
    private const REGISTRATION = '22222222-2222-4222-8222-222222222222';
    private const REPLACEMENT = '33333333-3333-4333-8333-333333333333';
    private const FAMILY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private BaseConnection $db;
    private Push $config;
    private PushTokenService $registry;
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'lantransport_',
            'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->db->query('CREATE TABLE lantransport_push_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            token TEXT NOT NULL UNIQUE, platform TEXT NOT NULL, installation_id TEXT NULL, api_family_id TEXT NULL,
            team_workspace_capable INTEGER NOT NULL DEFAULT 0, device_label TEXT NULL, created_ip TEXT NULL,
            last_used_at TEXT NULL, revoked_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NULL
        )');
        require_once APPPATH . 'Database/Migrations/2026-11-09-000001_AddLanPushRegistrationScope.php';
        (new AddLanPushRegistrationScope(Database::forge($this->db)))->up();
        $this->config = new Push();
        $this->config->enabled = true;
        $this->config->projectId = 'synthetic-project';
        $this->config->serviceAccountPath = 'not-read-synthetic-account.json';
        $this->config->deploymentId = 'synapse-lan-university';
        $this->registry = new PushTokenService($this->db, $this->config);
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    private function register(string $token, string $registration = self::REGISTRATION, bool $capable = false): void
    {
        $this->registry->register(['id' => 5, 'tenant_id' => 7], $token, 'android', null, null,
            self::INSTALLATION, self::FAMILY, $capable, 'lan-test', $registration);
    }

    private function transport(?callable $response = null): PushTransport
    {
        $client = new FcmClient($this->config, function (string $url, array $headers, string $body) use ($response): array {
            self::assertSame('https://fcm.googleapis.com/v1/projects/synthetic-project/messages:send', $url);
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $this->calls[] = [$url, $headers, $payload];
            return $response !== null ? $response($payload) : ['status' => 200, 'body' => '{}'];
        }, static fn (): int => 1700000000);
        // A synthetic cached access token avoids even opening a service-account file.
        (new ReflectionProperty(FcmClient::class, 'accessToken'))->setValue($client, 'synthetic-access-token');
        (new ReflectionProperty(FcmClient::class, 'accessTokenExpiresAt'))->setValue($client, 1700003600);
        return new PushTransport($client, $this->registry, $this->config);
    }

    public function testMixedFanoutStampsEachLanActivationAndKeepsLegacyPayloadUnchanged(): void
    {
        $lan = str_repeat('a', 40);
        $legacy = str_repeat('b', 40);
        $this->register($lan);
        $this->registry->register(['id' => 5, 'tenant_id' => 7], $legacy, 'ios', null, null);
        $message = ['title' => 'PRIVATE CLI override', 'body' => 'SECRET clinical body',
            'data' => ['type' => 'appointment_confirmed', 'id' => '42', 'scope_version' => 'spoofed',
                'deployment_id' => 'wrong-deployment', 'registration_id' => 'wrong-activation']];
        self::assertTrue($this->transport()->send(7, 5, $message));
        $deliveries = array_column(array_column($this->calls, 2), 'message');
        $byToken = array_column($deliveries, null, 'token');
        self::assertSame(['title' => 'Synapse LAN Test', 'body' => 'Open the app to check for updates'], $byToken[$lan]['notification']);
        self::assertSame(['type' => 'appointment_confirmed', 'id' => '42', 'scope_version' => '1',
            'deployment_id' => 'synapse-lan-university', 'registration_id' => self::REGISTRATION], $byToken[$lan]['data']);
        self::assertSame(['title' => $message['title'], 'body' => $message['body']], $byToken[$legacy]['notification']);
        self::assertSame($message['data'], $byToken[$legacy]['data']);
        self::assertSame(['priority' => 'high', 'notification' => ['channel_id' => 'synapse-notifications']], $byToken[$lan]['android']);
        self::assertSame(['payload' => ['aps' => ['sound' => 'default']]], $byToken[$lan]['apns']);
        self::assertStringNotContainsString('PRIVATE', json_encode($byToken[$lan]));
        self::assertStringNotContainsString('SECRET', json_encode($byToken[$lan]));
    }

    public function testDifferentLanDevicesReceiveTheirOwnRegistrationNotTheFirstDevicesEnvelope(): void
    {
        $first = str_repeat('a', 40);
        $second = str_repeat('b', 40);
        $this->register($first);
        $this->registry->register(['id' => 5, 'tenant_id' => 7], $second, 'android', null, null,
            '44444444-4444-4444-8444-444444444444', self::FAMILY, false, 'lan-test', self::REPLACEMENT);
        self::assertTrue($this->transport()->send(7, 5, ['title' => 'x', 'body' => 'y']));
        $byToken = array_column(array_column(array_column($this->calls, 2), 'message'), null, 'token');
        self::assertSame(self::REGISTRATION, $byToken[$first]['data']['registration_id']);
        self::assertSame(self::REPLACEMENT, $byToken[$second]['data']['registration_id']);
        self::assertSame('1', $byToken[$first]['data']['scope_version']);
    }

    public function testMissingStaleOrMalformedScopeMetadataFailsClosed(): void
    {
        $token = str_repeat('a', 40);
        foreach ([['audience' => null], ['registration_id' => null], ['registration_id' => 'invalid'],
            ['scope_version' => null], ['scope_version' => 2], ['deployment_id' => null], ['deployment_id' => 'other-deployment'],
            ['installation_id' => null], ['api_family_id' => null], ['active_lan_installation_id' => null],
            ['audience' => 'unsupported']] as $damage) {
            $this->db->table('push_tokens')->where('id >', 0)->delete();
            $this->register($token);
            $this->db->table('push_tokens')->where('token', $token)->update($damage);
            self::assertFalse($this->transport()->send(7, 5, ['title' => 'SECRET', 'body' => 'PRIVATE']));
            self::assertSame([], $this->calls);
        }
        $this->db->table('push_tokens')->where('id >', 0)->delete();
        $this->register($token);
        $this->config->deploymentId = '';
        self::assertFalse($this->transport()->send(7, 5, ['title' => 'x', 'body' => 'y']));
        self::assertSame([], $this->calls);
    }

    public function testMissingDeploymentOnlySuppressesLanNotLegacyFanout(): void
    {
        $lan = str_repeat('a', 40);
        $legacy = str_repeat('b', 40);
        $this->register($lan);
        $this->registry->register(['id' => 5, 'tenant_id' => 7], $legacy, 'ios', null, null);
        $this->config->deploymentId = '';
        self::assertFalse($this->transport()->send(7, 5, ['title' => 'Legacy title', 'body' => 'Legacy body']));
        self::assertCount(1, $this->calls);
        self::assertSame($legacy, $this->calls[0][2]['message']['token']);
        self::assertSame(['title' => 'Legacy title', 'body' => 'Legacy body'], $this->calls[0][2]['message']['notification']);
    }

    public function testTeamFanoutKeepsCapabilityAuthorizationAndUsesTheSameGenericPhoneEnvelope(): void
    {
        $lan = str_repeat('a', 40);
        $this->register($lan, self::REGISTRATION, true);
        $this->registry->register(['id' => 5, 'tenant_id' => 7], str_repeat('b', 40), 'android', null, null);
        $transport = $this->transport();
        self::assertTrue($transport->teamCapable(7, 5));
        self::assertTrue($transport->sendTeam(7, 5, ['title' => 'Private team detail', 'body' => 'Private team message',
            'data' => ['type' => 'team_task', 'id' => '17', 'unit' => 'clinic']]));
        self::assertCount(1, $this->calls);
        $message = $this->calls[0][2]['message'];
        self::assertSame($lan, $message['token']);
        self::assertSame('Synapse LAN Test', $message['notification']['title']);
        self::assertSame('team_task', $message['data']['type']);
        self::assertSame('clinic', $message['data']['unit']);
        self::assertSame(self::REGISTRATION, $message['data']['registration_id']);
    }

    public function testUnregisteredResponseRetiresOnlyTheActivationThatWasSent(): void
    {
        $token = str_repeat('a', 40);
        $this->register($token);
        $transport = $this->transport(function (): array {
            $this->register(str_repeat('a', 40), self::REPLACEMENT);
            return ['status' => 404, 'body' => '{"error":{"details":[{"errorCode":"UNREGISTERED"}]}}'];
        });
        self::assertTrue($transport->send(7, 5, ['title' => 'x', 'body' => 'y']));
        $row = $this->db->table('push_tokens')->get()->getRowArray();
        self::assertSame(self::REPLACEMENT, $row['registration_id']);
        self::assertNull($row['revoked_at']);
        self::assertTrue($this->transport(static fn (): array => ['status' => 404, 'body' => 'UNREGISTERED'])
            ->send(7, 5, ['title' => 'x', 'body' => 'y']));
        self::assertSame([], $this->registry->activeTokens(7, 5));
    }
}

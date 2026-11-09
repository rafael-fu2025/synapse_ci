<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\AddLanPushRegistrationScope;
use App\Services\Push\PushTokenService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Push;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Only explicitly injected SQLite :memory: fixtures; no application DB groups or migration runner. */
final class PushLanRegistrationTest extends TestCase
{
    private const IDENTITY = ['id' => 5, 'tenant_id' => 7];
    private const INSTALLATION = '11111111-1111-4111-8111-111111111111';
    private const REGISTRATION = '22222222-2222-4222-8222-222222222222';
    private const REPLACEMENT = '33333333-3333-4333-8333-333333333333';
    private const OTHER_INSTALLATION = '44444444-4444-4444-8444-444444444444';
    private const FAMILY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private BaseConnection $db;
    private Push $config;
    private PushTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'lanpush_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        $this->db->query('CREATE TABLE lanpush_push_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            token TEXT NOT NULL UNIQUE, platform TEXT NOT NULL, installation_id TEXT NULL,
            api_family_id TEXT NULL, team_workspace_capable INTEGER NOT NULL DEFAULT 0,
            device_label TEXT NULL, created_ip TEXT NULL, last_used_at TEXT NULL, revoked_at TEXT NULL,
            created_at TEXT NOT NULL, updated_at TEXT NULL
        )');
        require_once APPPATH . 'Database/Migrations/2026-11-09-000001_AddLanPushRegistrationScope.php';
        (new AddLanPushRegistrationScope(Database::forge($this->db)))->up();
        $this->config = new Push();
        $this->config->deploymentId = 'synapse-lan-university';
        $this->config->maxActiveTokens = 10;
        $this->service = new PushTokenService($this->db, $this->config);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    private function register(
        string $token,
        string $registration = self::REGISTRATION,
        string $installation = self::INSTALLATION,
        array $identity = self::IDENTITY,
        string $family = self::FAMILY,
        bool $capable = false,
    ): array {
        return $this->service->register($identity, $token, 'android', null, null,
            $installation, $family, $capable, 'lan-test', $registration);
    }

    private function row(string $token): array
    {
        return $this->db->table('push_tokens')->where('token', $token)->get()->getRowArray();
    }

    private function fails(int $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('The operation must fail closed.');
        } catch (InvalidArgumentException | RuntimeException $error) {
            self::assertSame($code, $error->getCode(), $error->getMessage());
        }
    }

    public function testCapabilitiesAndRegistrationReturnOnlyTheExactScopedClientEnvelope(): void
    {
        self::assertSame(['scope_version' => 1, 'deployment_id' => 'synapse-lan-university', 'audiences' => ['lan-test']],
            $this->service->capabilities());
        $result = $this->register(str_repeat('a', 40), strtoupper(self::REGISTRATION));
        self::assertSame(['registered' => true, 'scope_version' => 1, 'deployment_id' => 'synapse-lan-university',
            'registration_id' => self::REGISTRATION], $result);
        $row = $this->row(str_repeat('a', 40));
        self::assertSame([7, 5, self::FAMILY, self::INSTALLATION, 'lan-test', self::REGISTRATION, 1, 'synapse-lan-university', self::INSTALLATION],
            [$row['tenant_id'], $row['user_id'], $row['api_family_id'], $row['installation_id'], $row['audience'],
                $row['registration_id'], $row['scope_version'], $row['deployment_id'], $row['active_lan_installation_id']]);
        self::assertStringNotContainsString(str_repeat('a', 40), json_encode($result));
    }

    public function testColdRestartIsIdempotentAndTokenRotationRetiresOnlyTheSameLanInstallation(): void
    {
        $first = str_repeat('a', 40);
        $replacement = str_repeat('b', 40);
        $other = str_repeat('c', 40);
        $legacy = str_repeat('d', 40);
        $this->register($first);
        $created = $this->row($first)['created_at'];
        $this->register($first);
        self::assertSame(1, $this->db->table('push_tokens')->countAllResults());
        self::assertSame($created, $this->row($first)['created_at']);
        $this->register($other, self::REPLACEMENT, self::OTHER_INSTALLATION);
        $this->service->register(self::IDENTITY, $legacy, 'ios', null, null);
        $this->register($replacement);
        self::assertNotNull($this->row($first)['revoked_at']);
        self::assertNull($this->row($first)['active_lan_installation_id']);
        self::assertNull($this->row($replacement)['revoked_at']);
        self::assertNull($this->row($other)['revoked_at']);
        self::assertNull($this->row($legacy)['revoked_at']);
        self::assertCount(3, $this->service->activeTokens(7, 5));
    }

    public function testFreshActivationMovesTheSameTokenAndObsoleteScopedDeleteCannotRevokeIt(): void
    {
        $token = str_repeat('a', 40);
        $this->register($token);
        $this->register($token, self::REPLACEMENT, self::INSTALLATION, self::IDENTITY, str_repeat('b', 32));
        $this->service->revoke(self::IDENTITY, $token, 'lan-test', self::REGISTRATION, self::FAMILY);
        self::assertNull($this->row($token)['revoked_at']);
        $this->service->revoke(self::IDENTITY, $token, 'lan-test', self::REPLACEMENT, self::FAMILY);
        self::assertNull($this->row($token)['revoked_at'], 'The old API family must not revoke a new family binding.');
        $this->service->revoke(self::IDENTITY, $token, 'lan-test', self::REPLACEMENT, str_repeat('b', 32));
        self::assertNotNull($this->row($token)['revoked_at']);
        self::assertNull($this->row($token)['active_lan_installation_id']);
    }

    public function testAccountAndTenantSwitchAtomicallyRetirePreviousInstallContexts(): void
    {
        $old = str_repeat('a', 40);
        $new = str_repeat('b', 40);
        $this->register($old);
        $identity = ['id' => 6, 'tenant_id' => 8];
        $this->register($new, self::REPLACEMENT, self::INSTALLATION, $identity, str_repeat('b', 32));
        self::assertSame([], $this->service->activeTokens(7, 5));
        self::assertSame([$new], array_column($this->service->activeTokens(8, 6), 'token'));
        self::assertNotNull($this->row($old)['revoked_at']);
        $this->service->revoke(self::IDENTITY, $new, 'lan-test', self::REPLACEMENT, str_repeat('b', 32));
        $this->service->revoke(['id' => 6, 'tenant_id' => 7], $new, 'lan-test', self::REPLACEMENT, str_repeat('b', 32));
        self::assertNull($this->row($new)['revoked_at']);
    }

    public function testARegistrationCannotBeReusedAcrossInstallationsOrAuthContexts(): void
    {
        $this->register(str_repeat('a', 40));
        foreach ([
            [self::OTHER_INSTALLATION, self::IDENTITY, self::FAMILY],
            [self::INSTALLATION, ['id' => 6, 'tenant_id' => 7], self::FAMILY],
            [self::INSTALLATION, self::IDENTITY, str_repeat('b', 32)],
        ] as [$installation, $identity, $family]) {
            $this->fails(409, fn () => $this->register(str_repeat('b', 40), self::REGISTRATION, $installation, $identity, $family));
        }
        self::assertCount(1, $this->service->activeTokens(7, 5));
        self::assertSame(0, $this->db->transDepth);
    }

    public function testScopedTokensRejectBareDeleteAndCannotBeDowngradedByAnOldClient(): void
    {
        $token = str_repeat('a', 40);
        $this->register($token);
        $this->fails(422, fn () => $this->service->revoke(self::IDENTITY, $token));
        $this->fails(422, fn () => $this->service->register(self::IDENTITY, $token, 'android', null, null));
        self::assertSame(self::REGISTRATION, $this->row($token)['registration_id']);
        self::assertNull($this->row($token)['revoked_at']);
    }

    public function testMalformedOrPartialScopesAreRejectedBeforeStorage(): void
    {
        foreach ([[null, self::REGISTRATION], ['lan-test', null], ['normal', self::REGISTRATION], ['lan-test', 'not-a-uuid'],
            ['lan-test', '22222222-2222-1222-8222-222222222222'], ['', self::REGISTRATION]] as [$audience, $registration]) {
            $this->fails(422, fn () => $this->service->register(self::IDENTITY, str_repeat('a', 40), 'android', null, null,
                self::INSTALLATION, self::FAMILY, false, $audience, $registration));
            $this->fails(422, fn () => $this->service->revoke(self::IDENTITY, str_repeat('a', 40), $audience, $registration, self::FAMILY));
        }
        $this->fails(422, fn () => $this->service->register(self::IDENTITY, str_repeat('a', 40), 'android', null, null,
            null, self::FAMILY, false, 'lan-test', self::REGISTRATION));
        $this->fails(422, fn () => $this->service->register(self::IDENTITY, str_repeat('a', 40), 'android', null, null,
            self::INSTALLATION, null, false, 'lan-test', self::REGISTRATION));
        self::assertSame(0, $this->db->table('push_tokens')->countAllResults());
    }

    public function testMissingOrInvalidConfigFailsClosedOnlyForLanAndScopedRevocationStillWorks(): void
    {
        $token = str_repeat('a', 40);
        $this->register($token);
        foreach (['', 'https://host.test', 'short'] as $deployment) {
            $this->config->deploymentId = $deployment;
            $this->fails(503, fn () => $this->service->capabilities());
            $this->fails(503, fn () => $this->register(str_repeat('b', 40), self::REPLACEMENT));
        }
        self::assertSame(['registered' => true], $this->service->register(self::IDENTITY, str_repeat('c', 40), 'ios', null, null));
        $this->service->revoke(self::IDENTITY, $token, 'lan-test', self::REGISTRATION, self::FAMILY);
        self::assertNotNull($this->row($token)['revoked_at']);
    }

    public function testOptionalExpectedDeploymentMustMatchTheServerScope(): void
    {
        $token = str_repeat('a', 40);
        $this->fails(422, fn () => $this->service->register(self::IDENTITY, $token, 'android', null, null,
            self::INSTALLATION, self::FAMILY, false, 'lan-test', self::REGISTRATION, 'synapse-lan-home'));
        self::assertSame(0, $this->db->table('push_tokens')->countAllResults());
        self::assertTrue($this->service->register(self::IDENTITY, $token, 'android', null, null,
            self::INSTALLATION, self::FAMILY, false, 'lan-test', self::REGISTRATION, $this->config->deploymentId)['registered']);
    }

    public function testUniqueActiveInstallationKeyStopsConcurrentFirstRegistrationDuplicates(): void
    {
        $token = str_repeat('a', 40);
        $this->register($token);
        $copy = $this->row($token);
        unset($copy['id']);
        $copy['token'] = str_repeat('b', 40);
        $copy['registration_id'] = self::REPLACEMENT;
        self::assertTrue($this->db->transBegin());
        self::assertFalse($this->db->table('push_tokens')->insert($copy));
        self::assertTrue($this->db->transRollback());
        $this->db->resetTransStatus();
        self::assertSame(1, $this->db->table('push_tokens')->where('revoked_at', null)->countAllResults());
    }

    public function testFailedReplacementRollsBackRetirementAndPreservesPreviousActiveToken(): void
    {
        $token = str_repeat('a', 40);
        $this->register($token);
        $this->db->query("CREATE TRIGGER lanpush_reject_insert BEFORE INSERT ON lanpush_push_tokens
            BEGIN SELECT RAISE(ABORT, 'synthetic write failure'); END");
        $this->fails(503, fn () => $this->register(str_repeat('b', 40), self::REPLACEMENT));
        self::assertNull($this->row($token)['revoked_at']);
        self::assertSame(self::INSTALLATION, $this->row($token)['active_lan_installation_id']);
        self::assertSame(0, $this->db->transDepth);
        self::assertTrue($this->db->transStatus());
    }

    public function testFailedSameTokenReplacementAlsoRollsBackSiblingRetirement(): void
    {
        $current = str_repeat('a', 40);
        $sibling = str_repeat('b', 40);
        $thirdRegistration = '55555555-5555-4555-8555-555555555555';
        $this->register($current);
        $this->register($sibling, self::REPLACEMENT, self::OTHER_INSTALLATION);
        $this->db->query("CREATE TRIGGER lanpush_reject_update BEFORE UPDATE ON lanpush_push_tokens
            WHEN NEW.token = '" . $current . "' AND NEW.registration_id = '" . $thirdRegistration . "'
            BEGIN SELECT RAISE(ABORT, 'synthetic update failure'); END");
        $this->fails(503, fn () => $this->register($current, $thirdRegistration, self::OTHER_INSTALLATION));
        self::assertNull($this->row($current)['revoked_at']);
        self::assertSame(self::REGISTRATION, $this->row($current)['registration_id']);
        self::assertNull($this->row($sibling)['revoked_at']);
        self::assertSame(self::OTHER_INSTALLATION, $this->row($sibling)['active_lan_installation_id']);
        self::assertCount(2, $this->service->activeTokens(7, 5));
    }

    public function testFamilyLogoutAndUnregisteredFeedbackCannotRetireANewActivation(): void
    {
        $token = str_repeat('a', 40);
        $this->register($token, self::REGISTRATION, self::INSTALLATION, self::IDENTITY, self::FAMILY, true);
        $this->register($token, self::REPLACEMENT, self::INSTALLATION, self::IDENTITY, str_repeat('b', 32), true);
        $this->service->forget(7, $token, 'lan-test', self::REGISTRATION);
        $this->service->forget(7, $token);
        $this->service->revokeFamily(7, self::FAMILY);
        self::assertNull($this->row($token)['revoked_at']);
        self::assertTrue($this->service->hasTeamCapableToken(7, 5));
        $this->service->revokeFamily(7, str_repeat('b', 32));
        self::assertSame([], $this->service->activeTokens(7, 5));
        self::assertNull($this->row($token)['active_lan_installation_id']);
    }

    public function testCapIsEnforcedOnRefreshAndNewRegistrationWithoutEvictingTheSavedActivation(): void
    {
        $legacy = str_repeat('a', 40);
        $lan = str_repeat('b', 40);
        $this->service->register(self::IDENTITY, $legacy, 'android', null, null);
        $this->register($lan);
        $this->config->maxActiveTokens = 1;
        $this->register($lan);
        self::assertNotNull($this->row($legacy)['revoked_at']);
        self::assertSame([$lan], array_column($this->service->activeTokens(7, 5), 'token'));
        $this->service->register(self::IDENTITY, str_repeat('c', 40), 'android', null, null);
        self::assertNotNull($this->row($lan)['revoked_at']);
        self::assertNull($this->row($lan)['active_lan_installation_id']);
    }

    public function testMissingUniqueActiveIndexNeverAdvertisesOrAcceptsScopedSupport(): void
    {
        $token = str_repeat('a', 40);
        $this->register($token);
        $this->db->query('DROP INDEX push_tokens_lan_active_installation');
        $this->fails(503, fn () => $this->service->capabilities());
        $this->fails(503, fn () => $this->register(str_repeat('b', 40), self::REPLACEMENT));
        self::assertSame([], $this->service->activeTokens(7, 5));
        self::assertSame(['registered' => true], $this->service->register(self::IDENTITY, str_repeat('c', 40), 'android', null, null));
        self::assertSame([str_repeat('c', 40)], array_column($this->service->activeTokens(7, 5), 'token'));
    }

    public function testLegacySchemaWorksButScopedAndPartialSchemaNeverAdvertiseLanSupport(): void
    {
        $this->db->query('DROP TABLE lanpush_push_tokens');
        $this->db->query('CREATE TABLE lanpush_push_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            token TEXT NOT NULL UNIQUE, platform TEXT NOT NULL, device_label TEXT NULL, created_ip TEXT NULL,
            last_used_at TEXT NULL, revoked_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NULL
        )');
        $this->fails(503, fn () => $this->service->capabilities());
        $this->fails(503, fn () => $this->register(str_repeat('a', 40)));
        self::assertSame(['registered' => true], $this->service->register(self::IDENTITY, str_repeat('b', 40), 'ios', null, null));
        self::assertSame([['token' => str_repeat('b', 40), 'platform' => 'ios']], $this->service->activeTokens(7, 5));
        $this->db->query('ALTER TABLE lanpush_push_tokens ADD COLUMN audience VARCHAR(16) NULL');
        $this->db->table('push_tokens')->where('token', str_repeat('b', 40))->update(['audience' => 'lan-test']);
        self::assertSame([], $this->service->activeTokens(7, 5), 'Partial scope metadata must never become legacy fanout.');
        $this->fails(503, fn () => $this->service->capabilities());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Push\PushTokenService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class PushInstallationCapabilityTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->tests = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'pushcap_',
            'DBDebug' => true, 'foreignKeys' => true,
        ];
        $this->db = Database::connect(config('Database')->tests, false);
        $this->db->query('CREATE TABLE pushcap_push_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            token TEXT NOT NULL UNIQUE, platform TEXT NOT NULL, installation_id TEXT NULL,
            api_family_id TEXT NULL, team_workspace_capable INTEGER NOT NULL DEFAULT 0,
            device_label TEXT NULL, created_ip TEXT NULL, last_used_at TEXT NULL, revoked_at TEXT NULL,
            created_at TEXT NOT NULL, updated_at TEXT NULL
        )');
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testCapabilityAndFamilyAreStoredAndFamilyLogoutRevokesOnlyThatInstallation(): void
    {
        $service = new PushTokenService($this->db);
        $identity = ['id' => 5, 'tenant_id' => 7];
        $service->register($identity, str_repeat('a', 40), 'android', null, null,
            '11111111-1111-4111-8111-111111111111', str_repeat('b', 32), true);
        $service->register($identity, str_repeat('c', 40), 'ios', null, null,
            '22222222-2222-4222-8222-222222222222', str_repeat('d', 32), false);

        self::assertTrue($service->hasTeamCapableToken(7, 5));
        self::assertSame([str_repeat('a', 40)], array_column($service->activeTokens(7, 5, true), 'token'));
        self::assertCount(2, $service->activeTokens(7, 5));
        $service->revokeFamily(7, str_repeat('b', 32));
        self::assertFalse($service->hasTeamCapableToken(7, 5));
        self::assertCount(1, $service->activeTokens(7, 5));
        self::assertSame(str_repeat('c', 40), $service->activeTokens(7, 5)[0]['token']);
    }

    public function testOlderSchemaFailsClosedForTeamCapabilityWithoutBreakingRegistration(): void
    {
        $this->db->query('DROP TABLE pushcap_push_tokens');
        $this->db->query('CREATE TABLE pushcap_push_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            token TEXT NOT NULL UNIQUE, platform TEXT NOT NULL, device_label TEXT NULL, created_ip TEXT NULL,
            last_used_at TEXT NULL, revoked_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NULL
        )');
        $service = new PushTokenService($this->db);
        $service->register(['id' => 5, 'tenant_id' => 7], str_repeat('e', 40), 'android', null, null,
            '33333333-3333-4333-8333-333333333333', str_repeat('f', 32), true);
        self::assertFalse($service->hasTeamCapableToken(7, 5));
        self::assertCount(1, $service->activeTokens(7, 5));
    }
}

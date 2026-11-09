<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\AddLanPushRegistrationScope;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Directly applies only this additive migration to explicit in-memory test tables. */
final class PushLanScopeMigrationTest extends TestCase
{
    private BaseConnection $db;
    private AddLanPushRegistrationScope $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'lanmigration_',
            'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->db->query('CREATE TABLE lanmigration_users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL,
            UNIQUE (tenant_id,id))');
        $this->db->table('users')->insert(['id' => 5, 'tenant_id' => 7]);
        $this->db->query('CREATE TABLE lanmigration_push_tokens (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            token VARCHAR(512) NOT NULL UNIQUE, platform VARCHAR(16) NOT NULL,
            installation_id CHAR(36) NULL, api_family_id CHAR(32) NULL, team_workspace_capable INTEGER NOT NULL DEFAULT 0,
            device_label TEXT NULL, created_ip TEXT NULL, last_used_at TEXT NULL, revoked_at TEXT NULL,
            created_at TEXT NOT NULL, updated_at TEXT NULL,
            FOREIGN KEY (tenant_id,user_id) REFERENCES lanmigration_users(tenant_id,id)
        )');
        $this->db->table('push_tokens')->insert(['id' => 1, 'tenant_id' => 7, 'user_id' => 5,
            'token' => str_repeat('a', 40), 'platform' => 'android', 'created_at' => '2026-11-09 12:00:00']);
        $this->db->query('CREATE TABLE lanmigration_history (id INTEGER PRIMARY KEY, class TEXT)');
        $this->db->table('history')->insert(['id' => 1, 'class' => 'existing migration']);
        require_once APPPATH . 'Database/Migrations/2026-11-09-000001_AddLanPushRegistrationScope.php';
        $this->migration = new AddLanPushRegistrationScope(Database::forge($this->db));
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    private function baselineRows(): array
    {
        $rows = $this->db->table('push_tokens')->orderBy('id')->get()->getResultArray();
        foreach ($rows as &$row) {
            foreach (['audience', 'registration_id', 'scope_version', 'deployment_id', 'active_lan_installation_id'] as $column) {
                if (array_key_exists($column, $row)) {
                    self::assertNull($row[$column], 'Existing tokens must remain unscoped.');
                    unset($row[$column]);
                }
            }
        }
        unset($row);
        return $rows;
    }

    public function testUpgradeAndRerunPreserveRowsForeignKeysAndMigrationHistory(): void
    {
        $rows = $this->baselineRows();
        $history = $this->db->table('history')->get()->getResultArray();
        $foreignKeys = $this->db->getForeignKeyData('push_tokens');
        $this->migration->up();
        $this->migration->up();
        self::assertSame($rows, $this->baselineRows());
        self::assertSame($history, $this->db->table('history')->get()->getResultArray());
        self::assertEquals($foreignKeys, $this->db->getForeignKeyData('push_tokens'));
        self::assertSame([], $this->db->query('PRAGMA foreign_key_check')->getResultArray());
        $indexes = $this->db->getIndexData('push_tokens');
        self::assertSame('UNIQUE', $indexes['push_tokens_lan_active_installation']->type);
        self::assertSame(['active_lan_installation_id'], $indexes['push_tokens_lan_active_installation']->fields);
        self::assertSame(['audience', 'installation_id', 'revoked_at'], $indexes['push_tokens_lan_installation']->fields);
        foreach (['audience', 'registration_id', 'scope_version', 'deployment_id', 'active_lan_installation_id'] as $field) {
            self::assertTrue($this->db->fieldExists($field, 'push_tokens'));
        }
    }

    public function testAnInterruptedAddColumnResumesWithoutChangingLegacyBytes(): void
    {
        $rows = $this->baselineRows();
        $this->db->query('ALTER TABLE lanmigration_push_tokens ADD COLUMN audience VARCHAR(16) NULL');
        $this->db->query('ALTER TABLE lanmigration_push_tokens ADD COLUMN registration_id CHAR(36) NULL');
        $this->migration->up();
        self::assertSame($rows, $this->baselineRows());
        self::assertCount(1, array_filter($this->db->getIndexData('push_tokens'),
            static fn ($index): bool => $index->fields === ['active_lan_installation_id'] && $index->type === 'UNIQUE'));
    }

    public function testNullableUniqueKeyDoesNotCollapseOrRequireScopeForLegacyDevices(): void
    {
        $this->migration->up();
        self::assertTrue($this->db->table('push_tokens')->insert(['id' => 2, 'tenant_id' => 7, 'user_id' => 5,
            'token' => str_repeat('b', 40), 'platform' => 'ios', 'created_at' => '2026-11-09 12:00:00']));
        self::assertCount(2, $this->baselineRows());
    }

    public function testMissingBindingsAndIncompatibleColumnsStopBeforeAnyFurtherDdl(): void
    {
        $this->db->query('ALTER TABLE lanmigration_push_tokens ADD COLUMN audience INTEGER NOT NULL DEFAULT 1');
        try {
            $this->migration->up();
            self::fail('Incompatible scope columns must stop the migration.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Incompatible LAN push scope column', $error->getMessage());
        }
        self::assertFalse($this->db->fieldExists('registration_id', 'push_tokens'));
        $this->db->query('DROP TABLE lanmigration_push_tokens');
        $this->db->query('CREATE TABLE lanmigration_push_tokens (id INTEGER PRIMARY KEY,tenant_id INTEGER,user_id INTEGER,revoked_at TEXT)');
        try {
            $this->migration->up();
            self::fail('Missing installation/family bindings must stop the migration.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('required binding column is missing', $error->getMessage());
        }
        self::assertFalse($this->db->fieldExists('audience', 'push_tokens'));
    }

    public function testIncompatiblePreexistingIndexIsNotMistakenForACompletedMigration(): void
    {
        $this->db->query('CREATE INDEX push_tokens_lan_active_installation ON lanmigration_push_tokens (user_id)');
        try {
            $this->migration->up();
            self::fail('An incompatible index must stop before adding scope columns.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Incompatible LAN push scope index', $error->getMessage());
        }
        self::assertFalse($this->db->fieldExists('audience', 'push_tokens'));
    }

    public function testDestructiveRollbackIsRejected(): void
    {
        $this->migration->up();
        $before = $this->db->table('push_tokens')->get()->getResultArray();
        try {
            $this->migration->down();
            self::fail('Scope binding rollback must be explicitly reviewed.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('forward-only', $error->getMessage());
        }
        self::assertSame($before, $this->db->table('push_tokens')->get()->getResultArray());
        self::assertTrue($this->db->fieldExists('audience', 'push_tokens'));
    }
}

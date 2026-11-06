<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\AlignMobileTokenUserReferences;
use Config\Database;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

require_once APPPATH . 'Database/Migrations/2026-11-06-000901_AlignMobileTokenUserReferences.php';

/** Metadata and DDL coverage runs only in the verified disposable MySQL harness. */
final class MobileTokenUserReferenceMigrationTest extends FreshDatabaseTestCase
{
    private string $prefix;
    private int $userId;
    private AlignMobileTokenUserReferences $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('Database')->tests;
        $this->prefix = 'mobile_owner_' . bin2hex(random_bytes(5)) . '_';
        $config['DBPrefix'] = $this->prefix;
        $this->db = Database::connect($config, false);
        $this->migration = new AlignMobileTokenUserReferences(Database::forge($this->db));
        $this->db->query('CREATE TABLE ' . $this->table('users') . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            UNIQUE KEY existing_user_scope (tenant_id,id)
        ) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE ' . $this->table('api_tokens') . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            KEY api_tokens_user (tenant_id,user_id)
        ) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE ' . $this->table('push_tokens') . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            KEY push_tokens_user (tenant_id,user_id)
        ) ENGINE=InnoDB');
        $this->db->table('users')->insert(['tenant_id' => 7]);
        $this->userId = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        if (isset($this->prefix)) {
            foreach (['api_tokens', 'push_tokens', 'users'] as $table) {
                $this->db->query('DROP TABLE IF EXISTS ' . $this->table($table));
            }
            $this->db->close();
        }
        parent::tearDown();
    }

    private function table(string $table): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable($table));
    }

    public function testWidensOwnersAddsScopedForeignKeysPreservesRowsAndReruns(): void
    {
        $user = $this->userId;
        foreach (['api_tokens', 'push_tokens'] as $table) {
            self::assertTrue($this->db->table($table)->insert(['tenant_id' => 7, 'user_id' => $user]));
        }
        $before = [
            $this->db->table('api_tokens')->get()->getResultArray(),
            $this->db->table('push_tokens')->get()->getResultArray(),
        ];

        $this->migration->up();
        $this->migration->up();

        self::assertSame($before, [
            $this->db->table('api_tokens')->get()->getResultArray(),
            $this->db->table('push_tokens')->get()->getResultArray(),
        ]);
        foreach (['api_tokens' => 'api_tokens_user_owner_fk', 'push_tokens' => 'push_tokens_user_owner_fk'] as $table => $constraint) {
            $constraint = $this->prefix . $constraint;
            $column = $this->db->query('SHOW COLUMNS FROM ' . $this->table($table) . " LIKE 'user_id'")->getRowArray();
            self::assertSame('bigint unsigned', strtolower(preg_replace('/\(\d+\)/', '', $column['Type'])));
            $fk = $this->db->query('SELECT COLUMN_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? ORDER BY ORDINAL_POSITION',
                [$this->db->prefixTable($table), $constraint])->getResultArray();
            self::assertSame(['tenant_id', 'user_id'], array_column($fk, 'COLUMN_NAME'));
            self::assertSame(['tenant_id', 'id'], array_column($fk, 'REFERENCED_COLUMN_NAME'));
        }

        try {
            $this->migration->down();
            self::fail('The migration must be forward-only.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('forward-only', $error->getMessage());
        }
    }

    public function testResumesFromPartialAutocommittedDdl(): void
    {
        foreach (['api_tokens', 'push_tokens'] as $table) {
            self::assertTrue($this->db->table($table)->insert(['tenant_id' => 7, 'user_id' => $this->userId]));
        }
        // MySQL DDL auto-commits. Simulate a stopped deployment after the first table widened.
        $this->db->query('ALTER TABLE ' . $this->table('api_tokens') . ' MODIFY user_id BIGINT UNSIGNED NOT NULL');

        $this->migration->up();

        foreach (['api_tokens', 'push_tokens'] as $table) {
            self::assertSame('bigint unsigned', strtolower(preg_replace('/\(\d+\)/', '',
                $this->db->query('SHOW COLUMNS FROM ' . $this->table($table) . " LIKE 'user_id'")->getRowArray()['Type'])));
            self::assertCount(1, $this->db->table($table)->get()->getResultArray());
        }
    }

    public function testOrphanPreflightStopsBeforeAnyColumnChange(): void
    {
        self::assertTrue($this->db->table('api_tokens')->insert(['tenant_id' => 7, 'user_id' => 999]));
        try {
            $this->migration->up();
            self::fail('An orphan owner must stop before DDL.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('invalid tenant/user owner', $error->getMessage());
        }
        self::assertSame('int unsigned', strtolower(preg_replace('/\(\d+\)/', '',
            $this->db->query('SHOW COLUMNS FROM ' . $this->table('api_tokens') . " LIKE 'user_id'")->getRowArray()['Type'])));
        self::assertSame('int unsigned', strtolower(preg_replace('/\(\d+\)/', '',
            $this->db->query('SHOW COLUMNS FROM ' . $this->table('push_tokens') . " LIKE 'user_id'")->getRowArray()['Type'])));
    }
}

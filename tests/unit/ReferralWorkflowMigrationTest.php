<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\CreateNotifications;
use App\Database\Migrations\RebuildReferralWorkflow;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use RuntimeException;
use Tests\Support\InventoryTestDatabase;

require_once APPPATH . 'Database/Migrations/2026-10-16-000002_CreateNotifications.php';
require_once APPPATH . 'Database/Migrations/2026-10-16-000003_RebuildReferralWorkflow.php';

/** Real DDL, but only random scratch tables in an explicitly verified test database. */
final class ReferralWorkflowMigrationTest extends CIUnitTestCase
{
    private ?BaseConnection $scratch = null;
    private RebuildReferralWorkflow $migration;

    protected function setUp(): void
    {
        InventoryTestDatabase::verify();
        parent::setUp();
        $config = config('Database')->tests;
        $config['DBPrefix'] = 'rwm_' . bin2hex(random_bytes(5)) . '_';
        $this->scratch = Database::connect($config, false);
        $this->assertSame($config['database'], $this->scratch->query('SELECT DATABASE() AS name')->getRowArray()['name']);
        // Minimal legacy contracts: clinical tables intentionally do not exist.
        $this->sql('CREATE TABLE {tenants} (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        $this->sql('CREATE TABLE {people} (id BIGINT UNSIGNED PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL,
            UNIQUE KEY person_tenant (tenant_id,id)) ENGINE=InnoDB');
        $this->sql('CREATE TABLE {users} (id BIGINT UNSIGNED PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $this->sql('CREATE TABLE {appointments} (id BIGINT UNSIGNED PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $this->sql("CREATE TABLE {referrals} (id BIGINT UNSIGNED PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL,
            issuer_user_id BIGINT UNSIGNED NOT NULL, source_module ENUM('clinic','guidance') NOT NULL,
            target_module ENUM('clinic','guidance') NOT NULL, status VARCHAR(32) NOT NULL,
            KEY legacy_referral_inbox (tenant_id,target_module,status)) ENGINE=InnoDB");
        $forge = Database::forge($this->scratch);
        (new CreateNotifications($forge))->up();
        $this->migration = new RebuildReferralWorkflow($forge);
        foreach ([1, 2] as $id) {
            $this->scratch->table('tenants')->insert(['id' => $id]);
            foreach (['users', 'people', 'appointments'] as $table) {
                $this->scratch->table($table)->insert(['id' => $id, 'tenant_id' => $id]);
            }
            $this->scratch->table('referrals')->insert(['id' => $id, 'tenant_id' => $id, 'issuer_user_id' => $id,
                'source_module' => 'clinic', 'target_module' => 'guidance', 'status' => 'submitted']);
        }
        $this->scratch->table('notifications')->insert(['tenant_id' => 1, 'recipient_person_id' => 1,
            'appointment_id' => 1, 'appointment_version' => 7, 'type' => 'appointment_confirmed',
            'created_at' => '2026-10-15 09:00:00', 'read_at' => '2026-10-15 10:00:00']);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->scratch !== null) {
                $forge = Database::forge($this->scratch);
                foreach (['notifications', 'referrals', 'appointments', 'users', 'people', 'tenants'] as $table) {
                    $forge->dropTable($table, true);
                }
                $this->scratch->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function sql(string $sql): void
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($m) => $this->scratch->protectIdentifiers($this->scratch->prefixTable($m[1])), $sql);
        $this->assertNotFalse($this->scratch->query($sql));
    }

    private function row(): array
    {
        return $this->scratch->table('notifications')->where('id', 1)->get()->getRowArray();
    }

    public function testPreservesHistoryOriginalConstraintsAndReruns(): void
    {
        $before = $this->row();
        $this->migration->up();
        $this->migration->up();
        $after = $this->row();
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertNull($after['recipient_user_id']);
        $this->assertNull($after['referral_id']);
        foreach (['recipient_person_id', 'appointment_id', 'appointment_version', 'recipient_user_id', 'referral_id'] as $column) {
            $meta = $this->scratch->query('SELECT IS_NULLABLE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
                [$this->scratch->prefixTable('notifications'), $column])->getRowArray();
            $this->assertSame('YES', $meta['IS_NULLABLE']);
        }
        foreach (['notification_confirmation', 'notification_inbox'] as $index) {
            $this->assertNotNull($this->scratch->query('SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',
                [$this->scratch->prefixTable('notifications'), $index])->getRowArray());
        }
        $foreignKeys = $this->scratch->query('SELECT COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->scratch->prefixTable('notifications')])->getResultArray();
        foreach (['recipient_person_id' => 'people', 'appointment_id' => 'appointments', 'recipient_user_id' => 'users', 'referral_id' => 'referrals'] as $column => $parent) {
            $this->assertContains(['COLUMN_NAME' => $column, 'REFERENCED_TABLE_NAME' => $this->scratch->prefixTable($parent)], $foreignKeys);
        }
        $clinical = $this->scratch->query('SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
            WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN (?,?) AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->scratch->prefixTable('referrals'), 'target_case_id', 'target_encounter_id'])->getResultArray();
        $this->assertSame([], $clinical);
        try {
            $this->migration->down();
            $this->fail('History cannot be rolled back destructively.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('forward-only', $e->getMessage());
        }
        $this->assertSame($after, $this->row());
    }

    private function invalidInsert(string $table, array $data): void
    {
        $failed = false;
        try {
            $failed = $this->scratch->table($table)->insert($data) === false;
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            $failed = true;
        }
        $this->assertTrue($failed, 'Database constraint must reject this row.');
    }

    public function testSameTenantEventUniquenessAndNullableRequestKeys(): void
    {
        $this->migration->up();
        $notice = ['tenant_id' => 1, 'recipient_user_id' => 1, 'referral_id' => 1,
            'type' => 'referral_received', 'created_at' => '2026-10-16 09:00:00'];
        $this->assertTrue($this->scratch->table('notifications')->insert($notice));
        $this->invalidInsert('notifications', $notice);
        $this->invalidInsert('notifications', array_replace($notice, ['recipient_user_id' => 2]));
        $this->invalidInsert('notifications', array_replace($notice, ['referral_id' => 2]));
        $key = str_repeat('a', 64);
        $this->scratch->table('referrals')->where('id', 1)->update(['request_key' => $key]);
        $referral = ['tenant_id' => 1, 'issuer_user_id' => 1, 'source_module' => 'clinic',
            'target_module' => 'guidance', 'status' => 'submitted'];
        $this->invalidInsert('referrals', $referral + ['id' => 3, 'request_key' => $key]);
        foreach ([3, 4] as $id) {
            $this->assertTrue($this->scratch->table('referrals')->insert($referral + ['id' => $id, 'request_key' => null]));
        }
        $this->assertTrue($this->scratch->table('referrals')->insert($referral + ['id' => 5, 'request_key' => str_repeat('b', 64)]));
    }

    public function testDuplicatePartialRequestKeysFailBeforeAnyFurtherDdl(): void
    {
        $this->sql('ALTER TABLE {referrals} ADD request_key CHAR(64) NULL');
        $this->scratch->table('referrals')->where('id', 1)->update(['request_key' => str_repeat('a', 64)]);
        $this->scratch->table('referrals')->insert(['id' => 3, 'tenant_id' => 1, 'issuer_user_id' => 1,
            'source_module' => 'clinic', 'target_module' => 'guidance', 'status' => 'submitted', 'request_key' => str_repeat('a', 64)]);
        $before = $this->row();
        try {
            $this->migration->up();
            $this->fail('Conflicts must not be silently destroyed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('preflight', $e->getMessage());
        }
        $this->assertSame($before, $this->row());
        $this->assertFalse($this->scratch->fieldExists('recipient_user_id', 'notifications'));
        $this->scratch->table('referrals')->where('id', 3)->update(['request_key' => null]);
        $this->migration->up();
        $this->migration->up();
        $this->assertSame(3, $this->scratch->table('referrals')->countAllResults());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateWorkflowAlertStates;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

require_once APPPATH . 'Database/Migrations/2026-10-23-000004_CreateWorkflowAlertStates.php';

/** Real metadata/DDL only inside a fresh-runner-verified schema, with private prefixed fixtures. */
final class WorkflowAlertStatesMigrationTest extends FreshDatabaseTestCase
{
    private CreateWorkflowAlertStates $migration;
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp(); // Must guard before any connection or DDL.
        $config = config('Database')->tests;
        $this->prefix = 'alert_' . bin2hex(random_bytes(6)) . '_';
        $config['DBPrefix'] = $this->prefix;
        $this->db = Database::connect($config, false);
        $this->migration = new CreateWorkflowAlertStates(Database::forge($this->db));
        $this->db->query('CREATE TABLE ' . $this->table('tenants') . ' (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE ' . $this->table('workflow_events') . ' (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        $this->db->table('tenants')->insert(['id' => 1]);
    }

    protected function tearDown(): void
    {
        if (isset($this->prefix)) {
            foreach (['workflow_alert_states', 'workflow_events', 'tenants'] as $table) {
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

    private function definition(): array
    {
        return $this->db->query('SHOW CREATE TABLE ' . $this->table('workflow_alert_states'))->getRowArray();
    }

    private function rows(): array
    {
        return $this->db->table('workflow_alert_states')->orderBy('id')->get()->getResultArray();
    }

    private function history(array $values = []): void
    {
        self::assertTrue($this->db->table('workflow_alert_states')->insert(array_replace([
            'tenant_id' => 1, 'event_kind' => 'inventory_low_stock', 'subject_id' => 9,
            'active' => 1, 'episode_number' => 7, 'started_at' => '2026-10-23 00:00:00', 'resolved_at' => null,
        ], $values)));
    }

    private function fk(): string
    {
        return (string) $this->db->query('SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->db->prefixTable('workflow_alert_states')])->getRow('CONSTRAINT_NAME');
    }

    private function rejectWithoutMutation(): void
    {
        $before = [$this->definition(), $this->rows()];
        try {
            $this->migration->up();
            self::fail('Must reject incompatible storage/history, not repair it.');
        } catch (RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        self::assertSame($before, [$this->definition(), $this->rows()]);
    }

    public function testCreatesAndReadoptsCompleteSchemaWithoutChangingHistoryOrAutoIncrement(): void
    {
        $this->migration->up();
        $this->history();
        $this->history(['subject_id' => 10, 'active' => 0, 'resolved_at' => '2026-10-23 00:01:00']);
        $before = [$this->definition(), $this->rows()];
        $this->migration->up();
        $this->migration->up();
        self::assertSame($before, [$this->definition(), $this->rows()]);
        self::assertCount(1, $this->db->query('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME=? AND INDEX_NAME=\'workflow_alert_identity\' AND COLUMN_NAME=\'subject_id\' AND NON_UNIQUE=0 AND SUB_PART IS NULL',
            [$this->db->prefixTable('workflow_alert_states')])->getResultArray());
    }

    public function testAdoptsOriginalForgeSchemaWithDifferentTenantFkName(): void
    {
        // Same complete schema as the original 000004, not a migration-history/tableExists shortcut.
        $this->db->query('CREATE TABLE ' . $this->table('workflow_alert_states') . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL,
            event_kind VARCHAR(64) NOT NULL, subject_id BIGINT UNSIGNED NOT NULL,
            active BOOLEAN NOT NULL DEFAULT 0, episode_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NULL, resolved_at DATETIME NULL,
            UNIQUE KEY workflow_alert_identity (tenant_id,event_kind,subject_id),
            CONSTRAINT ' . $this->prefix . 'legacy_tenant_fk FOREIGN KEY (tenant_id) REFERENCES ' . $this->table('tenants') . '(id)
                ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB');
        $this->history();
        $before = [$this->definition(), $this->rows()];
        $this->migration->up();
        self::assertSame($before, [$this->definition(), $this->rows()]);
    }

    public static function malformedSchemas(): array
    {
        return [
            'missing column' => ['ALTER TABLE %s DROP COLUMN resolved_at'],
            'wrong type' => ['ALTER TABLE %s MODIFY episode_number INT UNSIGNED NOT NULL DEFAULT 0'],
            'missing auto increment' => ['ALTER TABLE %s MODIFY id BIGINT UNSIGNED NOT NULL'],
            'wrong nullability' => ['ALTER TABLE %s MODIFY started_at DATETIME NOT NULL'],
            'wrong default' => ['ALTER TABLE %s ALTER active SET DEFAULT 1'],
            'unexpected column' => ['ALTER TABLE %s ADD extra INT NULL'],
            'wrong primary key' => ['ALTER TABLE %s DROP PRIMARY KEY, ADD PRIMARY KEY (id,tenant_id)'],
            'missing identity' => ['ALTER TABLE %s DROP INDEX workflow_alert_identity, ADD KEY tenant_lookup (tenant_id)'],
            'nonunique identity' => ['ALTER TABLE %s DROP INDEX workflow_alert_identity, ADD KEY workflow_alert_identity (tenant_id,event_kind,subject_id)'],
            'partial identity' => ['ALTER TABLE %s DROP INDEX workflow_alert_identity, ADD UNIQUE KEY workflow_alert_identity (tenant_id,event_kind(16),subject_id)'],
            'wrong order' => ['ALTER TABLE %s DROP INDEX workflow_alert_identity, ADD UNIQUE KEY workflow_alert_identity (tenant_id,subject_id,event_kind)'],
            'extra unique' => ['ALTER TABLE %s ADD UNIQUE KEY subject_only (subject_id)'],
            'unexpected check restriction' => ['ALTER TABLE %s ADD CHECK (episode_number<10)'],
        ];
    }

    #[DataProvider('malformedSchemas')]
    public function testRejectsSameNamedButIncompatibleMetadataWithoutRepair(string $alter): void
    {
        $this->migration->up();
        $this->db->query(sprintf($alter, $this->table('workflow_alert_states')));
        $this->rejectWithoutMutation();
    }

    public function testRejectsMissingForeignKeyAndWrongDeleteRule(): void
    {
        $this->migration->up();
        $this->db->query('ALTER TABLE ' . $this->table('workflow_alert_states') . ' DROP FOREIGN KEY ' . $this->fk());
        $this->rejectWithoutMutation();
        $this->db->query('ALTER TABLE ' . $this->table('workflow_alert_states') . ' ADD FOREIGN KEY (tenant_id) REFERENCES '
            . $this->table('tenants') . '(id) ON DELETE CASCADE ON UPDATE RESTRICT');
        $this->rejectWithoutMutation();
        $this->db->query('ALTER TABLE ' . $this->table('workflow_alert_states') . ' DROP FOREIGN KEY ' . $this->fk());
        $this->db->query('ALTER TABLE ' . $this->table('workflow_alert_states') . ' ADD FOREIGN KEY (tenant_id) REFERENCES '
            . $this->table('tenants') . '(id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->rejectWithoutMutation();
    }

    public function testRejectsForeignKeyReferencingTheWrongTable(): void
    {
        $this->migration->up();
        $this->db->query('ALTER TABLE ' . $this->table('workflow_alert_states') . ' DROP FOREIGN KEY ' . $this->fk());
        $this->db->query('ALTER TABLE ' . $this->table('workflow_events') . ' MODIFY id INT UNSIGNED NOT NULL');
        $this->db->query('ALTER TABLE ' . $this->table('workflow_alert_states') . ' ADD FOREIGN KEY (tenant_id) REFERENCES '
            . $this->table('workflow_events') . '(id) ON DELETE RESTRICT ON UPDATE RESTRICT');
        $this->rejectWithoutMutation();
    }

    public function testRejectsMissingPrerequisitesWithoutCreatingStateTable(): void
    {
        $this->db->query('DROP TABLE ' . $this->table('workflow_events'));
        try {
            $this->migration->up();
            self::fail('Missing outbox prerequisite must be rejected.');
        } catch (RuntimeException $e) {
            self::assertFalse($this->db->tableExists('workflow_alert_states'));
        }
    }

    public function testRejectsNontransactionalStateAndPrerequisiteEngines(): void
    {
        $this->migration->up();
        $this->db->query('ALTER TABLE ' . $this->table('workflow_alert_states') . ' DROP FOREIGN KEY ' . $this->fk());
        $this->db->query('ALTER TABLE ' . $this->table('workflow_alert_states') . ' ENGINE=MyISAM');
        $this->rejectWithoutMutation();
        $this->db->query('ALTER TABLE ' . $this->table('workflow_events') . ' ENGINE=MyISAM');
        $this->rejectWithoutMutation();
    }

    public static function malformedHistory(): array
    {
        return [
            'invalid active' => [['active' => 2]],
            'zero episode' => [['episode_number' => 0]],
            'zero subject' => [['subject_id' => 0]],
            'unsupported kind' => [['event_kind' => 'bmg_rtc_recovered']],
            'noncanonical kind' => [['event_kind' => 'BMG_RTC_INVALID']],
            'missing start' => [['started_at' => null]],
            'active with resolution' => [['resolved_at' => '2026-10-23 00:01:00']],
            'inactive without resolution' => [['active' => 0]],
            'resolution before start' => [['active' => 0, 'resolved_at' => '2026-10-22 23:59:59']],
        ];
    }

    #[DataProvider('malformedHistory')]
    public function testRejectsMalformedHistoryWithoutResettingCounters(array $values): void
    {
        $this->migration->up();
        $this->history($values);
        $this->rejectWithoutMutation();
    }

    public function testRejectsOrphansEvenWithForeignKeyMetadataIntact(): void
    {
        $this->migration->up();
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        try {
            $this->history(['tenant_id' => 999]);
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->rejectWithoutMutation();
    }

    public function testDownNeverDiscardsHistory(): void
    {
        $this->migration->up();
        $this->history();
        $before = [$this->definition(), $this->rows()];
        try {
            $this->migration->down();
            self::fail('Rollback must not delete durable episode history.');
        } catch (RuntimeException $e) {
            self::assertSame($before, [$this->definition(), $this->rows()]);
        }
    }
}

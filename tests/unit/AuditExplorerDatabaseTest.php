<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\AddAuditExplorerIndexes;
use App\Services\Audit\AuditExplorer;
use App\Services\Audit\AuditExplorerQuery;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

// Timestamped migrations are excluded from Composer's class map.
require_once APPPATH . 'Database/Migrations/2026-10-23-000001_AddAuditExplorerIndexes.php';

final class AuditExplorerDatabaseTest extends CIUnitTestCase
{
    private ?BaseConnection $scratch = null;

    private const READER_INDEXES = [
        'idx_audit_tenant_id' => ['tenant_id', 'id'],
        'idx_audit_tenant_event_id' => ['tenant_id', 'event_type', 'id'],
        'idx_audit_tenant_actor_id' => ['tenant_id', 'actor_user_id', 'id'],
    ];

    protected function tearDown(): void
    {
        try {
            $this->scratch?->close();
            $this->scratch = null;
            CurrentTenant::reset();
        } finally {
            parent::tearDown();
        }
    }

    public static function prefixes(): iterable
    {
        yield 'unprefixed' => [''];
        yield 'prefixed' => ['scratch_'];
    }

    private function openDatabase(string $prefix): void
    {
        $this->scratch = Database::connect([
            'DSN' => '',
            'hostname' => '',
            'username' => '',
            'password' => '',
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => $prefix,
            'pConnect' => false,
            'DBDebug' => true,
            'charset' => 'utf8',
            'DBCollat' => 'utf8_general_ci',
            'swapPre' => '',
            'encrypt' => false,
            'compress' => false,
            'strictOn' => false,
            'failover' => [],
            'port' => 0,
            'foreignKeys' => true,
            'busyTimeout' => 1000,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
        $this->scratch->initialize();
        $this->assertSame('SQLite3', $this->scratch->DBDriver);
        $this->assertSame(':memory:', $this->scratch->database);
        $this->assertSame($prefix, $this->scratch->DBPrefix);
        $databases = $this->scratch->query('PRAGMA database_list')->getResultArray();
        $this->assertCount(1, $databases);
        $this->assertSame('', $databases[0]['file'], 'SQLite must not open a file-backed database.');

        $this->scratch->query('CREATE TABLE ' . $this->scratch->prefixTable('users') . ' (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, username TEXT NOT NULL)');
        $this->scratch->query('CREATE TABLE ' . $this->scratch->prefixTable('audit_events') . ' (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, actor_user_id INTEGER NULL,
            event_type TEXT NOT NULL, action TEXT NOT NULL, entity_type TEXT NOT NULL,
            entity_id TEXT NOT NULL, occurred_at TEXT NOT NULL, payload_json TEXT NOT NULL,
            previous_hash TEXT NOT NULL, event_hash TEXT NOT NULL)');
        $this->scratch->table('users')->insertBatch([
            ['id' => 7, 'tenant_id' => 42, 'username' => 'Local actor'],
            ['id' => 8, 'tenant_id' => 99, 'username' => 'Foreign secret actor'],
        ]);
        CurrentTenant::set(42);
    }

    private function insertEvent(int $id, array $overrides = []): void
    {
        $this->assertTrue($this->scratch->table('audit_events')->insert($overrides + [
            'id' => $id, 'tenant_id' => 42, 'actor_user_id' => 7,
            'event_type' => 'clinic.visit', 'action' => 'UPDATE', 'entity_type' => 'patient',
            'entity_id' => "O'Neil%_0", 'occurred_at' => '2024-02-29 12:00:00',
            'payload_json' => '{"literal":"unchanged ' . $id . '"}',
            'previous_hash' => str_repeat('a', 64), 'event_hash' => hash('sha256', (string) $id),
        ]));
    }

    private function page(array $input = []): array
    {
        $query = AuditExplorerQuery::fromQuery($input);
        $this->assertSame([], $query->errors);
        return (new AuditExplorer($this->scratch))->page($query);
    }

    private function ids(array $page): array
    {
        return array_map('strval', array_column($page['events'], 'id'));
    }

    #[DataProvider('prefixes')]
    public function testTenantScopedActorJoinRetainsAnonymousMissingAndForeignActors(string $prefix): void
    {
        $this->openDatabase($prefix);
        $this->insertEvent(1);
        $this->insertEvent(2, ['actor_user_id' => 8]);
        $this->insertEvent(3, ['actor_user_id' => 999]);
        $this->insertEvent(4, ['actor_user_id' => null]);
        $this->insertEvent(5, ['tenant_id' => 99, 'actor_user_id' => 8]);

        $page = $this->page();
        $this->assertSame(['4', '3', '2', '1'], $this->ids($page));
        $this->assertSame([null, null, null, 'Local actor'], array_column($page['events'], 'actor_name'));
        $this->assertSame([42, 42, 42, 42], array_map('intval', array_column($page['events'], 'tenant_id')));
        $this->assertNull($page['older_cursor']);
        $this->assertNull($page['newer_cursor']);

        CurrentTenant::set(99);
        $foreignPage = $this->page();
        $this->assertSame(['5'], $this->ids($foreignPage));
        $this->assertSame('Foreign secret actor', $foreignPage['events'][0]['actor_name']);
    }

    #[DataProvider('prefixes')]
    public function testFilteredKeysetWalkInBothDirectionsHasNoOmissionsOrDuplicates(string $prefix): void
    {
        $this->openDatabase($prefix);
        $filters = [
            'event_type' => 'clinic.visit', 'action' => 'UPDATE', 'entity_type' => 'patient',
            'entity_id' => "O'Neil%_0", 'actor_user_id' => '7',
            'from' => '2024-02-29', 'to' => '2024-02-29', 'per_page' => '25',
        ];
        $distractors = [
            ['tenant_id' => 99], ['event_type' => 'other'], ['action' => 'CREATE'],
            ['entity_type' => 'other'], ['entity_id' => "O'NeilXX0"], ['actor_user_id' => 8],
            ['occurred_at' => '2024-02-28 23:59:59'], ['occurred_at' => '2024-03-01 00:00:00'],
        ];
        $expected = [];
        for ($i = 1; $i <= 65; $i++) {
            $id = $i * 10;
            $expected[] = (string) $id;
            $this->insertEvent($id);
            foreach ($distractors as $offset => $overrides) {
                $this->insertEvent($id + $offset + 1, $overrides);
            }
        }
        $expected = array_reverse($expected);
        $pages = [];
        $seen = [];
        $input = $filters;
        do {
            $this->assertLessThan(4, count($pages), 'Older cursor must make progress.');
            $page = $this->page($input);
            $pages[] = $page;
            $seen = array_merge($seen, $this->ids($page));
            if ($page['older_cursor'] === null) {
                break;
            }
            $this->assertSame((string) end($page['events'])['id'], $page['older_cursor']);
            $input = $filters + ['cursor' => $page['older_cursor'], 'direction' => 'older'];
        } while (true);

        $this->assertSame($expected, $seen);
        $this->assertCount(65, array_unique($seen));
        $this->assertSame([25, 25, 15], array_map(static fn (array $page): int => count($page['events']), $pages));
        $this->assertNull($pages[0]['newer_cursor'], 'Newer excluded rows must not create a link.');
        $this->assertNull($pages[2]['older_cursor'], 'Older excluded rows must not create a link.');

        $page = $pages[2];
        $backwards = $this->ids($page);
        for ($i = 1; $i >= 0; $i--) {
            $this->assertSame((string) $page['events'][0]['id'], $page['newer_cursor']);
            $page = $this->page($filters + ['cursor' => $page['newer_cursor'], 'direction' => 'newer']);
            $this->assertSame($pages[$i], $page, 'Returning newer must reproduce the original page and links.');
            $backwards = array_merge($this->ids($page), $backwards);
        }
        $this->assertSame($expected, $backwards);
        $this->assertCount(65, array_unique($backwards));
        $this->assertNull($page['newer_cursor']);
    }

    #[DataProvider('prefixes')]
    public function testInclusiveDatesAndExactLiteralEntityIds(string $prefix): void
    {
        $this->openDatabase($prefix);
        $this->insertEvent(1, ['occurred_at' => '2024-02-28 23:59:59']);
        $this->insertEvent(2, ['occurred_at' => '2024-02-29 00:00:00']);
        $this->insertEvent(3, ['occurred_at' => '2024-02-29 23:59:59']);
        $this->insertEvent(4, ['occurred_at' => '2024-03-01 00:00:00']);
        $this->insertEvent(5, ['entity_id' => "O'NeilXX0"]);
        $this->insertEvent(6, ['entity_id' => "prefixO'Neil%_0"]);
        $this->insertEvent(7, ['entity_id' => '0']);
        $this->insertEvent(8, ['entity_id' => '00']);
        $this->insertEvent(9, ['entity_id' => "x' OR 1=1 --"]);
        $literal = ['entity_id' => "O'Neil%_0"];

        $this->assertSame(['3', '2'], $this->ids($this->page($literal + ['from' => '2024-02-29', 'to' => '2024-02-29'])));
        $this->assertSame(['4', '3', '2'], $this->ids($this->page($literal + ['from' => '2024-02-29'])));
        $this->assertSame(['3', '2', '1'], $this->ids($this->page($literal + ['to' => '2024-02-29'])));
        $this->assertSame(['7'], $this->ids($this->page(['entity_id' => '0'])));
        $this->assertSame(['8'], $this->ids($this->page(['entity_id' => '00'])));
        $this->assertSame(['9'], $this->ids($this->page(['entity_id' => "x' OR 1=1 --"])));
    }

    #[DataProvider('prefixes')]
    public function testEmptyCursorResultsHaveNoNavigationLinks(string $prefix): void
    {
        $this->openDatabase($prefix);
        $this->insertEvent(10);
        $empty = ['events' => [], 'older_cursor' => null, 'newer_cursor' => null];
        $this->assertSame($empty, $this->page(['cursor' => '1', 'direction' => 'older']));
        $this->assertSame($empty, $this->page(['cursor' => '10', 'direction' => 'newer']));
        $this->assertSame($empty, $this->page(['entity_id' => 'absent', 'cursor' => '11']));
        $this->assertSame($empty, $this->page(['entity_id' => 'absent']));
    }

    private function ledgerRows(): array
    {
        return $this->scratch->table('audit_events')->orderBy('id')->get()->getResultArray();
    }

    private function schemaObjects(): array
    {
        return $this->scratch->query("SELECT type, name, tbl_name, sql FROM sqlite_master
            WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->getResultArray();
    }

    private function addLedgerGuards(): void
    {
        $table = $this->scratch->prefixTable('audit_events');
        $this->scratch->query('CREATE INDEX legacy_audit_action ON ' . $table . ' (tenant_id, action)');
        $this->scratch->query('CREATE UNIQUE INDEX legacy_audit_hash ON ' . $table . ' (event_hash)');
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $this->scratch->query('CREATE TRIGGER ledger_no_' . strtolower($operation) . ' BEFORE ' . $operation
                . ' ON ' . $table . " BEGIN SELECT RAISE(ABORT, 'append-only ledger'); END");
        }
    }

    #[DataProvider('prefixes')]
    public function testMigrationUpIsRepeatableAndDownPreservesLedgerAndUnrelatedIndexes(string $prefix): void
    {
        $this->openDatabase($prefix);
        $this->insertEvent(1);
        $this->insertEvent(2, ['tenant_id' => 99, 'actor_user_id' => 8]);
        $this->addLedgerGuards();
        $rows = $this->ledgerRows();
        $schema = $this->schemaObjects();
        $indexes = $this->scratch->getIndexData('audit_events');
        $migration = new AddAuditExplorerIndexes(Database::forge($this->scratch));

        $migration->up();
        $installedSchema = $this->schemaObjects();
        $installed = $this->scratch->getIndexData('audit_events');
        $this->assertCount(count($indexes) + 3, $installed);
        foreach (self::READER_INDEXES as $name => $fields) {
            $this->assertArrayHasKey($name, $installed);
            $this->assertSame($fields, $installed[$name]->fields);
            $this->assertSame('INDEX', $installed[$name]->type);
        }
        foreach ($indexes as $name => $definition) {
            $this->assertEquals($definition, $installed[$name]);
        }
        $this->assertSame($rows, $this->ledgerRows());

        $migration->up();
        $this->assertSame($installedSchema, $this->schemaObjects());
        $this->assertSame($rows, $this->ledgerRows());
        $migration->down();
        $this->assertSame($schema, $this->schemaObjects(), 'Only the three reader indexes may be removed.');
        $this->assertEquals($indexes, $this->scratch->getIndexData('audit_events'));
        $this->assertSame($rows, $this->ledgerRows());
    }

    public static function conflictingIndexes(): iterable
    {
        foreach (['', 'scratch_'] as $prefix) {
            foreach (['up', 'down'] as $operation) {
                foreach (self::READER_INDEXES as $name => $fields) {
                    yield $prefix . $operation . ' wrong columns ' . $name => [$prefix, $operation, $name, 'INDEX', ['action']];
                    yield $prefix . $operation . ' wrong order ' . $name => [$prefix, $operation, $name, 'INDEX', array_reverse($fields)];
                    yield $prefix . $operation . ' unique ' . $name => [$prefix, $operation, $name, 'UNIQUE INDEX', $fields];
                }
            }
        }
    }

    #[DataProvider('conflictingIndexes')]
    public function testMigrationRefusesConflictingNamedIndexWithoutChangingSchemaOrLedger(
        string $prefix,
        string $operation,
        string $name,
        string $kind,
        array $fields
    ): void {
        $this->openDatabase($prefix);
        $this->insertEvent(1);
        $this->addLedgerGuards();
        $this->scratch->query('CREATE ' . $kind . ' ' . $name . ' ON '
            . $this->scratch->prefixTable('audit_events') . ' (' . implode(', ', $fields) . ')');
        $rows = $this->ledgerRows();
        $schema = $this->schemaObjects();
        $migration = new AddAuditExplorerIndexes(Database::forge($this->scratch));
        try {
            $migration->{$operation}();
            $this->fail('Migration must refuse a conflicting reader index.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unexpected audit reader index definition: ' . $name, $exception->getMessage());
        }
        $this->assertSame($schema, $this->schemaObjects());
        $this->assertSame($rows, $this->ledgerRows());
    }
}

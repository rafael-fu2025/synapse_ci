<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\AddAiAnalysisScope;
use App\Database\Migrations\CreateAiInsights;
use App\Services\Ai\DisclosurePolicy;
use App\Services\Ai\InsightStore;
use App\Services\Ai\PayloadCipher;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Explicit disposable SQLite only. Run with fresh-framework-bootstrap.php (no dotenv). */
final class AiAnalysisScopeMigrationTest extends TestCase
{
    private BaseConnection $db;
    private InsightStore $store;
    private PayloadCipher $cipher;
    private const TABLES = ['ai_releases', 'ai_snapshots', 'ai_jobs', 'ai_dispatch_attempts', 'ai_insights', 'ai_reviews'];

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('Disposable SQLite requires sqlite3.');
        }
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'migration_scope_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
        require_once APPPATH . 'Database/Migrations/2026-10-28-000001_CreateAiInsights.php';
        require_once APPPATH . 'Database/Migrations/2026-10-29-000001_AddAiAnalysisScope.php';
        (new CreateAiInsights(Database::forge($this->db)))->up();
        $this->cipher = new PayloadCipher(base64_encode(str_repeat('m', 32)));
        $this->store = new InsightStore($this->db, $this->cipher,
            static fn (): int => strtotime('2026-10-29 12:00:00 UTC'), 30);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) { $this->db->close(); }
        parent::tearDown();
    }

    private function upgrade(): void
    {
        (new AddAiAnalysisScope(Database::forge($this->db)))->up();
    }

    private function payload(): array
    {
        return DisclosurePolicy::release(['count' => 240, 'contributors' => 20, 'unlinked' => 0],
            ['count' => 200, 'contributors' => 20, 'unlinked' => 0], '2026-09', '2026-08');
    }

    private function rows(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = $this->db->table($table)->orderBy('id')->get()->getResultArray();
        }
        return $rows;
    }

    /** Insert a legacy v1 guidance snapshot directly via SQL, bypassing the store's scope guard. */
    private function legacyGuidanceSnapshot(): void
    {
        $payload = $this->payload();
        $canonical = static function (array $value) use (&$canonical): array {
            if (!array_is_list($value)) { ksort($value, SORT_STRING); }
            return array_map(static fn (mixed $item): mixed => is_array($item) ? $canonical($item) : $item, $value);
        };
        $fingerprint = $this->cipher->fingerprint(1, 'guidance', 'snapshot', json_encode($canonical([
            'schema_version' => '1', 'disclosure_version' => '1', 'payload' => $payload,
        ]), JSON_THROW_ON_ERROR));
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('ai_snapshots')->insert([
            'tenant_id' => 1, 'unit' => 'guidance', 'period' => $payload['period']['label'],
            'schema_version' => '1', 'disclosure_version' => '1', 'fingerprint' => $fingerprint,
            'payload' => $this->cipher->encrypt(1, 'guidance', 'snapshot:' . $fingerprint, $payload),
            'created_at' => $now, 'expires_at' => gmdate('Y-m-d H:i:s', strtotime($now) + 30 * 86400),
        ]);
        foreach (['comparison_period' => 'previous', 'period' => 'current'] as $period => $side) {
            $month = $payload[$period]['label'];
            $metric = $payload['metrics'][0];
            $this->db->table('ai_releases')->insert([
                'tenant_id' => 1, 'unit' => 'guidance', 'metric_code' => $metric['metric_code'], 'month' => $month,
                'fingerprint' => $this->cipher->fingerprint(1, 'guidance', 'monthly_release',
                    json_encode([$metric['metric_code'], $month, $metric[$side . '_value']], JSON_THROW_ON_ERROR)),
                'created_at' => $now,
            ]);
        }
    }

    public function testUpgradePreservesEveryLegacyRowCiphertextHistoryAndIncomingForeignKey(): void
    {
        $snapshot = $this->store->saveSnapshot(1, 'clinic', $this->payload());
        // Simulate a legacy v1 guidance snapshot via direct SQL — the store now
        // correctly rejects v1 payloads under non-clinic units.
        $this->legacyGuidanceSnapshot();
        $job = $this->store->enqueue(1, 'clinic', $snapshot['id'], 1, 'synthetic', 'synthetic/model', 2);
        $claim = $this->store->claim(1, 'clinic', 'synthetic');
        self::assertNotNull($claim);
        $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 2, 10);
        $this->store->complete(1, 'clinic', $job['id'], ['summary' => 'Synthetic migration test.'], $claim['lease_token']);
        $insight = $this->store->listInsights(1, 'clinic')[0];
        $this->store->review(1, 'clinic', $insight['id'], 1, 1, 'accept');
        $this->db->query('CREATE TABLE migration_scope_history (id INTEGER PRIMARY KEY, class TEXT, batch INTEGER)');
        $this->db->table('history')->insert(['id' => 33, 'class' => CreateAiInsights::class, 'batch' => 33]);
        $this->db->query('CREATE TABLE migration_scope_operational_records (id INTEGER PRIMARY KEY, snapshot_id BIGINT NOT NULL,
            FOREIGN KEY (snapshot_id) REFERENCES migration_scope_ai_snapshots(id))');
        $this->db->table('operational_records')->insert(['id' => 1, 'snapshot_id' => $snapshot['id']]);
        $definition = $this->db->query("SELECT sql FROM sqlite_master WHERE name='migration_scope_operational_records'")->getRow('sql');
        $history = $this->db->table('history')->get()->getResultArray();
        $before = $this->rows();
        foreach (self::TABLES as $table) { self::assertNotEmpty($before[$table], $table); }
        $this->upgrade();
        $this->upgrade();
        $after = $this->rows();
        foreach ($after['ai_snapshots'] as &$row) {
            self::assertSame('clinic.activity', $row['analysis_code']);
            unset($row['analysis_code']);
        }
        unset($row);
        self::assertSame($before, $after);
        self::assertSame($history, $this->db->table('history')->get()->getResultArray());
        self::assertSame($definition, $this->db->query("SELECT sql FROM sqlite_master WHERE name='migration_scope_operational_records'")->getRow('sql'));
        self::assertSame([], $this->db->query('PRAGMA foreign_key_check')->getResultArray());
        self::assertCount(1, $this->db->getForeignKeyData('operational_records'));
        self::assertSame('accepted', $this->store->getInsight(1, 'clinic', $insight['id'])['status']);
        self::assertSame($snapshot['id'], $this->store->saveSnapshot(1, 'clinic', $this->payload())['id']);
        self::assertTrue($this->store->ready());
        self::assertTrue($this->store->supportsAnalysisScope());
    }

    public static function interruptedStages(): iterable
    {
        yield 'no DDL yet' => [false, false];
        yield 'column only' => [true, false];
        yield 'both unique keys' => [true, true];
    }

    #[DataProvider('interruptedStages')]
    public function testRetryCompletesInterruptedSchemaWithoutChangingLegacyBytes(bool $column, bool $key): void
    {
        $snapshot = $this->store->saveSnapshot(1, 'clinic', $this->payload());
        $before = $this->rows();
        if ($column) {
            $this->db->query("ALTER TABLE migration_scope_ai_snapshots ADD COLUMN analysis_code VARCHAR(64) NOT NULL DEFAULT 'clinic.activity'");
            unset($this->db->dataCache['field_names']['ai_snapshots']);
        }
        if ($key) {
            $this->db->query('CREATE UNIQUE INDEX migration_scope_ai_snapshot_analysis_release
                ON migration_scope_ai_snapshots (tenant_id, unit, analysis_code, period)');
        }
        self::assertFalse($this->store->supportsAnalysisScope());
        self::assertSame(!$column, $this->store->ready());
        $this->upgrade();
        self::assertTrue($this->store->supportsAnalysisScope());
        self::assertTrue($this->store->ready());
        $after = $this->rows();
        foreach ($after['ai_snapshots'] as &$row) { unset($row['analysis_code']); }
        unset($row);
        self::assertSame($before, $after);
        self::assertSame($snapshot['id'], $this->store->saveSnapshot(1, 'clinic', $this->payload())['id']);
        self::assertSame(0, $this->db->table('ai_department_access')->countAllResults());
    }

    public function testUniqueScopeAllowsDifferentAnalysisButRejectsDuplicateAndSeedsNoAccess(): void
    {
        $snapshot = $this->store->saveSnapshot(1, 'clinic', $this->payload());
        $this->upgrade();
        $row = $this->db->table('ai_snapshots')->where('id', $snapshot['id'])->get()->getRowArray();
        unset($row['id']);
        $row['analysis_code'] = 'clinic.complaints';
        self::assertTrue($this->db->table('ai_snapshots')->insert($row));
        self::assertTrue($this->db->table('ai_snapshots')->ignore(true)->insert($row));
        self::assertSame(0, $this->db->affectedRows());
        self::assertSame(2, $this->db->table('ai_snapshots')->countAllResults());
        self::assertSame(['id', 'tenant_id', 'role', 'unit', 'permission', 'created_by', 'created_at'],
            $this->db->getFieldNames('ai_department_access'));
        self::assertSame(0, $this->db->table('ai_department_access')->countAllResults());
        $unique = array_values(array_filter($this->db->getIndexData('ai_department_access'),
            static fn ($index): bool => strtoupper($index->type) === 'UNIQUE'
                && $index->fields === ['tenant_id', 'unit', 'role', 'permission']));
        self::assertCount(1, $unique);
    }

    public function testRollbackRefusesWithoutChangingDataOrSchema(): void
    {
        $this->store->saveSnapshot(1, 'clinic', $this->payload());
        $this->upgrade();
        $before = $this->rows();
        try {
            (new AddAiAnalysisScope(Database::forge($this->db)))->down();
            self::fail('Destructive automatic rollback must not run.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('requires review', $error->getMessage());
        }
        self::assertSame($before, $this->rows());
        self::assertTrue($this->store->supportsAnalysisScope());
        self::assertTrue($this->db->tableExists('ai_department_access'));
    }
}

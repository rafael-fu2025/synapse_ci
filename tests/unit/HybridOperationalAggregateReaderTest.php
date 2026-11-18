<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Reporting\ExportRange;
use App\Services\Reporting\OperationalExportService;
use CodeIgniter\Database\SQLite3\Connection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use RuntimeException;

/** Private SQLite data: no dotenv, migrations, live integrations, or ambient app database. */
final class HybridOperationalAggregateReaderTest extends CIUnitTestCase
{
    private HybridAggregateFixtureConnection $fixtureDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDb = $this->fixture();
    }

    protected function tearDown(): void
    {
        $this->fixtureDb->close();
        parent::tearDown();
    }

    public static function budgets(): iterable
    {
        yield 'one-day combined' => ['2026-10-01', ['clinic', 'guidance', 'bmg'], 3, 5];
        yield 'seven-day combined' => ['2026-10-07', ['clinic', 'guidance', 'bmg'], 9, 35];
        yield '31-day combined' => ['2026-10-31', ['clinic', 'guidance', 'bmg'], 33, 155];
        yield '31-day Clinic' => ['2026-10-31', ['clinic'], 31, 31];
        yield '31-day Guidance' => ['2026-10-31', ['guidance'], 1, 31];
        yield '31-day BMG' => ['2026-10-31', ['bmg'], 1, 93];
        yield '31-day Guidance and BMG' => ['2026-10-31', ['guidance', 'bmg'], 2, 124];
    }

    #[DataProvider('budgets')]
    public function testUnitAwareBudgetDoesNotSacrificeClinicRangeCounts(string $to, array $units, int $calls, int $metrics): void
    {
        $rows = $this->aggregate('2026-10-01', $to, 'UTC', $units);
        $this->assertCount($metrics + 1, $rows);
        $this->assertSame(['date_manila', 'unit', 'metric', 'count'], $rows[0]);
        $this->assertCount($calls, $this->fixtureDb->queries, 'Queries must follow the per-unit hybrid budget.');
    }

    public static function storageTimezones(): iterable
    {
        yield 'UTC' => ['UTC'];
        yield 'Manila' => ['Asia/Manila'];
        yield 'New York' => ['America/New_York'];
    }

    #[DataProvider('storageTimezones')]
    public function testBoundariesStatusesTenantsAndIndependentMetricDatesArePreserved(string $timezone): void
    {
        $this->seed($this->fixtureDb, $timezone);
        $this->assertSame(self::expectedRows(), $this->aggregate('2026-10-01', '2026-10-02', $timezone));
    }

    public function testDaySpecificStorageBoundsArePreservedAcrossDst(): void
    {
        $this->fixtureDb->table('counselling_cases')->insert(['id' => 1, 'tenant_id' => 37]);
        foreach (['2026-10-31 11:59:59', '2026-10-31 12:00:00', '2026-11-01 10:59:59',
            '2026-11-01 11:00:00', '2026-11-02 11:00:00'] as $time) {
            $this->fixtureDb->table('counselling_sessions')->insert([
                'tenant_id' => 37, 'case_id' => 1, 'status' => 'completed', 'started_at' => $time,
            ]);
        }
        $this->assertSame([
            ['date_manila', 'unit', 'metric', 'count'],
            ['2026-10-31', 'guidance', 'completed_guidance_sessions_started', 1],
            ['2026-11-01', 'guidance', 'completed_guidance_sessions_started', 2],
            ['2026-11-02', 'guidance', 'completed_guidance_sessions_started', 1],
        ], $this->aggregate('2026-10-31', '2026-11-02', 'America/New_York', ['guidance']));
        $this->assertCount(1, $this->fixtureDb->queries);
    }

    public function testRequestedUnitOrderDoesNotChangeOutputOrder(): void
    {
        $this->seed($this->fixtureDb, 'UTC');
        $this->assertSame(self::expectedRows(), $this->aggregate('2026-10-01', '2026-10-02', 'UTC', ['bmg', 'guidance', 'clinic']));
    }

    public static function selections(): iterable
    {
        yield 'Clinic' => [['clinic'], ['counselling_sessions', 'counselling_cases', 'bmg_batches'], 2];
        yield 'Guidance' => [['guidance'], ['clinic_encounters', 'bmg_batches'], 1];
        yield 'BMG' => [['bmg'], ['clinic_encounters', 'counselling_sessions', 'counselling_cases'], 1];
        yield 'Guidance and BMG' => [['guidance', 'bmg'], ['clinic_encounters'], 2];
    }

    #[DataProvider('selections')]
    public function testUnselectedTablesAreNotRead(array $units, array $unused, int $calls): void
    {
        $this->seed($this->fixtureDb, 'UTC');
        foreach ($unused as $table) {
            $this->fixtureDb->query('DROP TABLE ' . $this->fixtureDb->escapeIdentifiers($table));
        }
        $expected = [self::expectedRows()[0]];
        foreach (array_slice(self::expectedRows(), 1) as $row) {
            if (in_array($row[1], $units, true)) $expected[] = $row;
        }
        $this->assertSame($expected, $this->aggregate('2026-10-01', '2026-10-02', 'UTC', $units));
        $this->assertCount($calls, $this->fixtureDb->queries);
    }

    public function testClinicQueriesStayUngroupedAndRangeBased(): void
    {
        $this->seed($this->fixtureDb, 'UTC');
        $rows = $this->aggregate('2026-10-01', '2026-10-02', 'UTC', ['clinic']);
        $this->assertSame([self::expectedRows()[0], self::expectedRows()[1], self::expectedRows()[6]], $rows);
        $this->assertCount(2, $this->fixtureDb->queries);
        $bounds = [
            ['2026-09-30 16:00:00', '2026-10-01 16:00:00'],
            ['2026-10-01 16:00:00', '2026-10-02 16:00:00'],
        ];
        foreach ($this->fixtureDb->queries as $index => $query) {
            $this->assertMatchesRegularExpression('/COUNT\s*\(\s*\*\s*\)/i', $query['sql']);
            $this->assertDoesNotMatchRegularExpression('/\bCASE\b|\bGROUP\s+BY\b|\bJOIN\b|\bDATE\s*\(|\bORDER\s+BY\b/i', $query['sql']);
            $this->assertSame([37, 'completed', ...$bounds[$index]], $query['binds']);
        }
    }

    public function testSqlValuesAreBoundRatherThanInterpolated(): void
    {
        $this->aggregate('2026-10-01', '2026-10-02');
        foreach ($this->fixtureDb->queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/2026-|\b37\b|\x27completed\x27|\x27released\x27|\x27voided\x27/', $query['sql']);
            $this->assertSame(substr_count($query['sql'], '?'), count($query['binds']));
            $this->assertContains(37, $query['binds']);
        }
    }

    public function testEmptySelectionReturnsHeaderWithoutQueries(): void
    {
        $this->assertSame([['date_manila', 'unit', 'metric', 'count']], $this->aggregate('2026-10-01', '2026-10-31', 'UTC', []));
        $this->assertSame([], $this->fixtureDb->queries);
    }

    public function testPrefixesPreserveScopeAndCounts(): void
    {
        $db = $this->fixture('hybrid_');
        try {
            $this->seed($db, 'UTC');
            $rows = (new OperationalExportService())->aggregate($db, 37,
                ExportRange::fromInput(['from' => '2026-10-01', 'to' => '2026-10-02']), 'UTC', ['clinic', 'guidance', 'bmg']);
            $this->assertSame(self::expectedRows(), $rows);
        } finally {
            $db->close();
        }
    }

    public function testCallerTransactionRemainsOpenAndCanRollback(): void
    {
        $this->seed($this->fixtureDb, 'UTC');
        $this->assertTrue($this->fixtureDb->transBegin());
        $this->fixtureDb->table('clinic_encounters')->insert(['tenant_id' => 37, 'status' => 'completed', 'started_at' => '2026-09-30 16:00:00']);
        $rows = $this->aggregate('2026-10-01', '2026-10-02');
        $this->assertSame(4, $rows[1][3]);
        $this->assertSame(1, $this->fixtureDb->transDepth);
        $this->assertTrue($this->fixtureDb->transStatus());
        $this->assertTrue($this->fixtureDb->transRollback());
        $this->assertSame(self::expectedRows(), $this->aggregate('2026-10-01', '2026-10-02'));
    }

    public static function failedTables(): iterable
    {
        yield 'Clinic' => ['clinic_encounters', ['clinic']];
        yield 'Guidance' => ['counselling_sessions', ['guidance']];
        yield 'BMG' => ['bmg_batches', ['bmg']];
    }

    #[DataProvider('failedTables')]
    public function testQueryFailureCannotBecomeZeroActivity(string $table, array $units): void
    {
        Services::injectMock('logger', new NullLogger());
        $this->fixtureDb->close();
        $this->fixtureDb = $this->fixture('', false);
        $this->fixtureDb->query('DROP TABLE ' . $this->fixtureDb->escapeIdentifiers($table));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(503);
        $this->aggregate('2026-10-01', '2026-10-01', 'UTC', $units);
    }

    public function testLaterUnitFailureIsGenericAndStopsBeforeBmg(): void
    {
        Services::injectMock('logger', new NullLogger());
        $this->fixtureDb->query('DROP TABLE counselling_sessions');
        try {
            $this->aggregate('2026-10-01', '2026-10-02');
            $this->fail('A failed selected unit must reject the report.');
        } catch (RuntimeException $error) {
            $this->assertSame(503, $error->getCode());
            $this->assertSame('Operational report aggregation is unavailable.', $error->getMessage());
            $this->assertNull($error->getPrevious());
            foreach ($this->fixtureDb->queries as $query) {
                $this->assertStringNotContainsString('bmg_batches', $query['sql']);
            }
        }
    }

    public static function integerText(): iterable
    {
        yield 'seven' => ['7', 7];
        yield 'zero' => ['0', 0];
        yield 'maximum integer' => [(string) PHP_INT_MAX, PHP_INT_MAX];
    }

    #[DataProvider('integerText')]
    public function testScalarIntegerTextIsPreserved(string $value, int $count): void
    {
        $this->fixtureDb->scalarRows = [['aggregate_count' => $value]];
        $this->assertSame([
            ['date_manila', 'unit', 'metric', 'count'],
            ['2026-10-01', 'clinic', 'completed_clinic_encounters_started', $count],
        ], $this->aggregate('2026-10-01', '2026-10-01', 'UTC', ['clinic']));
    }

    public static function groupedIntegerText(): iterable
    {
        foreach (['guidance' => 'completed_guidance_sessions_started', 'bmg' => 'bmg_batches_started'] as $unit => $metric) {
            foreach (self::integerText() as $name => [$value, $count]) {
                yield $unit . ' ' . $name => [$unit, $metric, $value, $count];
            }
        }
    }

    #[DataProvider('groupedIntegerText')]
    public function testGroupedIntegerTextAndMissingDaysArePreserved(string $unit, string $metric, string $value, int $count): void
    {
        $this->fixtureDb->groupRows = [['date_manila' => '2026-10-01', 'unit' => $unit, 'metric' => $metric, 'aggregate_count' => $value]];
        $rows = $this->aggregate('2026-10-01', '2026-10-02', 'UTC', [$unit]);
        $this->assertSame(['2026-10-01', $unit, $metric, $count], $rows[1]);
        $this->assertSame(['2026-10-02', $unit, $metric, 0], $rows[$unit === 'guidance' ? 2 : 4]);
    }

    public static function invalidScalars(): iterable
    {
        yield 'missing row' => [[]];
        yield 'duplicate count rows' => [[['aggregate_count' => 1], ['aggregate_count' => 2]]];
        foreach ([null, -1, 1.5, 'bad', '999999999999999999999999999999', '01', '+1', '1.0', '1e3', '1 '] as $index => $value) {
            yield 'invalid value ' . $index => [[['aggregate_count' => $value]]];
        }
    }

    #[DataProvider('invalidScalars')]
    public function testInvalidScalarCannotProduceAnExport(array $rows): void
    {
        $this->fixtureDb->scalarRows = $rows;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(503);
        $this->aggregate('2026-10-01', '2026-10-01', 'UTC', ['clinic']);
    }

    public static function invalidGroups(): iterable
    {
        foreach (['guidance' => 'completed_guidance_sessions_started', 'bmg' => 'bmg_batches_started'] as $unit => $metric) {
            $valid = ['date_manila' => '2026-10-01', 'unit' => $unit, 'metric' => $metric, 'aggregate_count' => 1];
            foreach ([
                'outside date' => ['date_manila' => '2026-10-03'],
                'null date' => ['date_manila' => null],
                'wrong unit' => ['unit' => 'clinic'],
                'wrong metric' => ['metric' => 'private_note'],
                'negative' => ['aggregate_count' => -1],
                'null' => ['aggregate_count' => null],
                'fraction' => ['aggregate_count' => 1.5],
                'text' => ['aggregate_count' => 'bad'],
                'overflow' => ['aggregate_count' => '999999999999999999999999999999'],
            ] as $name => $change) {
                yield $unit . ' ' . $name => [[array_replace($valid, $change)], [$unit]];
            }
            yield $unit . ' duplicate group' => [[$valid, $valid], [$unit]];
        }
    }

    #[DataProvider('invalidGroups')]
    public function testInvalidGroupedRowsCannotProduceAnExport(array $rows, array $units): void
    {
        $this->fixtureDb->groupRows = $rows;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(503);
        $this->aggregate('2026-10-01', '2026-10-02', 'UTC', $units);
    }

    public static function resultFailures(): iterable
    {
        foreach (['clinic', 'guidance', 'bmg'] as $unit) {
            foreach (['false-result', 'true-result', 'invalid-id', 'truthy-boolean-id', 'truthy-scalar-id', 'fetch'] as $mode) {
                yield $unit . ' ' . $mode => [[$unit], $mode];
            }
        }
    }

    #[DataProvider('resultFailures')]
    public function testUnusableResultsFailClosedWithoutPrivateErrorDetails(array $units, string $mode): void
    {
        $this->fixtureDb->resultFailure = $mode;
        try {
            $this->aggregate('2026-10-01', '2026-10-01', 'UTC', $units);
            $this->fail('An unusable result must not become activity counts.');
        } catch (RuntimeException $error) {
            $this->assertSame(503, $error->getCode());
            $this->assertSame('Operational report aggregation is unavailable.', $error->getMessage());
            $this->assertNull($error->getPrevious());
            foreach ($this->fixtureDb->results as $result) $this->assertTrue($result->freed);
        }
    }

    public function testEverySuccessfulResultIsFreed(): void
    {
        $this->seed($this->fixtureDb, 'UTC');
        $this->assertSame(self::expectedRows(), $this->aggregate('2026-10-01', '2026-10-02'));
        $this->assertNotEmpty($this->fixtureDb->results);
        foreach ($this->fixtureDb->results as $result) $this->assertTrue($result->freed);
    }

    #[DataProvider('failedTables')]
    public function testMalformedResultIsFreedBeforeRejection(string $table, array $units): void
    {
        if ($units === ['clinic']) {
            $this->fixtureDb->scalarRows = [['aggregate_count' => null]];
        } else {
            $this->fixtureDb->groupRows = [['date_manila' => null, 'unit' => $units[0], 'metric' => 'unknown', 'aggregate_count' => 1]];
        }
        try {
            $this->aggregate('2026-10-01', '2026-10-01', 'UTC', $units);
            $this->fail('A malformed count must reject the report.');
        } catch (RuntimeException $error) {
            $this->assertSame(503, $error->getCode());
            $this->assertNotEmpty($this->fixtureDb->results);
            foreach ($this->fixtureDb->results as $result) $this->assertTrue($result->freed);
        }
    }

    private function aggregate(string $from, string $to, string $timezone = 'UTC', array $units = ['clinic', 'guidance', 'bmg']): array
    {
        $this->fixtureDb->queries = $this->fixtureDb->results = [];
        return (new OperationalExportService())->aggregate($this->fixtureDb, 37,
            ExportRange::fromInput(['from' => $from, 'to' => $to]), $timezone, $units);
    }

    private function fixture(string $prefix = '', bool $debug = true): HybridAggregateFixtureConnection
    {
        $db = new HybridAggregateFixtureConnection(['database' => ':memory:', 'DBPrefix' => $prefix,
            'DBDebug' => $debug, 'foreignKeys' => true, 'busyTimeout' => 1000]);
        if ($db->database !== ':memory:') throw new RuntimeException('Only a private in-memory fixture is permitted.');
        foreach ([
            'clinic_encounters' => '(tenant_id INTEGER, status TEXT, started_at TEXT, deleted_at TEXT)',
            'counselling_cases' => '(id INTEGER PRIMARY KEY, tenant_id INTEGER, deleted_at TEXT)',
            'counselling_sessions' => '(tenant_id INTEGER, case_id INTEGER, status TEXT, started_at TEXT, deleted_at TEXT)',
            'bmg_batches' => '(tenant_id INTEGER, status TEXT, started_at TEXT, completed_at TEXT)',
        ] as $table => $columns) {
            $db->query('CREATE TABLE ' . $db->escapeIdentifiers($db->prefixTable($table)) . ' ' . $columns);
        }
        return $db;
    }

    private function seed(HybridAggregateFixtureConnection $db, string $timezone): void
    {
        // Hand-specified equivalents, independent of ExportRange and the reader's bucketing.
        $times = [
            'UTC' => ['2026-09-30 15:59:59', '2026-09-30 16:00:00', '2026-10-01 15:59:59', '2026-10-01 16:00:00', '2026-10-02 05:00:00', '2026-10-02 16:00:00'],
            'Asia/Manila' => ['2026-09-30 23:59:59', '2026-10-01 00:00:00', '2026-10-01 23:59:59', '2026-10-02 00:00:00', '2026-10-02 13:00:00', '2026-10-03 00:00:00'],
            'America/New_York' => ['2026-09-30 11:59:59', '2026-09-30 12:00:00', '2026-10-01 11:59:59', '2026-10-01 12:00:00', '2026-10-02 01:00:00', '2026-10-02 12:00:00'],
        ][$timezone];
        [$before, $first, $lastFirst, $second, $middleSecond, $until] = $times;
        foreach ([
            [37, 'completed', $before, null], [37, 'completed', $first, null],
            [37, 'completed', $lastFirst, null], [37, 'completed', $second, null],
            [37, 'completed', $until, null], [38, 'completed', $first, null],
            [37, 'open', $first, null], [37, 'completed', null, null],
            [37, 'completed', $first, '2026-10-03 00:00:00'],
        ] as $row) {
            $db->table('clinic_encounters')->insert(array_combine(['tenant_id', 'status', 'started_at', 'deleted_at'], $row));
        }
        foreach ([[1, 37, null], [2, 38, null], [3, 37, '2026-10-03 00:00:00']] as $row) {
            $db->table('counselling_cases')->insert(array_combine(['id', 'tenant_id', 'deleted_at'], $row));
        }
        foreach ([
            [37, 1, 'completed', $first, null], [37, 1, 'completed', $second, null],
            [37, 3, 'completed', $lastFirst, '2026-10-03 00:00:00'],
            [37, 2, 'completed', $first, null], [37, 99, 'completed', $first, null],
            [38, 2, 'completed', $first, null], [37, 1, 'open', $lastFirst, null],
            [37, 1, 'completed', $until, null], [37, 1, 'completed', null, null],
        ] as $row) {
            $db->table('counselling_sessions')->insert(array_combine(['tenant_id', 'case_id', 'status', 'started_at', 'deleted_at'], $row));
        }
        foreach ([
            [37, 'released', $first, $second], [37, 'voided', $second, $middleSecond],
            [37, 'active', $lastFirst, $first], [37, 'released', $before, $first],
            [37, 'voided', null, $lastFirst], [38, 'released', $first, $first],
            [37, 'released', $until, $until], [37, 'released', $middleSecond, null],
        ] as $row) {
            $db->table('bmg_batches')->insert(array_combine(['tenant_id', 'status', 'started_at', 'completed_at'], $row));
        }
    }

    private static function expectedRows(): array
    {
        return [
            ['date_manila', 'unit', 'metric', 'count'],
            ['2026-10-01', 'clinic', 'completed_clinic_encounters_started', 3],
            ['2026-10-01', 'guidance', 'completed_guidance_sessions_started', 2],
            ['2026-10-01', 'bmg', 'bmg_batches_started', 2],
            ['2026-10-01', 'bmg', 'bmg_batches_released', 1],
            ['2026-10-01', 'bmg', 'bmg_batches_voided', 1],
            ['2026-10-02', 'clinic', 'completed_clinic_encounters_started', 1],
            ['2026-10-02', 'guidance', 'completed_guidance_sessions_started', 1],
            ['2026-10-02', 'bmg', 'bmg_batches_started', 2],
            ['2026-10-02', 'bmg', 'bmg_batches_released', 1],
            ['2026-10-02', 'bmg', 'bmg_batches_voided', 1],
        ];
    }
}

final class HybridAggregateFixtureBuilder extends \CodeIgniter\Database\SQLite3\Builder { }

/** Native SQLite fetching and cleanup, with one narrowly injected fetch failure. */
final class HybridAggregateFixtureResult extends \CodeIgniter\Database\SQLite3\Result
{
    public bool $freed = false;
    public bool $failOnFetch = false;

    public function getResultArray(): array
    {
        if ($this->failOnFetch) throw new RuntimeException('Synthetic private query detail.');
        return parent::getResultArray();
    }

    public function freeResult()
    {
        $this->freed = true;
        parent::freeResult();
    }
}

/** Normal calls execute real SQL; malformed fixtures still use native SQLite results. */
final class HybridAggregateFixtureConnection extends Connection
{
    public array $queries = [];
    public array $results = [];
    public ?array $scalarRows = null;
    public ?array $groupRows = null;
    public string $resultFailure = '';

    public function query(string $sql, $binds = null, bool $setEscapeFlags = true, string $queryClass = '')
    {
        $this->queries[] = ['sql' => $sql, 'binds' => $binds];
        $rows = $this->scalarRows ?? $this->groupRows;
        if (str_starts_with(ltrim($sql), 'SELECT')) {
            // Keep the original daily-count path valid so red tests fail on missing behavior, not fixture errors.
            if (str_contains($sql, 'numrows') && ($rows !== null || $this->resultFailure !== '')) {
                $result = parent::query('SELECT 0 AS numrows', [], true, $queryClass);
            } elseif ($this->resultFailure === 'false-result') {
                return false;
            } elseif ($this->resultFailure === 'true-result') {
                return true;
            } elseif ($rows !== null) {
                $selects = $values = [];
                if ($this->scalarRows !== null) {
                    if ($rows === []) $selects[] = 'SELECT 0 AS aggregate_count WHERE 1=0';
                    foreach ($rows as $row) {
                        $selects[] = 'SELECT ? AS aggregate_count';
                        $values[] = $row['aggregate_count'] ?? null;
                    }
                } else {
                    foreach ($rows as $row) {
                        $selects[] = 'SELECT ? AS date_manila, ? AS unit, ? AS metric, ? AS aggregate_count';
                        array_push($values, $row['date_manila'] ?? null, $row['unit'] ?? null, $row['metric'] ?? null, $row['aggregate_count'] ?? null);
                    }
                }
                $result = parent::query(implode(' UNION ALL ', $selects), $values, true, $queryClass);
            } else {
                $result = parent::query($sql, $binds, $setEscapeFlags, $queryClass);
            }
            if ($result instanceof HybridAggregateFixtureResult) {
                $this->results[] = $result;
                if (!str_contains($sql, 'numrows')) {
                    if ($this->resultFailure === 'invalid-id') $result->freeResult();
                    if (in_array($this->resultFailure, ['truthy-boolean-id', 'truthy-scalar-id'], true)) {
                        $result->freeResult();
                        $result->resultID = $this->resultFailure === 'truthy-boolean-id' ? true : 'not-a-native-handle';
                        $result->freed = false; // Observe the reader's subsequent cleanup independently.
                    }
                    if ($this->resultFailure === 'fetch') $result->failOnFetch = true;
                }
            }
            return $result;
        }
        return parent::query($sql, $binds, $setEscapeFlags, $queryClass);
    }
}

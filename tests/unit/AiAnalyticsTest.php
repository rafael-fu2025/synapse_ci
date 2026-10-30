<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiFailure;
use App\Services\Ai\ClinicSnapshotBuilder;
use App\Services\Ai\DisclosurePolicy;
use App\Services\Ai\ProgramLibrary;
use App\Services\Ai\SignalDetector;
use App\Services\Reporting\ExportRange;
use App\Services\Reporting\OperationalExportService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;

/**
 * Synthetic inputs and a private SQLite :memory: connection only; no migrations or default DB.
 * Released schema v1 has EXACTLY eight root keys, one metric, one descriptive signal and fixed
 * limitations. Contributors/unlinked counts are internal only. Percentages are rounded to two
 * decimals (null for a zero baseline); JSON whole-valued percentages may decode as integers.
 * Builder owns the latest complete Manila month; pure policy validation checks adjacency, not
 * the wall clock, authorization, publication history or the provenance of contributor checks.
 */
final class AiAnalyticsTest extends CIUnitTestCase
{
    private ?BaseConnection $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->close();
        $this->fixture = null;
        parent::tearDown();
    }

    private function db(): BaseConnection
    {
        if ($this->fixture === null) {
            $this->fixture = Database::connect([
                'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'ai_private_',
                'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
            ], false);
            $this->fixture->query('CREATE TABLE ai_private_people (id INTEGER PRIMARY KEY, tenant_id INTEGER, deleted_at TEXT, first_name TEXT)');
            // Deliberately permit broken links here so coverage failures can be tested.
            $this->fixture->query('CREATE TABLE ai_private_clinic_encounters (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER,
                status TEXT, started_at TEXT, completed_at TEXT, deleted_at TEXT, chief_complaint TEXT, assessment_notes TEXT)');
            for ($id = 1; $id <= 12; $id++) {
                $this->fixture->table('people')->insert(['id' => $id, 'tenant_id' => 1, 'first_name' => 'PRIVATE PERSON SENTINEL']);
            }
            $this->fixture->table('people')->insert(['id' => 101, 'tenant_id' => 2]);
        }
        return $this->fixture;
    }

    private function encounter(string $startedAt, ?int $person = 1, array $changes = []): void
    {
        $this->db()->table('clinic_encounters')->insert(array_replace([
            'tenant_id' => 1, 'person_id' => $person, 'status' => 'completed', 'started_at' => $startedAt,
            'completed_at' => '2030-01-01 00:00:00', 'chief_complaint' => 'PRIVATE COMPLAINT SENTINEL',
            'assessment_notes' => 'PRIVATE NARRATIVE SENTINEL',
        ], $changes));
    }

    private function seedMonth(string $month, string $storage = 'UTC', int $contributors = 10): void
    {
        $timestamp = $this->stored($month . '-01 00:00:00', $storage);
        for ($person = 1; $person <= $contributors; $person++) {
            $this->encounter($timestamp, $person);
        }
    }

    private function stored(string $manila, string $storage): string
    {
        return (new DateTimeImmutable($manila, new DateTimeZone('Asia/Manila')))
            ->setTimezone(new DateTimeZone($storage))->format('Y-m-d H:i:s');
    }

    private function build(string $storage = 'UTC', string $now = '2026-10-28T00:00:00Z', int $threshold = 10): array
    {
        return (new ClinicSnapshotBuilder($this->db(), new DateTimeImmutable($now), $threshold, $storage))->build(1);
    }

    private static function aggregate(int $count = 20, int $contributors = 10): array
    {
        return ['count' => $count, 'contributors' => $contributors, 'unlinked' => 0];
    }

    private static function payload(): array
    {
        return DisclosurePolicy::release(self::aggregate(240, 12), self::aggregate(200), '2026-09', '2026-08');
    }

    private function assertFailure(callable $operation, string $reason = 'insufficient_data', int $status = 422): void
    {
        try {
            $operation();
            self::fail('Unsafe or unsupported analytics input was accepted.');
        } catch (AiFailure $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($reason, $e->getMessage());
            self::assertSame($status, $e->getCode());
            self::assertNull($e->getPrevious());
        }
    }

    public function testExactReleasedSchemaContainsOnlyApprovedOperationalEvidence(): void
    {
        $payload = self::payload();
        self::assertSame(['schema_version', 'department', 'reporting_timezone', 'period', 'comparison_period',
            'metrics', 'signals', 'limitations'], array_keys($payload));
        self::assertSame('1', $payload['schema_version']);
        self::assertSame('clinic', $payload['department']);
        self::assertSame('Asia/Manila', $payload['reporting_timezone']);
        self::assertSame(['kind' => 'calendar_month', 'label' => '2026-09'], $payload['period']);
        self::assertSame(['kind' => 'calendar_month', 'label' => '2026-08'], $payload['comparison_period']);
        self::assertSame([[
            'evidence_id' => 'm1', 'metric_code' => 'completed_encounters', 'unit_of_analysis' => 'encounter',
            'current_value' => 240, 'previous_value' => 200, 'absolute_change' => 40,
            'relative_change_percent' => 20.0, 'quality_status' => 'usable',
        ]], $payload['metrics']);
        self::assertSame([[
            'signal_id' => 'activity_increased', 'evidence_id' => 'm1', 'unit_of_analysis' => 'encounter',
            'absolute_change' => 40, 'relative_change_percent' => 20.0,
        ]], $payload['signals']);
        self::assertSame([
            'Counts are completed clinic encounters started in each month, not unique people.',
            'Counts follow operational export rules, including soft-deleted completed encounters.',
            'Service use is not campus population prevalence.',
            'Two monthly counts do not establish statistical significance, seasonality, causation, or clinical need.',
            'Operating days, attendance, recording practices, and historical coverage require staff review.',
            'Complaint trends, guidance, and BMG analytics are unsupported pending separate validation.',
            'Operational proposals require staff review; no clinical recommendations or automated actions.',
        ], $payload['limitations']);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['contributors', 'unlinked', 'tenant_id', 'person_id', 'patient_id', 'gender', 'categories', 'approved_programs'] as $blocked) {
            self::assertStringNotContainsString('"' . $blocked . '"', $json);
        }
        // Persistence uses ordinary JSON, which need not preserve .0 on whole percentages.
        DisclosurePolicy::validatePayload(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
    }

    public static function unsafeAggregates(): iterable
    {
        foreach (['count', 'contributors', 'unlinked'] as $key) {
            foreach (['10', 10.0, true, null, -1, [], INF, NAN] as $index => $bad) {
                yield $key . ' invalid ' . $index => [array_replace(self::aggregate(), [$key => $bad]), 'insufficient_data'];
            }
        }
        yield 'repeated visits are not contributors' => [self::aggregate(2000, 9), 'insufficient_data'];
        yield 'contributors exceed encounters' => [self::aggregate(10, 11), 'insufficient_data'];
        yield 'unlinked contributor' => [self::aggregate() + [], 'insufficient_data', 1];
        yield 'true zero is not releasable at this threshold' => [self::aggregate(0, 0), 'insufficient_data'];
        yield 'missing data is not zero' => [[], 'invalid_snapshot'];
        yield 'unknown patient dimension' => [self::aggregate() + ['gender' => 'private'], 'invalid_snapshot'];
    }

    #[DataProvider('unsafeAggregates')]
    public function testBothMonthsMustPassTheWholeReleaseGate(array $unsafe, string $reason, ?int $unlinked = null): void
    {
        if ($unlinked !== null) {
            $unsafe['unlinked'] = $unlinked;
        }
        foreach ([true, false] as $currentUnsafe) {
            $this->assertFailure(fn () => DisclosurePolicy::release(
                $currentUnsafe ? $unsafe : self::aggregate(), $currentUnsafe ? self::aggregate() : $unsafe,
                '2026-09', '2026-08'), $reason);
        }
    }

    public function testThresholdIsAppliedToDistinctContributorsInBothMonths(): void
    {
        self::assertSame(20, DisclosurePolicy::release(self::aggregate(), self::aggregate(), '2026-09', '2026-08')['metrics'][0]['current_value']);
        foreach ([0, -1, 11] as $threshold) {
            $this->assertFailure(fn () => DisclosurePolicy::release(self::aggregate(), self::aggregate(), '2026-09', '2026-08', $threshold));
        }
        self::assertSame(20, DisclosurePolicy::release(self::aggregate(20, 11), self::aggregate(20, 11), '2026-09', '2026-08', 11)['metrics'][0]['current_value']);
    }

    public static function invalidMonths(): iterable
    {
        foreach (['2026-9', '2026-00', '2026-13', '0000-01', '2026-09-01', '2026-09/tenant', "2026-09\n", "2026-09\0", '2026-09 private'] as $label) {
            yield $label => [$label, '2026-08'];
        }
        yield 'same month' => ['2026-09', '2026-09'];
        yield 'nonadjacent month' => ['2026-09', '2026-07'];
        yield 'reversed months' => ['2026-08', '2026-09'];
        yield 'bad comparison label' => ['2026-09', '2026-8'];
    }

    #[DataProvider('invalidMonths')]
    public function testPolicyRejectsArbitraryOrNonadjacentPeriodLabels(string $month, string $previous): void
    {
        $this->assertFailure(fn () => DisclosurePolicy::release(self::aggregate(), self::aggregate(), $month, $previous), 'invalid_snapshot');
    }

    public function testYearBoundaryMonthsAreAdjacent(): void
    {
        $payload = DisclosurePolicy::release(self::aggregate(), self::aggregate(), '2027-01', '2026-12');
        DisclosurePolicy::validatePayload($payload);
        self::assertSame('2026-12', $payload['comparison_period']['label']);
    }

    public static function descriptiveSignals(): iterable
    {
        yield 'increase' => [24, 20, 4, 20.0, 'activity_increased'];
        yield 'decrease' => [10, 20, -10, -50.0, 'activity_decreased'];
        yield 'stable' => [20, 20, 0, 0.0, 'activity_stable'];
        yield 'zero baseline' => [20, 0, 20, null, 'activity_increased'];
        yield 'both zero' => [0, 0, 0, null, 'activity_stable'];
        yield 'decrease to zero' => [0, 20, -20, -100.0, 'activity_decreased'];
        yield 'relative percent not percentage points' => [20, 15, 5, 33.33, 'activity_increased'];
        yield 'largest safe integers' => [PHP_INT_MAX, PHP_INT_MAX - 1, 1, 0.0, 'activity_increased'];
    }

    #[DataProvider('descriptiveSignals')]
    public function testSignalsAreDeterministicDescriptiveArithmetic(int $current, int $previous, int $change, ?float $percent, string $signal): void
    {
        $metric = self::payload()['metrics'][0];
        $metric = array_replace($metric, ['current_value' => $current, 'previous_value' => $previous,
            'absolute_change' => $change, 'relative_change_percent' => $percent]);
        $expected = [['signal_id' => $signal, 'evidence_id' => 'm1', 'unit_of_analysis' => 'encounter',
            'absolute_change' => $change, 'relative_change_percent' => $percent]];
        self::assertSame($expected, SignalDetector::detect($metric));
        self::assertSame($expected, SignalDetector::detect($metric));
    }

    public static function payloadMutations(): iterable
    {
        yield 'root narrative' => [['context'], 'PRIVATE'];
        yield 'patient ID' => [['person_id'], 123];
        yield 'raw aggregates' => [['contributors'], 100];
        yield 'extra programs' => [['approved_programs'], []];
        yield 'schema type' => [['schema_version'], 1];
        yield 'guidance unsupported' => [['department'], 'guidance'];
        yield 'BMG unsupported' => [['department'], 'bmg'];
        yield 'timezone' => [['reporting_timezone'], 'UTC'];
        yield 'period not object' => [['period'], '2026-09'];
        yield 'arbitrary range' => [['period', 'from'], '2026-09-01'];
        yield 'weekly period' => [['period', 'kind'], 'week'];
        yield 'period label not string' => [['period', 'label'], ['2026-09']];
        yield 'leaky label' => [['period', 'label'], '2026-09 PRIVATE'];
        yield 'comparison dimension' => [['comparison_period', 'gender'], 'PRIVATE'];
        yield 'comparison not adjacent' => [['comparison_period', 'label'], '2026-07'];
        yield 'metrics not array' => [['metrics'], null];
        yield 'no metrics' => [['metrics'], []];
        yield 'not a metric list' => [['metrics'], ['m1' => []]];
        yield 'extra metric' => [['metrics', 1], []];
        yield 'metric not object' => [['metrics', 0], 'PRIVATE'];
        yield 'metric narrative' => [['metrics', 0, 'label'], 'PRIVATE'];
        yield 'contributor count in metric' => [['metrics', 0, 'contributors'], 10];
        yield 'complaints unsupported' => [['metrics', 0, 'metric_code'], 'complaint_trends'];
        yield 'unapproved evidence' => [['metrics', 0, 'evidence_id'], 'person-123'];
        yield 'not people' => [['metrics', 0, 'unit_of_analysis'], 'person'];
        yield 'not missing or suppressed zero' => [['metrics', 0, 'quality_status'], 'suppressed'];
        yield 'string current' => [['metrics', 0, 'current_value'], '240'];
        yield 'float previous' => [['metrics', 0, 'previous_value'], 200.0];
        yield 'boolean current' => [['metrics', 0, 'current_value'], true];
        yield 'negative previous' => [['metrics', 0, 'previous_value'], -1];
        yield 'missing is not zero' => [['metrics', 0, 'current_value'], null];
        yield 'invented delta' => [['metrics', 0, 'absolute_change'], 41];
        yield 'float delta' => [['metrics', 0, 'absolute_change'], 40.0];
        yield 'invented relative change' => [['metrics', 0, 'relative_change_percent'], 21.0];
        yield 'string percentage' => [['metrics', 0, 'relative_change_percent'], '20'];
        yield 'unknown percentage' => [['metrics', 0, 'relative_change_percent'], null];
        yield 'infinite percentage' => [['metrics', 0, 'relative_change_percent'], INF];
        yield 'NaN percentage' => [['metrics', 0, 'relative_change_percent'], NAN];
        yield 'signals not array' => [['signals'], 'PRIVATE'];
        yield 'omitted deterministic signal' => [['signals'], []];
        yield 'extra signal' => [['signals', 1], []];
        yield 'not a signal list' => [['signals'], ['s1' => []]];
        yield 'signal not object' => [['signals', 0], null];
        yield 'clinical signal' => [['signals', 0, 'signal_id'], 'outbreak_confirmed'];
        yield 'wrong direction' => [['signals', 0, 'signal_id'], 'activity_decreased'];
        yield 'signal wrong evidence' => [['signals', 0, 'evidence_id'], 'm2'];
        yield 'signal wrong unit' => [['signals', 0, 'unit_of_analysis'], 'percentage_point'];
        yield 'signal invented delta' => [['signals', 0, 'absolute_change'], 41];
        yield 'signal invented percent' => [['signals', 0, 'relative_change_percent'], 40];
        yield 'signal numeric string' => [['signals', 0, 'relative_change_percent'], '20'];
        yield 'signal confidence claim' => [['signals', 0, 'significance'], '95%'];
        yield 'limitations not list' => [['limitations'], 'PRIVATE'];
        yield 'missing limitations' => [['limitations'], []];
        yield 'source narrative limitation' => [['limitations', 0], 'PRIVATE'];
        yield 'extra limitation' => [['limitations', 7], 'PRIVATE'];
    }

    #[DataProvider('payloadMutations')]
    public function testStrictNestedAllowlistRejectsUnapprovedFieldsTypesAndClaims(array $path, mixed $value): void
    {
        $payload = self::payload();
        $target = &$payload;
        foreach ($path as $key) {
            $target = &$target[$key];
        }
        $target = $value;
        unset($target);
        $this->assertFailure(fn () => DisclosurePolicy::validatePayload($payload), 'invalid_snapshot');
    }

    public function testAllRequiredFieldsAreRequiredAndObjectKeyOrderDoesNotMatter(): void
    {
        $original = self::payload();
        foreach ([[], ['period'], ['comparison_period'], ['metrics', 0], ['signals', 0]] as $path) {
            $object = $original;
            foreach ($path as $key) {
                $object = $object[$key];
            }
            foreach (array_keys($object) as $missing) {
                $payload = $original;
                $target = &$payload;
                foreach ($path as $key) {
                    $target = &$target[$key];
                }
                unset($target[$missing], $target);
                $this->assertFailure(fn () => DisclosurePolicy::validatePayload($payload), 'invalid_snapshot');
            }
        }
        $payload = array_reverse($original, true);
        $payload['metrics'][0] = array_reverse($payload['metrics'][0], true);
        $payload['signals'][0] = array_reverse($payload['signals'][0], true);
        DisclosurePolicy::validatePayload($payload);
        self::assertSame(240, $payload['metrics'][0]['current_value']);
    }

    public function testSqlCountsOnlyCompletedTenantActivityUsingStartTimeAndManilaHalfOpenBounds(): void
    {
        $this->seedMonth('2026-08');
        $this->seedMonth('2026-09');
        $this->encounter('2026-08-31 15:59:59'); // Still August in Manila.
        $this->encounter('2026-09-30 15:59:59', 1, ['deleted_at' => '2026-10-01']);
        $this->encounter('2026-09-15 00:00:00', 1, ['completed_at' => null]);
        foreach ([
            ['started_at' => '2026-07-31 15:59:59'], ['started_at' => '2026-09-30 16:00:00'],
            ['started_at' => '2026-10-15 00:00:00'], ['tenant_id' => 2, 'person_id' => 101],
            ['status' => 'in_progress'], ['status' => 'cancelled'], ['status' => 'referred'],
        ] as $changes) {
            $this->encounter('2026-09-15 00:00:00', 1, $changes);
        }
        $payload = $this->build();
        self::assertSame(12, $payload['metrics'][0]['current_value']);
        self::assertSame(11, $payload['metrics'][0]['previous_value']);
        self::assertSame(9.09, $payload['metrics'][0]['relative_change_percent']);
        $query = (string) $this->db()->getLastQuery();
        self::assertSame(2, substr_count($query, 'COUNT(DISTINCT CASE'));
        self::assertSame(6, substr_count($query, 'COUNT('));
        self::assertStringContainsString('ai_private_clinic_encounters', $query);
        self::assertStringContainsString('ai_private_people', $query);
        self::assertStringNotContainsString('GROUP BY', $query);
        foreach (['chief_complaint', 'assessment_notes', 'first_name', 'gender', 'completed_at', 'deleted_at', 'SELECT *'] as $blocked) {
            self::assertStringNotContainsString($blocked, $query);
        }
        foreach (['PRIVATE', 'person_id', 'contributors', 'unlinked', 'tenant_id'] as $blocked) {
            self::assertStringNotContainsString($blocked, json_encode($payload, JSON_THROW_ON_ERROR));
        }
        // The AI collector does not change the historical daily export definition.
        foreach (['2026-08' => 11, '2026-09' => 12] as $month => $expected) {
            $start = new DateTimeImmutable($month . '-01');
            $range = ExportRange::fromInput(['from' => $start->format('Y-m-d'), 'to' => $start->format('Y-m-t')]);
            $rows = (new OperationalExportService())->aggregate($this->db(), 1, $range, 'UTC', ['clinic']);
            self::assertSame($expected, array_sum(array_column(array_slice($rows, 1), 3)));
        }
    }

    public static function clocksAndStorageZones(): iterable
    {
        foreach (['UTC', 'Asia/Manila', 'America/New_York'] as $storage) {
            foreach ([
                ['2026-09-30T15:59:59Z', '2026-08', '2026-07'],
                ['2026-09-30T16:00:00Z', '2026-09', '2026-08'],
                ['2026-12-31T16:00:00Z', '2026-12', '2026-11'],
                ['2027-01-31T16:00:00Z', '2027-01', '2026-12'],
                ['2024-02-29T16:00:00Z', '2024-02', '2024-01'],
                ['2024-03-31T16:00:00Z', '2024-03', '2024-02'],
            ] as [$now, $current, $previous]) {
                yield $storage . ' ' . $now => [$storage, $now, $current, $previous];
            }
        }
    }

    #[DataProvider('clocksAndStorageZones')]
    public function testLatestCompleteCalendarMonthAcrossClockStorageLeapAndYearBoundaries(string $storage, string $now, string $current, string $previous): void
    {
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Apia');
            $this->seedMonth($previous, $storage);
            $this->seedMonth($current, $storage);
            $currentStart = new DateTimeImmutable($current . '-01 00:00:00', new DateTimeZone('Asia/Manila'));
            $until = $currentStart->modify('+1 month');
            $this->encounter($this->stored($until->modify('-1 second')->format('Y-m-d H:i:s'), $storage));
            $this->encounter($this->stored($until->format('Y-m-d H:i:s'), $storage));
            $this->encounter($this->stored($previous . '-01 00:00:00', $storage), 1, ['status' => 'cancelled']);
            $payload = $this->build($storage, $now);
            self::assertSame($current, $payload['period']['label']);
            self::assertSame($previous, $payload['comparison_period']['label']);
            self::assertSame(11, $payload['metrics'][0]['current_value']);
            self::assertSame(10, $payload['metrics'][0]['previous_value']);
            self::assertSame('Pacific/Apia', date_default_timezone_get());
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testDefaultStorageTimezoneAndThresholdComeFromServerConfiguration(): void
    {
        $app = config('App');
        $ai = config('Ai');
        $timezone = $app->appTimezone;
        $threshold = $ai->minimumContributors;
        try {
            $app->appTimezone = 'Asia/Manila';
            $ai->minimumContributors = 11;
            $this->seedMonth('2026-08', 'Asia/Manila', 11);
            $this->seedMonth('2026-09', 'Asia/Manila', 10);
            $builder = new ClinicSnapshotBuilder($this->db(), new DateTimeImmutable('2026-10-28T00:00:00Z'));
            $this->assertFailure(fn () => $builder->build(1));
            $this->encounter('2026-09-01 00:00:00', 11);
            self::assertSame(11, $builder->build(1)['metrics'][0]['current_value']);
            $ai->minimumContributors = '10';
            $this->assertFailure(fn () => $builder->build(1));
        } finally {
            $app->appTimezone = $timezone;
            $ai->minimumContributors = $threshold;
        }
    }

    public static function unlinkedPeople(): iterable
    {
        yield 'NULL source' => [null];
        yield 'orphaned source' => [999];
        yield 'cross-tenant source' => [101];
    }

    #[DataProvider('unlinkedPeople')]
    public function testAnyUnlinkedContributorAbstainsEvenWithTenOtherDistinctPeople(?int $person): void
    {
        $this->seedMonth('2026-08');
        $this->seedMonth('2026-09');
        $this->encounter('2026-09-15 00:00:00', $person);
        $this->assertFailure(fn () => $this->build());
        $this->db()->table('clinic_encounters')->where('person_id', $person)->update(['started_at' => '2026-08-15 00:00:00']);
        $this->assertFailure(fn () => $this->build());
    }

    public function testLinkedSoftDeletedPeopleStillContributeWithoutChangingExportCohort(): void
    {
        $this->seedMonth('2026-08');
        $this->seedMonth('2026-09');
        $this->db()->table('people')->where('id', 1)->update(['deleted_at' => '2026-10-01']);
        self::assertSame(10, $this->build()['metrics'][0]['current_value']);
    }

    public function testRepeatedVisitsAndUnavailableOrEmptyMonthsCannotPassAsAdequateCoverage(): void
    {
        $this->seedMonth('2026-08');
        for ($visit = 0; $visit < 100; $visit++) {
            $this->encounter('2026-09-15 00:00:00');
        }
        $this->assertFailure(fn () => $this->build());
        $this->db()->table('clinic_encounters')->where('started_at', '2026-09-15 00:00:00')->delete();
        $this->assertFailure(fn () => $this->build());
        $this->seedMonth('2026-09');
        $this->db()->table('clinic_encounters')->where('started_at <', '2026-08-31 16:00:00')->delete();
        $this->assertFailure(fn () => $this->build());
    }

    public function testBuilderHasNoDateOrDimensionSelectorAndInvalidTenantNeverQueries(): void
    {
        $method = new ReflectionMethod(ClinicSnapshotBuilder::class, 'build');
        self::assertSame(['tenantId'], array_map(static fn ($parameter) => $parameter->getName(), $method->getParameters()));
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->never())->method('table');
        $this->assertFailure(fn () => (new ClinicSnapshotBuilder($db))->build(0));
        $this->assertFailure(fn () => (new ClinicSnapshotBuilder($db, threshold: 0))->build(1));
    }

    public function testDatabaseFailureIsGenericWithoutSqlSourceValuesOrChainedException(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('escape')->willThrowException(new RuntimeException('PRIVATE SQL AND DATABASE SECRET'));
        $builder = new ClinicSnapshotBuilder($db, new DateTimeImmutable('2026-10-28T00:00:00Z'), 10, 'UTC');
        $this->assertFailure(fn () => $builder->build(1), 'analytics_unavailable', 503);
        $this->assertFailure(fn () => $this->build('PRIVATE INVALID TIMEZONE'), 'analytics_unavailable', 503);
    }

    public static function aggregateRepresentations(): iterable
    {
        foreach ([0, 10, PHP_INT_MAX, '0', '10', (string) PHP_INT_MAX] as $index => $value) {
            yield 'valid ' . $index => [$value, true];
        }
        foreach ([-1, '-1', '01', '+1', '1.0', '1e2', '10 PRIVATE', '', true, false, null, 10.0, INF, [], (string) PHP_INT_MAX . '0'] as $index => $value) {
            yield 'invalid ' . $index => [$value, false];
        }
    }

    #[DataProvider('aggregateRepresentations')]
    public function testDriverCountsNormalizeWithoutLossyCastsOrOverflow(mixed $value, bool $valid): void
    {
        $method = new ReflectionMethod(ClinicSnapshotBuilder::class, 'aggregateInteger');
        if ($valid) {
            self::assertSame((int) $value, $method->invoke(null, $value));
        } else {
            $this->assertFailure(fn () => $method->invoke(null, $value), 'analytics_unavailable', 503);
        }
    }

    public function testLegacyLibraryTemplatesRemainVersionedServerOwnedAndPendingReferenceReview(): void
    {
        $library = ProgramLibrary::all();
        $programs = array_values(array_filter($library, static fn (array $program): bool =>
            in_array('clinic.activity', $program['eligible_analysis_codes'], true)));
        self::assertSame('department-programs-v2', ProgramLibrary::version());
        self::assertSame(['capacity_review', 'service_access_review'], array_column($programs, 'program_id'));
        self::assertSame([['activity_increased'], ['activity_decreased']], array_column($programs, 'eligible_signal_ids'));
        foreach ($programs as $program) {
            self::assertSame(ProgramLibrary::version(), $program['version']);
            self::assertSame('clinic', $program['department']);
            self::assertSame('draft_template', $program['status']);
            self::assertSame('Clinic lead', $program['required_reviewer']);
            self::assertSame('review_required', $program['reference_review_status']);
            self::assertCount(2, $program['references']);
            self::assertSame('https://www.who.int/publications/i/item/9789240029200', $program['references'][0]['url']);
            self::assertSame('https://www.who.int/news/item/18-01-2024-who-releases-ai-ethics-and-governance-guidance-for-large-multi-modal-models', $program['references'][1]['url']);
            self::assertStringContainsString('do not endorse', $program['limitations'][1]);
        }
        $copy = $library;
        $copy[0]['title'] = 'PRIVATE INJECTION';
        $copy[0]['references'][0]['url'] = 'https://untrusted.invalid';
        self::assertSame($library, ProgramLibrary::all());
    }
}

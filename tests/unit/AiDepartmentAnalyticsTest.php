<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\AddAiAnalysisScope;
use App\Database\Migrations\CreateAiInsights;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AnalysisCatalog;
use App\Services\Ai\BroadDisclosurePolicy;
use App\Services\Ai\DepartmentSnapshotBuilder;
use App\Services\Ai\DisclosurePolicy;
use App\Services\Ai\InsightStore;
use App\Services\Ai\PayloadCipher;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionMethod;

/**
 * Synthetic SQLite :memory: only; no migration runner, configured DB, dotenv or network.
 * Isolated invocation:
 * php vendor/phpunit/phpunit/phpunit --no-configuration --do-not-cache-result
 *   --bootstrap tests/_support/ai-runtime-bootstrap.php tests/unit/AiDepartmentAnalyticsTest.php
 * Internal aggregate contract is documented on BroadDisclosurePolicy, not a model-facing schema.
 */
final class AiDepartmentAnalyticsTest extends CIUnitTestCase
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
                'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'broad_private_',
                'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
            ], false);
            foreach ([
                'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, deleted_at TEXT, first_name TEXT',
                'clinic_encounters' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, status TEXT,
                    started_at TEXT, deleted_at TEXT, chief_complaint TEXT, assessment_notes TEXT',
                'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, category TEXT COLLATE NOCASE,
                    deleted_at TEXT, presenting_problem TEXT',
                'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, status TEXT,
                    started_at TEXT, deleted_at TEXT, notes TEXT',
                'bmg_batches' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, started_at TEXT,
                    completed_at TEXT, notes TEXT',
                'bmg_rotation_runs' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, motor_state TEXT, occurred_at TEXT, notes TEXT',
            ] as $table => $columns) {
                $this->fixture->query('CREATE TABLE broad_private_' . $table . ' (' . $columns . ')');
            }
            for ($i = 1; $i <= 100; $i++) {
                $this->fixture->table('people')->insert(['id' => $i, 'tenant_id' => 1,
                    'deleted_at' => '2026-10-01', 'first_name' => 'PRIVATE PERSON SENTINEL']);
            }
            $this->fixture->table('people')->insert(['id' => 201, 'tenant_id' => 2]);
        }
        return $this->fixture;
    }

    private function failure(callable $operation, string $reason = 'insufficient_data', int $status = 422): void
    {
        try {
            $operation();
            self::fail('Unsafe analytics were accepted.');
        } catch (AiFailure $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($reason, $e->getMessage());
            self::assertSame($status, $e->getCode());
            self::assertNull($e->getPrevious());
        }
    }

    /** Disjoint synthetic contributors; overlapping real contributors are tested separately in SQL. */
    private static function healthAggregate(string $analysis, array $counts): array
    {
        $total = array_sum($counts);
        $buckets = [];
        $codes = [...array_column(array_slice(AnalysisCatalog::metrics($analysis), 1), 'metric_code'),
            '__other', '__missing', ...($analysis === 'guidance.themes' ? ['__personal', '__crisis'] : [])];
        foreach ($codes as $code) {
            $count = $counts[$code] ?? 0;
            $buckets[$code] = ['count' => $count, 'contributors' => $count, 'complement_contributors' => $total - $count];
        }
        return ['count' => $total, 'contributors' => $total, 'unlinked' => 0, 'buckets' => $buckets];
    }

    private static function release(string $analysis, array $current, ?array $previous = null, int $threshold = 10): array
    {
        return BroadDisclosurePolicy::release($analysis, $current, $previous ?? $current, '2026-09', '2026-08', $threshold);
    }

    private static function payload(): array
    {
        return self::release('clinic.complaints', self::healthAggregate('clinic.complaints', [
            'complaint_headache' => 20, 'complaint_fever' => 10,
        ]));
    }

    private function build(string $analysis, string $storage = 'UTC', string $now = '2026-10-29T12:00:00Z'): array
    {
        return (new DepartmentSnapshotBuilder($this->db(), new DateTimeImmutable($now), 10, $storage))->build(1, $analysis);
    }

    private function stored(string $date, string $storage = 'UTC'): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('Asia/Manila')))
            ->setTimezone(new DateTimeZone($storage))->format('Y-m-d H:i:s');
    }

    private function clinic(string $date, string $complaint, ?int $person, array $changes = []): void
    {
        $this->db()->table('clinic_encounters')->insert(array_replace([
            'tenant_id' => 1, 'person_id' => $person, 'status' => 'completed', 'started_at' => $this->stored($date),
            'chief_complaint' => $complaint, 'assessment_notes' => 'PRIVATE NARRATIVE SENTINEL',
            'deleted_at' => '2026-10-01',
        ], $changes));
    }

    private function guidance(string $date, ?string $category, int $person, array $changes = []): void
    {
        $this->db()->table('counselling_cases')->insert(['tenant_id' => 1, 'person_id' => $person,
            'category' => $category, 'deleted_at' => '2026-10-01', 'presenting_problem' => 'PRIVATE PROBLEM SENTINEL']);
        $case = $this->db()->insertID();
        $this->db()->table('counselling_sessions')->insert(array_replace([
            'tenant_id' => 1, 'case_id' => $case, 'status' => 'completed', 'started_at' => $this->stored($date),
            'deleted_at' => '2026-10-01', 'notes' => 'PRIVATE NARRATIVE SENTINEL',
        ], $changes));
    }

    public function testCatalogIsFixedAndLegacyDefinitionRemainsButBuilderRejectsIt(): void
    {
        self::assertSame(['clinic.activity', 'clinic.complaints', 'guidance.activity', 'guidance.themes', 'bmg.batches', 'bmg.rotations'],
            array_keys(AnalysisCatalog::definitions()));
        foreach (AnalysisCatalog::definitions() as $code => $row) {
            self::assertSame(['code', 'unit', 'title', 'description', 'permission'], array_keys($row));
            self::assertSame($row, AnalysisCatalog::get($code));
            self::assertContains($row, AnalysisCatalog::forUnit($row['unit']));
            foreach (AnalysisCatalog::metrics($code) as $metric) {
                self::assertSame($metric['label'], AnalysisCatalog::metricLabel($code, $metric['metric_code']));
            }
        }
        self::assertSame([], AnalysisCatalog::forUnit('unknown'));
        self::assertCount(13, AnalysisCatalog::metrics('clinic.complaints'));
        self::assertCount(5, AnalysisCatalog::metrics('guidance.themes'));
        self::assertStringNotContainsString('personal', json_encode(AnalysisCatalog::metrics('guidance.themes')));
        self::assertStringNotContainsString('crisis', json_encode(AnalysisCatalog::definitions()));
        $this->failure(fn () => AnalysisCatalog::get('private source text'), 'invalid_request');
        $this->failure(fn () => AnalysisCatalog::metricLabel('clinic.complaints', '__other'), 'invalid_request');
        $this->failure(fn () => $this->build('clinic.activity'), 'invalid_request');
        $this->failure(fn () => self::release('clinic.activity', []), 'invalid_request');
    }

    public function testExactV2SchemaArithmeticAndJsonRoundTrip(): void
    {
        $current = self::healthAggregate('clinic.complaints', ['complaint_headache' => 30, 'complaint_fever' => 10]);
        $previous = self::healthAggregate('clinic.complaints', ['complaint_headache' => 10, 'complaint_fever' => 10]);
        $p = self::release('clinic.complaints', $current, $previous);
        self::assertSame(['schema_version', 'analysis_code', 'department', 'reporting_timezone', 'period',
            'comparison_period', 'metrics', 'signals', 'limitations'], array_keys($p));
        self::assertSame('2', $p['schema_version']);
        self::assertSame('Asia/Manila', $p['reporting_timezone']);
        self::assertSame(['kind' => 'calendar_month', 'label' => '2026-09'], $p['period']);
        self::assertSame(['kind' => 'calendar_month', 'label' => '2026-08'], $p['comparison_period']);
        self::assertSame([
            'evidence_id' => 'm2', 'metric_code' => 'complaint_headache', 'unit_of_analysis' => 'encounter',
            'current_value' => 30, 'previous_value' => 10, 'absolute_change' => 20, 'relative_change_percent' => 200.0,
            'current_denominator' => 40, 'previous_denominator' => 20, 'current_share_percent' => 75.0,
            'previous_share_percent' => 50.0, 'share_change_points' => 25.0, 'quality_status' => 'usable',
        ], $p['metrics'][1]);
        self::assertNull($p['metrics'][0]['current_denominator']);
        self::assertSame([
            ['signal_id' => 'activity_increased', 'evidence_id' => 'm1', 'metric_code' => 'completed_encounters'],
            ['signal_id' => 'share_increased', 'evidence_id' => 'm2', 'metric_code' => 'complaint_headache'],
            ['signal_id' => 'share_decreased', 'evidence_id' => 'm3', 'metric_code' => 'complaint_fever'],
        ], $p['signals']);
        $json = json_encode($p, JSON_THROW_ON_ERROR);
        foreach (['contributors', 'unlinked', 'buckets', '__other', 'tenant_id', 'person_id'] as $blocked) {
            self::assertStringNotContainsString($blocked, $json);
        }
        BroadDisclosurePolicy::validatePayload(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
    }

    public function testComplementarySuppressionHidesAdditionalCellsDeterministically(): void
    {
        $a = self::healthAggregate('clinic.complaints', [
            'complaint_headache' => 10, 'complaint_fever' => 10, 'complaint_dizziness' => 10, '__other' => 1,
        ]);
        $p = self::release('clinic.complaints', $a);
        self::assertSame(['completed_encounters', 'complaint_headache', 'complaint_fever'], array_column($p['metrics'], 'metric_code'));
        self::assertSame(31, $p['metrics'][1]['current_denominator']);
        self::assertStringNotContainsString('dizziness', json_encode($p));
        // Two small hidden buckets still require an additional eligible cell to reach a safe pool.
        $a = self::healthAggregate('clinic.complaints', [
            'complaint_headache' => 10, 'complaint_fever' => 10, '__other' => 1, '__missing' => 1,
        ]);
        self::assertCount(2, self::release('clinic.complaints', $a)['metrics']);
        $a = self::healthAggregate('clinic.complaints', ['complaint_headache' => 10, '__other' => 10]);
        $this->failure(fn () => self::release('clinic.complaints', $a));
        // Suppression needed in the previous month can create a single hidden cell in current.
        $a = self::healthAggregate('clinic.complaints', ['complaint_headache' => 10, 'complaint_fever' => 10]);
        $b = self::healthAggregate('clinic.complaints', ['complaint_headache' => 10, 'complaint_fever' => 10, '__other' => 1]);
        $this->failure(fn () => self::release('clinic.complaints', $a, $b));
        // Three eligible categories permit a safe common release after hiding TWO cells.
        $a = self::healthAggregate('clinic.complaints', ['complaint_headache' => 10, 'complaint_fever' => 10, 'complaint_cough' => 10]);
        $b = self::healthAggregate('clinic.complaints', ['complaint_headache' => 10, 'complaint_fever' => 10, 'complaint_cough' => 10, '__other' => 1]);
        self::assertSame(['completed_encounters', 'complaint_headache'], array_column(self::release('clinic.complaints', $a, $b)['metrics'], 'metric_code'));
    }

    public function testAdjacentComparisonsCannotSwapTheSharedMonthsProtectiveCell(): void
    {
        // September needs an eligible cell kept hidden alongside its one-person unmapped cell.
        $shared = self::healthAggregate('clinic.complaints', [
            'complaint_headache' => 10, 'complaint_fever' => 10, 'complaint_cough' => 10, '__other' => 1,
        ]);
        $august = $shared;
        $october = self::healthAggregate('clinic.complaints', [
            'complaint_headache' => 10, 'complaint_cough' => 10, '__other' => 10, '__missing' => 1,
        ]);
        $first = BroadDisclosurePolicy::release('clinic.complaints', $shared, $august, '2026-09', '2026-08');
        $next = BroadDisclosurePolicy::release('clinic.complaints', $october, $shared, '2026-10', '2026-09');
        $released = [];
        foreach ([$first['metrics'], $next['metrics']] as $metrics) {
            foreach (array_slice($metrics, 1) as $metric) {
                $released[$metric['metric_code']] = true;
            }
        }
        self::assertSame(['complaint_headache', 'complaint_fever'], array_keys($released));
        self::assertArrayNotHasKey('complaint_cough', $released);
        self::assertSame(11, $shared['count'] - array_sum(array_map(
            static fn (string $code): int => $shared['buckets'][$code]['count'], array_keys($released))));
    }

    public function testAdjacentReportWithDifferentCategoryMaskIsRejectedWithoutNewReleaseRows(): void
    {
        $shared = self::healthAggregate('clinic.complaints', [
            'complaint_headache' => 10, 'complaint_fever' => 10, 'complaint_cough' => 10, '__other' => 1,
        ]);
        $october = self::healthAggregate('clinic.complaints', [
            'complaint_headache' => 10, 'complaint_cough' => 10, '__other' => 10, '__missing' => 1,
        ]);
        $first = BroadDisclosurePolicy::release('clinic.complaints', $shared, $shared, '2026-09', '2026-08');
        $next = BroadDisclosurePolicy::release('clinic.complaints', $october, $shared, '2026-10', '2026-09');
        self::assertSame(['completed_encounters', 'complaint_headache', 'complaint_fever'], array_column($first['metrics'], 'metric_code'));
        self::assertSame(['completed_encounters', 'complaint_headache'], array_column($next['metrics'], 'metric_code'));
        self::assertSame($first['metrics'][0]['current_value'], $next['metrics'][0]['previous_value']);

        // Exercise the real mask/value freezes without modifying InsightStore or any live schema.
        $db = $this->db();
        require_once APPPATH . 'Database/Migrations/2026-10-28-000001_CreateAiInsights.php';
        require_once APPPATH . 'Database/Migrations/2026-10-29-000001_AddAiAnalysisScope.php';
        (new CreateAiInsights(Database::forge($db)))->up();
        (new AddAiAnalysisScope(Database::forge($db)))->up();
        $store = new InsightStore($db, new PayloadCipher(base64_encode(str_repeat('a', 32))),
            static fn (): int => (new DateTimeImmutable('2026-10-29T12:00:00Z'))->getTimestamp(), 30);
        self::assertTrue($store->supportsAnalysisScope());
        self::assertSame($first, $store->saveSnapshot(1, 'clinic', $first)['payload']);
        $before = $db->table('ai_releases')->orderBy('id')->get()->getResultArray();
        $snapshotsBefore = $db->table('ai_snapshots')->orderBy('id')->get()->getResultArray();
        $this->failure(fn () => $store->saveSnapshot(1, 'clinic', $next), 'snapshot_changed_requires_review', 409);
        self::assertSame($before, $db->table('ai_releases')->orderBy('id')->get()->getResultArray());
        self::assertSame($snapshotsBefore, $db->table('ai_snapshots')->orderBy('id')->get()->getResultArray());
        $changed = $shared;
        $changed['count']++;
        $changed['buckets']['__other']['count']++;
        // Keep synthetic contributor/complement counts coherent with the extra repeat event.
        $corrected = BroadDisclosurePolicy::release('clinic.complaints', $shared, $changed, '2026-10', '2026-09');
        self::assertSame(array_column($first['metrics'], 'metric_code'), array_column($corrected['metrics'], 'metric_code'));
        $this->failure(fn () => $store->saveSnapshot(1, 'clinic', $corrected), 'snapshot_changed_requires_review', 409);
        self::assertSame($before, $db->table('ai_releases')->orderBy('id')->get()->getResultArray());
        self::assertSame($snapshotsBefore, $db->table('ai_snapshots')->orderBy('id')->get()->getResultArray());
        self::assertSame(1, $db->table('ai_snapshots')->countAllResults());
    }

    public function testHealthThresholdsAndMalformedAggregatesFailClosedInBothMonths(): void
    {
        $safe = ['count' => 20, 'contributors' => 10, 'unlinked' => 0];
        foreach ([['count' => 2000, 'contributors' => 9, 'unlinked' => 0],
            ['count' => 10, 'contributors' => 11, 'unlinked' => 0],
            ['count' => 20, 'contributors' => 10, 'unlinked' => 1],
            ['count' => 0, 'contributors' => 0, 'unlinked' => 0],
        ] as $unsafe) {
            $this->failure(fn () => self::release('guidance.activity', $unsafe, $safe));
            $this->failure(fn () => self::release('guidance.activity', $safe, $unsafe));
        }
        foreach (['20', 20.0, true, -1, null, INF, NAN, []] as $bad) {
            $this->failure(fn () => self::release('guidance.activity', array_replace($safe, ['count' => $bad])));
        }
        $this->failure(fn () => self::release('guidance.activity', $safe + ['notes' => 'PRIVATE']), 'invalid_snapshot');
        $this->failure(fn () => self::release('guidance.activity', $safe, null, 0));
        $this->failure(fn () => self::release('guidance.activity', $safe, null, 11));
        $a = self::healthAggregate('clinic.complaints', ['complaint_headache' => 10, 'complaint_fever' => 10]);
        $a['buckets']['complaint_fever']['contributors'] = 9;
        $this->failure(fn () => self::release('clinic.complaints', $a));
        $a['buckets']['complaint_fever']['contributors'] = 10;
        $a['buckets']['complaint_headache']['complement_contributors'] = 9;
        $this->failure(fn () => self::release('clinic.complaints', $a));
        $a = self::healthAggregate('clinic.complaints', ['complaint_headache' => 10, 'complaint_fever' => 10]);
        $a['buckets']['__other']['count'] = 1;
        $this->failure(fn () => self::release('clinic.complaints', $a));
    }

    public function testPayloadRejectsEveryExtraKeyAndTamperedEvidence(): void
    {
        $p = self::payload();
        $mutations = [];
        $q = $p; $q['private'] = 'SOURCE'; $mutations[] = $q;
        $q = $p; unset($q['analysis_code']); $mutations[] = $q;
        foreach (['period', 'comparison_period'] as $key) {
            $q = $p; $q[$key]['private'] = 'SOURCE'; $mutations[] = $q;
            foreach (['2026-9', '2026-13', "2026-09\n", '0000-01', '2026-09-01', '2026-10'] as $label) {
                $q = $p; $q[$key]['label'] = $label; $mutations[] = $q;
            }
        }
        foreach (['schema_version' => 2, 'analysis_code' => 'clinic.activity', 'department' => 'guidance',
            'reporting_timezone' => 'UTC', 'limitations' => [], 'signals' => []] as $key => $bad) {
            $q = $p; $q[$key] = $bad; $mutations[] = $q;
        }
        foreach ([
            'evidence_id' => 'm99', 'metric_code' => 'complaint_private', 'unit_of_analysis' => 'person',
            'current_value' => '20', 'previous_value' => -1, 'absolute_change' => 1,
            'relative_change_percent' => INF, 'current_denominator' => 99, 'previous_denominator' => 99,
            'current_share_percent' => NAN, 'previous_share_percent' => 99.0,
            'share_change_points' => 1.0, 'quality_status' => 'unverified', 'notes' => 'PRIVATE',
        ] as $key => $bad) {
            $q = $p; $q['metrics'][1][$key] = $bad; $mutations[] = $q;
        }
        foreach (['signal_id' => 'significant_increase', 'evidence_id' => 'm99', 'metric_code' => 'private', 'extra' => 0] as $key => $bad) {
            $q = $p; $q['signals'][0][$key] = $bad; $mutations[] = $q;
        }
        $q = $p; $q['metrics'] = array_reverse($q['metrics']); $mutations[] = $q;
        $q = $p; $q['metrics'][] = $q['metrics'][1]; $q['signals'][] = $q['signals'][1]; $mutations[] = $q;
        $q = $p; $q['metrics'] = array_fill(0, 17, $q['metrics'][0]); $mutations[] = $q;
        $q = $p; $q['metrics'] = [$q['metrics'][0]]; $q['signals'] = [$q['signals'][0]]; $mutations[] = $q;
        foreach ($mutations as $q) {
            $this->failure(fn () => BroadDisclosurePolicy::validatePayload($q), 'invalid_snapshot');
        }
    }

    public function testClinicSqlUsesFixedVocabularyTenantSafePeopleAndManilaBoundaries(): void
    {
        foreach (['2026-08-01 00:00:00', '2026-09-01 00:00:00'] as $date) {
            for ($p = 1; $p <= 10; $p++) {
                $this->clinic($date, '  HeAdAcHe  ', $p);
                // The same contributors overlap buckets: complement cannot be subtraction.
                $this->clinic($date, 'fever', $p);
            }
        }
        $this->clinic('2026-07-31 23:59:59', 'fever', null);
        $this->clinic('2026-10-01 00:00:00', 'fever', null);
        $this->clinic('2026-09-10', 'PRIVATE COMPLAINT SENTINEL', 201, ['tenant_id' => 2]);
        $this->clinic('2026-09-10', 'PRIVATE COMPLAINT SENTINEL', null, ['status' => 'draft']);
        $before = $this->db()->table('clinic_encounters')->countAllResults();
        $p = $this->build('clinic.complaints');
        self::assertSame(20, $p['metrics'][0]['current_value']);
        self::assertSame(20, $p['metrics'][0]['previous_value']);
        self::assertCount(3, $p['metrics']);
        self::assertSame(50.0, $p['metrics'][1]['current_share_percent']);
        self::assertStringNotContainsString('PRIVATE', json_encode($p));
        self::assertSame($before, $this->db()->table('clinic_encounters')->countAllResults());
        $this->clinic('2026-09-10', 'fever', 201); // A person from another tenant is unlinked.
        $this->failure(fn () => $this->build('clinic.complaints'));
    }

    public function testClinicOtherMissingAndInvalidTextNeverLeaveSql(): void
    {
        foreach (['2026-08-15', '2026-09-15'] as $date) {
            foreach (['headache', 'fever', 'cough', 'colds'] as $i => $complaint) {
                for ($p = 1; $p <= 10; $p++) {
                    $this->clinic($date, $complaint, $p + $i * 10);
                }
            }
            $this->clinic($date, 'PRIVATE COMPLAINT SENTINEL', 41);
            $this->clinic($date, '', 42);
            $this->clinic($date, 'headache with PRIVATE narrative', 43);
        }
        $collector = new DepartmentSnapshotBuilder($this->db());
        $method = new ReflectionMethod($collector, 'health');
        $a = $method->invoke($collector, $this->db(), 1, 'clinic.complaints',
            $this->stored('2026-08-01'), $this->stored('2026-09-01'), $this->stored('2026-10-01'));
        self::assertSame(2, $a['current']['buckets']['__other']['count']);
        self::assertSame(1, $a['current']['buckets']['__missing']['count']);
        self::assertStringNotContainsString('PRIVATE', json_encode($a));
        self::assertSame(43, $a['current']['count']);
        $p = $this->build('clinic.complaints');
        self::assertSame(['completed_encounters', 'complaint_headache', 'complaint_fever', 'complaint_cough'], array_column($p['metrics'], 'metric_code'));
        self::assertSame(43, $p['metrics'][1]['current_denominator']);
    }

    public function testCategoryAndComplementGatesCountPeopleRatherThanRepeatedEvents(): void
    {
        foreach (['2026-08-15', '2026-09-15'] as $date) {
            for ($person = 1; $person <= 10; $person++) {
                $this->clinic($date, 'headache', $person);
            }
            for ($i = 0; $i < 100; $i++) {
                $this->clinic($date, 'fever', 11);
            }
        }
        // Total coverage passes; headache's complement and fever's own coverage do not.
        $this->failure(fn () => $this->build('clinic.complaints'));
        foreach (['2026-08-15', '2026-09-15'] as $date) {
            for ($person = 12; $person <= 20; $person++) {
                $this->clinic($date, 'fever', $person);
            }
        }
        self::assertCount(3, $this->build('clinic.complaints')['metrics']);
    }

    public function testHiddenPoolUsesDistinctCoverageNotTheSumOfSmallOverlappingBuckets(): void
    {
        foreach (['2026-08-15', '2026-09-15'] as $date) {
            foreach (['headache', 'fever'] as $complaint) {
                for ($person = 1; $person <= 10; $person++) {
                    $this->clinic($date, $complaint, $person);
                }
            }
            for ($person = 11; $person <= 19; $person++) {
                $this->clinic($date, '', $person);
                $this->clinic($date, 'PRIVATE UNKNOWN CATEGORY', $person);
            }
        }
        // Eighteen hidden events still have only nine people; an eligible cell must hide too.
        $payload = $this->build('clinic.complaints');
        self::assertSame(['completed_encounters', 'complaint_headache'], array_column($payload['metrics'], 'metric_code'));
        self::assertSame(38, $payload['metrics'][1]['current_denominator']);
    }

    public function testRepeatedVisitsDoNotPassDistinctContributorGate(): void
    {
        foreach (['2026-08-15', '2026-09-15'] as $date) {
            for ($i = 0; $i < 100; $i++) {
                $this->clinic($date, $i % 2 === 0 ? 'headache' : 'fever', 1);
            }
        }
        $this->failure(fn () => $this->build('clinic.complaints'));
    }

    public function testGuidanceHiddenCategoriesRetainedDeletedAndTenantSafeCaseJoin(): void
    {
        foreach (['2026-08-15', '2026-09-15'] as $date) {
            foreach (['academic' => 10, 'career' => 10, 'personal' => 10, 'crisis' => 1] as $category => $n) {
                for ($p = 1; $p <= $n; $p++) {
                    $this->guidance($date, $category, $p);
                }
            }
        }
        $themes = $this->build('guidance.themes');
        $activity = $this->build('guidance.activity');
        self::assertSame(['completed_sessions', 'category_academic', 'category_career'], array_column($themes['metrics'], 'metric_code'));
        self::assertSame(31, $themes['metrics'][1]['current_denominator']);
        self::assertSame($activity['metrics'][0], $themes['metrics'][0]);
        self::assertStringNotContainsString('personal', json_encode($themes));
        self::assertStringNotContainsString('crisis', json_encode($themes));
        self::assertStringNotContainsString('PRIVATE', json_encode($themes));
        $this->db()->table('counselling_cases')->insert(['id' => 1001, 'tenant_id' => 2, 'person_id' => 201, 'category' => 'academic']);
        $this->db()->table('counselling_sessions')->insert(['tenant_id' => 2, 'case_id' => 1001, 'status' => 'completed',
            'started_at' => $this->stored('2026-09-15')]);
        self::assertSame(31, $this->build('guidance.activity')['metrics'][0]['current_value']);
        $this->db()->table('counselling_sessions')->insert(['tenant_id' => 1, 'case_id' => 1001, 'status' => 'completed',
            'started_at' => $this->stored('2026-09-15')]);
        $this->failure(fn () => $this->build('guidance.activity'));
        $this->failure(fn () => $this->build('guidance.themes'));
    }

    public function testGuidanceUnknownMissingSingleHiddenCellAndUnlinkedPeopleAbstain(): void
    {
        foreach (['2026-08-15', '2026-09-15'] as $date) {
            for ($p = 1; $p <= 10; $p++) {
                $this->guidance($date, 'academic', $p);
                $this->guidance($date, 'PRIVATE CATEGORY SENTINEL', $p);
            }
        }
        $this->failure(fn () => $this->build('guidance.themes'));
        self::assertSame(20, $this->build('guidance.activity')['metrics'][0]['current_value']);
        foreach (['2026-08-15', '2026-09-15'] as $date) {
            $this->guidance($date, null, 1);
        }
        self::assertCount(2, $this->build('guidance.themes')['metrics']);
        $this->guidance('2026-09-15', 'academic', 201);
        $this->failure(fn () => $this->build('guidance.activity'));
    }

    public function testSourceCollationCannotPromoteUnapprovedGuidanceCategorySpellings(): void
    {
        foreach (['2026-08-15', '2026-09-15'] as $date) {
            foreach (['ACADEMIC', 'personal', 'crisis'] as $category) {
                for ($person = 1; $person <= 10; $person++) {
                    $this->guidance($date, $category, $person);
                }
            }
        }
        // The synthetic source deliberately has a case-insensitive category collation.
        // Guidance has no approved normalization rule, unlike clinic complaint text.
        $this->failure(fn () => $this->build('guidance.themes'));
        self::assertSame(30, $this->build('guidance.activity')['metrics'][0]['current_value']);
    }

    public function testMysqlCategoryComparisonsAreBinaryAfterOnlyApprovedNormalization(): void
    {
        // Mock escaping only: constructing the CASE must not open a MySQL connection.
        $db = $this->getMockBuilder(\CodeIgniter\Database\MySQLi\Connection::class)
            ->disableOriginalConstructor()->onlyMethods(['escape'])->getMock();
        $db->method('escape')->willReturnCallback(static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'");
        $builder = new DepartmentSnapshotBuilder($db);
        $method = new ReflectionMethod($builder, 'buckets');
        [$clinic] = $method->invoke($builder, $db, 'clinic.complaints');
        [$guidance] = $method->invoke($builder, $db, 'guidance.themes');
        self::assertStringContainsString('CAST(LOWER(TRIM(e.chief_complaint)) AS BINARY)', $clinic);
        self::assertStringContainsString('CAST(c.category AS BINARY)', $guidance);
        self::assertStringNotContainsString('LOWER', $guidance);
    }

    public function testGuidanceDefaultAndCrisisNeverBecomeReleasedMetricsEvenWithLargeCoverage(): void
    {
        foreach (['2026-08-15', '2026-09-15'] as $date) {
            foreach (['personal', 'crisis'] as $category) {
                for ($person = 1; $person <= 20; $person++) {
                    $this->guidance($date, $category, $person);
                }
            }
        }
        $this->failure(fn () => $this->build('guidance.themes'));
        self::assertSame(40, $this->build('guidance.activity')['metrics'][0]['current_value']);
        self::assertSame('guidance.activity', AnalysisCatalog::forUnit('guidance')[0]['code']);
        foreach (['2026-08-15', '2026-09-15'] as $date) {
            for ($person = 1; $person <= 10; $person++) {
                $this->guidance($date, 'academic', $person);
            }
        }
        $db = $this->db();
        $db->query('PRAGMA query_only = ON');
        try {
            $payload = $this->build('guidance.themes');
            self::assertSame(['completed_sessions', 'category_academic'], array_column($payload['metrics'], 'metric_code'));
            self::assertSame(50, $payload['metrics'][1]['current_denominator']);
            DisclosurePolicy::validatePayload($payload);
            foreach (['personal', 'crisis', 'PRIVATE', 'person_id', 'case_id', 'contributors'] as $private) {
                self::assertStringNotContainsString($private, json_encode($payload, JSON_THROW_ON_ERROR));
            }
        } finally {
            $db->query('PRAGMA query_only = OFF');
        }
        // A local case pointing at a foreign person is unlinked, even with a valid session-case link.
        $this->guidance('2026-08-15', 'academic', 201);
        $this->failure(fn () => $this->build('guidance.activity'));
        $this->failure(fn () => $this->build('guidance.themes'));
    }

    public function testGuidanceAlsoKeepsMonthlyCamouflageIndependentOfComparisonPartner(): void
    {
        $shared = self::healthAggregate('guidance.themes', [
            'category_academic' => 10, 'category_career' => 10, 'category_family' => 10, '__crisis' => 1,
        ]);
        $partner = self::healthAggregate('guidance.themes', [
            'category_academic' => 10, 'category_family' => 10, '__personal' => 10, '__crisis' => 1,
        ]);
        $first = self::release('guidance.themes', $shared);
        $next = BroadDisclosurePolicy::release('guidance.themes', $partner, $shared, '2026-10', '2026-09');
        self::assertSame(['completed_sessions', 'category_academic', 'category_career'], array_column($first['metrics'], 'metric_code'));
        self::assertSame(['completed_sessions', 'category_academic'], array_column($next['metrics'], 'metric_code'));
        self::assertSame(['completed_sessions', 'category_academic'], array_column(self::release('guidance.themes', $shared, $partner)['metrics'], 'metric_code'));
    }

    public function testBatchesUseSeparateStartAndTerminalCohortsWithNoCompletionRate(): void
    {
        foreach ([
            ['idle', '2026-09-01 00:00:00', null],
            ['processing', '2026-08-15', null],
            ['awaiting_output', '2026-08-15', null],
            ['curing', '2026-08-15', null],
            ['released', '2026-07-01', '2026-09-01 00:00:00'],
            ['released', '2026-07-01', '2026-09-30 23:59:59'],
            ['voided', '2026-07-01', '2026-08-31 23:59:59'],
            ['released', '2026-07-01', '2026-10-01 00:00:00'],
        ] as [$status, $started, $completed]) {
            $this->db()->table('bmg_batches')->insert(['tenant_id' => 1, 'status' => $status,
                'started_at' => $this->stored($started), 'completed_at' => $completed === null ? null : $this->stored($completed),
                'notes' => 'PRIVATE NARRATIVE SENTINEL']);
        }
        $this->db()->table('bmg_batches')->insert(['tenant_id' => 2, 'status' => 'PRIVATE INVALID', 'started_at' => $this->stored('2026-09-15')]);
        $p = $this->build('bmg.batches');
        self::assertSame([1, 2, 0], array_column($p['metrics'], 'current_value'));
        self::assertSame([3, 0, 1], array_column($p['metrics'], 'previous_value'));
        foreach ($p['metrics'] as $m) {
            self::assertNull($m['current_denominator']);
            self::assertNull($m['current_share_percent']);
        }
        self::assertStringNotContainsString('PRIVATE', json_encode($p));
        $this->db()->table('bmg_batches')->insert(['tenant_id' => 1, 'status' => 'PRIVATE INVALID', 'started_at' => $this->stored('2026-09-15')]);
        $this->failure(fn () => $this->build('bmg.batches'));
    }

    public function testRotationsUseManilaNotStorageTimezoneAndIncludeAllAcceptedStates(): void
    {
        foreach ([['turned_normal', '2026-09-01 00:00:00'], ['stopped', '2026-09-30 23:59:59'],
            ['failed', '2026-09-15 00:00:00'], ['interrupted', '2026-08-31 23:59:59'],
            ['failed', '2026-10-01 00:00:00'], ['failed', '2026-07-31 23:59:59']] as [$state, $date]) {
            $this->db()->table('bmg_rotation_runs')->insert(['tenant_id' => 1, 'motor_state' => $state, 'occurred_at' => $date,
                'notes' => 'PRIVATE NARRATIVE SENTINEL']);
        }
        $this->db()->table('bmg_rotation_runs')->insert(['tenant_id' => 2, 'motor_state' => 'PRIVATE INVALID', 'occurred_at' => '2026-09-15']);
        $p = $this->build('bmg.rotations', 'UTC');
        self::assertSame([3, 1, 0], array_column($p['metrics'], 'current_value'));
        self::assertSame([1, 0, 1], array_column($p['metrics'], 'previous_value'));
        self::assertSame(33.33, $p['metrics'][1]['current_share_percent']);
        self::assertSame('share_increased', $p['signals'][1]['signal_id']);
        self::assertSame($p, $this->build('bmg.rotations', 'America/New_York'));
        self::assertStringNotContainsString('PRIVATE', json_encode($p));
        $this->db()->table('bmg_rotation_runs')->insert(['tenant_id' => 1, 'motor_state' => 'PRIVATE INVALID', 'occurred_at' => '2026-09-15']);
        $this->failure(fn () => $this->build('bmg.rotations'));
    }

    public function testHealthAndBatchBoundsConvertStorageTimezonesWhileRotationsIgnoreThem(): void
    {
        foreach (['UTC', 'America/New_York', 'Asia/Manila'] as $storage) {
            foreach (['clinic_encounters', 'counselling_sessions', 'counselling_cases', 'bmg_batches'] as $table) {
                $this->db()->table($table)->emptyTable();
            }
            foreach (['2026-08-01 00:00:00', '2026-09-01 00:00:00', '2026-09-30 23:59:59'] as $date) {
                for ($person = 1; $person <= 10; $person++) {
                    foreach (['headache', 'fever'] as $complaint) {
                        $this->clinic($date, $complaint, $person, ['started_at' => $this->stored($date, $storage)]);
                    }
                    $this->guidance($date, 'academic', $person, ['started_at' => $this->stored($date, $storage)]);
                }
                $this->db()->table('bmg_batches')->insert(['tenant_id' => 1, 'status' => 'released',
                    'started_at' => $this->stored($date, $storage), 'completed_at' => $this->stored($date, $storage)]);
            }
            foreach (['2026-07-31 23:59:59', '2026-10-01 00:00:00'] as $outside) {
                $this->clinic($outside, 'headache', null, ['started_at' => $this->stored($outside, $storage)]);
                $this->guidance($outside, 'academic', 201, ['started_at' => $this->stored($outside, $storage)]);
                $this->db()->table('bmg_batches')->insert(['tenant_id' => 1, 'status' => 'released',
                    'started_at' => $this->stored($outside, $storage), 'completed_at' => $this->stored($outside, $storage)]);
            }
            // UTC is still September, but Manila has just entered October.
            $now = '2026-09-30T16:00:00Z';
            $db = $this->db();
            $db->query('PRAGMA query_only = ON');
            try {
                $clinic = $this->build('clinic.complaints', $storage, $now);
                $guidance = $this->build('guidance.activity', $storage, $now);
                $batches = $this->build('bmg.batches', $storage, $now);
                self::assertSame('2026-09', $clinic['period']['label']);
                self::assertSame([40, 20, 20], array_column($clinic['metrics'], 'current_value'));
                self::assertSame([20, 10, 10], array_column($clinic['metrics'], 'previous_value'));
                self::assertSame(20, $guidance['metrics'][0]['current_value']);
                self::assertSame(10, $guidance['metrics'][0]['previous_value']);
                self::assertSame([2, 2, 0], array_column($batches['metrics'], 'current_value'));
                self::assertSame([1, 1, 0], array_column($batches['metrics'], 'previous_value'));
            } finally {
                $db->query('PRAGMA query_only = OFF');
            }
        }
        $this->db()->table('bmg_rotation_runs')->insert(['tenant_id' => 1, 'motor_state' => 'stopped',
            'occurred_at' => '2026-09-01 00:00:00']);
        self::assertSame($this->build('bmg.rotations'), $this->build('bmg.rotations', 'INVALID STORAGE ZONE'));
    }

    public function testOperationalZeroIsPermittedOnlyWithSomeCombinedEvidenceAndNullShares(): void
    {
        $empty = ['counts' => ['rotation_reports' => 0, 'rotation_failed' => 0, 'rotation_interrupted' => 0]];
        $some = ['counts' => ['rotation_reports' => 1, 'rotation_failed' => 1, 'rotation_interrupted' => 0]];
        $this->failure(fn () => self::release('bmg.rotations', $empty));
        $p = self::release('bmg.rotations', $some, $empty);
        self::assertNull($p['metrics'][0]['relative_change_percent']);
        self::assertNull($p['metrics'][1]['previous_share_percent']);
        self::assertNull($p['metrics'][1]['share_change_points']);
        self::assertSame('share_stable', $p['signals'][1]['signal_id']);
        BroadDisclosurePolicy::validatePayload($p);
        $this->failure(fn () => self::release('bmg.rotations', ['counts' => array_replace($some['counts'], ['rotation_interrupted' => 1])]));
        $this->failure(fn () => $this->build('bmg.batches'));
        $this->failure(fn () => $this->build('bmg.rotations'));
    }

    public function testOnlyLastTwoFullManilaMonthsIncludingYearRolloverAreSelected(): void
    {
        foreach (['2026-11-01 00:00:00', '2026-12-31 23:59:59', '2027-01-01 00:00:00'] as $date) {
            $this->db()->table('bmg_rotation_runs')->insert(['tenant_id' => 1, 'motor_state' => 'stopped', 'occurred_at' => $date]);
        }
        $p = $this->build('bmg.rotations', 'UTC', '2026-12-31T16:00:00Z');
        self::assertSame('2026-12', $p['period']['label']);
        self::assertSame('2026-11', $p['comparison_period']['label']);
        self::assertSame(1, $p['metrics'][0]['current_value']);
        self::assertSame(1, $p['metrics'][0]['previous_value']);
    }

    public function testDatabaseFailureDoesNotExposeSqlOrSourceDetails(): void
    {
        $this->db()->query('DROP TABLE broad_private_clinic_encounters');
        $this->failure(fn () => $this->build('clinic.complaints'), 'analytics_unavailable', 503);
        $this->failure(fn () => $this->build('guidance.activity', 'PRIVATE INVALID TIMEZONE'), 'analytics_unavailable', 503);
        $this->failure(fn () => (new DepartmentSnapshotBuilder($this->db()))->build(0, 'guidance.activity'));
        $this->failure(fn () => $this->build('PRIVATE ANALYSIS'), 'invalid_request');
    }
}

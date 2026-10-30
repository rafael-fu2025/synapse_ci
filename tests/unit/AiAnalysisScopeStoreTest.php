<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\AddAiAnalysisScope;
use App\Database\Migrations\CreateAiInsights;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AnalysisCatalog;
use App\Services\Ai\BroadDisclosurePolicy;
use App\Services\Ai\DisclosurePolicy;
use App\Services\Ai\InsightStore;
use App\Services\Ai\PayloadCipher;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Explicit in-memory SQLite only; never a database group or application migration runner. */
final class AiAnalysisScopeStoreTest extends TestCase
{
    private BaseConnection $db;
    private PayloadCipher $cipher;
    private InsightStore $store;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('Disposable SQLite requires sqlite3.');
        }
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'scope_test_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
        require_once APPPATH . 'Database/Migrations/2026-10-28-000001_CreateAiInsights.php';
        require_once APPPATH . 'Database/Migrations/2026-10-29-000001_AddAiAnalysisScope.php';
        (new CreateAiInsights(Database::forge($this->db)))->up();
        $this->now = strtotime('2026-10-29 12:00:00 UTC');
        $this->cipher = new PayloadCipher(base64_encode(str_repeat('s', 32)));
        $this->store = new InsightStore($this->db, $this->cipher, fn (): int => $this->now, 1);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
        parent::tearDown();
    }

    private function upgrade(): void
    {
        (new AddAiAnalysisScope(Database::forge($this->db)))->up();
    }

    private function legacy(string $month = '2026-09', int $current = 240, int $previous = 200): array
    {
        return DisclosurePolicy::release(['count' => $current, 'contributors' => 20, 'unlinked' => 0],
            ['count' => $previous, 'contributors' => 20, 'unlinked' => 0], $month, $this->previous($month));
    }

    private function previous(string $month): string
    {
        return (new \DateTimeImmutable($month . '-01', new \DateTimeZone('UTC')))->modify('-1 month')->format('Y-m');
    }

    /** Independent synthetic aggregates enter through the real v2 disclosure boundary. */
    private function health(string $analysis, string $month = '2026-09', int $current = 240, int $previous = 200,
        array $currentContributors = [], array $previousContributors = []): array
    {
        $aggregate = static function (int $count, array $contributors) use ($analysis): array {
            $value = ['count' => $count, 'contributors' => 20, 'unlinked' => 0];
            if ($analysis === 'guidance.activity') {
                return $value;
            }
            $buckets = [];
            foreach (array_slice(AnalysisCatalog::metrics($analysis), 1) as $i => $metric) {
                $n = [40, 30, 5][$i] ?? 0;
                $buckets[$metric['metric_code']] = ['count' => $n,
                    'contributors' => $contributors[$metric['metric_code']] ?? min(10, $n), 'complement_contributors' => 20];
            }
            $buckets['__other'] = ['count' => $count - 85, 'contributors' => 10, 'complement_contributors' => 20];
            $buckets['__missing'] = ['count' => 10, 'contributors' => 10, 'complement_contributors' => 20];
            if ($analysis === 'guidance.themes') {
                foreach (['__personal', '__crisis'] as $code) {
                    $buckets[$code] = ['count' => 0, 'contributors' => 0, 'complement_contributors' => 20];
                }
            }
            return $value + ['buckets' => $buckets];
        };
        return BroadDisclosurePolicy::release($analysis, $aggregate($current, $currentContributors),
            $aggregate($previous, $previousContributors), $month, $this->previous($month));
    }

    private function bmg(string $analysis = 'bmg.batches', string $month = '2026-09', array $current = [], array $previous = []): array
    {
        $defaults = $analysis === 'bmg.batches'
            ? ['batches_started' => 20, 'batches_released' => 12, 'batches_voided' => 0]
            : ['rotation_reports' => 20, 'rotation_failed' => 2, 'rotation_interrupted' => 0];
        return BroadDisclosurePolicy::release($analysis, ['counts' => array_replace($defaults, $current)],
            ['counts' => array_replace($defaults, $previous)], $month, $this->previous($month));
    }

    private function failure(string $reason, callable $action, int $status = 409): void
    {
        try {
            $action();
            $this->fail('Expected safe AI failure: ' . $reason);
        } catch (AiFailure $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($status, $e->getCode());
            $this->assertNull($e->getPrevious());
        }
    }

    private function enqueue(int $tenant, string $unit, array $payload, string $profile = 'r1'): array
    {
        $snapshot = $payload['schema_version'] === '1' && $unit !== 'clinic'
            ? $this->historicalLegacySnapshot($tenant, $unit, $payload)
            : $this->store->saveSnapshot($tenant, $unit, $payload);
        return $this->store->enqueue($tenant, $unit, $snapshot['id'], 10, $profile, 'synthetic/model', 100);
    }

    private function complete(int $tenant, string $unit, array $payload): array
    {
        $job = $this->enqueue($tenant, $unit, $payload);
        $claim = $this->store->claim($tenant, $unit, 'r1');
        $this->assertSame($job['id'], $claim['id']);
        $this->store->complete($tenant, $unit, $job['id'], ['summary' => 'Synthetic validated draft'], $claim['lease_token']);
        return $this->store->job($tenant, $unit, $job['id']);
    }

    private function dropSnapshotUnique(array $fields): void
    {
        foreach ($this->db->getIndexData('ai_snapshots') as $index) {
            if ($index->fields === $fields && strtoupper($index->type) === 'UNIQUE') {
                $this->assertTrue(Database::forge($this->db)->dropKey('ai_snapshots', $index->name, false));
                return;
            }
        }
        $this->fail('Expected snapshot unique key.');
    }

    /** Seed old storage-only scopes; new v1 publications must now be clinic-only. */
    private function historicalLegacySnapshot(int $tenant, string $unit, array $payload): array
    {
        $canonical = static function (array $value) use (&$canonical): array {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            return array_map(static fn (mixed $item): mixed => is_array($item) ? $canonical($item) : $item, $value);
        };
        $fingerprint = $this->cipher->fingerprint($tenant, $unit, 'snapshot', json_encode($canonical([
            'schema_version' => '1', 'disclosure_version' => '1', 'payload' => $payload,
        ]), JSON_THROW_ON_ERROR));
        $this->db->table('ai_snapshots')->insert([
            'tenant_id' => $tenant, 'unit' => $unit, 'period' => $payload['period']['label'],
            'schema_version' => '1', 'disclosure_version' => '1', 'fingerprint' => $fingerprint,
            'payload' => $this->cipher->encrypt($tenant, $unit, 'snapshot:' . $fingerprint, $payload),
            'created_at' => gmdate('Y-m-d H:i:s', $this->now), 'expires_at' => gmdate('Y-m-d H:i:s', $this->now + 86400),
        ]);
        return ['id' => (int) $this->db->insertID()];
    }

    private function publicationRows(): array
    {
        return [
            $this->db->table('ai_releases')->orderBy('id')->get()->getResultArray(),
            $this->db->table('ai_snapshots')->orderBy('id')->get()->getResultArray(),
        ];
    }

    public function testLegacyFoundationStillReadsHistoricalIsolationUnitsWithoutNewMetadata(): void
    {
        $this->assertTrue($this->store->ready());
        $this->assertFalse($this->store->supportsAnalysisScope());
        $job = $this->complete(1, 'guidance', $this->legacy());
        $this->assertArrayNotHasKey('analysis_code', $job);
        $this->assertArrayNotHasKey('analysis_code', $job['insight']);
        $this->assertCount(1, $this->store->listJobs(1, 'guidance', 'clinic.activity'));
        $this->assertCount(1, $this->store->listInsights(1, 'guidance', 'clinic.activity'));
        $this->assertSame([], $this->store->listJobs(1, 'guidance', 'guidance.activity'));
        $this->assertSame([], $this->store->listInsights(1, 'guidance', 'guidance.activity'));
    }

    public function testV2RequiresScopeButV1WorksBeforeUpgrade(): void
    {
        $this->failure('store_unavailable', fn () => $this->store->saveSnapshot(1, 'bmg', $this->bmg()), 503);
        $this->assertSame(0, $this->db->table('ai_releases')->countAllResults());
        $this->store->saveSnapshot(1, 'clinic', $this->legacy());
        $this->upgrade();
        $this->assertTrue($this->store->ready());
        $this->assertTrue($this->store->supportsAnalysisScope());
        $this->store->saveSnapshot(1, 'bmg', $this->bmg());
    }

    public function testUpgradePreservesCiphertextIdsHistoryAndIncomingForeignKeys(): void
    {
        $clinic = $this->complete(1, 'clinic', $this->legacy());
        $this->store->review(1, 'clinic', $clinic['insight']['id'], 11, 1, 'accept');
        $guidance = $this->enqueue(1, 'guidance', $this->legacy());
        $claim = $this->store->claim(1, 'guidance', 'r1');
        $this->store->reserveDispatch(1, 'guidance', $claim['id'], $claim['lease_token'], 10, 1);
        $this->db->query('CREATE TABLE scope_test_snapshot_references (id INTEGER PRIMARY KEY, snapshot_id BIGINT NOT NULL,
            FOREIGN KEY (snapshot_id) REFERENCES scope_test_ai_snapshots(id))');
        $this->db->table('snapshot_references')->insert(['id' => 17, 'snapshot_id' => $clinic['snapshot_id']]);
        $tables = ['ai_releases', 'ai_snapshots', 'ai_jobs', 'ai_dispatch_attempts', 'ai_insights', 'ai_reviews'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $this->db->table($table)->orderBy('id')->get()->getResultArray();
        }
        $this->upgrade();
        // Retrying installation after interrupted DDL must not rewrite any evidence.
        $this->upgrade();
        foreach ($tables as $table) {
            $after = $this->db->table($table)->orderBy('id')->get()->getResultArray();
            if ($table === 'ai_snapshots') {
                foreach ($after as &$row) {
                    $this->assertSame('clinic.activity', $row['analysis_code']);
                    unset($row['analysis_code']);
                }
                unset($row);
            }
            $this->assertSame($before[$table], $after, $table);
        }
        $this->assertSame([], $this->db->query('PRAGMA foreign_key_check')->getResultArray());
        $this->assertCount(1, $this->db->getForeignKeyData('snapshot_references'));
        $this->assertSame('clinic.activity', $this->store->job(1, 'guidance', $guidance['id'])['analysis_code']);
        $this->assertSame('accepted', $this->store->getInsight(1, 'clinic', $clinic['insight']['id'])['status']);
        foreach ($this->db->table('ai_snapshots')->get()->getResultArray() as $row) {
            $this->assertStringStartsWith('v1.', $row['payload']);
            $this->assertStringNotContainsString('completed_encounters', $row['payload']);
            $this->assertSame($this->legacy(), $this->cipher->decrypt(1, $row['unit'], 'snapshot:' . $row['fingerprint'], $row['payload']));
        }
        $next = $this->store->saveSnapshot(1, 'clinic', $this->health('clinic.complaints'));
        $this->assertGreaterThan($guidance['snapshot_id'], $next['id']);
    }

    public function testDepartmentAccessHasScopedUniqueGrantAndSeedsNothing(): void
    {
        $this->upgrade();
        $this->assertSame(['id', 'tenant_id', 'role', 'unit', 'permission', 'created_by', 'created_at'], $this->db->getFieldNames('ai_department_access'));
        $this->assertSame(0, $this->db->table('ai_department_access')->countAllResults());
        $grant = ['tenant_id' => 1, 'role' => 'staff', 'unit' => 'guidance', 'permission' => 'ai.insights.read',
            'created_by' => 11, 'created_at' => '2026-10-29 12:00:00'];
        $this->db->table('ai_department_access')->insert($grant);
        $this->db->table('ai_department_access')->ignore(true)->insert($grant);
        $this->assertSame(0, $this->db->affectedRows());
        $this->db->table('ai_department_access')->insert(array_replace($grant, ['tenant_id' => 2]));
        $this->db->table('ai_department_access')->insert(array_replace($grant, ['unit' => 'bmg']));
        $this->assertSame(3, $this->db->table('ai_department_access')->countAllResults());
    }

    public function testRollbackRefusesBeforeChangingAnything(): void
    {
        $this->upgrade();
        $snapshot = $this->store->saveSnapshot(1, 'bmg', $this->bmg());
        try {
            (new AddAiAnalysisScope(Database::forge($this->db)))->down();
            $this->fail('Rollback must require review.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('requires review', $e->getMessage());
        }
        $this->assertTrue($this->store->supportsAnalysisScope());
        $this->assertSame($snapshot, $this->store->saveSnapshot(1, 'bmg', $this->bmg()));
        $this->assertTrue($this->db->tableExists('ai_department_access'));
    }

    public function testReadinessRejectsColumnOnlyBothKeysAndMissingScopeUnique(): void
    {
        $this->db->query("ALTER TABLE scope_test_ai_snapshots ADD COLUMN analysis_code VARCHAR(64) NOT NULL DEFAULT 'clinic.activity'");
        unset($this->db->dataCache['field_names']['ai_snapshots']);
        $this->assertFalse($this->store->supportsAnalysisScope());
        $this->assertFalse($this->store->ready());
        $this->db->query('CREATE UNIQUE INDEX scope_test_new_scope ON scope_test_ai_snapshots (tenant_id, unit, analysis_code, period)');
        $this->assertFalse($this->store->supportsAnalysisScope());
        $this->assertFalse($this->store->ready());
        $this->failure('store_unavailable', fn () => $this->store->saveSnapshot(1, 'clinic', $this->legacy()), 503);
        $this->dropSnapshotUnique(['tenant_id', 'unit', 'period']);
        $this->assertTrue($this->store->supportsAnalysisScope());
        $this->assertTrue($this->store->ready());
        $this->dropSnapshotUnique(['tenant_id', 'unit', 'analysis_code', 'period']);
        $this->assertFalse($this->store->supportsAnalysisScope());
        $this->assertFalse($this->store->ready());
        $this->db->query('CREATE INDEX scope_test_not_unique ON scope_test_ai_snapshots (tenant_id, unit, analysis_code, period)');
        $this->db->query('CREATE UNIQUE INDEX scope_test_wrong_scope ON scope_test_ai_snapshots (tenant_id, unit, analysis_code, period, schema_version)');
        $this->assertFalse($this->store->supportsAnalysisScope());
        $this->assertFalse($this->store->ready());
    }

    public function testTwoAnalysesSharePeriodButCannotReinterpretExistingAnalysis(): void
    {
        $this->upgrade();
        $legacy = $this->store->saveSnapshot(1, 'clinic', $this->legacy());
        $complaints = $this->store->saveSnapshot(1, 'clinic', $this->health('clinic.complaints'));
        $this->assertNotSame($legacy['id'], $complaints['id']);
        $this->assertSame($complaints, $this->store->saveSnapshot(1, 'clinic', $this->health('clinic.complaints')));
        $this->assertSame(2, $this->db->table('ai_snapshots')->countAllResults());
        $changed = $this->legacy(current: 241);
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, 'clinic', $changed));
        // A profile/model change may create another job, never another release.
        $one = $this->store->enqueue(1, 'clinic', $legacy['id'], 10, 'r1', 'model-v1', 100);
        $two = $this->store->enqueue(1, 'clinic', $legacy['id'], 10, 'r2', 'model-v2', 100);
        $this->assertNotSame($one['id'], $two['id']);
        $this->assertSame($one['snapshot_id'], $two['snapshot_id']);
    }

    public function testSharedTotalsBlockCrossAnalysisCorrectionsInEitherDirection(): void
    {
        $this->upgrade();
        $this->store->saveSnapshot(1, 'clinic', $this->legacy());
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, 'clinic', $this->health('clinic.complaints', current: 241)));
        $this->store->saveSnapshot(2, 'clinic', $this->health('clinic.complaints'));
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(2, 'clinic', $this->legacy(current: 241)));
        $this->store->saveSnapshot(1, 'guidance', $this->health('guidance.activity'));
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, 'guidance', $this->health('guidance.themes', current: 241)));
        $this->store->saveSnapshot(2, 'guidance', $this->health('guidance.themes'));
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(2, 'guidance', $this->health('guidance.activity', current: 241)));
        $this->assertSame(4, $this->db->table('ai_snapshots')->countAllResults());
    }

    public function testEveryReleasedMetricAndDenominatorIsFrozenWithLegacyBytesOnly(): void
    {
        $this->upgrade();
        $payloads = [$this->health('clinic.complaints'), $this->health('guidance.activity'),
            $this->health('guidance.themes'), $this->bmg(), $this->bmg('bmg.rotations')];
        foreach ($payloads as $payload) {
            $unit = $payload['department'];
            $this->store->saveSnapshot(1, $unit, $payload);
            foreach ($payload['metrics'] as $metric) {
                foreach (['comparison_period' => 'previous', 'period' => 'current'] as $period => $side) {
                    $month = $payload[$period]['label'];
                    $row = $this->db->table('ai_releases')->where('tenant_id', 1)->where('unit', $unit)
                        ->where('metric_code', $metric['metric_code'])->where('month', $month)->get()->getRowArray();
                    $this->assertNotNull($row);
                    $this->assertSame($this->cipher->fingerprint(1, $unit, 'monthly_release',
                        json_encode([$metric['metric_code'], $month, $metric[$side . '_value']], JSON_THROW_ON_ERROR)), $row['fingerprint']);
                }
            }
        }
        $codes = array_column($this->db->table('ai_releases')->get()->getResultArray(), 'metric_code');
        foreach (['__other', '__missing', '__personal', '__crisis'] as $hidden) {
            $this->assertNotContains($hidden, $codes);
        }
        foreach (['clinic.complaints', 'guidance.themes'] as $analysis) {
            $suppressed = AnalysisCatalog::metrics($analysis)[3]['metric_code'];
            $this->assertNotContains($suppressed, $codes);
        }
        $this->assertSame(['id', 'tenant_id', 'unit', 'metric_code', 'month', 'fingerprint', 'created_at'], $this->db->getFieldNames('ai_releases'));
    }

    public static function categoryAnalyses(): iterable
    {
        yield 'clinic complaints' => ['clinic.complaints', 'disclosure_clinic_complaints'];
        yield 'guidance themes' => ['guidance.themes', 'disclosure_guidance_themes'];
    }

    #[DataProvider('categoryAnalyses')]
    public function testCategoryMasksFreezeOrderedCodesAndPolicyForBothMonthsAndSurvivePurge(string $analysis, string $code): void
    {
        $this->upgrade();
        $payload = $this->health($analysis, previous: 240);
        $unit = $payload['department'];
        $snapshot = $this->store->saveSnapshot(1, $unit, $payload);
        $before = $this->publicationRows();
        $this->assertSame($snapshot, $this->store->saveSnapshot(1, $unit, $payload));
        $this->assertSame($before, $this->publicationRows());
        $categories = array_column(array_slice($payload['metrics'], 1), 'metric_code');
        $markers = $this->db->table('ai_releases')->where('metric_code', $code)->orderBy('month')->get()->getResultArray();
        $this->assertSame(['2026-08', '2026-09'], array_column($markers, 'month'));
        $this->assertLessThanOrEqual(64, strlen($code));
        foreach ($markers as $marker) {
            $expected = $this->cipher->fingerprint(1, $unit, 'monthly_release',
                json_encode([$code, $marker['month'], ['1', $categories]], JSON_THROW_ON_ERROR));
            $this->assertSame($expected, $marker['fingerprint']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $marker['fingerprint']);
        }
        // Marker reservations precede all numeric freezes, oldest month first.
        $this->assertSame([$code, $code], array_column(array_slice($before[0], 0, 2), 'metric_code'));
        $this->now += 86400;
        $this->assertSame(1, $this->store->purgeExpired(1, $unit)['ai_snapshots']);
        $this->store->saveSnapshot(1, $unit, $this->health($analysis, '2026-10', 260, 240));
        $oldReleases = $this->db->table('ai_releases')->where('month <=', '2026-09')->orderBy('id')->get()->getResultArray();
        $this->assertSame($before[0], $oldReleases);
        // No overlap and another tenant may independently publish a different mask.
        $hidden = AnalysisCatalog::metrics($analysis)[2]['metric_code'];
        $different = $this->health($analysis, '2026-12', currentContributors: [$hidden => 9], previousContributors: [$hidden => 9]);
        $this->store->saveSnapshot(1, $unit, $different);
        $this->store->saveSnapshot(2, $unit, $this->health($analysis,
            currentContributors: [$hidden => 9], previousContributors: [$hidden => 9]));
    }

    #[DataProvider('categoryAnalyses')]
    public function testChangedCategorySubsetsFailWithIdenticalReleasedValuesAndRollback(string $analysis, string $code): void
    {
        $this->upgrade();
        $descriptors = AnalysisCatalog::metrics($analysis);
        $firstOnly = [$descriptors[2]['metric_code'] => 9];
        $secondOnly = [$descriptors[1]['metric_code'] => 9];
        $tenant = 0;
        foreach ([[[], $firstOnly], [[], $secondOnly], [$firstOnly, []], [$firstOnly, $secondOnly]] as [$initial, $changed]) {
            foreach (['2026-08', '2026-09', '2026-10'] as $month) {
                ++$tenant;
                $original = $this->health($analysis, current: 240, previous: 240,
                    currentContributors: $initial, previousContributors: $initial);
                $candidate = $this->health($analysis, $month, 240, 240, $changed, $changed);
                $this->assertNotSame(array_column($original['metrics'], 'metric_code'), array_column($candidate['metrics'], 'metric_code'));
                $originalMetrics = array_column($original['metrics'], null, 'metric_code');
                foreach ($candidate['metrics'] as $metric) {
                    if (isset($originalMetrics[$metric['metric_code']])) {
                        foreach (['current_value', 'previous_value', 'current_denominator', 'previous_denominator'] as $key) {
                            $this->assertSame($originalMetrics[$metric['metric_code']][$key], $metric[$key]);
                        }
                    }
                }
                $unit = $original['department'];
                $snapshot = $this->store->saveSnapshot($tenant, $unit, $original);
                $before = $this->publicationRows();
                $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot($tenant, $unit, $candidate));
                // Earlier windows reserve a new oldest-month marker before the
                // overlapping marker conflicts; no reservation may escape rollback.
                $this->assertSame($before, $this->publicationRows());
                $this->assertSame($snapshot, $this->store->saveSnapshot($tenant, $unit, $original));
            }
        }
    }

    #[DataProvider('categoryAnalyses')]
    public function testNonOverlappingPartnerCannotChangeTheSharedMonthsPairMask(string $analysis, string $code): void
    {
        $this->upgrade();
        $payload = $this->health($analysis, previous: 240);
        $unit = $payload['department'];
        $this->store->saveSnapshot(1, $unit, $payload);
        $hidden = AnalysisCatalog::metrics($analysis)[2]['metric_code'];
        // September is unchanged; only October loses eligibility. The emitted
        // pair mask still changes for September, so deliberately require review.
        $candidate = $this->health($analysis, '2026-10', 240, 240, [$hidden => 9]);
        $before = $this->publicationRows();
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, $unit, $candidate));
        $this->assertSame($before, $this->publicationRows());
        $this->now += 86400;
        $this->store->purgeExpired(1, $unit);
        $before = $this->publicationRows();
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, $unit, $candidate));
        $this->assertSame($before, $this->publicationRows());
    }

    #[DataProvider('categoryAnalyses')]
    public function testUnguardedCategoryHistoryFailsClosedEvenWhenOnlySuppressedRowsExist(string $analysis, string $code): void
    {
        $this->upgrade();
        $payload = $this->health($analysis);
        $unit = $payload['department'];
        $suppressed = AnalysisCatalog::metrics($analysis)[3]['metric_code'];
        $this->assertNotContains($suppressed, array_column($payload['metrics'], 'metric_code'));
        foreach (['2026-08', '2026-09'] as $i => $month) {
            $tenant = $i + 1;
            $this->db->table('ai_releases')->insert([
                'tenant_id' => $tenant, 'unit' => $unit, 'metric_code' => $suppressed, 'month' => $month,
                'fingerprint' => $this->cipher->fingerprint($tenant, $unit, 'monthly_release',
                    json_encode([$suppressed, $month, 5], JSON_THROW_ON_ERROR)),
                'created_at' => gmdate('Y-m-d H:i:s', $this->now),
            ]);
            $before = $this->publicationRows();
            $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot($tenant, $unit, $payload));
            $this->assertSame($before, $this->publicationRows());
        }
        $this->store->saveSnapshot(3, $unit, $payload);
    }

    #[DataProvider('categoryAnalyses')]
    public function testMissingHistoricalMarkerCannotBeBackfilledOnRetryOrAdjacentWindow(string $analysis, string $code): void
    {
        $this->upgrade();
        $payload = $this->health($analysis, previous: 240);
        $unit = $payload['department'];
        $this->store->saveSnapshot(1, $unit, $payload);
        $this->db->table('ai_releases')->where('metric_code', $code)->where('month', '2026-09')->delete();
        $this->now += 86400;
        $this->store->purgeExpired(1, $unit);
        $before = $this->publicationRows();
        foreach (['2026-09', '2026-10'] as $month) {
            $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, $unit,
                $this->health($analysis, $month, 240, 240)));
            $this->assertSame($before, $this->publicationRows());
        }
    }

    #[DataProvider('categoryAnalyses')]
    public function testChangedMaskPolicyVersionRequiresReview(string $analysis, string $code): void
    {
        $this->upgrade();
        $payload = $this->health($analysis);
        $unit = $payload['department'];
        $this->store->saveSnapshot(1, $unit, $payload);
        $fingerprint = $this->cipher->fingerprint(1, $unit, 'monthly_release', json_encode([
            $code, '2026-09', ['2', array_column(array_slice($payload['metrics'], 1), 'metric_code')],
        ], JSON_THROW_ON_ERROR));
        $this->db->table('ai_releases')->where('metric_code', $code)->where('month', '2026-09')->update(['fingerprint' => $fingerprint]);
        $before = $this->publicationRows();
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, $unit, $payload));
        $this->assertSame($before, $this->publicationRows());
    }

    public function testFailedNumericFreezeRollsBackNewMasksWithoutChangingLegacyEvidence(): void
    {
        $legacy = $this->store->saveSnapshot(1, 'clinic', $this->legacy());
        $before = $this->publicationRows();
        $this->upgrade();
        foreach (['comparison_period' => 'previous', 'period' => 'current'] as $period => $side) {
            $month = $this->legacy()[$period]['label'];
            $row = $this->db->table('ai_releases')->where('month', $month)->get()->getRowArray();
            $this->assertSame($this->cipher->fingerprint(1, 'clinic', 'monthly_release',
                json_encode(['completed_encounters', $month, $this->legacy()['metrics'][0][$side . '_value']], JSON_THROW_ON_ERROR)), $row['fingerprint']);
        }
        $scopedBefore = $this->publicationRows();
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, 'clinic',
            $this->health('clinic.complaints', current: 241)));
        $this->assertSame($scopedBefore, $this->publicationRows());
        $this->assertSame($before[0], $scopedBefore[0]);
        $this->assertSame($before[1][0]['payload'], $scopedBefore[1][0]['payload']);
        $this->assertSame($legacy, $this->store->saveSnapshot(1, 'clinic', $this->legacy()));
        // Totals alone are not unguarded category history.
        $this->store->saveSnapshot(1, 'clinic', $this->health('clinic.complaints'));
    }

    public function testV1RejectsNonClinicScopeBeforeAndAfterUpgradeWithoutChangingHistoricalRows(): void
    {
        $this->historicalLegacySnapshot(1, 'guidance', $this->legacy());
        foreach ([false, true] as $upgrade) {
            if ($upgrade) {
                $this->upgrade();
            }
            $before = $this->publicationRows();
            foreach (['guidance', 'bmg', 'research'] as $unit) {
                $this->failure('invalid_scope', fn () => $this->store->saveSnapshot(1, $unit, $this->legacy()), 422);
                $this->assertSame($before, $this->publicationRows());
            }
        }
    }

    public function testAllMetricFreezesSurvivePayloadPurgeAndRollbackFailedAdjacentReleases(): void
    {
        $this->upgrade();
        $this->store->saveSnapshot(1, 'bmg', $this->bmg());
        $this->store->saveSnapshot(1, 'bmg', $this->bmg('bmg.rotations'));
        $before = $this->db->table('ai_releases')->orderBy('id')->get()->getResultArray();
        $this->now += 86400;
        $this->assertSame(2, $this->store->purgeExpired(1, 'bmg')['ai_snapshots']);
        foreach (['batches_started' => 21, 'batches_released' => 13, 'batches_voided' => 1] as $code => $value) {
            $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, 'bmg',
                $this->bmg(month: '2026-10', previous: [$code => $value])));
        }
        foreach (['rotation_reports' => 21, 'rotation_failed' => 3, 'rotation_interrupted' => 1] as $code => $value) {
            $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, 'bmg',
                $this->bmg('bmg.rotations', '2026-10', previous: [$code => $value])));
        }
        $this->assertSame($before, $this->db->table('ai_releases')->orderBy('id')->get()->getResultArray());
        $this->store->saveSnapshot(1, 'bmg', $this->bmg(month: '2026-10'));
        $this->store->saveSnapshot(1, 'bmg', $this->bmg('bmg.rotations', '2026-10'));
    }

    public function testReleaseLocksAreOrderedByMetricThenOldestMonth(): void
    {
        $this->upgrade();
        $this->store->saveSnapshot(1, 'bmg', $this->bmg('bmg.rotations'));
        $rows = $this->db->table('ai_releases')->orderBy('id')->get()->getResultArray();
        $actual = array_map(static fn (array $row): array => [$row['metric_code'], $row['month']], $rows);
        $expected = $actual;
        sort($expected);
        $this->assertSame($expected, $actual);
        $this->assertCount(6, $actual);
    }

    public function testV2DepartmentAndRegistryMustMatchStorageScopeBeforeWrites(): void
    {
        $this->upgrade();
        $payload = $this->health('guidance.activity');
        $this->failure('invalid_scope', fn () => $this->store->saveSnapshot(1, 'clinic', $payload), 422);
        $payload['department'] = 'clinic';
        $this->failure('invalid_snapshot', fn () => $this->store->saveSnapshot(1, 'clinic', $payload), 422);
        $payload = $this->bmg();
        $payload['analysis_code'] = 'bmg.custom';
        $this->failure('invalid_snapshot', fn () => $this->store->saveSnapshot(1, 'bmg', $payload), 422);
        $this->failure('invalid_scope', fn () => $this->store->saveSnapshot(0, 'bmg', $this->bmg()), 422);
        $this->assertSame(0, $this->db->table('ai_releases')->countAllResults());
        $this->assertSame(0, $this->db->table('ai_snapshots')->countAllResults());
        foreach (['guidance', 'bmg', 'research'] as $unit) {
            $this->failure('invalid_scope', fn () => $this->store->saveSnapshot(1, $unit, $this->legacy()), 422);
        }
        $this->assertSame(0, $this->db->table('ai_releases')->countAllResults());
        $this->assertSame(0, $this->db->table('ai_snapshots')->countAllResults());
    }

    public function testMetadataFiltersClaimAndInsightUseSnapshotAnalysisWithoutLeakingStorageFields(): void
    {
        $this->upgrade();
        $activity = $this->complete(1, 'clinic', $this->legacy());
        $complaints = $this->complete(1, 'clinic', $this->health('clinic.complaints'));
        $this->assertSame('clinic.activity', $activity['analysis_code']);
        $this->assertSame('clinic.complaints', $complaints['analysis_code']);
        $this->assertSame('clinic.complaints', $complaints['insight']['analysis_code']);
        $this->assertCount(2, $this->store->listJobs(1, 'clinic'));
        $this->assertSame([$activity['id']], array_column($this->store->listJobs(1, 'clinic', 'clinic.activity'), 'id'));
        $this->assertSame([$complaints['insight']['id']], array_column($this->store->listInsights(1, 'clinic', 'clinic.complaints'), 'id'));
        $this->assertSame([], $this->store->listJobs(1, 'clinic', 'guidance.activity'));
        $this->assertSame([], $this->store->listInsights(2, 'clinic', 'clinic.activity'));
        foreach (['tenant_id', 'unit', 'payload', 'lease_token', 'active_key', 'budget_key', 'dedupe_key'] as $private) {
            $this->assertArrayNotHasKey($private, $this->store->listJobs(1, 'clinic')[0]);
            $this->assertArrayNotHasKey($private, $complaints['insight']);
        }
        $queued = $this->enqueue(1, 'bmg', $this->bmg());
        $this->assertSame('bmg.batches', $queued['analysis_code']);
        $this->assertTrue($this->enqueue(1, 'bmg', $this->bmg())['reused']);
        $claim = $this->store->claim(1, 'bmg', 'r1');
        $this->assertSame('bmg.batches', $claim['analysis_code']);
        $this->assertSame($this->bmg(), $claim['payload']);
    }

    public function testMetadataJoinCannotBorrowAnotherTenantOrUnitsSnapshot(): void
    {
        $this->upgrade();
        $job = $this->complete(1, 'clinic', $this->legacy());
        $otherTenant = $this->store->saveSnapshot(2, 'clinic', $this->legacy());
        $otherUnit = $this->store->saveSnapshot(1, 'guidance', $this->health('guidance.activity'));
        foreach ([$otherTenant['id'], $otherUnit['id']] as $wrongSnapshot) {
            $this->db->table('ai_jobs')->where('id', $job['id'])->update(['snapshot_id' => $wrongSnapshot]);
            $this->db->table('ai_insights')->where('id', $job['insight']['id'])->update(['snapshot_id' => $wrongSnapshot]);
            $this->assertSame([], $this->store->listJobs(1, 'clinic'));
            $this->assertSame([], $this->store->listInsights(1, 'clinic'));
            $this->failure('job_not_found', fn () => $this->store->job(1, 'clinic', $job['id']), 404);
            $this->failure('insight_not_found', fn () => $this->store->getInsight(1, 'clinic', $job['insight']['id']), 404);
        }
    }

    public function testClaimClearsOtherKnownUnitStaleOwnersButNotOtherTenantsUnknownUnitsOrQueuedJobs(): void
    {
        $guidance = $this->enqueue(1, 'guidance', $this->legacy());
        $this->store->claim(1, 'guidance', 'r1', 10);
        $otherTenant = $this->enqueue(2, 'guidance', $this->legacy());
        $this->store->claim(2, 'guidance', 'r1', 10);
        $unknown = $this->enqueue(1, 'research', $this->legacy(), 'r2');
        $this->store->claim(1, 'research', 'r2', 10);
        $queued = $this->enqueue(1, 'clinic', $this->legacy());
        $this->db->table('ai_jobs')->where('id', $queued['id'])->update(['expires_at' => '2026-10-29 11:00:00']);
        $bmg = $this->enqueue(1, 'bmg', $this->legacy());
        $this->assertNull($this->store->claim(1, 'bmg', 'r1', 10));
        $this->now += 11;
        $claim = $this->store->claim(1, 'bmg', 'r1', 10);
        $this->assertSame($bmg['id'], $claim['id']);
        $this->assertSame('outcome_unknown', $this->store->job(1, 'guidance', $guidance['id'])['status']);
        $stale = $this->db->table('ai_jobs')->where('id', $guidance['id'])->get()->getRowArray();
        foreach (['active_key', 'lease_token', 'lease_expires_at'] as $field) {
            $this->assertNull($stale[$field]);
        }
        $this->assertSame('running', $this->store->job(2, 'guidance', $otherTenant['id'])['status']);
        $this->assertSame('running', $this->store->job(1, 'research', $unknown['id'])['status']);
        $this->assertSame('queued', $this->store->job(1, 'clinic', $queued['id'])['status']);
    }
}

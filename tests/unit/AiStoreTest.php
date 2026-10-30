<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\AddAiAnalysisScope;
use App\Database\Migrations\CreateAiInsights;
use App\Services\Ai\AiFailure;
use App\Services\Ai\BroadDisclosurePolicy;
use App\Services\Ai\DisclosurePolicy;
use App\Services\Ai\InsightStore;
use App\Services\Ai\PayloadCipher;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/** Explicit disposable SQLite only: no database group, DatabaseTestTrait or migration runner. */
final class AiStoreTest extends TestCase
{
    private BaseConnection $db;
    private PayloadCipher $cipher;
    private InsightStore $store;
    private int $now;
    private array $hints = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('Disposable SQLite requires sqlite3.');
        }
        $this->now = strtotime('2026-10-28 12:00:00 UTC');
        $this->db = $this->connection();
        require_once APPPATH . 'Database/Migrations/2026-10-28-000001_CreateAiInsights.php';
        (new CreateAiInsights(Database::forge($this->db)))->up();
        $this->cipher = new PayloadCipher(base64_encode(str_repeat('k', 32)));
        $this->store = $this->storeFor($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
        parent::tearDown();
    }

    private function connection(string $database = ':memory:'): BaseConnection
    {
        return Database::connect([
            'DBDriver' => 'SQLite3', 'database' => $database, 'DBPrefix' => 'ai_test_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
    }

    private function storeFor(BaseConnection $db): InsightStore
    {
        return new InsightStore($db, $this->cipher, fn (): int => $this->now, notify: function (int $tenant, string $unit) use ($db): void {
            $this->assertSame(0, $db->transDepth, 'Hints must follow the independent commit');
            $this->hints[] = [$tenant, $unit];
        });
    }

    private function payload(string $period = '2026-09', int $current = 240, int $previous = 200): array
    {
        $comparison = (new \DateTimeImmutable($period . '-01', new \DateTimeZone('UTC')))->modify('-1 month')->format('Y-m');
        return DisclosurePolicy::release(['count' => $current, 'contributors' => 20, 'unlinked' => 0],
            ['count' => $previous, 'contributors' => 20, 'unlinked' => 0], $period, $comparison);
    }

    private function upgrade(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-10-29-000001_AddAiAnalysisScope.php';
        (new AddAiAnalysisScope(Database::forge($this->db)))->up();
    }

    private function guidancePayload(string $period = '2026-09', int $current = 240, int $previous = 200): array
    {
        $comparison = (new \DateTimeImmutable($period . '-01', new \DateTimeZone('UTC')))->modify('-1 month')->format('Y-m');
        return BroadDisclosurePolicy::release('guidance.activity',
            ['count' => $current, 'contributors' => 20, 'unlinked' => 0],
            ['count' => $previous, 'contributors' => 20, 'unlinked' => 0], $period, $comparison);
    }

    /** Recreate historical v1 isolation rows without allowing a new invalid save. */
    private function historicalGuidanceSnapshot(int $clinicSnapshotId): int
    {
        $row = $this->db->table('ai_snapshots')->where('id', $clinicSnapshotId)->get()->getRowArray();
        $this->assertNotNull($row);
        unset($row['id']);
        $payload = $this->payload();
        $canonical = static function (array $value) use (&$canonical): array {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            return array_map(static fn (mixed $item): mixed => is_array($item) ? $canonical($item) : $item, $value);
        };
        $tenant = (int) $row['tenant_id'];
        $row['unit'] = 'guidance';
        $row['fingerprint'] = $this->cipher->fingerprint($tenant, 'guidance', 'snapshot', json_encode($canonical([
            'schema_version' => '1', 'disclosure_version' => '1', 'payload' => $payload,
        ]), JSON_THROW_ON_ERROR));
        $row['payload'] = $this->cipher->encrypt($tenant, 'guidance', 'snapshot:' . $row['fingerprint'], $payload);
        $this->assertTrue($this->db->table('ai_snapshots')->insert($row));
        $id = (int) $this->db->insertID();
        foreach (['comparison_period' => 'previous', 'period' => 'current'] as $period => $side) {
            $month = $payload[$period]['label'];
            $metric = $payload['metrics'][0];
            $this->assertTrue($this->db->table('ai_releases')->insert([
                'tenant_id' => $tenant, 'unit' => 'guidance', 'metric_code' => $metric['metric_code'], 'month' => $month,
                'fingerprint' => $this->cipher->fingerprint($tenant, 'guidance', 'monthly_release',
                    json_encode([$metric['metric_code'], $month, $metric[$side . '_value']], JSON_THROW_ON_ERROR)),
                'created_at' => $row['created_at'],
            ]));
        }
        return $id;
    }

    private function enqueue(int $tenant = 1, string $unit = 'clinic', int $actor = 10, string $profile = 'r1', int $limit = 10, string $model = 'org/model'): array
    {
        $payload = $unit === 'guidance' ? $this->guidancePayload() : $this->payload();
        $snapshot = $this->store->saveSnapshot($tenant, $unit, $payload);
        return $this->store->enqueue($tenant, $unit, $snapshot['id'], $actor, $profile, $model, $limit);
    }

    private function completed(int $tenant = 1, string $unit = 'clinic'): array
    {
        $job = $this->enqueue($tenant, $unit);
        $claimed = $this->store->claim($tenant, $unit, 'r1');
        $this->assertSame($job['id'], $claimed['id']);
        $this->store->complete($tenant, $unit, $job['id'], ['summary' => 'Synthetic validated draft', 'evidence_ids' => ['m1']]);
        return $this->store->listInsights($tenant, $unit)[0];
    }

    private function failure(string $reason, callable $action, int $status = 409): void
    {
        try {
            $action();
            $this->fail('Expected safe AI failure: ' . $reason);
        } catch (AiFailure $e) {
            $this->assertSame($status, $e->getCode());
            $this->assertSame($reason, $e->reason);
            $this->assertNull($e->getPrevious());
        }
    }

    public function testRealtimeHintsFollowCommittedMutationsOnly(): void
    {
        $job = $this->enqueue(2);
        $this->assertSame([[2, 'clinic'], [2, 'clinic']], $this->hints);
        $this->hints = [];
        $this->store->claim(2, 'clinic', 'r1');
        $this->assertSame([[2, 'clinic']], $this->hints);
        $this->hints = [];
        $this->failure('lease_lost', fn () => $this->store->complete(2, 'clinic', $job['id'], ['secret' => 'must not publish'], 'wrong'));
        $this->assertSame([], $this->hints);
        $this->store->fail(2, 'clinic', $job['id'], 'invalid_output');
        $this->assertSame([[2, 'clinic']], $this->hints);
        $this->hints = [];
        $this->store->listJobs(2, 'clinic');
        $this->assertSame([], $this->hints, 'Reads must not publish');
    }

    public function testRealtimeCompletionReviewExpiryAndTransportFailure(): void
    {
        $insight = $this->completed();
        $this->assertCount(4, $this->hints, 'Prepare, enqueue, claim and completion');
        $this->hints = [];
        $this->store->review(1, 'clinic', $insight['id'], 10, 1, 'accept');
        $this->assertSame([[1, 'clinic']], $this->hints);
        $this->hints = [];
        $this->failure('review_conflict', fn () => $this->store->review(1, 'clinic', $insight['id'], 10, 1, 'reject'));
        $this->assertSame([], $this->hints);
        $store = new InsightStore($this->db, $this->cipher, fn (): int => $this->now, notify: static function (): void { throw new \RuntimeException('offline'); });
        $store->review(1, 'clinic', $insight['id'], 10, 2, 'approve');
        $this->assertSame('approved', $store->getInsight(1, 'clinic', $insight['id'])['status']);
        $this->now += 100 * 86400;
        $this->store->purgeExpired(1, 'clinic');
        $this->assertSame([[1, 'clinic']], $this->hints);
    }

    public function testRealtimeCancellationAndExpiredWorkerOutcome(): void
    {
        $job = $this->enqueue();
        $this->hints = [];
        $this->store->cancel(1, 'clinic', $job['id']);
        $this->assertSame([[1, 'clinic']], $this->hints);
        $job = $this->enqueue(profile: 'r2');
        $this->store->claim(1, 'clinic', 'r2', 90);
        $this->hints = [];
        $this->now += 91;
        $this->store->claim(1, 'clinic', 'r2');
        $this->assertSame([[1, 'clinic']], $this->hints);
        $this->assertSame('outcome_unknown', $this->store->job(1, 'clinic', $job['id'])['status']);
    }

    public function testCipherRandomizesAndAuthenticatesScopeKindKeyAndEnvelope(): void
    {
        $payload = ['safe' => 'aggregate', 'rate' => 1.0];
        $one = $this->cipher->encrypt(1, 'clinic', 'snapshot:abc', $payload);
        $two = $this->cipher->encrypt(1, 'clinic', 'snapshot:abc', $payload);
        $this->assertNotSame($one, $two);
        $this->assertStringNotContainsString('aggregate', $one);
        $this->assertSame($payload, $this->cipher->decrypt(1, 'clinic', 'snapshot:abc', $one));
        foreach ([[2, 'clinic', 'snapshot:abc'], [1, 'guidance', 'snapshot:abc'], [1, 'clinic', 'insight:abc']] as $scope) {
            $this->failure('payload_unavailable', fn () => $this->cipher->decrypt(...[...$scope, $one]), 503);
        }
        $bytes = base64_decode(substr($one, 3), true);
        $bytes[28] = chr(ord($bytes[28]) ^ 1);
        $this->failure('payload_unavailable', fn () => $this->cipher->decrypt(1, 'clinic', 'snapshot:abc', 'v1.' . base64_encode($bytes)), 503);
        $other = new PayloadCipher(base64_encode(str_repeat('x', 32)));
        $this->failure('payload_unavailable', fn () => $other->decrypt(1, 'clinic', 'snapshot:abc', $one), 503);
    }

    public function testMissingKeyAndMissingSchemaFailClosed(): void
    {
        foreach (['', 'not base64!', base64_encode('short'), base64_encode(str_repeat('k', 32)) . "\n"] as $key) {
            $cipher = new PayloadCipher($key);
            $this->assertFalse($cipher->ready());
            $this->assertFalse((new InsightStore($this->db, $cipher))->ready());
            $this->failure('encryption_unavailable', fn () => $cipher->encrypt(1, 'clinic', 'snapshot', []), 503);
            $this->failure('encryption_unavailable', fn () => $cipher->fingerprint(1, 'clinic', 'monthly_release', '240'), 503);
        }
        $empty = $this->connection();
        try {
            $store = $this->storeFor($empty);
            $this->assertFalse($store->ready());
            $this->failure('store_unavailable', fn () => $store->listInsights(1, 'clinic'), 503);
        } finally {
            $empty->close();
        }
        $this->assertTrue($this->store->ready());
        Database::forge($this->db)->dropTable('ai_reviews');
        $this->assertFalse($this->store->ready());
    }

    public function testFingerprintsAreKeyedDeterministicAndDomainSeparated(): void
    {
        $value = json_encode(['completed_encounters', '2026-09', 240], JSON_THROW_ON_ERROR);
        $fingerprint = $this->cipher->fingerprint(1, 'clinic', 'monthly_release', $value);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $fingerprint);
        $this->assertSame($fingerprint, $this->cipher->fingerprint(1, 'clinic', 'monthly_release', $value));
        $this->assertNotSame(hash('sha256', $value), $fingerprint);
        $this->assertNotSame(hash_hmac('sha256', $value, str_repeat('k', 32)), $fingerprint);
        foreach ([[2, 'clinic', 'monthly_release', $value], [1, 'guidance', 'monthly_release', $value],
            [1, 'clinic', 'snapshot', $value], [1, 'clinic', 'monthly_release', $value . ' ']] as $arguments) {
            $this->assertNotSame($fingerprint, $this->cipher->fingerprint(...$arguments));
        }
        $other = new PayloadCipher(base64_encode(str_repeat('x', 32)));
        $this->assertNotSame($fingerprint, $other->fingerprint(1, 'clinic', 'monthly_release', $value));
    }

    public function testSnapshotValidationPrecedesAnyPersistenceOrReadinessCheck(): void
    {
        $payload = $this->payload();
        $invalid = [];
        foreach (array_keys($payload) as $key) {
            $copy = $payload;
            unset($copy[$key]);
            $invalid[] = $copy;
        }
        foreach (['disclosure_version' => '1', 'approved_programs' => [], 'raw_count' => 240] as $key => $value) {
            $invalid[] = $payload + [$key => $value];
        }
        $copy = $payload;
        $copy['metrics'][0]['current_value'] = 241;
        $invalid[] = $copy;
        $copy = $payload;
        $copy['metrics'][0]['contributors'] = 20;
        $invalid[] = $copy;
        $copy = $payload;
        $copy['comparison_period']['label'] = '2026-07';
        $invalid[] = $copy;
        $copy = $payload;
        $copy['signals'] = [];
        $invalid[] = $copy;
        $copy = $payload;
        $copy['limitations'] = [];
        $invalid[] = $copy;
        $unready = new InsightStore($this->db, new PayloadCipher(''), null, 30);
        foreach ($invalid as $copy) {
            $this->failure('invalid_snapshot', fn () => $this->store->saveSnapshot(1, 'clinic', $copy), 422);
            $this->failure('invalid_snapshot', fn () => $unready->saveSnapshot(1, 'clinic', $copy), 422);
        }
        $this->assertSame(0, $this->db->table('ai_releases')->countAllResults());
        $this->assertSame(0, $this->db->table('ai_snapshots')->countAllResults());
    }

    public function testRetentionUsesStrictConfigOrExplicitFourthArgumentWithoutExtendingFreeze(): void
    {
        $config = config('Ai');
        $original = $config->retentionDays;
        try {
            $config->retentionDays = 7;
            $store = new InsightStore($this->db, $this->cipher, fn (): int => $this->now);
            $snapshot = $store->saveSnapshot(1, 'clinic', $this->payload());
            $this->assertSame('2026-11-04 12:00:00', $snapshot['expires_at']);
            $explicit = new InsightStore($this->db, $this->cipher, fn (): int => $this->now, 1);
            $this->assertSame('2026-10-29 12:00:00', $explicit->saveSnapshot(2, 'clinic', $this->payload())['expires_at']);
            $this->now += 86400;
            $longer = new InsightStore($this->db, $this->cipher, fn (): int => $this->now, 365);
            $this->assertSame($snapshot, $longer->saveSnapshot(1, 'clinic', $this->payload()));
            $this->failure('snapshot_expired', fn () => $longer->saveSnapshot(2, 'clinic', $this->payload()));
            foreach ([0, 366] as $days) {
                $this->failure('invalid_config', fn () => new InsightStore($this->db, $this->cipher, null, $days), 503);
            }
            foreach ([0, 366, '30', 30.0, true, null] as $days) {
                $config->retentionDays = $days;
                $this->failure('invalid_config', fn () => new InsightStore($this->db, $this->cipher), 503);
            }
            // An explicit, valid retention does not inherit malformed configuration.
            $this->assertTrue((new InsightStore($this->db, $this->cipher, null, 30))->ready());
        } finally {
            $config->retentionDays = $original;
        }
    }

    public function testReadinessRequiresDurableMonthlyReleaseUniqueness(): void
    {
        foreach ($this->db->getIndexData('ai_releases') as $index) {
            if ($index->fields === ['tenant_id', 'unit', 'metric_code', 'month']) {
                $this->db->query('DROP INDEX ' . $this->db->escapeIdentifiers($index->name));
                $this->assertFalse($this->store->ready());
                $this->failure('store_unavailable', fn () => $this->store->saveSnapshot(1, 'clinic', $this->payload()), 503);
                return;
            }
        }
        $this->fail('Missing monthly release unique index.');
    }

    public function testReadinessRequiresUniquenessNotJustTableNames(): void
    {
        $index = null;
        foreach ($this->db->getIndexData('ai_jobs') as $candidate) {
            if ($candidate->fields === ['active_key']) {
                $index = $candidate->name;
            }
        }
        $this->assertNotNull($index);
        $this->db->query('DROP INDEX ' . $this->db->escapeIdentifiers($index));
        $this->assertFalse($this->store->ready());
    }

    public function testSnapshotFreezeCanonicalReuseAndCorrectionRejection(): void
    {
        $payload = $this->payload();
        $first = $this->store->saveSnapshot(1, 'clinic', $payload);
        $this->assertSame('2026-11-27 12:00:00', $first['expires_at']);
        $this->assertSame($payload, $first['payload']);
        $reordered = array_reverse($payload, true);
        $reordered['period'] = array_reverse($payload['period'], true);
        $second = $this->store->saveSnapshot(1, 'clinic', $reordered);
        $this->assertSame($first, $second);
        $roundTripped = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame($first, $this->store->saveSnapshot(1, 'clinic', $roundTripped));
        $payload = $this->payload(current: 241);
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, 'clinic', $payload));
        $payload = $this->payload();
        $payload['disclosure_version'] = '2';
        $this->failure('invalid_snapshot', fn () => $this->store->saveSnapshot(1, 'clinic', $payload), 422);
        $this->assertSame(1, $this->db->table('ai_snapshots')->countAllResults());
        $stored = $this->db->table('ai_snapshots')->get()->getRowArray();
        $this->assertStringStartsWith('v1.', $stored['payload']);
        $this->assertStringNotContainsString('current_value', $stored['payload']);
        $this->assertNotSame($first['id'], $this->store->saveSnapshot(2, 'clinic', $this->payload())['id']);
    }

    public function testAdjacentPairsRejectCorrectedSeptemberButPermitUnchangedSeptember(): void
    {
        $this->upgrade();
        $this->store->saveSnapshot(1, 'clinic', $this->payload('2026-09', 240, 200));
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, 'clinic',
            $this->payload('2026-10', 300, 241)));
        $this->assertSame(1, $this->db->table('ai_snapshots')->countAllResults());
        $this->assertSame(2, $this->db->table('ai_releases')->countAllResults());
        $october = $this->store->saveSnapshot(1, 'clinic', $this->payload('2026-10', 300, 240));
        $this->assertSame(240, $october['payload']['metrics'][0]['previous_value']);
        $this->assertSame(3, $this->db->table('ai_releases')->countAllResults());
        // Scope, not the value itself, identifies a release.
        $this->store->saveSnapshot(2, 'clinic', $this->payload('2026-10', 300, 241));
        $this->store->saveSnapshot(1, 'guidance', $this->guidancePayload('2026-10', 300, 241));
    }

    public function testComparisonMonthFreezeAlsoRejectsBackfillAndRollsBackFirstReservation(): void
    {
        $this->store->saveSnapshot(1, 'clinic', $this->payload());
        $before = $this->db->table('ai_releases')->orderBy('id')->get()->getResultArray();
        // July is tentatively reserved first; the already released August conflicts.
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, 'clinic',
            $this->payload('2026-08', 201, 180)));
        $this->assertSame($before, $this->db->table('ai_releases')->orderBy('id')->get()->getResultArray());
        $this->assertSame(1, $this->db->table('ai_snapshots')->countAllResults());
        $this->store->saveSnapshot(1, 'clinic', $this->payload('2026-08', 200, 180));
        $this->assertSame(3, $this->db->table('ai_releases')->countAllResults());
    }

    public function testSnapshotInsertFailureRollsBackBothMonthlyReservations(): void
    {
        $this->db->query("CREATE TRIGGER ai_snapshot_failure BEFORE INSERT ON ai_test_ai_snapshots BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            $this->failure('store_unavailable', fn () => $this->store->saveSnapshot(1, 'clinic', $this->payload()), 503);
            $this->assertSame(0, $this->db->table('ai_snapshots')->countAllResults());
            $this->assertSame(0, $this->db->table('ai_releases')->countAllResults());
        } finally {
            $this->db->query('DROP TRIGGER ai_snapshot_failure');
        }
        $this->store->saveSnapshot(1, 'clinic', $this->payload());
        $this->assertSame(2, $this->db->table('ai_releases')->countAllResults());
    }

    public function testPurgeRetainsKeyedMonthlyFingerprintsAndBlocksAdjacentCorrections(): void
    {
        $store = new InsightStore($this->db, $this->cipher, fn (): int => $this->now, 1);
        $snapshot = $store->saveSnapshot(1, 'clinic', $this->payload());
        $releases = $this->db->table('ai_releases')->orderBy('month')->get()->getResultArray();
        $this->assertSame(['2026-08', '2026-09'], array_column($releases, 'month'));
        $this->assertSame(['id', 'tenant_id', 'unit', 'metric_code', 'month', 'fingerprint', 'created_at'], array_keys($releases[0]));
        foreach ($releases as $index => $release) {
            $value = $index === 0 ? 200 : 240;
            $this->assertSame($this->cipher->fingerprint(1, 'clinic', 'monthly_release',
                json_encode(['completed_encounters', $release['month'], $value], JSON_THROW_ON_ERROR)), $release['fingerprint']);
        }
        $this->now += 86400;
        $this->assertSame(1, $store->purgeExpired(1, 'clinic')['ai_snapshots']);
        $this->assertNull($this->db->table('ai_snapshots')->where('id', $snapshot['id'])->get()->getRowArray()['payload']);
        $this->assertSame($releases, $this->db->table('ai_releases')->orderBy('month')->get()->getResultArray());
        $this->failure('snapshot_changed_requires_review', fn () => $store->saveSnapshot(1, 'clinic', $this->payload(current: 241)));
        $this->failure('snapshot_changed_requires_review', fn () => $store->saveSnapshot(1, 'clinic', $this->payload(previous: 201)));
        $this->failure('snapshot_changed_requires_review', fn () => $store->saveSnapshot(1, 'clinic', $this->payload('2026-10', 300, 241)));
        $this->failure('snapshot_expired', fn () => $store->saveSnapshot(1, 'clinic', $this->payload()));
        $wrongKey = new InsightStore($this->db, new PayloadCipher(base64_encode(str_repeat('x', 32))), fn (): int => $this->now, 1);
        $this->failure('snapshot_changed_requires_review', fn () => $wrongKey->saveSnapshot(1, 'clinic', $this->payload('2026-10', 300, 240)));
        $this->assertSame($releases, $this->db->table('ai_releases')->orderBy('month')->get()->getResultArray());
        $store->saveSnapshot(1, 'clinic', $this->payload('2026-10', 300, 240));
        $this->assertSame(3, $this->db->table('ai_releases')->countAllResults());
    }

    public function testApprovedPayloadKeepsDisclosureMetadataOutOfModelInput(): void
    {
        $payload = $this->payload();

        $snapshot = $this->store->saveSnapshot(1, 'clinic', $payload);
        $this->assertSame($payload, $snapshot['payload']);
        $this->store->enqueue(1, 'clinic', $snapshot['id'], 10, 'r1', 'model', 10);
        $this->assertSame($payload, $this->store->claim(1, 'clinic', 'r1')['payload']);
        $row = $this->db->table('ai_snapshots')->get()->getRowArray();
        $this->assertSame('1', $row['disclosure_version']);
    }

    public function testEnqueueDedupesAcrossActorsBySnapshotProfileModelAndReservesBudgetAcrossUnits(): void
    {
        $this->upgrade();
        $one = $this->enqueue(limit: 2);
        $again = $this->enqueue(actor: 99, limit: 2);
        $this->assertFalse($one['reused']);
        $this->assertTrue($again['reused']);
        $this->assertSame($one['id'], $again['id']);
        $this->assertSame(10, $again['actor_id']);
        $this->assertNotSame($one['id'], $this->enqueue(unit: 'guidance', actor: 11, limit: 2)['id']);
        $this->failure('daily_budget_exceeded', fn () => $this->enqueue(actor: 12, profile: 'r2', limit: 2), 429);
        $this->assertFalse($this->enqueue(tenant: 2, limit: 2)['reused']);
        $this->assertSame(3, $this->db->table('ai_jobs')->countAllResults());
        $this->now += 86400;
        $this->assertTrue($this->enqueue(actor: 12, limit: 2)['reused']);
        $this->assertFalse($this->enqueue(actor: 12, profile: 'r2', limit: 2)['reused']);
        $this->assertFalse($this->enqueue(actor: 12, profile: 'r3', limit: 2)['reused']);
        $snapshot = $this->store->saveSnapshot(1, 'clinic', $this->payload());
        $this->failure('daily_budget_exceeded', fn () => $this->store->enqueue(1, 'clinic', $snapshot['id'], 12, 'r2', 'other/model', 2), 429);
    }

    public function testAllObjectReadsAndWritesAreTenantUnitScoped(): void
    {
        $job = $this->enqueue();
        $this->failure('job_not_found', fn () => $this->store->job(2, 'clinic', $job['id']), 404);
        $this->failure('job_not_found', fn () => $this->store->job(1, 'guidance', $job['id']), 404);
        $this->failure('snapshot_not_found', fn () => $this->store->enqueue(2, 'clinic', $job['snapshot_id'], 10, 'r1', 'model', 10), 404);
        $this->failure('snapshot_not_found', fn () => $this->store->enqueue(1, 'guidance', $job['snapshot_id'], 10, 'r1', 'model', 10), 404);
        $this->assertNull($this->store->claim(2, 'clinic', 'r1'));
        $this->assertNull($this->store->claim(1, 'guidance', 'r1'));
        $claimed = $this->store->claim(1, 'clinic', 'r1');
        $this->failure('job_not_found', fn () => $this->store->complete(2, 'clinic', $job['id'], [], $claimed['lease_token']), 404);
        $this->failure('job_not_found', fn () => $this->store->fail(1, 'guidance', $job['id'], 'provider_unavailable', false, $claimed['lease_token']), 404);
        $this->store->complete(1, 'clinic', $job['id'], ['summary' => 'Safe']);
        $insight = $this->store->listInsights(1, 'clinic')[0];
        $this->assertSame([], $this->store->listInsights(2, 'clinic'));
        $this->assertSame([], $this->store->listInsights(1, 'guidance'));
        $this->failure('insight_not_found', fn () => $this->store->review(2, 'clinic', $insight['id'], 20, 1, 'accept'), 404);
        $this->failure('insight_not_found', fn () => $this->store->review(1, 'guidance', $insight['id'], 20, 1, 'accept'), 404);
        $this->assertSame(0, $this->db->table('ai_reviews')->countAllResults());
    }

    public function testListJobsReturnsOnlyLatestTwentyScopedSanitizedMetadata(): void
    {
        $ids = [];
        for ($i = 0; $i < 22; ++$i) {
            $ids[] = $this->enqueue(limit: 100, model: 'model-' . $i)['id'];
        }
        $this->enqueue(tenant: 2);
        // Keep the original legacy-schema metadata contract, including old
        // nonclinic isolation history that cannot be newly published anymore.
        $historical = $this->historicalGuidanceSnapshot($this->store->job(1, 'clinic', $ids[0])['snapshot_id']);
        $this->store->enqueue(1, 'guidance', $historical, 10, 'r1', 'org/model', 100);
        $claim = $this->store->claim(1, 'clinic', 'r1');
        $this->assertSame($ids[0], $claim['id']);
        $rows = $this->store->listJobs(1, 'clinic');
        $this->assertSame(array_slice(array_reverse($ids), 0, 20), array_column($rows, 'id'));
        $keys = ['id', 'snapshot_id', 'actor_id', 'profile_revision', 'model', 'status', 'reason',
            'attempts', 'created_at', 'updated_at', 'expires_at'];
        foreach ($rows as $row) {
            $this->assertSame($keys, array_keys($row));
        }
        $this->assertCount(1, $this->store->listJobs(2, 'clinic'));
        $this->assertCount(1, $this->store->listJobs(1, 'guidance'));
        $this->assertSame([], $this->store->listJobs(3, 'clinic'));
        $this->failure('invalid_scope', fn () => $this->store->listJobs(0, 'clinic'), 422);
    }

    public function testGetInsightReturnsCurrentDecryptedVersionOnlyWithinScopeAndRetention(): void
    {
        $insight = $this->completed();
        $this->assertSame($insight, $this->store->getInsight(1, 'clinic', $insight['id']));
        $this->failure('insight_not_found', fn () => $this->store->getInsight(2, 'clinic', $insight['id']), 404);
        $this->failure('insight_not_found', fn () => $this->store->getInsight(1, 'guidance', $insight['id']), 404);
        $this->failure('insight_not_found', fn () => $this->store->getInsight(1, 'clinic', 999), 404);
        $this->store->review(1, 'clinic', $insight['id'], 20, 1, 'accept');
        $current = $this->store->getInsight(1, 'clinic', $insight['id']);
        $this->assertSame('accepted', $current['status']);
        $this->assertSame(2, $current['version']);
        $this->assertSame($insight['draft'], $current['draft']);
        $this->now += 90 * 86400;
        $this->failure('insight_not_found', fn () => $this->store->getInsight(1, 'clinic', $insight['id']), 404);
        $this->store->purgeExpired(1, 'clinic');
        $this->failure('insight_not_found', fn () => $this->store->getInsight(1, 'clinic', $insight['id']), 404);
    }

    public function testGetInsightNeverReturnsNullOrUnauthenticatedPayload(): void
    {
        $insight = $this->completed();
        $this->db->table('ai_insights')->where('id', $insight['id'])->update(['payload' => 'v1.invalid']);
        $this->failure('payload_unavailable', fn () => $this->store->getInsight(1, 'clinic', $insight['id']), 503);
        $this->db->table('ai_insights')->where('id', $insight['id'])->update(['payload' => null]);
        $this->failure('insight_not_found', fn () => $this->store->getInsight(1, 'clinic', $insight['id']), 404);
    }

    public function testCancelIsQueuedOnlyScopedAndRetainsDedupeAndBudget(): void
    {
        $job = $this->enqueue(limit: 1);
        $before = $this->db->table('ai_jobs')->where('id', $job['id'])->get()->getRowArray();
        $this->failure('job_not_found', fn () => $this->store->cancel(2, 'clinic', $job['id']), 404);
        $this->failure('job_not_found', fn () => $this->store->cancel(1, 'guidance', $job['id']), 404);
        $this->store->cancel(1, 'clinic', $job['id']);
        $row = $this->store->job(1, 'clinic', $job['id']);
        $this->assertSame('cancelled', $row['status']);
        $this->assertSame(0, $row['attempts']);
        $this->assertNull($this->store->claim(1, 'clinic', 'r1'));
        $this->failure('invalid_job', fn () => $this->store->cancel(1, 'clinic', $job['id']));
        $reused = $this->enqueue(actor: 99, limit: 1);
        $this->assertTrue($reused['reused']);
        $this->assertSame($job['id'], $reused['id']);
        $this->assertSame(10, $reused['actor_id']);
        $after = $this->db->table('ai_jobs')->where('id', $job['id'])->get()->getRowArray();
        $this->assertSame($before['budget_key'], $after['budget_key']);
        $this->assertSame($before['dedupe_key'], $after['dedupe_key']);
        $this->failure('daily_budget_exceeded', fn () => $this->enqueue(limit: 1, model: 'other/model'), 429);
    }

    public function testCancelCannotInterfereWithRunningOrTerminalLeases(): void
    {
        $job = $this->enqueue();
        $claim = $this->store->claim(1, 'clinic', 'r1');
        $this->failure('invalid_job', fn () => $this->store->cancel(1, 'clinic', $job['id']));
        $running = $this->db->table('ai_jobs')->where('id', $job['id'])->get()->getRowArray();
        $this->assertSame('running', $running['status']);
        $this->assertSame($claim['lease_token'], $running['lease_token']);
        $this->assertNotNull($running['active_key']);
        $this->store->complete(1, 'clinic', $job['id'], ['summary' => 'Safe'], $claim['lease_token']);
        $this->failure('invalid_job', fn () => $this->store->cancel(1, 'clinic', $job['id']));
        $this->assertSame('succeeded', $this->store->job(1, 'clinic', $job['id'])['status']);
        $expired = $this->enqueue(model: 'other/model');
        $this->now += 30 * 86400;
        $this->failure('invalid_job', fn () => $this->store->cancel(1, 'clinic', $expired['id']));
        $this->assertNull($this->store->claim(1, 'clinic', 'r1'));
        $this->assertSame('expired', $this->store->job(1, 'clinic', $expired['id'])['status']);
    }

    public function testClaimUsesOldestProfileAndOneTenantProfileLeaseAcrossUnits(): void
    {
        $this->upgrade();
        $one = $this->enqueue();
        $two = $this->enqueue(actor: 11, model: 'other/model');
        $otherUnit = $this->enqueue(unit: 'guidance');
        $otherTenant = $this->enqueue(tenant: 2);
        $otherProfile = $this->enqueue(profile: 'r2');
        $this->assertNull($this->store->claim(1, 'clinic', 'wrong-revision'));
        $claimed = $this->store->claim(1, 'clinic', 'r1');
        $this->assertSame($one['id'], $claimed['id']);
        $this->assertSame(10, $claimed['actor_id']);
        $this->assertSame($this->payload(), $claimed['payload']);
        $secondWorker = $this->storeFor($this->db);
        $this->assertNull($secondWorker->claim(1, 'clinic', 'r1'));
        $this->assertNull($secondWorker->claim(1, 'guidance', 'r1'));
        $this->assertSame($otherTenant['id'], $secondWorker->claim(2, 'clinic', 'r1')['id']);
        $this->assertSame($otherProfile['id'], $secondWorker->claim(1, 'clinic', 'r2')['id']);
        $this->store->fail(1, 'clinic', $one['id'], 'provider_unavailable');
        $this->assertSame($otherUnit['id'], $secondWorker->claim(1, 'guidance', 'r1')['id']);
        $this->assertSame('queued', $this->store->job(1, 'clinic', $two['id'])['status']);
    }

    public function testExpiredLeaseBecomesUnknownAndCannotReplayOrComplete(): void
    {
        $job = $this->enqueue();
        $claim = $this->store->claim(1, 'clinic', 'r1', 10);
        $this->now += 10;
        $this->failure('lease_lost', fn () => $this->store->complete(1, 'clinic', $job['id'], []));
        $this->assertNull($this->storeFor($this->db)->claim(1, 'clinic', 'r1'));
        $status = $this->store->job(1, 'clinic', $job['id']);
        $this->assertSame('outcome_unknown', $status['status']);
        $this->assertSame('outcome_unknown', $status['reason']);
        $this->assertSame(1, $status['attempts']);
        $this->assertTrue($this->enqueue()['reused']);
        $this->failure('lease_lost', fn () => $this->store->complete(1, 'clinic', $job['id'], [], $claim['lease_token']));
        $this->assertSame(0, $this->db->table('ai_insights')->countAllResults());
    }

    public function testCompletionRequiresOwnershipAndReturnsEncryptedDraftWithVersion(): void
    {
        $job = $this->enqueue();
        $claim = $this->store->claim(1, 'clinic', 'r1');
        $otherWorker = $this->storeFor($this->db);
        $this->failure('lease_lost', fn () => $otherWorker->complete(1, 'clinic', $job['id'], []));
        $this->failure('lease_lost', fn () => $otherWorker->complete(1, 'clinic', $job['id'], [], str_repeat('f', 64)));
        $draft = ['summary' => 'Synthetic validated text'];
        $otherWorker->complete(1, 'clinic', $job['id'], $draft, $claim['lease_token']);
        $result = $this->store->job(1, 'clinic', $job['id']);
        $this->assertSame('succeeded', $result['status']);
        $this->assertSame($draft, $result['draft']);
        $this->assertSame(1, $result['version']);
        $this->assertSame($draft, $this->store->listInsights(1, 'clinic')[0]['draft']);
        foreach (['payload', 'lease_token', 'active_key', 'budget_key', 'dedupe_key'] as $key) {
            $this->assertArrayNotHasKey($key, $result);
        }
        $stored = $this->db->table('ai_insights')->get()->getRowArray();
        $this->assertStringNotContainsString('Synthetic', $stored['payload']);
        $this->failure('lease_lost', fn () => $this->store->complete(1, 'clinic', $job['id'], $draft));
        $this->assertSame(1, $this->db->table('ai_insights')->countAllResults());
    }

    public function testFailuresUseFixedCodesAndNeverRetryAmbiguousOutcome(): void
    {
        $job = $this->enqueue();
        $this->store->claim(1, 'clinic', 'r1');
        $this->store->fail(1, 'clinic', $job['id'], 'Bearer secret provider body patient narrative');
        $row = $this->store->job(1, 'clinic', $job['id']);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('generation_failed', $row['reason']);
        $ambiguous = $this->enqueue(actor: 11, model: 'other/model');
        $this->store->claim(1, 'clinic', 'r1');
        $this->store->fail(1, 'clinic', $ambiguous['id'], 'provider_timeout', true);
        $this->assertSame('outcome_unknown', $this->store->job(1, 'clinic', $ambiguous['id'])['status']);
        $this->assertNull($this->store->claim(1, 'clinic', 'r1'));
    }

    public function testReviewIsVersionedAcceptedBeforeApprovedAndAppendOnly(): void
    {
        $insight = $this->completed();
        $id = $insight['id'];
        $this->failure('invalid_review_transition', fn () => $this->store->review(1, 'clinic', $id, 20, 1, 'approve'));
        $this->store->review(1, 'clinic', $id, 20, 1, 'accept');
        $this->failure('review_conflict', fn () => $this->store->review(1, 'clinic', $id, 21, 1, 'reject'));
        $this->store->review(1, 'clinic', $id, 21, 2, 'approve');
        $row = $this->store->listInsights(1, 'clinic')[0];
        $this->assertSame('approved', $row['status']);
        $this->assertSame(3, $row['version']);
        $reviews = $this->db->table('ai_reviews')->orderBy('id')->get()->getResultArray();
        $this->assertSame(['accept', 'approve'], array_column($reviews, 'decision'));
        $this->assertSame(['staff_accept', 'staff_approve'], array_column($reviews, 'reason'));
        $this->assertSame([2, 3], array_map('intval', array_column($reviews, 'version')));
        $this->failure('invalid_review_transition', fn () => $this->store->review(1, 'clinic', $id, 20, 3, 'reject'));
        $this->failure('invalid_review', fn () => $this->store->review(1, 'clinic', $id, 20, 3, 'raw note'), 422);
    }

    public function testRejectRemainsRestrictedAndCannotBeApproved(): void
    {
        $insight = $this->completed();
        $this->store->review(1, 'clinic', $insight['id'], 20, 1, 'reject');
        $this->assertSame('rejected', $this->store->listInsights(1, 'clinic')[0]['status']);
        $this->failure('invalid_review_transition', fn () => $this->store->review(1, 'clinic', $insight['id'], 20, 2, 'approve'));
    }

    public function testAuditInsertFailureRollsBackReviewAndConnectionRecovers(): void
    {
        $insight = $this->completed();
        $this->db->query("CREATE TRIGGER ai_review_failure BEFORE INSERT ON ai_test_ai_reviews BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            $this->failure('store_unavailable', fn () => $this->store->review(1, 'clinic', $insight['id'], 20, 1, 'accept'), 503);
            $this->assertSame('draft', $this->store->listInsights(1, 'clinic')[0]['status']);
            $this->assertSame(1, $this->store->listInsights(1, 'clinic')[0]['version']);
            $this->assertSame(0, $this->db->table('ai_reviews')->countAllResults());
            $this->assertSame(0, $this->db->transDepth);
        } finally {
            $this->db->query('DROP TRIGGER ai_review_failure');
        }
        $this->store->review(1, 'clinic', $insight['id'], 20, 1, 'accept');
        $this->assertSame(1, $this->db->table('ai_reviews')->countAllResults());
    }

    public function testInsightInsertFailureRollsBackSuccessStatus(): void
    {
        $job = $this->enqueue();
        $this->store->claim(1, 'clinic', 'r1');
        $this->db->query("CREATE TRIGGER ai_insight_failure BEFORE INSERT ON ai_test_ai_insights BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            $this->failure('store_unavailable', fn () => $this->store->complete(1, 'clinic', $job['id'], ['summary' => 'safe']), 503);
            $this->assertSame('running', $this->store->job(1, 'clinic', $job['id'])['status']);
            $this->assertSame(0, $this->db->table('ai_insights')->countAllResults());
        } finally {
            $this->db->query('DROP TRIGGER ai_insight_failure');
        }
        $this->store->complete(1, 'clinic', $job['id'], ['summary' => 'safe']);
        $this->assertSame('succeeded', $this->store->job(1, 'clinic', $job['id'])['status']);
    }

    public function testSnapshotExpiryBlocksClaimAndPurgeRetainsFreezeAndAudit(): void
    {
        $insight = $this->completed();
        $this->store->review(1, 'clinic', $insight['id'], 20, 1, 'accept');
        $job = $this->enqueue(actor: 11, model: 'other/model');
        $this->enqueue(tenant: 2);
        $this->now += 30 * 86400;
        $this->assertNull($this->store->claim(1, 'clinic', 'r1'));
        $this->assertSame('expired', $this->store->job(1, 'clinic', $job['id'])['status']);
        $this->failure('snapshot_expired', fn () => $this->store->saveSnapshot(1, 'clinic', $this->payload()));
        $counts = $this->store->purgeExpired(1, 'clinic');
        $this->assertSame(['ai_snapshots' => 1, 'ai_insights' => 0], $counts);
        $this->assertNull($this->db->table('ai_snapshots')->where('tenant_id', 1)->get()->getRowArray()['payload']);
        $this->assertNotNull($this->db->table('ai_snapshots')->where('tenant_id', 2)->get()->getRowArray()['payload']);
        $corrected = $this->payload(current: 241);
        $this->failure('snapshot_changed_requires_review', fn () => $this->store->saveSnapshot(1, 'clinic', $corrected));
        $this->now += 60 * 86400;
        $this->assertSame(1, $this->store->purgeExpired(1, 'clinic')['ai_insights']);
        $this->assertSame([], $this->store->listInsights(1, 'clinic'));
        $this->assertArrayNotHasKey('draft', $this->store->job(1, 'clinic', $insight['job_id']));
        $this->assertSame(1, $this->db->table('ai_reviews')->countAllResults());
    }

    public function testListingIsNewestTwentyAndPurgeIsBounded(): void
    {
        for ($i = 0; $i < 22; ++$i) {
            $job = $this->enqueue(actor: 100 + $i, limit: 100, model: 'org/model-' . $i);
            $this->store->claim(1, 'clinic', 'r1');
            $this->store->complete(1, 'clinic', $job['id'], ['summary' => 'safe ' . $i]);
        }
        $rows = $this->store->listInsights(1, 'clinic');
        $this->assertCount(20, $rows);
        $this->assertSame('safe 21', $rows[0]['draft']['summary']);
        $this->assertSame('safe 2', $rows[19]['draft']['summary']);
        $template = $this->db->table('ai_insights')->get()->getRowArray();
        unset($template['id']);
        for ($i = 0; $i < 80; ++$i) {
            $this->db->table('ai_insights')->insert(array_replace($template, ['job_id' => 1000 + $i]));
        }
        $this->now += 90 * 86400;
        $this->assertSame(100, $this->store->purgeExpired(1, 'clinic')['ai_insights']);
        $this->assertSame(2, $this->store->purgeExpired(1, 'clinic')['ai_insights']);
    }

    public function testIndependentConnectionsShareDurableDedupeBudgetAndLeaseMutex(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'humus_ai_');
        $this->assertIsString($path);
        $one = $this->connection($path);
        $two = $this->connection($path);
        try {
            (new CreateAiInsights(Database::forge($one)))->up();
            $a = $this->storeFor($one);
            $b = $this->storeFor($two);
            $snapshot = $a->saveSnapshot(1, 'clinic', $this->payload());
            $this->assertSame($snapshot, $b->saveSnapshot(1, 'clinic', $this->payload()));
            $job = $a->enqueue(1, 'clinic', $snapshot['id'], 10, 'r1', 'model', 2);
            $this->assertTrue($b->enqueue(1, 'clinic', $snapshot['id'], 99, 'r1', 'model', 2)['reused']);
            $b->enqueue(1, 'clinic', $snapshot['id'], 11, 'r1', 'other/model', 2);
            $this->failure('daily_budget_exceeded', fn () => $a->enqueue(1, 'clinic', $snapshot['id'], 12, 'r1', 'third/model', 2), 429);
            $claimed = $a->claim(1, 'clinic', 'r1');
            $this->assertSame($job['id'], $claimed['id']);
            $this->assertNull($b->claim(1, 'clinic', 'r1'));
            $b->complete(1, 'clinic', $job['id'], ['summary' => 'Safe'], $claimed['lease_token']);
            $this->assertNotNull($a->claim(1, 'clinic', 'r1'));
            $this->assertSame(1, $one->table('ai_insights')->countAllResults());
        } finally {
            $two->close();
            $one->close();
            unlink($path);
        }
    }

    public function testOverlappingProcessesCannotDoubleFreezeReserveBudgetOrClaim(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('Overlapping disposable SQLite workers require proc_open.');
        }
        $path = tempnam(sys_get_temp_dir(), 'humus_ai_race_');
        $this->assertIsString($path);
        $db = $this->connection($path);
        try {
            (new CreateAiInsights(Database::forge($db)))->up();
            $store = $this->storeFor($db);
            $snapshot = $store->saveSnapshot(1, 'clinic', $this->payload());
            $same = $this->race($db, $path, 'enqueue', [10, 11], $snapshot['id']);
            $this->assertArrayHasKey('id', $same[0], json_encode($same));
            $this->assertArrayHasKey('id', $same[1], json_encode($same));
            $this->assertSame($same[0]['id'], $same[1]['id']);
            $reused = array_column($same, 'reused');
            sort($reused);
            $this->assertSame([false, true], $reused);
            $this->assertSame(1, $db->table('ai_snapshots')->countAllResults());
            $this->now += 86400;
            $budget = $this->race($db, $path, 'budget', [20, 21], $snapshot['id']);
            $this->assertCount(1, array_filter($budget, static fn (array $row): bool => isset($row['id'])));
            $this->assertCount(1, array_filter($budget, static fn (array $row): bool => ($row['error_status'] ?? 0) === 429));
            $this->assertSame(2, $db->table('ai_jobs')->countAllResults());
            $claims = $this->race($db, $path, 'claim', [0, 0], $snapshot['id']);
            $this->assertCount(1, array_filter($claims, static fn (array $row): bool => isset($row['id'])));
            $this->assertCount(1, array_filter($claims, static fn (array $row): bool => $row === ['empty' => true]));
        } finally {
            $db->close();
            unlink($path);
        }
    }

    public function testRacingAdjacentSnapshotPairsFreezeBothMonthsAtomically(): void
    {
        foreach ([240, 241] as $september) {
            $path = tempnam(sys_get_temp_dir(), 'humus_ai_month_race_');
            $this->assertIsString($path);
            $db = $this->connection($path);
            try {
                (new CreateAiInsights(Database::forge($db)))->up();
                $payloads = [$this->payload('2026-09', 240, 200), $this->payload('2026-10', 300, $september)];
                $results = $this->race($db, $path, 'freeze', [0, 0], 0, $payloads);
                $successes = array_filter($results, static fn (array $row): bool => isset($row['id']));
                if ($september === 240) {
                    $this->assertCount(2, $successes, json_encode($results));
                    $this->assertSame(2, $db->table('ai_snapshots')->countAllResults());
                    $this->assertSame(3, $db->table('ai_releases')->countAllResults());
                } else {
                    $this->assertCount(1, $successes, json_encode($results));
                    $conflicts = array_filter($results, static fn (array $row): bool =>
                        ($row['reason'] ?? '') === 'snapshot_changed_requires_review' && $row['error_status'] === 409);
                    $this->assertCount(1, $conflicts, json_encode($results));
                    $this->assertSame(1, $db->table('ai_snapshots')->countAllResults());
                    $this->assertSame(2, $db->table('ai_releases')->countAllResults());
                    // Whichever pair won owns September; the losing pair leaked no
                    // reservation for its other month, and can only use the winner's count.
                    $releasedSeptember = isset($results[0]['id']) ? 240 : 241;
                    $store = $this->storeFor($db);
                    $store->saveSnapshot(1, 'clinic', $this->payload('2026-09', $releasedSeptember, 200));
                    $store->saveSnapshot(1, 'clinic', $this->payload('2026-10', 300, $releasedSeptember));
                    $this->assertSame(2, $db->table('ai_snapshots')->countAllResults());
                    $this->assertSame(3, $db->table('ai_releases')->countAllResults());
                }
            } finally {
                $db->close();
                unlink($path);
            }
        }
    }

    public function testOverlappingDispatchAttemptsCannotDoubleReserveJobOrTenantDay(): void
    {
        foreach ([true, false] as $sameJob) {
            $path = tempnam(sys_get_temp_dir(), 'humus_ai_dispatch_race_');
            $this->assertIsString($path);
            $db = $this->connection($path);
            try {
                (new CreateAiInsights(Database::forge($db)))->up();
                $store = $this->storeFor($db);
                $snapshot = $store->saveSnapshot(1, 'clinic', $this->payload());
                $one = $store->enqueue(1, 'clinic', $snapshot['id'], 10, 'r1', 'model', 10);
                $two = $store->enqueue(1, 'clinic', $snapshot['id'], 11, 'r2', 'model', 10);
                $this->now += 86400;
                $firstClaim = $store->claim(1, 'clinic', 'r1');
                $secondClaim = $store->claim(1, 'clinic', 'r2');
                $first = ['id' => $one['id'], 'token' => $firstClaim['lease_token']];
                $second = $sameJob ? $first : ['id' => $two['id'], 'token' => $secondClaim['lease_token']];
                $results = $this->race($db, $path, 'dispatch', [0, 0], $snapshot['id'], [$first, $second]);
                $this->assertCount(1, array_filter($results, static fn (array $row): bool => $row === ['reserved' => true]), json_encode($results));
                $reason = $sameJob ? 'invalid_job' : 'daily_budget_exceeded';
                $status = $sameJob ? 409 : 429;
                $this->assertCount(1, array_filter($results, static fn (array $row): bool =>
                    ($row['reason'] ?? '') === $reason && $row['error_status'] === $status), json_encode($results));
                $rows = $db->table('ai_dispatch_attempts')->get()->getResultArray();
                $this->assertCount(1, $rows);
                $this->assertSame(gmdate('Y-m-d', $this->now), $rows[0]['day']);
                $this->assertSame(1, (int) $rows[0]['slot']);
                $this->assertSame('running', $store->job(1, 'clinic', $one['id'])['status']);
                $this->assertSame('running', $store->job(1, 'clinic', $two['id'])['status']);
            } finally {
                $db->close();
                unlink($path);
            }
        }
    }

    private function race(BaseConnection $db, string $path, string $mode, array $actors, int $snapshotId, ?array $payloads = null): array
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('Overlapping disposable SQLite workers require proc_open.');
        }
        $script = <<<'PHP'
require $argv[1];
$db = \Config\Database::connect(['DBDriver' => 'SQLite3', 'database' => $argv[2],
    'DBPrefix' => 'ai_test_', 'DBDebug' => true, 'busyTimeout' => 5000], false);
$db->initialize();
$now = (int) $argv[4];
$store = new \App\Services\Ai\InsightStore($db,
    new \App\Services\Ai\PayloadCipher(base64_encode(str_repeat('k', 32))), static fn (): int => $now, 30);
echo "ready\n";
fflush(STDOUT);
fgets(STDIN);
try {
    if (in_array($argv[3], ['enqueue', 'budget', 'freeze'], true)) {
        $payload = json_decode(base64_decode($argv[7]), true, 64, JSON_THROW_ON_ERROR);
        $snapshot = $store->saveSnapshot(1, 'clinic', $payload);
        if ($argv[3] === 'freeze') {
            $result = ['id' => $snapshot['id']];
        } else {
            $model = $argv[3] === 'budget' ? 'model-' . $argv[5] : 'model';
            $job = $store->enqueue(1, 'clinic', $snapshot['id'], (int) $argv[5], 'r1', $model, 1);
            $result = ['id' => $job['id'], 'reused' => $job['reused']];
        }
    } elseif ($argv[3] === 'dispatch') {
        $attempt = json_decode(base64_decode($argv[7]), true, 64, JSON_THROW_ON_ERROR);
        $store->assertLease(1, 'clinic', $attempt['id'], $attempt['token'], 60);
        $store->reserveDispatch(1, 'clinic', $attempt['id'], $attempt['token'], 1, 60);
        $result = ['reserved' => true];
    } else {
        $job = $store->claim(1, 'clinic', 'r1');
        $result = $job === null ? ['empty' => true] : ['id' => $job['id']];
    }
} catch (\App\Services\Ai\AiFailure $e) {
    $result = ['error_status' => $e->getCode(), 'reason' => $e->reason];
}
echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
$db->close();
PHP;
        $workers = [];
        try {
            foreach ($actors as $index => $actor) {
                $process = proc_open([PHP_BINARY, '-r', $script, TESTPATH . '_support/fresh-framework-bootstrap.php',
                    $path, $mode, (string) $this->now, (string) $actor, (string) $snapshotId,
                    base64_encode(json_encode($payloads[$index] ?? $this->payload(), JSON_THROW_ON_ERROR))],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
                $this->assertIsResource($process);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
                stream_set_timeout($pipes[1], 10);
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            // Hold the scratch database writer so both workers overlap at their first write.
            $db->transBegin();
            $db->table('ai_snapshots')->where('id', $snapshotId)->update(['id' => $snapshotId]);
            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], "go\n");
                fflush($worker['pipes'][0]);
            }
            usleep(100000);
            $db->transCommit();
            $results = [];
            foreach ($workers as $worker) {
                $line = fgets($worker['pipes'][1]);
                $this->assertIsString($line);
                $results[] = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            foreach ($workers as $worker) {
                proc_terminate($worker['process']);
                foreach ($worker['pipes'] as $pipe) {
                    fclose($pipe);
                }
                proc_close($worker['process']);
            }
        }
    }

    public function testModelIdsAreOpaqueUtf8WithAStrictByteLimit(): void
    {
        foreach (['vendor/model@2026+review', '模型/版本@β+1', 'vendor/model?version=1&mode=fast',
            str_repeat('a', 191), str_repeat('é', 95) . 'x'] as $model) {
            $job = $this->enqueue(limit: 100, model: $model);
            $this->assertSame($model, $job['model']);
            $this->assertSame($model, $this->store->job(1, 'clinic', $job['id'])['model']);
        }
        foreach (['', str_repeat('a', 192), str_repeat('é', 96), 'has space', "tab\tmodel", "line\nmodel",
            "control\0model", "delete\x7f", "bad\xc3\x28", "nonbreaking\u{00a0}space", "line\u{2028}separator",
            "control\u{0085}", "zero\u{200b}width"] as $model) {
            $this->failure('invalid_job', fn () => $this->enqueue(limit: 100, model: $model), 422);
        }
        $this->assertSame(5, $this->db->table('ai_jobs')->countAllResults());
    }

    public function testAssertLeaseChecksRemainingTimeAndNeverMutatesOrReserves(): void
    {
        $job = $this->enqueue();
        $claim = $this->store->claim(1, 'clinic', 'r1', 90);
        $before = $this->db->table('ai_jobs')->get()->getResultArray();
        $snapshotBefore = $this->db->table('ai_snapshots')->get()->getResultArray();
        $this->store->assertLease(1, 'clinic', $job['id'], $claim['lease_token']);
        $this->store->assertLease(1, 'clinic', $job['id'], $claim['lease_token'], 90);
        $this->failure('lease_lost', fn () => $this->store->assertLease(1, 'clinic', $job['id'], ''));
        $this->failure('lease_lost', fn () => $this->store->assertLease(1, 'clinic', $job['id'], str_repeat('x', 64)));
        $this->failure('job_not_found', fn () => $this->store->assertLease(2, 'clinic', $job['id'], $claim['lease_token']), 404);
        $this->failure('job_not_found', fn () => $this->store->assertLease(1, 'guidance', $job['id'], $claim['lease_token']), 404);
        foreach ([0, -1, 901] as $seconds) {
            $this->failure('invalid_lease', fn () => $this->store->assertLease(1, 'clinic', $job['id'], $claim['lease_token'], $seconds), 422);
        }
        $this->now++;
        $this->failure('lease_lost', fn () => $this->store->assertLease(1, 'clinic', $job['id'], $claim['lease_token'], 90));
        $this->store->assertLease(1, 'clinic', $job['id'], $claim['lease_token'], 89);
        $this->now += 89;
        $this->failure('lease_lost', fn () => $this->store->assertLease(1, 'clinic', $job['id'], $claim['lease_token']));
        $this->assertSame($before, $this->db->table('ai_jobs')->get()->getResultArray());
        $this->assertSame($snapshotBefore, $this->db->table('ai_snapshots')->get()->getResultArray());
        $this->assertSame(0, $this->db->table('ai_dispatch_attempts')->countAllResults());
    }

    public function testDispatchBudgetUsesOutboundUtcDayAcrossOldJobsProfilesAndUnits(): void
    {
        $this->upgrade();
        $this->now = strtotime('2026-10-28 23:59:50 UTC');
        $old = $this->enqueue(profile: 'r1');
        $oldChangedProfile = $this->enqueue(profile: 'r2');
        $this->now += 20;
        $today = $this->enqueue(profile: 'r3');
        $otherUnit = $this->enqueue(unit: 'guidance', profile: 'r4');
        $otherTenant = $this->enqueue(tenant: 2);
        $oldClaim = $this->store->claim(1, 'clinic', 'r1');
        $changedClaim = $this->store->claim(1, 'clinic', 'r2');
        $todayClaim = $this->store->claim(1, 'clinic', 'r3');
        $unitClaim = $this->store->claim(1, 'guidance', 'r4');
        $tenantClaim = $this->store->claim(2, 'clinic', 'r1');
        $this->store->reserveDispatch(1, 'clinic', $old['id'], $oldClaim['lease_token'], 2, 60);
        $this->store->reserveDispatch(1, 'clinic', $oldChangedProfile['id'], $changedClaim['lease_token'], 2, 60);
        $this->failure('daily_budget_exceeded', fn () => $this->store->reserveDispatch(1, 'clinic', $today['id'], $todayClaim['lease_token'], 2, 60), 429);
        $this->failure('daily_budget_exceeded', fn () => $this->store->reserveDispatch(1, 'guidance', $otherUnit['id'], $unitClaim['lease_token'], 2, 60), 429);
        $this->store->reserveDispatch(2, 'clinic', $otherTenant['id'], $tenantClaim['lease_token'], 1, 60);
        $rows = $this->db->table('ai_dispatch_attempts')->where('tenant_id', 1)->orderBy('slot')->get()->getResultArray();
        $this->assertSame(['2026-10-29', '2026-10-29'], array_column($rows, 'day'));
        $this->assertSame([1, 2], array_map('intval', array_column($rows, 'slot')));
        $this->assertSame([$old['id'], $oldChangedProfile['id']], array_map('intval', array_column($rows, 'job_id')));
        $this->assertSame(['2026-10-29 00:00:10', '2026-10-29 00:00:10'], array_column($rows, 'reserved_at'));
        $this->assertSame(['id', 'tenant_id', 'unit', 'job_id', 'day', 'slot', 'reserved_at'], array_keys($rows[0]));
        $this->assertSame('2026-10-28', substr($old['created_at'], 0, 10));
        $this->assertSame('2026-10-29', substr($today['created_at'], 0, 10));
        $this->assertSame(3, $this->db->table('ai_dispatch_attempts')->countAllResults());
    }

    public function testNewUtcDayAllowsAnotherJobButNeverReplaysAnExistingReservation(): void
    {
        $this->now = strtotime('2026-10-28 23:59:59 UTC');
        $one = $this->enqueue(profile: 'r1');
        $two = $this->enqueue(profile: 'r2');
        $first = $this->store->claim(1, 'clinic', 'r1');
        $second = $this->store->claim(1, 'clinic', 'r2');
        $this->store->reserveDispatch(1, 'clinic', $one['id'], $first['lease_token'], 1, 60);
        $this->now++;
        $other = $this->storeFor($this->db);
        $other->assertLease(1, 'clinic', $one['id'], $first['lease_token'], 60);
        $this->failure('invalid_job', fn () => $other->reserveDispatch(1, 'clinic', $one['id'], $first['lease_token'], 1, 60));
        $other->reserveDispatch(1, 'clinic', $two['id'], $second['lease_token'], 1, 60);
        $rows = $this->db->table('ai_dispatch_attempts')->orderBy('id')->get()->getResultArray();
        $this->assertSame(['2026-10-28', '2026-10-29'], array_column($rows, 'day'));
        $this->assertSame([1, 1], array_map('intval', array_column($rows, 'slot')));
        $this->assertSame([$one['id'], $two['id']], array_map('intval', array_column($rows, 'job_id')));
    }

    public function testDispatchReservationIsOneShotAcrossInstancesAndRetainedAfterErrorsAndPurge(): void
    {
        foreach (['provider_unavailable', 'outcome_unknown'] as $index => $reason) {
            $profile = 'r' . $index;
            $job = $this->enqueue(profile: $profile);
            $claim = $this->store->claim(1, 'clinic', $profile);
            $this->store->assertLease(1, 'clinic', $job['id'], $claim['lease_token'], 60);
            $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 2, 60);
            $other = $this->storeFor($this->db);
            $this->failure('invalid_job', fn () => $other->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 100, 60));
            $this->store->fail(1, 'clinic', $job['id'], $reason, $reason === 'outcome_unknown', $claim['lease_token']);
            $this->failure('lease_lost', fn () => $other->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 100, 60));
        }
        $rows = $this->db->table('ai_dispatch_attempts')->orderBy('id')->get()->getResultArray();
        $this->assertCount(2, $rows);
        $another = $this->enqueue(profile: 'changed-again');
        $claim = $this->store->claim(1, 'clinic', 'changed-again');
        $this->failure('daily_budget_exceeded', fn () => $this->store->reserveDispatch(1, 'clinic', $another['id'], $claim['lease_token'], 2, 60), 429);
        $this->now += 90 * 86400;
        $this->store->purgeExpired(1, 'clinic');
        $this->assertSame($rows, $this->db->table('ai_dispatch_attempts')->orderBy('id')->get()->getResultArray());
    }

    public function testDispatchRevalidatesOwnershipSnapshotAndLeaseWithoutConsumingOnFailure(): void
    {
        $job = $this->enqueue();
        $this->failure('lease_lost', fn () => $this->store->reserveDispatch(1, 'clinic', $job['id'], 'queued', 1, 1));
        $claim = $this->store->claim(1, 'clinic', 'r1', 90);
        $this->failure('job_not_found', fn () => $this->store->reserveDispatch(2, 'clinic', $job['id'], $claim['lease_token'], 1, 1), 404);
        $this->failure('job_not_found', fn () => $this->store->reserveDispatch(1, 'guidance', $job['id'], $claim['lease_token'], 1, 1), 404);
        $this->failure('lease_lost', fn () => $this->store->reserveDispatch(1, 'clinic', $job['id'], '', 1, 1));
        $this->failure('lease_lost', fn () => $this->store->reserveDispatch(1, 'clinic', $job['id'], str_repeat('x', 64), 1, 1));
        foreach ([0, 10001] as $limit) {
            $this->failure('invalid_job', fn () => $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], $limit, 1), 422);
        }
        foreach ([0, 901] as $seconds) {
            $this->failure('invalid_lease', fn () => $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 1, $seconds), 422);
        }
        $this->now++;
        $this->failure('lease_lost', fn () => $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 1, 90));
        $snapshot = $this->db->table('ai_snapshots')->where('id', $job['snapshot_id'])->get()->getRowArray();
        foreach ([['expires_at' => gmdate('Y-m-d H:i:s', $this->now)], ['payload' => null]] as $change) {
            $this->db->table('ai_snapshots')->where('id', $job['snapshot_id'])->update($change);
            $this->failure('snapshot_expired', fn () => $this->store->assertLease(1, 'clinic', $job['id'], $claim['lease_token']));
            $this->failure('snapshot_expired', fn () => $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 1, 60));
            $this->db->table('ai_snapshots')->where('id', $job['snapshot_id'])->update([
                'expires_at' => $snapshot['expires_at'], 'payload' => $snapshot['payload'],
            ]);
        }
        $this->assertSame(0, $this->db->table('ai_dispatch_attempts')->countAllResults());
        $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 1, 89);
        $this->assertSame(1, $this->db->table('ai_dispatch_attempts')->countAllResults());
    }

    public function testDispatchRollsBackIfLeaseBudgetElapsesWhileReserving(): void
    {
        $job = $this->enqueue();
        $claim = $this->store->claim(1, 'clinic', 'r1', 90);
        $this->db->connID->createFunction('ai_dispatch_elapsed', function (): int {
            $this->now++;
            return 1;
        }, 0);
        $this->db->query('CREATE TRIGGER ai_dispatch_delay AFTER INSERT ON ai_test_ai_dispatch_attempts BEGIN SELECT ai_dispatch_elapsed(); END');
        try {
            $this->failure('lease_lost', fn () => $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 1, 90));
            $this->assertSame(0, $this->db->table('ai_dispatch_attempts')->countAllResults());
            $this->assertSame('running', $this->store->job(1, 'clinic', $job['id'])['status']);
        } finally {
            $this->db->query('DROP TRIGGER ai_dispatch_delay');
        }
        $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 1, 89);
        $this->assertSame(1, $this->db->table('ai_dispatch_attempts')->countAllResults());
    }

    public function testDispatchRollsBackIfUtcDayChangesDuringReservation(): void
    {
        $this->now = strtotime('2026-10-28 23:59:59 UTC');
        $job = $this->enqueue();
        $claim = $this->store->claim(1, 'clinic', 'r1');
        $this->db->connID->createFunction('ai_dispatch_midnight', function (): int {
            $this->now++;
            return 1;
        }, 0);
        $this->db->query('CREATE TRIGGER ai_dispatch_midnight_delay AFTER INSERT ON ai_test_ai_dispatch_attempts BEGIN SELECT ai_dispatch_midnight(); END');
        try {
            $this->failure('invalid_job', fn () => $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 1, 60));
            $this->assertSame(0, $this->db->table('ai_dispatch_attempts')->countAllResults());
        } finally {
            $this->db->query('DROP TRIGGER ai_dispatch_midnight_delay');
        }
        $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 1, 60);
        $row = $this->db->table('ai_dispatch_attempts')->get()->getRowArray();
        $this->assertSame('2026-10-29', $row['day']);
        $this->assertSame('2026-10-29 00:00:00', $row['reserved_at']);
    }

    public function testDispatchInsertFailureAndCallerTransactionCannotConsumeOrCommit(): void
    {
        $job = $this->enqueue();
        $claim = $this->store->claim(1, 'clinic', 'r1');
        $this->db->transBegin();
        try {
            $this->store->assertLease(1, 'clinic', $job['id'], $claim['lease_token']);
            $this->failure('independent_transaction_required', fn () => $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 1, 60), 503);
            $this->assertSame(1, $this->db->transDepth);
        } finally {
            $this->db->transRollback();
        }
        $this->db->query("CREATE TRIGGER ai_dispatch_failure BEFORE INSERT ON ai_test_ai_dispatch_attempts BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            $this->failure('store_unavailable', fn () => $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 1, 60), 503);
            $this->assertSame(0, $this->db->table('ai_dispatch_attempts')->countAllResults());
            $this->assertSame(0, $this->db->transDepth);
        } finally {
            $this->db->query('DROP TRIGGER ai_dispatch_failure');
        }
        $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 1, 60);
        $this->assertSame(1, $this->db->table('ai_dispatch_attempts')->countAllResults());
    }

    public function testReadinessRequiresDispatchTableAndBothUniqueConstraints(): void
    {
        foreach ([['tenant_id', 'day', 'slot'], ['tenant_id', 'unit', 'job_id']] as $fields) {
            $db = $this->connection();
            try {
                (new CreateAiInsights(Database::forge($db)))->up();
                foreach ($db->getIndexData('ai_dispatch_attempts') as $index) {
                    if ($index->fields === $fields) {
                        $db->query('DROP INDEX ' . $db->escapeIdentifiers($index->name));
                    }
                }
                $this->assertFalse($this->storeFor($db)->ready());
            } finally {
                $db->close();
            }
        }
        Database::forge($this->db)->dropTable('ai_dispatch_attempts');
        $this->assertFalse($this->store->ready());
    }

    public function testCallerTransactionIsNeverCommittedOrRolledBack(): void
    {
        $this->db->transBegin();
        try {
            $this->failure('independent_transaction_required', fn () => $this->enqueue(), 503);
            $this->assertSame(1, $this->db->transDepth);
            $this->assertSame(0, $this->db->table('ai_snapshots')->countAllResults());
        } finally {
            $this->db->transRollback();
        }
    }
}

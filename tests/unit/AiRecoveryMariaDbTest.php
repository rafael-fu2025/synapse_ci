<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiFailure;
use App\Services\Ai\DisclosurePolicy;
use App\Services\Ai\InsightStore;
use App\Services\Ai\PayloadCipher;
use App\Services\Ai\PipelineProvenance;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\FreshDatabaseTestCase;

/**
 * Run only with python -I tests/_support/run-fresh-database-tests.py tests/unit/AiRecoveryMariaDbTest.php.
 * Full App migrations run only after FreshDatabaseTestCase verifies the disposable MySQL schema.
 * No provider, operational records, settings files, or production encryption keys are used.
 */
final class AiRecoveryMariaDbTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private int $tenantId;
    private int $now;
    private string $period;
    private PayloadCipher $cipher;
    private InsightStore $store;

    protected function setUp(): void
    {
        parent::setUp(); // The inherited guard MUST precede DatabaseTestTrait migrations.
        self::assertSame('MySQLi', $this->db->DBDriver);
        self::assertSame(HUMUS_FRESH_DATABASE_VERIFIED, $this->db->query('SELECT DATABASE() AS name')->getRowArray()['name']);
        // Use the current Manila reporting month, but avoid month-boundary clock races.
        $month = new \DateTimeImmutable('first day of this month', new \DateTimeZone('Asia/Manila'));
        $this->now = $month->setDate((int) $month->format('Y'), (int) $month->format('m'), 12)->setTime(12, 0)->getTimestamp();
        $this->period = $month->modify('-1 month')->format('Y-m');
        $time = gmdate('Y-m-d H:i:s', $this->now);
        self::assertTrue($this->db->table('tenants')->insert([
            'slug' => 'ai_recovery_' . bin2hex(random_bytes(8)), 'name' => 'Disposable synthetic AI recovery',
            'is_active' => 1, 'created_at' => $time, 'updated_at' => $time,
        ]));
        $this->tenantId = (int) $this->db->insertID();
        $this->cipher = new PayloadCipher(base64_encode(str_repeat('r', 32)));
        $this->store = $this->newStore();
        self::assertTrue($this->store->ready(), 'Full migrated MariaDB schema must be usable.');
        self::assertTrue($this->store->supportsAnalysisScope());
        self::assertTrue($this->store->supportsRecovery(), 'Recovery columns and unique successor index must be present.');
    }

    private function newStore(): InsightStore
    {
        return new InsightStore($this->db, $this->cipher, fn (): int => $this->now, 1, static function (): void {});
    }

    private function payload(int $count = 240): array
    {
        $previous = (new \DateTimeImmutable($this->period . '-01'))->modify('-1 month')->format('Y-m');
        return DisclosurePolicy::release(['count' => $count, 'contributors' => 20, 'unlinked' => 0],
            ['count' => 200, 'contributors' => 20, 'unlinked' => 0], $this->period, $previous);
    }

    private function job(string $revision = 'synthetic-r1'): array
    {
        $snapshot = $this->store->saveSnapshot($this->tenantId, 'clinic', $this->payload());
        return $this->store->enqueue($this->tenantId, 'clinic', $snapshot['id'], 10, $revision, 'synthetic-model', 10);
    }

    private function row(string $table, int $id): array
    {
        return $this->db->table($table)->where('tenant_id', $this->tenantId)->where('id', $id)->get()->getRowArray();
    }

    private function scopedCount(string $table): int
    {
        return $this->db->table($table)->where('tenant_id', $this->tenantId)->countAllResults();
    }

    private function denied(callable $operation, string $reason = 'invalid_job'): void
    {
        try { $operation(); self::fail('Expected recovery safety rejection.'); }
        catch (AiFailure $error) { self::assertSame($reason, $error->reason); }
        self::assertSame(0, $this->db->transDepth);
        self::assertTrue($this->db->transStatus());
    }

    public function testFullMigrationHasInnoDbRecoverySchemaAndNullableUniqueSuccessor(): void
    {
        foreach (['ai_snapshots', 'ai_jobs', 'ai_storage_audit'] as $table) {
            $row = $this->db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])->getRowArray();
            self::assertSame('InnoDB', $row['ENGINE']);
        }
        $indexes = $this->db->query("SHOW INDEX FROM ai_jobs WHERE Key_name='ai_retry_successor'")->getResultArray();
        self::assertCount(1, $indexes);
        self::assertSame('retry_of_id', $indexes[0]['Column_name']);
        self::assertSame(0, (int) $indexes[0]['Non_unique']);
        $first = $this->job();
        $second = $this->job('synthetic-r2');
        self::assertNotSame($first['id'], $second['id']);
        self::assertNull($this->row('ai_jobs', $first['id'])['retry_of_id']);
        self::assertNull($this->row('ai_jobs', $second['id'])['retry_of_id']);
        self::assertSame($first['id'], (int) $first['root_job_id'], 'MySQL insert identity must survive metadata readiness queries.');
        self::assertSame($second['id'], (int) $second['root_job_id']);
        self::assertSame(1, (int) $first['snapshot_generation']);
        self::assertSame(PipelineProvenance::digest(), $first['pipeline_digest']);
    }

    public function testIdenticalRecoveryRetainsEncryptedDisclosureAndBindsNewGeneration(): void
    {
        $job = $this->job();
        $before = $this->row('ai_snapshots', $job['snapshot_id']);
        self::assertStringStartsWith('v1.', $before['payload']);
        self::assertStringNotContainsString('"metrics"', $before['payload']);
        self::assertEquals($this->payload(), $this->cipher->decrypt($this->tenantId, 'clinic', 'snapshot:' . $before['fingerprint'], $before['payload']));
        $releases = $this->db->table('ai_releases')->where('tenant_id', $this->tenantId)->orderBy('id')->get()->getResultArray();
        $this->now += 86400;
        self::assertSame(1, $this->store->purgeExpired($this->tenantId, 'clinic')['ai_snapshots']);
        self::assertNull($this->row('ai_snapshots', $job['snapshot_id'])['payload']);
        self::assertCount(1, $this->store->listRecoverableSnapshots($this->tenantId, 'clinic'));
        $this->denied(fn () => $this->store->recoverSnapshot($this->tenantId, 'clinic', $this->payload(241), 10), 'snapshot_changed_requires_review');
        $recovered = $this->store->recoverSnapshot($this->tenantId, 'clinic', array_reverse($this->payload(), true), 10);
        self::assertSame(2, $recovered['materialization_generation']);
        $after = $this->row('ai_snapshots', $job['snapshot_id']);
        self::assertSame($before['fingerprint'], $after['fingerprint']);
        self::assertSame($before['created_at'], $after['created_at']);
        self::assertNotSame($before['payload'], $after['payload']);
        self::assertEquals($this->payload(), $this->cipher->decrypt($this->tenantId, 'clinic', 'snapshot:' . $after['fingerprint'], $after['payload']));
        self::assertSame($releases, $this->db->table('ai_releases')->where('tenant_id', $this->tenantId)->orderBy('id')->get()->getResultArray());
        self::assertSame('expired', $this->store->job($this->tenantId, 'clinic', $job['id'])['status']);
        self::assertNull($this->store->claim($this->tenantId, 'clinic', 'synthetic-r1'));
        $next = $this->store->retry($this->tenantId, 'clinic', $job['id'], 11, 'synthetic-r1', 'synthetic-model', 10);
        self::assertSame(2, (int) $next['snapshot_generation']);
        $claim = $this->store->claim($this->tenantId, 'clinic', 'synthetic-r1');
        self::assertSame($next['id'], $claim['id']);
        self::assertEquals($this->payload(), $claim['payload']);
        self::assertSame([], $this->store->listRecoverableSnapshots($this->tenantId, 'clinic'));
        self::assertSame(['snapshot_recovered', 'job_retried'], array_column($this->db->table('ai_storage_audit')->where('tenant_id', $this->tenantId)->orderBy('id')->get()->getResultArray(), 'action'));
    }

    public function testCancelledRetryDedupesAndMariaDbRejectsSecondSuccessor(): void
    {
        $job = $this->job();
        $this->store->cancel($this->tenantId, 'clinic', $job['id']);
        self::assertTrue($this->store->job($this->tenantId, 'clinic', $job['id'])['can_retry'], 'An unclaimed cancelled root job with no dispatch reservation must be retryable.');
        $next = $this->store->retry($this->tenantId, 'clinic', $job['id'], 11, 'synthetic-r1', 'synthetic-model', 10);
        $again = $this->newStore()->retry($this->tenantId, 'clinic', $job['id'], 12, 'synthetic-r1', 'synthetic-model', 10);
        self::assertSame($next['id'], $again['id']);
        self::assertTrue($again['reused']);
        self::assertSame($job['id'], (int) $next['retry_of_id']);
        self::assertSame($job['id'], (int) $next['root_job_id']);
        self::assertSame($next['id'], $this->job()['id']);
        self::assertFalse($this->store->job($this->tenantId, 'clinic', $job['id'])['can_retry']);
        $duplicate = $this->row('ai_jobs', $next['id']);
        unset($duplicate['id']);
        // Distinct admission and dedupe keys isolate the successor index as the rejection cause.
        $duplicate['dedupe_key'] = bin2hex(random_bytes(32));
        $duplicate['budget_key'] = bin2hex(random_bytes(32));
        self::assertTrue($this->db->table('ai_jobs')->ignore(true)->insert($duplicate));
        self::assertSame(0, $this->db->affectedRows(), 'MySQL uniqueness must reject a second successor without a preceding application read.');
        self::assertSame(2, $this->scopedCount('ai_jobs'));
        self::assertSame(1, $this->scopedCount('ai_storage_audit'));
        $this->store->cancel($this->tenantId, 'clinic', $next['id']);
        $this->denied(fn () => $this->store->retry($this->tenantId, 'clinic', $next['id'], 10, 'synthetic-r1', 'synthetic-model', 2), 'daily_budget_exceeded');
        self::assertSame(2, $this->scopedCount('ai_jobs'));
    }

    public function testDispatchReservationIsOneShotAndPermanentlyBlocksRetry(): void
    {
        $job = $this->job();
        $claim = $this->store->claim($this->tenantId, 'clinic', 'synthetic-r1');
        self::assertSame($job['id'], $claim['id']);
        $this->store->reserveDispatch($this->tenantId, 'clinic', $job['id'], $claim['lease_token'], 10, 1);
        $this->denied(fn () => $this->store->reserveDispatch($this->tenantId, 'clinic', $job['id'], $claim['lease_token'], 10, 1));
        self::assertSame(1, $this->scopedCount('ai_dispatch_attempts'));
        // No HTTP call occurs: recording a synthetic failure cannot make a reservation safe to replay.
        $this->store->fail($this->tenantId, 'clinic', $job['id'], 'provider_unavailable', false, $claim['lease_token']);
        self::assertFalse($this->store->job($this->tenantId, 'clinic', $job['id'])['can_retry']);
        $this->denied(fn () => $this->store->retry($this->tenantId, 'clinic', $job['id'], 11, 'synthetic-r1', 'synthetic-model', 10));
        self::assertSame(1, $this->scopedCount('ai_jobs'));
        self::assertSame(0, $this->scopedCount('ai_storage_audit'));
    }

    public function testGenerationMismatchCannotReserveOrCompleteAndScopeCannotRecover(): void
    {
        $job = $this->job();
        $claim = $this->store->claim($this->tenantId, 'clinic', 'synthetic-r1');
        self::assertTrue($this->db->table('ai_snapshots')->where('tenant_id', $this->tenantId)->where('id', $job['snapshot_id'])->update(['materialization_generation' => 2]));
        $this->denied(fn () => $this->store->reserveDispatch($this->tenantId, 'clinic', $job['id'], $claim['lease_token'], 10, 1));
        $this->denied(fn () => $this->store->complete($this->tenantId, 'clinic', $job['id'], ['synthetic' => true], $claim['lease_token']));
        self::assertSame(0, $this->scopedCount('ai_dispatch_attempts'));
        self::assertSame(0, $this->scopedCount('ai_insights'));
        $this->denied(fn () => $this->store->recoverSnapshot($this->tenantId, 'guidance', $this->payload(), 10), 'invalid_scope');
        self::assertSame([], $this->store->listRecoverableSnapshots($this->tenantId, 'guidance'));
        self::assertSame(0, $this->scopedCount('ai_storage_audit'));
    }
}

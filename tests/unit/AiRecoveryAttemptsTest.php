<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\AddAiRecoveryAttempts;
use App\Database\Migrations\CreateAiInsights;
use App\Services\Ai\AiFailure;
use App\Services\Ai\DisclosurePolicy;
use App\Services\Ai\InsightStore;
use App\Services\Ai\PayloadCipher;
use App\Services\Ai\PipelineProvenance;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/** No migration runner or application connection: disposable SQLite only. */
final class AiRecoveryAttemptsTest extends TestCase
{
    private BaseConnection $db;
    private InsightStore $store;
    private int $now;

    protected function setUp(): void
    {
        if (!extension_loaded('sqlite3')) { $this->markTestSkipped('Requires disposable SQLite.'); }
        $this->now = strtotime('2026-11-05 12:00:00 UTC');
        $this->db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'recovery_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 5000], false);
        require_once APPPATH . 'Database/Migrations/2026-10-28-000001_CreateAiInsights.php';
        require_once APPPATH . 'Database/Migrations/2026-11-05-000001_AddAiRecoveryAttempts.php';
        (new CreateAiInsights(Database::forge($this->db)))->up();
        $this->store = $this->newStore();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) { $this->db->close(); }
    }

    private function newStore(): InsightStore
    {
        return new InsightStore($this->db, new PayloadCipher(base64_encode(str_repeat('k', 32))),
            fn (): int => $this->now, 1, static function (): void {});
    }

    private function upgrade(): void
    {
        (new AddAiRecoveryAttempts(Database::forge($this->db)))->up();
        $this->assertTrue($this->store->supportsRecovery());
        $this->assertTrue($this->store->ready());
    }

    private function payload(string $period = '2026-10', int $count = 240): array
    {
        $previous = (new \DateTimeImmutable($period . '-01'))->modify('-1 month')->format('Y-m');
        return DisclosurePolicy::release(['count' => $count, 'contributors' => 20, 'unlinked' => 0],
            ['count' => 200, 'contributors' => 20, 'unlinked' => 0], $period, $previous);
    }

    private function job(): array
    {
        $snapshot = $this->store->saveSnapshot(1, 'clinic', $this->payload());
        return $this->store->enqueue(1, 'clinic', $snapshot['id'], 10, 'r1', 'model', 10);
    }

    private function denied(callable $work, string $reason = 'invalid_job'): void
    {
        try { $work(); $this->fail('Expected rejection'); }
        catch (AiFailure $error) { $this->assertSame($reason, $error->reason); }
    }

    public function testPartialRecoverySchemaFailsClosedBeforeAnyNewWork(): void
    {
        $this->assertTrue($this->store->ready());
        Database::forge($this->db)->addColumn('ai_snapshots', [
            'materialization_generation' => ['type' => 'BIGINT', 'default' => 1],
        ]);
        $this->db->resetDataCache();
        $this->assertFalse($this->store->supportsRecovery());
        $this->assertFalse($this->store->ready());
        $this->denied(fn () => $this->store->saveSnapshot(1, 'clinic', $this->payload()), 'store_unavailable');
    }

    public function testMigrationKeepsLegacyRowsButDoesNotBlessDispatchOrRecoveryBeforeUpgrade(): void
    {
        $job = $this->job();
        $this->assertFalse($this->store->supportsRecovery());
        $this->denied(fn () => $this->store->retry(1, 'clinic', $job['id'], 10, 'r1', 'model', 10), 'store_unavailable');
        $this->upgrade();
        $this->assertNull($this->store->claim(1, 'clinic', 'r1'));
        $this->assertSame('expired', $this->store->job(1, 'clinic', $job['id'])['status']);
        $this->assertFalse($this->store->job(1, 'clinic', $job['id'])['can_retry']);
        $this->assertSame(1, (int) $this->db->table('ai_snapshots')->get()->getRowArray()['materialization_generation']);
    }

    public function testRecoveryRetainsFreezeAndCreationExpiresOldWorkAndRequiresFullFingerprint(): void
    {
        $this->upgrade();
        $job = $this->job();
        $snapshot = $this->db->table('ai_snapshots')->get()->getRowArray();
        $releases = $this->db->table('ai_releases')->orderBy('id')->get()->getResultArray();
        $this->now += 86400;
        $this->store->purgeExpired(1, 'clinic');
        $this->assertCount(1, $this->store->listRecoverableSnapshots(1, 'clinic'));
        $this->assertSame([], $this->store->listRecoverableSnapshots(2, 'clinic'));
        $this->denied(fn () => $this->store->recoverSnapshot(1, 'clinic', $this->payload(count: 241), 10), 'snapshot_changed_requires_review');
        $result = $this->store->recoverSnapshot(1, 'clinic', array_reverse($this->payload(), true), 10);
        $this->assertSame(2, $result['materialization_generation']);
        $after = $this->db->table('ai_snapshots')->get()->getRowArray();
        $this->assertSame($snapshot['created_at'], $after['created_at']);
        $this->assertSame($snapshot['fingerprint'], $after['fingerprint']);
        $this->assertSame($releases, $this->db->table('ai_releases')->orderBy('id')->get()->getResultArray());
        $this->assertSame('expired', $this->store->job(1, 'clinic', $job['id'])['status']);
        $this->assertNull($this->store->claim(1, 'clinic', 'r1'));
        $this->assertSame([], $this->store->listRecoverableSnapshots(1, 'clinic'));
        $this->assertSame('snapshot_recovered', $this->db->table('ai_storage_audit')->get()->getRowArray()['action']);
    }

    public function testRecoveryAuditFailureRollsBackGenerationAndQueuedExpiry(): void
    {
        $this->upgrade();
        $job = $this->job();
        $this->db->table('ai_snapshots')->where('id', $job['snapshot_id'])->update(['payload' => null]);
        $this->db->query("CREATE TRIGGER recovery_audit_failure BEFORE INSERT ON recovery_ai_storage_audit BEGIN SELECT RAISE(ABORT, 'synthetic'); END");
        $this->denied(fn () => $this->store->recoverSnapshot(1, 'clinic', $this->payload(), 10), 'store_unavailable');
        $snapshot = $this->db->table('ai_snapshots')->get()->getRowArray();
        $this->assertSame(1, (int) $snapshot['materialization_generation']);
        $this->assertNull($snapshot['payload']);
        $this->assertSame('queued', $this->store->job(1, 'clinic', $job['id'])['status']);
        $this->db->query('DROP TRIGGER recovery_audit_failure');
        $this->store->recoverSnapshot(1, 'clinic', $this->payload(), 10);
        $this->assertSame('expired', $this->store->job(1, 'clinic', $job['id'])['status']);
        $this->assertNull($this->store->claim(1, 'clinic', 'r1'));
        $next = $this->store->retry(1, 'clinic', $job['id'], 11, 'r1', 'model', 10);
        $this->assertSame(2, (int) $next['snapshot_generation']);
    }

    public function testRecoveryOnlyCurrentManilaReportingWindow(): void
    {
        $this->upgrade();
        $this->store->saveSnapshot(1, 'clinic', $this->payload('2026-09'));
        $this->now += 86400;
        $this->assertSame([], $this->store->listRecoverableSnapshots(1, 'clinic'));
        $this->denied(fn () => $this->store->recoverSnapshot(1, 'clinic', $this->payload('2026-09'), 10), 'snapshot_expired');
        $this->assertSame(0, $this->db->table('ai_storage_audit')->countAllResults());
    }

    public function testRetryOneSuccessorAcrossInstancesDedupesToLatestAndConsumesBudget(): void
    {
        $this->upgrade();
        $job = $this->job();
        $this->store->cancel(1, 'clinic', $job['id']);
        $this->assertTrue($this->store->job(1, 'clinic', $job['id'])['can_retry']);
        $next = $this->store->retry(1, 'clinic', $job['id'], 11, 'r1', 'model', 10);
        $again = $this->newStore()->retry(1, 'clinic', $job['id'], 12, 'r1', 'model', 10);
        $this->assertSame($next['id'], $again['id']);
        $this->assertTrue($again['reused']);
        $this->assertSame($job['id'], (int) $next['root_job_id']);
        $this->assertSame($job['id'], (int) $next['retry_of_id']);
        $this->assertSame($next['id'], $this->job()['id']);
        $this->assertFalse($this->store->job(1, 'clinic', $job['id'])['can_retry']);
        $this->store->cancel(1, 'clinic', $next['id']);
        $this->denied(fn () => $this->store->retry(1, 'clinic', $next['id'], 10, 'r1', 'model', 2), 'daily_budget_exceeded');
        $this->assertSame(2, $this->db->table('ai_jobs')->countAllResults());
        $this->assertSame(1, $this->db->table('ai_storage_audit')->countAllResults());
    }

    public function testUniqueSuccessorConstraintAndSnapshotFreezeRemainAuthoritative(): void
    {
        $this->upgrade();
        $job = $this->job();
        $this->store->cancel(1, 'clinic', $job['id']);
        $next = $this->store->retry(1, 'clinic', $job['id'], 11, 'r1', 'model', 10);
        $duplicate = $this->db->table('ai_jobs')->where('id', $next['id'])->get()->getRowArray();
        unset($duplicate['id']);
        $duplicate['dedupe_key'] = str_repeat('a', 64);
        $duplicate['budget_key'] = str_repeat('b', 64);
        $this->db->table('ai_jobs')->ignore(true)->insert($duplicate);
        $this->assertSame(0, $this->db->affectedRows(), 'Database uniqueness, not a preceding read, enforces one successor');
        $this->db->table('ai_snapshots')->where('id', $job['snapshot_id'])->update(['payload' => null]);
        $this->db->table('ai_releases')->where('month', '2026-10')->update(['fingerprint' => str_repeat('0', 64)]);
        $this->denied(fn () => $this->store->recoverSnapshot(1, 'clinic', $this->payload(), 10), 'snapshot_changed_requires_review');
        $this->assertNull($this->db->table('ai_snapshots')->get()->getRowArray()['payload']);
        $this->denied(fn () => $this->store->retry(2, 'clinic', $job['id'], 10, 'r1', 'model', 10), 'job_not_found');
    }

    public function testReportingWindowChangesAtManilaMidnightNotUtcMidnight(): void
    {
        $this->upgrade();
        $this->now = strtotime('2026-10-31 15:59:59 UTC');
        $job = $this->job();
        $this->db->table('ai_snapshots')->where('id', $job['snapshot_id'])->update(['payload' => null]);
        $this->assertSame([], $this->store->listRecoverableSnapshots(1, 'clinic'));
        $this->now++;
        $this->assertCount(1, $this->store->listRecoverableSnapshots(1, 'clinic'));
        $this->store->recoverSnapshot(1, 'clinic', $this->payload(), 10);
    }

    public function testReservationAndUnknownLineageCanNeverRetry(): void
    {
        $this->upgrade();
        $job = $this->job();
        $claim = $this->store->claim(1, 'clinic', 'r1');
        $this->assertSame(PipelineProvenance::digest(), $claim['pipeline_digest']);
        $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 10, 1);
        $this->store->fail(1, 'clinic', $job['id'], 'provider_unavailable', false, $claim['lease_token']);
        $this->assertFalse($this->store->job(1, 'clinic', $job['id'])['can_retry']);
        $this->denied(fn () => $this->store->retry(1, 'clinic', $job['id'], 10, 'r1', 'model', 10));
    }

    public function testReservedAncestorBlocksRetryOfEveryDescendant(): void
    {
        $this->upgrade();
        $root = $this->job();
        $this->store->cancel(1, 'clinic', $root['id']);
        $next = $this->store->retry(1, 'clinic', $root['id'], 11, 'r1', 'model', 10);
        $this->store->cancel(1, 'clinic', $next['id']);
        $this->db->table('ai_dispatch_attempts')->insert([
            'tenant_id' => 1, 'unit' => 'clinic', 'job_id' => $root['id'],
            'day' => '2026-11-05', 'slot' => 1, 'reserved_at' => '2026-11-05 12:00:00',
        ]);
        $this->assertFalse($this->store->job(1, 'clinic', $next['id'])['can_retry']);
        $this->denied(fn () => $this->store->retry(1, 'clinic', $next['id'], 12, 'r1', 'model', 10));
        $this->assertSame(2, $this->db->table('ai_jobs')->countAllResults());
    }

    public function testRecoveryCannotBypassAnEarlierOutboundReservation(): void
    {
        $this->upgrade();
        $job = $this->job();
        $claim = $this->store->claim(1, 'clinic', 'r1');
        $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 10, 1);
        $this->store->fail(1, 'clinic', $job['id'], 'invalid_output', false, $claim['lease_token']);
        $this->now += 86400;
        $this->store->purgeExpired(1, 'clinic');
        $this->store->recoverSnapshot(1, 'clinic', $this->payload(), 10);
        $this->assertSame($job['id'], $this->job()['id']);
        $this->assertFalse($this->store->job(1, 'clinic', $job['id'])['can_retry']);
        $this->denied(fn () => $this->store->retry(1, 'clinic', $job['id'], 11, 'r1', 'model', 10));
        $this->assertSame(1, $this->db->table('ai_dispatch_attempts')->countAllResults());
    }

    public function testGenerationAndProvenanceAreCheckedAtReservationAndClaim(): void
    {
        $this->upgrade();
        $job = $this->job();
        $claim = $this->store->claim(1, 'clinic', 'r1');
        $this->db->table('ai_snapshots')->where('id', $job['snapshot_id'])->update(['materialization_generation' => 2]);
        $this->denied(fn () => $this->store->reserveDispatch(1, 'clinic', $job['id'], $claim['lease_token'], 10, 1));
        $this->assertSame(0, $this->db->table('ai_dispatch_attempts')->countAllResults());
        $this->store->fail(1, 'clinic', $job['id'], 'generation_failed', false, $claim['lease_token']);
        $this->denied(fn () => $this->store->retry(1, 'clinic', $job['id'], 10, 'r2', 'model', 10));
        $this->db->table('ai_jobs')->where('id', $job['id'])->update(['pipeline_digest' => str_repeat('0', 64)]);
        $next = $this->store->retry(1, 'clinic', $job['id'], 10, 'r1', 'model', 10);
        $this->assertSame(PipelineProvenance::digest(), $next['pipeline_digest']);
        $this->assertSame(2, (int) $next['snapshot_generation']);
        $claim = $this->store->claim(1, 'clinic', 'r1', 1);
        $this->now++;
        $this->assertNull($this->store->claim(1, 'clinic', 'r1'));
        $this->assertSame('outcome_unknown', $this->store->job(1, 'clinic', $next['id'])['status']);
        $this->denied(fn () => $this->store->retry(1, 'clinic', $next['id'], 10, 'r1', 'model', 10));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Realtime\AiInsightInvalidation;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/** Stores approved aggregates/validated drafts only; authorization remains the caller's duty. */
final class InsightStore
{
    private ?BaseConnection $db;
    private PayloadCipher $cipher;
    private Closure $clock;
    private int $retentionDays;
    private array $leases = [];
    private array $invalidations = [];
    private readonly Closure $notify;

    private const DISCLOSURE_VERSION = '1';
    // Fixed monthly-independent suppression policy; changes require history review.
    private const CATEGORY_POLICY_VERSION = '1';
    private const CATEGORY_MASK_CODES = [
        'clinic.complaints' => 'disclosure_clinic_complaints',
        'guidance.themes' => 'disclosure_guidance_themes',
    ];
    private const COLUMNS = [
        'ai_releases' => 'id tenant_id unit metric_code month fingerprint created_at',
        'ai_snapshots' => 'id tenant_id unit period schema_version disclosure_version fingerprint payload created_at expires_at',
        'ai_jobs' => 'id tenant_id unit snapshot_id actor_id profile_revision model dedupe_key budget_key active_key status reason attempts lease_token lease_expires_at created_at updated_at expires_at',
        'ai_dispatch_attempts' => 'id tenant_id unit job_id day slot reserved_at',
        'ai_insights' => 'id tenant_id unit job_id snapshot_id payload status version created_at updated_at expires_at',
        'ai_reviews' => 'id tenant_id unit insight_id actor_id version decision reason created_at',
    ];
    private const UNIQUE_KEYS = [
        'ai_releases' => [['id'], ['tenant_id', 'unit', 'metric_code', 'month']],
        'ai_snapshots' => [['id'], ['tenant_id', 'unit', 'period']],
        'ai_jobs' => [['id'], ['dedupe_key'], ['budget_key'], ['active_key']],
        'ai_dispatch_attempts' => [['id'], ['tenant_id', 'day', 'slot'], ['tenant_id', 'unit', 'job_id']],
        'ai_insights' => [['id'], ['tenant_id', 'unit', 'job_id']],
        'ai_reviews' => [['id'], ['tenant_id', 'unit', 'insight_id', 'version']],
    ];

    public function __construct(?BaseConnection $db = null, ?PayloadCipher $cipher = null, ?Closure $clock = null, ?int $retentionDays = null, ?callable $notify = null)
    {
        $this->db = $db;
        $this->notify = Closure::fromCallable($notify ?? (new AiInsightInvalidation())->changed(...));
        $this->cipher = $cipher ?? new PayloadCipher();
        $this->clock = $clock ?? static fn (): int => time();
        if ($retentionDays === null) {
            $config = config('Ai');
            $retentionDays = $config !== null && property_exists($config, 'retentionDays') ? $config->retentionDays : 30;
        }
        if (!is_int($retentionDays) || $retentionDays < 1 || $retentionDays > 365) {
            throw new AiFailure('invalid_config', 503);
        }
        $this->retentionDays = $retentionDays;
    }

    /** Metadata inspection only, never migrations or operational table reads. */
    public function ready(): bool
    {
        if (!$this->cipher->ready()) {
            return false;
        }
        try {
            $this->db ??= db_connect();
            if (!in_array($this->db->DBDriver, ['MySQLi', 'SQLite3'], true)) {
                return false;
            }
            foreach (self::COLUMNS as $table => $columns) {
                if (!$this->db->tableExists($table)
                    || array_diff(explode(' ', $columns), $this->db->getFieldNames($table)) !== []) {
                    return false;
                }
                $indexes = $this->db->getIndexData($table);
                $keys = self::UNIQUE_KEYS[$table];
                if ($table === 'ai_snapshots') {
                    $hasColumn = in_array('analysis_code', $this->db->getFieldNames($table), true);
                    if ($hasColumn ? !$this->supportsAnalysisScope() : !$this->hasUnique($indexes, ['tenant_id', 'unit', 'period'])) {
                        return false;
                    }
                    $keys = [['id']];
                }
                foreach ($keys as $key) {
                    $found = false;
                    foreach ($indexes as $index) {
                        if (in_array(strtoupper($index->type), ['PRIMARY', 'UNIQUE'], true)
                            && $index->fields === $key) {
                            $found = true;
                        }
                    }
                    if (!$found) {
                        return false;
                    }
                }
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Scope is installed only after the new unique key replaces the legacy key. */
    public function supportsAnalysisScope(): bool
    {
        try {
            $this->db ??= db_connect();
            if (!in_array($this->db->DBDriver, ['MySQLi', 'SQLite3'], true)
                || !$this->db->tableExists('ai_snapshots')
                || !in_array('analysis_code', $this->db->getFieldNames('ai_snapshots'), true)) {
                return false;
            }
            $indexes = $this->db->getIndexData('ai_snapshots');
            return $this->hasUnique($indexes, ['tenant_id', 'unit', 'analysis_code', 'period'])
                && !$this->hasUnique($indexes, ['tenant_id', 'unit', 'period']);
        } catch (Throwable) {
            return false;
        }
    }

    private function hasUnique(array $indexes, array $fields): bool
    {
        foreach ($indexes as $index) {
            if (in_array(strtoupper($index->type), ['PRIMARY', 'UNIQUE'], true) && $index->fields === $fields) {
                return true;
            }
        }
        return false;
    }

    public function saveSnapshot(int $tenantId, string $unit, array $payload): array
    {
        $this->scope($tenantId, $unit);
        DisclosurePolicy::validatePayload($payload);
        $analysis = 'clinic.activity';
        if ($payload['schema_version'] === '1' && $unit !== 'clinic') {
            throw new AiFailure('invalid_scope', 422);
        }
        if ($payload['schema_version'] === '2') {
            $analysis = $payload['analysis_code'];
            $definition = AnalysisCatalog::get($analysis);
            if ($payload['department'] !== $unit || $definition['unit'] !== $unit
                || !str_starts_with($analysis, $unit . '.')) {
                throw new AiFailure('invalid_scope', 422);
            }
            if (!$this->supportsAnalysisScope()) {
                throw new AiFailure('store_unavailable', 503);
            }
        }
        $period = $payload['period']['label'];
        $schema = $payload['schema_version'];
        $disclosure = self::DISCLOSURE_VERSION;
        $fingerprint = $this->cipher->fingerprint($tenantId, $unit, 'snapshot', $this->canonical([
            'schema_version' => $schema, 'disclosure_version' => $disclosure, 'payload' => $payload]));
        return $this->transaction(function () use ($tenantId, $unit, $payload, $analysis, $period, $schema, $disclosure, $fingerprint): array {
            $now = $this->now();
            $this->freezeMonths($tenantId, $unit, $payload);
            $scoped = $this->supportsAnalysisScope();
            $this->write($this->db->table('ai_snapshots')->ignore(true)->insert(($scoped ? ['analysis_code' => $analysis] : []) + [
                'tenant_id' => $tenantId, 'unit' => $unit, 'period' => $period,
                'schema_version' => $schema, 'disclosure_version' => $disclosure, 'fingerprint' => $fingerprint,
                'payload' => $this->cipher->encrypt($tenantId, $unit, 'snapshot:' . $fingerprint, $payload),
                'created_at' => $now, 'expires_at' => $this->after($this->retentionDays * 86400),
            ]));
            if ($this->db->affectedRows() === 1) { $this->changed($tenantId, $unit); }
            $query = $this->table('ai_snapshots', $tenantId, $unit)->where('period', $period);
            if ($scoped) {
                $query->where('analysis_code', $analysis);
            }
            $sql = $query->getCompiledSelect();
            $row = $this->db->query($sql . ($this->db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : ''))->getRowArray();
            if ($row === null) {
                throw new AiFailure('store_unavailable', 503);
            }
            if (!hash_equals($row['fingerprint'], $fingerprint)) {
                throw new AiFailure('snapshot_changed_requires_review', 409);
            }
            if ($row['expires_at'] <= $now || $row['payload'] === null) {
                throw new AiFailure('snapshot_expired', 409);
            }
            return ['id' => (int) $row['id'], 'payload' => $this->snapshotPayload($tenantId, $unit, $row),
                'fingerprint' => $row['fingerprint'], 'expires_at' => $row['expires_at']];
        });
    }

    public function enqueue(int $tenantId, string $unit, int $snapshotId, int $actorId, string $profileRevision, string $model, int $dailyLimit): array
    {
        $this->scope($tenantId, $unit);
        if ($actorId < 1 || $snapshotId < 1 || !preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $profileRevision)
            || $model === '' || strlen($model) > 191 || !mb_check_encoding($model, 'UTF-8')
            || preg_match('/[\s\p{Z}\p{C}]/u', $model) || $dailyLimit < 1 || $dailyLimit > 10000) {
            throw new AiFailure('invalid_job', 422);
        }
        return $this->transaction(function () use ($tenantId, $unit, $snapshotId, $actorId, $profileRevision, $model, $dailyLimit): array {
            // Acquire a scoped row lock before any reads. This serializes same-release
            // enqueue on InnoDB too, without relying on repeatable-read snapshots.
            $this->write($this->table('ai_snapshots', $tenantId, $unit)->where('id', $snapshotId)
                ->update(['id' => $snapshotId]));
            $snapshot = $this->snapshot($tenantId, $unit, $snapshotId);
            $key = $this->key([$tenantId, $unit, $snapshotId, $snapshot['fingerprint'], $profileRevision, $model]);
            $existing = $this->table('ai_jobs', $tenantId, $unit)->where('dedupe_key', $key)->get()->getRowArray();
            if ($existing !== null) {
                return $this->jobMetadata($existing + array_intersect_key($snapshot, ['analysis_code' => true])) + ['reused' => true];
            }
            // Enqueue-day admission control is separate from outbound-day spend.
            // reserveDispatch() must run immediately before the single provider POST.
            for ($slot = 1; $slot <= $dailyLimit; ++$slot) {
                $this->write($this->db->table('ai_jobs')->ignore(true)->insert([
                    'tenant_id' => $tenantId, 'unit' => $unit, 'snapshot_id' => $snapshotId, 'actor_id' => $actorId,
                    'profile_revision' => $profileRevision, 'model' => $model, 'dedupe_key' => $key,
                    'budget_key' => $this->key([$tenantId, substr($this->now(), 0, 10), $slot]),
                    'status' => 'queued', 'attempts' => 0,
                    'created_at' => $this->now(), 'updated_at' => $this->now(), 'expires_at' => $snapshot['expires_at'],
                ]));
                $inserted = $this->db->affectedRows() === 1;
                if ($inserted) { $this->changed($tenantId, $unit); }
                $row = $this->table('ai_jobs', $tenantId, $unit)->where('dedupe_key', $key)->get()->getRowArray();
                if ($row !== null) {
                    return $this->jobMetadata($row + array_intersect_key($snapshot, ['analysis_code' => true])) + ['reused' => !$inserted];
                }
            }
            throw new AiFailure('daily_budget_exceeded', 429);
        });
    }

    public function listJobs(int $tenantId, string $unit, ?string $analysis = null): array
    {
        return $this->read(function () use ($tenantId, $unit, $analysis): array {
            $rows = $this->metadataTable('ai_jobs', $tenantId, $unit, $analysis)
                ->orderBy('ai_jobs.id', 'DESC')->limit(20)->get()->getResultArray();
            return array_map(fn (array $row): array => $this->jobMetadata($row), $rows);
        });
    }

    public function getInsight(int $tenantId, string $unit, int $id): array
    {
        return $this->read(function () use ($tenantId, $unit, $id): array {
            $row = $this->metadataTable('ai_insights', $tenantId, $unit)->where('ai_insights.id', $id)
                ->where('ai_insights.expires_at >', $this->now())->where('ai_insights.payload !=', null)->get()->getRowArray();
            if ($row === null) {
                throw new AiFailure('insight_not_found', 404);
            }
            return $this->insight($tenantId, $unit, $row);
        });
    }

    public function cancel(int $tenantId, string $unit, int $jobId): void
    {
        $this->transaction(function () use ($tenantId, $unit, $jobId): void {
            $this->expireJobs($tenantId, $unit);
            $this->write($this->table('ai_jobs', $tenantId, $unit)->where('id', $jobId)->where('status', 'queued')
                ->where('expires_at >', $this->now())->update([
                    'status' => 'cancelled', 'reason' => null, 'updated_at' => $this->now(),
                ]));
            if ($this->db->affectedRows() !== 1) {
                $exists = $this->table('ai_jobs', $tenantId, $unit)->where('id', $jobId)->countAllResults() !== 0;
                throw new AiFailure($exists ? 'invalid_job' : 'job_not_found', $exists ? 409 : 404);
            }
            $this->changed($tenantId, $unit);
        });
    }

    public function listInsights(int $tenantId, string $unit, ?string $analysis = null): array
    {
        return $this->read(function () use ($tenantId, $unit, $analysis): array {
            $rows = $this->metadataTable('ai_insights', $tenantId, $unit, $analysis)->where('ai_insights.expires_at >', $this->now())
                ->where('ai_insights.payload !=', null)->orderBy('ai_insights.id', 'DESC')->limit(20)->get()->getResultArray();
            return array_map(fn (array $row): array => $this->insight($tenantId, $unit, $row), $rows);
        });
    }

    public function job(int $tenantId, string $unit, int $jobId): array
    {
        return $this->read(function () use ($tenantId, $unit, $jobId): array {
            $row = $this->metadataTable('ai_jobs', $tenantId, $unit)->where('ai_jobs.id', $jobId)->get()->getRowArray();
            if ($row === null) {
                throw new AiFailure('job_not_found', 404);
            }
            $result = $this->jobMetadata($row);
            if ($row['status'] === 'succeeded') {
                $insight = $this->metadataTable('ai_insights', $tenantId, $unit)->where('ai_insights.job_id', $jobId)
                    ->where('ai_insights.expires_at >', $this->now())->where('ai_insights.payload !=', null)->get()->getRowArray();
                if ($insight !== null) {
                    $result['insight'] = $this->insight($tenantId, $unit, $insight);
                    $result['draft'] = $result['insight']['draft'];
                    $result['version'] = $result['insight']['version'];
                }
            }
            return $result;
        });
    }

    public function claim(int $tenantId, string $unit, string $profileRevision, int $leaseSeconds = 90): ?array
    {
        $this->scope($tenantId, $unit);
        if ($leaseSeconds < 1 || $leaseSeconds > 900) {
            throw new AiFailure('invalid_lease', 422);
        }
        $busy = false;
        try {
            return $this->transaction(function () use ($tenantId, $unit, $profileRevision, $leaseSeconds, &$busy): ?array {
                // active_key is tenant/profile-wide, so stale owners in other known
                // departments must be cleared before trying to acquire it.
                foreach (['clinic', 'guidance', 'bmg'] as $department) {
                    $this->expireJobs($tenantId, $department, $department === $unit);
                }
                $rows = $this->table('ai_jobs', $tenantId, $unit)->where('profile_revision', $profileRevision)
                    ->where('status', 'queued')->where('expires_at >', $this->now())
                    ->orderBy('id', 'ASC')->limit(10)->get()->getResultArray();
                foreach ($rows as $row) {
                    if ($row['profile_revision'] !== $profileRevision) {
                        continue;
                    }
                    $snapshot = $this->snapshot($tenantId, $unit, (int) $row['snapshot_id']);
                    $payload = $this->snapshotPayload($tenantId, $unit, $snapshot);
                    $token = bin2hex(random_bytes(32));
                    $ok = $this->table('ai_jobs', $tenantId, $unit)->where('id', $row['id'])->where('status', 'queued')->update([
                        'status' => 'running', 'attempts' => 1, 'lease_token' => $token,
                        'lease_expires_at' => $this->after($leaseSeconds), 'updated_at' => $this->now(),
                        'active_key' => $this->key([$tenantId, $profileRevision]),
                    ]);
                    if (!$ok) {
                        // A rejected unique lease reservation is unclaimable, never a
                        // reason to dispatch. Roll back even when the driver lost its
                        // error code while reporting the failed query.
                        $busy = true;
                        throw new AiFailure('worker_busy', 409);
                    }
                    if ($this->db->affectedRows() !== 1) {
                        continue;
                    }
                    $this->changed($tenantId, $unit);
                    $this->leases[$this->key([$tenantId, $unit, (int) $row['id']])] = $token;
                    return ['id' => (int) $row['id'], 'actor_id' => (int) $row['actor_id'],
                        'snapshot_id' => (int) $row['snapshot_id'], 'profile_revision' => $row['profile_revision'],
                        'model' => $row['model'], 'payload' => $payload, 'lease_token' => $token,
                        'lease_expires_at' => $this->after($leaseSeconds)] + array_intersect_key($snapshot, ['analysis_code' => true]);
                }
                return null;
            });
        } catch (AiFailure $e) {
            if ($busy) {
                return null;
            }
            throw $e;
        }
    }

    /** Read-only preflight; callers must still reserve immediately before transport. */
    public function assertLease(int $tenantId, string $unit, int $jobId, string $token, int $requiredSeconds = 1): void
    {
        $this->read(function () use ($tenantId, $unit, $jobId, $token, $requiredSeconds): void {
            $this->assertDispatchLease($tenantId, $unit, $jobId, $token, $requiredSeconds);
        });
    }

    /** One-shot outbound permission, never an idempotent/retryable reservation. */
    public function reserveDispatch(int $tenantId, string $unit, int $jobId, string $leaseToken, int $dailyLimit, int $requiredLeaseSeconds): void
    {
        if ($dailyLimit < 1 || $dailyLimit > 10000) {
            throw new AiFailure('invalid_job', 422);
        }
        $this->transaction(function () use ($tenantId, $unit, $jobId, $leaseToken, $dailyLimit, $requiredLeaseSeconds): void {
            // Lock before reads: serialize same-job retries on InnoDB and avoid
            // SQLite's deferred read-to-write upgrade during overlapping attempts.
            $this->write($this->table('ai_jobs', $tenantId, $unit)->where('id', $jobId)->update(['id' => $jobId]));
            $this->assertDispatchLease($tenantId, $unit, $jobId, $leaseToken, $requiredLeaseSeconds, true);
            if ($this->table('ai_dispatch_attempts', $tenantId, $unit)->where('job_id', $jobId)->countAllResults() !== 0) {
                throw new AiFailure('invalid_job', 409);
            }
            for ($slot = 1; $slot <= $dailyLimit; ++$slot) {
                $reservedAt = $this->now();
                $day = substr($reservedAt, 0, 10);
                $this->write($this->db->table('ai_dispatch_attempts')->ignore(true)->insert([
                    'tenant_id' => $tenantId, 'unit' => $unit, 'job_id' => $jobId,
                    'day' => $day, 'slot' => $slot, 'reserved_at' => $reservedAt,
                ]));
                if ($this->db->affectedRows() === 1) {
                    // Slot contention may have consumed the transport's lease budget.
                    $this->assertDispatchLease($tenantId, $unit, $jobId, $leaseToken, $requiredLeaseSeconds, true);
                    if (substr($this->now(), 0, 10) !== $day) {
                        throw new AiFailure('invalid_job', 409);
                    }
                    return;
                }
            }
            $this->assertDispatchLease($tenantId, $unit, $jobId, $leaseToken, $requiredLeaseSeconds, true);
            throw new AiFailure('daily_budget_exceeded', 429);
        });
    }

    /** A different store instance must pass the token returned by claim(). */
    public function complete(int $tenantId, string $unit, int $jobId, array $validatedDraft, ?string $leaseToken = null): void
    {
        $this->canonical($validatedDraft);
        $this->transaction(function () use ($tenantId, $unit, $jobId, $validatedDraft, $leaseToken): void {
            $row = $this->ownedJob($tenantId, $unit, $jobId, $leaseToken);
            $this->snapshot($tenantId, $unit, (int) $row['snapshot_id']);
            $this->transition($tenantId, $unit, $row, 'succeeded', null);
            $this->write($this->db->table('ai_insights')->insert([
                'tenant_id' => $tenantId, 'unit' => $unit, 'job_id' => $jobId, 'snapshot_id' => $row['snapshot_id'],
                'payload' => $this->cipher->encrypt($tenantId, $unit, 'insight:' . $jobId, $validatedDraft),
                'status' => 'draft', 'version' => 1, 'created_at' => $this->now(), 'updated_at' => $this->now(),
                'expires_at' => $this->after(90 * 86400),
            ]));
        });
        unset($this->leases[$this->key([$tenantId, $unit, $jobId])]);
    }

    public function fail(int $tenantId, string $unit, int $jobId, string $reason, bool $ambiguous = false, ?string $leaseToken = null): void
    {
        $allowed = ['generation_failed', 'provider_unavailable', 'provider_timeout', 'invalid_output', 'policy_blocked',
            'authorization_revoked', 'profile_changed', 'snapshot_expired', 'ai_disabled', 'budget_exceeded', 'outcome_unknown'];
        $reason = $ambiguous ? 'outcome_unknown' : (in_array($reason, $allowed, true) ? $reason : 'generation_failed');
        $this->transaction(function () use ($tenantId, $unit, $jobId, $reason, $ambiguous, $leaseToken): void {
            $row = $this->ownedJob($tenantId, $unit, $jobId, $leaseToken);
            $this->transition($tenantId, $unit, $row, $ambiguous ? 'outcome_unknown' : 'failed', $reason);
        });
        unset($this->leases[$this->key([$tenantId, $unit, $jobId])]);
    }

    public function review(int $tenantId, string $unit, int $insightId, int $actorId, int $expectedVersion, string $decision): void
    {
        if ($actorId < 1 || $expectedVersion < 1 || !in_array($decision, ['accept', 'reject', 'approve'], true)) {
            throw new AiFailure('invalid_review', 422);
        }
        $this->transaction(function () use ($tenantId, $unit, $insightId, $actorId, $expectedVersion, $decision): void {
            $row = $this->table('ai_insights', $tenantId, $unit)->where('id', $insightId)->get()->getRowArray();
            if ($row === null || $row['payload'] === null || $row['expires_at'] <= $this->now()) {
                throw new AiFailure('insight_not_found', 404);
            }
            if ((int) $row['version'] !== $expectedVersion) {
                throw new AiFailure('review_conflict', 409);
            }
            if (($decision === 'approve' && $row['status'] !== 'accepted')
                || ($decision === 'accept' && $row['status'] !== 'draft')
                || ($decision === 'reject' && !in_array($row['status'], ['draft', 'accepted'], true))) {
                throw new AiFailure('invalid_review_transition', 409);
            }
            $status = ['accept' => 'accepted', 'reject' => 'rejected', 'approve' => 'approved'][$decision];
            $this->write($this->table('ai_insights', $tenantId, $unit)->where('id', $insightId)
                ->where('version', $expectedVersion)->where('status', $row['status'])
                ->where('payload !=', null)->where('expires_at >', $this->now())->update([
                    'status' => $status, 'version' => $expectedVersion + 1, 'updated_at' => $this->now(),
                ]));
            if ($this->db->affectedRows() !== 1) {
                throw new AiFailure('review_conflict', 409);
            }
            $this->write($this->db->table('ai_reviews')->insert([
                'tenant_id' => $tenantId, 'unit' => $unit, 'insight_id' => $insightId, 'actor_id' => $actorId,
                'version' => $expectedVersion + 1, 'decision' => $decision, 'reason' => 'staff_' . $decision,
                'created_at' => $this->now(),
            ]));
            $this->changed($tenantId, $unit);
        });
    }

    /** Bounded payload erasure. Freeze fingerprints, budget reservations and review audit survive. */
    public function purgeExpired(int $tenantId, string $unit): array
    {
        return $this->transaction(function () use ($tenantId, $unit): array {
            $this->expireJobs($tenantId, $unit);
            $counts = [];
            foreach (['ai_snapshots', 'ai_insights'] as $table) {
                $rows = $this->table($table, $tenantId, $unit)->select('id')->where('expires_at <=', $this->now())
                    ->where('payload !=', null)->orderBy('id')->limit(100)->get()->getResultArray();
                $counts[$table] = 0;
                if ($rows !== []) {
                    $this->write($this->table($table, $tenantId, $unit)->whereIn('id', array_column($rows, 'id'))->update(['payload' => null]));
                    $counts[$table] = $this->db->affectedRows();
                    if ($counts[$table] > 0) { $this->changed($tenantId, $unit); }
                }
            }
            return $counts;
        });
    }

    private function expireJobs(int $tenantId, string $unit, bool $expireQueued = true): void
    {
        $this->write($this->table('ai_jobs', $tenantId, $unit)->where('status', 'running')
            ->where('lease_expires_at <=', $this->now())->update([
                'status' => 'outcome_unknown', 'reason' => 'outcome_unknown', 'active_key' => null,
                'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $this->now(),
            ]));
        if ($this->db->affectedRows() > 0) { $this->changed($tenantId, $unit); }
        if (!$expireQueued) { return; }
        $this->write($this->table('ai_jobs', $tenantId, $unit)->where('status', 'queued')
            ->where('expires_at <=', $this->now())->update(['status' => 'expired', 'reason' => 'snapshot_expired', 'updated_at' => $this->now()]));
        if ($this->db->affectedRows() > 0) { $this->changed($tenantId, $unit); }
    }

    private function freezeMonths(int $tenantId, string $unit, array $payload): void
    {
        $this->freezeCategoryMask($tenantId, $unit, $payload);
        $releases = [];
        $denominator = match ($payload['analysis_code'] ?? '') {
            'clinic.complaints' => 'completed_encounters',
            'guidance.themes' => 'completed_sessions',
            'bmg.rotations' => 'rotation_reports',
            default => null,
        };
        foreach ($payload['metrics'] as $metric) {
            if (($metric['quality_status'] ?? 'usable') !== 'usable') {
                continue;
            }
            foreach (['comparison_period' => 'previous', 'period' => 'current'] as $period => $side) {
                $month = $payload[$period]['label'];
                if (is_int($metric[$side . '_value'])) {
                    $releases[] = [$metric['metric_code'], $month, $metric[$side . '_value']];
                }
                if ($denominator !== null && isset($metric[$side . '_denominator'])
                    && is_int($metric[$side . '_denominator'])) {
                    $releases[] = [$denominator, $month, $metric[$side . '_denominator']];
                }
            }
        }
        // After mask reservations, lock value metric codes in the same order,
        // then oldest month. Keep duplicates: disagreeing totals must also fail.
        usort($releases, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
        foreach ($releases as [$code, $month, $count]) {
            $this->freezeRelease($tenantId, $unit, $code, $month, $count);
        }
    }

    private function freezeCategoryMask(int $tenantId, string $unit, array $payload): void
    {
        $analysis = $payload['analysis_code'] ?? '';
        $code = self::CATEGORY_MASK_CODES[$analysis] ?? null;
        if ($code === null) {
            return;
        }
        // The pair emits one ordered mask for BOTH months. Freeze it before any
        // values so overlapping reports cannot accumulate different safe subsets.
        $mask = [self::CATEGORY_POLICY_VERSION, array_column(array_slice($payload['metrics'], 1), 'metric_code')];
        $categoryCodes = array_column(array_slice(AnalysisCatalog::metrics($analysis), 1), 'metric_code');
        foreach ([$payload['comparison_period']['label'], $payload['period']['label']] as $month) {
            if ($this->freezeRelease($tenantId, $unit, $code, $month, $mask)) {
                // Never retroactively bless category history without a guard. Use
                // a current read after reserving the marker, including on InnoDB.
                $query = $this->table('ai_releases', $tenantId, $unit)->where('month', $month)
                    ->whereIn('metric_code', $categoryCodes)->limit(1)->getCompiledSelect();
                $row = $this->db->query($query . ($this->db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : ''))->getRowArray();
                if ($row !== null) {
                    throw new AiFailure('snapshot_changed_requires_review', 409);
                }
            }
        }
    }

    /** Returns whether this transaction reserved a previously unpublished release. */
    private function freezeRelease(int $tenantId, string $unit, string $code, string $month, int|array $value): bool
    {
        // Do not change this domain or array encoding: legacy freezes survive upgrades.
        $fingerprint = $this->cipher->fingerprint($tenantId, $unit, 'monthly_release',
            $this->canonical([$code, $month, $value]));
        $this->write($this->db->table('ai_releases')->ignore(true)->insert([
            'tenant_id' => $tenantId, 'unit' => $unit, 'metric_code' => $code,
            'month' => $month, 'fingerprint' => $fingerprint, 'created_at' => $this->now(),
        ]));
        $inserted = $this->db->affectedRows() === 1;
        $query = $this->table('ai_releases', $tenantId, $unit)->where('metric_code', $code)
            ->where('month', $month)->getCompiledSelect();
        // A locking/current read is essential on InnoDB: a competing insert
        // may have committed after an earlier repeatable-read snapshot.
        $row = $this->db->query($query . ($this->db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : ''))->getRowArray();
        if ($row === null) {
            throw new AiFailure('store_unavailable', 503);
        }
        if (!hash_equals($row['fingerprint'], $fingerprint)) {
            throw new AiFailure('snapshot_changed_requires_review', 409);
        }
        return $inserted;
    }

    private function snapshot(int $tenantId, string $unit, int $id, bool $lock = false): array
    {
        $query = $this->table('ai_snapshots', $tenantId, $unit)->where('id', $id);
        $row = $lock && $this->db->DBDriver === 'MySQLi'
            ? $this->db->query($query->getCompiledSelect() . ' FOR UPDATE')->getRowArray()
            : $query->get()->getRowArray();
        if ($row === null) {
            throw new AiFailure('snapshot_not_found', 404);
        }
        if ($row['expires_at'] <= $this->now() || $row['payload'] === null) {
            throw new AiFailure('snapshot_expired', 409);
        }
        return $row;
    }

    private function snapshotPayload(int $tenantId, string $unit, array $row): array
    {
        return $this->cipher->decrypt($tenantId, $unit, 'snapshot:' . $row['fingerprint'], $row['payload']);
    }

    private function assertDispatchLease(int $tenantId, string $unit, int $jobId, string $token, int $requiredSeconds, bool $lockSnapshot = false): void
    {
        if ($requiredSeconds < 1 || $requiredSeconds > 900) {
            throw new AiFailure('invalid_lease', 422);
        }
        $row = $this->ownedJob($tenantId, $unit, $jobId, $token);
        $this->snapshot($tenantId, $unit, (int) $row['snapshot_id'], $lockSnapshot);
        if ($row['lease_expires_at'] < $this->after($requiredSeconds)) {
            throw new AiFailure('lease_lost', 409);
        }
    }

    private function ownedJob(int $tenantId, string $unit, int $jobId, ?string $token): array
    {
        $token ??= $this->leases[$this->key([$tenantId, $unit, $jobId])] ?? '';
        $row = $this->table('ai_jobs', $tenantId, $unit)->where('id', $jobId)->get()->getRowArray();
        if ($row === null) {
            throw new AiFailure('job_not_found', 404);
        }
        if ($row['status'] !== 'running' || $row['lease_expires_at'] <= $this->now()
            || $token === '' || !hash_equals((string) $row['lease_token'], $token)) {
            throw new AiFailure('lease_lost', 409);
        }
        return $row;
    }

    private function transition(int $tenantId, string $unit, array $row, string $status, ?string $reason): void
    {
        $this->write($this->table('ai_jobs', $tenantId, $unit)->where('id', $row['id'])->where('status', 'running')
            ->where('lease_token', $row['lease_token'])->where('lease_expires_at >', $this->now())->update([
                'status' => $status, 'reason' => $reason, 'active_key' => null,
                'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $this->now(),
            ]));
        if ($this->db->affectedRows() !== 1) {
            throw new AiFailure('lease_lost', 409);
        }
        $this->changed($tenantId, $unit);
    }

    private function jobMetadata(array $row): array
    {
        $result = array_intersect_key($row, array_flip(['id', 'snapshot_id', 'actor_id', 'profile_revision', 'model',
            'status', 'reason', 'attempts', 'created_at', 'updated_at', 'expires_at', 'analysis_code']));
        foreach (['id', 'snapshot_id', 'actor_id', 'attempts'] as $key) {
            $result[$key] = (int) $result[$key];
        }
        return $result;
    }

    private function insight(int $tenantId, string $unit, array $row): array
    {
        return ['id' => (int) $row['id'], 'job_id' => (int) $row['job_id'], 'snapshot_id' => (int) $row['snapshot_id'],
            'status' => $row['status'], 'version' => (int) $row['version'], 'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'], 'expires_at' => $row['expires_at'],
            'draft' => $this->cipher->decrypt($tenantId, $unit, 'insight:' . $row['job_id'], $row['payload'])]
                        + array_intersect_key($row, ['analysis_code' => true]);
    }

    /** Analysis metadata comes only from a snapshot with the same tenant and unit. */
    private function metadataTable(string $table, int $tenantId, string $unit, ?string $analysis = null): BaseBuilder
    {
        $this->scope($tenantId, $unit);
        if (!$this->supportsAnalysisScope()) {
            $query = $this->table($table, $tenantId, $unit);
            if ($analysis !== null && $analysis !== 'clinic.activity') {
                $query->where('1 = 0', null, false);
            }
            return $query;
        }
        $query = $this->db->table($table)->select($table . '.*')->select('analysis_snapshot.analysis_code')
            ->join('ai_snapshots analysis_snapshot', 'analysis_snapshot.id = ' . $table . '.snapshot_id'
                . ' AND analysis_snapshot.tenant_id = ' . $table . '.tenant_id'
                . ' AND analysis_snapshot.unit = ' . $table . '.unit')
            ->where($table . '.tenant_id', $tenantId)->where($table . '.unit', $unit);
        if ($analysis !== null) {
            $query->where('analysis_snapshot.analysis_code', $analysis);
        }
        return $query;
    }

    private function table(string $table, int $tenantId, string $unit): BaseBuilder
    {
        $this->scope($tenantId, $unit);
        return $this->db->table($table)->where('tenant_id', $tenantId)->where('unit', $unit);
    }

    private function scope(int $tenantId, string $unit): void
    {
        if ($tenantId < 1 || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $unit)) {
            throw new AiFailure('invalid_scope', 422);
        }
    }

    private function canonical(array $payload): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            return array_map($sort, $value);
        };
        try {
            $json = json_encode($sort($payload), JSON_THROW_ON_ERROR);
            if (strlen($json) > 32000) {
                throw new AiFailure('invalid_payload', 422);
            }
            return $json;
        } catch (Throwable) {
            throw new AiFailure('invalid_payload', 422);
        }
    }

    private function key(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function now(): string
    {
        return $this->after(0);
    }

    private function after(int $seconds): string
    {
        $value = ($this->clock)();
        $time = $value instanceof DateTimeInterface ? $value->getTimestamp() : (int) $value;
        return (new DateTimeImmutable('@' . ($time + $seconds)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function write(bool $success): void
    {
        if (!$success) {
            throw new AiFailure('store_unavailable', 503);
        }
    }

    private function read(Closure $work): mixed
    {
        if (!$this->ready()) {
            throw new AiFailure('store_unavailable', 503);
        }
        try {
            return $work();
        } catch (AiFailure $e) {
            throw $e;
        } catch (Throwable) {
            throw new AiFailure('store_unavailable', 503);
        }
    }

    private function changed(int $tenantId, string $unit): void
    {
        $this->invalidations[$tenantId . ':' . $unit] = [$tenantId, $unit];
    }

    private function transaction(Closure $work): mixed
    {
        return $this->read(function () use ($work): mixed {
            if ($this->db->transDepth !== 0) {
                throw new AiFailure('independent_transaction_required', 503);
            }
            $this->invalidations = [];
            if (!$this->db->transBegin()) {
                throw new AiFailure('store_unavailable', 503);
            }
            try {
                $result = $work();
                if (!$this->db->transStatus() || !$this->db->transCommit()) {
                    throw new AiFailure('store_unavailable', 503);
                }
                $hints = $this->invalidations;
                $this->invalidations = [];
                foreach ($hints as [$tenantId, $unit]) {
                    try { ($this->notify)($tenantId, $unit); }
                    catch (Throwable) { /* Committed mutations must survive transport failures. */ }
                }
                return $result;
            } catch (Throwable $e) {
                $this->invalidations = [];
                if ($this->db->transDepth > 0) {
                    $this->db->transRollback();
                }
                $this->db->resetTransStatus();
                throw $e;
            }
        });
    }
}

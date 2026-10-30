<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\AuthenticatedUser;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class InsightWorker
{
    private readonly Profile $profile;
    private readonly InsightStore $store;
    private readonly InsightGenerator $generator;
    private readonly Closure $identity;
    private readonly Closure $dispatchGate;
    private readonly bool $reloadProfile;

    public function __construct(?Profile $profile = null, ?InsightStore $store = null, ?InsightGenerator $generator = null, ?callable $identity = null, ?callable $dispatchGate = null)
    {
        $this->reloadProfile = $profile === null;
        $this->profile = $profile ?? Profile::fromConfig();
        $this->store = $store ?? new InsightStore();
        $this->generator = $generator ?? new InsightGenerator($this->profile);
        $this->identity = Closure::fromCallable($identity ?? (new AuthenticatedUser())->forIdentity(...));
        $this->dispatchGate = Closure::fromCallable($dispatchGate ?? static function (Profile $profile, int $tenantId, string $unit): void {
            (new DeploymentControl())->assertAllowed($profile, $tenantId, $unit);
        });
    }

    /** One shared job/time budget across the selected unit or all approved units. No retries. */
    public function run(int $tenantId, ?string $unit = null): array
    {
        $deadline = hrtime(true) + 120_000_000_000;
        $result = ['processed' => 0, 'failed' => 0, 'outcome_unknown' => 0];
        $units = $unit === null ? $this->profile->approvedUnits() : [$unit];
        if ($units === []) { throw new AiFailure('unit_not_approved'); }
        foreach ($units as $selected) { $this->assertReady($tenantId, $selected); }
        $config = clone $this->profile->config;
        $leaseSeconds = max($config->requestTimeoutSeconds + 45, 90);
        $claimed = 0;
        while ($claimed < $config->maxJobsPerRun && $units !== []) {
            // Preserve local finalization headroom as well as the entire provider timeout.
            if (hrtime(true) + ($config->requestTimeoutSeconds + 5) * 1_000_000_000 >= $deadline) {
                break;
            }
            $selected = array_shift($units);
            try {
                $this->assertReady($tenantId, $selected);
            } catch (AiFailure) {
                // Revocation stops further claims; already-finalized jobs keep their recorded outcomes.
                break;
            }
            $job = $this->store->claim($tenantId, $selected, $this->profile->revision(), $leaseSeconds);
            if ($job === null) {
                continue;
            }
            $units[] = $selected;
            ++$claimed;
            $started = false;
            try {
                $analysis = $this->assertSnapshot($job['payload'], $selected, $job['analysis_code'] ?? null);
                $this->assertAuthority($job['actor_id'], $tenantId, $selected, $analysis);
                if ($job['model'] !== $config->model || $job['profile_revision'] !== $this->profile->revision()) {
                    throw new AiFailure('revision_not_approved');
                }
                $this->assertTimeRemaining($deadline, $config->requestTimeoutSeconds);
                $draft = $this->generator->generate($job['payload'], function () use ($tenantId, $selected, $analysis, $job, $config, $deadline, &$started): void {
                    if ($started) {
                        throw new AiFailure('invalid_job', 409);
                    }
                    // ChatClient invokes this after bounded DNS, immediately before its only POST.
                    $this->assertReady($tenantId, $selected);
                    $this->assertAuthority($job['actor_id'], $tenantId, $selected, $analysis);
                    $this->assertSnapshot($job['payload'], $selected, $analysis);
                    $this->assertTimeRemaining($deadline, $config->requestTimeoutSeconds);
                    $requiredLeaseSeconds = $config->requestTimeoutSeconds + 10;
                    $this->store->assertLease($tenantId, $selected, $job['id'], $job['lease_token'], $requiredLeaseSeconds);
                    $this->store->reserveDispatch($tenantId, $selected, $job['id'], $job['lease_token'],
                        $config->dailyRequestLimit, $requiredLeaseSeconds);
                    // Budget contention may wait on another transaction. Recheck authority,
                    // control, deadline and lease after that wait, before anything can be sent.
                    $this->assertTimeRemaining($deadline, $config->requestTimeoutSeconds);
                    $this->assertReady($tenantId, $selected);
                    $this->assertAuthority($job['actor_id'], $tenantId, $selected, $analysis);
                    $this->store->assertLease($tenantId, $selected, $job['id'], $job['lease_token'], $requiredLeaseSeconds);
                    // Authority/lease reads may wait too; revocation during those reads still blocks the POST.
                    $this->assertReady($tenantId, $selected);
                    $this->assertTimeRemaining($deadline, $config->requestTimeoutSeconds);
                    $started = true;
                });
                $this->assertAuthority($job['actor_id'], $tenantId, $selected, $analysis);
                $this->assertSnapshot($job['payload'], $selected, $analysis);
                $this->assertReady($tenantId, $selected);
                $this->store->complete($tenantId, $selected, $job['id'], $draft, $job['lease_token']);
                ++$result['processed'];
            } catch (Throwable $error) {
                $ambiguous = $started && (!$error instanceof AiFailure
                    || in_array($error->reason, ['transport_failed', 'response_too_large', 'store_unavailable', 'lease_lost'], true));
                try {
                    $this->store->fail($tenantId, $selected, $job['id'], self::failureReason($error), $ambiguous, $job['lease_token']);
                } catch (Throwable) {
                    // Never reclaim a lost lease. Expiry may record outcome_unknown, but a blocked preflight sent nothing.
                    ++$result[$started ? 'outcome_unknown' : 'failed'];
                    break;
                }
                ++$result[$ambiguous ? 'outcome_unknown' : 'failed'];
            }
        }

        return $result;
    }

    private function assertReady(int $tenantId, string $unit): void
    {
        // A fresh config bypasses CI's shared object cache, not dotenv's process-level environment.
        // DeploymentControl independently checks the on-disk pause, revision and environment stamp.
        $fresh = $this->reloadProfile ? Profile::fromConfig() : $this->profile;
        $fresh->assertDispatchAllowed($tenantId, $unit);
        $this->generator->assertProfile($fresh);
        try {
            if (($this->dispatchGate)($fresh, $tenantId, $unit) !== null) {
                throw new AiFailure('dispatch_paused');
            }
        } catch (AiFailure $error) {
            throw $error;
        } catch (Throwable) {
            throw new AiFailure('dispatch_paused');
        }
    }

    private function assertTimeRemaining(int $deadline, int $requestTimeout): void
    {
        if (hrtime(true) + ($requestTimeout + 5) * 1_000_000_000 >= $deadline) {
            throw new AiFailure('job_expired');
        }
    }

    private function assertAuthority(int $actorId, int $tenantId, string $unit, string $analysis): void
    {
        $actor = ($this->identity)($actorId, $tenantId, $unit);
        // AiAccess merges fresh tenant/unit grants through AiGrantStore on every assertion.
        $actor = AiAccess::assertActor(is_array($actor) ? $actor : null, $tenantId, 'ai.insights.generate', $unit);
        if ((int) $actor['id'] !== $actorId || !in_array(AnalysisCatalog::get($analysis)['permission'], $actor['permissions'], true)) {
            throw new AiFailure('access_denied', 403);
        }
    }

    private function assertSnapshot(array $payload, string $unit, ?string $expectedAnalysis): string
    {
        DisclosurePolicy::validatePayload($payload);
        $analysis = $payload['schema_version'] === '1' ? 'clinic.activity' : $payload['analysis_code'];
        if ($payload['department'] !== $unit || AnalysisCatalog::get($analysis)['unit'] !== $unit
            || ($expectedAnalysis !== null && $expectedAnalysis !== $analysis)) {
            throw new AiFailure('invalid_snapshot', 422);
        }
        $month = new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('Asia/Manila'));
        if ($payload['period']['label'] !== $month->modify('-1 month')->format('Y-m')
            || $payload['comparison_period']['label'] !== $month->modify('-2 months')->format('Y-m')) {
            throw new AiFailure('snapshot_expired', 409);
        }
        return $analysis;
    }

    private static function failureReason(Throwable $error): string
    {
        if (!$error instanceof AiFailure) {
            return 'generation_failed';
        }

        return match ($error->reason) {
            'authentication_required', 'access_denied' => 'authorization_revoked',
            'disabled', 'dispatch_paused' => 'ai_disabled',
            'invalid_config', 'revision_not_approved', 'compatibility_required', 'library_not_approved' => 'profile_changed',
            'governance_required', 'tenant_not_approved', 'unit_not_approved', 'http_dispatch_not_approved',
            'endpoint_not_allowed', 'address_not_allowed', 'invalid_snapshot', 'invalid_request', 'invalid_scope' => 'policy_blocked',
            'snapshot_expired', 'job_expired' => 'snapshot_expired',
            'output_rejected', 'invalid_response', 'completion_rejected', 'structured_output_invalid' => 'invalid_output',
            'provider_unavailable' => 'provider_unavailable',
            'daily_budget_exceeded', 'dispatch_budget_exceeded' => 'budget_exceeded',
            default => 'generation_failed',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** HTTP-facing use cases; no endpoint is contacted from this service. */
final class InsightApplication
{
    private readonly Profile $profile;
    private readonly InsightStore $store;
    private readonly ClinicSnapshotBuilder $collector;
    private readonly DeploymentControl $control;
    private readonly DepartmentSnapshotBuilder $departmentCollector;

    public function __construct(?Profile $profile = null, ?InsightStore $store = null, ?ClinicSnapshotBuilder $collector = null, ?DeploymentControl $control = null,
        private readonly string $unit = 'clinic', ?DepartmentSnapshotBuilder $departmentCollector = null)
    {
        $this->profile = $profile ?? Profile::fromConfig();
        $this->store = $store ?? new InsightStore();
        $this->collector = $collector ?? new ClinicSnapshotBuilder(threshold: $this->profile->config->minimumContributors);
        $this->control = $control ?? new DeploymentControl();
        $this->departmentCollector = $departmentCollector ?? new DepartmentSnapshotBuilder(threshold: $this->profile->config->minimumContributors);
        if (AnalysisCatalog::forUnit($this->unit) === []) { throw new AiFailure('invalid_request', 422); }
    }

    public function panel(array $actor): array
    {
        $this->authorize($actor, 'ai.insights.read');
        $data = ['unit' => $this->unit, 'analyses' => AnalysisCatalog::forUnit($this->unit),
            'basePath' => match ($this->unit) {
                'guidance' => 'guidance/insights', 'bmg' => 'facilities/insights', default => 'clinic/insights',
            },
            'profile' => $this->profile->publicStatus(), 'ready' => false, 'reason' => null,
            'jobs' => [], 'insights' => [], 'canGenerate' => false, 'canReview' => false, 'canApprove' => false];
        foreach (['canGenerate' => 'ai.insights.generate', 'canReview' => 'ai.insights.review', 'canApprove' => 'ai.insights.approve'] as $key => $permission) {
            try { $this->authorize($actor, $permission); $data[$key] = true; }
            catch (AiFailure) { /* A hidden action is not an authorization substitute. */ }
        }
        try {
            $this->assertAnalyticsAllowed((int) $actor['tenant_id']);
            if (!$this->store->ready()) { throw new AiFailure('store_unavailable'); }
            $data['ready'] = true;
            $revision = $this->profile->revision();
            $data['jobs'] = $this->store->listJobs((int) $actor['tenant_id'], $this->unit);
            // Old configuration output cannot be released or approved under a new policy.
            $data['insights'] = array_values(array_filter($this->store->listInsights((int) $actor['tenant_id'], $this->unit),
                static fn (array $row): bool => ($row['draft']['provenance']['profile_revision'] ?? null) === $revision));
            try {
                $this->profile->assertDispatchAllowed((int) $actor['tenant_id'], $this->unit);
                (new InsightGenerator($this->profile))->assertProfile($this->profile);
                $this->control->assertAllowed($this->profile, (int) $actor['tenant_id'], $this->unit);
            } catch (AiFailure $error) { $data['reason'] = $error->reason; }
        } catch (AiFailure $error) { $data['reason'] = $error->reason; }
        return $data;
    }

    /** A local, explicit baseline action; never runs during dashboard reads or polling. */
    public function prepare(array $actor, ?string $analysis = null): array
    {
        $this->authorize($actor, 'ai.insights.generate');
        $analysis = $this->analysis($analysis);
        $this->assertAnalyticsAllowed((int) $actor['tenant_id']);
        if (!$this->store->ready() || ($analysis !== 'clinic.activity' && !$this->store->supportsAnalysisScope())) {
            throw new AiFailure('store_unavailable');
        }
        $payload = $analysis === 'clinic.activity' ? $this->collector->build((int) $actor['tenant_id'])
            : $this->departmentCollector->build((int) $actor['tenant_id'], $analysis);
        return $this->store->saveSnapshot((int) $actor['tenant_id'], $this->unit, $payload)['payload'];
    }

    public function generate(array $actor, ?string $analysis = null): array
    {
        $this->authorize($actor, 'ai.insights.generate');
        $analysis = $this->analysis($analysis);
        $this->profile->assertDispatchAllowed((int) $actor['tenant_id'], $this->unit);
        (new InsightGenerator($this->profile))->assertProfile($this->profile);
        $this->control->assertAllowed($this->profile, (int) $actor['tenant_id'], $this->unit);
        $payload = $this->prepare($actor, $analysis);
        $snapshot = $this->store->saveSnapshot((int) $actor['tenant_id'], $this->unit, $payload);
        return $this->store->enqueue((int) $actor['tenant_id'], $this->unit, $snapshot['id'], (int) $actor['id'],
            $this->profile->revision(), $this->profile->config->model, $this->profile->config->dailyRequestLimit);
    }

    public function review(array $actor, int $id, int $version, string $decision): void
    {
        $permission = $decision === 'approve' ? 'ai.insights.approve' : 'ai.insights.review';
        $this->authorize($actor, $permission);
        $this->assertAnalyticsAllowed((int) $actor['tenant_id']);
        $row = $this->store->getInsight((int) $actor['tenant_id'], $this->unit, $id);
        if (($row['draft']['provenance']['profile_revision'] ?? null) !== $this->profile->revision()) {
            throw new AiFailure('endpoint_changed', 409);
        }
        if ($decision === 'approve' && ($row['draft']['programs'] ?? []) === []) {
            throw new AiFailure('invalid_review_transition', 409);
        }
        $this->store->review((int) $actor['tenant_id'], $this->unit, $id, (int) $actor['id'], $version, $decision);
    }

    public function cancel(array $actor, int $id): void
    {
        $this->authorize($actor, 'ai.insights.generate');
        $this->assertAnalyticsAllowed((int) $actor['tenant_id']);
        $this->store->cancel((int) $actor['tenant_id'], $this->unit, $id);
    }

    private function analysis(?string $analysis): string
    {
        $analysis ??= AnalysisCatalog::forUnit($this->unit)[0]['code'];
        if (AnalysisCatalog::get($analysis)['unit'] !== $this->unit) { throw new AiFailure('invalid_request', 422); }
        return $analysis;
    }

    private function authorize(array $actor, string $permission): void
    {
        AiAccess::assertActor($actor, (int) ($actor['tenant_id'] ?? 0), $permission, $this->unit);
        $source = AnalysisCatalog::forUnit($this->unit)[0]['permission'];
        if ($permission === 'ai.insights.generate' && !in_array($source, $actor['permissions'] ?? [], true)) {
            throw new AiFailure('access_denied', 403);
        }
    }

    private function assertAnalyticsAllowed(int $tenantId): void
    {
        $this->profile->assertUnchanged();
        $c = $this->profile->config;
        if (!$c->governanceApproved) { throw new AiFailure('governance_required'); }
        if (!in_array($this->unit, $this->profile->approvedUnits(), true)) { throw new AiFailure('unit_not_approved'); }
        if (!in_array((string) $tenantId, Profile::csv($c->approvedTenants), true)) { throw new AiFailure('tenant_not_approved'); }
        if (!hash_equals($this->profile->revision(), $c->approvedRevision)) { throw new AiFailure('revision_not_approved'); }
    }
}

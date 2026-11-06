<?php
require __DIR__ . '/_scope.php';

$analysisTitles = array_column($analyses, 'title', 'code');
$activeStatuses = ['queued', 'running'];
$activeJobs = array_values(array_filter($jobs, static fn (array $job): bool => in_array($job['status'], $activeStatuses, true)
    && $job['profile_revision'] === $profile['revision']));
$historyJobs = array_values(array_filter($jobs, static fn (array $job): bool => !in_array($job['status'], $activeStatuses, true)
    || $job['profile_revision'] !== $profile['revision']));
$pending = $activeJobs;
$stateMessage = match ($reason) {
    null => 'AI setup approved',
    'disabled' => 'AI requests turned off',
    'dispatch_paused' => 'AI requests paused',
    'store_unavailable', 'encryption_unavailable' => 'Secure insight storage unavailable',
    'governance_required', 'tenant_not_approved', 'unit_not_approved' => 'Privacy or department approval required',
    'revision_not_approved', 'compatibility_required', 'library_not_approved', 'endpoint_changed' => 'Current AI setup needs approval',
    default => 'Insights unavailable',
};
$stateHelp = match ($reason) {
    null => 'Eligibility and worker availability are checked separately.',
    'disabled' => 'Local previews still require approval and privacy checks.',
    'dispatch_paused' => 'Ask an administrator to review the setup. Local previews have separate approval requirements.',
    'store_unavailable', 'encryption_unavailable' => 'Ask an administrator for help. Other department functions are unaffected.',
    default => 'Ask an administrator for help.',
};
$jobStatuses = [
    'queued' => 'Waiting for a worker', 'running' => 'Processing', 'completed' => 'Draft ready',
    'succeeded' => 'Draft ready', 'failed' => 'Could not create a draft', 'cancelled' => 'Cancelled',
    'expired' => 'Expired', 'outcome_unknown' => 'Result could not be confirmed',
];
$jobReasons = [
    'generation_failed' => 'The draft could not be created.',
    'provider_unavailable' => 'The AI service was unavailable.',
    'provider_auth_failed' => 'The provider rejected authentication. Ask an administrator to check the saved connection.',
    'provider_rate_limited' => 'The provider rate limit was reached. This request will not be replayed automatically.',
    'provider_rejected' => 'The provider rejected the model or request options. Ask an administrator to check compatibility.',
    'provider_timeout' => 'The AI service did not respond in time.',
    'invalid_output' => 'The AI response did not pass validation.',
    'policy_blocked' => 'This request did not pass safety checks.',
    'authorization_revoked' => 'Permission to process this request was removed.',
    'profile_changed' => 'The AI setup changed before this request finished.',
    'snapshot_expired' => 'The summary for this request expired.',
    'ai_disabled' => 'AI requests were turned off.',
    'budget_exceeded' => 'The daily AI request limit was reached.',
    'outcome_unknown' => 'The result could not be confirmed.',
];
$progressStages = ['queued' => 'Queued', 'generating' => 'Generating and checking', 'ready' => 'Ready for review'];
$insightStatuses = [
    'draft' => 'Awaiting review', 'accepted' => 'Reviewed and accepted',
    'rejected' => 'Rejected', 'approved' => 'Program draft approved',
];
$time = static function (string $value): string {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
    return $date ? $date->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d H:i P') : 'Unavailable';
};
$selectedAnalysis = $analyses[0]['code'];

$renderJob = static function (array $job) use ($analysisTitles, $jobStatuses, $jobReasons, $progressStages, $profile, $reason, $canGenerate, $basePath, $time): void {
    $progressStage = match ($job['status']) {
        'queued' => 'queued', 'running' => 'generating', 'succeeded', 'completed' => 'ready', default => 'stopped',
    };
    $currentStep = array_search($progressStage, array_keys($progressStages), true);
    $older = $job['profile_revision'] !== $profile['revision'];
?>
  <li class="ai-insights__request" data-ai-request="<?= (int) $job['id'] ?>" data-ai-progress="<?= esc($progressStage, 'attr') ?>" data-ai-key="request-<?= (int) $job['id'] ?>">
    <div class="ai-insights__request-copy">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <strong><?= esc($analysisTitles[$job['analysis_code'] ?? ''] ?? 'Analysis') ?> #<?= (int) $job['id'] ?></strong>
        <span class="badge text-bg-light"><?= esc($jobStatuses[$job['status']] ?? 'Status unavailable') ?></span>
        <?php if ($older): ?><span class="badge text-bg-secondary">Older configuration</span><?php endif; ?>
      </div>
      <div class="ai-insights__progress-status" data-ai-progress-status>
        <?php if ($progressStage === 'generating'): ?>
        <span class="spinner-border spinner-border-sm" role="img" aria-label="Generating and checking; progress is indeterminate" data-ai-progress-spinner></span>
        <span>Generating and checking. No partial draft is available.</span>
        <?php elseif ($progressStage === 'queued'): ?>
        <i class="bi bi-clock" aria-hidden="true"></i><span><?= $older ? 'Older setup: this request cannot be processed by the current worker and will expire.' : 'Waiting for a worker; processing has not started.' ?></span>
        <?php elseif ($progressStage === 'ready'): ?>
        <i class="bi bi-check-circle" aria-hidden="true"></i><span>Ready for review. Generation and checks finished.</span>
        <?php else: ?>
        <i class="bi bi-dash-circle" aria-hidden="true"></i><span><?= esc(match ($job['status']) {
            'failed' => 'No draft is ready for review.', 'cancelled' => 'This waiting request was cancelled.',
            'expired' => 'This request expired; no draft is ready for review.',
            'outcome_unknown' => 'Completion cannot be confirmed; no draft is ready for review.',
            default => 'The request status is unavailable; completion cannot be confirmed.',
        }) ?></span>
        <?php endif; ?>
      </div>
      <ol class="ai-insights__progress-steps" aria-label="Draft request stages" data-ai-progress-steps>
        <?php foreach ($progressStages as $stage => $stageLabel):
            $step = array_search($stage, array_keys($progressStages), true);
            $stepState = $currentStep === false ? 'not-confirmed' : ($step < $currentStep ? 'complete' : ($step === $currentStep ? 'current' : 'pending'));
        ?>
        <li data-ai-stage="<?= esc($stage, 'attr') ?>" data-ai-stage-state="<?= esc($stepState, 'attr') ?>"<?= $stepState === 'current' ? ' aria-current="step"' : '' ?>>
          <span aria-hidden="true"></span><?= esc($stageLabel) ?>
        </li>
        <?php endforeach; ?>
      </ol>
      <small class="text-muted">Created <?= esc($time($job['created_at'])) ?> Philippine time<?php if ($job['reason']): ?> · <?= esc($jobReasons[$job['reason']] ?? 'This request could not proceed. Ask an administrator for help.') ?><?php endif; ?></small>
      <?php if ($job['status'] === 'outcome_unknown'): ?><p class="small mb-0 mt-1">The AI service may have processed or charged for this request. It will not be sent again automatically.</p><?php endif; ?>
    </div>
    <div class="ai-insights__request-actions">
      <?php if ($canGenerate && ($job['can_retry'] ?? false) && !$older && $reason === null): ?>
      <form method="post" action="<?= esc(site_url($basePath . '/jobs/' . (int) $job['id'] . '/retry'), 'attr') ?>" data-ai-action data-ai-confirm="Queue a new attempt using the normal request budget? This is allowed only when no attempt in this request family reserved an outbound dispatch." data-ai-key="retry-job-<?= (int) $job['id'] ?>">
        <?= csrf_field() ?><input type="hidden" name="confirm_retry" value="1"><button type="submit" class="btn btn-sm btn-outline-primary">Queue safe new attempt</button>
      </form>
      <?php endif; ?>
      <?php if ($canGenerate && $job['status'] === 'queued'): ?>
      <form method="post" action="<?= esc(site_url($basePath . '/jobs/' . (int) $job['id'] . '/cancel'), 'attr') ?>" data-ai-action data-ai-key="cancel-job-<?= (int) $job['id'] ?>">
        <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger">Cancel waiting request</button>
      </form>
      <?php endif; ?>
    </div>
  </li>
<?php
};
?>
<div data-ai-pending="<?= $pending !== [] ? 'true' : 'false' ?>">
  <section class="ai-insights__control card" aria-labelledby="ai-analysis-heading">
    <div class="card-body">
      <div class="ai-insights__status-row">
        <div>
          <h2 class="h5 mb-1" id="ai-analysis-heading">Create an insight</h2>
          <p class="small text-muted mb-0">Comparison: <?= esc($comparisonMonthLabel) ?> and <?= esc($completedMonthLabel) ?> <span aria-hidden="true">&middot;</span> completed months in Philippine time</p>
        </div>
        <span class="ai-insights__status <?= $reason === null ? 'is-ready' : 'is-attention' ?>"><span aria-hidden="true"></span><?= esc($stateMessage) ?></span>
      </div>
      <p class="small text-muted ai-insights__status-help"><?= esc($stateHelp) ?></p>

      <div class="ai-insights__action-grid">
        <div class="ai-insights__selector">
          <label class="form-label fw-semibold" for="ai-analysis-selector">Analysis</label>
          <select class="form-select" id="ai-analysis-selector" data-native-select data-ai-analysis-selector>
            <?php foreach ($analyses as $analysis): ?>
            <option value="<?= esc($analysis['code'], 'attr') ?>"<?= $analysis['code'] === $selectedAnalysis ? ' selected' : '' ?>><?= esc($analysis['title']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="form-text mb-0" data-ai-analysis-description><?= esc($analysisDescriptions[$selectedAnalysis] ?? 'Compare approved monthly totals.') ?></p>
          <?php foreach ($analyses as $analysis): ?><span hidden data-ai-analysis-option="<?= esc($analysis['code'], 'attr') ?>" data-description="<?= esc($analysisDescriptions[$analysis['code']] ?? 'Compare approved monthly totals.', 'attr') ?>"></span><?php endforeach; ?>
        </div>
        <?php if ($canGenerate): ?>
        <div class="ai-insights__action-bar" aria-label="Analysis actions">
          <form method="post" action="<?= esc(site_url($basePath . '/prepare'), 'attr') ?>" data-ai-action data-ai-key="prepare-analysis">
            <?= csrf_field() ?><?php if (!$legacyAnalysisFixture): ?><input type="hidden" name="analysis_code" value="<?= esc($selectedAnalysis, 'attr') ?>" data-ai-analysis-value><?php endif; ?>
            <button class="btn btn-outline-primary" type="submit"<?= !$ready ? ' disabled' : '' ?>><i class="bi bi-table" aria-hidden="true"></i> Preview data</button>
          </form>
          <form method="post" action="<?= esc(site_url($basePath . '/generate'), 'attr') ?>" data-ai-action data-ai-key="generate-analysis">
            <?= csrf_field() ?><?php if (!$legacyAnalysisFixture): ?><input type="hidden" name="analysis_code" value="<?= esc($selectedAnalysis, 'attr') ?>" data-ai-analysis-value><?php endif; ?>
            <button class="btn btn-primary" type="submit"<?= !$ready || $reason !== null ? ' disabled' : '' ?>><i class="bi bi-stars" aria-hidden="true"></i> Generate draft</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
      <div class="ai-insights__control-footer">
        <span class="small text-muted">Repeated requests reuse the existing request.</span>
        <button class="btn btn-sm btn-link" type="button" data-ai-refresh data-ai-key="refresh-status"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh status</button>
      </div>
    </div>
  </section>

  <?php if ($canGenerate && ($recoverableSnapshots ?? []) !== []): ?>
  <details class="ai-insights__attention" data-ai-key="needs-attention">
    <summary data-ai-key="needs-attention-summary"><span><i class="bi bi-exclamation-circle" aria-hidden="true"></i> Needs attention</span><span class="badge text-bg-light"><?= count($recoverableSnapshots) ?></span></summary>
    <div class="ai-insights__attention-body">
      <p class="small">Expired local evidence can be recovered only when the source and category masks are exactly identical. Old requests and approvals are not renewed.</p>
      <?php foreach ($recoverableSnapshots as $snapshot): ?>
      <form method="post" action="<?= esc(site_url($basePath . '/recover'), 'attr') ?>" data-ai-action data-ai-confirm="Recover identical evidence with a new retention interval? This does not renew old requests or approvals." data-ai-key="recover-<?= esc($snapshot['analysis_code'], 'attr') ?>-<?= esc($snapshot['period'], 'attr') ?>" class="ai-insights__recovery">
        <?= csrf_field() ?><input type="hidden" name="analysis_code" value="<?= esc($snapshot['analysis_code'], 'attr') ?>"><input type="hidden" name="confirm_recovery" value="1">
        <span><strong><?= esc($analysisTitles[$snapshot['analysis_code']] ?? 'Analysis') ?></strong><small><?= esc($snapshot['period']) ?></small></span>
        <button class="btn btn-sm btn-outline-secondary" type="submit"<?= !$ready ? ' disabled' : '' ?>>Recover identical data</button>
      </form>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endif; ?>

  <?php if ($activeJobs !== []): ?>
  <section class="ai-insights__requests card" aria-labelledby="ai-active-requests-heading">
    <div class="card-body">
      <div class="ai-insights__section-heading">
        <div><h2 class="h5 mb-1" id="ai-active-requests-heading">Active requests</h2><p class="small text-muted mb-0" data-ai-progress-help>Status updates automatically. A separate worker must be enabled; this page cannot confirm one is scheduled.</p></div>
        <span class="badge text-bg-light"><?= count($activeJobs) ?></span>
      </div>
      <ul class="ai-insights__request-list"><?php foreach ($activeJobs as $job) { $renderJob($job); } ?></ul>
    </div>
  </section>
  <?php endif; ?>

  <section class="ai-insights__drafts" aria-labelledby="ai-drafts-heading">
    <div class="ai-insights__section-heading">
      <div><h2 class="h5 mb-1" id="ai-drafts-heading">Drafts for review</h2><p class="small text-muted mb-0">AI output is unverified. Open a draft to review its narrative, evidence, program details, and available decisions.</p></div>
      <?php if ($insights !== []): ?><span class="badge text-bg-light"><?= count($insights) ?></span><?php endif; ?>
    </div>
    <?php if ($insights === []): ?>
    <div class="ai-insights__empty"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><div><strong>No AI drafts yet</strong><p class="mb-0">Generate a draft if you have permission. Only unexpired drafts are shown; historical policies cannot be accepted or approved.</p></div></div>
    <?php endif; ?>
    <div class="ai-insights__draft-grid">
    <?php foreach ($insights as $insight):
        $draft = $insight['draft'];
        $narrative = $draft['narrative'];
        $analysisTitle = $analysisTitles[$draft['evidence']['analysis_code'] ?? $insight['analysis_code'] ?? ''] ?? ($unitLabel . ' operations');
        $currentPolicy = $insight['current_policy'] ?? \App\Services\Ai\PipelineProvenance::matches($draft['provenance']['pipeline_digest'] ?? null);
    ?>
      <article class="ai-insights__draft card" data-ai-insight-card="<?= (int) $insight['id'] ?>" data-ai-insight-version="<?= (int) $insight['version'] ?>" data-ai-key="insight-<?= (int) $insight['id'] ?>">
        <div class="card-body">
          <div class="ai-insights__draft-meta"><span class="badge text-bg-light"><?= esc($insightStatuses[$insight['status']] ?? 'Review status unavailable') ?></span><time><?= esc($time($insight['created_at'])) ?></time></div>
          <h3 class="h6 mb-2"><?= esc($analysisTitle) ?> <span class="text-muted">#<?= (int) $insight['id'] ?></span></h3>
          <p class="ai-insights__draft-summary"><?= esc($narrative['summary']) ?></p>
          <?php if (!$currentPolicy): ?><p class="ai-insights__policy-note"><i class="bi bi-clock-history" aria-hidden="true"></i> Historical policy: reject only</p><?php endif; ?>
          <button type="button" class="btn btn-sm btn-outline-primary" data-ai-open-review data-ai-insight-id="<?= (int) $insight['id'] ?>" data-ai-insight-version="<?= (int) $insight['version'] ?>" data-ai-key="open-insight-<?= (int) $insight['id'] ?>" aria-haspopup="dialog">Open draft</button>
        </div>
      </article>
      <template data-ai-review-template="<?= (int) $insight['id'] ?>" data-ai-insight-version="<?= (int) $insight['version'] ?>" data-title="<?= esc($analysisTitle . ' insight #' . (int) $insight['id'], 'attr') ?>">
        <div class="ai-insights__review-content" data-ai-review-content="<?= (int) $insight['id'] ?>" data-ai-insight-version="<?= (int) $insight['version'] ?>">
          <?php if (!$currentPolicy): ?><div class="alert alert-warning small">Historical or unverified pipeline policy. This draft cannot be accepted or approved under the current setup. Authorized reviewers may reject it.</div><?php endif; ?>
          <p class="small text-muted">Created <?= esc($time($insight['created_at'])) ?> Philippine time · AI can make mistakes. This is not a clinical finding and does not prove why activity changed.</p>
          <section class="ai-insights__narrative" aria-labelledby="ai-summary-<?= (int) $insight['id'] ?>">
            <h3 class="h6" id="ai-summary-<?= (int) $insight['id'] ?>">Draft interpretation</h3>
            <p class="fw-semibold"><?= esc($narrative['summary']) ?></p>
            <p><?= esc($narrative['interpretation']) ?></p>
            <?php if ($narrative['abstained']): ?><p class="alert alert-info">The AI did not recommend a program.</p><?php endif; ?>
            <h4 class="h6">Alternative explanations to review</h4>
            <ul><?php foreach ($narrative['alternative_explanations'] as $alternative): ?><li><?= esc($alternative) ?></li><?php endforeach; ?></ul>
          </section>
          <?= view('ai/evidence', ['evidence' => $draft['evidence']]) ?>
          <?php foreach ($draft['programs'] as $program): ?>
          <section class="ai-insights__program" aria-label="Operational program draft">
            <p class="ai-insights__eyebrow mb-1">Program draft</p>
            <h3 class="h6 mb-2"><?= esc($program['title']) ?></h3>
            <p><strong>Objective:</strong> <?= esc($program['objective']) ?></p>
            <details data-ai-key="program-<?= (int) $insight['id'] ?>-<?= esc(hash('sha256', $program['title']), 'attr') ?>">
              <summary>Program details</summary>
              <div class="mt-3">
                <p class="small"><strong>Audience:</strong> <?= esc($program['audience']) ?></p>
                <?php foreach (['activities' => 'Proposed activities', 'resource_assumptions' => 'Resources that may be needed', 'evaluation_measures' => 'How to assess the program', 'limitations' => 'Limits to keep in mind'] as $key => $label): ?>
                <h4 class="h6"><?= esc($label) ?></h4><ul class="small"><?php foreach ($program[$key] as $item): ?><li><?= esc($item) ?></li><?php endforeach; ?></ul>
                <?php endforeach; ?>
                <p class="small">Required reviewer: <?= esc($program['required_reviewer']) ?>. References guide review and approval; they do not prove the program works.</p>
                <ul class="small mb-0"><?php foreach ($program['references'] as $reference): ?><li><a href="<?= esc($reference['url'], 'attr') ?>" target="_blank" rel="noopener noreferrer" data-navigation="false"><?= esc($reference['title']) ?></a></li><?php endforeach; ?></ul>
              </div>
            </details>
          </section>
          <?php endforeach; ?>
          <details class="ai-insights__provenance" data-ai-key="provenance-<?= (int) $insight['id'] ?>"><summary>Draft details</summary><p class="small mt-2 mb-0">Model: <?= esc($draft['provenance']['model']) ?> · Instruction version: <?= esc($draft['provenance']['prompt_version']) ?> · Library: <?= esc($draft['provenance']['library_version'] ?? 'Legacy') ?></p><p class="small text-break mb-0">Pipeline policy: <?= esc($draft['provenance']['pipeline_digest'] ?? 'Not recorded (legacy)') ?></p></details>
          <p class="small text-muted mb-0">Accepting records your review; it does not confirm that the AI is correct. Approving a program draft does not publish, fund, schedule, or send anything.</p>
        </div>
      </template>
      <template data-ai-review-actions="<?= (int) $insight['id'] ?>" data-ai-insight-version="<?= (int) $insight['version'] ?>">
        <?php $decisions = [];
        if ($canReview && $currentPolicy && $insight['status'] === 'draft') { $decisions['accept'] = 'Accept insight'; }
        if ($canReview && in_array($insight['status'], ['draft', 'accepted'], true)) { $decisions['reject'] = 'Reject insight'; }
        if ($canApprove && $currentPolicy && $insight['status'] === 'accepted' && $draft['programs'] !== []) { $decisions['approve'] = 'Approve program draft'; }
        foreach ($decisions as $decision => $label): ?>
        <form method="post" action="<?= esc(site_url($basePath . '/' . (int) $insight['id'] . '/review'), 'attr') ?>" data-ai-action data-ai-review-form data-ai-decision="<?= esc($decision, 'attr') ?>" data-ai-key="review-<?= (int) $insight['id'] ?>-<?= esc($decision, 'attr') ?>">
          <?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $insight['version'] ?>"><input type="hidden" name="decision" value="<?= esc($decision, 'attr') ?>">
          <button type="submit" class="btn btn-sm <?= $decision === 'reject' ? 'btn-outline-danger' : 'btn-primary' ?>"><?= esc($label) ?></button>
        </form>
        <?php endforeach; ?>
      </template>
    <?php endforeach; ?>
    </div>
  </section>

  <?php if ($historyJobs !== []): ?>
  <details class="ai-insights__history" data-ai-key="request-history">
    <summary data-ai-key="request-history-summary"><span>Request history</span><span class="badge text-bg-light"><?= count($historyJobs) ?></span></summary>
    <div class="ai-insights__history-body"><ul class="ai-insights__request-list"><?php foreach ($historyJobs as $job) { $renderJob($job); } ?></ul></div>
  </details>
  <?php endif; ?>
</div>

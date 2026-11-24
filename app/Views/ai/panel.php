<?php
require __DIR__ . '/_scope.php';

$analysisTitles = array_column($analyses, 'title', 'code');
$analysisIcons = [
    'clinic.activity' => 'bi-activity',
    'clinic.complaints' => 'bi-clipboard2-pulse',
    'guidance.activity' => 'bi-chat-heart',
    'guidance.themes' => 'bi-journal-text',
    'bmg.batches' => 'bi-box-seam',
    'bmg.rotations' => 'bi-arrow-repeat',
];
$activeStatuses = ['queued', 'running'];
$activeJobs = array_values(array_filter($jobs, static fn (array $job): bool => in_array($job['status'], $activeStatuses, true)
    && $job['profile_revision'] === $profile['revision']));
$pagedPanel = is_array($insightPagination ?? null) && is_array($jobPagination ?? null);
$historyJobs = $pagedPanel ? $jobs : array_values(array_filter($jobs, static fn (array $job): bool => !in_array($job['status'], $activeStatuses, true)
    || $job['profile_revision'] !== $profile['revision']));

// Standalone legacy presentation fixtures have no paging metadata. Web counts are SQL scope totals.
$insightCounts = $insightCounts ?? [];
$jobCounts = $jobCounts ?? [];
$awaitingCount = (int) ($insightCounts['draft'] ?? count(array_filter($insights, static fn (array $item): bool => ($item['status'] ?? '') === 'draft')));
$acceptedApprovedCount = (int) ($insightCounts['reviewed'] ?? count(array_filter($insights, static fn (array $item): bool => in_array($item['status'] ?? '', ['accepted', 'approved'], true))));
$rejectedCount = (int) ($insightCounts['rejected'] ?? count(array_filter($insights, static fn (array $item): bool => ($item['status'] ?? '') === 'rejected')));
$insightCount = (int) ($insightCounts['total'] ?? count($insights));
$activeCount = (int) ($jobCounts['active'] ?? count($activeJobs));
$requestCount = (int) ($jobCounts['total'] ?? count($jobs));
$selectedStatus = $pagedPanel ? ($panelQuery['insights_status'] ?? 'all') : 'all';
$panelQuery = $pagedPanel ? [
    'insights_page' => (int) $insightPagination['page'], 'insights_per_page' => (int) $insightPagination['per_page'],
    'jobs_page' => (int) $jobPagination['page'], 'jobs_per_page' => (int) $jobPagination['per_page'],
    'insights_status' => $selectedStatus,
] : [];
$panelUrl = site_url($basePath);
$statusUrl = static fn (string $status): string => $panelUrl . '?' . http_build_query(
    array_replace($panelQuery, ['insights_page' => 1, 'insights_status' => $status]), '', '&', PHP_QUERY_RFC3986,
);
$renderPagination = static function (string $list, array $pagination, string $label) use ($panelQuery, $panelUrl): void {
    $pageParam = $list . '_page';
    $perPageParam = $list . '_per_page';
?>
  <div data-ai-pagination="<?= esc($list, 'attr') ?>" data-ai-key="pagination-<?= esc($list, 'attr') ?>">
    <?= view('partials/table_pagination', [
        'pagination' => $pagination, 'filters' => [], 'baseUrl' => $panelUrl,
        'paginationPageParam' => $pageParam, 'paginationPerPageParam' => $perPageParam,
        'paginationExtraQuery' => $panelQuery, 'paginationLabel' => $label . ' pages',
        'totalLabel' => $list === 'insights' ? 'unexpired drafts' : 'scoped requests',
    ]) ?>
    <form method="get" action="<?= esc($panelUrl, 'attr') ?>" data-ai-page-size class="d-flex align-items-center gap-2 mt-2">
      <?php foreach (array_replace($panelQuery, [$pageParam => 1]) as $key => $value): if ($key === $perPageParam) { continue; } ?>
      <input type="hidden" name="<?= esc($key, 'attr') ?>" value="<?= esc((string) $value, 'attr') ?>">
      <?php endforeach; ?>
      <label class="small text-muted" for="ai-<?= esc($list, 'attr') ?>-per-page"><?= esc($label) ?> per page</label>
      <select class="form-select form-select-sm w-auto" id="ai-<?= esc($list, 'attr') ?>-per-page" name="<?= esc($perPageParam, 'attr') ?>" data-native-select>
        <?php foreach ([20, 25, 50, 100] as $size): ?><option value="<?= $size ?>"<?= (int) $pagination['per_page'] === $size ? ' selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
    </form>
  </div>
<?php
};

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
      <div class="ai-insights__request-head">
        <strong><?= esc($analysisTitles[$job['analysis_code'] ?? ''] ?? 'Analysis') ?> <span class="ai-insights__id">#<?= (int) $job['id'] ?></span></strong>
        <div class="ai-insights__request-badges">
          <span class="badge text-bg-light" data-ai-tone="<?= esc($progressStage, 'attr') ?>"><?= esc($jobStatuses[$job['status']] ?? 'Status unavailable') ?></span>
          <?php if ($older): ?><span class="badge text-bg-secondary">Older configuration</span><?php endif; ?>
        </div>
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
      <small class="ai-insights__request-meta text-muted">Created <?= esc($time($job['created_at'])) ?> Philippine time<?php if ($job['reason']): ?> · <?= esc($jobReasons[$job['reason']] ?? 'This request could not proceed. Ask an administrator for help.') ?><?php endif; ?></small>
      <?php if ($job['status'] === 'outcome_unknown'): ?><p class="ai-insights__unknown-note small mb-0 mt-1">The AI service may have processed or charged for this request. It will not be sent again automatically.</p><?php endif; ?>
    </div>
    <?php
      $showRetry = $canGenerate && ($job['can_retry'] ?? false) && !$older && $reason === null;
      $showCancel = $canGenerate && $job['status'] === 'queued';
    ?>
    <?php if ($showRetry || $showCancel): ?>
    <div class="ai-insights__request-actions">
      <?php if ($showRetry): ?>
      <form method="post" action="<?= esc(site_url($basePath . '/jobs/' . (int) $job['id'] . '/retry'), 'attr') ?>" data-ai-action data-ai-confirm="Queue a new attempt using the normal request budget? This is allowed only when no attempt in this request family reserved an outbound dispatch." data-ai-key="retry-job-<?= (int) $job['id'] ?>">
        <?= csrf_field() ?><input type="hidden" name="confirm_retry" value="1"><button type="submit" class="btn btn-sm btn-outline-primary">Queue safe new attempt</button>
      </form>
      <?php endif; ?>
      <?php if ($showCancel): ?>
      <form method="post" action="<?= esc(site_url($basePath . '/jobs/' . (int) $job['id'] . '/cancel'), 'attr') ?>" data-ai-action data-ai-key="cancel-job-<?= (int) $job['id'] ?>">
        <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger">Cancel waiting request</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </li>
<?php
};
?>
<div data-ai-pending="<?= $activeCount > 0 ? 'true' : 'false' ?>"<?= $pagedPanel ? ' data-ai-page-state' : '' ?><?php foreach ($panelQuery as $key => $value): ?> data-ai-<?= esc(str_replace('_', '-', $key), 'attr') ?>="<?= esc((string) $value, 'attr') ?>"<?php endforeach; ?>>
  <aside class="ai-insights__rail" aria-label="Analysis controls and live requests">
    <section class="ai-insights__control card" aria-labelledby="ai-analysis-heading">
      <div class="card-body">
        <div class="ai-insights__studio-head">
          <div class="ai-insights__status-row">
            <h2 class="h5 mb-0" id="ai-analysis-heading">Create an insight</h2>
            <span class="ai-insights__status <?= $reason === null ? 'is-ready' : 'is-attention' ?>"><span aria-hidden="true"></span><?= esc($stateMessage) ?></span>
          </div>
          <p class="small text-muted mb-0"><?= esc($comparisonMonthLabel) ?> and <?= esc($completedMonthLabel) ?> · Philippine time</p>
          <p class="small text-muted ai-insights__status-help"><?= esc($stateHelp) ?></p>
        </div>

        <div class="ai-insights__step-block">
          <div class="ai-insights__step-head">
            <span class="ai-insights__step-num" aria-hidden="true">1</span>
            <label class="ai-insights__step-label" for="ai-analysis-selector">Choose an analysis</label>
            <span class="ai-insights__step-count"><?= count($analyses) ?> fixed</span>
          </div>

          <div class="ai-insights__selector-wrap">
            <select class="form-select ai-insights__native-select" id="ai-analysis-selector" data-native-select data-ai-analysis-selector tabindex="-1">
              <?php foreach ($analyses as $analysis): ?>
              <option value="<?= esc($analysis['code'], 'attr') ?>"<?= $analysis['code'] === $selectedAnalysis ? ' selected' : '' ?>><?= esc($analysis['title']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="ai-insights__choices" role="radiogroup" aria-label="Available analyses">
              <?php foreach ($analyses as $analysis):
                  $code = $analysis['code'];
                  $isSelected = $code === $selectedAnalysis;
                  $icon = $analysisIcons[$code] ?? 'bi-bar-chart-line';
                  $desc = $analysisDescriptions[$code] ?? 'Compare approved monthly totals.';
              ?>
              <button
                type="button"
                role="radio"
                aria-checked="<?= $isSelected ? 'true' : 'false' ?>"
                class="ai-insights__choice<?= $isSelected ? ' is-selected' : '' ?>"
                data-ai-analysis-choice="<?= esc($code, 'attr') ?>"
                data-ai-key="choice-<?= esc($code, 'attr') ?>"
              >
                <span class="ai-insights__choice-icon" aria-hidden="true"><i class="bi <?= esc($icon, 'attr') ?>"></i></span>
                <span class="ai-insights__choice-body">
                  <span class="ai-insights__choice-title"><?= esc($analysis['title']) ?></span>
                  <span class="ai-insights__choice-desc"><?= esc($desc) ?></span>
                </span>
                <span class="ai-insights__choice-dot" aria-hidden="true"></span>
              </button>
              <?php endforeach; ?>
            </div>
            <p class="form-text visually-hidden mb-0" data-ai-analysis-description><?= esc($analysisDescriptions[$selectedAnalysis] ?? 'Compare approved monthly totals.') ?></p>
            <?php foreach ($analyses as $analysis): ?><span hidden data-ai-analysis-option="<?= esc($analysis['code'], 'attr') ?>" data-description="<?= esc($analysisDescriptions[$analysis['code']] ?? 'Compare approved monthly totals.', 'attr') ?>"></span><?php endforeach; ?>
          </div>
        </div>

        <?php if ($canGenerate): ?>
        <div class="ai-insights__step-block">
          <div class="ai-insights__step-head">
            <span class="ai-insights__step-num" aria-hidden="true">2</span>
            <span class="ai-insights__step-label">Preview locally or generate draft</span>
          </div>
          <div class="ai-insights__action-stack" aria-label="Analysis actions">
            <div class="ai-insights__action-item">
              <form method="post" action="<?= esc(site_url($basePath . '/prepare'), 'attr') ?>" data-ai-action data-ai-key="prepare-analysis">
                <?= csrf_field() ?><?php if (!$legacyAnalysisFixture): ?><input type="hidden" name="analysis_code" value="<?= esc($selectedAnalysis, 'attr') ?>" data-ai-analysis-value><?php endif; ?>
                <button class="btn btn-outline-primary" type="submit"<?= !$ready ? ' disabled' : '' ?>><i class="bi bi-table" aria-hidden="true"></i> Preview data</button>
              </form>
              <span class="ai-insights__action-hint">Inspect approved local numbers right beside this panel — no AI contacted.</span>
            </div>
            <div class="ai-insights__action-item">
              <form method="post" action="<?= esc(site_url($basePath . '/generate'), 'attr') ?>" data-ai-action data-ai-key="generate-analysis">
                <?= csrf_field() ?><?php if (!$legacyAnalysisFixture): ?><input type="hidden" name="analysis_code" value="<?= esc($selectedAnalysis, 'attr') ?>" data-ai-analysis-value><?php endif; ?>
                <button class="btn btn-primary" type="submit"<?= !$ready || $reason !== null ? ' disabled' : '' ?>><i class="bi bi-stars" aria-hidden="true"></i> Generate draft</button>
              </form>
              <span class="ai-insights__action-hint">Queue a worker to draft a summary &amp; program proposal for staff review.</span>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <div class="ai-insights__control-footer">
          <span class="small text-muted">Repeated requests reuse the existing request.</span>
          <button class="btn btn-sm btn-link" type="button" data-ai-refresh data-ai-key="refresh-status"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh status</button>
        </div>
      </div>
    </section>

    <?php if ($activeCount > 0): ?>
    <section class="ai-insights__requests card" aria-labelledby="ai-active-requests-heading">
      <div class="card-body">
        <div class="ai-insights__section-heading">
          <div>
            <h2 class="h6 mb-1" id="ai-active-requests-heading">Active requests</h2>
            <p class="small text-muted mb-0" data-ai-progress-help>Status updates automatically. A separate worker must be enabled; this page cannot confirm one is scheduled.</p>
          </div>
          <span class="badge text-bg-light" data-ai-tone="generating"><?= $activeCount ?></span>
        </div>
        <?php if ($pagedPanel): ?>
        <p class="small text-muted mb-0 mt-2">All <?= $activeCount ?> active requests under the current setup are counted across this department, including other request pages. Open Request history below to inspect the selected page.</p>
        <?php else: ?>
        <ul class="ai-insights__request-list"><?php foreach ($activeJobs as $job) { $renderJob($job); } ?></ul>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($canGenerate && ($recoverableSnapshots ?? []) !== []): ?>
    <details class="ai-insights__attention" data-ai-key="needs-attention">
      <summary data-ai-key="needs-attention-summary"><span><i class="bi bi-exclamation-circle" aria-hidden="true"></i> Needs attention</span><span class="badge text-bg-light" data-ai-tone="draft"><?= count($recoverableSnapshots) ?></span></summary>
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
  </aside>

  <div class="ai-insights__canvas">
    <div class="ai-insights__summary-strip" aria-label="Workspace overview">
      <div class="ai-insights__stat">
        <span class="ai-insights__stat-label">Awaiting review</span>
        <strong class="ai-insights__stat-value"><?= $awaitingCount ?></strong>
      </div>
      <div class="ai-insights__stat">
        <span class="ai-insights__stat-label">Accepted &amp; approved</span>
        <strong class="ai-insights__stat-value"><?= $acceptedApprovedCount ?></strong>
      </div>
      <div class="ai-insights__stat">
        <span class="ai-insights__stat-label">Active requests</span>
        <strong class="ai-insights__stat-value"><?= $activeCount ?></strong>
      </div>
    </div>
    <?php if ($pagedPanel): ?><p class="small text-muted mb-3" data-ai-count-scope>Review totals cover all unexpired drafts in this department, not only this page or status filter. Active requests cover the current setup across all request pages.</p><?php endif; ?>

    <section class="ai-insights__drafts card" aria-labelledby="ai-drafts-heading">
      <div class="card-body">
        <div class="ai-insights__drafts-head">
          <div>
            <div class="ai-insights__drafts-title-row">
              <h2 class="h5 mb-0" id="ai-drafts-heading">Drafts for review</h2>
              <?php if ($insightCount > 0): ?><span class="badge text-bg-light"><?= $insightCount ?></span><?php endif; ?>
            </div>
            <p class="small text-muted mb-0 mt-1">AI output is unverified. Open a draft to compare its narrative and program proposal side-by-side against approved local data.</p>
          </div>
          <?php if ($pagedPanel || count($insights) > 1): ?>
          <div class="ai-insights__filter-tabs" role="group" aria-label="<?= $pagedPanel ? 'Filter all department drafts by status' : 'Filter drafts by status' ?>">
            <?php foreach (['all' => ['All', $insightCount], 'draft' => ['Awaiting', $awaitingCount], 'reviewed' => ['Reviewed', $acceptedApprovedCount], 'rejected' => ['Rejected', $rejectedCount]] as $status => [$label, $count]):
                if (!$pagedPanel && $status !== 'all' && $count === 0) { continue; }
                $isSelected = $selectedStatus === $status;
            ?>
              <?php if ($pagedPanel): ?>
              <a href="<?= esc($statusUrl($status), 'attr') ?>" role="button" class="ai-insights__filter-tab<?= $isSelected ? ' is-active' : '' ?>" data-ai-status="<?= esc($status, 'attr') ?>" data-navigation="false" aria-pressed="<?= $isSelected ? 'true' : 'false' ?>"><?= esc($label) ?> <span class="ai-insights__filter-count"><?= $count ?></span></a>
              <?php else: ?>
              <button type="button" class="ai-insights__filter-tab<?= $isSelected ? ' is-active' : '' ?>" data-ai-filter="<?= esc($status, 'attr') ?>" aria-pressed="<?= $isSelected ? 'true' : 'false' ?>"><?= esc($label) ?> <span class="ai-insights__filter-count"><?= $count ?></span></button>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($insights === []): ?>
        <div class="ai-insights__empty">
          <div class="ai-insights__empty-icon"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></div>
          <div class="ai-insights__empty-copy">
            <strong><?= $pagedPanel && $selectedStatus !== 'all' ? 'No drafts match this status' : 'No AI drafts yet' ?></strong>
            <p class="mb-0">Generate a draft if you have permission. Only unexpired drafts are shown; historical policies cannot be accepted or approved.</p>
            <div class="ai-insights__empty-flow" aria-hidden="true">
              <span>1. Select analysis</span>
              <i class="bi bi-arrow-right"></i>
              <span>2. Preview or generate</span>
              <i class="bi bi-arrow-right"></i>
              <span>3. Open &amp; decide</span>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($insights !== []): ?>
        <div class="ai-insights__draft-list">
        <?php foreach ($insights as $insight):
            $draft = $insight['draft'];
            $narrative = $draft['narrative'];
            $analysisTitle = $analysisTitles[$draft['evidence']['analysis_code'] ?? $insight['analysis_code'] ?? ''] ?? ($unitLabel . ' operations');
            $currentPolicy = $insight['current_policy'] ?? \App\Services\Ai\PipelineProvenance::matches($draft['provenance']['pipeline_digest'] ?? null);
            $insightTone = match ($insight['status'] ?? '') {
                'draft' => 'draft', 'accepted' => 'accepted', 'approved' => 'approved', 'rejected' => 'rejected', default => 'neutral',
            };
            $filterGroup = match ($insight['status'] ?? '') {
                'draft' => 'draft', 'accepted', 'approved' => 'reviewed', 'rejected' => 'rejected', default => 'all',
            };
            $programCount = count($draft['programs'] ?? []);
            $metricCount = count($draft['evidence']['metrics'] ?? []);
        ?>
          <article class="ai-insights__draft" data-ai-insight-card="<?= (int) $insight['id'] ?>" data-ai-insight-version="<?= (int) $insight['version'] ?>" data-ai-filter-group="<?= esc($filterGroup, 'attr') ?>" data-ai-key="insight-<?= (int) $insight['id'] ?>">
            <div class="ai-insights__draft-main">
              <div class="ai-insights__draft-meta">
                <span class="badge text-bg-light" data-ai-tone="<?= esc($insightTone, 'attr') ?>"><?= esc($insightStatuses[$insight['status']] ?? 'Review status unavailable') ?></span>
                <?php if ($programCount > 0): ?>
                <span class="ai-insights__draft-chip"><i class="bi bi-kanban" aria-hidden="true"></i> <?= $programCount === 1 ? '1 program proposal' : $programCount . ' program proposals' ?></span>
                <?php elseif (!empty($narrative['abstained'])): ?>
                <span class="ai-insights__draft-chip"><i class="bi bi-info-circle" aria-hidden="true"></i> Observation only</span>
                <?php endif; ?>
                <?php if ($metricCount > 0): ?>
                <span class="ai-insights__draft-chip"><?= $metricCount === 1 ? '1 metric' : $metricCount . ' metrics' ?></span>
                <?php endif; ?>
                <time class="ai-insights__draft-time"><?= esc($time($insight['created_at'])) ?></time>
              </div>
              <h3 class="ai-insights__draft-title h6 mb-1"><?= esc($analysisTitle) ?> <span class="ai-insights__id">#<?= (int) $insight['id'] ?></span></h3>
              <p class="ai-insights__draft-summary mb-0"><?= esc($narrative['summary']) ?></p>
              <?php if (!$currentPolicy): ?><p class="ai-insights__policy-note mb-0"><i class="bi bi-clock-history" aria-hidden="true"></i> Historical policy: reject only</p><?php endif; ?>
            </div>
            <div class="ai-insights__draft-cta">
              <button type="button" class="btn btn-sm btn-outline-primary" data-ai-open-review data-ai-insight-id="<?= (int) $insight['id'] ?>" data-ai-insight-version="<?= (int) $insight['version'] ?>" data-ai-key="open-insight-<?= (int) $insight['id'] ?>" aria-haspopup="dialog">Open draft</button>
            </div>
          </article>
          <template data-ai-review-template="<?= (int) $insight['id'] ?>" data-ai-insight-version="<?= (int) $insight['version'] ?>" data-title="<?= esc($analysisTitle . ' insight #' . (int) $insight['id'], 'attr') ?>">
            <div class="ai-insights__review-content" data-ai-review-content="<?= (int) $insight['id'] ?>" data-ai-insight-version="<?= (int) $insight['version'] ?>">
              <?php if (!$currentPolicy): ?><div class="alert alert-warning small mb-0">Historical or unverified pipeline policy. This draft cannot be accepted or approved under the current setup. Authorized reviewers may reject it.</div><?php endif; ?>
              <div class="ai-insights__review-banner">
                <span class="badge text-bg-light" data-ai-tone="<?= esc($insightTone, 'attr') ?>"><?= esc($insightStatuses[$insight['status']] ?? 'Review status unavailable') ?></span>
                <p class="small text-muted mb-0">Created <?= esc($time($insight['created_at'])) ?> Philippine time · AI can make mistakes. This is not a clinical finding and does not prove why activity changed.</p>
              </div>

              <div class="ai-insights__review-split">
                <div class="ai-insights__review-col ai-insights__review-col--draft">
                  <section class="ai-insights__narrative" aria-labelledby="ai-summary-<?= (int) $insight['id'] ?>">
                    <p class="ai-insights__eyebrow mb-1">AI narrative</p>
                    <h3 class="h6 mb-2" id="ai-summary-<?= (int) $insight['id'] ?>">Draft interpretation</h3>
                    <p class="ai-insights__narrative-lead"><?= esc($narrative['summary']) ?></p>
                    <p class="ai-insights__narrative-body"><?= esc($narrative['interpretation']) ?></p>
                    <?php if ($narrative['abstained']): ?><p class="alert alert-info small mb-3">The AI did not recommend a program.</p><?php endif; ?>
                    <div class="ai-insights__alternatives">
                      <h4 class="h6 mb-2"><i class="bi bi-signpost-split" aria-hidden="true"></i> Alternative explanations to review</h4>
                      <ul><?php foreach ($narrative['alternative_explanations'] as $alternative): ?><li><?= esc($alternative) ?></li><?php endforeach; ?></ul>
                    </div>
                  </section>

                  <?php foreach ($draft['programs'] as $program): ?>
                  <section class="ai-insights__program" aria-label="Operational program draft">
                    <div class="ai-insights__program-head">
                      <p class="ai-insights__eyebrow mb-1">Program draft</p>
                      <h3 class="h6 mb-0"><?= esc($program['title']) ?></h3>
                    </div>
                    <p class="ai-insights__program-objective"><strong>Objective:</strong> <?= esc($program['objective']) ?></p>
                    <details data-ai-key="program-<?= (int) $insight['id'] ?>-<?= esc(hash('sha256', $program['title']), 'attr') ?>">
                      <summary>Program details</summary>
                      <div class="ai-insights__program-details mt-3">
                        <p class="ai-insights__program-audience small"><strong>Audience:</strong> <?= esc($program['audience']) ?></p>
                        <div class="ai-insights__program-grid">
                          <?php foreach (['activities' => 'Proposed activities', 'resource_assumptions' => 'Resources that may be needed', 'evaluation_measures' => 'How to assess the program', 'limitations' => 'Limits to keep in mind'] as $key => $label): ?>
                          <div class="ai-insights__program-box">
                            <h4 class="h6"><?= esc($label) ?></h4>
                            <ul class="small mb-0"><?php foreach ($program[$key] as $item): ?><li><?= esc($item) ?></li><?php endforeach; ?></ul>
                          </div>
                          <?php endforeach; ?>
                        </div>
                        <div class="ai-insights__program-refs">
                          <p class="small mb-2"><strong>Required reviewer:</strong> <?= esc($program['required_reviewer']) ?>. References guide review and approval; they do not prove the program works.</p>
                          <ul class="small mb-0"><?php foreach ($program['references'] as $reference): ?><li><a href="<?= esc($reference['url'], 'attr') ?>" target="_blank" rel="noopener noreferrer" data-navigation="false"><?= esc($reference['title']) ?></a></li><?php endforeach; ?></ul>
                        </div>
                      </div>
                    </details>
                  </section>
                  <?php endforeach; ?>
                </div>

                <div class="ai-insights__review-col ai-insights__review-col--evidence">
                  <?= view('ai/evidence', ['evidence' => $draft['evidence']]) ?>
                  <details class="ai-insights__provenance" data-ai-key="provenance-<?= (int) $insight['id'] ?>">
                    <summary>Draft details</summary>
                    <div class="ai-insights__provenance-body">
                      <p class="small mb-1">Model: <?= esc($draft['provenance']['model']) ?> · Instruction version: <?= esc($draft['provenance']['prompt_version']) ?> · Library: <?= esc($draft['provenance']['library_version'] ?? 'Legacy') ?></p>
                      <p class="small text-break mb-0">Pipeline policy: <?= esc($draft['provenance']['pipeline_digest'] ?? 'Not recorded (legacy)') ?></p>
                    </div>
                  </details>
                </div>
              </div>

              <p class="ai-insights__review-disclaimer small text-muted mb-0">Accepting records your review; it does not confirm that the AI is correct. Approving a program draft does not publish, fund, schedule, or send anything.</p>
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
        <?php endif; ?>
        <?php if ($pagedPanel): $renderPagination('insights', $insightPagination, 'Drafts'); endif; ?>
      </div>
    </section>

    <?php if ($pagedPanel || $historyJobs !== []): ?>
    <details class="ai-insights__history" data-ai-key="request-history"<?= $pagedPanel && $panelQuery['jobs_page'] > 1 ? ' open' : '' ?>>
      <summary data-ai-key="request-history-summary"><span><i class="bi bi-clock-history" aria-hidden="true"></i> Request history</span><span class="badge text-bg-light"><?= $pagedPanel ? $requestCount : count($historyJobs) ?></span></summary>
      <div class="ai-insights__history-body">
        <?php if ($pagedPanel): ?>
        <p class="small text-muted">All request statuses are included, including waiting and processing requests. The department total covers all request pages; status updates never start or replay a request.<?php if (($jobCounts['older_active'] ?? 0) > 0): ?> <?= (int) $jobCounts['older_active'] ?> waiting or processing requests belong to an older setup.<?php endif; ?></p>
        <?php if ($historyJobs === []): ?><p class="small text-muted mb-0">No requests yet.</p><?php endif; ?>
        <?php endif; ?>
        <ul class="ai-insights__request-list ai-insights__request-list--compact"><?php foreach ($historyJobs as $job) { $renderJob($job); } ?></ul>
        <?php if ($pagedPanel): $renderPagination('jobs', $jobPagination, 'Requests'); endif; ?>
      </div>
    </details>
    <?php endif; ?>
  </div>
</div>

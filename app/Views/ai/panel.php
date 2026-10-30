<?php
require __DIR__ . '/_scope.php';
$analysisTitles = array_column($analyses, 'title', 'code');
$pending = array_filter($jobs, static fn (array $job): bool => in_array($job['status'], ['queued', 'running'], true) && $job['profile_revision'] === $profile['revision']);
$stateMessage = match ($reason) {
    null => 'The AI setup is approved. This does not confirm that enough eligible data is available or that the separate worker is running.',
    'disabled' => 'AI requests are turned off. Local summaries still require separate approval and privacy checks.',
    'dispatch_paused' => 'AI requests are paused. Ask an administrator to review the setup and resume requests. Local summaries have separate approval requirements.',
    'store_unavailable', 'encryption_unavailable' => 'Secure storage for insights is not ready. Ask an administrator for help. Other department functions are unaffected.',
    'governance_required', 'tenant_not_approved', 'unit_not_approved' => 'Privacy and department approval is needed before a summary can be prepared.',
    'revision_not_approved', 'compatibility_required', 'library_not_approved', 'endpoint_changed' => 'The current AI setup or program templates still need approval. Ask an administrator for help.',
    default => 'Insights are unavailable with the current setup. Ask an administrator for help.',
};
$jobStatuses = [
    'queued' => 'Waiting for a worker', 'running' => 'Processing', 'completed' => 'Draft ready',
    'succeeded' => 'Draft ready', 'failed' => 'Could not create a draft', 'cancelled' => 'Cancelled',
    'expired' => 'Expired', 'outcome_unknown' => 'Result could not be confirmed',
];
$jobReasons = [
    'generation_failed' => 'The draft could not be created.',
    'provider_unavailable' => 'The AI service was unavailable.',
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
$insightStatuses = ['draft' => 'Awaiting review', 'accepted' => 'Reviewed and accepted',
    'rejected' => 'Rejected', 'approved' => 'Program draft approved'];
$time = static function (string $value): string {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
    return $date ? $date->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d H:i P') : 'Unavailable';
};
?>
<div data-ai-pending="<?= $pending !== [] ? 'true' : 'false' ?>">
  <div class="card mb-3"><div class="card-body">
    <h2 class="h5">Local summaries and AI drafts</h2>
    <p><?= esc($stateMessage) ?></p>
    <p class="small text-muted">Comparison: <?= esc($comparisonMonthLabel) ?> and <?= esc($completedMonthLabel) ?>. Data eligibility is checked when you prepare a summary or request a draft; it is separate from AI service connectivity.</p>
    <details class="small mb-3">
      <summary>Why might a summary be unavailable?</summary>
      <ul class="mt-2 mb-0">
        <li>Both completed months need eligible records for the selected comparison.</li>
        <?php if ($unit !== 'bmg'): ?>
        <li>Both months need enough different people to meet privacy rules; repeat visits by one person do not count as different people.</li>
        <li><?= $unit === 'guidance' ? 'Counselling sessions' : 'Clinic visits' ?> must be recorded as completed and have valid linked records.</li>
        <?php else: ?>
        <li>Check batch records or accepted rotation reports for the selected comparison.</li>
        <?php endif; ?>
        <li>Required approvals and privacy checks must pass. Hidden or unavailable values are not zero.</li>
      </ul>
    </details>
    <?php foreach ($analyses as $analysis): ?>
    <section class="border rounded p-3 mb-3" aria-label="<?= esc($analysis['title'], 'attr') ?>">
      <h3 class="h6"><?= esc($analysis['title']) ?></h3>
      <p class="small"><?= esc($analysisDescriptions[$analysis['code']] ?? 'Compare approved monthly totals.') ?></p>
      <?php if ($canGenerate): ?>
      <div class="d-flex gap-2 flex-wrap">
        <form method="post" action="<?= esc(site_url($basePath . '/prepare'), 'attr') ?>" data-ai-action>
          <?= csrf_field() ?><?php if (!$legacyAnalysisFixture): ?><input type="hidden" name="analysis_code" value="<?= esc($analysis['code'], 'attr') ?>"><?php endif; ?>
          <button class="btn btn-outline-primary" type="submit"<?= !$ready ? ' disabled' : '' ?>>Prepare local summary</button>
        </form>
        <form method="post" action="<?= esc(site_url($basePath . '/generate'), 'attr') ?>" data-ai-action>
          <?= csrf_field() ?><?php if (!$legacyAnalysisFixture): ?><input type="hidden" name="analysis_code" value="<?= esc($analysis['code'], 'attr') ?>"><?php endif; ?>
          <button class="btn btn-primary" type="submit"<?= !$ready || $reason !== null ? ' disabled' : '' ?>>Request AI draft</button>
        </form>
      </div>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
    <button class="btn btn-outline-secondary" type="button" data-ai-refresh>Refresh status</button>
    <p class="small text-muted mt-2 mb-0">Choose one of the comparisons above. Dates and privacy rules are fixed; you cannot select individual people or send custom instructions to AI. Repeated requests reuse the existing request.</p>
  </div></div>
  <?php if ($jobs !== []): ?>
  <div class="card mb-3"><div class="card-body">
    <h2 class="h5">Recent draft requests</h2>
    <?php if (array_filter($jobs, static fn (array $job): bool => in_array($job['status'], ['queued', 'running'], true)) !== []): ?>
    <p class="small text-muted" data-ai-progress-help>Status updates automatically; the draft appears only after checks pass. Waiting for a worker requires a separate worker enabled by an administrator; this page cannot confirm that one is scheduled.</p>
    <?php endif; ?>
    <ul class="list-group list-group-flush">
      <?php foreach ($jobs as $job):
          $progressStage = match ($job['status']) {
              'queued' => 'queued', 'running' => 'generating', 'succeeded', 'completed' => 'ready',
              default => 'stopped',
          };
          $currentStep = array_search($progressStage, array_keys($progressStages), true);
      ?>
      <li class="list-group-item d-flex flex-wrap gap-2 align-items-center justify-content-between" data-ai-request="<?= (int) $job['id'] ?>" data-ai-progress="<?= esc($progressStage, 'attr') ?>">
        <div><strong><?= esc($analysisTitles[$job['analysis_code'] ?? ''] ?? 'Analysis') ?> #<?= (int) $job['id'] ?></strong> · <?= esc($jobStatuses[$job['status']] ?? 'Status unavailable') ?>
          <div class="d-flex align-items-center gap-2 mt-2 mb-2" data-ai-progress-status>
            <?php if ($progressStage === 'generating'): ?>
            <span class="spinner-border spinner-border-sm text-primary" role="img" aria-label="Generating and checking; progress is indeterminate" data-ai-progress-spinner></span>
            <span>Generating and checking. No partial draft is available.</span>
            <?php elseif ($progressStage === 'queued'): ?>
            <span aria-hidden="true">&#9202;</span><span>Queued. Waiting for a worker; processing has not started.</span>
            <?php elseif ($progressStage === 'ready'): ?>
            <span class="text-success" aria-hidden="true">&#10003;</span><span>Ready for review. Generation and checks finished.</span>
            <?php else: ?>
            <span class="badge bg-secondary">Stopped</span><span><?= esc(match ($job['status']) {
                'failed' => 'No draft is ready for review.',
                'cancelled' => 'This waiting request was cancelled.',
                'expired' => 'This request expired; no draft is ready for review.',
                'outcome_unknown' => 'Completion cannot be confirmed; no draft is ready for review.',
                default => 'The request status is unavailable; completion cannot be confirmed.',
            }) ?></span>
            <?php endif; ?>
          </div>
          <ol class="list-unstyled d-flex flex-wrap gap-2 small mb-2" aria-label="Draft request stages" data-ai-progress-steps>
            <?php foreach ($progressStages as $stage => $stageLabel):
                $step = array_search($stage, array_keys($progressStages), true);
                $stepState = $currentStep === false ? 'not-confirmed' : ($step < $currentStep ? 'complete' : ($step === $currentStep ? 'current' : 'pending'));
            ?>
            <li data-ai-stage="<?= esc($stage, 'attr') ?>" data-ai-stage-state="<?= esc($stepState, 'attr') ?>"<?= $stepState === 'current' ? ' aria-current="step"' : '' ?>><span class="badge <?= $stepState === 'current' ? 'bg-primary' : ($stepState === 'complete' ? 'bg-success' : 'bg-secondary') ?>"><?= esc($stageLabel) ?></span></li>
            <?php endforeach; ?>
          </ol>
          <?php if ($job['profile_revision'] !== $profile['revision']): ?><span class="badge bg-secondary">Older configuration</span><?php endif; ?>
          <small class="d-block text-muted">Created <?= esc($time($job['created_at'])) ?> Philippine time
            <?php if ($job['reason']): ?> · <?= esc($jobReasons[$job['reason']] ?? 'This request could not proceed. Ask an administrator for help.') ?><?php endif; ?>
          </small>
          <?php if ($job['status'] === 'outcome_unknown'): ?><p class="small mb-0">The AI service may have processed or charged for this request. It will not be sent again automatically.</p><?php endif; ?>
        </div>
        <?php if ($canGenerate && $job['status'] === 'queued'): ?>
        <form method="post" action="<?= esc(site_url($basePath . '/jobs/' . (int) $job['id'] . '/cancel'), 'attr') ?>" data-ai-action>
          <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger">Cancel waiting request</button>
        </form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div></div>
  <?php endif; ?>
  <h2 class="h5">AI drafts for review</h2>
  <?php if ($insights === []): ?><p class="text-muted">No AI drafts yet. Request a draft using a comparison above if you have permission. A separate worker must process the request before a draft appears. Only current, unexpired drafts for the approved setup are shown.</p><?php endif; ?>
  <?php foreach ($insights as $insight): $draft = $insight['draft']; $narrative = $draft['narrative']; ?>
  <article class="card mb-3"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
      <h3 class="h5 mb-0"><?= esc($analysisTitles[$draft['evidence']['analysis_code'] ?? $insight['analysis_code'] ?? ''] ?? ($unitLabel . ' operations')) ?> insight #<?= (int) $insight['id'] ?></h3>
      <span class="badge bg-secondary"><?= esc($insightStatuses[$insight['status']] ?? 'Review status unavailable') ?></span>
    </div>
    <p class="small text-muted">Created <?= esc($time($insight['created_at'])) ?> Philippine time</p>
    <details class="small mb-2"><summary>AI draft details</summary><p class="mt-2 mb-0">Model: <?= esc($draft['provenance']['model']) ?> · Instruction version: <?= esc($draft['provenance']['prompt_version']) ?></p></details>
    <p class="small text-muted">AI can make mistakes. This draft is not a clinical finding and does not prove why activity changed.</p>
    <p class="fw-semibold"><?= esc($narrative['summary']) ?></p>
    <p><?= esc($narrative['interpretation']) ?></p>
    <?php if ($narrative['abstained']): ?><p class="alert alert-info">The AI did not recommend a program.</p><?php endif; ?>
    <h4 class="h6">Alternative explanations to review</h4>
    <ul><?php foreach ($narrative['alternative_explanations'] as $alternative): ?><li><?= esc($alternative) ?></li><?php endforeach; ?></ul>
    <?= view('ai/evidence', ['evidence' => $draft['evidence']]) ?>
    <?php foreach ($draft['programs'] as $program): ?>
    <section class="border rounded p-3 mb-3" aria-label="Operational program draft">
      <h4 class="h6"><?= esc($program['title']) ?> · program draft</h4>
      <p><?= esc($program['objective']) ?></p>
      <p class="small"><strong>Audience:</strong> <?= esc($program['audience']) ?></p>
      <?php foreach (['activities' => 'Proposed activities', 'resource_assumptions' => 'Resources that may be needed', 'evaluation_measures' => 'How to assess the program', 'limitations' => 'Limits to keep in mind'] as $key => $label): ?>
      <h5 class="h6"><?= esc($label) ?></h5><ul class="small"><?php foreach ($program[$key] as $item): ?><li><?= esc($item) ?></li><?php endforeach; ?></ul>
      <?php endforeach; ?>
      <p class="small">Required reviewer: <?= esc($program['required_reviewer']) ?>. These references guide review and approval; they do not prove the program works.</p>
      <ul class="small mb-0"><?php foreach ($program['references'] as $reference): ?><li><a href="<?= esc($reference['url'], 'attr') ?>" target="_blank" rel="noopener noreferrer" data-navigation="false"><?= esc($reference['title']) ?></a></li><?php endforeach; ?></ul>
    </section>
    <?php endforeach; ?>
    <div class="d-flex gap-2 flex-wrap">
      <?php $decisions = []; if ($canReview && $insight['status'] === 'draft') { $decisions['accept'] = 'Accept insight'; }
        if ($canReview && in_array($insight['status'], ['draft', 'accepted'], true)) { $decisions['reject'] = 'Reject insight'; }
        if ($canApprove && $insight['status'] === 'accepted' && $draft['programs'] !== []) { $decisions['approve'] = 'Approve program draft'; }
        foreach ($decisions as $decision => $label): ?>
      <form method="post" action="<?= esc(site_url($basePath . '/' . (int) $insight['id'] . '/review'), 'attr') ?>" data-ai-action>
        <?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $insight['version'] ?>"><input type="hidden" name="decision" value="<?= esc($decision, 'attr') ?>">
        <button type="submit" class="btn btn-sm <?= $decision === 'reject' ? 'btn-outline-danger' : 'btn-outline-primary' ?>"><?= esc($label) ?></button>
      </form>
      <?php endforeach; ?>
    </div>
    <p class="small text-muted mt-2 mb-0">Accepting records your review; it does not confirm that the AI is correct. Approving a program draft does not publish, fund, schedule, or send anything.</p>
  </div></article>
  <?php endforeach; ?>
</div>

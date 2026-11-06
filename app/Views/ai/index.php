<?php require __DIR__ . '/_scope.php'; ?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<section class="ai-insights" data-ai-workspace data-panel-url="<?= esc(site_url($basePath . '/panel'), 'attr') ?>" aria-label="<?= esc($unitLabel . ' AI insights and program planning', 'attr') ?>">
  <header class="ai-insights__header">
    <div>
      <p class="ai-insights__eyebrow mb-1">Staff workspace</p>
      <h2 class="h4 mb-1"><?= esc($unitLabel) ?> AI insights</h2>
      <p class="mb-0 text-muted">Review approved aggregate activity from <?= esc($comparisonMonthLabel) ?> and <?= esc($completedMonthLabel) ?>. The current month is not included.</p>
    </div>
    <span class="ai-insights__privacy"><i class="bi bi-shield-check" aria-hidden="true"></i> Aggregate data only</span>
  </header>

  <details class="ai-insights__how" data-ai-key="how-this-works">
    <summary data-ai-key="how-this-works-summary">How this works</summary>
    <div class="ai-insights__how-body">
      <ol class="ai-insights__steps">
        <li><strong>Choose an analysis.</strong> Dates, privacy rules, and eligible records are fixed. Individual people and custom AI instructions cannot be selected.</li>
        <li><strong>Preview data.</strong> This shows approved local totals without contacting an AI service. Hidden or unavailable values are not zero and must not be worked out from totals.</li>
        <li><strong>Generate a draft.</strong> This queues a separate worker. The page cannot confirm that a worker is scheduled, and every draft requires staff review.</li>
      </ol>
      <div class="ai-insights__privacy-copy small">
        <p>Only approved combined totals may be used. Patient names, identifiers, charts, complaint descriptions, counselling notes, and individual survey answers are never sent to the AI model.</p>
        <?php if ($unit !== 'bmg'): ?>
        <p>Both completed months need enough different people with completed <?= $unit === 'guidance' ? 'counselling sessions' : 'clinic visits' ?> and valid linked records to meet privacy rules. Repeat visits by one person do not count as different people, and categories must pass separate privacy checks.</p>
        <?php else: ?>
        <p>Both completed months need eligible recorded events for the selected comparison. Missing records do not prove that no activity occurred.</p>
        <?php endif; ?>
        <p class="mb-0">Recorded categories describe service activity, not diagnoses, prevalence, severity, outcomes, or cause. Staff approval does not publish a program or authorize clinical, staffing, or spending changes.</p>
      </div>
    </div>
  </details>

  <noscript><div class="alert alert-warning">Turn on JavaScript to preview data, generate drafts, or review them. Other department functions are unaffected.</div></noscript>
  <p data-ai-feedback role="status" aria-live="polite" aria-atomic="true" class="alert alert-info small" hidden></p>
  <div data-ai-baseline></div>
  <div data-ai-panel><?= view('ai/panel', ['recoverableSnapshots' => $recoverableSnapshots ?? []] + compact('unit', 'basePath', 'analyses', 'legacyAnalysisFixture', 'profile', 'ready', 'reason', 'jobs', 'insights', 'canGenerate', 'canReview', 'canApprove', 'presentationNow')) ?></div>

  <div class="modal fade ai-insights__review-modal" id="ai-insight-review-modal" data-ai-review-modal tabindex="-1" aria-labelledby="ai-insight-review-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <p class="ai-insights__eyebrow mb-1">Staff review</p>
            <h2 class="modal-title h5 mb-0" id="ai-insight-review-title" data-ai-review-modal-title>AI draft</h2>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close draft review"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning small" data-ai-review-stale role="status" aria-live="polite" aria-atomic="true" hidden>
            This draft changed while it was open. Close it and open the current version before recording a decision.
          </div>
          <div data-ai-review-modal-body></div>
        </div>
        <div class="modal-footer ai-insights__review-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
          <div class="ai-insights__review-actions" data-ai-review-modal-actions></div>
        </div>
      </div>
    </div>
  </div>
</section>
<script src="<?= esc(base_url('assets/js/ai-insights.js'), 'attr') ?>"></script>
<?= $this->endSection() ?>

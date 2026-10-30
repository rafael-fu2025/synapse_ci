<?php require __DIR__ . '/_scope.php'; ?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<section data-ai-workspace data-panel-url="<?= esc(site_url($basePath . '/panel'), 'attr') ?>" aria-label="<?= esc($unitLabel . ' insights and program planning', 'attr') ?>">
  <p><strong><?= esc($unitLabel) ?> planning.</strong> Compare <?= esc($comparisonMonthLabel) ?> and <?= esc($completedMonthLabel) ?>, the last two completed months in Philippine time. The current month is not included.</p>
  <p><strong>Prepare local summary</strong> shows approved totals without using AI. <strong>Request AI draft</strong> adds a request to a queue for a separate worker to process. AI drafts need staff review.</p>
  <details class="mb-3">
    <summary>Privacy and how to use these summaries</summary>
    <div class="small mt-2">
      <p>Only approved combined totals may be used. Patient names, identifiers, charts, complaint descriptions, counselling notes, and individual survey answers are never sent to the AI model.</p>
      <?php if ($unit !== 'bmg'): ?>
      <p>Both months must have enough different people with completed <?= $unit === 'guidance' ? 'counselling sessions' : 'clinic visits' ?> and valid linked records to meet the privacy rules. Repeat visits by one person do not count as different people. Categories must also pass privacy checks before they can be shown.</p>
      <?php else: ?>
      <p>Both months must have eligible recorded events for the selected comparison. Missing records do not prove that no activity occurred.</p>
      <?php endif; ?>
      <p>Hidden or unavailable values are not zero. Do not work them out from totals. Recorded categories describe service activity, not diagnoses, how much care people need, or how common a condition is across the campus.</p>
      <p class="mb-0">The local summary supplies the numbers. AI adds an unverified explanation and can suggest reviewed program templates. Staff approval does not publish a program or authorize clinical, staffing, or spending changes.</p>
    </div>
  </details>
  <noscript><div class="alert alert-warning">Turn on JavaScript to prepare summaries, request drafts, or review them. Other department functions are unaffected.</div></noscript>
  <p data-ai-feedback role="status" aria-live="polite" class="alert alert-info small" hidden></p>
  <div data-ai-baseline aria-live="polite"></div>
  <div data-ai-panel><?= view('ai/panel', compact('unit', 'basePath', 'analyses', 'legacyAnalysisFixture', 'profile', 'ready', 'reason', 'jobs', 'insights', 'canGenerate', 'canReview', 'canApprove', 'presentationNow')) ?></div>
</section>
<script src="<?= esc(base_url('assets/js/ai-insights.js'), 'attr') ?>"></script>
<?= $this->endSection() ?>

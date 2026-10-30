<?php
/** Only disclosure-validated aggregate payloads may be passed to this view. */
$analysisCode = $evidence['analysis_code'] ?? 'clinic.activity';
$definition = \App\Services\Ai\AnalysisCatalog::get($analysisCode);
$descriptors = array_column(\App\Services\Ai\AnalysisCatalog::metrics($analysisCode), null, 'metric_code');
$percent = static fn ($value): string => $value === null ? 'Not defined' : number_format((float) $value, 2) . '%';
?>
<div class="card mb-3"><div class="card-body">
  <h3 class="h6">Local summary · <?= esc($definition['title']) ?></h3>
  <p class="small text-muted">Completed calendar months · Philippine time · recorded events<?php if ($definition['unit'] !== 'bmg'): ?>, not different people or how common a condition is across the campus<?php endif; ?></p>
  <?php foreach ($evidence['metrics'] as $metric):
      $descriptor = $descriptors[$metric['metric_code'] ?? 'completed_encounters'] ?? null;
      $hasShare = ($metric['current_denominator'] ?? null) !== null || ($metric['previous_denominator'] ?? null) !== null;
  ?>
  <section class="border rounded p-3 mb-2" aria-label="<?= esc($descriptor['label'] ?? 'Released metric', 'attr') ?>">
    <h4 class="h6"><?= esc($descriptor['label'] ?? 'Released metric') ?></h4>
    <p class="small text-muted">What is counted: <?= esc(match ($metric['unit_of_analysis'] ?? 'encounter') {
            'encounter' => 'completed clinic visits', 'session' => 'completed counselling sessions',
            'batch' => 'batches', 'reported_event' => 'accepted rotation reports', default => 'recorded events',
        }) ?></p>
    <dl class="row mb-2">
      <dt class="col-sm-6"><?= esc($evidence['period']['label']) ?> · count</dt><dd class="col-sm-6"><?= number_format((int) $metric['current_value']) ?></dd>
      <dt class="col-sm-6"><?= esc($evidence['comparison_period']['label']) ?> · count</dt><dd class="col-sm-6"><?= number_format((int) $metric['previous_value']) ?></dd>
      <dt class="col-sm-6">Count change</dt><dd class="col-sm-6"><?= number_format((int) $metric['absolute_change']) ?></dd>
      <dt class="col-sm-6">Change compared with the earlier month (%)</dt><dd class="col-sm-6"><?= esc($percent($metric['relative_change_percent'])) ?></dd>
      <?php if ($hasShare): ?>
      <dt class="col-sm-6">Total used to calculate the share</dt><dd class="col-sm-6"><?= esc($descriptors[$descriptor['denominator_metric_code'] ?? '']['label'] ?? 'Recorded source events') ?></dd>
      <dt class="col-sm-6"><?= esc($evidence['period']['label']) ?> · total for share</dt><dd class="col-sm-6"><?= number_format((int) $metric['current_denominator']) ?></dd>
      <dt class="col-sm-6"><?= esc($evidence['comparison_period']['label']) ?> · total for share</dt><dd class="col-sm-6"><?= number_format((int) $metric['previous_denominator']) ?></dd>
      <dt class="col-sm-6"><?= esc($evidence['period']['label']) ?> · share</dt><dd class="col-sm-6"><?= esc($percent($metric['current_share_percent'])) ?></dd>
      <dt class="col-sm-6"><?= esc($evidence['comparison_period']['label']) ?> · share</dt><dd class="col-sm-6"><?= esc($percent($metric['previous_share_percent'])) ?></dd>
      <dt class="col-sm-6">Share change (percentage points)</dt><dd class="col-sm-6"><?= $metric['share_change_points'] === null ? 'Not defined' : esc(number_format((float) $metric['share_change_points'], 2)) ?></dd>
      <?php endif; ?>
    </dl>
  </section>
  <?php endforeach; ?>
  <p class="small mb-2">Only values that passed privacy checks are shown. Hidden or missing values are not zero.</p>
  <details><summary>Privacy and limits of this comparison</summary>
  <p class="small mt-2">Do not work out hidden, excluded, missing, or unrecognised categories from totals. Shares may not add up to 100%. A share cannot be calculated when its total is zero; that is not the same as 0%.</p>
  <p class="small">Two months alone cannot show whether a change is meaningful, repeats seasonally, or has a particular cause. They do not show how much care is needed or whether a program works.
    <?php if ($analysisCode === 'bmg.batches'): ?>Batch starts, releases, and voids can refer to different batches. These are not completion rates.<?php endif; ?>
    <?php if ($analysisCode === 'bmg.rotations'): ?>The share of reports marked as failures does not measure machine reliability.<?php endif; ?>
  </p>
  <ul class="small mt-2 mb-0">
    <?php foreach ($evidence['limitations'] as $limitation): ?><li><?= esc($limitation) ?></li><?php endforeach; ?>
  </ul></details>
</div></div>

<?php
/** Only disclosure-validated aggregate payloads may be passed to this view. */
$analysisCode = $evidence['analysis_code'] ?? 'clinic.activity';
$definition = \App\Services\Ai\AnalysisCatalog::get($analysisCode);
$descriptors = array_column(\App\Services\Ai\AnalysisCatalog::metrics($analysisCode), null, 'metric_code');
$percent = static fn ($value): string => $value === null ? 'Not defined' : number_format((float) $value, 2) . '%';
$number = static fn ($value): string => $value === null ? 'Not defined' : number_format((int) $value);
$point = static fn ($value): string => $value === null ? 'Not defined' : number_format((float) $value, 2);
?>
<section class="ai-insights__evidence" aria-labelledby="ai-evidence-<?= esc(hash('sha256', $analysisCode . ($evidence['period']['label'] ?? '')), 'attr') ?>">
  <div class="ai-insights__evidence-heading">
    <div>
      <p class="ai-insights__eyebrow mb-1">Approved local data</p>
      <h3 class="h6 mb-1" id="ai-evidence-<?= esc(hash('sha256', $analysisCode . ($evidence['period']['label'] ?? '')), 'attr') ?>"><?= esc($definition['title']) ?></h3>
      <p class="small text-muted mb-0">Completed calendar months · Philippine time · recorded events<?php if ($definition['unit'] !== 'bmg'): ?>, not different people or campus prevalence<?php endif; ?></p>
    </div>
  </div>

  <div class="ai-insights__evidence-warning" role="note">
    <i class="bi bi-eye-slash" aria-hidden="true"></i>
    <p class="mb-0"><strong>Hidden or unavailable values are not zero.</strong> Do not derive them from totals. This two-month comparison describes recorded activity and cannot establish cause, seasonality, significance, need, or program effectiveness.</p>
  </div>

  <div class="table-responsive ai-insights__evidence-table-wrap">
    <table class="table table-sm align-middle ai-insights__evidence-table">
      <caption>Released values for <?= esc($evidence['comparison_period']['label']) ?> and <?= esc($evidence['period']['label']) ?>. Counts and server-released comparisons are shown without charts or judgment labels.</caption>
      <thead>
        <tr>
          <th scope="col">Released metric</th>
          <th scope="col"><?= esc($evidence['comparison_period']['label']) ?></th>
          <th scope="col"><?= esc($evidence['period']['label']) ?></th>
          <th scope="col">Released comparison</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($evidence['metrics'] as $metric):
          $descriptor = $descriptors[$metric['metric_code'] ?? 'completed_encounters'] ?? null;
          $hasShare = ($metric['current_denominator'] ?? null) !== null || ($metric['previous_denominator'] ?? null) !== null;
          $unitOfAnalysis = match ($metric['unit_of_analysis'] ?? 'encounter') {
              'encounter' => 'completed clinic visits', 'session' => 'completed counselling sessions',
              'batch' => 'batches', 'reported_event' => 'accepted rotation reports', default => 'recorded events',
          };
      ?>
        <tr data-ai-evidence-metric="<?= esc($metric['metric_code'] ?? 'released', 'attr') ?>">
          <th scope="row"><span><?= esc($descriptor['label'] ?? 'Released metric') ?></span><small>Counted unit: <?= esc($unitOfAnalysis) ?></small></th>
          <td><span class="ai-insights__metric-value"><?= esc($number($metric['previous_value'] ?? null)) ?></span><?php if ($hasShare): ?><small><?= esc($percent($metric['previous_share_percent'] ?? null)) ?> share of <?= esc($number($metric['previous_denominator'] ?? null)) ?> <?= esc($descriptors[$descriptor['denominator_metric_code'] ?? '']['label'] ?? 'recorded source events') ?></small><?php endif; ?></td>
          <td><span class="ai-insights__metric-value"><?= esc($number($metric['current_value'] ?? null)) ?></span><?php if ($hasShare): ?><small><?= esc($percent($metric['current_share_percent'] ?? null)) ?> share of <?= esc($number($metric['current_denominator'] ?? null)) ?> <?= esc($descriptors[$descriptor['denominator_metric_code'] ?? '']['label'] ?? 'recorded source events') ?></small><?php endif; ?></td>
          <td>
            <span><?= esc($number($metric['absolute_change'] ?? null)) ?> count change</span>
            <small><?= esc($percent($metric['relative_change_percent'] ?? null)) ?> compared with the earlier month<?php if ($hasShare): ?> · <?= esc($point($metric['share_change_points'] ?? null)) ?> percentage-point share change<?php endif; ?></small>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <details class="ai-insights__limits" data-ai-key="evidence-limits-<?= esc(hash('sha256', $analysisCode . ($evidence['period']['label'] ?? '')), 'attr') ?>">
    <summary>Privacy and limits of this comparison</summary>
    <div class="mt-3">
      <p class="small">Do not work out hidden, excluded, missing, or unrecognised categories from totals. Shares may not add up to 100%. A share is <strong>Not defined</strong> when its total is zero; that is not the same as 0%.</p>
      <?php if ($analysisCode === 'bmg.batches'): ?><p class="small">Batch starts, releases, and voids can refer to different batches. These are not completion rates.</p><?php endif; ?>
      <?php if ($analysisCode === 'bmg.rotations'): ?><p class="small">The share of reports marked as failures does not measure machine reliability.</p><?php endif; ?>
      <ul class="small mb-0"><?php foreach ($evidence['limitations'] as $limitation): ?><li><?= esc($limitation) ?></li><?php endforeach; ?></ul>
    </div>
  </details>
</section>

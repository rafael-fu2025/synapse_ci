<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<?php $period = $summary['period']; ?>
<div class="d-flex flex-wrap gap-2 d-print-none cr-page-actions">
  <form method="post" action="<?= esc(site_url('/clinic/reports/pdf'), 'attr') ?>" class="cr-pdf-form" data-report-download data-cr-pdf-form>
    <?= csrf_field() ?>
    <input type="hidden" name="preset" value="<?= esc($period['preset'], 'attr') ?>" data-cr-pdf-preset>
    <input type="hidden" name="from" value="<?= esc($period['from'], 'attr') ?>" data-cr-pdf-from>
    <input type="hidden" name="to" value="<?= esc($period['to'], 'attr') ?>" data-cr-pdf-to>
    <button type="submit" name="format" value="pdf" class="btn btn-primary btn-sm cr-action-pdf" data-cr-pdf-submit>
      <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Export LaTeX PDF
    </button>
  </form>
  <button type="button" class="btn btn-outline-secondary btn-sm cr-action-print" onclick="window.print()">
    <i class="bi bi-printer" aria-hidden="true"></i> Print
  </button>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$shortPresetLabels = [
    'weekly'      => 'Weekly',
    'monthly'     => 'Month',
    'semester'    => 'Semester',
    'school_year' => 'School Year · CHED',
    'custom'      => 'Custom',
];
?>
<section class="cr-workspace" data-cr-workspace data-endpoint="<?= esc(site_url('/clinic/reports'), 'attr') ?>">
  <!-- Instant-Switch Reporting Horizon Strip -->
  <div class="cr-horizon-shell d-print-none">
    <div class="cr-horizon-row">
      <div class="cr-horizon-tabs-wrap">
        <span class="cr-horizon-label">Reporting horizon</span>
        <nav class="cr-preset-tabs" aria-label="Reporting horizon presets">
          <?php foreach ($presets as $pKey => $pLabel):
              $isCurrent = $period['preset'] === $pKey;
              $tabUrl = site_url('/clinic/reports?' . http_build_query(['preset' => $pKey]));
          ?>
            <a
              href="<?= esc($tabUrl, 'attr') ?>"
              class="cr-preset-tab<?= $isCurrent ? ' is-active' : '' ?>"
              data-cr-preset="<?= esc($pKey, 'attr') ?>"
              data-navigation="false"
              <?= $isCurrent ? 'aria-current="page"' : '' ?>
              title="<?= esc($pLabel, 'attr') ?>"
            ><?= esc($shortPresetLabels[$pKey] ?? $pLabel) ?></a>
          <?php endforeach; ?>
        </nav>
      </div>

      <span class="cr-live-status" data-cr-status role="status" aria-live="polite"></span>
    </div>

    <form
      method="GET"
      action="<?= esc(site_url('/clinic/reports'), 'attr') ?>"
      class="cr-custom-form clinic-report-filters"
      data-cr-custom-form
      <?= $period['preset'] === 'custom' ? '' : 'hidden' ?>
    >
      <input type="hidden" name="preset" value="custom">
      <div class="cr-date-field">
        <label for="reportFromDisplay" class="form-label">From Date (Max 366d)</label>
        <input type="text" class="form-control form-control-sm" id="reportFromDisplay" data-datepicker data-target="#reportFromIso" placeholder="DD/MM/YYYY">
        <input type="hidden" name="from" id="reportFromIso" value="<?= esc($period['from'], 'attr') ?>">
      </div>
      <div class="cr-date-field">
        <label for="reportToDisplay" class="form-label">To Date</label>
        <input type="text" class="form-control form-control-sm" id="reportToDisplay" data-datepicker data-target="#reportToIso" placeholder="DD/MM/YYYY">
        <input type="hidden" name="to" id="reportToIso" value="<?= esc($period['to'], 'attr') ?>">
      </div>
      <div class="cr-date-submit">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel" aria-hidden="true"></i> Apply range</button>
      </div>
    </form>

    <div class="cr-error-bar" data-cr-error role="alert" hidden>
      <span data-cr-error-message></span>
      <button type="button" class="btn btn-outline-danger btn-sm" data-cr-retry>Retry</button>
    </div>
  </div>

  <!-- Live Replaceable Report Body -->
  <div data-cr-body>
    <?= view('clinic/reports/_body', compact('summary', 'presets'), ['saveData' => false]) ?>
  </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/clinic-report.js'), 'attr') ?>"></script>
<?= $this->endSection() ?>

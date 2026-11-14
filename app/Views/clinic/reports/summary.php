<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<?php
$period = $summary['period'];
$totals = $summary['totals'];
$pdfQuery = http_build_query([
    'preset' => $period['preset'],
    'from'   => $period['from'],
    'to'     => $period['to'],
]);
?>
<div class="d-flex flex-wrap gap-2 d-print-none">
  <a href="<?= site_url('/clinic/reports/pdf?' . $pdfQuery) ?>" class="btn btn-danger btn-sm" target="_blank" rel="noopener">
    <i class="bi bi-file-earmark-pdf me-1"></i> Export LaTeX PDF
  </a>
  <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
    <i class="bi bi-printer me-1"></i> Print
  </button>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<!-- Filter / Reporting Horizon Bar -->
<div class="card border-0 shadow-sm mb-4 d-print-none" x-data="{ preset: '<?= esc($period['preset']) ?>' }">
  <div class="card-body py-3">
    <form method="GET" action="<?= site_url('/clinic/reports') ?>" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label for="reportPreset" class="form-label small fw-semibold mb-1">Reporting Horizon</label>
        <select name="preset" id="reportPreset" class="form-select form-select-sm" x-model="preset">
          <?php foreach ($presets as $pKey => $pLabel): ?>
            <option value="<?= esc($pKey) ?>" <?= $period['preset'] === $pKey ? 'selected' : '' ?>><?= esc($pLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3" x-show="preset === 'custom'">
        <label for="reportFromDisplay" class="form-label small fw-semibold mb-1">From Date (Max 366d)</label>
        <input type="text" class="form-control form-control-sm" id="reportFromDisplay" data-datepicker data-target="#reportFromIso" placeholder="DD/MM/YYYY">
        <input type="hidden" name="from" id="reportFromIso" value="<?= esc($period['from']) ?>">
      </div>
      <div class="col-md-3" x-show="preset === 'custom'">
        <label for="reportToDisplay" class="form-label small fw-semibold mb-1">To Date</label>
        <input type="text" class="form-control form-control-sm" id="reportToDisplay" data-datepicker data-target="#reportToIso" placeholder="DD/MM/YYYY">
        <input type="hidden" name="to" id="reportToIso" value="<?= esc($period['to']) ?>">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i> Generate</button>
      </div>
    </form>
  </div>
</div>

<div class="mb-3 small text-muted">
  <strong>Coverage:</strong> <?= esc($period['label']) ?> · <strong><?= esc($period['from']) ?></strong> through <strong><?= esc($period['to']) ?></strong> (Asia/Manila) · Current SY: <strong><?= esc($summary['school_year']) ?></strong>
</div>

<?php if (!empty($summary['isolation_alerts'])): ?>
  <div class="alert alert-warning border-0 shadow-sm mb-4">
    <strong><i class="bi bi-exclamation-triangle-fill me-1"></i> Communicable Cluster Advisories (14-day window):</strong>
    <?php foreach ($summary['isolation_alerts'] as $a): ?>
      <span class="badge bg-white text-dark border ms-1"><?= esc($a['condition_label']) ?>: <?= (int) ($a['case_count'] ?? 0) ?> (<?= esc($a['cohort_summary'] ?? 'Campus') ?>)</span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Top KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-3 col-sm-6">
    <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
      <div class="card-body">
        <div class="text-muted small text-uppercase fw-semibold">Total Encounters</div>
        <div class="h2 fw-bold text-primary mt-1 mb-0"><?= number_format((int) $totals['encounters']) ?></div>
        <div class="small text-muted">Students: <?= (int) $totals['student'] ?> · Employees: <?= (int) $totals['employee'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6">
    <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
      <div class="card-body">
        <div class="text-muted small text-uppercase fw-semibold">Completed &amp; Referred</div>
        <div class="h2 fw-bold text-success mt-1 mb-0"><?= number_format((int) $totals['completed'] + (int) $totals['referred']) ?></div>
        <div class="small text-muted">Completed: <?= (int) $totals['completed'] ?> · Referred: <?= (int) $totals['referred'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6">
    <div class="card border-0 shadow-sm h-100 border-start border-info border-4">
      <div class="card-body">
        <div class="text-muted small text-uppercase fw-semibold">Medicines Dispensed</div>
        <div class="h2 fw-bold text-info mt-1 mb-0"><?= number_format((int) $totals['dispensed']) ?></div>
        <div class="small text-muted">Net units allocated (FEFO)</div>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6">
    <div class="card border-0 shadow-sm h-100 border-start border-danger border-4">
      <div class="card-body">
        <div class="text-muted small text-uppercase fw-semibold">Communicable &amp; Clearances</div>
        <div class="h2 fw-bold text-danger mt-1 mb-0"><?= number_format((int) $totals['isolation']) ?> <span class="fs-6 text-muted fw-normal">isolations</span></div>
        <div class="small text-muted">SY Cleared: <?= (int) ($summary['clearances']['cleared'] ?? 0) ?> · Hold: <?= (int) ($summary['clearances']['hold_followup'] ?? 0) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Monthly Trend Chart & Table -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center">
    <h6 class="fw-bold mb-0"><i class="bi bi-bar-chart-line me-1 text-primary"></i> Monthly Encounter Volume</h6>
  </div>
  <div class="card-body">
    <?php if (empty($summary['monthly_series'])): ?>
      <p class="text-muted small mb-0">No clinical encounters recorded within this reporting range.</p>
    <?php else: ?>
      <div id="clinicSummaryMonthlyChart" style="min-height: 240px;"></div>
      <div class="table-responsive mt-3">
        <table class="table table-sm table-bordered small mb-0">
          <thead class="table-light">
            <tr>
              <?php foreach ($summary['monthly_series'] as $m): ?>
                <th class="text-center"><?= esc($m['label']) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <tr>
              <?php foreach ($summary['monthly_series'] as $m): ?>
                <td class="text-center fw-bold"><?= number_format((int) $m['count']) ?></td>
              <?php endforeach; ?>
            </tr>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Detailed Breakdown Grids -->
<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent py-3">
        <h6 class="fw-bold mb-0">Chief Complaints Breakdown</h6>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover table-sm small mb-0">
          <thead class="table-light"><tr><th class="ps-3">Category / Complaint</th><th class="text-end pe-3">Cases</th></tr></thead>
          <tbody>
            <?php if (empty($summary['complaints'])): ?>
              <tr><td colspan="2" class="text-center py-3 text-muted">No recorded complaints.</td></tr>
            <?php else: ?>
              <?php foreach ($summary['complaints'] as $label => $cnt): ?>
                <tr><td class="ps-3"><?= esc($label) ?></td><td class="text-end pe-3 fw-bold"><?= number_format((int) $cnt) ?></td></tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent py-3">
        <h6 class="fw-bold mb-0">Clinical Dispositions</h6>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover table-sm small mb-0">
          <thead class="table-light"><tr><th class="ps-3">Outcome / Disposition</th><th class="text-end pe-3">Encounters</th></tr></thead>
          <tbody>
            <?php if (empty($summary['dispositions'])): ?>
              <tr><td colspan="2" class="text-center py-3 text-muted">No dispositions logged.</td></tr>
            <?php else: ?>
              <?php foreach ($summary['dispositions'] as $label => $cnt): ?>
                <tr><td class="ps-3"><?= esc($label) ?></td><td class="text-end pe-3 fw-bold"><?= number_format((int) $cnt) ?></td></tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent py-3">
        <h6 class="fw-bold mb-0">Top Dispensed Medicines (Net FEFO)</h6>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover table-sm small mb-0">
          <thead class="table-light"><tr><th class="ps-3">Medicine</th><th class="text-end">Encounters</th><th class="text-end pe-3">Units</th></tr></thead>
          <tbody>
            <?php if (empty($summary['top_medicines'])): ?>
              <tr><td colspan="3" class="text-center py-3 text-muted">No medicines dispensed in this range.</td></tr>
            <?php else: ?>
              <?php foreach ($summary['top_medicines'] as $med): ?>
                <tr>
                  <td class="ps-3"><?= esc($med['name']) ?></td>
                  <td class="text-end"><?= (int) $med['encounter_count'] ?></td>
                  <td class="text-end pe-3 fw-bold"><?= number_format((int) $med['total_qty']) ?> <?= esc($med['unit']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent py-3">
        <h6 class="fw-bold mb-0">Communicable Diseases &amp; SY Clearances</h6>
      </div>
      <div class="card-body">
        <h6 class="small fw-bold text-uppercase text-muted">Communicable Cases in Range</h6>
        <?php if (empty($summary['isolation_summary'])): ?>
          <p class="small text-muted">No communicable isolation cases logged within range.</p>
        <?php else: ?>
          <ul class="list-group list-group-flush small mb-3">
            <?php foreach ($summary['isolation_summary'] as $iso): ?>
              <li class="list-group-item px-0 d-flex justify-content-between">
                <span><?= esc($iso['label']) ?></span>
                <strong><?= (int) $iso['count'] ?></strong>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <h6 class="small fw-bold text-uppercase text-muted mt-3">School-Year <?= esc($summary['school_year']) ?> Enrollment Health Clearances</h6>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <span class="badge bg-success-subtle text-success border px-3 py-2">Cleared: <strong><?= (int) ($summary['clearances']['cleared'] ?? 0) ?></strong></span>
          <span class="badge bg-warning-subtle text-warning-emphasis border px-3 py-2">Pending Labs: <strong><?= (int) ($summary['clearances']['pending'] ?? 0) ?></strong></span>
          <span class="badge bg-danger-subtle text-danger border px-3 py-2">Hold / Follow-up: <strong><?= (int) ($summary['clearances']['hold_followup'] ?? 0) ?></strong></span>
        </div>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function() {
  const el = document.querySelector('#clinicSummaryMonthlyChart');
  const seriesData = <?= json_encode(array_map(static fn ($m) => (int) $m['count'], $summary['monthly_series'] ?? [])) ?>;
  const labels = <?= json_encode(array_map(static fn ($m) => (string) $m['label'], $summary['monthly_series'] ?? [])) ?>;
  if (!el || !seriesData.length || typeof ApexCharts === 'undefined' || typeof ChartTheme === 'undefined') return;
  const initChart = () => {
    ChartTheme.create(el, function(t) {
      return ChartTheme.base(t, {
        chart: { type: 'bar', height: 240 },
        series: [{ name: 'Encounters', data: seriesData }],
        colors: [t['series-1']],
        xaxis: { categories: labels }
      });
    });
    ChartTheme.initialize();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initChart);
  else initChart();
})();
</script>
<?= $this->endSection() ?>


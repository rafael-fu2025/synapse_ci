<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex gap-2">
  <a href="<?= site_url('/guidance/cases/create') ?>" class="btn btn-primary btn-sm" data-app-modal-open>
    <i class="bi bi-folder-plus me-1"></i> Open New Case
  </a>
  <a href="<?= site_url('/guidance/queue') ?>" class="btn btn-outline-info btn-sm">
    <i class="bi bi-clock-history me-1"></i> Guidance Queue
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-dashboard nd-dashboard" data-queue-dashboard="guidance" data-queue-counts-url="<?= site_url('/guidance/dashboard/queue-counts') ?>" data-queue-date="<?= esc(\App\Services\Scheduling\SchedulingClock::today(), 'attr') ?>">
  <!-- Welcome Banner -->
  <div class="card border-0 shadow-sm text-white p-4 mb-4 nd-welcome-banner nd-welcome-banner--admin">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <h2 class="h4 fw-bold mb-1 text-white">Welcome back, <?= esc($user['name'] ?? 'Counselor') ?>!</h2>
        <p class="mb-0 opacity-75">
          Foundation University Guidance & Counseling Center · Workstation Console · <?= date('l, F j, Y') ?>
        </p>
      </div>
      <div class="d-none d-md-block text-end">
        <span class="badge bg-white text-primary px-3 py-2 fw-semibold">
          <?= esc(ucwords(str_replace('_', ' ', $user['role'] ?? 'Counselor'))) ?>
        </span>
      </div>
    </div>
  </div>

  <p class="small text-muted mb-3" data-queue-feedback role="status" hidden></p>
  <!-- NiceAdmin v3 KPI Rail -->
  <section class="row g-3 mb-4 nd-kpi-rail">
    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-leads">
        <span class="nd-kpi-icon"><i class="bi bi-folder2-open"></i></span>
        <span class="nd-kpi-label">Active Counseling Cases</span>
        <strong class="nd-kpi-value"><?= number_format($activeCases) ?></strong>
        <span class="nd-kpi-trend up">
          <i class="bi bi-check2-circle"></i> <?= number_format($closedCases) ?> closed cases
        </span>
      </article>
    </div>

    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-revenue">
        <span class="nd-kpi-icon"><i class="bi bi-chat-heart"></i></span>
        <span class="nd-kpi-label">Completed Sessions</span>
        <strong class="nd-kpi-value"><?= number_format($completedSessions) ?></strong>
        <span class="nd-kpi-trend up">
          <i class="bi bi-shield-lock-fill"></i> AES-256-GCM notes
        </span>
      </article>
    </div>

    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-cycle">
        <span class="nd-kpi-icon"><i class="bi bi-clock-history"></i></span>
        <span class="nd-kpi-label">Waiting Queue Today</span>
        <strong class="nd-kpi-value" data-queue-count="queueToday"><?= number_format($queueToday) ?></strong>
        <span class="nd-kpi-trend <?= ($queueToday > 0) ? 'up' : '' ?>" data-queue-trend="queueToday">
          <i class="bi bi-people"></i> Walk-in consultations
        </span>
      </article>
    </div>

    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-retention">
        <span class="nd-kpi-icon"><i class="bi bi-arrow-left-right"></i></span>
        <span class="nd-kpi-label">Incoming Referrals</span>
        <strong class="nd-kpi-value"><?= number_format($pendingReferrals) ?></strong>
        <span class="nd-kpi-trend down">
          <i class="bi bi-hospital"></i> From University Clinic
        </span>
      </article>
    </div>
  </section>

  <!-- Visualizations & Analytics -->
  <section class="row g-3 mb-4">
    <!-- Left Column: Session Activity Chart & Recent Cases -->
    <div class="col-xl-8">
      <div class="card mb-3 border-0 shadow-sm">
        <div class="card-header nd-chart-header bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Counseling Sessions & Engagements Velocity</h5>
          <div class="badge bg-light text-muted border">Last 7 Days</div>
        </div>
        <div class="card-body">
          <div class="nd-chart-summary">
            <div>
              <span>Active Cases</span>
              <strong><?= number_format($activeCases) ?></strong>
            </div>
            <div>
              <span>Completed Sessions</span>
              <strong><?= number_format($completedSessions) ?></strong>
            </div>
            <div>
              <span>Today's Queue</span>
              <strong data-queue-count="queueToday"><?= number_format($queueToday) ?></strong>
            </div>
          </div>
          <div id="guidanceActivityChart" class="nd-chart-canvas"></div>
        </div>
      </div>

      <!-- Recent Counseling Cases -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Recent Counseling Cases</h5>
          <a href="<?= site_url('/guidance/cases') ?>" class="nd-inline-link">View all cases <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0 nd-matrix-table">
              <thead class="table-light">
                <tr>
                  <th class="ps-3">Case #</th>
                  <th>Client</th>
                  <th>Category</th>
                  <th>Counselor</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($recentCases)): ?>
                  <tr><td colspan="5" class="text-center py-4 text-muted">No counseling cases recorded yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($recentCases as $rc): ?>
                    <tr>
                      <td class="ps-3 fw-bold">
                        <a href="<?= site_url('/guidance/cases/' . $rc['id']) ?>" class="text-decoration-none">
                          <?= esc($rc['case_number']) ?>
                        </a>
                      </td>
                      <td>
                        <strong><?= esc($rc['last_name'] . ', ' . $rc['first_name']) ?></strong>
                        <small class="d-block text-muted">ID: <?= esc($rc['mis_id']) ?></small>
                      </td>
                      <td>
                        <span class="badge bg-light text-dark border text-capitalize">
                          <?= esc($rc['category']) ?>
                        </span>
                      </td>
                      <td><?= esc($rc['counsellor_name'] ?? 'Assigned Staff') ?></td>
                      <td>
                        <span class="badge <?= in_array($rc['status'], ['open', 'active'], true) ? 'bg-success' : 'bg-secondary' ?>">
                          <?= esc(ucfirst($rc['status'])) ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Column: Categories Donut & Hub -->
    <div class="col-xl-4">
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Case Focus Categories</h5>
        </div>
        <div class="card-body">
          <div id="guidanceCategoryChart" class="nd-donut-canvas"></div>
          <div class="nd-acq-list mt-2">
            <div><span>Academic</span><strong><?= $categoryCounts['academic'] ?></strong></div>
            <div><span>Personal</span><strong><?= $categoryCounts['personal'] ?></strong></div>
            <div><span>Career</span><strong><?= $categoryCounts['career'] ?></strong></div>
            <div><span>Crisis</span><strong class="text-danger"><?= $categoryCounts['crisis'] ?></strong></div>
            <div><span>Behavioral</span><strong><?= $categoryCounts['behavioral'] ?></strong></div>
            <div><span>Family</span><strong><?= $categoryCounts['family'] ?></strong></div>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Guidance Operations Hub</h5>
        </div>
        <div class="card-body p-3">
          <div class="d-grid gap-2">
            <a href="<?= site_url('/guidance/cases') ?>" class="btn btn-outline-primary text-start p-2">
              <i class="bi bi-folder-check me-2"></i> Counseling Cases Registry
            </a>
            <a href="<?= site_url('/guidance/queue') ?>" class="btn btn-outline-secondary text-start p-2">
              <i class="bi bi-clock-history me-2"></i> Student & Staff Walk-in Queue
            </a>
            <a href="<?= site_url('/guidance/referrals') ?>" class="btn btn-outline-secondary text-start p-2">
              <i class="bi bi-arrow-left-right me-2"></i> Incoming Clinical Referrals
            </a>
            <?php if (in_array($user['role'] ?? '', ['superadmin', 'guidance_admin', 'guidance_supervisor'], true)
                && (($user['role'] ?? '') === 'superadmin' || in_array('guidance.surveys.manage', $user['permissions'] ?? [], true))): ?>
            <a href="<?= site_url('/guidance/surveys') ?>" class="btn btn-outline-secondary text-start p-2">
              <i class="bi bi-clipboard2-check me-2"></i> Campus Guidance Surveys
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Periodic Non-PII CHED / Monthly / School-Year Summary -->
  <section class="card border-0 shadow-sm mt-4" aria-labelledby="guidance-periodic-summary-title">
    <div class="card-body p-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
          <h3 id="guidance-periodic-summary-title" class="h6 fw-bold mb-0"><i class="bi bi-calendar-range text-primary me-2" aria-hidden="true"></i>Periodic Guidance Service Summary (CHED / Monthly / School Year)</h3>
          <small class="text-muted">Aggregate, non-PII counts across Manila calendar dates (up to 366 days per report).</small>
        </div>
        <a id="gd-summary-csv" href="<?= esc(site_url('/guidance/reports/summary?from=' . date('Y-m-01') . '&to=' . date('Y-m-d') . '&format=csv'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-download me-1" aria-hidden="true"></i>Download CSV Summary
        </a>
      </div>
      <div class="row g-2 align-items-end mb-3">
        <div class="col-md-3">
          <label for="gd-summary-from-display" class="form-label small fw-semibold mb-1">Period Start</label>
          <input id="gd-summary-from-display" type="text" class="form-control form-control-sm" data-datepicker data-target="#gd-summary-from" value="<?= esc(date('d/m/Y', strtotime(date('Y-m-01'))), 'attr') ?>" autocomplete="off">
          <input id="gd-summary-from" type="hidden" value="<?= esc(date('Y-m-01'), 'attr') ?>">
        </div>
        <div class="col-md-3">
          <label for="gd-summary-to-display" class="form-label small fw-semibold mb-1">Period End</label>
          <input id="gd-summary-to-display" type="text" class="form-control form-control-sm" data-datepicker data-target="#gd-summary-to" value="<?= esc(date('d/m/Y'), 'attr') ?>" autocomplete="off">
          <input id="gd-summary-to" type="hidden" value="<?= esc(date('Y-m-d'), 'attr') ?>">
        </div>
        <div class="col-md-3">
          <button type="button" id="gd-summary-load" class="btn btn-primary btn-sm">
            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Compute Period Counts
          </button>
        </div>
      </div>
      <div id="gd-summary-grid" class="row g-2 small text-muted">
        <div class="col-12">Click "Compute Period Counts" or download CSV for custom school-year / monthly reporting totals.</div>
      </div>
    </div>
  </section>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/queue-live.js') ?>"></script>
<script>
  (function() {
    'use strict';
    function initGuidanceDashboard() {
      var activityTarget = document.querySelector('#guidanceActivityChart');
      var categoryTarget = document.querySelector('#guidanceCategoryChart');
      if (typeof ApexCharts === 'undefined' || typeof ChartTheme === 'undefined') return;

      var activitySeries = [{
        name: 'Counseling Sessions',
        data: <?= json_encode($chartSeries) ?>
      }];
      var activityCategories = <?= json_encode($chartDays) ?>;

      var catData = [
        <?= (int) $categoryCounts['academic'] ?>,
        <?= (int) $categoryCounts['personal'] ?>,
        <?= (int) $categoryCounts['career'] ?>,
        <?= (int) $categoryCounts['crisis'] ?>,
        <?= (int) $categoryCounts['behavioral'] ?>,
        <?= (int) $categoryCounts['family'] ?>
      ];
      var totalCat = catData.reduce(function(a, b) { return a + b; }, 0);
      if (totalCat === 0) catData = [1, 0, 0, 0, 0, 0];

      ChartTheme.create(activityTarget, function(t) {
        return ChartTheme.base(t, {
          chart: { type: 'area', height: 300 },
          series: activitySeries,
          colors: [t['series-1']],
          stroke: { width: 3 },
          fill: {
            type: 'gradient',
            gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 100] }
          },
          xaxis: { categories: activityCategories }
        });
      });

      ChartTheme.create(categoryTarget, function(t) {
        return ChartTheme.donut(t, {
          series: catData,
          labels: ['Academic', 'Personal', 'Career', 'Crisis', 'Behavioral', 'Family'],
          colors: ChartTheme.themedSlices(t, ChartTheme.categorical(t)),
          plotOptions: {
            pie: {
              donut: {
                labels: {
                  total: { show: true, label: 'Total Cases', formatter: function() { return totalCat; } }
                }
              }
            }
          }
        });
      });

      ChartTheme.initialize();
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initGuidanceDashboard);
    } else {
      initGuidanceDashboard();
    }
    document.addEventListener('synapse:page-load', initGuidanceDashboard);

    function initPeriodicSummary() {
      var loadBtn = document.getElementById('gd-summary-load');
      var fromEl = document.getElementById('gd-summary-from');
      var toEl = document.getElementById('gd-summary-to');
      var csvEl = document.getElementById('gd-summary-csv');
      var gridEl = document.getElementById('gd-summary-grid');
      if (!loadBtn || !fromEl || !toEl || !gridEl || loadBtn.dataset.bound === '1') return;
      loadBtn.dataset.bound = '1';

      function updateCsvLink() {
        if (!csvEl) return;
        csvEl.href = <?= json_encode(site_url('/guidance/reports/summary')) ?>
          + '?from=' + encodeURIComponent(fromEl.value)
          + '&to=' + encodeURIComponent(toEl.value)
          + '&format=csv';
      }

      loadBtn.addEventListener('click', function () {
        updateCsvLink();
        gridEl.innerHTML = '<div class="col-12 text-muted">Computing aggregate counts...</div>';
        fetch(<?= json_encode(site_url('/guidance/reports/summary')) ?> + '?from=' + encodeURIComponent(fromEl.value) + '&to=' + encodeURIComponent(toEl.value), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.success || !data.summary) {
            gridEl.innerHTML = '<div class="col-12 text-danger">' + (data.message || 'Unable to load summary.') + '</div>';
            return;
          }
          var s = data.summary;
          var cards = [
            ['Cases Opened', s.cases_opened],
            ['Cases Closed', s.cases_closed],
            ['PSP Sessions (Associates)', s.psp_sessions_completed],
            ['Counseling Sessions', s.counseling_sessions_completed],
            ['Intakes Submitted', s.intakes_submitted],
            ['Call Slips Issued', s.call_slips_issued],
            ['MI Assessments Completed', s.mi_assessments_completed],
            ['Surveys Submitted', s.surveys_submitted]
          ];
          gridEl.innerHTML = cards.map(function (c) {
            return '<div class="col-6 col-md-3"><div class="border rounded p-2"><div class="text-muted">' + c[0] + '</div><div class="h5 fw-bold text-dark mb-0">' + Number(c[1] || 0) + '</div></div></div>';
          }).join('');
        })
        .catch(function () {
          gridEl.innerHTML = '<div class="col-12 text-danger">Unable to fetch periodic summary right now.</div>';
        });
      });
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initPeriodicSummary);
    } else {
      initPeriodicSummary();
    }
    document.addEventListener('synapse:page-load', initPeriodicSummary);
  })();
</script>
<?= $this->endSection() ?>

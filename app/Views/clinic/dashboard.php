<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex gap-2">
  <a href="<?= site_url('/clinic/queue') ?>" class="btn btn-primary btn-sm">
    <i class="bi bi-kanban me-1"></i> Live Queue
  </a>
  <a href="<?= site_url('/clinic/appointments/create') ?>" class="btn btn-outline-primary btn-sm" data-app-modal-open>
    <i class="bi bi-calendar-plus me-1"></i> New Appointment
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-dashboard nd-dashboard">
  <!-- Welcome Banner -->
  <div class="card border-0 shadow-sm text-white p-4 mb-4" style="background: linear-gradient(135deg, var(--fu-maroon, #800000) 0%, #5a0000 100%) !important;">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <h2 class="h4 fw-bold mb-1 text-white">Welcome back, <?= esc($user['name'] ?? 'Clinic Staff') ?>!</h2>
        <p class="mb-0 opacity-75">
          Foundation University Health Services · Clinic Workstation Console · <?= date('l, F j, Y') ?>
        </p>
      </div>
      <div class="d-none d-md-block text-end">
        <span class="badge bg-white text-danger px-3 py-2 fw-semibold">
          <?= esc(ucwords(str_replace('_', ' ', $user['role'] ?? 'Clinic Staff'))) ?>
        </span>
      </div>
    </div>
  </div>

  <!-- NiceAdmin v3 KPI Rail -->
  <section class="row g-3 mb-4 nd-kpi-rail">
    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-leads">
        <span class="nd-kpi-icon"><i class="bi bi-people"></i></span>
        <span class="nd-kpi-label">Today's Patient Queue</span>
        <strong class="nd-kpi-value"><?= number_format($queueWaiting + $queueCalled) ?></strong>
        <span class="nd-kpi-trend <?= ($queueWaiting > 0) ? 'up' : '' ?>">
          <i class="bi bi-clock"></i> <?= $queueWaiting ?> waiting, <?= $queueCalled ?> called
        </span>
      </article>
    </div>

    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-revenue">
        <span class="nd-kpi-icon"><i class="bi bi-heart-pulse"></i></span>
        <span class="nd-kpi-label">Consultations Completed</span>
        <strong class="nd-kpi-value"><?= number_format($encountersCount) ?></strong>
        <span class="nd-kpi-trend up">
          <i class="bi bi-check2-circle"></i> <?= $queueCompleted ?> completed today
        </span>
      </article>
    </div>

    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-cycle">
        <span class="nd-kpi-icon"><i class="bi bi-calendar-event"></i></span>
        <span class="nd-kpi-label">Appointments Today</span>
        <strong class="nd-kpi-value"><?= number_format($appointmentsToday) ?></strong>
        <span class="nd-kpi-trend down">
          <i class="bi bi-bell"></i> Scheduled visits
        </span>
      </article>
    </div>

    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-retention">
        <span class="nd-kpi-icon"><i class="bi bi-capsule"></i></span>
        <span class="nd-kpi-label">Active Medicines</span>
        <strong class="nd-kpi-value"><?= !empty($inventoryInstalled) ? number_format($medicinesCount) : 'Unavailable' ?></strong>
        <span class="nd-kpi-trend up">
          <?php if (!empty($inventoryInstalled)): ?>
            <i class="bi bi-shield-check"></i> FEFO inventory active
          <?php else: ?>
            Inventory setup pending
          <?php endif; ?>
        </span>
      </article>
    </div>
  </section>

  <?php if (empty($inventoryInstalled)): ?>
    <div class="alert alert-warning" role="status">
      <strong>Medicine inventory unavailable.</strong>
      <?= esc(\App\Services\Clinic\InventoryService::SETUP_PENDING_MESSAGE) ?>
      Queue, appointments and consultation statistics remain available below.
    </div>
  <?php endif; ?>

  <!-- Visualizations & Analytics -->
  <section class="row g-3 mb-4">
    <!-- Left Column: Activity Chart & Recent Encounters -->
    <div class="col-xl-8">
      <!-- Performance Curve Card -->
      <div class="card mb-3 border-0 shadow-sm">
        <div class="card-header nd-chart-header bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Clinical Patient Intake & Consultations</h5>
          <div class="badge bg-light text-muted border">Last 7 Days</div>
        </div>
        <div class="card-body">
          <div class="nd-chart-summary">
            <div>
              <span>Completed Visits</span>
              <strong><?= number_format($encountersCount) ?></strong>
            </div>
            <div>
              <span>Today's Active Queue</span>
              <strong><?= number_format($queueWaiting + $queueCalled) ?></strong>
            </div>
            <div>
              <span>Appointments</span>
              <strong><?= number_format($appointmentsToday) ?></strong>
            </div>
          </div>
          <div id="clinicActivityChart" class="nd-chart-canvas"></div>
        </div>
      </div>

      <!-- Recent Encounters Table -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Recent Clinical Encounters</h5>
          <a href="<?= site_url('/clinic/encounters') ?>" class="nd-inline-link">View all encounters <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0 nd-transaction-table">
              <thead class="table-light">
                <tr>
                  <th class="ps-3">Encounter</th>
                  <th>Patient</th>
                  <th>Chief Complaint</th>
                  <th>Attending</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($recentEncounters)): ?>
                  <tr><td colspan="5" class="text-center py-4 text-muted">No encounters recorded yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($recentEncounters as $re): ?>
                    <tr>
                      <td class="ps-3 fw-bold">#<?= (int) $re['id'] ?></td>
                      <td>
                        <strong><?= esc($re['last_name'] . ', ' . $re['first_name']) ?></strong>
                        <small class="d-block text-muted">ID: <?= esc($re['mis_id']) ?></small>
                      </td>
                      <td><?= esc($re['chief_complaint'] ?? 'Routine consultation') ?></td>
                      <td><?= esc($re['attending_name'] ?? 'Clinic Staff') ?></td>
                      <td>
                        <span class="badge <?= $re['status'] === 'completed' ? 'bg-success' : 'bg-warning text-dark' ?>">
                          <?= esc(ucfirst($re['status'])) ?>
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

    <!-- Right Column: Donut & Quick Hub -->
    <div class="col-xl-4">
      <!-- Triage Severity Donut Chart -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Patient Triage Breakdown</h5>
        </div>
        <div class="card-body">
          <div id="clinicTriageChart" class="nd-donut-canvas"></div>
          <div class="nd-acq-list mt-2">
            <div><span>Emergency</span><strong class="text-danger"><?= $triageCounts['emergency'] ?></strong></div>
            <div><span>Urgent</span><strong class="text-warning"><?= $triageCounts['urgent'] ?></strong></div>

            <div><span>Routine</span><strong class="text-success"><?= $triageCounts['routine'] ?></strong></div>
          </div>
        </div>
      </div>

      <!-- Quick Actions Hub -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Clinical Operations Hub</h5>
        </div>
        <div class="card-body p-3">
          <div class="d-grid gap-2">
            <a href="<?= site_url('/clinic/queue') ?>" class="btn btn-outline-primary text-start p-2">
              <i class="bi bi-kanban me-2"></i> Manage Live Queue
            </a>
            <a href="<?= site_url('/clinic/patients') ?>" class="btn btn-outline-secondary text-start p-2">
              <i class="bi bi-person-lines-fill me-2"></i> Patient Directory & Charts
            </a>
            <a href="<?= site_url('/clinic/inventory') ?>" class="btn btn-outline-secondary text-start p-2">
              <i class="bi bi-capsule me-2"></i> FEFO Medicine Inventory
            </a>
            <a href="<?= site_url('/clinic/referrals') ?>" class="btn btn-outline-secondary text-start p-2">
              <i class="bi bi-arrows-left-right me-2"></i> Outgoing Referrals to Guidance
            </a>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
  (function() {
    'use strict';
    function initClinicDashboard() {
      var activityTarget = document.querySelector('#clinicActivityChart');
      var triageTarget = document.querySelector('#clinicTriageChart');
      if (typeof ApexCharts === 'undefined') return;

      var maroonColor = getComputedStyle(document.documentElement).getPropertyValue('--fu-maroon-light').trim() || '#800000';
      var mutedColor = '#6c757d';
      var borderColor = 'rgba(0,0,0,0.06)';

      if (activityTarget && !activityTarget.dataset.rendered) {
        activityTarget.dataset.rendered = 'true';
        var activityChart = new ApexCharts(activityTarget, {
          chart: {
            type: 'area',
            height: 300,
            toolbar: { show: false },
            zoom: { enabled: false },
            fontFamily: 'inherit'
          },
          series: [{
            name: 'Encounters',
            data: <?= json_encode($chartSeries) ?>
          }],
          colors: [maroonColor],
          stroke: { width: 3, curve: 'smooth' },
          fill: {
            type: 'gradient',
            gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 100] }
          },
          dataLabels: { enabled: false },
          xaxis: {
            categories: <?= json_encode($chartDays) ?>,
            axisBorder: { show: false },
            axisTicks: { show: false },
            labels: { style: { colors: mutedColor, fontSize: '11px' } }
          },
          yaxis: { labels: { style: { colors: mutedColor, fontSize: '11px' } }, min: 0, forceNiceScale: true },
          grid: { borderColor: borderColor, strokeDashArray: 4 }
        });
        activityChart.render();
      }

      if (triageTarget && !triageTarget.dataset.rendered) {
        triageTarget.dataset.rendered = 'true';
        var triageData = [
          <?= (int) $triageCounts['emergency'] ?>,
          <?= (int) $triageCounts['urgent'] ?>,

          <?= (int) $triageCounts['routine'] ?>
        ];
        var totalTriage = triageData.reduce(function(a, b) { return a + b; }, 0);
        if (totalTriage === 0) triageData = [0, 0, 1];

        var triageChart = new ApexCharts(triageTarget, {
          chart: { type: 'donut', height: 230, fontFamily: 'inherit' },
          series: triageData,
          labels: ['Emergency', 'Urgent', 'Routine'],
          colors: ['#dc3545', '#ffc107', '#198754'],
          legend: { show: false },
          dataLabels: { enabled: false },
          plotOptions: {
            pie: {
              donut: {
                size: '72%',
                labels: {
                  show: true,
                  name: { show: true, fontSize: '12px' },
                  value: { show: true, fontSize: '18px', fontWeight: 700 },
                  total: { show: true, label: 'Total Queue', formatter: function() { return totalTriage; } }
                }
              }
            }
          }
        });
        triageChart.render();
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initClinicDashboard);
    } else {
      initClinicDashboard();
    }
    document.addEventListener('synapse:page-load', initClinicDashboard);
  })();
</script>
<?= $this->endSection() ?>

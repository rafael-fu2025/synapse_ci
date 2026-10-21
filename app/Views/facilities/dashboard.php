<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/bmg.css'), 'attr') ?>">
<?= $this->endSection() ?>

<?= $this->section('page_actions') ?>
<div class="d-flex gap-2">
  <a href="<?= site_url('/facilities') ?>" class="btn btn-primary btn-sm">
    <i class="bi bi-arrow-repeat me-1"></i> Drum Rotation
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-dashboard nd-dashboard">
  <!-- Welcome Banner -->
  <div class="card border-0 shadow-sm text-white p-4 mb-4" style="background: linear-gradient(135deg, #198754 0%, #0f5132 100%) !important;">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <h2 class="h4 fw-bold mb-1 text-white">Welcome back, <?= esc($user['name'] ?? 'Facilities Operator') ?>!</h2>
        <p class="mb-0 opacity-75">
          Foundation University Facilities · Bioreactor Mechanized Gearing (BMG) Console · <?= date('l, F j, Y') ?>
        </p>
      </div>
      <div class="d-none d-md-block text-end">
        <span class="badge bg-white text-success px-3 py-2 fw-semibold">
          <?= esc(ucwords(str_replace('_', ' ', $user['role'] ?? 'Facilities Operator'))) ?>
        </span>
      </div>
    </div>
  </div>

  <!-- NiceAdmin v3 KPI Rail -->
  <section class="row g-3 mb-4 nd-kpi-rail">
    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-revenue">
        <span class="nd-kpi-icon"><i class="bi bi-arrow-repeat"></i></span>
        <span class="nd-kpi-label">Active Drum Units</span>
        <strong class="nd-kpi-value"><?= number_format($activeUnits) ?> <small class="text-muted fs-6 fw-normal">/ <?= number_format($totalUnits) ?></small></strong>
        <span class="nd-kpi-trend up">
          <i class="bi bi-check2-circle"></i> <?= number_format($maintenanceUnits) ?> in maintenance
        </span>
      </article>
    </div>

    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-leads">
        <span class="nd-kpi-icon"><i class="bi bi-recycle"></i></span>
        <span class="nd-kpi-label">Batches Processed</span>
        <strong class="nd-kpi-value"><?= number_format($totalBatches) ?></strong>
        <span class="nd-kpi-trend up">
          <i class="bi bi-hourglass-split"></i> <?= number_format($activeBatches) ?> active now
        </span>
      </article>
    </div>

    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-retention">
        <span class="nd-kpi-icon"><i class="bi bi-cpu"></i></span>
        <span class="nd-kpi-label">Bound IoT Controllers</span>
        <strong class="nd-kpi-value"><?= number_format($boundDevices) ?></strong>
        <span class="nd-kpi-trend <?= $boundDevices > 0 ? 'up' : 'down' ?>">
          <i class="bi bi-broadcast-pin"></i> ESP32-S3 hardware
        </span>
      </article>
    </div>

    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-cycle">
        <span class="nd-kpi-icon"><i class="bi bi-box-seam"></i></span>
        <span class="nd-kpi-label">Organic Waste Treated</span>
        <strong class="nd-kpi-value"><?= number_format($totalTreatedKg, 1) ?> <small class="text-muted fs-6 fw-normal">kg</small></strong>
        <span class="nd-kpi-trend up">
          <i class="bi bi-speedometer2"></i> Composted output
        </span>
      </article>
    </div>
  </section>

  <!-- Visualizations & Analytics -->
  <section class="row g-3 mb-4">
    <!-- Left Column: Batch Activity Velocity & Drum Units Table -->
    <div class="col-xl-8">
      <div class="card mb-3 border-0 shadow-sm">
        <div class="card-header nd-chart-header bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Composting & Batch Processing Activity</h5>
          <div class="badge bg-light text-muted border">Last 7 Days</div>
        </div>
        <div class="card-body">
          <div class="nd-chart-summary">
            <div>
              <span>Active Drums</span>
              <strong><?= number_format($activeUnits) ?></strong>
            </div>
            <div>
              <span>Current Batches</span>
              <strong><?= number_format($activeBatches) ?></strong>
            </div>
            <div>
              <span>Treated Waste</span>
              <strong><?= number_format($totalTreatedKg, 1) ?> kg</strong>
            </div>
          </div>
          <div id="bmgActivityChart" class="nd-chart-canvas"></div>
        </div>
      </div>

      <!-- Drum Units Table -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Drum Units Status & Telemetry</h5>
          <a href="<?= site_url('/facilities') ?>" class="nd-inline-link">Manage drum rotation <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0 nd-matrix-table">
              <thead class="table-light">
                <tr>
                  <th class="ps-3">Unit Code</th>
                  <th>Drum Name</th>
                  <th>Capacity</th>
                  <th>Schedule</th>
                  <th>Hardware Device</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($units)): ?>
                  <tr><td colspan="6" class="text-center py-4 text-muted">No drum units registered yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($units as $u): $drum = $u['unit']; $sched = $u['schedule']; $dev = $u['device']; ?>
                    <tr>
                      <td class="ps-3 fw-bold">
                        <a href="<?= site_url('/facilities/unit/' . $drum['id']) ?>" class="text-decoration-none">
                          <?= esc($drum['unit_code']) ?>
                        </a>
                      </td>
                      <td><?= esc($drum['name']) ?></td>
                      <td><?= esc($drum['drum_capacity_kg']) ?> kg</td>
                      <td>
                        <span class="badge <?= !empty($sched['enabled']) ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border' ?>">
                          <?= !empty($sched['enabled']) ? 'Active (' . count($sched['times'] ?? []) . ' runs)' : 'Disabled' ?>
                        </span>
                      </td>
                      <td>
                        <?php if ($dev !== null): ?>
                          <span class="badge bg-light text-dark border">
                            <i class="bi bi-cpu me-1"></i><?= esc($dev['chip_mac'] ?? 'Bound') ?>
                          </span>
                        <?php else: ?>
                          <span class="text-muted small">Unbound</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="badge <?= $drum['status'] === 'active' ? 'bg-success' : 'bg-warning text-dark' ?>">
                          <?= esc(ucfirst($drum['status'])) ?>
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

    <!-- Right Column: Status Donut & Hub -->
    <div class="col-xl-4">
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Drum Operational Status</h5>
        </div>
        <div class="card-body">
          <div id="bmgStatusChart" class="nd-donut-canvas"></div>
          <div class="nd-acq-list mt-2">
            <div><span>Active Operational</span><strong class="text-success"><?= $activeUnits ?></strong></div>
            <div><span>Under Maintenance</span><strong class="text-warning"><?= $maintenanceUnits ?></strong></div>
            <div><span>Idle / Unconfigured</span><strong class="text-muted"><?= max(0, $totalUnits - $activeUnits - $maintenanceUnits) ?></strong></div>
          </div>
        </div>
      </div>

      <!-- Quick Actions Hub -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Facilities Operations Hub</h5>
        </div>
        <div class="card-body p-3">
          <div class="d-grid gap-2">
            <a href="<?= site_url('/facilities') ?>" class="btn btn-outline-success text-start p-2">
              <i class="bi bi-arrow-repeat me-2"></i> Drum Rotation Workspace
            </a>
            <?php if (!empty($units)): ?>
              <a href="<?= site_url('/facilities/unit/' . $units[0]['unit']['id']) ?>" class="btn btn-outline-secondary text-start p-2">
                <i class="bi bi-gear-wide-connected me-2"></i> Primary Drum Operations
              </a>
              <?php if (!empty($canUsb)): ?>
              <a href="<?= site_url('/facilities/unit/' . $units[0]['unit']['id'] . '/usb') ?>" class="btn btn-outline-secondary text-start p-2">
                <i class="bi bi-usb-symbol me-2"></i> WebUSB Firmware Commissioning
              </a>
              <?php endif; ?>
            <?php endif; ?>
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
    function initBmgDashboard() {
      var activityTarget = document.querySelector('#bmgActivityChart');
      var statusTarget = document.querySelector('#bmgStatusChart');
      if (typeof ApexCharts === 'undefined') return;

      var greenColor = '#198754';
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
            name: 'Batch Operations',
            data: <?= json_encode($chartSeries) ?>
          }],
          colors: [greenColor],
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

      if (statusTarget && !statusTarget.dataset.rendered) {
        statusTarget.dataset.rendered = 'true';
        var idleUnits = Math.max(0, <?= (int) $totalUnits ?> - <?= (int) $activeUnits ?> - <?= (int) $maintenanceUnits ?>);
        var statusData = [
          <?= (int) $activeUnits ?>,
          <?= (int) $maintenanceUnits ?>,
          idleUnits
        ];
        var totalStatus = statusData.reduce(function(a, b) { return a + b; }, 0);
        if (totalStatus === 0) statusData = [1, 0, 0];

        var statusChart = new ApexCharts(statusTarget, {
          chart: { type: 'donut', height: 230, fontFamily: 'inherit' },
          series: statusData,
          labels: ['Active', 'Maintenance', 'Idle'],
          colors: ['#198754', '#ffc107', '#6c757d'],
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
                  total: { show: true, label: 'Total Units', formatter: function() { return totalStatus; } }
                }
              }
            }
          }
        });
        statusChart.render();
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initBmgDashboard);
    } else {
      initBmgDashboard();
    }
    document.addEventListener('synapse:page-load', initBmgDashboard);
  })();
</script>
<?= $this->endSection() ?>

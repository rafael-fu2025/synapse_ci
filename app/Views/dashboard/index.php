<?= $this->extend('layouts/app') ?>
<?php
use App\Services\PortalContext;

$canReadAudit = ($canReadAudit ?? false) === true;
$dashboardRole = $user['role'] ?? 'guest';
$canDashboardLink = static fn (string $permission, string $portal = 'main'): bool =>
    PortalContext::isRolePermitted($dashboardRole, $portal)
    && ($dashboardRole === 'superadmin' || in_array($permission, $user['permissions'] ?? [], true));
$canExportReports = in_array($dashboardRole, ['superadmin', 'audit_reader', 'report_viewer', 'clinic_admin', 'guidance_admin', 'bmg_admin'], true)
    && $canDashboardLink('reports.read') && $canDashboardLink('reports.export');
$canSyncMis = in_array($dashboardRole, ['superadmin', 'clinic_admin', 'guidance_admin'], true) && $canDashboardLink('mis.sync');
?>

<?= $this->section('page_actions') ?>
<div class="d-flex gap-2">
  <?php if ($canReadAudit): ?>
  <a href="<?= esc(PortalContext::urlFor('main', 'admin/audit')) ?>" class="btn btn-primary btn-sm">
    <i class="bi bi-shield-check me-1"></i> Verify Audit Chain
  </a>
  <?php endif; ?>
  <?php if ($canDashboardLink('rbac.manage')): ?>
  <a href="<?= esc(PortalContext::urlFor('main', 'admin/carousel')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-images me-1"></i> Login Carousel
  </a>
  <?php endif; ?>
  <?php if ($canExportReports): ?>
  <a href="<?= esc(PortalContext::urlFor('main', 'reports/export')) ?>" class="btn btn-outline-secondary btn-sm">Operational reports</a>
  <?php endif; ?>
  <?php if ($canSyncMis): ?>
  <a href="<?= esc(PortalContext::urlFor('main', 'admin/mis-sync')) ?>" class="btn btn-outline-secondary btn-sm">MIS reference synchronization</a>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-dashboard nd-dashboard">
  <!-- Welcome Banner -->
  <div class="card border-0 shadow-sm text-white p-4 mb-4" style="background: linear-gradient(135deg, var(--fu-maroon, #800000) 0%, #5a0000 100%) !important;">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <h2 class="h4 fw-bold mb-1 text-white">Welcome back, <?= esc($user['name'] ?? 'Administrator') ?>!</h2>
        <p class="mb-0 opacity-75">
          Foundation University <?= $canReadAudit ? 'Institutional Administration & Governance' : 'Operations Reports & Analytics' ?> · <?= date('l, F j, Y') ?>
        </p>
      </div>
      <div class="d-none d-md-block text-end">
        <span class="badge bg-white text-danger px-3 py-2 fw-semibold">
          <?= esc(ucwords(str_replace('_', ' ', $user['role'] ?? 'Superadmin'))) ?>
        </span>
      </div>
    </div>
  </div>

  <!-- NiceAdmin v3 KPI Rail -->
  <section class="row g-3 mb-4 nd-kpi-rail">
    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-leads">
        <span class="nd-kpi-icon"><i class="bi bi-people"></i></span>
        <span class="nd-kpi-label">Campus Directory Population</span>
        <strong class="nd-kpi-value"><?= number_format($totalPopulation) ?></strong>
        <span class="nd-kpi-trend up">
          <i class="bi bi-mortarboard"></i> <?= number_format($studentsCount) ?> students · <?= number_format($employeesCount) ?> staff
        </span>
      </article>
    </div>

    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-revenue">
        <span class="nd-kpi-icon"><i class="bi bi-activity"></i></span>
        <span class="nd-kpi-label">Care Operations Volume</span>
        <strong class="nd-kpi-value"><?= number_format($totalOperationsVolume) ?></strong>
        <span class="nd-kpi-trend up">
          <i class="bi bi-hospital"></i> <?= number_format($totalClinicEncounters) ?> clinic · <?= number_format($totalGuidanceSessions) ?> guidance
        </span>
      </article>
    </div>

    <?php if ($canReadAudit): ?>
    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-retention">
        <span class="nd-kpi-icon"><i class="bi bi-shield-lock-fill"></i></span>
        <span class="nd-kpi-label">Cryptographic Audit Ledger</span>
        <strong class="nd-kpi-value"><?= number_format($totalAuditEvents) ?></strong>
        <span class="nd-kpi-trend up">
          <i class="bi bi-shield-check"></i> Verify integrity on demand
        </span>
      </article>
    </div>

    <?php endif; ?>

    <div class="col-xxl-3 col-md-6">
      <article class="nd-kpi-card nd-kpi-cycle">
        <span class="nd-kpi-icon"><i class="bi bi-person-badge"></i></span>
        <span class="nd-kpi-label">Active System Accounts</span>
        <strong class="nd-kpi-value"><?= number_format($activeAccounts) ?></strong>
        <span class="nd-kpi-trend down">
          <i class="bi bi-key"></i> Multi-role access matrix
        </span>
      </article>
    </div>
  </section>

  <!-- Visualizations & Analytics -->
  <section class="row g-3 mb-4">
    <!-- Left Column: Operations Velocity -->
    <div class="col-xl-8">
      <div class="card mb-3 border-0 shadow-sm">
        <div class="card-header nd-chart-header bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Cross-Department Operations Velocity</h5>
          <div class="badge bg-light text-muted border">Clinic vs Guidance (7 Days)</div>
        </div>
        <div class="card-body">
          <div class="nd-chart-summary">
            <div>
              <span>Clinic Visits</span>
              <strong><?= number_format($totalClinicEncounters) ?></strong>
            </div>
            <div>
              <span>Guidance Sessions</span>
              <strong><?= number_format($totalGuidanceSessions) ?></strong>
            </div>
            <div>
              <span>Total Population</span>
              <strong><?= number_format($totalPopulation) ?></strong>
            </div>
          </div>
          <div id="adminOpsChart" class="nd-chart-canvas"></div>
        </div>
      </div>

      <?php if ($canReadAudit): ?>
      <!-- Recent Audit Events Table -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Recent Cryptographic Audit Trail</h5>
          <a href="<?= esc(PortalContext::urlFor('main', 'admin/audit')) ?>" class="nd-inline-link">Open ledger chain <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0 nd-transaction-table">
              <thead class="table-light">
                <tr>
                  <th class="ps-3">ID</th>
                  <th>Event Type</th>
                  <th>Resource</th>
                  <th>Actor</th>
                  <th>Timestamp</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($recentAudit)): ?>
                  <tr><td colspan="5" class="text-center py-4 text-muted">No audit events recorded yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($recentAudit as $ae): ?>
                    <tr>
                      <td class="ps-3 fw-bold">#<?= (int) $ae['id'] ?></td>
                      <td>
                        <span class="badge bg-dark-subtle text-dark"><?= esc($ae['event_type']) ?></span>
                      </td>
                      <td><?= esc($ae['entity_type']) ?> #<?= esc($ae['entity_id']) ?></td>
                      <td><?= esc($ae['actor_name'] ?? 'System') ?></td>
                      <td class="text-muted small"><?= date('M j, Y · h:i A', strtotime($ae['occurred_at'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Right Column: Population Donut & Quick Hub -->
    <div class="col-xl-4">
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold">Campus Population Mix</h5>
        </div>
        <div class="card-body">
          <div id="adminPopulationChart" class="nd-donut-canvas"></div>
          <div class="nd-acq-list mt-2">
            <div><span>Students</span><strong class="text-primary"><?= number_format($studentsCount) ?></strong></div>
            <div><span>Employees (Faculty & Staff)</span><strong class="text-info"><?= number_format($employeesCount) ?></strong></div>
            <div><span>Total Directory Rows</span><strong><?= number_format($totalPopulation) ?></strong></div>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent py-3">
          <h5 class="card-title mb-0 fw-bold"><?= $canReadAudit ? 'Administration Hub' : 'Campus Services' ?></h5>
        </div>
        <div class="card-body p-3">
          <div class="d-grid gap-2">
            <?php if ($canReadAudit): ?>
            <a href="<?= esc(PortalContext::urlFor('main', 'admin/audit')) ?>" class="btn btn-outline-primary text-start p-2">
              <i class="bi bi-shield-check me-2"></i> Audit Chain Ledger
            </a>
            <?php endif; ?>
            <?php if ($canDashboardLink('clinic.queue.read', 'clinic')): ?>
            <a href="<?= esc(PortalContext::urlFor('clinic', 'clinic/queue')) ?>" class="btn btn-outline-secondary text-start p-2">
              <i class="bi bi-hospital me-2"></i> University Clinic Console
            </a>
            <?php endif; ?>
            <?php if ($canDashboardLink('guidance.cases.read', 'guidance')): ?>
            <a href="<?= esc(PortalContext::urlFor('guidance', 'guidance/cases')) ?>" class="btn btn-outline-secondary text-start p-2">
              <i class="bi bi-person-heart me-2"></i> Guidance Center Console
            </a>
            <?php endif; ?>
            <?php if ($canDashboardLink('facilities.read', 'bmg')): ?>
            <a href="<?= esc(PortalContext::urlFor('bmg', 'facilities')) ?>" class="btn btn-outline-secondary text-start p-2">
              <i class="bi bi-recycle me-2"></i> Facilities & BMG Drum Console
            </a>
            <?php endif; ?>
            <?php if ($canDashboardLink('rbac.manage')): ?>
            <a href="<?= esc(PortalContext::urlFor('main', 'admin/carousel')) ?>" class="btn btn-outline-secondary text-start p-2">
              <i class="bi bi-images me-2"></i> Login Carousel Management
            </a>
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
    function initAdminDashboard() {
      var opsTarget = document.querySelector('#adminOpsChart');
      var popTarget = document.querySelector('#adminPopulationChart');
      if (typeof ApexCharts === 'undefined') return;

      var maroonColor = getComputedStyle(document.documentElement).getPropertyValue('--fu-maroon-light').trim() || '#800000';
      var guidanceColor = '#b06a6a';
      var mutedColor = '#6c757d';
      var borderColor = 'rgba(0,0,0,0.06)';

      if (opsTarget && !opsTarget.dataset.rendered) {
        opsTarget.dataset.rendered = 'true';
        var opsChart = new ApexCharts(opsTarget, {
          chart: {
            type: 'area',
            height: 300,
            toolbar: { show: false },
            zoom: { enabled: false },
            fontFamily: 'inherit'
          },
          series: [
            { name: 'Clinic Visits', data: <?= json_encode($clinicSeries) ?> },
            { name: 'Guidance Sessions', data: <?= json_encode($guidanceSeries) ?> }
          ],
          colors: [maroonColor, guidanceColor],
          stroke: { width: [2.5, 2.5], curve: 'smooth' },
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
          grid: { borderColor: borderColor, strokeDashArray: 4 },
          legend: { position: 'top', horizontalAlign: 'left', labels: { colors: mutedColor } }
        });
        opsChart.render();
      }

      if (popTarget && !popTarget.dataset.rendered) {
        popTarget.dataset.rendered = 'true';
        var popData = [
          <?= (int) $studentsCount ?>,
          <?= (int) $employeesCount ?>
        ];
        var totalPop = popData.reduce(function(a, b) { return a + b; }, 0);
        if (totalPop === 0) popData = [1, 0];

        var popChart = new ApexCharts(popTarget, {
          chart: { type: 'donut', height: 230, fontFamily: 'inherit' },
          series: popData,
          labels: ['Students', 'Employees'],
          colors: [maroonColor, guidanceColor],
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
                  total: { show: true, label: 'Population', formatter: function() { return totalPop; } }
                }
              }
            }
          }
        });
        popChart.render();
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initAdminDashboard);
    } else {
      initAdminDashboard();
    }
    document.addEventListener('synapse:page-load', initAdminDashboard);
  })();
</script>
<?= $this->endSection() ?>

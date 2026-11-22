<?= $this->extend('layouts/app') ?>
<?php
use App\Services\PortalContext;
use App\Services\Scheduling\SchedulingClock;

$canReadAudit = ($canReadAudit ?? false) === true;
$dashboardRole = $user['role'] ?? 'guest';
$canDashboardLink = static fn (string $permission, string $portal = 'main'): bool =>
    PortalContext::isRolePermitted($dashboardRole, $portal)
    && ($dashboardRole === 'superadmin' || in_array($permission, $user['permissions'] ?? [], true));
$canExportReports = in_array($dashboardRole, ['superadmin', 'audit_reader', 'report_viewer', 'clinic_admin', 'guidance_admin', 'bmg_admin'], true)
    && $canDashboardLink('reports.read') && $canDashboardLink('reports.export');
$canSyncMis = in_array($dashboardRole, ['superadmin', 'clinic_admin', 'guidance_admin'], true) && $canDashboardLink('mis.sync');
$canManageEmployees = $canDashboardLink('rbac.manage');
$canAccessConsoles = $canDashboardLink('clinic.queue.read', 'clinic')
    || $canDashboardLink('guidance.cases.read', 'guidance')
    || $canDashboardLink('facilities.read', 'bmg');

$rangeKey           = ($rangeKey ?? '7d') === '30d' ? '30d' : '7d';
$inactiveAccounts   = isset($inactiveAccounts) ? (int) $inactiveAccounts : 0;
$privilegedAccounts = isset($privilegedAccounts) ? (int) $privilegedAccounts : null;
$privilegedRoles    = is_array($privilegedRoles ?? null) ? $privilegedRoles : [];
$diagnostics        = is_array($diagnostics ?? null) ? $diagnostics : [];
$bmgSeries          = is_array($bmgSeries ?? null) ? $bmgSeries : array_fill(0, count($chartDays), 0);
$priorPeriodTotal   = isset($priorPeriodTotal) ? (int) $priorPeriodTotal : null;

$fallbackWindow = [
    'days'       => array_values($chartDays),
    'clinic'     => array_values($clinicSeries),
    'guidance'   => array_values($guidanceSeries),
    'bmg'        => array_values($bmgSeries),
    'priorTotal' => $priorPeriodTotal,
];
$opsWindows = is_array($opsWindows ?? null) ? $opsWindows : [
    '7d'  => $fallbackWindow,
    '30d' => $fallbackWindow,
];
$activeWindow       = $opsWindows[$rangeKey] ?? $fallbackWindow;
$activeDays         = array_values($activeWindow['days'] ?? []);
$activeClinic       = array_values($activeWindow['clinic'] ?? []);
$activeGuidance     = array_values($activeWindow['guidance'] ?? []);
$activeBmg          = array_values($activeWindow['bmg'] ?? array_fill(0, count($activeDays), 0));
$activePriorTotal   = array_key_exists('priorTotal', $activeWindow) && $activeWindow['priorTotal'] !== null
    ? (int) $activeWindow['priorTotal']
    : null;

$clinicWindowSum   = array_sum($activeClinic);
$guidanceWindowSum = array_sum($activeGuidance);
$bmgWindowSum      = array_sum($activeBmg);
$periodTotal       = $clinicWindowSum + $guidanceWindowSum + $bmgWindowSum;
$dayCount          = max(1, count($activeDays));
$dailyAvg          = round($periodTotal / $dayCount, 1);

$clinicShare   = $periodTotal > 0 ? round(($clinicWindowSum / $periodTotal) * 100, 1) : 0.0;
$guidanceShare = $periodTotal > 0 ? round(($guidanceWindowSum / $periodTotal) * 100, 1) : 0.0;
$bmgShare      = $periodTotal > 0 ? round(($bmgWindowSum / $periodTotal) * 100, 1) : 0.0;

$dailyTotals = [];
$peakVal = 0;
$peakDay = '—';
foreach ($activeDays as $idx => $dayLabel) {
    $daySum = (int) ($activeClinic[$idx] ?? 0) + (int) ($activeGuidance[$idx] ?? 0) + (int) ($activeBmg[$idx] ?? 0);
    $dailyTotals[] = $daySum;
    if ($daySum > $peakVal) {
        $peakVal = $daySum;
        $peakDay = (string) $dayLabel;
    }
}
$sparkWindow = $opsWindows['7d'] ?? $activeWindow;
$sparkTotals = [];
foreach (($sparkWindow['days'] ?? []) as $sIdx => $_) {
    $sparkTotals[] = (int) (($sparkWindow['clinic'][$sIdx] ?? 0)) + (int) (($sparkWindow['guidance'][$sIdx] ?? 0)) + (int) (($sparkWindow['bmg'][$sIdx] ?? 0));
}
$sparkTotals = array_slice($sparkTotals, -7);
$sparkMax = !empty($sparkTotals) ? max(1, max($sparkTotals)) : 1;

$deltaPctText = null;
$deltaLabel = null;
$deltaClass = 'is-flat';
if ($activePriorTotal !== null) {
    if ($activePriorTotal === 0 && $periodTotal > 0) {
        $deltaPctText = '+100%';
        $deltaLabel = '+100% vs prior ' . $rangeKey;
        $deltaClass = 'is-up';
    } elseif ($activePriorTotal > 0) {
        $pctChange = (int) round((($periodTotal - $activePriorTotal) / $activePriorTotal) * 100);
        $deltaPctText = ($pctChange > 0 ? '+' : '') . $pctChange . '%';
        $deltaLabel = $deltaPctText . ' vs prior ' . $rangeKey;
        $deltaClass = $pctChange > 0 ? 'is-up' : ($pctChange < 0 ? 'is-down' : 'is-flat');
    } else {
        $deltaPctText = '0%';
        $deltaLabel = '0% vs prior ' . $rangeKey;
    }
}

$studentPct  = $totalPopulation > 0 ? round(($studentsCount / $totalPopulation) * 100, 1) : 0.0;
$employeePct = $totalPopulation > 0 ? round(($employeesCount / $totalPopulation) * 100, 1) : 0.0;
$coveragePct = $totalPopulation > 0 ? min(100.0, round(($activeAccounts / $totalPopulation) * 100, 1)) : 0.0;
$inactivePct = $totalPopulation > 0 ? min(max(0.0, 100.0 - $coveragePct), round(($inactiveAccounts / $totalPopulation) * 100, 1)) : 0.0;
$dashboardBaseUrl = $canReadAudit ? site_url('/admin') : site_url('/dashboard');

$misAgeLabel = 'Never synced';
$misStateClass = 'is-warn';
$misStateText = 'Pending';
if (!empty($diagnostics['misLastSyncedAt'])) {
    $syncedTs = strtotime((string) $diagnostics['misLastSyncedAt'] . ' UTC');
    if ($syncedTs !== false) {
        $diffSec = max(0, time() - $syncedTs);
        if ($diffSec < 3600) {
            $misAgeLabel = max(1, (int) floor($diffSec / 60)) . 'm ago';
            $misStateClass = 'is-ok';
            $misStateText = 'Current';
        } elseif ($diffSec < 86400) {
            $misAgeLabel = (int) floor($diffSec / 3600) . 'h ago';
            $misStateClass = 'is-ok';
            $misStateText = 'Current';
        } else {
            $diffDays = (int) floor($diffSec / 86400);
            $misAgeLabel = $diffDays . 'd ago';
            $misStateClass = $diffDays > 7 ? 'is-warn' : 'is-ok';
            $misStateText = $diffDays > 7 ? 'Stale' : 'Current';
        }
    }
}
?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 sa-page-actions">
  <?php if ($canReadAudit): ?>
  <a href="<?= esc(PortalContext::urlFor('main', 'admin/audit')) ?>" class="btn sh-btn sh-btn-primary">
    <i class="bi bi-shield-check" aria-hidden="true"></i> Verify Audit Chain
  </a>
  <?php endif; ?>
  <?php if ($canDashboardLink('rbac.manage')): ?>
  <a href="<?= esc(PortalContext::urlFor('main', 'admin/carousel')) ?>" class="btn sh-btn sh-btn-outline">
    <i class="bi bi-images" aria-hidden="true"></i> Login Carousel
  </a>
  <?php endif; ?>
  <?php if ($canExportReports): ?>
  <a href="<?= esc(PortalContext::urlFor('main', 'reports/export')) ?>" class="btn sh-btn sh-btn-outline">
    <i class="bi bi-download" aria-hidden="true"></i> Operational reports
  </a>
  <?php endif; ?>
  <?php if ($canSyncMis): ?>
  <a href="<?= esc(PortalContext::urlFor('main', 'admin/mis-sync')) ?>" class="btn sh-btn sh-btn-outline">
    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> MIS reference synchronization
  </a>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-dashboard nd-dashboard exec-dashboard-page adm-workspace adm-superadmin-dash">
  <?php if (!$canReadAudit): ?>
  <p class="text-muted small mb-3">Foundation University Operations Reports & Analytics · <?= SchedulingClock::now()->format('l, F j, Y') ?></p>
  <?php endif; ?>

  <section class="row g-3 mb-3" aria-label="Primary metrics">
    <div class="<?= $canReadAudit ? 'col-12 col-sm-6 col-xl-3' : 'col-12 col-md-4' ?>">
      <article class="sh-kpi-card">
        <div class="sh-kpi-head">
          <span class="sh-kpi-label">Campus Directory Population</span>
          <span class="sh-kpi-badge">
            <i class="bi bi-people" aria-hidden="true"></i> <?= number_format($coveragePct, 1) ?>% active
          </span>
        </div>
        <div class="sh-kpi-val-row">
          <strong class="sh-kpi-value"><?= number_format($totalPopulation) ?></strong>
        </div>
        <div class="sh-kpi-splitbar" aria-hidden="true">
          <span style="width: <?= $studentPct ?>%; background: var(--sh-pop-1);"></span>
          <span style="width: <?= $employeePct ?>%; background: var(--sh-pop-2);"></span>
        </div>
        <div class="sh-kpi-foot">
          <span class="sh-kpi-foot-main">
            <?= number_format($studentsCount) ?> students · <?= number_format($employeesCount) ?> staff
          </span>
          <span class="sh-kpi-foot-sub">Synced university directory records</span>
        </div>
      </article>
    </div>

    <div class="<?= $canReadAudit ? 'col-12 col-sm-6 col-xl-3' : 'col-12 col-md-4' ?>">
      <article class="sh-kpi-card">
        <div class="sh-kpi-head">
          <span class="sh-kpi-label">Care Operations Volume</span>
          <?php if ($deltaPctText !== null): ?>
            <span class="sh-kpi-badge <?= $deltaClass ?>">
              <i class="bi <?= $deltaClass === 'is-down' ? 'bi-graph-down-arrow' : 'bi-graph-up-arrow' ?>" aria-hidden="true"></i> <?= esc($deltaPctText) ?>
            </span>
          <?php else: ?>
            <span class="sh-kpi-badge">
              <i class="bi bi-activity" aria-hidden="true"></i> <?= number_format($periodTotal) ?> / <?= esc($rangeKey) ?>
            </span>
          <?php endif; ?>
        </div>
        <div class="sh-kpi-val-row">
          <strong class="sh-kpi-value"><?= number_format($totalOperationsVolume) ?></strong>
          <?php if (!empty($sparkTotals)): ?>
          <div class="sh-kpi-spark" aria-hidden="true">
            <?php foreach ($sparkTotals as $sv): ?>
              <?php $hPct = max(14, (int) round(($sv / $sparkMax) * 100)); ?>
              <span class="sh-kpi-spark-bar<?= $sv === $peakVal && $peakVal > 0 ? ' is-peak' : '' ?>" style="height: <?= $hPct ?>%;"></span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="sh-kpi-foot">
          <span class="sh-kpi-foot-main">
            <?= number_format($totalClinicEncounters) ?> clinic · <?= number_format($totalGuidanceSessions) ?> guidance
          </span>
          <span class="sh-kpi-foot-sub">Cumulative care &amp; counseling volume</span>
        </div>
      </article>
    </div>

    <?php if ($canReadAudit): ?>
    <div class="col-12 col-sm-6 col-xl-3">
      <article class="sh-kpi-card">
        <div class="sh-kpi-head">
          <span class="sh-kpi-label">Cryptographic Audit Ledger</span>
          <span class="sh-kpi-badge is-up">
            <i class="bi bi-shield-check" aria-hidden="true"></i> Signed
          </span>
        </div>
        <div class="sh-kpi-val-row">
          <strong class="sh-kpi-value"><?= number_format($totalAuditEvents) ?></strong>
        </div>
        <div class="sh-kpi-foot">
          <span class="sh-kpi-foot-main">Verify integrity on demand</span>
          <span class="sh-kpi-foot-sub">Tamper-evident tenant mutation chain</span>
        </div>
      </article>
    </div>
    <?php endif; ?>

    <div class="<?= $canReadAudit ? 'col-12 col-sm-6 col-xl-3' : 'col-12 col-md-4' ?>">
      <article class="sh-kpi-card">
        <div class="sh-kpi-head">
          <span class="sh-kpi-label">Active System Accounts</span>
          <span class="sh-kpi-badge">
            <i class="bi bi-key" aria-hidden="true"></i>
            <?= $privilegedAccounts !== null ? number_format($privilegedAccounts) . ' staff' : 'RBAC' ?>
          </span>
        </div>
        <div class="sh-kpi-val-row">
          <strong class="sh-kpi-value"><?= number_format($activeAccounts) ?></strong>
        </div>
        <div class="sh-kpi-foot">
          <span class="sh-kpi-foot-main">
            <?php if ($privilegedAccounts !== null): ?>
              <?= number_format($privilegedAccounts) ?> privileged · <?= number_format($inactiveAccounts) ?> inactive
            <?php else: ?>
              Multi-role access matrix
            <?php endif; ?>
          </span>
          <span class="sh-kpi-foot-sub">Authorized campus &amp; staff identities</span>
        </div>
      </article>
    </div>
  </section>

  <?php if ($canReadAudit && !empty($diagnostics)): ?>
  <?php
    $throttled = (int) ($diagnostics['throttledBuckets'] ?? 0);
    $apiTokens = (int) ($diagnostics['activeApiTokens'] ?? 0);
    $authStateClass = $throttled > 0 ? 'is-alert' : 'is-ok';
    $authStateText = $throttled > 0 ? $throttled . ' throttled' : 'Normal';

    $bmgBound = (int) ($diagnostics['bmgDevicesBound'] ?? 0);
    $bmgOnline = (int) ($diagnostics['bmgDevicesOnline'] ?? 0);
    $bmgUnits = (int) ($diagnostics['bmgUnitsTotal'] ?? 0);
    $bmgFailed = (int) ($diagnostics['bmgFailedCommands7d'] ?? 0);
    $bmgOffline = max(0, $bmgBound - $bmgOnline);
    $bmgStateClass = $bmgFailed > 0 ? 'is-alert' : ($bmgBound === 0 ? '' : ($bmgOffline > 0 ? 'is-warn' : 'is-ok'));
    $bmgStateText = $bmgFailed > 0 ? $bmgFailed . ' cmd issues' : ($bmgBound === 0 ? 'No devices' : ($bmgOffline > 0 ? $bmgOffline . ' offline' : 'All online'));

    $jobsFailed = (int) ($diagnostics['docJobsFailed'] ?? 0);
    $jobsPending = (int) ($diagnostics['docJobsPending'] ?? 0);
    $gatesEnabled = (int) ($diagnostics['gatesEnabled'] ?? 0);
    $gatesTotal = (int) ($diagnostics['gatesTotal'] ?? 0);
    $openRefs = (int) ($diagnostics['openReferrals'] ?? 0);
    $todayQueue = (int) ($diagnostics['todayActiveQueue'] ?? 0);
    $opsStateClass = $jobsFailed > 0 ? 'is-alert' : ($jobsPending > 5 ? 'is-warn' : 'is-ok');
    $opsStateText = $jobsFailed > 0 ? $jobsFailed . ' job failed' : 'Healthy';
  ?>
  <section class="row g-3 mb-3" aria-label="Platform health diagnostics">
    <div class="col-12 col-sm-6 col-xl-3">
      <article class="sh-diag-card">
        <div class="sh-diag-top">
          <div class="sh-diag-head">
            <span class="sh-diag-label"><i class="bi bi-shield-lock" aria-hidden="true"></i> Auth &amp; Sessions</span>
            <span class="sh-status-badge <?= $authStateClass ?>"><span class="sh-status-dot"></span><?= esc($authStateText) ?></span>
          </div>
          <div class="sh-diag-primary"><?= number_format($apiTokens) ?> active API tokens</div>
        </div>
        <div class="sh-diag-foot">
          <span class="sh-diag-kv">
            <span>Throttled: <strong><?= number_format($throttled) ?></strong></span>
            <span>Inactive: <strong><?= number_format($inactiveAccounts) ?></strong></span>
          </span>
          <?php if ($canManageEmployees): ?>
            <a href="<?= esc(PortalContext::urlFor('main', 'admin/employees')) ?>" class="sh-diag-link">Access <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
          <?php endif; ?>
        </div>
      </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <article class="sh-diag-card">
        <div class="sh-diag-top">
          <div class="sh-diag-head">
            <span class="sh-diag-label"><i class="bi bi-database-check" aria-hidden="true"></i> MIS Directory Sync</span>
            <span class="sh-status-badge <?= $misStateClass ?>"><span class="sh-status-dot"></span><?= esc($misStateText) ?></span>
          </div>
          <div class="sh-diag-primary"><?= esc($misAgeLabel) ?></div>
        </div>
        <div class="sh-diag-foot">
          <span class="sh-diag-kv">
            <span>Catalog: <strong><?= number_format((int) ($diagnostics['misCatalogTotal'] ?? 0)) ?></strong></span>
            <span>Directory: <strong><?= number_format($totalPopulation) ?></strong></span>
          </span>
        </div>
      </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <article class="sh-diag-card">
        <div class="sh-diag-top">
          <div class="sh-diag-head">
            <span class="sh-diag-label"><i class="bi bi-cpu" aria-hidden="true"></i> BMG Hardware</span>
            <span class="sh-status-badge <?= $bmgStateClass ?>"><span class="sh-status-dot"></span><?= esc($bmgStateText) ?></span>
          </div>
          <div class="sh-diag-primary"><?= number_format($bmgOnline) ?> / <?= number_format($bmgBound) ?> devices online</div>
        </div>
        <div class="sh-diag-foot">
          <span class="sh-diag-kv">
            <span>Units: <strong><?= number_format($bmgUnits) ?></strong></span>
            <span>7d failed cmds: <strong><?= number_format($bmgFailed) ?></strong></span>
          </span>
        </div>
      </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <article class="sh-diag-card">
        <div class="sh-diag-top">
          <div class="sh-diag-head">
            <span class="sh-diag-label"><i class="bi bi-hdd-stack" aria-hidden="true"></i> Jobs, Gates &amp; Queue</span>
            <span class="sh-status-badge <?= $opsStateClass ?>"><span class="sh-status-dot"></span><?= esc($opsStateText) ?></span>
          </div>
          <div class="sh-diag-primary"><?= number_format($todayQueue) ?> active in queue today</div>
        </div>
        <div class="sh-diag-foot">
          <span class="sh-diag-kv">
            <span>PDF: <strong><?= number_format($jobsPending) ?></strong> q / <strong><?= number_format($jobsFailed) ?></strong> err</span>
            <span>Gates: <strong><?= number_format($gatesEnabled) ?>/<?= number_format($gatesTotal) ?></strong> · Refs: <strong><?= number_format($openRefs) ?></strong></span>
          </span>
        </div>
      </article>
    </div>
  </section>
  <?php endif; ?>

  <section class="row g-3 mb-4">
    <div class="col-xl-8">
      <div class="card sh-card mb-3">
        <div class="sh-card-head">
          <div>
            <h5 class="sh-card-title">Cross-Department Operations Velocity</h5>
            <p class="sh-card-desc">Segmented daily throughput across Clinic, Guidance, and BMG drum runs</p>
          </div>
          <div class="sh-chart-controls">
            <div class="sh-tabs" role="group" aria-label="Chart timeframe">
              <button type="button" class="sh-tab<?= $rangeKey === '7d' ? ' is-active' : '' ?>" data-ops-range="7d" aria-pressed="<?= $rangeKey === '7d' ? 'true' : 'false' ?>">7 Days</button>
              <button type="button" class="sh-tab<?= $rangeKey === '30d' ? ' is-active' : '' ?>" data-ops-range="30d" aria-pressed="<?= $rangeKey === '30d' ? 'true' : 'false' ?>">30 Days</button>
            </div>
            <div class="sh-tabs" role="group" aria-label="Chart visualization">
              <button type="button" class="sh-tab is-active" data-ops-mode="stacked" aria-pressed="true">Histogram</button>
              <button type="button" class="sh-tab" data-ops-mode="matrix" aria-pressed="false">Matrix</button>
            </div>
          </div>
        </div>

        <div class="sh-metric-strip">
          <div class="sh-metric-cell">
            <span class="sh-metric-label" data-ops-range-label><?= $rangeKey === '30d' ? '30-Day' : '7-Day' ?> Throughput</span>
            <div class="sh-metric-row">
              <strong class="sh-metric-val" data-ops-total><?= number_format($periodTotal) ?></strong>
              <span class="sh-delta-pill <?= $deltaClass ?><?= $deltaLabel === null ? ' d-none' : '' ?>" data-ops-delta-pill><?= esc($deltaPctText ?? '') ?></span>
            </div>
          </div>
          <button type="button" class="sh-metric-cell" data-ops-series="0" aria-pressed="true" title="Toggle Clinic Visits">
            <span class="sh-metric-label"><span class="sh-swatch sh-swatch-s1"></span>Clinic Visits</span>
            <div class="sh-metric-row">
              <strong class="sh-metric-val" data-ops-unit-val="0"><?= number_format($clinicWindowSum) ?></strong>
              <span class="sh-metric-share" data-ops-unit-share="0"><?= number_format($clinicShare, 1) ?>%</span>
            </div>
          </button>
          <button type="button" class="sh-metric-cell" data-ops-series="1" aria-pressed="true" title="Toggle Guidance Sessions">
            <span class="sh-metric-label"><span class="sh-swatch sh-swatch-s2"></span>Guidance Sessions</span>
            <div class="sh-metric-row">
              <strong class="sh-metric-val" data-ops-unit-val="1"><?= number_format($guidanceWindowSum) ?></strong>
              <span class="sh-metric-share" data-ops-unit-share="1"><?= number_format($guidanceShare, 1) ?>%</span>
            </div>
          </button>
          <button type="button" class="sh-metric-cell" data-ops-series="2" aria-pressed="true" title="Toggle BMG Drum Runs">
            <span class="sh-metric-label"><span class="sh-swatch sh-swatch-s3"></span>BMG Drum Runs</span>
            <div class="sh-metric-row">
              <strong class="sh-metric-val" data-ops-unit-val="2"><?= number_format($bmgWindowSum) ?></strong>
              <span class="sh-metric-share" data-ops-unit-share="2"><?= number_format($bmgShare, 1) ?>%</span>
            </div>
          </button>
        </div>

        <div class="sh-chart-body">
          <div id="adminOpsViz" class="sh-viz-stage" aria-label="Cross-department operations throughput visualization"></div>
          <div id="adminOpsTip" class="sh-floating-tip" role="tooltip" aria-hidden="true"></div>
        </div>

        <div class="sh-card-footer">
          <span>Daily avg <strong data-ops-avg><?= number_format($dailyAvg, 1) ?></strong> · Peak <strong data-ops-peak><?= $peakVal > 0 ? number_format($peakVal) : '0' ?></strong> (<span data-ops-peak-day><?= esc($peakDay) ?></span>)</span>
          <span data-ops-delta-note><?= $deltaLabel !== null ? esc($deltaLabel) . ' · Click a unit above to isolate' : 'Click a unit above to isolate series' ?></span>
        </div>
      </div>

      <?php if ($canReadAudit): ?>
      <div class="card sh-card mb-3">
        <div class="sh-card-head">
          <div>
            <h5 class="sh-card-title">Recent Cryptographic Audit Trail</h5>
            <p class="sh-card-desc">Latest signed system mutations across this tenant</p>
          </div>
          <a href="<?= esc(PortalContext::urlFor('main', 'admin/audit')) ?>" class="sh-btn sh-btn-outline sh-btn-xs">
            Open ledger chain <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </a>
        </div>
        <div class="sh-dt-content">
          <?php if (!empty($recentAudit)): ?>
          <div class="sh-dt-toolbar">
            <div class="sh-search-wrap">
              <i class="bi bi-search" aria-hidden="true"></i>
              <input type="search" class="sh-search-input" data-ledger-search placeholder="Filter event, actor, resource…" aria-label="Filter recent events">
            </div>
            <div class="sh-tabs" role="group" aria-label="Filter recent events by domain">
              <button type="button" class="sh-tab is-active" data-ledger-domain="all">All</button>
              <button type="button" class="sh-tab" data-ledger-domain="rbac">RBAC / Auth</button>
              <button type="button" class="sh-tab" data-ledger-domain="clinic">Clinic</button>
              <button type="button" class="sh-tab" data-ledger-domain="guidance">Guidance</button>
              <button type="button" class="sh-tab" data-ledger-domain="bmg">Facilities</button>
              <button type="button" class="sh-tab" data-ledger-domain="system">System</button>
            </div>
          </div>
          <?php endif; ?>

          <div class="sh-table-frame">
            <div class="table-responsive">
              <table class="sh-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Event Type</th>
                    <th>Resource</th>
                    <th>Actor</th>
                    <th class="text-end">Timestamp</th>
                  </tr>
                </thead>
                <tbody data-ledger-rows>
                  <?php if (empty($recentAudit)): ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted">No audit events recorded yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($recentAudit as $ae): ?>
                      <?php
                        $ev = strtolower((string) ($ae['event_type'] ?? ''));
                        $evDomain = 'system';
                        if (str_starts_with($ev, 'rbac') || str_starts_with($ev, 'auth') || str_starts_with($ev, 'user')) {
                            $evDomain = 'rbac';
                        } elseif (str_starts_with($ev, 'clinic')) {
                            $evDomain = 'clinic';
                        } elseif (str_starts_with($ev, 'guidance') || str_starts_with($ev, 'counselling')) {
                            $evDomain = 'guidance';
                        } elseif (str_starts_with($ev, 'bmg') || str_starts_with($ev, 'facilities')) {
                            $evDomain = 'bmg';
                        }
                        $searchHaystack = strtolower((string) $ae['id'] . ' ' . $ae['event_type'] . ' ' . $ae['entity_type'] . ' ' . $ae['entity_id'] . ' ' . ($ae['actor_name'] ?? 'system'));
                      ?>
                      <tr data-ledger-domain-row="<?= esc($evDomain, 'attr') ?>" data-ledger-haystack="<?= esc($searchHaystack, 'attr') ?>">
                        <td class="sh-mono-id">#<?= (int) $ae['id'] ?></td>
                        <td>
                          <span class="sh-event-badge">
                            <span class="sh-event-dot is-<?= esc($evDomain, 'attr') ?>"></span>
                            <?= esc($ae['event_type']) ?>
                          </span>
                        </td>
                        <td class="sh-resource-ref"><?= esc($ae['entity_type']) ?> #<?= esc($ae['entity_id']) ?></td>
                        <td class="sh-actor-handle"><?= esc($ae['actor_name'] ?? 'System') ?></td>
                        <td class="sh-time-cell"><?= SchedulingClock::formatManila($ae['occurred_at'], 'M j, Y · h:i A') ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <tr class="d-none" data-ledger-empty-filter><td colspan="5" class="text-center py-4 text-muted">No recent events match this filter.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="col-xl-4">
      <div class="card sh-card mb-3">
        <div class="sh-card-head sh-donut-head">
          <div>
            <h5 class="sh-card-title" data-pop-title>Campus Population Mix</h5>
            <p class="sh-card-desc" data-pop-sub>100-tile proportional directory matrix</p>
          </div>
          <?php if ($canReadAudit && !empty($privilegedRoles)): ?>
          <div class="sh-tabs" role="group" aria-label="Population breakdown view">
            <button type="button" class="sh-tab is-active" data-pop-mode="directory" aria-pressed="true">Directory</button>
            <button type="button" class="sh-tab" data-pop-mode="roles" aria-pressed="false">Staff Roles</button>
          </div>
          <?php endif; ?>
        </div>

        <div class="sh-waffle-body">
          <div class="sh-waffle-hero">
            <div class="sh-waffle-total-wrap">
              <strong class="sh-waffle-total" data-waffle-total><?= number_format($totalPopulation) ?></strong>
              <span class="sh-waffle-unit" data-waffle-unit>records</span>
            </div>
            <span class="sh-waffle-pill" data-waffle-pill>1 tile = 1%</span>
          </div>

          <div id="adminPopulationWaffle" class="sh-waffle-grid" role="img" aria-label="Proportional 100-cell composition matrix"></div>

          <div class="sh-waffle-status" data-waffle-status>
            <span>Hover any tile or row to inspect</span>
            <span>100%</span>
          </div>

          <div class="sh-share-list" data-waffle-legend></div>
        </div>

        <div class="sh-card-footer">
          <div class="sh-coverage-footer" data-pop-footer="directory">
            <div class="sh-coverage-head">
              <span>Active Account Coverage</span>
              <strong><?= number_format($coveragePct, 1) ?>%</strong>
            </div>
            <div class="sh-track">
              <div class="sh-track-fill sh-track-fill-brand" style="width: <?= $coveragePct ?>%;"></div>
              <?php if ($inactivePct > 0): ?>
                <div class="sh-track-fill sh-track-fill-warn" style="width: <?= $inactivePct ?>%;"></div>
              <?php endif; ?>
            </div>
            <div class="sh-coverage-note">
              <?= number_format($activeAccounts) ?> active<?php if ($inactiveAccounts > 0): ?> · <?= number_format($inactiveAccounts) ?> inactive<?php endif; ?> across <?= number_format($totalPopulation) ?> rows
            </div>
          </div>
          <?php if ($canReadAudit && !empty($privilegedRoles)): ?>
          <div class="sh-coverage-footer d-none" data-pop-footer="roles">
            <div class="sh-coverage-note">
              <?= number_format(array_sum($privilegedRoles)) ?> active privileged staff &amp; admin accounts across <?= count($privilegedRoles) ?> roles.
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($canAccessConsoles): ?>
      <div class="card sh-card mb-3">
        <div class="sh-card-head">
          <div>
            <h5 class="sh-card-title">Workstation Consoles</h5>
            <p class="sh-card-desc">Direct access to operational unit consoles</p>
          </div>
        </div>
        <div class="sh-console-body">
          <div class="sh-console-list exec-ops-hub">
            <?php if ($canDashboardLink('clinic.queue.read', 'clinic')): ?>
            <a href="<?= esc(PortalContext::urlFor('clinic', 'clinic/queue')) ?>" class="sh-console-item">
              <span class="sh-console-main">
                <span class="sh-console-icon"><?= view('partials/unit_vector_icon', ['unit' => 'clinic', 'class' => 'unit-btn-icon']) ?></span>
                <span class="sh-console-copy">
                  <span class="sh-console-title">University Clinic Console</span>
                  <span class="sh-console-sub">Queue, triage &amp; patient encounters</span>
                </span>
              </span>
              <i class="bi bi-chevron-right sh-console-arrow" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            <?php if ($canDashboardLink('guidance.cases.read', 'guidance')): ?>
            <a href="<?= esc(PortalContext::urlFor('guidance', 'guidance/cases')) ?>" class="sh-console-item">
              <span class="sh-console-main">
                <span class="sh-console-icon"><?= view('partials/unit_vector_icon', ['unit' => 'guidance', 'class' => 'unit-btn-icon']) ?></span>
                <span class="sh-console-copy">
                  <span class="sh-console-title">Guidance Center Console</span>
                  <span class="sh-console-sub">Cases, counseling &amp; rollout gates</span>
                </span>
              </span>
              <i class="bi bi-chevron-right sh-console-arrow" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            <?php if ($canDashboardLink('facilities.read', 'bmg')): ?>
            <a href="<?= esc(PortalContext::urlFor('bmg', 'facilities')) ?>" class="sh-console-item">
              <span class="sh-console-main">
                <span class="sh-console-icon"><i class="bi bi-recycle" aria-hidden="true"></i></span>
                <span class="sh-console-copy">
                  <span class="sh-console-title">Facilities & BMG Drum Console</span>
                  <span class="sh-console-sub">Drum telemetry &amp; unit operations</span>
                </span>
              </span>
              <i class="bi bi-chevron-right sh-console-arrow" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </section>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
  (function() {
    'use strict';

    function escHtml(str) {
      return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function isDarkTheme() {
      var t = document.documentElement.getAttribute('data-theme');
      if (t === 'dark') return true;
      if (t === 'light') return false;
      return document.body.classList.contains('dark-mode');
    }

    function niceCeiling(maxVal) {
      if (maxVal <= 4) return 4;
      var exp = Math.pow(10, Math.floor(Math.log10(maxVal)));
      var frac = maxVal / exp;
      var nice = frac <= 1.2 ? 1.2 : frac <= 1.6 ? 1.6 : frac <= 2 ? 2 : frac <= 3 ? 3 : frac <= 4 ? 4 : frac <= 5 ? 5 : frac <= 6 ? 6 : frac <= 8 ? 8 : 10;
      var top = Math.ceil(nice * exp);
      return Math.ceil(top / 4) * 4;
    }

    function allocate100(counts) {
      var sum = counts.reduce(function(a, b) { return a + b; }, 0);
      if (sum <= 0) return counts.map(function() { return 0; });
      var exact = counts.map(function(v) { return (v / sum) * 100; });
      var floors = exact.map(function(v) { return Math.floor(v); });
      var rem = 100 - floors.reduce(function(a, b) { return a + b; }, 0);
      var order = exact.map(function(v, i) { return { i: i, r: v - floors[i] }; })
        .sort(function(a, b) { return b.r - a.r; });
      for (var k = 0; k < rem; k++) {
        floors[order[k].i]++;
      }
      return floors;
    }

    function initAdminDashboard() {
      var opsStage = document.getElementById('adminOpsViz');
      var opsTip = document.getElementById('adminOpsTip');
      var waffleGrid = document.getElementById('adminPopulationWaffle');
      if (!opsStage || !waffleGrid) return;

      var opsRange = <?= json_encode($rangeKey) ?>;
      var opsMode = 'stacked';
      var popMode = 'directory';
      var hiddenSeries = {};

      var opsWindows = <?= json_encode($opsWindows) ?>;
      var opsDays = [];
      var allSeries = [];

      function loadRangeWindow(key) {
        var win = opsWindows[key] || opsWindows['7d'] || { days: [], clinic: [], guidance: [], bmg: [], priorTotal: null };
        opsDays = Array.isArray(win.days) ? win.days : [];
        allSeries = [
          { name: 'Clinic Visits', key: 'clinic', data: Array.isArray(win.clinic) ? win.clinic : [] },
          { name: 'Guidance Sessions', key: 'guidance', data: Array.isArray(win.guidance) ? win.guidance : [] },
          { name: 'BMG Drum Runs', key: 'bmg', data: Array.isArray(win.bmg) ? win.bmg : [] }
        ];

        var sums = allSeries.map(function(s) {
          return s.data.reduce(function(a, b) { return a + Number(b || 0); }, 0);
        });
        var total = sums[0] + sums[1] + sums[2];
        var dayCount = Math.max(1, opsDays.length);
        var avg = total / dayCount;
        var pkVal = 0;
        var pkDay = '—';
        for (var i = 0; i < opsDays.length; i++) {
          var dSum = Number(allSeries[0].data[i] || 0) + Number(allSeries[1].data[i] || 0) + Number(allSeries[2].data[i] || 0);
          if (dSum > pkVal) { pkVal = dSum; pkDay = String(opsDays[i]); }
        }

        var labelEl = document.querySelector('[data-ops-range-label]');
        var totalEl = document.querySelector('[data-ops-total]');
        var pillEl = document.querySelector('[data-ops-delta-pill]');
        var avgEl = document.querySelector('[data-ops-avg]');
        var pkEl = document.querySelector('[data-ops-peak]');
        var pkDayEl = document.querySelector('[data-ops-peak-day]');
        var noteEl = document.querySelector('[data-ops-delta-note]');

        if (labelEl) labelEl.textContent = (key === '30d' ? '30-Day' : '7-Day') + ' Throughput';
        if (totalEl) totalEl.textContent = total.toLocaleString('en-US');
        if (avgEl) avgEl.textContent = avg.toFixed(1);
        if (pkEl) pkEl.textContent = pkVal.toLocaleString('en-US');
        if (pkDayEl) pkDayEl.textContent = pkDay;

        sums.forEach(function(sumVal, idx) {
          var vEl = document.querySelector('[data-ops-unit-val="' + idx + '"]');
          var sEl = document.querySelector('[data-ops-unit-share="' + idx + '"]');
          if (vEl) vEl.textContent = sumVal.toLocaleString('en-US');
          if (sEl) sEl.textContent = (total > 0 ? ((sumVal / total) * 100).toFixed(1) : '0.0') + '%';
        });

        var prior = win.priorTotal;
        if (prior !== null && prior !== undefined) {
          var pNum = Number(prior);
          var pct = pNum === 0 ? (total > 0 ? 100 : 0) : Math.round(((total - pNum) / pNum) * 100);
          var pctTxt = (pct > 0 ? '+' : '') + pct + '%';
          var cls = pct > 0 ? 'is-up' : (pct < 0 ? 'is-down' : 'is-flat');
          if (pillEl) {
            pillEl.textContent = pctTxt;
            pillEl.className = 'sh-delta-pill ' + cls;
          }
          if (noteEl) noteEl.textContent = pctTxt + ' vs prior ' + key + ' · Click a unit above to isolate';
        } else {
          if (pillEl) pillEl.className = 'sh-delta-pill d-none';
          if (noteEl) noteEl.textContent = 'Click a unit above to isolate series';
        }
      }

      loadRangeWindow(opsRange);

      var dirItems = [
        { label: 'Students', count: <?= (int) $studentsCount ?> },
        { label: 'Employees (Faculty & Staff)', count: <?= (int) $employeesCount ?> }
      ];
      var roleLabels = <?= json_encode(array_map(static fn ($k) => str_replace('Bmg', 'BMG', ucwords(str_replace('_', ' ', (string) $k))), array_keys($privilegedRoles))) ?>;
      var roleCounts = <?= json_encode(array_map('intval', array_values($privilegedRoles))) ?>;
      var roleItems = roleLabels.map(function(label, i) {
        return { label: label, count: roleCounts[i] || 0 };
      });

      function unitPalette() {
        return isDarkTheme()
          ? ['#e05263', '#e4e4e7', '#52525b']
          : ['#800000', '#27272a', '#a1a1aa'];
      }

      function rolePalette() {
        return isDarkTheme()
          ? ['#e05263', '#f4f4f5', '#2dd4bf', '#fbbf24', '#a1a1aa', '#fb7185', '#52525b']
          : ['#800000', '#27272a', '#0f766e', '#b45309', '#52525b', '#be123c', '#a1a1aa'];
      }

      function activeSeriesList() {
        var pal = unitPalette();
        var out = [];
        allSeries.forEach(function(s, i) {
          if (!hiddenSeries[i]) out.push({ idx: i, name: s.name, key: s.key, data: s.data, color: pal[i] });
        });
        if (!out.length) {
          out.push({ idx: 0, name: allSeries[0].name, key: allSeries[0].key, data: allSeries[0].data, color: pal[0] });
        }
        return out;
      }

      function showOpsTooltip(dayIdx, anchorX, anchorY, active, avgVal) {
        if (!opsTip) return;
        var label = opsDays[dayIdx] || '';
        var dayTotal = 0;
        active.forEach(function(s) { dayTotal += Number(s.data[dayIdx] || 0); });
        var diffPct = avgVal > 0 ? Math.round(((dayTotal - avgVal) / avgVal) * 100) : 0;
        var diffCls = diffPct > 4 ? 'is-up' : (diffPct < -4 ? 'is-down' : '');
        var diffTxt = avgVal > 0 ? ((diffPct > 0 ? '+' : '') + diffPct + '% vs avg') : '';

        var rows = active.map(function(s) {
          var v = Number(s.data[dayIdx] || 0);
          var share = dayTotal > 0 ? Math.round((v / dayTotal) * 100) : 0;
          return '<div class="sh-tooltip-row">' +
            '<span class="sh-tooltip-key"><span class="sh-tooltip-dot" style="background:' + escHtml(s.color) + '"></span>' + escHtml(s.name) + '</span>' +
            '<span class="sh-tooltip-val">' + v.toLocaleString('en-US') + ' <span style="font-weight:400;opacity:.6">(' + share + '%)</span></span>' +
          '</div>';
        }).join('');

        opsTip.innerHTML = '<div class="sh-tooltip">' +
          '<div class="sh-tooltip-head"><span>' + escHtml(label) + '</span>' +
            (diffTxt ? '<span class="sh-tooltip-delta ' + diffCls + '">' + escHtml(diffTxt) + '</span>' : '') +
          '</div>' +
          '<div class="sh-tooltip-rows">' + rows + '</div>' +
          (active.length > 1
            ? '<div class="sh-tooltip-total"><span>Day Total</span><span class="sh-tooltip-val">' + dayTotal.toLocaleString('en-US') + '</span></div>'
            : '') +
        '</div>';

        var bodyRect = opsStage.parentElement.getBoundingClientRect();
        var tipW = 215;
        var left = anchorX + 14;
        if (left + tipW > bodyRect.width - 8) left = Math.max(8, anchorX - tipW - 14);
        var top = Math.max(8, Math.min(anchorY - 24, bodyRect.height - 135));
        opsTip.style.left = left + 'px';
        opsTip.style.top = top + 'px';
        opsTip.classList.add('is-visible');
        opsTip.setAttribute('aria-hidden', 'false');
      }

      function hideOpsTooltip() {
        if (!opsTip) return;
        opsTip.classList.remove('is-visible');
        opsTip.setAttribute('aria-hidden', 'true');
      }

      function renderOpsStacked() {
        var n = opsDays.length;
        if (n === 0) {
          opsStage.innerHTML = '<div class="sh-viz-empty">No operational telemetry recorded in this window.</div>';
          return;
        }
        var active = activeSeriesList();
        var totals = [];
        var maxDay = 0;
        var peakIdx = -1;
        var sumAll = 0;
        for (var d = 0; d < n; d++) {
          var t = 0;
          active.forEach(function(s) { t += Number(s.data[d] || 0); });
          totals.push(t);
          sumAll += t;
          if (t > maxDay) { maxDay = t; peakIdx = d; }
        }
        var avgVal = sumAll / n;
        var ceil = niceCeiling(Math.max(maxDay, 4));

        var w = Math.max(300, Math.floor(opsStage.clientWidth || 680));
        var h = 286;
        var padL = 34;
        var padR = 12;
        var padT = 30;
        var padB = 26;
        var plotW = Math.max(120, w - padL - padR);
        var plotH = h - padT - padB;
        var yOf = function(val) { return padT + plotH - (val / ceil) * plotH; };

        var parts = [];
        parts.push('<svg class="sh-viz-svg" viewBox="0 0 ' + w + ' ' + h + '" width="' + w + '" height="' + h + '" role="img" aria-label="Segmented daily throughput histogram">');

        // 5 horizontal gridlines (0, 25%, 50%, 75%, 100%)
        for (var g = 0; g <= 4; g++) {
          var gv = Math.round((ceil / 4) * g);
          var gy = Math.round(yOf(gv)) + 0.5;
          parts.push('<line class="sh-viz-gridline" x1="' + padL + '" y1="' + gy + '" x2="' + (w - padR) + '" y2="' + gy + '"></line>');
          parts.push('<text class="sh-viz-axis-label" x="' + (padL - 8) + '" y="' + (gy + 3.5) + '" text-anchor="end">' + gv + '</text>');
        }

        // Segmented capsule columns
        var step = plotW / n;
        var barW = n > 14 ? Math.max(6, Math.min(16, step * 0.64)) : Math.max(22, Math.min(44, step * 0.48));
        var segGap = n > 14 ? 2 : 3;
        var segRad = n > 14 ? 2 : 4;
        var labelStep = n > 14 ? Math.ceil(n / 7) : 1;

        for (var i = 0; i < n; i++) {
          var cx = padL + step * i + step / 2;
          var bx = cx - barW / 2;
          var railW = Math.min(step - 4, barW + 18);
          var railX = cx - railW / 2;

          parts.push('<g class="sh-bar-group" data-day-idx="' + i + '" data-cx="' + Math.round(cx) + '">');
          parts.push('<rect class="sh-bar-rail" x="' + railX.toFixed(1) + '" y="' + (padT - 6) + '" width="' + railW.toFixed(1) + '" height="' + (plotH + 10) + '" rx="7"></rect>');

          // Stack segments bottom-to-top with crisp micro-gaps
          var nonZero = active.filter(function(s) { return Number(s.data[i] || 0) > 0; });
          var totalVal = totals[i];
          var totalPx = (totalVal / ceil) * plotH;
          var totalGapPx = Math.max(0, nonZero.length - 1) * segGap;
          var availPx = Math.max(0, totalPx - totalGapPx);
          var curY = padT + plotH;

          nonZero.forEach(function(s, sPos) {
            var v = Number(s.data[i] || 0);
            var sh = totalVal > 0 ? (v / totalVal) * availPx : 0;
            if (sh < 2 && v > 0) sh = 2;
            var sy = curY - sh;
            parts.push('<rect class="sh-bar-seg" x="' + bx.toFixed(1) + '" y="' + sy.toFixed(1) + '" width="' + barW.toFixed(1) + '" height="' + sh.toFixed(1) + '" rx="' + segRad + '" fill="' + escHtml(s.color) + '"></rect>');
            curY = sy - (sPos < nonZero.length - 1 ? segGap : 0);
          });

          // Peak annotation badge
          if (i === peakIdx && maxDay > 0) {
            var peakTopY = curY;
            var pTxt = maxDay.toLocaleString('en-US');
            var pW = Math.max(28, pTxt.length * 7.2 + 12);
            var pY = Math.max(2, peakTopY - 24);
            parts.push('<rect class="sh-viz-peak-bg" x="' + (cx - pW / 2).toFixed(1) + '" y="' + pY.toFixed(1) + '" width="' + pW.toFixed(1) + '" height="18" rx="5"></rect>');
            parts.push('<text class="sh-viz-peak-text" x="' + cx.toFixed(1) + '" y="' + (pY + 12.5).toFixed(1) + '" text-anchor="middle">' + escHtml(pTxt) + '</text>');
          }

          // X-axis label
          var showLabel = (i % labelStep === 0) || (i === n - 1 && (n - 1) % labelStep >= Math.floor(labelStep / 2));
          if (showLabel) {
            parts.push('<text class="sh-viz-x-label" x="' + cx.toFixed(1) + '" y="' + (h - 6) + '" text-anchor="middle">' + escHtml(opsDays[i]) + '</text>');
          }
          parts.push('</g>');
        }

        // Dashed horizontal average reference line (painted cleanly above bars, non-blocking)
        if (avgVal > 0) {
          var ay = Math.round(yOf(avgVal)) + 0.5;
          var avgText = 'avg ' + avgVal.toFixed(1);
          var pillW = avgText.length * 6.4 + 12;
          var pillX = w - padR - pillW;
          parts.push('<g style="pointer-events:none">');
          parts.push('<line class="sh-viz-avg-line" x1="' + padL + '" y1="' + ay + '" x2="' + (pillX - 4) + '" y2="' + ay + '"></line>');
          parts.push('<rect class="sh-viz-avg-pill-bg" x="' + pillX + '" y="' + (ay - 9) + '" width="' + pillW + '" height="18" rx="4"></rect>');
          parts.push('<text class="sh-viz-avg-pill-text" x="' + (pillX + pillW / 2) + '" y="' + (ay + 3.2) + '" text-anchor="middle">' + escHtml(avgText) + '</text>');
          parts.push('</g>');
        }

        parts.push('</svg>');
        opsStage.innerHTML = parts.join('');

        var svg = opsStage.querySelector('.sh-viz-svg');
        if (!svg) return;
        svg.querySelectorAll('.sh-bar-group').forEach(function(grp) {
          grp.addEventListener('mouseenter', function() {
            var idx = Number(grp.getAttribute('data-day-idx'));
            var cxVal = Number(grp.getAttribute('data-cx'));
            svg.classList.add('has-hover');
            svg.querySelectorAll('.sh-bar-group.is-hovered').forEach(function(g) { g.classList.remove('is-hovered'); });
            grp.classList.add('is-hovered');
            showOpsTooltip(idx, cxVal + 16, 42, active, avgVal);
          });
        });
        svg.addEventListener('mouseleave', function() {
          svg.classList.remove('has-hover');
          svg.querySelectorAll('.sh-bar-group.is-hovered').forEach(function(g) { g.classList.remove('is-hovered'); });
          hideOpsTooltip();
        });
      }

      function matrixTone(seriesIdx, ratio) {
        var dark = isDarkTheme();
        if (ratio <= 0) {
          return { bg: dark ? 'rgba(255,255,255,0.03)' : '#f4f4f5', fg: dark ? '#71717a' : '#a1a1aa' };
        }
        var alpha = (0.16 + ratio * 0.78).toFixed(2);
        if (dark) {
          if (seriesIdx === 0) return { bg: 'rgba(224, 82, 99, ' + alpha + ')', fg: ratio > 0.42 ? '#ffffff' : '#fecdd3' };
          if (seriesIdx === 1) return { bg: 'rgba(228, 228, 231, ' + (0.12 + ratio * 0.68).toFixed(2) + ')', fg: ratio > 0.55 ? '#09090b' : '#fafafa' };
          return { bg: 'rgba(113, 113, 122, ' + (0.2 + ratio * 0.72).toFixed(2) + ')', fg: '#fafafa' };
        }
        if (seriesIdx === 0) return { bg: 'rgba(128, 0, 0, ' + alpha + ')', fg: ratio > 0.42 ? '#ffffff' : '#7f1d1d' };
        if (seriesIdx === 1) return { bg: 'rgba(39, 39, 42, ' + alpha + ')', fg: ratio > 0.42 ? '#ffffff' : '#18181b' };
        return { bg: 'rgba(113, 113, 122, ' + (0.12 + ratio * 0.62).toFixed(2) + ')', fg: ratio > 0.5 ? '#ffffff' : '#3f3f46' };
      }

      function renderOpsMatrix() {
        var n = opsDays.length;
        if (n === 0) {
          opsStage.innerHTML = '<div class="sh-viz-empty">No operational telemetry recorded in this window.</div>';
          return;
        }
        var active = activeSeriesList();
        var compact = n > 14;
        var labelCol = compact ? '126px' : '148px';
        var colTpl = labelCol + ' repeat(' + n + ', minmax(' + (compact ? '12px' : '44px') + ', 1fr)) 54px';

        var dayTotals = [];
        var maxDayTotal = 0;
        var sumAll = 0;
        for (var d = 0; d < n; d++) {
          var dt = 0;
          active.forEach(function(s) { dt += Number(s.data[d] || 0); });
          dayTotals.push(dt);
          sumAll += dt;
          if (dt > maxDayTotal) maxDayTotal = dt;
        }
        var avgVal = sumAll / n;

        var html = '<div class="sh-matrix-wrap"><div class="sh-matrix-grid" style="grid-template-columns:' + colTpl + '">';
        html += '<div></div>';
        for (var c = 0; c < n; c++) {
          var showColLbl = !compact || c % 5 === 0 || c === n - 1;
          html += '<div class="sh-matrix-col-head">' + (showColLbl ? escHtml(opsDays[c]) : '') + '</div>';
        }
        html += '<div class="sh-matrix-col-head is-right">Sum</div>';

        active.forEach(function(s) {
          var laneMax = Math.max(1, Math.max.apply(null, s.data.map(Number)));
          var laneSum = s.data.reduce(function(a, b) { return a + Number(b || 0); }, 0);
          html += '<div class="sh-matrix-lane-label"><span class="sh-swatch" style="background:' + escHtml(s.color) + '"></span>' + escHtml(s.name) + '</div>';
          for (var j = 0; j < n; j++) {
            var val = Number(s.data[j] || 0);
            var tone = matrixTone(s.idx, val / laneMax);
            html += '<div class="sh-matrix-cell' + (compact ? ' is-compact' : '') + '" data-mx-day="' + j + '" style="background:' + tone.bg + ';color:' + tone.fg + ';" title="' + escHtml(s.name + ' · ' + opsDays[j] + ': ' + val) + '">' + (compact ? '' : val) + '</div>';
          }
          html += '<div class="sh-matrix-total">' + laneSum.toLocaleString('en-US') + '</div>';
        });

        html += '<div class="sh-matrix-lane-label sh-matrix-sum-row" style="color:var(--sh-muted-fg);font-size:0.73rem;">Daily Total</div>';
        for (var tIdx = 0; tIdx < n; tIdx++) {
          var isPk = dayTotals[tIdx] === maxDayTotal && maxDayTotal > 0;
          html += '<div class="sh-matrix-sum-cell sh-matrix-sum-row' + (isPk ? ' is-peak' : '') + '">' + (compact ? '' : dayTotals[tIdx]) + '</div>';
        }
        html += '<div class="sh-matrix-total sh-matrix-sum-row">' + sumAll.toLocaleString('en-US') + '</div>';
        html += '</div></div>';

        opsStage.innerHTML = html;
        opsStage.querySelectorAll('[data-mx-day]').forEach(function(cell) {
          cell.addEventListener('mouseenter', function() {
            var dIdx = Number(cell.getAttribute('data-mx-day'));
            var cellRect = cell.getBoundingClientRect();
            var bodyRect = opsStage.parentElement.getBoundingClientRect();
            showOpsTooltip(dIdx, cellRect.left - bodyRect.left + cellRect.width / 2, cellRect.top - bodyRect.top, active, avgVal);
          });
          cell.addEventListener('mouseleave', hideOpsTooltip);
        });
      }

      function renderOps() {
        hideOpsTooltip();
        if (opsMode === 'matrix') renderOpsMatrix();
        else renderOpsStacked();
      }

      function renderWaffle() {
        var useRoles = popMode === 'roles' && roleItems.length > 0;
        var items = useRoles ? roleItems : dirItems;
        var total = items.reduce(function(a, it) { return a + it.count; }, 0);
        var colors = useRoles
          ? rolePalette()
          : (isDarkTheme() ? ['#e05263', '#3f3f46'] : ['#800000', '#d4d4d8']);

        var totalEl = document.querySelector('[data-waffle-total]');
        var unitEl = document.querySelector('[data-waffle-unit]');
        var pillEl = document.querySelector('[data-waffle-pill]');
        var statusEl = document.querySelector('[data-waffle-status]');
        var legendEl = document.querySelector('[data-waffle-legend]');

        if (totalEl) totalEl.textContent = total.toLocaleString('en-US');
        if (unitEl) unitEl.textContent = useRoles ? 'staff accounts' : 'records';
        if (pillEl) pillEl.textContent = useRoles ? (items.length + ' roles · 1 tile = 1%') : '1 tile = 1%';

        var tiles = allocate100(items.map(function(it) { return it.count; }));
        var cellHtml = [];
        if (total <= 0) {
          for (var e = 0; e < 100; e++) {
            cellHtml.push('<span class="sh-waffle-cell" style="background:var(--sh-border);"></span>');
          }
        } else {
          tiles.forEach(function(cnt, segIdx) {
            var color = colors[segIdx % colors.length];
            var it = items[segIdx];
            var pct = ((it.count / total) * 100).toFixed(1);
            for (var k = 0; k < cnt; k++) {
              cellHtml.push('<span class="sh-waffle-cell" data-seg="' + segIdx + '" style="background:' + escHtml(color) + ';" title="' + escHtml(it.label + ': ' + it.count.toLocaleString('en-US') + ' (' + pct + '%)') + '"></span>');
            }
          });
        }
        waffleGrid.innerHTML = cellHtml.join('');

        if (legendEl) {
          legendEl.innerHTML = items.map(function(it, segIdx) {
            var color = colors[segIdx % colors.length];
            var pct = total > 0 ? ((it.count / total) * 100).toFixed(1) : '0.0';
            return '<div class="sh-share-item" data-seg-row="' + segIdx + '">' +
              '<div class="sh-share-head">' +
                '<span class="sh-share-name"><span class="sh-swatch" style="background:' + escHtml(color) + ';"></span>' + escHtml(it.label) + '</span>' +
                '<span class="sh-share-nums"><strong>' + it.count.toLocaleString('en-US') + '</strong><span class="sh-share-pct">' + pct + '%</span></span>' +
              '</div>' +
            '</div>';
          }).join('');
        }

        var defaultStatus = '<span>Hover any tile or row to inspect</span><span>' + (useRoles ? items.length + ' active roles' : '100 tiles') + '</span>';
        if (statusEl) statusEl.innerHTML = defaultStatus;

        function setFocus(segIdx) {
          if (segIdx === null || segIdx === undefined) {
            waffleGrid.classList.remove('has-focus');
            waffleGrid.querySelectorAll('.is-focus').forEach(function(el) { el.classList.remove('is-focus'); });
            if (legendEl) legendEl.querySelectorAll('.is-active').forEach(function(el) { el.classList.remove('is-active'); });
            if (statusEl) statusEl.innerHTML = defaultStatus;
            return;
          }
          waffleGrid.classList.add('has-focus');
          waffleGrid.querySelectorAll('.sh-waffle-cell').forEach(function(el) {
            el.classList.toggle('is-focus', Number(el.getAttribute('data-seg')) === segIdx);
          });
          if (legendEl) {
            legendEl.querySelectorAll('[data-seg-row]').forEach(function(el) {
              el.classList.toggle('is-active', Number(el.getAttribute('data-seg-row')) === segIdx);
            });
          }
          var it = items[segIdx];
          if (it && statusEl) {
            var pct = total > 0 ? ((it.count / total) * 100).toFixed(1) : '0.0';
            statusEl.innerHTML = '<span><strong>' + escHtml(it.label) + '</strong> · ' + it.count.toLocaleString('en-US') + '</span><span><strong>' + pct + '%</strong> (' + tiles[segIdx] + ' tiles)</span>';
          }
        }

        waffleGrid.querySelectorAll('[data-seg]').forEach(function(cell) {
          cell.addEventListener('mouseenter', function() { setFocus(Number(cell.getAttribute('data-seg'))); });
        });
        waffleGrid.addEventListener('mouseleave', function() { setFocus(null); });

        if (legendEl) {
          legendEl.querySelectorAll('[data-seg-row]').forEach(function(row) {
            row.addEventListener('mouseenter', function() { setFocus(Number(row.getAttribute('data-seg-row'))); });
            row.addEventListener('mouseleave', function() { setFocus(null); });
          });
        }
      }

      renderOps();
      renderWaffle();

      if (typeof ResizeObserver === 'function' && !opsStage.dataset.roBound) {
        opsStage.dataset.roBound = 'true';
        var lastW = opsStage.clientWidth;
        var ro = new ResizeObserver(function() {
          if (!opsStage.isConnected) { ro.disconnect(); return; }
          var nextW = opsStage.clientWidth;
          if (Math.abs(nextW - lastW) > 6) {
            lastW = nextW;
            renderOps();
          }
        });
        ro.observe(opsStage);
      }

      if (!opsStage.dataset.themeBound) {
        opsStage.dataset.themeBound = 'true';
        var redrawAll = function() { renderOps(); renderWaffle(); };
        document.addEventListener('synapse:theme-change', redrawAll);
        var mo = new MutationObserver(redrawAll);
        mo.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
      }

      document.querySelectorAll('[data-ops-range]').forEach(function(btn) {
        if (btn.dataset.rangeBound) return;
        btn.dataset.rangeBound = 'true';
        btn.addEventListener('click', function() {
          var next = btn.getAttribute('data-ops-range');
          if (!next || next === opsRange || !opsWindows[next]) return;
          opsRange = next;
          document.querySelectorAll('[data-ops-range]').forEach(function(b) {
            var active = b.getAttribute('data-ops-range') === opsRange;
            b.classList.toggle('is-active', active);
            b.setAttribute('aria-pressed', String(active));
          });
          loadRangeWindow(opsRange);
          renderOps();
          if (window.history && typeof window.history.replaceState === 'function') {
            try {
              var url = new URL(window.location.href);
              url.searchParams.set('range', opsRange);
              window.history.replaceState(null, '', url.pathname + url.search + url.hash);
            } catch (_) {}
          }
        });
      });

      document.querySelectorAll('[data-ops-mode]').forEach(function(btn) {
        if (btn.dataset.opsBound) return;
        btn.dataset.opsBound = 'true';
        btn.addEventListener('click', function() {
          var next = btn.getAttribute('data-ops-mode');
          if (!next || next === opsMode) return;
          opsMode = next;
          document.querySelectorAll('[data-ops-mode]').forEach(function(b) {
            var active = b.getAttribute('data-ops-mode') === opsMode;
            b.classList.toggle('is-active', active);
            b.setAttribute('aria-pressed', String(active));
          });
          renderOps();
        });
      });

      document.querySelectorAll('[data-ops-series]').forEach(function(btn) {
        if (btn.dataset.seriesBound) return;
        btn.dataset.seriesBound = 'true';
        btn.addEventListener('click', function() {
          var idx = Number(btn.getAttribute('data-ops-series'));
          if (!Number.isInteger(idx)) return;
          var willHide = !hiddenSeries[idx];
          if (willHide) {
            var remaining = allSeries.filter(function(_, i) { return i !== idx && !hiddenSeries[i]; }).length;
            if (remaining === 0) return;
          }
          hiddenSeries[idx] = willHide;
          btn.classList.toggle('is-muted', willHide);
          btn.setAttribute('aria-pressed', String(!willHide));
          renderOps();
        });
      });

      document.querySelectorAll('[data-pop-mode]').forEach(function(btn) {
        if (btn.dataset.popBound) return;
        btn.dataset.popBound = 'true';
        btn.addEventListener('click', function() {
          var next = btn.getAttribute('data-pop-mode');
          if (!next || next === popMode) return;
          popMode = next;
          document.querySelectorAll('[data-pop-mode]').forEach(function(b) {
            var active = b.getAttribute('data-pop-mode') === popMode;
            b.classList.toggle('is-active', active);
            b.setAttribute('aria-pressed', String(active));
          });
          document.querySelectorAll('[data-pop-footer]').forEach(function(foot) {
            foot.classList.toggle('d-none', foot.getAttribute('data-pop-footer') !== popMode);
          });
          var titleEl = document.querySelector('[data-pop-title]');
          var subEl = document.querySelector('[data-pop-sub]');
          if (titleEl && subEl) {
            titleEl.textContent = popMode === 'roles' ? 'Privileged Role Spread' : 'Campus Population Mix';
            subEl.textContent = popMode === 'roles' ? '100-tile staff & admin distribution' : '100-tile proportional directory matrix';
          }
          renderWaffle();
        });
      });

      <?php if ($canReadAudit): ?>
      var ledgerBody = document.querySelector('[data-ledger-rows]');
      if (ledgerBody && !ledgerBody.dataset.filterBound) {
        ledgerBody.dataset.filterBound = 'true';
        var activeDomain = 'all';
        var searchInput = document.querySelector('[data-ledger-search]');
        var emptyRow = ledgerBody.querySelector('[data-ledger-empty-filter]');
        var applyRowFilter = function() {
          var q = (searchInput ? searchInput.value : '').trim().toLowerCase();
          var visible = 0;
          ledgerBody.querySelectorAll('[data-ledger-domain-row]').forEach(function(row) {
            var domainMatch = activeDomain === 'all' || row.getAttribute('data-ledger-domain-row') === activeDomain;
            var textMatch = q === '' || (row.getAttribute('data-ledger-haystack') || '').indexOf(q) !== -1;
            var show = domainMatch && textMatch;
            row.classList.toggle('d-none', !show);
            if (show) visible++;
          });
          if (emptyRow) emptyRow.classList.toggle('d-none', visible > 0);
        };
        document.querySelectorAll('[data-ledger-domain]').forEach(function(chip) {
          chip.addEventListener('click', function() {
            activeDomain = chip.getAttribute('data-ledger-domain') || 'all';
            document.querySelectorAll('[data-ledger-domain]').forEach(function(c) {
              c.classList.toggle('is-active', c === chip);
            });
            applyRowFilter();
          });
        });
        if (searchInput) searchInput.addEventListener('input', applyRowFilter);
      }
      <?php endif; ?>
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

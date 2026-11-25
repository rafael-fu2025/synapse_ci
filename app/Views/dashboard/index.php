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
          <span class="sh-kpi-split-seg--1" data-width-pct="<?= esc((string) $studentPct, 'attr') ?>"></span>
          <span class="sh-kpi-split-seg--2" data-width-pct="<?= esc((string) $employeePct, 'attr') ?>"></span>
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
              <span class="sh-kpi-spark-bar<?= $sv === $peakVal && $peakVal > 0 ? ' is-peak' : '' ?>" data-height-pct="<?= esc((string) $hPct, 'attr') ?>"></span>
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
            <a href="<?= esc(PortalContext::urlFor('main', 'admin/access')) ?>" class="sh-diag-link">Access <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
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
              <input type="search" id="adminLedgerSearch" class="sh-search-input" data-ledger-search placeholder="Filter event, actor, resource…" aria-label="Filter recent events">
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
          <div id="adminWaffleTip" class="sh-floating-tip" role="tooltip" aria-hidden="true"></div>

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
              <div class="sh-track-fill sh-track-fill-brand" data-width-pct="<?= esc((string) $coveragePct, 'attr') ?>"></div>
              <?php if ($inactivePct > 0): ?>
                <div class="sh-track-fill sh-track-fill-warn" data-width-pct="<?= esc((string) $inactivePct, 'attr') ?>"></div>
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
<script type="application/json" id="adminDashboardConfig"><?= json_encode([
    'rangeKey' => $rangeKey,
    'opsWindows' => $opsWindows,
    'studentsCount' => (int) $studentsCount,
    'employeesCount' => (int) $employeesCount,
    'roleLabels' => array_map(static fn ($k) => str_replace('Bmg', 'BMG', ucwords(str_replace('_', ' ', (string) $k))), array_keys($privilegedRoles)),
    'roleCounts' => array_map('intval', array_values($privilegedRoles)),
    'canReadAudit' => $canReadAudit,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= esc(base_url('assets/js/admin-dashboard.js') . '?v=' . ((defined('FCPATH') ? @filemtime(FCPATH . 'assets/js/admin-dashboard.js') : false) ?: 1), 'attr') ?>"></script>
<?= $this->endSection() ?>

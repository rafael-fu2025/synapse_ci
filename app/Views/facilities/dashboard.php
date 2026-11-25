<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/bmg.css'), 'attr') ?>">
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/bmg-workstation.css'), 'attr') ?>">
<?= $this->endSection() ?>

<?= $this->section('page_actions') ?>
<div class="cdc-actions ce-page-actions">
  <a href="<?= esc(site_url('/facilities'), 'attr') ?>" class="btn btn-primary btn-sm">
    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Drum Rotation
  </a>
  <?php if (!empty($canConfigure)): ?>
    <a href="<?= esc(site_url('/facilities') . '?create=1', 'attr') ?>" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-plus-lg" aria-hidden="true"></i> Add drum
    </a>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$totalUnits = (int) ($totalUnits ?? 0);
$activeUnits = (int) ($activeUnits ?? 0);
$maintenanceUnits = (int) ($maintenanceUnits ?? 0);
$boundDevices = (int) ($boundDevices ?? 0);
$totalBatches = (int) ($totalBatches ?? 0);
$activeBatches = (int) ($activeBatches ?? 0);
$totalTreatedKg = (float) ($totalTreatedKg ?? 0);
$units = is_array($units ?? null) ? $units : [];
$userRole = (string) ($user['role'] ?? 'facilities_op');
$offlineUnits = max(0, $totalUnits - $activeUnits - $maintenanceUnits);
$unboundUnits = max(0, $totalUnits - $boundDevices);
$seriesVals = array_map('intval', is_array($chartSeries ?? null) ? array_values($chartSeries) : []);
$seriesLabels = is_array($chartDays ?? null) ? array_values($chartDays) : [];
$periodTotal = array_sum($seriesVals);
$periodAverage = $periodTotal / max(1, count($seriesVals));
$peakValue = $seriesVals !== [] ? max($seriesVals) : 0;
$peakIndex = $peakValue > 0 ? array_search($peakValue, $seriesVals, true) : false;
$peakLabel = $peakIndex !== false && isset($seriesLabels[$peakIndex]) ? (string) $seriesLabels[$peakIndex] : '—';
$chartData = [
    'days' => $seriesLabels,
    'series' => $seriesVals,
    'status' => [$activeUnits, $maintenanceUnits, $offlineUnits],
];
$shiftNow = \App\Services\Scheduling\SchedulingClock::now();
?>
<section class="cdc-dashboard ce-workspace bmg-workspace bmg-workstation bmg-dashboard"
         data-bmg-dashboard
         data-bmg-chart-data="<?= esc(json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') ?>">

  <!-- Compact context, without a live-device claim. -->
  <div class="cdc-shift-bar">
    <div class="cdc-shift-left">
      <span class="ce-tag"><i class="bi bi-building-gear" aria-hidden="true"></i> Facilities · BMG</span>
      <span class="cdc-shift-identity"><?= esc($user['name'] ?? 'Facilities Operator') ?></span>
      <span class="cdc-shift-sep" aria-hidden="true">|</span>
      <span class="cdc-shift-meta"><?= esc(ucwords(str_replace('_', ' ', $userRole))) ?></span>
    </div>
    <div class="cdc-shift-right">
      <span class="cdc-shift-meta"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= esc($shiftNow->format('D, M j, Y')) ?></span>
      <span class="cdc-shift-clock cdc-mono" data-live-clock data-clock-tz="Asia/Manila" title="Philippine Standard Time (Asia/Manila)">
        <i class="bi bi-clock" aria-hidden="true"></i>
        <time data-live-clock-time datetime="<?= esc($shiftNow->format(\DateTimeInterface::ATOM), 'attr') ?>"><?= esc($shiftNow->format('g:i:s A')) ?></time>
        <span class="cdc-shift-clock-tz">PHT</span>
      </span>
      <span class="ce-tag ce-tag-upper">Recorded state</span>
    </div>
  </div>

  <?= view('partials/intern_supervision_banner', ['user' => $user ?? []]) ?>

  <div class="ce-notice bmg-dashboard-notice">
    <div class="ce-notice-body">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <p>Figures summarize recorded application state only — not verified physical motor state, device liveness, or compost certification.</p>
    </div>
  </div>

  <!-- Shared dashboard metric deck; bindings and weights remain bookkeeping. -->
  <section class="cdc-deck" aria-label="Recorded facilities overview">
    <article class="cdc-deck-cell cdc-deck-cell--hero">
      <div class="cdc-deck-head">
        <span class="cdc-deck-label">Configured active drums</span>
        <span class="cdc-deck-badge">Current</span>
      </div>
      <div class="cdc-deck-main">
        <strong class="cdc-deck-num bmg-number" data-bmg-metric="active-units"><?= number_format($activeUnits) ?></strong>
        <span class="cdc-deck-unit">/ <?= number_format($totalUnits) ?> drums</span>
      </div>
      <div class="cdc-deck-foot">
        <span class="cdc-deck-foot-info"><i class="bi bi-tools" aria-hidden="true"></i> <?= number_format($maintenanceUnits) ?> in maintenance</span>
        <a href="<?= esc(site_url('/facilities'), 'attr') ?>" class="cdc-micro-link">Directory <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>
    </article>

    <article class="cdc-deck-cell">
      <div class="cdc-deck-head">
        <span class="cdc-deck-label">Batch records</span>
        <span class="cdc-deck-badge">All statuses</span>
      </div>
      <div class="cdc-deck-main">
        <strong class="cdc-deck-num bmg-number" data-bmg-metric="total-batches"><?= number_format($totalBatches) ?></strong>
        <span class="cdc-deck-unit">recorded batches</span>
      </div>
      <div class="cdc-deck-foot">
        <span class="cdc-deck-foot-info"><i class="bi bi-hourglass-split" aria-hidden="true"></i> <?= number_format($activeBatches) ?> active batch records</span>
      </div>
    </article>

    <article class="cdc-deck-cell">
      <div class="cdc-deck-head">
        <span class="cdc-deck-label">Bound controllers</span>
        <span class="cdc-deck-badge">Registered</span>
      </div>
      <div class="cdc-deck-main">
        <strong class="cdc-deck-num bmg-number" data-bmg-metric="bound-devices"><?= number_format($boundDevices) ?></strong>
        <span class="cdc-deck-unit">controller bindings</span>
      </div>
      <div class="cdc-deck-foot">
        <span class="cdc-deck-foot-info"><i class="bi bi-cpu" aria-hidden="true"></i> Not a connectivity measurement</span>
      </div>
    </article>

    <article class="cdc-deck-cell">
      <div class="cdc-deck-head">
        <span class="cdc-deck-label">Released batch input</span>
        <span class="cdc-deck-badge">Recorded</span>
      </div>
      <div class="cdc-deck-main">
        <strong class="cdc-deck-num bmg-number" data-bmg-metric="released-input"><?= number_format($totalTreatedKg, 1) ?></strong>
        <span class="cdc-deck-unit">kg</span>
      </div>
      <div class="cdc-deck-foot">
        <span class="cdc-deck-foot-info"><i class="bi bi-box-seam" aria-hidden="true"></i> Input weight, not measured output</span>
      </div>
    </article>
  </section>

  <?php if ($maintenanceUnits > 0 || $unboundUnits > 0): ?>
    <section class="bmg-dashboard-review" aria-label="Configuration review">
      <?php if ($maintenanceUnits > 0): ?>
        <div class="bmg-dashboard-review-item">
          <i class="bi bi-tools" aria-hidden="true"></i>
          <div><strong><?= number_format($maintenanceUnits) ?> maintenance records</strong><span>Review configured unit status in Drum Rotation.</span></div>
        </div>
      <?php endif; ?>
      <?php if ($unboundUnits > 0): ?>
        <div class="bmg-dashboard-review-item">
          <i class="bi bi-cpu" aria-hidden="true"></i>
          <div><strong><?= number_format($unboundUnits) ?> drums without a binding</strong><span>A saved drum record does not connect or start hardware.</span></div>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <!-- Seven-day record activity, with a static summary available without charts. -->
  <div class="bmg-dashboard-analytics">
    <section class="card ce-card cdc-panel bmg-dashboard-activity" aria-labelledby="bmgActivityHeading">
      <div class="cdc-panel-head">
        <div class="cdc-panel-title-wrap">
          <h2 class="cdc-panel-title" id="bmgActivityHeading">Batch records created</h2>
          <span class="cdc-panel-sub">Last 7 days · Manila business days</span>
        </div>
        <span class="ce-tag"><i class="bi bi-calendar-week" aria-hidden="true"></i> 7-day overview</span>
      </div>
      <div class="cdc-period-ribbon">
        <div class="cdc-period-metrics">
          <div class="cdc-period-metric">
            <span class="cdc-period-stat-label">Period total</span>
            <div class="cdc-period-stat-val"><strong class="bmg-number" data-bmg-period-total><?= number_format($periodTotal) ?></strong><span>batch records</span></div>
          </div>
          <div class="cdc-period-metric">
            <span class="cdc-period-stat-label">Daily average</span>
            <div class="cdc-period-stat-val"><strong class="bmg-number"><?= number_format($periodAverage, 1) ?></strong><span>/ day</span></div>
          </div>
          <div class="cdc-period-metric">
            <span class="cdc-period-stat-label">Peak day</span>
            <div class="cdc-period-stat-val"><strong class="bmg-number"><?= number_format($peakValue) ?></strong><span><?= esc($peakLabel) ?></span></div>
          </div>
        </div>
      </div>
      <div class="cdc-chart-stage">
        <div id="bmgActivityChart" class="bmg-dashboard-activity-chart" aria-hidden="true"></div>
        <noscript><p class="bmg-dashboard-chart-caption">Charts require JavaScript. View daily counts below for the recorded series.</p></noscript>
        <?php if ($periodTotal === 0): ?>
          <p class="bmg-dashboard-chart-caption">No batch records were created in this seven-day period.</p>
        <?php else: ?>
          <p class="bmg-dashboard-chart-caption"><span class="cdc-legend-line" aria-hidden="true"></span> Created batch records · not rotation events or completed processing</p>
        <?php endif; ?>
        <details class="bmg-dashboard-chart-data">
          <summary>View daily counts</summary>
          <dl>
            <?php foreach ($seriesLabels as $dayIndex => $dayLabel): ?>
              <div><dt><?= esc($dayLabel) ?></dt><dd class="bmg-number"><?= number_format($seriesVals[$dayIndex] ?? 0) ?></dd></div>
            <?php endforeach; ?>
          </dl>
        </details>
      </div>
    </section>

    <aside class="bmg-dashboard-aside" aria-label="Configured status and operations">
      <section class="card ce-card cdc-panel" aria-labelledby="bmgStatusHeading">
        <div class="cdc-panel-head">
          <div class="cdc-panel-title-wrap">
            <h2 class="cdc-panel-title" id="bmgStatusHeading">Configured drum status</h2>
            <span class="cdc-panel-sub">All current drum records</span>
          </div>
        </div>
        <div class="bmg-dashboard-status-layout">
          <div class="bmg-dashboard-ring" aria-hidden="true">
            <div id="bmgStatusChart" class="bmg-dashboard-status-chart"<?= $totalUnits === 0 ? ' hidden' : '' ?>></div>
            <div class="bmg-dashboard-ring-empty" data-bmg-status-empty<?= $totalUnits > 0 ? ' hidden' : '' ?>></div>
            <div class="bmg-dashboard-ring-total"><strong class="bmg-number"><?= number_format($totalUnits) ?></strong><span>Current drums</span></div>
          </div>
          <dl class="bmg-dashboard-status-list">
            <div><dt><span class="ce-status-dot ce-status-emerald">Configured active</span></dt><dd class="bmg-number"><?= number_format($activeUnits) ?></dd></div>
            <div><dt><span class="ce-status-dot ce-status-amber">Maintenance</span></dt><dd class="bmg-number"><?= number_format($maintenanceUnits) ?></dd></div>
            <div><dt><span class="ce-status-dot ce-status-slate">Configured offline</span></dt><dd class="bmg-number"><?= number_format($offlineUnits) ?></dd></div>
          </dl>
        </div>
        <p class="bmg-dashboard-panel-note"><?= $totalUnits === 0 ? 'No current drums registered. Configured status will appear after setup.' : 'Configured status is not verified motor state or device liveness.' ?></p>
      </section>

      <section class="card ce-card cdc-panel" aria-labelledby="bmgOperationsHeading">
        <div class="cdc-panel-head">
          <div class="cdc-panel-title-wrap">
            <h2 class="cdc-panel-title" id="bmgOperationsHeading">Facilities operations</h2>
            <span class="cdc-panel-sub">Open a workspace to review and act deliberately</span>
          </div>
        </div>
        <nav class="bmg-dashboard-operations" aria-label="Facilities workspaces">
          <a href="<?= esc(site_url('/facilities'), 'attr') ?>" class="bmg-dashboard-operation">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i><span><strong>Drum Rotation workspace</strong><small>Browse drums, schedules and controller setup</small></span><i class="bi bi-arrow-right" aria-hidden="true"></i>
          </a>
          <?php if (!empty($units)): ?>
            <a href="<?= esc(site_url('/facilities/unit/' . (int) $units[0]['unit']['id']), 'attr') ?>" class="bmg-dashboard-operation">
              <i class="bi bi-gear-wide-connected" aria-hidden="true"></i><span><strong>First listed drum</strong><small>Open its configuration and recorded history</small></span><i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
            <?php if (!empty($canUsb)): ?>
              <a href="<?= esc(site_url('/facilities/unit/' . (int) $units[0]['unit']['id'] . '/usb'), 'attr') ?>" class="bmg-dashboard-operation">
                <i class="bi bi-usb-symbol" aria-hidden="true"></i><span><strong>USB commissioning</strong><small>Physical motor-power isolation is still required</small></span><i class="bi bi-arrow-right" aria-hidden="true"></i>
              </a>
            <?php endif; ?>
          <?php endif; ?>
        </nav>
      </section>
    </aside>
  </div>

  <!-- The bounded worklist is separate from the all-current-drum statistics. -->
  <section class="card ce-card cdc-panel bmg-dashboard-worklist" aria-labelledby="bmgWorklistHeading">
    <div class="cdc-panel-head">
      <div class="cdc-panel-title-wrap">
        <h2 class="cdc-panel-title" id="bmgWorklistHeading">Current drum worklist</h2>
        <span class="cdc-panel-sub">Saved configuration and controller bindings</span>
      </div>
      <a href="<?= esc(site_url('/facilities'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">Browse all drums <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
    <p class="bmg-dashboard-worklist-context">Showing <?= number_format(count($units)) ?> of <?= number_format($totalUnits) ?> current drums, ordered by unit ID (limit 200). Summary counts cover all current drums.</p>
    <div class="bmg-dashboard-table-wrap">
      <table class="table bmg-dashboard-table" role="table" data-bmg-unit-table data-mobile-cards="false">
        <thead>
          <tr><th scope="col">Unit code</th><th scope="col">Drum name</th><th scope="col">Capacity</th><th scope="col">Schedule</th><th scope="col">Controller binding</th><th scope="col">Configured status</th></tr>
        </thead>
        <tbody>
          <?php if (empty($units)): ?>
            <tr><td colspan="6" class="bmg-dashboard-worklist-empty"><i class="bi bi-box" aria-hidden="true"></i><strong>No drum units registered yet.</strong><span>Use Drum Rotation to review setup<?= !empty($canConfigure) ? ' or add a drum' : '' ?>.</span></td></tr>
          <?php else: ?>
            <?php foreach ($units as $u):
                $drum = $u['unit'];
                $sched = $u['schedule'] ?? [];
                $dev = $u['device'] ?? null;
                $status = (string) ($drum['status'] ?? 'offline');
                $statusTone = match ($status) { 'active' => 'emerald', 'maintenance' => 'amber', default => 'slate' };
                $scheduleTimes = is_array($sched['times'] ?? null) ? count($sched['times']) : 0;
            ?>
              <tr class="bmg-dashboard-row">
                <th scope="row" data-label="Unit code"><a href="<?= esc(site_url('/facilities/unit/' . (int) $drum['id']), 'attr') ?>" class="bmg-code"><?= esc($drum['unit_code']) ?></a></th>
                <td data-label="Drum name"><?= esc($drum['name']) ?></td>
                <td data-label="Capacity"><span class="bmg-number"><?= number_format((float) $drum['drum_capacity_kg'], 2) ?></span> kg</td>
                <td data-label="Schedule"><span class="ce-tag"><?= !empty($sched['enabled']) ? 'Saved enabled (' . $scheduleTimes . ' times)' : 'Saved disabled' ?></span><small class="bmg-dashboard-cell-note">Saved intent only</small></td>
                <td data-label="Controller binding">
                  <?php if ($dev !== null): ?>
                    <span class="bmg-code"><?= esc($dev['chip_mac'] ?? 'Bound') ?></span>
                  <?php else: ?>
                    <span class="bmg-dashboard-cell-note">Unbound</span>
                  <?php endif; ?>
                </td>
                <td data-label="Configured status"><span class="ce-status-dot ce-status-<?= $statusTone ?>"><?= esc(ucfirst($status)) ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="bmg-dashboard-panel-note">Saved schedules are intent, not confirmation of device-applied settings. Controller bindings do not establish connectivity.</p>
  </section>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
  (function() {
    'use strict';
    var pageLoadHandler;
    function initBmgDashboard(root) {
      var scope = root && typeof root.querySelector === 'function' ? root : document;
      var workspace = scope.querySelector('[data-bmg-dashboard]');
      if (!workspace || !workspace.isConnected || typeof ApexCharts === 'undefined' || typeof ChartTheme === 'undefined') return;
      if (workspace.dataset.bmgDashboardBound === 'true') {
        document.removeEventListener('synapse:page-load', pageLoadHandler);
        return;
      }
      var activityTarget = workspace.querySelector('#bmgActivityChart');
      var statusTarget = workspace.querySelector('#bmgStatusChart');
      var data;
      try { data = JSON.parse(workspace.getAttribute('data-bmg-chart-data') || '{}'); } catch (_) { return; }
      if (!activityTarget || !Array.isArray(data.days) || !Array.isArray(data.series) || data.days.length !== data.series.length || !Array.isArray(data.status)) return;
      workspace.dataset.bmgDashboardBound = 'true';
      document.removeEventListener('synapse:page-load', pageLoadHandler);
      var bindings = new AbortController();
      var numericFont = getComputedStyle(document.body).getPropertyValue('--default-font').trim()
        || '"Nunito Sans", system-ui, sans-serif';
      var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var totalStatus = data.status.reduce(function(total, value) { return total + Number(value); }, 0);
      var escapeHtml = function(value) {
        return String(value == null ? '' : value)
          .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
      };
      var rawSeries = data.series.map(function(n) { return Number(n || 0); });
      var activityCategories = data.days.slice();
      var avgVal = rawSeries.length
        ? rawSeries.reduce(function(sum, n) { return sum + n; }, 0) / rawSeries.length
        : 0;
      var peak = rawSeries.length ? Math.max.apply(null, rawSeries) : 0;
      var peakIndex = peak > 0 ? rawSeries.indexOf(peak) : -1;
      var denseSeries = rawSeries.length > 14;
      var calcNiceMax = function(maxVal) {
        if (maxVal <= 0) return 5;
        var rawStep = (maxVal * 1.25) / 5;
        var mag = Math.pow(10, Math.floor(Math.log10(rawStep)));
        var norm = rawStep / mag;
        var steps = mag < 10 ? [1, 2, 3, 4, 5, 6, 8, 10] : [1, 2, 2.5, 4, 5, 8, 10];
        var chosen = 10;
        for (var si = 0; si < steps.length; si++) {
          if (norm <= steps[si]) { chosen = steps[si]; break; }
        }
        return Math.max(5, Math.round(chosen * mag) * 5);
      };

      // Read this connected page's data, never an outgoing page's captured metrics.
      ChartTheme.create(activityTarget, function(t) {
        var dark = ChartTheme.isDark();
        var lineColor = dark ? '#f87171' : '#800000';
        var surfaceBg = dark ? '#151517' : '#ffffff';
        var gridColor = dark ? '#26262c' : '#e8ecf2';
        var axisColor = dark ? '#9ca3af' : '#64748b';
        var avgColor = dark ? '#71717a' : '#94a3b8';
        var yMax = calcNiceMax(peak);
        var discreteMarkers = peakIndex >= 0 ? [{
          seriesIndex: 0,
          dataPointIndex: peakIndex,
          fillColor: lineColor,
          strokeColor: surfaceBg,
          size: 6,
          strokeWidth: 2.5
        }] : [];
        var yAnnotations = avgVal > 0 ? [{
          y: Number(avgVal.toFixed(1)),
          borderColor: avgColor,
          strokeDashArray: 4,
          opacity: 0.85,
          label: {
            borderColor: dark ? '#2a2a30' : '#e3e6ec',
            borderRadius: 3,
            style: {
              color: axisColor,
              background: dark ? '#1b1b1f' : '#f8f9fb',
              fontSize: '10px',
              fontWeight: 700,
              fontFamily: numericFont,
              padding: { left: 6, right: 6, top: 2, bottom: 2 }
            },
            position: 'left',
            textAnchor: 'start',
            offsetX: 6,
            offsetY: -6,
            text: 'AVG ' + avgVal.toFixed(1)
          }
        }] : [];
        var pointAnnotations = (peakIndex >= 0 && activityCategories[peakIndex] !== undefined) ? [{
          x: activityCategories[peakIndex],
          y: peak,
          marker: { size: 0, strokeWidth: 0, fillColor: 'transparent', strokeColor: 'transparent' },
          label: {
            borderColor: lineColor,
            borderRadius: 3,
            offsetY: -10,
            style: {
              color: '#ffffff',
              background: '#800000',
              fontSize: '10px',
              fontWeight: 700,
              fontFamily: numericFont,
              padding: { left: 6, right: 6, top: 2, bottom: 2 }
            },
            text: 'PEAK ' + peak
          }
        }] : [];

        return ChartTheme.base(t, {
          chart: {
            id: 'bmgActivityChart',
            type: 'area',
            height: 316,
            fontFamily: numericFont,
            toolbar: { show: false },
            zoom: { enabled: false },
            sparkline: { enabled: false },
            animations: {
              enabled: !reducedMotion,
              easing: 'easeinout',
              speed: 520,
              animateGradually: { enabled: true, delay: 40 },
              dynamicAnimation: { enabled: !reducedMotion, speed: 520 }
            }
          },
          series: [{ name: 'Batch records', data: rawSeries }],
          colors: [lineColor],
          stroke: { curve: 'monotoneCubic', width: 2.5, lineCap: 'round' },
          fill: {
            type: 'gradient',
            gradient: {
              shadeIntensity: 0.4,
              opacityFrom: dark ? 0.22 : 0.16,
              opacityTo: 0.0,
              stops: [0, 92, 100]
            }
          },
          markers: {
            size: denseSeries ? 0 : 4,
            colors: [surfaceBg],
            strokeColors: [lineColor],
            strokeWidth: denseSeries ? 0 : 2.2,
            discrete: discreteMarkers,
            hover: { size: 6, sizeOffset: 2 }
          },
          grid: {
            borderColor: gridColor,
            strokeDashArray: 3,
            xaxis: { lines: { show: false } },
            yaxis: { lines: { show: true } },
            padding: { top: 18, right: 12, bottom: 4, left: 6 }
          },
          xaxis: {
            categories: activityCategories,
            tickAmount: denseSeries ? 6 : undefined,
            axisBorder: { show: true, color: gridColor },
            axisTicks: { show: false },
            crosshairs: {
              show: true,
              stroke: { color: dark ? '#3f3f46' : '#cbd5e1', width: 1, dashArray: 3 }
            },
            tooltip: { enabled: false },
            labels: {
              rotate: 0,
              hideOverlappingLabels: true,
              style: { colors: axisColor, fontSize: '11px', fontFamily: numericFont }
            }
          },
          yaxis: {
            min: 0,
            max: yMax,
            tickAmount: 5,
            decimalsInFloat: 0,
            labels: {
              style: { colors: axisColor, fontSize: '11px', fontFamily: numericFont },
              formatter: function(value) { return String(Math.round(value)); }
            }
          },
          annotations: {
            yaxis: yAnnotations,
            points: pointAnnotations
          },
          tooltip: {
            custom: function(ctx) {
              var val = Number(ctx.series[0][ctx.dataPointIndex] || 0);
              var cat = activityCategories[ctx.dataPointIndex] || '';
              var diff = val - avgVal;
              var sign = diff >= 0 ? '+' : '';
              var deltaClass = diff >= 0 ? 'cdc-tip-delta cdc-tip-delta--up' : 'cdc-tip-delta';
              return '<div class="cdc-tip">' +
                '<div class="cdc-tip-head">' + escapeHtml(cat) + '</div>' +
                '<div class="cdc-tip-row">' +
                  '<span class="cdc-tip-label"><span class="cdc-tip-dot" style="background:' + lineColor + '"></span>Batch records</span>' +
                  '<span class="cdc-tip-val">' + val + '</span>' +
                '</div>' +
                (avgVal > 0 ? '<div class="' + deltaClass + '">' + sign + diff.toFixed(1) + ' vs period avg</div>' : '') +
              '</div>';
            }
          }
        });
      });

      if (statusTarget && totalStatus > 0) {
        ChartTheme.create(statusTarget, function(t) {
          var dark = ChartTheme.isDark();
          var sliceGap = dark ? '#161618' : '#ffffff';
          var sliceColors = ChartTheme.themedSlices(t, [t['status-success'], t['status-warning'], t['status-idle']]);
          var statusLabels = ['Configured active', 'Maintenance', 'Configured offline'];
          return ChartTheme.donut(t, {
            chart: { id: 'bmgStatusChart', height: 156, fontFamily: numericFont, animations: { enabled: !reducedMotion } },
            series: data.status,
            labels: statusLabels,
            colors: sliceColors,
            stroke: { width: 3, colors: [sliceGap] },
            plotOptions: {
              pie: {
                expandOnClick: false,
                donut: {
                  size: '76%',
                  labels: {
                    show: true,
                    name: { show: true, fontSize: '9px', fontWeight: 700, color: t['muted-color'], offsetY: 16 },
                    value: { show: true, fontSize: '24px', fontWeight: 700, fontFamily: numericFont, color: t['heading-color'], offsetY: -10, formatter: function() { return String(totalStatus); } },
                    total: { show: true, showAlways: true, label: 'DRUMS', fontSize: '9px', fontWeight: 700, color: t['muted-color'], formatter: function() { return String(totalStatus); } }
                  }
                }
              }
            },
            tooltip: {
              followCursor: false,
              fixed: { enabled: true, position: 'topLeft', offsetX: 0, offsetY: 0 },
              custom: function(ctx) {
                var idx = ctx.seriesIndex;
                var label = statusLabels[idx] || '';
                var val = Number(ctx.series[idx] || 0);
                var pct = totalStatus > 0 ? Math.round((val / totalStatus) * 100) : 0;
                var color = sliceColors[idx] || t['muted-color'];
                return '<div class="cdc-tip">' +
                  '<div class="cdc-tip-head">Drum Status</div>' +
                  '<div class="cdc-tip-row">' +
                    '<span class="cdc-tip-label"><span class="cdc-tip-dot" style="background:' + color + '"></span>' + escapeHtml(label) + '</span>' +
                    '<span class="cdc-tip-val">' + val + ' (' + pct + '%)</span>' +
                  '</div>' +
                '</div>';
              }
            }
          });
        });
      }
      document.addEventListener('synapse:before-swap', function(event) {
        var swapRoot = event && event.detail && event.detail.root;
        if (!swapRoot || (swapRoot !== workspace && !swapRoot.contains(workspace))) return;
        bindings.abort();
        delete workspace.dataset.bmgDashboardBound;
      }, { signal: bindings.signal });
      ChartTheme.initialize();
    }

    pageLoadHandler = function(event) { initBmgDashboard(event && event.detail && event.detail.root); };
    document.addEventListener('synapse:page-load', pageLoadHandler);
    if (!document.body.classList.contains('is-navigating')) {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { initBmgDashboard(document); }, { once: true });
      } else {
        initBmgDashboard(document);
      }
    }
  })();
</script>
<?= $this->endSection() ?>

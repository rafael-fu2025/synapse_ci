<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="cdc-actions">
  <a href="<?= site_url('/clinic/queue') ?>" class="btn btn-primary btn-sm">
    <i class="bi bi-kanban" aria-hidden="true"></i> Live Queue
  </a>
  <a href="<?= site_url('/clinic/appointments/create') ?>" class="btn btn-outline-primary btn-sm" data-app-modal-open>
    <i class="bi bi-calendar-plus" aria-hidden="true"></i> New Appointment
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$curRange = $rangeKey ?? '7d';
$lowStock = (int) ($inventoryAlerts['low_stock'] ?? 0);
$expiredBatches = (int) ($inventoryAlerts['expired_batches'] ?? 0);
$nearExpiryBatches = (int) ($inventoryAlerts['near_expiry_batches'] ?? 0);
$hasInventoryWatch = !empty($inventoryInstalled) && ($lowStock > 0 || $expiredBatches > 0 || $nearExpiryBatches > 0);
$hasIsolationWatch = !empty($inventoryInstalled) && (($activeIsolationCount ?? 0) > 0 || !empty($isolationAlerts));
$reviewStatus = $lowStock > 0 ? 'low_stock' : ($expiredBatches > 0 ? 'expired' : 'expiring_soon');

$openEncountersCount = (int) ($openEncountersCount ?? 0);
$activeQueuePreview = is_array($activeQueuePreview ?? null) ? $activeQueuePreview : [];
$todaySchedule = is_array($todaySchedule ?? null) ? $todaySchedule : [];
$pendingAppointmentsCount = (int) ($pendingAppointmentsCount ?? 0);
$schoolYear = (string) ($schoolYear ?? '');

// Exactly one PHP reference per triage bucket here (+ one inside JS triageData = 2 total per contract test)
$triageEmergency = (int) ($triageCounts['emergency'] ?? 0);
$triageUrgent = (int) ($triageCounts['urgent'] ?? 0);
$triageRoutine = (int) ($triageCounts['routine'] ?? 0);
$triageTotal = $triageEmergency + $triageUrgent + $triageRoutine;
$pctEmergency = $triageTotal > 0 ? round(($triageEmergency / $triageTotal) * 100) : 0;
$pctUrgent = $triageTotal > 0 ? round(($triageUrgent / $triageTotal) * 100) : 0;
$pctRoutine = $triageTotal > 0 ? round(($triageRoutine / $triageTotal) * 100) : 0;

// Computed period analytics from $chartSeries (zero duplication of top KPIs)
$seriesVals = array_map('intval', is_array($chartSeries ?? null) ? $chartSeries : []);
$seriesLabels = is_array($chartDays ?? null) ? $chartDays : [];
$periodTotal = array_sum($seriesVals);
$periodBuckets = max(1, count($seriesVals));
$periodAvg = $periodTotal / $periodBuckets;
$peakValue = $seriesVals !== [] ? max($seriesVals) : 0;
$peakIdx = $seriesVals !== [] ? array_search($peakValue, $seriesVals, true) : false;
$peakLabel = ($peakIdx !== false && isset($seriesLabels[$peakIdx])) ? (string) $seriesLabels[$peakIdx] : '—';
$rangeSubtitle = match ($curRange) {
    '30d' => '30-day clinical intake trajectory · Manila business days',
    'sy' => ($schoolYear !== '' ? 'SY ' . $schoolYear : 'School Year') . ' monthly clinical intake',
    default => '7-day clinical intake trajectory · Manila business days',
};
$avgUnit = $curRange === 'sy' ? '/ month' : '/ day';

$manilaTz = new \DateTimeZone('Asia/Manila');
$utcTz = new \DateTimeZone('UTC');
$formatManilaTime = static function (?string $ts) use ($manilaTz, $utcTz): string {
    if ($ts === null || trim($ts) === '') {
        return '';
    }
    try {
        return (new \DateTimeImmutable($ts, $utcTz))->setTimezone($manilaTz)->format('g:i A');
    } catch (\Throwable) {
        return '';
    }
};
$shiftNow = method_exists(\App\Services\Scheduling\SchedulingClock::class, 'now')
    ? \App\Services\Scheduling\SchedulingClock::now()
    : new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
?>
<div class="page-dashboard nd-dashboard cdc-dashboard"
     data-queue-dashboard="clinic"
     data-queue-counts-url="<?= site_url('/clinic/dashboard/queue-counts') ?>"
     data-queue-date="<?= esc(\App\Services\Scheduling\SchedulingClock::today(), 'attr') ?>">

  <!-- 1. Compact Shift Telemetry Strip (Replaces generic welcome banner) -->
  <div class="cdc-shift-bar">
    <div class="cdc-shift-left">
      <span class="cdc-live-tag">Clinic Operational</span>
      <span class="cdc-shift-identity"><?= esc($user['name'] ?? 'Clinic Staff') ?></span>
      <span class="cdc-shift-sep" aria-hidden="true">|</span>
      <span class="cdc-shift-meta"><?= esc(ucwords(str_replace('_', ' ', $user['role'] ?? 'Clinic Staff'))) ?></span>
      <span class="cdc-shift-sep d-none d-sm-inline" aria-hidden="true">·</span>
      <span class="cdc-shift-meta cdc-mono"><?= esc($shiftNow->format('D, M j, Y')) ?><?= $schoolYear !== '' ? ' · SY ' . esc($schoolYear) : '' ?></span>
    </div>

    <div class="cdc-shift-right">
      <?php if ($hasIsolationWatch): ?>
        <a href="<?= site_url('/clinic/isolation') ?>" class="cdc-status-link cdc-status-link--crimson">
          <span class="cdc-mono"><?= (int) ($activeIsolationCount ?? 0) ?></span> Active Isolations
        </a>
      <?php endif; ?>
      <?php if ($hasInventoryWatch): ?>
        <a href="<?= site_url('/clinic/inventory?status=' . $reviewStatus) ?>" class="cdc-status-link cdc-status-link--amber">
          <span class="cdc-mono"><?= $lowStock + $expiredBatches + $nearExpiryBatches ?></span> Stock Alerts
        </a>
      <?php endif; ?>
      <span class="cdc-shift-clock cdc-mono" data-live-clock data-clock-tz="Asia/Manila" title="Philippine Standard Time (Asia/Manila)">
        <i class="bi bi-clock" aria-hidden="true"></i>
        <time data-live-clock-time datetime="<?= esc($shiftNow->format(\DateTimeInterface::ATOM), 'attr') ?>"><?= esc($shiftNow->format('g:i:s A')) ?></time>
        <span class="cdc-shift-clock-tz">PHT</span>
      </span>
    </div>
  </div>

  <?= view('partials/intern_supervision_banner', ['user' => $user ?? []]) ?>

  <p class="cdc-queue-feedback" data-queue-feedback role="status" hidden></p>

  <!-- 2. Unified 4-Column Asymmetric Live Telemetry Deck -->
  <section class="cdc-deck" aria-label="Live clinic shift telemetry">
    <!-- Hero Cell: Live Patient Queue -->
    <article class="cdc-deck-cell cdc-deck-cell--hero">
      <div class="cdc-deck-head">
        <span class="cdc-deck-label">Today's Patient Queue</span>
        <span class="cdc-deck-badge cdc-deck-badge--live">Live</span>
      </div>
      <div class="cdc-deck-main">
        <strong class="cdc-deck-num" data-queue-count="queueToday"><?= number_format($queueWaiting + $queueCalled) ?></strong>
        <span class="cdc-deck-unit">active in line</span>
      </div>
      <div class="cdc-deck-foot">
        <span class="cdc-deck-foot-info <?= ($queueWaiting > 0) ? 'up' : '' ?>" data-queue-trend="queueWaiting">
          <i class="bi bi-clock" aria-hidden="true"></i>
          <span><span class="cdc-mono" data-queue-count="queueWaiting" data-queue-format="plain"><?= $queueWaiting ?></span> waiting, <span class="cdc-mono" data-queue-count="queueCalled" data-queue-format="plain"><?= $queueCalled ?></span> called</span>
        </span>
        <a href="<?= site_url('/clinic/queue') ?>" class="cdc-micro-link">Board <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>
    </article>

    <!-- Cell 2: Consultations -->
    <article class="cdc-deck-cell">
      <div class="cdc-deck-head">
        <span class="cdc-deck-label">Consultations Completed</span>
        <span class="cdc-deck-badge cdc-mono"><span data-queue-count="queueCompleted" data-queue-format="plain"><?= $queueCompleted ?></span>&nbsp;today</span>
      </div>
      <div class="cdc-deck-main">
        <strong class="cdc-deck-num"><?= number_format($encountersCount) ?></strong>
        <span class="cdc-deck-unit">sealed charts</span>
      </div>
      <div class="cdc-deck-foot">
        <?php if ($openEncountersCount > 0): ?>
          <span class="cdc-deck-foot-info warn">
            <i class="bi bi-pencil-square" aria-hidden="true"></i>
            <span><span class="cdc-mono"><?= $openEncountersCount ?></span> in progress</span>
          </span>
          <a href="<?= site_url('/clinic/encounters?status=in_progress') ?>" class="cdc-micro-link">Resume <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        <?php else: ?>
          <span class="cdc-deck-foot-info up">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <span>All charts sealed</span>
          </span>
          <a href="<?= site_url('/clinic/encounters') ?>" class="cdc-micro-link">Logs <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        <?php endif; ?>
      </div>
    </article>

    <!-- Cell 3: Appointments Today -->
    <article class="cdc-deck-cell">
      <div class="cdc-deck-head">
        <span class="cdc-deck-label">Appointments Today</span>
        <?php if ($pendingAppointmentsCount > 0): ?>
          <span class="cdc-deck-badge cdc-deck-badge--amber cdc-mono"><?= $pendingAppointmentsCount ?> pending</span>
        <?php else: ?>
          <span class="cdc-deck-badge">Scheduled</span>
        <?php endif; ?>
      </div>
      <div class="cdc-deck-main">
        <strong class="cdc-deck-num"><?= number_format($appointmentsToday) ?></strong>
        <span class="cdc-deck-unit">booked today</span>
      </div>
      <div class="cdc-deck-foot">
        <span class="cdc-deck-foot-info">
          <i class="bi bi-calendar-event" aria-hidden="true"></i>
          <span>Clinic calendar</span>
        </span>
        <a href="<?= site_url('/clinic/appointments') ?>" class="cdc-micro-link">Calendar <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>
    </article>

    <!-- Cell 4: Pharmacy & FEFO Stock -->
    <article class="cdc-deck-cell">
      <div class="cdc-deck-head">
        <span class="cdc-deck-label">Active Medicines</span>
        <?php if (!empty($inventoryInstalled)): ?>
          <span class="cdc-deck-badge">FEFO</span>
        <?php else: ?>
          <span class="cdc-deck-badge cdc-deck-badge--amber">Pending</span>
        <?php endif; ?>
      </div>
      <div class="cdc-deck-main">
        <strong class="cdc-deck-num <?= empty($inventoryInstalled) ? 'cdc-deck-num--text' : '' ?>">
          <?= !empty($inventoryInstalled) ? number_format($medicinesCount) : 'Unavailable' ?>
        </strong>
        <?php if (!empty($inventoryInstalled)): ?>
          <span class="cdc-deck-unit">in catalog</span>
        <?php endif; ?>
      </div>
      <div class="cdc-deck-foot">
        <?php if (empty($inventoryInstalled)): ?>
          <span class="cdc-deck-foot-info warn">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            <span>Inventory setup pending</span>
          </span>
        <?php elseif ($hasInventoryWatch): ?>
          <span class="cdc-deck-foot-info warn">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span><span class="cdc-mono"><?= $lowStock ?></span> low · <span class="cdc-mono"><?= $nearExpiryBatches ?></span> exp &le;90d</span>
          </span>
          <a href="<?= site_url('/clinic/inventory?status=' . $reviewStatus) ?>" class="cdc-micro-link">Stock <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        <?php else: ?>
          <span class="cdc-deck-foot-info up">
            <i class="bi bi-shield-check" aria-hidden="true"></i>
            <span>FEFO inventory active</span>
          </span>
          <a href="<?= site_url('/clinic/inventory') ?>" class="cdc-micro-link">Catalog <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        <?php endif; ?>
      </div>
    </article>
  </section>

  <!-- 3. Operational Watch Strip (Only shown when setup pending or alerts exist) -->
  <?php if (empty($inventoryInstalled)): ?>
    <div class="cdc-watch" role="status">
      <div class="cdc-watch-copy">
        <div class="cdc-watch-title">
          <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
          <span>Medicine inventory unavailable.</span>
        </div>
        <div class="cdc-watch-desc">
          <?= esc(\App\Services\Clinic\InventoryService::SETUP_PENDING_MESSAGE) ?>
          Queue, appointments and consultation statistics remain available below.
        </div>
      </div>
    </div>
  <?php elseif ($hasInventoryWatch || $hasIsolationWatch): ?>
    <div class="cdc-watch-grid <?= ($hasInventoryWatch && $hasIsolationWatch) ? '' : 'cdc-watch-grid--single' ?>">
      <?php if ($hasInventoryWatch): ?>
        <div class="cdc-watch">
          <div class="cdc-watch-copy">
            <div class="cdc-watch-title">
              <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
              <span>Inventory Attention Required</span>
            </div>
            <div class="cdc-watch-desc">
              Low-stock items: <strong class="cdc-mono"><?= $lowStock ?></strong> ·
              Near-expiry (&le;90d): <strong class="cdc-mono"><?= $nearExpiryBatches ?></strong> ·
              Expired w/ stock: <strong class="cdc-mono"><?= $expiredBatches ?></strong>
            </div>
          </div>
          <a href="<?= site_url('/clinic/inventory?status=' . $reviewStatus) ?>" class="btn btn-outline-secondary btn-sm text-nowrap">
            Review Stock
          </a>
        </div>
      <?php endif; ?>

      <?php if ($hasIsolationWatch): ?>
        <div class="cdc-watch cdc-watch--crimson">
          <div class="cdc-watch-copy">
            <div class="cdc-watch-title">
              <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
              <span>Communicable Isolation Watch</span>
            </div>
            <div class="cdc-watch-desc">
              Active campus isolations: <strong class="cdc-mono"><?= (int) ($activeIsolationCount ?? 0) ?></strong>
            </div>
            <?php if (!empty($isolationAlerts)): ?>
              <div class="cdc-watch-tags">
                <?php foreach ($isolationAlerts as $alert): ?>
                  <span class="cdc-watch-tag">
                    <?= esc($alert['condition_label']) ?>: <span class="cdc-mono"><?= (int) ($alert['case_count'] ?? 0) ?></span>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <a href="<?= site_url('/clinic/isolation') ?>" class="btn btn-outline-danger btn-sm text-nowrap">
            Isolation Calendar
          </a>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- 4. Asymmetric Bento Row 2: Clinical Intake Analytics (7) + Active Queue & Triage Radar (5) -->
  <section class="cdc-bento-row">
    <!-- Left: Intake Velocity & Computed Period Stats -->
    <div class="card cdc-panel cdc-intake-panel"
         data-cdc-intake-panel
         data-intake-url="<?= site_url('/clinic/dashboard/intake-series') ?>">
      <div class="cdc-panel-head">
        <div class="cdc-panel-title-wrap">
          <h5 class="cdc-panel-title">Clinical Patient Intake &amp; Consultations</h5>
          <span class="cdc-panel-sub" data-cdc-intake-subtitle><?= esc($rangeSubtitle) ?></span>
        </div>
        <div class="cdc-range-switch" role="group" aria-label="Chart timeframe" data-navigation="false">
          <a href="<?= site_url('/clinic?range=7d') ?>" data-cdc-range="7d" class="cdc-range-btn <?= $curRange === '7d' ? 'active' : '' ?>" <?= $curRange === '7d' ? 'aria-current="page"' : '' ?>>7 Days</a>
          <a href="<?= site_url('/clinic?range=30d') ?>" data-cdc-range="30d" class="cdc-range-btn <?= $curRange === '30d' ? 'active' : '' ?>" <?= $curRange === '30d' ? 'aria-current="page"' : '' ?>>30 Days</a>
          <a href="<?= site_url('/clinic?range=sy') ?>" data-cdc-range="sy" class="cdc-range-btn <?= $curRange === 'sy' ? 'active' : '' ?>" <?= $curRange === 'sy' ? 'aria-current="page"' : '' ?>>School Year</a>
        </div>
      </div>

      <div class="cdc-period-ribbon">
        <div class="cdc-period-metrics">
          <div class="cdc-period-metric">
            <span class="cdc-period-stat-label">Period Volume</span>
            <div class="cdc-period-stat-val">
              <strong data-cdc-intake-total><?= number_format($periodTotal) ?></strong>
              <span>encounters</span>
            </div>
          </div>
          <div class="cdc-period-metric">
            <span class="cdc-period-stat-label" data-cdc-intake-avg-label><?= $curRange === 'sy' ? 'Monthly Average' : 'Daily Average' ?></span>
            <div class="cdc-period-stat-val">
              <strong data-cdc-intake-avg><?= number_format($periodAvg, 1) ?></strong>
              <span data-cdc-intake-avg-unit><?= esc($avgUnit) ?></span>
            </div>
          </div>
          <div class="cdc-period-metric">
            <span class="cdc-period-stat-label">Peak Volume</span>
            <div class="cdc-period-stat-val">
              <strong data-cdc-intake-peak><?= number_format($peakValue) ?></strong>
              <span data-cdc-intake-peak-label>on <?= esc($peakLabel) ?></span>
            </div>
          </div>
        </div>

        <div class="cdc-chart-legend" aria-hidden="true">
          <span class="cdc-legend-item"><span class="cdc-legend-line"></span>Encounters</span>
          <span class="cdc-legend-item"><span class="cdc-legend-dash"></span>Avg</span>
          <span class="cdc-legend-item" data-cdc-intake-peak-legend<?= $peakValue <= 0 ? ' hidden' : '' ?>><span class="cdc-legend-peak"></span>Peak</span>
        </div>
      </div>

      <p class="cdc-intake-error" data-cdc-intake-error role="alert" hidden></p>

      <div class="cdc-chart-stage">
        <div id="clinicActivityChart" class="nd-chart-canvas"></div>
      </div>
    </div>

    <!-- Right: Active Queue Triage Breakdown + Live Up Next Queue Feed -->
    <div class="card cdc-panel">
      <div class="cdc-panel-head">
        <div class="cdc-panel-title-wrap">
          <h5 class="cdc-panel-title">Active Queue &amp; Triage Acuity</h5>
          <span class="cdc-panel-sub">Today&rsquo;s live priority distribution &amp; waiting room</span>
        </div>
        <a href="<?= site_url('/clinic/queue') ?>" class="cdc-micro-link">Manage <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>

      <div class="cdc-triage-top">
        <div class="cdc-donut-stage<?= $triageTotal === 0 ? ' cdc-donut-stage--empty' : '' ?>">
          <div id="clinicTriageChart" class="nd-donut-canvas cdc-triage-donut"></div>
        </div>

        <div class="cdc-acuity-stack">
          <div class="cdc-acuity-row">
            <div class="cdc-acuity-row-top">
              <span class="cdc-dot cdc-dot--crimson">Emergency</span>
              <span class="cdc-acuity-nums">
                <span class="cdc-acuity-pct"><?= (int) $pctEmergency ?>%</span>
                <span class="cdc-acuity-count"><?= $triageEmergency ?></span>
              </span>
            </div>
            <div class="cdc-acuity-track" aria-hidden="true">
              <div class="cdc-acuity-fill cdc-acuity-fill--emergency" style="width: <?= (int) $pctEmergency ?>%;"></div>
            </div>
          </div>
          <div class="cdc-acuity-row">
            <div class="cdc-acuity-row-top">
              <span class="cdc-dot cdc-dot--amber">Urgent</span>
              <span class="cdc-acuity-nums">
                <span class="cdc-acuity-pct"><?= (int) $pctUrgent ?>%</span>
                <span class="cdc-acuity-count"><?= $triageUrgent ?></span>
              </span>
            </div>
            <div class="cdc-acuity-track" aria-hidden="true">
              <div class="cdc-acuity-fill cdc-acuity-fill--urgent" style="width: <?= (int) $pctUrgent ?>%;"></div>
            </div>
          </div>
          <div class="cdc-acuity-row">
            <div class="cdc-acuity-row-top">
              <span class="cdc-dot cdc-dot--emerald">Routine</span>
              <span class="cdc-acuity-nums">
                <span class="cdc-acuity-pct"><?= (int) $pctRoutine ?>%</span>
                <span class="cdc-acuity-count"><?= $triageRoutine ?></span>
              </span>
            </div>
            <div class="cdc-acuity-track" aria-hidden="true">
              <div class="cdc-acuity-fill cdc-acuity-fill--routine" style="width: <?= (int) $pctRoutine ?>%;"></div>
            </div>
          </div>
        </div>
      </div>

      <div class="cdc-subhead">
        <span>Up Next in Waiting Room</span>
        <span class="cdc-mono"><?= count($activeQueuePreview) ?> shown</span>
      </div>

      <div class="cdc-queue-feed">
        <?php if (empty($activeQueuePreview)): ?>
          <div class="cdc-empty-block">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            Waiting room is currently clear.
          </div>
        <?php else: ?>
          <?php foreach ($activeQueuePreview as $qItem): ?>
            <?php
              $qPriority = (string) ($qItem['priority'] ?? 'routine');
              $qRowMod = match ($qPriority) {
                  'emergency' => 'cdc-queue-row--emergency',
                  'urgent' => 'cdc-queue-row--urgent',
                  default => 'cdc-queue-row--routine',
              };
              $qStatus = (string) ($qItem['status'] ?? 'waiting');
              [$qDotClass, $qStatusLabel] = match ($qStatus) {
                  'in_consultation' => ['cdc-dot--sky', 'In Session'],
                  'called' => ['cdc-dot--amber', 'Called'],
                  default => ['cdc-dot--slate', 'Waiting'],
              };
              $qSub = !empty($qItem['program'])
                  ? $qItem['program'] . (!empty($qItem['year_level']) ? '-' . $qItem['year_level'] : '')
                  : (!empty($qItem['employee_dept']) ? $qItem['employee_dept'] : $qItem['mis_id']);
            ?>
            <a href="<?= site_url('/clinic/queue') ?>" class="cdc-queue-row <?= $qRowMod ?>">
              <div class="cdc-queue-row-left">
                <span class="cdc-ticket-code"><?= esc($qItem['ticket_code']) ?></span>
                <div class="cdc-queue-patient">
                  <span class="cdc-queue-name"><?= esc($qItem['last_name'] . ', ' . $qItem['first_name']) ?></span>
                  <span class="cdc-queue-meta"><?= esc($qSub) ?></span>
                </div>
              </div>
              <span class="cdc-dot <?= $qDotClass ?>"><?= esc($qStatusLabel) ?></span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- 5. Asymmetric Bento Row 3: Actionable Recent Encounters (7) + Today's Schedule (5) -->
  <section class="cdc-bento-row">
    <!-- Left: Actionable Clinical Worklist -->
    <div class="card cdc-panel">
      <div class="cdc-panel-head">
        <div class="cdc-panel-title-wrap">
          <h5 class="cdc-panel-title">Recent Clinical Encounters</h5>
          <span class="cdc-panel-sub">Latest consultations &amp; active charts requiring follow-up</span>
        </div>
        <div class="d-flex align-items-center gap-3">
          <?php if ($openEncountersCount > 0): ?>
            <span class="cdc-dot cdc-dot--amber cdc-mono"><?= $openEncountersCount ?> open</span>
          <?php endif; ?>
          <a href="<?= site_url('/clinic/encounters') ?>" class="cdc-micro-link">
            View all <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </a>
        </div>
      </div>

      <div class="cdc-panel-body cdc-panel-body--flush">
        <div class="table-responsive">
          <table class="table table-hover align-middle cdc-worklist" data-mobile-cards="false">
            <thead>
              <tr>
                <th>Patient &amp; Chart</th>
                <th>Complaint &amp; Clinician</th>
                <th>Status</th>
                <th class="text-end">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentEncounters)): ?>
                <tr>
                  <td colspan="4" class="cdc-empty-block">
                    <i class="bi bi-clipboard2" aria-hidden="true"></i>
                    No encounters recorded yet.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentEncounters as $re): ?>
                  <?php
                    $reStatus = (string) ($re['status'] ?? '');
                    $isOpen = $reStatus === 'in_progress';
                    $dotClass = match ($reStatus) {
                        'completed' => 'cdc-dot--emerald',
                        'in_progress' => 'cdc-dot--amber',
                        'referred' => 'cdc-dot--sky',
                        default => 'cdc-dot--slate',
                    };
                    $statusLabel = ucwords(str_replace('_', ' ', $reStatus));
                    $initials = strtoupper(substr((string) ($re['first_name'] ?? ''), 0, 1) . substr((string) ($re['last_name'] ?? ''), 0, 1));
                    $timeStr = $formatManilaTime($re['visited_at'] ?? $re['started_at'] ?? null);
                    $reTenantId = (int) (session()->get('user')['tenant_id'] ?? 0);
                    $rePhotoUrl = $reTenantId > 0 ? (new \App\Services\ProfilePhotoService())->personPhotoUrl($reTenantId, (int) ($re['person_id'] ?? 0)) : null;
                  ?>
                  <tr>
                    <td data-label="Patient & Chart">
                      <div class="cdc-pt-cell">
                        <span class="cdc-initials <?= $isOpen ? 'cdc-initials--active' : '' ?>" aria-hidden="true"><?= \App\Services\ProfileAvatar::contentHtml($rePhotoUrl, $initials, $re) ?></span>
                        <div style="min-width: 0;">
                          <span class="cdc-pt-name"><?= esc($re['last_name'] . ', ' . $re['first_name']) ?></span>
                          <span class="cdc-pt-sub cdc-mono">#<?= (int) $re['id'] ?> · <?= esc($re['mis_id']) ?></span>
                        </div>
                      </div>
                    </td>
                    <td data-label="Complaint & Clinician">
                      <span class="cdc-complaint-line"><?= esc($re['chief_complaint'] ?? 'Routine consultation') ?></span>
                      <span class="cdc-clinician-sub">
                        <?= esc($re['attending_name'] ?? 'Clinic Staff') ?><?= $timeStr !== '' ? ' · ' . esc($timeStr) : '' ?>
                      </span>
                    </td>
                    <td data-label="Status">
                      <span class="cdc-dot <?= $dotClass ?>"><?= esc($statusLabel) ?></span>
                    </td>
                    <td data-label="Action" class="text-end">
                      <a href="<?= site_url('/clinic/encounters/' . (int) $re['id']) ?>"
                         class="btn btn-xs <?= $isOpen ? 'btn-primary' : 'btn-outline-secondary' ?>">
                        <?= $isOpen ? 'Resume' : 'View' ?>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Right: Today's Clinic Schedule & Quick Tools Footer -->
    <div class="card cdc-panel">
      <div class="cdc-panel-head">
        <div class="cdc-panel-title-wrap">
          <h5 class="cdc-panel-title">Today&rsquo;s Clinic Schedule</h5>
          <span class="cdc-panel-sub cdc-mono"><?= date('M j, Y') ?> · <?= number_format($appointmentsToday) ?> booked</span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <a href="<?= site_url('/clinic/appointments/create') ?>" class="cdc-micro-link" data-app-modal-open>+ Book</a>
          <span class="cdc-shift-sep" aria-hidden="true">·</span>
          <a href="<?= site_url('/clinic/appointments') ?>" class="cdc-micro-link">All <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
      </div>

      <?php if ($pendingAppointmentsCount > 0): ?>
        <div class="cdc-pending-bar">
          <span><strong class="cdc-mono"><?= $pendingAppointmentsCount ?></strong> booking request<?= $pendingAppointmentsCount === 1 ? '' : 's' ?> awaiting review</span>
          <a href="<?= site_url('/clinic/appointments') ?>" class="cdc-micro-link">Review <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
      <?php endif; ?>

      <div class="cdc-schedule-list">
        <?php if (empty($todaySchedule)): ?>
          <div class="cdc-empty-block">
            <i class="bi bi-calendar2-check" aria-hidden="true"></i>
            No clinic appointments scheduled for today.
          </div>
        <?php else: ?>
          <?php foreach ($todaySchedule as $appt): ?>
            <?php
              $apptTime = $formatManilaTime($appt['starts_at'] ?? null) ?: '—';
              $apptStatus = (string) ($appt['status'] ?? 'confirmed');
              $apptDot = match ($apptStatus) {
                  'confirmed', 'completed' => 'cdc-dot--emerald',
                  'requested' => 'cdc-dot--amber',
                  'checked_in', 'scheduled' => 'cdc-dot--sky',
                  default => 'cdc-dot--slate',
              };
              $apptReason = trim((string) ($appt['reason'] ?? '')) !== ''
                  ? (string) $appt['reason']
                  : ucwords(str_replace('_', ' ', (string) ($appt['type'] ?? 'Consultation')));
            ?>
            <div class="cdc-sched-row">
              <span class="cdc-sched-time"><?= esc($apptTime) ?></span>
              <div class="cdc-sched-info">
                <span class="cdc-sched-name"><?= esc($appt['last_name'] . ', ' . $appt['first_name']) ?></span>
                <span class="cdc-sched-reason"><?= esc($apptReason) ?></span>
              </div>
              <span class="cdc-dot <?= $apptDot ?>"><?= esc(ucwords(str_replace('_', ' ', $apptStatus))) ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Understated 4-Item Quick Jump Footer -->
      <nav class="cdc-tools-footer" aria-label="Clinic workstation shortcuts">
        <a href="<?= site_url('/clinic/patients') ?>" class="cdc-tool-link">
          <i class="bi bi-person-lines-fill" aria-hidden="true"></i>
          <span>Patients</span>
        </a>
        <a href="<?= site_url('/clinic/inventory') ?>" class="cdc-tool-link">
          <i class="bi bi-capsule" aria-hidden="true"></i>
          <span>Inventory</span>
        </a>
        <a href="<?= site_url('/clinic/isolation') ?>" class="cdc-tool-link">
          <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
          <span>Isolation</span>
        </a>
        <a href="<?= site_url('/clinic/reports') ?>" class="cdc-tool-link">
          <i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i>
          <span>Reports</span>
        </a>
      </nav>
    </div>
  </section>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/queue-live.js') ?>"></script>
<script>
  (function() {
    'use strict';
    var pageLoadHandler;
    function initClinicDashboard(root) {
      var scope = root && typeof root.querySelector === 'function' ? root : document;
      var activityTarget = scope.querySelector('#clinicActivityChart');
      var triageTarget = scope.querySelector('#clinicTriageChart');
      if (!activityTarget || typeof ApexCharts === 'undefined' || typeof ChartTheme === 'undefined') return;
      // Initial DOM readiness and page-load can both fire; the chart and controls
      // must share exactly one state closure for the lifetime of this target.
      if (activityTarget.dataset.clinicDashboardBound === 'true') return;
      activityTarget.dataset.clinicDashboardBound = 'true';
      document.removeEventListener('synapse:page-load', pageLoadHandler);
      var bindings = new AbortController();
      var stopped = false;
      var activeController = null;
      var counterRaf = 0;
      var requestToken = 0;

      var intakePanel = scope.querySelector('[data-cdc-intake-panel]');
      var intakeUrl = intakePanel ? (intakePanel.getAttribute('data-intake-url') || '') : '';
      var rangeSwitch = intakePanel ? intakePanel.querySelector('.cdc-range-switch') : null;
      var numericFont = getComputedStyle(document.body).getPropertyValue('--default-font').trim()
        || '"Nunito Sans", system-ui, sans-serif';

      var escapeHtml = function(value) {
        return String(value == null ? '' : value)
          .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
      };

      var formatInt = function(n) {
        return Number(n || 0).toLocaleString('en-US');
      };

      var intakeState = {
        rangeKey: <?= json_encode($curRange) ?>,
        days: <?= json_encode($seriesLabels) ?>,
        series: <?= json_encode($seriesVals) ?>,
        subtitle: <?= json_encode($rangeSubtitle) ?>,
        avgLabel: <?= json_encode($curRange === 'sy' ? 'Monthly Average' : 'Daily Average') ?>,
        avgUnit: <?= json_encode($avgUnit) ?>,
        total: <?= (int) $periodTotal ?>,
        average: <?= json_encode(round($periodAvg, 1)) ?>,
        peakValue: <?= (int) $peakValue ?>,
        peakLabel: <?= json_encode($peakLabel) ?>
      };
      var seriesCache = {};
      seriesCache[intakeState.rangeKey] = intakeState;

      var triageData = [
        <?= (int) $triageCounts['emergency'] ?>,
        <?= (int) $triageCounts['urgent'] ?>,
        <?= (int) $triageCounts['routine'] ?>
      ];
      var totalTriage = triageData.reduce(function(a, b) { return a + b; }, 0);
      if (totalTriage === 0) triageData = [0, 0, 1];

      var reducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
      var activityChartId = 'clinicActivityIntake';

      var buildActivityOptions = function(t) {
        var rawSeries = Array.isArray(intakeState.series) ? intakeState.series.map(function(n) { return Number(n || 0); }) : [];
        var activityCategories = Array.isArray(intakeState.days) ? intakeState.days.slice() : [];
        var avgVal = rawSeries.length
          ? rawSeries.reduce(function(sum, n) { return sum + n; }, 0) / rawSeries.length
          : 0;
        var peakVal = rawSeries.length ? Math.max.apply(null, rawSeries) : 0;
        var peakIndex = peakVal > 0 ? rawSeries.indexOf(peakVal) : -1;
        var denseSeries = rawSeries.length > 14;

        var dark = ChartTheme.isDark();
        var lineColor = dark ? '#f87171' : '#800000';
        var surfaceBg = dark ? '#151517' : '#ffffff';
        var gridColor = dark ? '#26262c' : '#e8ecf2';
        var axisColor = dark ? '#9ca3af' : '#64748b';
        var avgColor = dark ? '#71717a' : '#94a3b8';
        var discreteMarkers = peakIndex >= 0 ? [{
          seriesIndex: 0,
          dataPointIndex: peakIndex,
          fillColor: lineColor,
          strokeColor: surfaceBg,
          size: 6,
          strokeWidth: 2.5
        }] : [];
        var calcNiceMax = function(peak) {
          if (peak <= 0) return 10;
          var rawStep = (peak * 1.25) / 5;
          var mag = Math.pow(10, Math.floor(Math.log10(rawStep)));
          var norm = rawStep / mag;
          var steps = mag < 10 ? [1, 2, 3, 4, 5, 6, 8, 10] : [1, 2, 2.5, 4, 5, 8, 10];
          var chosen = 10;
          for (var si = 0; si < steps.length; si++) {
            if (norm <= steps[si]) { chosen = steps[si]; break; }
          }
          return Math.max(5, Math.round(chosen * mag) * 5);
        };
        var yMax = calcNiceMax(peakVal);
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
          y: peakVal,
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
            text: 'PEAK ' + peakVal
          }
        }] : [];

        return ChartTheme.base(t, {
          chart: {
            id: activityChartId,
            type: 'area',
            height: 316,
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
          series: [{
            name: 'Encounters',
            data: rawSeries
          }],
          colors: [lineColor],
          stroke: {
            curve: 'monotoneCubic',
            width: 2.5,
            lineCap: 'round'
          },
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
            labels: {
              style: { colors: axisColor, fontSize: '11px', fontFamily: numericFont },
              formatter: function(val) { return String(Math.round(val)); }
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
                  '<span class="cdc-tip-label"><span class="cdc-tip-dot" style="background:' + lineColor + '"></span>Encounters</span>' +
                  '<span class="cdc-tip-val">' + val + '</span>' +
                '</div>' +
                (avgVal > 0 ? '<div class="' + deltaClass + '">' + sign + diff.toFixed(1) + ' vs period avg</div>' : '') +
              '</div>';
            }
          }
        });
      };

      ChartTheme.create(activityTarget, buildActivityOptions);

      if (intakePanel && rangeSwitch && intakeUrl && typeof window.fetch === 'function') {
        var animateCounters = function(fromState, toState, totalEl, avgEl, peakEl) {
          if (counterRaf) {
            cancelAnimationFrame(counterRaf);
            counterRaf = 0;
          }
          if (reducedMotion || typeof window.requestAnimationFrame !== 'function') {
            if (totalEl) totalEl.textContent = formatInt(toState.total);
            if (avgEl) avgEl.textContent = toState.average.toFixed(1);
            if (peakEl) peakEl.textContent = formatInt(toState.peakValue);
            return;
          }
          var startTime = performance.now();
          var duration = 420;
          var fromTotal = Number(fromState.total || 0);
          var fromAvg = Number(fromState.average || 0);
          var fromPeak = Number(fromState.peakValue || 0);
          var step = function(now) {
            var p = Math.min(1, (now - startTime) / duration);
            var eased = 1 - Math.pow(1 - p, 3);
            if (totalEl) totalEl.textContent = formatInt(Math.round(fromTotal + (toState.total - fromTotal) * eased));
            if (avgEl) avgEl.textContent = (fromAvg + (toState.average - fromAvg) * eased).toFixed(1);
            if (peakEl) peakEl.textContent = formatInt(Math.round(fromPeak + (toState.peakValue - fromPeak) * eased));
            if (p < 1) {
              counterRaf = requestAnimationFrame(step);
            } else {
              counterRaf = 0;
            }
          };
          counterRaf = requestAnimationFrame(step);
        };
        var setActiveRangeButton = function(key) {
          rangeSwitch.querySelectorAll('[data-cdc-range]').forEach(function(btn) {
            var isSelected = btn.getAttribute('data-cdc-range') === key;
            btn.classList.toggle('active', isSelected);
            if (isSelected) {
              btn.setAttribute('aria-current', 'page');
            } else {
              btn.removeAttribute('aria-current');
            }
          });
        };
        var setIntakeError = function(msg) {
          var errEl = intakePanel.querySelector('[data-cdc-intake-error]');
          if (!errEl) return;
          if (msg) {
            errEl.textContent = msg;
            errEl.hidden = false;
          } else {
            errEl.textContent = '';
            errEl.hidden = true;
          }
        };
        var syncAddressRange = function(key) {
          try {
            var nextUrl = new URL(window.location.href);
            if (key === '7d') {
              nextUrl.searchParams.delete('range');
            } else {
              nextUrl.searchParams.set('range', key);
            }
            if (window.SynapseNavigation && typeof window.SynapseNavigation.updateHistory === 'function') {
              window.SynapseNavigation.updateHistory(nextUrl.href, 'replace');
            } else if (window.history && typeof window.history.replaceState === 'function') {
              window.history.replaceState(window.history.state, '', nextUrl.href);
            }
          } catch (_) {
            // Keep existing URL if history update is unavailable.
          }
        };
        var applyIntakePayload = function(payload) {
          var prevState = intakeState;
          intakeState = {
            rangeKey: payload.rangeKey,
            days: Array.isArray(payload.days) ? payload.days : [],
            series: Array.isArray(payload.series) ? payload.series.map(function(n) { return Number(n || 0); }) : [],
            subtitle: String(payload.subtitle || ''),
            avgLabel: String(payload.avgLabel || 'Daily Average'),
            avgUnit: String(payload.avgUnit || '/ day'),
            total: Number(payload.total || 0),
            average: Number(payload.average || 0),
            peakValue: Number(payload.peakValue || 0),
            peakLabel: String(payload.peakLabel || '—')
          };

          var subEl = intakePanel.querySelector('[data-cdc-intake-subtitle]');
          var totalEl = intakePanel.querySelector('[data-cdc-intake-total]');
          var avgLabelEl = intakePanel.querySelector('[data-cdc-intake-avg-label]');
          var avgEl = intakePanel.querySelector('[data-cdc-intake-avg]');
          var avgUnitEl = intakePanel.querySelector('[data-cdc-intake-avg-unit]');
          var peakEl = intakePanel.querySelector('[data-cdc-intake-peak]');
          var peakLabelEl = intakePanel.querySelector('[data-cdc-intake-peak-label]');
          var peakLegendEl = intakePanel.querySelector('[data-cdc-intake-peak-legend]');

          if (subEl) subEl.textContent = intakeState.subtitle;
          if (avgLabelEl) avgLabelEl.textContent = intakeState.avgLabel;
          if (avgUnitEl) avgUnitEl.textContent = intakeState.avgUnit;
          if (peakLabelEl) peakLabelEl.textContent = 'on ' + intakeState.peakLabel;
          if (peakLegendEl) peakLegendEl.hidden = intakeState.peakValue <= 0;
          animateCounters(prevState, intakeState, totalEl, avgEl, peakEl);

          setActiveRangeButton(intakeState.rangeKey);
          setIntakeError('');
          syncAddressRange(intakeState.rangeKey);

          // ChartTheme owns this target and serializes data refreshes with its
          // pending initial render, resize and theme updates. Do not race it with
          // a second direct ApexCharts update or resolve a stale global chart ID.
          ChartTheme.refresh(activityTarget, !reducedMotion);
        };

        rangeSwitch.addEventListener('click', function(event) {
          if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
          }
          var link = event.target && event.target.closest ? event.target.closest('[data-cdc-range]') : null;
          if (!link) return;

          event.preventDefault();
          var nextRange = link.getAttribute('data-cdc-range') || '7d';
          if (stopped || !activityTarget.isConnected || !['7d', '30d', 'sy'].includes(nextRange)) return;
          if (nextRange === intakeState.rangeKey && !activeController) return;
          var token = ++requestToken;

          if (activeController) {
            activeController.abort();
            activeController = null;
          }

          if (seriesCache[nextRange]) {
            intakePanel.classList.remove('is-loading');
            intakePanel.removeAttribute('aria-busy');
            applyIntakePayload(seriesCache[nextRange]);
            return;
          }

          var previousRange = intakeState.rangeKey;
          setActiveRangeButton(nextRange);
          setIntakeError('');
          intakePanel.classList.add('is-loading');
          intakePanel.setAttribute('aria-busy', 'true');

          var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
          activeController = controller;
          var sep = intakeUrl.indexOf('?') === -1 ? '?' : '&';
          var requestUrl = intakeUrl + sep + 'range=' + encodeURIComponent(nextRange);

          fetch(requestUrl, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            },
            signal: controller ? controller.signal : undefined
          }).then(function(response) {
            if (stopped || token !== requestToken || !activityTarget.isConnected || (controller && controller.signal.aborted)) return null;
            if (window.SynapseCsrf && window.SynapseCsrf.fromResponse(response)) {
              return null;
            }
            if (response.status === 401 || response.status === 403) {
              window.location.assign(link.href);
              return null;
            }
            if (!response.ok) {
              throw new Error('Request failed');
            }
            return response.json();
          }).then(function(data) {
            if (!data || stopped || token !== requestToken || !activityTarget.isConnected || (controller && controller.signal.aborted)) return;
            if (data.rangeKey !== nextRange || !Array.isArray(data.series) || !Array.isArray(data.days) || data.series.length !== data.days.length) {
              throw new Error('Invalid payload');
            }
            seriesCache[nextRange] = data;
            applyIntakePayload(data);
          }).catch(function(err) {
            if (stopped || token !== requestToken || !activityTarget.isConnected || (err && err.name === 'AbortError')) return;
            setActiveRangeButton(previousRange);
            setIntakeError('Unable to update timeframe right now. Please try again.');
          }).finally(function() {
            if (!stopped && token === requestToken && activeController === controller) {
              activeController = null;
              intakePanel.classList.remove('is-loading');
              intakePanel.removeAttribute('aria-busy');
            }
          });
        }, { signal: bindings.signal });
      }

      document.addEventListener('synapse:before-swap', function(event) {
        var swapRoot = event && event.detail && event.detail.root;
        if (!swapRoot || (swapRoot !== activityTarget && !swapRoot.contains(activityTarget))) return;
        stopped = true;
        ++requestToken;
        if (activeController) {
          activeController.abort();
          activeController = null;
        }
        if (counterRaf) {
          cancelAnimationFrame(counterRaf);
          counterRaf = 0;
        }
        bindings.abort();
        delete activityTarget.dataset.clinicDashboardBound;
      }, { signal: bindings.signal });

      ChartTheme.create(triageTarget, function(t) {
        var dark = ChartTheme.isDark();
        var sliceGap = dark ? '#151517' : '#ffffff';
        var headingColor = dark ? '#f4f4f5' : '#0f172a';
        var mutedColor = dark ? '#9ca3af' : '#64748b';
        var sliceColors = ChartTheme.themedSlices(t, ChartTheme.statusPalette(t).slice(0, 3));
        return ChartTheme.donut(t, {
          chart: { height: 168 },
          series: triageData,
          labels: ['Emergency', 'Urgent', 'Routine'],
          colors: ChartTheme.themedSlices(t, ChartTheme.statusPalette(t).slice(0, 3)),
          stroke: { width: 3, colors: [sliceGap] },
          legend: { show: false },
          states: {
            hover: { filter: { type: totalTriage === 0 ? 'none' : 'darken', value: 0.92 } },
            active: { filter: { type: 'none' } }
          },
          plotOptions: {
            pie: {
              customScale: 1.04,
              offsetY: 6,
              expandOnClick: false,
              donut: {
                size: '78%',
                labels: {
                  show: true,
                  name: {
                    show: true,
                    fontSize: '10px',
                    fontWeight: 700,
                    color: mutedColor,
                    offsetY: 19,
                    formatter: function(label) { return totalTriage === 0 ? 'ACTIVE' : String(label).toUpperCase(); }
                  },
                  value: {
                    show: true,
                    fontSize: '25px',
                    fontWeight: 700,
                    fontFamily: numericFont,
                    color: headingColor,
                    offsetY: -12,
                    formatter: function(val) { return totalTriage === 0 ? '0' : String(val); }
                  },
                  total: {
                    show: true,
                    showAlways: false,
                    label: 'ACTIVE',
                    fontSize: '10px',
                    fontWeight: 700,
                    color: mutedColor,
                    formatter: function() { return String(totalTriage); }
                  }
                }
              }
            }
          },
          tooltip: {
            enabled: totalTriage > 0,
            followCursor: false,
            fixed: {
              enabled: true,
              position: 'topLeft',
              offsetX: 0,
              offsetY: 0
            },
            custom: function(ctx) {
              if (totalTriage === 0) return '';
              var idx = ctx.seriesIndex;
              var label = ctx.w.globals.labels[idx] || '';
              var val = Number(ctx.series[idx] || 0);
              var pct = Math.round((val / totalTriage) * 100);
              var color = sliceColors[idx] || mutedColor;
              return '<div class="cdc-tip">' +
                '<div class="cdc-tip-head">Triage Acuity</div>' +
                '<div class="cdc-tip-row">' +
                  '<span class="cdc-tip-label"><span class="cdc-tip-dot" style="background:' + color + '"></span>' + escapeHtml(label) + '</span>' +
                  '<span class="cdc-tip-val">' + val + ' (' + pct + '%)</span>' +
                '</div>' +
              '</div>';
            }
          }
        });
      });

      ChartTheme.initialize();
    }

    pageLoadHandler = function(event) {
      initClinicDashboard(event && event.detail && event.detail.root);
    };
    document.addEventListener('synapse:page-load', pageLoadHandler);
    if (!document.body.classList.contains('is-navigating')) {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { initClinicDashboard(document); }, { once: true });
      } else {
        initClinicDashboard(document);
      }
    }
  })();
</script>
<?= $this->endSection() ?>

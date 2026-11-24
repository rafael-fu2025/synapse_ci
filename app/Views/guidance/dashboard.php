<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="cdc-actions">
  <a href="<?= site_url('/guidance/cases/create') ?>" class="btn btn-primary btn-sm" data-app-modal-open>
    <i class="bi bi-folder-plus" aria-hidden="true"></i> Open New Case
  </a>
  <a href="<?= site_url('/guidance/queue') ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-clock-history" aria-hidden="true"></i> Guidance Queue
  </a>
  <a href="<?= site_url('/guidance/intake') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-inbox" aria-hidden="true"></i> Intake &amp; Triage
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$curRange = ($rangeKey ?? '7d');
if (!in_array($curRange, ['7d', '30d', 'sy'], true)) {
    $curRange = '7d';
}
$schoolYear = (string) ($schoolYear ?? '');
$userRole = (string) ($user['role'] ?? 'counsellor');
$userPerms = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];
$isSuperadmin = $userRole === 'superadmin';

$activeCases = (int) ($activeCases ?? 0);
$closedCases = (int) ($closedCases ?? 0);
$completedSessions = (int) ($completedSessions ?? 0);
$inProgressSessions = (int) ($inProgressSessions ?? 0);
$queueToday = (int) ($queueToday ?? 0);
$queueWaiting = (int) ($queueWaiting ?? 0);
$queueCalled = (int) ($queueCalled ?? 0);
$pendingReferrals = (int) ($pendingReferrals ?? 0);
$pendingIntakes = (int) ($pendingIntakes ?? 0);
$pendingCosigns = (int) ($pendingCosigns ?? 0);
$appointmentsToday = (int) ($appointmentsToday ?? 0);
$pendingAppointmentsCount = (int) ($pendingAppointmentsCount ?? 0);

$recentCases = is_array($recentCases ?? null) ? $recentCases : [];
$activeQueuePreview = is_array($activeQueuePreview ?? null) ? $activeQueuePreview : [];
$todaySchedule = is_array($todaySchedule ?? null) ? $todaySchedule : [];

$catCounts = is_array($categoryCounts ?? null) ? $categoryCounts : [];
$catAcademic = (int) ($catCounts['academic'] ?? 0);
$catPersonal = (int) ($catCounts['personal'] ?? 0);
$catCareer = (int) ($catCounts['career'] ?? 0);
$catCrisis = (int) ($catCounts['crisis'] ?? 0);
$catBehavioral = (int) ($catCounts['behavioral'] ?? 0);
$catFamily = (int) ($catCounts['family'] ?? 0);
$catTotal = $catAcademic + $catPersonal + $catCareer + $catCrisis + $catBehavioral + $catFamily;

$catRows = [
    ['key' => 'academic',   'label' => 'Academic',   'count' => $catAcademic,   'dot' => 'cdc-dot--sky'],
    ['key' => 'personal',   'label' => 'Personal',   'count' => $catPersonal,   'dot' => 'cdc-dot--violet'],
    ['key' => 'career',     'label' => 'Career',     'count' => $catCareer,     'dot' => 'cdc-dot--emerald'],
    ['key' => 'crisis',     'label' => 'Crisis',     'count' => $catCrisis,     'dot' => 'cdc-dot--crimson'],
    ['key' => 'behavioral', 'label' => 'Behavioral', 'count' => $catBehavioral, 'dot' => 'cdc-dot--amber'],
    ['key' => 'family',     'label' => 'Family',     'count' => $catFamily,     'dot' => 'cdc-dot--slate'],
];

$seriesVals = array_map('intval', is_array($chartSeries ?? null) ? $chartSeries : []);
$seriesLabels = is_array($chartDays ?? null) ? array_values($chartDays) : [];
$periodTotal = array_sum($seriesVals);
$periodBuckets = max(1, count($seriesVals));
$periodAvg = $periodTotal / $periodBuckets;
$peakValue = $seriesVals !== [] ? max($seriesVals) : 0;
$peakIdx = $seriesVals !== [] ? array_search($peakValue, $seriesVals, true) : false;
$peakLabel = ($peakIdx !== false && isset($seriesLabels[$peakIdx])) ? (string) $seriesLabels[$peakIdx] : '—';
$rangeSubtitle = match ($curRange) {
    '30d' => '30-day session & engagement trajectory · Manila business days',
    'sy' => ($schoolYear !== '' ? 'SY ' . $schoolYear : 'School Year') . ' monthly guidance engagements',
    default => '7-day session & engagement trajectory · Manila business days',
};
$avgUnit = $curRange === 'sy' ? '/ month' : '/ day';

$hasSafetyOrIntakeWatch = $catCrisis > 0 || $pendingIntakes > 0;
$hasHandoffOrCosignWatch = $pendingReferrals > 0 || $pendingCosigns > 0;
$showWatchRow = $hasSafetyOrIntakeWatch || $hasHandoffOrCosignWatch;

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
?>
<div class="page-dashboard nd-dashboard cdc-dashboard guidance-ui"
     data-queue-dashboard="guidance"
     data-queue-counts-url="<?= site_url('/guidance/dashboard/queue-counts') ?>"
     data-queue-date="<?= esc(\App\Services\Scheduling\SchedulingClock::today(), 'attr') ?>">

  <!-- 1. Compact Shift Telemetry Strip -->
  <div class="cdc-shift-bar">
    <div class="cdc-shift-left">
      <span class="cdc-live-tag">Guidance Operational</span>
      <span class="cdc-shift-identity"><?= esc($user['name'] ?? 'Counselor') ?></span>
      <span class="cdc-shift-sep" aria-hidden="true">|</span>
      <span class="cdc-shift-meta"><?= esc(ucwords(str_replace('_', ' ', $userRole))) ?></span>
      <span class="cdc-shift-sep d-none d-sm-inline" aria-hidden="true">·</span>
      <span class="cdc-shift-meta cdc-mono"><?= date('D, M j, Y') ?><?= $schoolYear !== '' ? ' · SY ' . esc($schoolYear) : '' ?></span>
    </div>

    <div class="cdc-shift-right">
      <?php if ($catCrisis > 0): ?>
        <a href="<?= site_url('/guidance/cases?category=crisis') ?>" class="cdc-status-link cdc-status-link--crimson">
          <span class="cdc-mono"><?= $catCrisis ?></span> Crisis Caseload
        </a>
      <?php endif; ?>
      <?php if ($pendingIntakes > 0): ?>
        <a href="<?= site_url('/guidance/intake') ?>" class="cdc-status-link cdc-status-link--amber">
          <span class="cdc-mono"><?= $pendingIntakes ?></span> Pending Intakes
        </a>
      <?php endif; ?>
      <?php if ($pendingReferrals > 0): ?>
        <a href="<?= site_url('/guidance/referrals') ?>" class="cdc-status-link cdc-status-link--amber">
          <span class="cdc-mono"><?= $pendingReferrals ?></span> Clinic Referrals
        </a>
      <?php endif; ?>
      <?php if ($catCrisis === 0 && $pendingIntakes === 0 && $pendingReferrals === 0): ?>
        <span class="cdc-shift-meta">AES-256-GCM encrypted case vault active</span>
      <?php endif; ?>
    </div>
  </div>

  <p class="cdc-queue-feedback" data-queue-feedback role="status" hidden></p>

  <!-- 2. Unified 4-Column Asymmetric Live Telemetry Deck -->
  <section class="cdc-deck" aria-label="Live guidance shift telemetry">
    <!-- Hero Cell: Active Counseling Cases -->
    <article class="cdc-deck-cell cdc-deck-cell--hero">
      <div class="cdc-deck-head">
        <span class="cdc-deck-label">Active Counseling Cases</span>
        <?php if ($catCrisis > 0): ?>
          <span class="cdc-deck-badge cdc-deck-badge--amber cdc-mono"><?= $catCrisis ?> crisis</span>
        <?php else: ?>
          <span class="cdc-deck-badge">Caseload</span>
        <?php endif; ?>
      </div>
      <div class="cdc-deck-main">
        <strong class="cdc-deck-num"><?= number_format($activeCases) ?></strong>
        <span class="cdc-deck-unit">open cases</span>
      </div>
      <div class="cdc-deck-foot">
        <span class="cdc-deck-foot-info up">
          <i class="bi bi-check2-circle" aria-hidden="true"></i>
          <span><span class="cdc-mono"><?= number_format($closedCases) ?></span> closed cases</span>
        </span>
        <a href="<?= site_url('/guidance/cases') ?>" class="cdc-micro-link">Registry <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>
    </article>

    <!-- Cell 2: Completed Sessions -->
    <article class="cdc-deck-cell">
      <div class="cdc-deck-head">
        <span class="cdc-deck-label">Completed Sessions</span>
        <span class="cdc-deck-badge">AES-256-GCM</span>
      </div>
      <div class="cdc-deck-main">
        <strong class="cdc-deck-num"><?= number_format($completedSessions) ?></strong>
        <span class="cdc-deck-unit">sealed notes</span>
      </div>
      <div class="cdc-deck-foot">
        <?php if ($inProgressSessions > 0): ?>
          <span class="cdc-deck-foot-info warn">
            <i class="bi bi-pencil-square" aria-hidden="true"></i>
            <span><span class="cdc-mono"><?= $inProgressSessions ?></span> in progress</span>
          </span>
        <?php else: ?>
          <span class="cdc-deck-foot-info up">
            <i class="bi bi-shield-lock-fill" aria-hidden="true"></i>
            <span>Encrypted session ledger</span>
          </span>
        <?php endif; ?>
        <a href="<?= site_url('/guidance/episodes') ?>" class="cdc-micro-link">Episodes <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>
    </article>

    <!-- Cell 3: Waiting Queue Today -->
    <article class="cdc-deck-cell">
      <div class="cdc-deck-head">
        <span class="cdc-deck-label">Waiting Queue Today</span>
        <span class="cdc-deck-badge cdc-deck-badge--live">Live</span>
      </div>
      <div class="cdc-deck-main">
        <strong class="cdc-deck-num" data-queue-count="queueToday"><?= number_format($queueToday) ?></strong>
        <span class="cdc-deck-unit">in line</span>
      </div>
      <div class="cdc-deck-foot">
        <span class="cdc-deck-foot-info <?= ($queueToday > 0) ? 'up' : '' ?>" data-queue-trend="queueToday">
          <i class="bi bi-people" aria-hidden="true"></i>
          <span><span class="cdc-mono"><?= $queueWaiting ?></span> waiting, <span class="cdc-mono"><?= $queueCalled ?></span> called</span>
        </span>
        <a href="<?= site_url('/guidance/queue') ?>" class="cdc-micro-link">Board <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>
    </article>

    <!-- Cell 4: Incoming Referrals -->
    <article class="cdc-deck-cell">
      <div class="cdc-deck-head">
        <span class="cdc-deck-label">Incoming Referrals</span>
        <?php if ($pendingReferrals > 0): ?>
          <span class="cdc-deck-badge cdc-deck-badge--amber cdc-mono">Action needed</span>
        <?php else: ?>
          <span class="cdc-deck-badge">Clinic Handoff</span>
        <?php endif; ?>
      </div>
      <div class="cdc-deck-main">
        <strong class="cdc-deck-num"><?= number_format($pendingReferrals) ?></strong>
        <span class="cdc-deck-unit">awaiting review</span>
      </div>
      <div class="cdc-deck-foot">
        <span class="cdc-deck-foot-info <?= $pendingReferrals > 0 ? 'warn' : '' ?>">
          <i class="bi bi-hospital" aria-hidden="true"></i>
          <span>From University Clinic</span>
        </span>
        <a href="<?= site_url('/guidance/referrals') ?>" class="cdc-micro-link">Inbox <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>
    </article>
  </section>

  <!-- 3. Clinical & Safety Watch Rail (Only shown when actionable attention exists) -->
  <?php if ($showWatchRow): ?>
    <div class="cdc-watch-grid <?= ($hasSafetyOrIntakeWatch && $hasHandoffOrCosignWatch) ? '' : 'cdc-watch-grid--single' ?>">
      <?php if ($hasSafetyOrIntakeWatch): ?>
        <div class="cdc-watch <?= $catCrisis > 0 ? 'cdc-watch--crimson' : '' ?>">
          <div class="cdc-watch-copy">
            <div class="cdc-watch-title">
              <i class="bi <?= $catCrisis > 0 ? 'bi-shield-exclamation' : 'bi-inbox-fill' ?>" aria-hidden="true"></i>
              <span><?= $catCrisis > 0 ? 'Crisis Caseload &amp; Intake Attention' : 'Intake &amp; Safety Triage Queue' ?></span>
            </div>
            <div class="cdc-watch-desc">
              <?php if ($catCrisis > 0): ?>
                Active crisis cases: <strong class="cdc-mono"><?= $catCrisis ?></strong><?php if ($pendingIntakes > 0): ?> · <?php endif; ?>
              <?php endif; ?>
              <?php if ($pendingIntakes > 0): ?>
                Referrals awaiting intake triage: <strong class="cdc-mono"><?= $pendingIntakes ?></strong>
              <?php endif; ?>
            </div>
          </div>
          <a href="<?= site_url($pendingIntakes > 0 ? '/guidance/intake' : '/guidance/cases?category=crisis') ?>" class="btn <?= $catCrisis > 0 ? 'btn-outline-danger' : 'btn-outline-secondary' ?> btn-sm text-nowrap">
            <?= $pendingIntakes > 0 ? 'Review Intake' : 'Review Cases' ?>
          </a>
        </div>
      <?php endif; ?>

      <?php if ($hasHandoffOrCosignWatch): ?>
        <div class="cdc-watch">
          <div class="cdc-watch-copy">
            <div class="cdc-watch-title">
              <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
              <span>Inter-Unit Handoffs &amp; Clinical Co-Sign</span>
            </div>
            <div class="cdc-watch-desc">
              <?php if ($pendingReferrals > 0): ?>
                Clinic referrals: <strong class="cdc-mono"><?= $pendingReferrals ?></strong><?php if ($pendingCosigns > 0): ?> · <?php endif; ?>
              <?php endif; ?>
              <?php if ($pendingCosigns > 0): ?>
                Forms awaiting supervisor co-sign: <strong class="cdc-mono"><?= $pendingCosigns ?></strong>
              <?php endif; ?>
            </div>
          </div>
          <a href="<?= site_url($pendingReferrals > 0 ? '/guidance/referrals' : '/guidance/clinical/cosign-queue') ?>" class="btn btn-outline-secondary btn-sm text-nowrap">
            <?= $pendingReferrals > 0 ? 'View Referrals' : 'Co-Sign Queue' ?>
          </a>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- 4. Asymmetric Bento Row 2: Session Velocity Analytics (7) + Category Breakdown & Waiting Room (5) -->
  <section class="cdc-bento-row">
    <!-- Left: Counseling Sessions & Engagements Velocity -->
    <div class="card cdc-panel cdc-intake-panel"
         data-cdc-intake-panel
         data-intake-url="<?= site_url('/guidance/dashboard/session-series') ?>">
      <div class="cdc-panel-head">
        <div class="cdc-panel-title-wrap">
          <h5 class="cdc-panel-title">Counseling Sessions &amp; Engagements Velocity</h5>
          <span class="cdc-panel-sub" data-cdc-intake-subtitle><?= esc($rangeSubtitle) ?></span>
        </div>
        <div class="cdc-range-switch" role="group" aria-label="Chart timeframe" data-navigation="false">
          <a href="<?= site_url('/guidance?range=7d') ?>" data-cdc-range="7d" class="cdc-range-btn <?= $curRange === '7d' ? 'active' : '' ?>" <?= $curRange === '7d' ? 'aria-current="page"' : '' ?>>7 Days</a>
          <a href="<?= site_url('/guidance?range=30d') ?>" data-cdc-range="30d" class="cdc-range-btn <?= $curRange === '30d' ? 'active' : '' ?>" <?= $curRange === '30d' ? 'aria-current="page"' : '' ?>>30 Days</a>
          <a href="<?= site_url('/guidance?range=sy') ?>" data-cdc-range="sy" class="cdc-range-btn <?= $curRange === 'sy' ? 'active' : '' ?>" <?= $curRange === 'sy' ? 'aria-current="page"' : '' ?>>School Year</a>
        </div>
      </div>

      <div class="cdc-period-ribbon">
        <div class="cdc-period-metrics">
          <div class="cdc-period-metric">
            <span class="cdc-period-stat-label">Period Volume</span>
            <div class="cdc-period-stat-val">
              <strong data-cdc-intake-total><?= number_format($periodTotal) ?></strong>
              <span>sessions</span>
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
          <span class="cdc-legend-item"><span class="cdc-legend-line"></span>Sessions</span>
          <span class="cdc-legend-item"><span class="cdc-legend-dash"></span>Avg</span>
          <span class="cdc-legend-item" data-cdc-intake-peak-legend<?= $peakValue <= 0 ? ' hidden' : '' ?>><span class="cdc-legend-peak"></span>Peak</span>
        </div>
      </div>

      <p class="cdc-intake-error" data-cdc-intake-error role="alert" hidden></p>

      <div class="cdc-chart-stage">
        <div id="guidanceActivityChart" class="nd-chart-canvas"></div>
      </div>
    </div>

    <!-- Right: Case Focus Categories + Live Waiting Room -->
    <div class="card cdc-panel">
      <div class="cdc-panel-head">
        <div class="cdc-panel-title-wrap">
          <h5 class="cdc-panel-title">Case Focus Categories</h5>
          <span class="cdc-panel-sub">Caseload distribution &amp; waiting room preview</span>
        </div>
        <a href="<?= site_url('/guidance/intake') ?>" class="cdc-micro-link">Intake <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>

      <div class="cdc-triage-top">
        <div class="cdc-donut-stage<?= $catTotal === 0 ? ' cdc-donut-stage--empty' : '' ?>">
          <div id="guidanceCategoryChart" class="nd-donut-canvas cdc-triage-donut"></div>
        </div>

        <div class="cdc-category-grid">
          <?php foreach ($catRows as $row): ?>
            <?php $pct = $catTotal > 0 ? (int) round(($row['count'] / $catTotal) * 100) : 0; ?>
            <div class="cdc-acuity-row">
              <div class="cdc-acuity-row-top">
                <span class="cdc-dot <?= esc($row['dot']) ?>"><?= esc($row['label']) ?></span>
                <span class="cdc-acuity-nums">
                  <span class="cdc-acuity-pct"><?= $pct ?>%</span>
                  <span class="cdc-acuity-count"><?= $row['count'] ?></span>
                </span>
              </div>
              <div class="cdc-acuity-track" aria-hidden="true">
                <div class="cdc-acuity-fill cdc-acuity-fill--<?= esc($row['key']) ?>" style="width: <?= $pct ?>%;"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="cdc-subhead">
        <span>Up Next in Guidance Queue</span>
        <span class="cdc-mono"><?= count($activeQueuePreview) ?> shown</span>
      </div>

      <div class="cdc-queue-feed">
        <?php if (empty($activeQueuePreview)): ?>
          <div class="cdc-empty-block">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            No assigned clients waiting in the guidance queue right now.
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
            <a href="<?= site_url('/guidance/queue') ?>" class="cdc-queue-row <?= $qRowMod ?>">
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

  <!-- 5. Asymmetric Bento Row 3: Actionable Recent Counseling Cases (7) + Schedule & Operations Hub (5) -->
  <section class="cdc-bento-row">
    <!-- Left: Actionable Counseling Cases Worklist -->
    <div class="card cdc-panel">
      <div class="cdc-panel-head">
        <div class="cdc-panel-title-wrap">
          <h5 class="cdc-panel-title">Recent Counseling Cases</h5>
          <span class="cdc-panel-sub">Latest caseload records with AES-256-GCM session logs</span>
        </div>
        <a href="<?= site_url('/guidance/cases') ?>" class="cdc-micro-link">View all <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>

      <div class="cdc-panel-body cdc-panel-body--flush">
        <div class="table-responsive">
          <table class="table table-hover align-middle cdc-worklist" data-mobile-cards="false">
            <thead>
              <tr>
                <th>Client &amp; Case #</th>
                <th>Focus &amp; Counselor</th>
                <th>Status</th>
                <th class="text-end">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentCases)): ?>
                <tr>
                  <td colspan="4" class="cdc-empty-block">
                    <i class="bi bi-folder2" aria-hidden="true"></i>
                    No counseling cases recorded yet.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentCases as $rc): ?>
                  <?php
                    $rcStatus = (string) ($rc['status'] ?? 'open');
                    $isActive = in_array($rcStatus, ['open', 'active', 'monitoring'], true);
                    $dotClass = match ($rcStatus) {
                        'open', 'active' => 'cdc-dot--emerald',
                        'monitoring' => 'cdc-dot--amber',
                        'closed' => 'cdc-dot--slate',
                        default => 'cdc-dot--sky',
                    };
                    $initials = strtoupper(substr((string) ($rc['first_name'] ?? ''), 0, 1) . substr((string) ($rc['last_name'] ?? ''), 0, 1));
                    $catLabel = ucfirst((string) ($rc['category'] ?? 'General'));
                    $openedStr = $formatManilaTime($rc['opened_at'] ?? null);
                  ?>
                  <tr>
                    <td data-label="Client & Case #">
                      <div class="cdc-pt-cell">
                        <span class="cdc-initials <?= $isActive ? 'cdc-initials--active' : '' ?>" aria-hidden="true"><?= esc($initials) ?></span>
                        <div style="min-width: 0;">
                          <span class="cdc-pt-name"><?= esc($rc['last_name'] . ', ' . $rc['first_name']) ?></span>
                          <span class="cdc-pt-sub cdc-mono"><?= esc($rc['case_number']) ?> · <?= esc($rc['mis_id']) ?></span>
                        </div>
                      </div>
                    </td>
                    <td data-label="Focus & Counselor">
                      <span class="cdc-complaint-line"><?= esc($catLabel) ?></span>
                      <span class="cdc-clinician-sub">
                        <?= esc($rc['counsellor_name'] ?? 'Assigned Staff') ?><?= $openedStr !== '' ? ' · ' . esc($openedStr) : '' ?>
                      </span>
                    </td>
                    <td data-label="Status">
                      <span class="cdc-dot <?= $dotClass ?>"><?= esc(ucfirst($rcStatus)) ?></span>
                    </td>
                    <td data-label="Action" class="text-end">
                      <a href="<?= site_url('/guidance/cases/' . (int) $rc['id']) ?>"
                         class="btn btn-xs <?= $isActive ? 'btn-primary' : 'btn-outline-secondary' ?>">
                        <?= $isActive ? 'Open' : 'View' ?>
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

    <!-- Right: Today's Guidance Schedule, Operations Hub & Shortcuts Footer -->
    <div class="card cdc-panel">
      <div class="cdc-panel-head">
        <div class="cdc-panel-title-wrap">
          <h5 class="cdc-panel-title">Today&rsquo;s Schedule &amp; Operations Hub</h5>
          <span class="cdc-panel-sub cdc-mono"><?= date('M j, Y') ?> · <?= number_format($appointmentsToday) ?> booked</span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <a href="<?= site_url('/guidance/appointments/create') ?>" class="cdc-micro-link" data-app-modal-open>+ Book</a>
          <span class="cdc-shift-sep" aria-hidden="true">·</span>
          <a href="<?= site_url('/guidance/appointments') ?>" class="cdc-micro-link">All <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
      </div>

      <?php if ($pendingAppointmentsCount > 0): ?>
        <div class="cdc-pending-bar">
          <span><strong class="cdc-mono"><?= $pendingAppointmentsCount ?></strong> booking request<?= $pendingAppointmentsCount === 1 ? '' : 's' ?> awaiting confirmation</span>
          <a href="<?= site_url('/guidance/appointments') ?>" class="cdc-micro-link">Review <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
      <?php endif; ?>

      <div class="cdc-schedule-list">
        <?php if (empty($todaySchedule)): ?>
          <div class="cdc-empty-block">
            <i class="bi bi-calendar2-check" aria-hidden="true"></i>
            No guidance appointments scheduled for today.
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
                  : ucwords(str_replace('_', ' ', (string) ($appt['type'] ?? 'Counseling')));
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

      <div class="cdc-hub-section">
        <div class="cdc-subhead">
          <span>Guidance Operations Hub</span>
          <span class="cdc-mono">Quick access</span>
        </div>
        <div class="cdc-hub-grid guidance-ops-hub">
          <?php if ($isSuperadmin || in_array('guidance.cases.read', $userPerms, true)): ?>
            <a href="<?= esc(site_url('/guidance/clients')) ?>" class="cdc-hub-link">
              <span class="cdc-hub-link-main"><i class="bi bi-person-lines-fill" aria-hidden="true"></i><span>Clients &amp; Records</span></span>
              <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </a>
          <?php endif; ?>
          <a href="<?= site_url('/guidance/cases') ?>" class="cdc-hub-link">
            <span class="cdc-hub-link-main"><i class="bi bi-folder-check" aria-hidden="true"></i><span>Cases Registry</span></span>
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
          </a>
          <a href="<?= site_url('/guidance/queue') ?>" class="cdc-hub-link">
            <span class="cdc-hub-link-main"><i class="bi bi-clock-history" aria-hidden="true"></i><span>Walk-in Queue</span></span>
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
          </a>
          <a href="<?= site_url('/guidance/referrals') ?>" class="cdc-hub-link">
            <span class="cdc-hub-link-main"><i class="bi bi-arrow-left-right" aria-hidden="true"></i><span>Clinic Referrals</span></span>
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
          </a>
          <?php if ($isSuperadmin || in_array('guidance.clinical.cosign', $userPerms, true)): ?>
            <a href="<?= site_url('/guidance/clinical/cosign-queue') ?>" class="cdc-hub-link">
              <span class="cdc-hub-link-main"><i class="bi bi-pen" aria-hidden="true"></i><span>Co-Sign Queue</span></span>
              <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </a>
          <?php endif; ?>
          <?php if ($isSuperadmin || in_array('guidance.assessments.view', $userPerms, true)): ?>
            <a href="<?= site_url('/guidance/assessments/mi') ?>" class="cdc-hub-link">
              <span class="cdc-hub-link-main"><i class="bi bi-stars" aria-hidden="true"></i><span>MI Assessments</span></span>
              <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </a>
          <?php endif; ?>
          <?php if (in_array($userRole, ['superadmin', 'guidance_admin', 'guidance_supervisor'], true)
              && ($isSuperadmin || in_array('guidance.surveys.manage', $userPerms, true))): ?>
            <a href="<?= site_url('/guidance/surveys') ?>" class="cdc-hub-link">
              <span class="cdc-hub-link-main"><i class="bi bi-clipboard2-check" aria-hidden="true"></i><span>Campus Surveys</span></span>
              <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Understated 4-Item Quick Jump Footer -->
      <nav class="cdc-tools-footer" aria-label="Guidance workstation shortcuts">
        <a href="<?= site_url('/guidance/episodes') ?>" class="cdc-tool-link">
          <i class="bi bi-journal-medical" aria-hidden="true"></i>
          <span>Episodes</span>
        </a>
        <a href="<?= site_url('/guidance/call-slips') ?>" class="cdc-tool-link">
          <i class="bi bi-ticket-perforated" aria-hidden="true"></i>
          <span>Call Slips</span>
        </a>
        <a href="<?= site_url('/guidance/evaluations/dashboard') ?>" class="cdc-tool-link">
          <i class="bi bi-bar-chart-line" aria-hidden="true"></i>
          <span>Evaluations</span>
        </a>
        <a href="<?= site_url('/guidance/teams') ?>" class="cdc-tool-link">
          <i class="bi bi-people" aria-hidden="true"></i>
          <span>Teams</span>
        </a>
      </nav>
    </div>
  </section>

  <!-- 6. Periodic Non-PII CHED / Monthly / School-Year Summary -->
  <section class="card cdc-panel" aria-labelledby="guidance-periodic-summary-title">
    <div class="cdc-panel-head">
      <div class="cdc-panel-title-wrap">
        <h3 id="guidance-periodic-summary-title" class="cdc-panel-title">
          <i class="bi bi-calendar-range me-1" aria-hidden="true"></i>Periodic Guidance Service Summary (CHED / Monthly / School Year)
        </h3>
        <span class="cdc-panel-sub">Aggregate, non-PII counts across Manila calendar dates (up to 366 days per report)</span>
      </div>
      <a id="gd-summary-csv" href="<?= esc(site_url('/guidance/reports/summary?from=' . date('Y-m-01') . '&to=' . date('Y-m-d') . '&format=csv'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-download" aria-hidden="true"></i> Download CSV Summary
      </a>
    </div>

    <div class="cdc-panel-body">
      <div class="cdc-summary-toolbar">
        <div class="cdc-summary-field">
          <label for="gd-summary-from-display" class="form-label">Period Start</label>
          <input id="gd-summary-from-display" type="text" class="form-control form-control-sm" data-datepicker data-target="#gd-summary-from" value="<?= esc(date('d/m/Y', strtotime(date('Y-m-01'))), 'attr') ?>" autocomplete="off">
          <input id="gd-summary-from" type="hidden" value="<?= esc(date('Y-m-01'), 'attr') ?>">
        </div>
        <div class="cdc-summary-field">
          <label for="gd-summary-to-display" class="form-label">Period End</label>
          <input id="gd-summary-to-display" type="text" class="form-control form-control-sm" data-datepicker data-target="#gd-summary-to" value="<?= esc(date('d/m/Y'), 'attr') ?>" autocomplete="off">
          <input id="gd-summary-to" type="hidden" value="<?= esc(date('Y-m-d'), 'attr') ?>">
        </div>
        <div>
          <button type="button" id="gd-summary-load" class="btn btn-primary btn-sm">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Compute Period Counts
          </button>
        </div>
      </div>

      <div id="gd-summary-grid" class="row g-2">
        <div class="col-12">
          <div class="cdc-summary-placeholder">
            Click &ldquo;Compute Period Counts&rdquo; or download CSV for custom school-year / monthly reporting totals.
          </div>
        </div>
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
    var pageLoadHandler;
    function initGuidanceDashboard(root) {
      var scope = root && typeof root.querySelector === 'function' ? root : document;
      var activityTarget = scope.querySelector('#guidanceActivityChart');
      var categoryTarget = scope.querySelector('#guidanceCategoryChart');
      if (!activityTarget || typeof ApexCharts === 'undefined' || typeof ChartTheme === 'undefined') return;
      // Only this dashboard may own its chart state and shared range controls.
      if (activityTarget.dataset.guidanceDashboardBound === 'true') return;
      activityTarget.dataset.guidanceDashboardBound = 'true';
      document.removeEventListener('synapse:page-load', pageLoadHandler);
      var bindings = new AbortController();
      var stopped = false;
      var activeController = null;
      var counterRaf = 0;

      var intakePanel = activityTarget.closest('[data-cdc-intake-panel]');
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

      var sessionState = {
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
      seriesCache[sessionState.rangeKey] = sessionState;

      var catData = [
        <?= $catAcademic ?>,
        <?= $catPersonal ?>,
        <?= $catCareer ?>,
        <?= $catCrisis ?>,
        <?= $catBehavioral ?>,
        <?= $catFamily ?>
      ];
      var catLabels = ['Academic', 'Personal', 'Career', 'Crisis', 'Behavioral', 'Family'];
      var totalCat = catData.reduce(function(a, b) { return a + b; }, 0);
      if (totalCat === 0) catData = [1, 0, 0, 0, 0, 0];

      var reducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
      var activityChartId = 'guidanceActivityTrajectory';

      var buildActivityOptions = function(t) {
        var rawSeries = Array.isArray(sessionState.series) ? sessionState.series.map(function(n) { return Number(n || 0); }) : [];
        var activityCategories = Array.isArray(sessionState.days) ? sessionState.days.slice() : [];
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
            name: 'Sessions',
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
                  '<span class="cdc-tip-label"><span class="cdc-tip-dot" style="background:' + lineColor + '"></span>Sessions</span>' +
                  '<span class="cdc-tip-val">' + val + '</span>' +
                '</div>' +
                (avgVal > 0 ? '<div class="' + deltaClass + '">' + sign + diff.toFixed(1) + ' vs period avg</div>' : '') +
              '</div>';
            }
          }
        });
      };

      if (activityTarget) {
        ChartTheme.create(activityTarget, buildActivityOptions);
      }

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
        var setRangeError = function(msg) {
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
            // Preserve current address if URL/history update is unavailable.
          }
        };
        var applySessionPayload = function(payload) {
          var prevState = sessionState;
          sessionState = {
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

          if (subEl) subEl.textContent = sessionState.subtitle;
          if (avgLabelEl) avgLabelEl.textContent = sessionState.avgLabel;
          if (avgUnitEl) avgUnitEl.textContent = sessionState.avgUnit;
          if (peakLabelEl) peakLabelEl.textContent = 'on ' + sessionState.peakLabel;
          if (peakLegendEl) peakLegendEl.hidden = sessionState.peakValue <= 0;
          animateCounters(prevState, sessionState, totalEl, avgEl, peakEl);

          setActiveRangeButton(sessionState.rangeKey);
          setRangeError('');
          syncAddressRange(sessionState.rangeKey);

          var chartInst = (typeof ApexCharts.getChartByID === 'function')
            ? ApexCharts.getChartByID(activityChartId)
            : null;
          if (chartInst && typeof chartInst.updateOptions === 'function') {
            var nextOpts = buildActivityOptions(ChartTheme.tokens());
            var targetWidth = activityTarget ? Math.floor(activityTarget.clientWidth) : 0;
            if (targetWidth > 32) {
              nextOpts.chart.width = targetWidth;
            }
            nextOpts.chart.redrawOnParentResize = false;
            nextOpts.chart.redrawOnWindowResize = false;
            chartInst.updateOptions(nextOpts, true, !reducedMotion, false);
          } else {
            ChartTheme.refresh();
          }
        };

        rangeSwitch.addEventListener('click', function(event) {
          if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
          }
          var link = event.target && event.target.closest ? event.target.closest('[data-cdc-range]') : null;
          if (!link) return;

          event.preventDefault();
          var nextRange = link.getAttribute('data-cdc-range') || '7d';
          if (stopped || !activityTarget.isConnected) return;
          if (nextRange === sessionState.rangeKey && !activeController) return;

          if (activeController) {
            activeController.abort();
            activeController = null;
          }

          if (seriesCache[nextRange]) {
            intakePanel.classList.remove('is-loading');
            intakePanel.removeAttribute('aria-busy');
            applySessionPayload(seriesCache[nextRange]);
            return;
          }

          var previousRange = sessionState.rangeKey;
          setActiveRangeButton(nextRange);
          setRangeError('');
          intakePanel.classList.add('is-loading');
          intakePanel.setAttribute('aria-busy', 'true');

          var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
          activeController = controller;
          var sep = intakeUrl.indexOf('?') === -1 ? '?' : '&';
          var requestUrl = intakeUrl + sep + 'range=' + encodeURIComponent(nextRange);

          fetch(requestUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            },
            signal: controller ? controller.signal : undefined
          }).then(function(response) {
            if (stopped || !activityTarget.isConnected || (controller && controller.signal.aborted)) return null;
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
            if (!data || stopped || !activityTarget.isConnected || (controller && controller.signal.aborted)) return;
            if (!Array.isArray(data.series) || !Array.isArray(data.days)) {
              throw new Error('Invalid payload');
            }
            seriesCache[nextRange] = data;
            applySessionPayload(data);
          }).catch(function(err) {
            if (stopped || !activityTarget.isConnected || (err && err.name === 'AbortError')) return;
            setActiveRangeButton(previousRange);
            setRangeError('Unable to update timeframe right now. Please try again.');
          }).finally(function() {
            if (!stopped && activeController === controller) {
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
        if (activeController) {
          activeController.abort();
          activeController = null;
        }
        if (counterRaf) {
          cancelAnimationFrame(counterRaf);
          counterRaf = 0;
        }
        bindings.abort();
        delete activityTarget.dataset.guidanceDashboardBound;
      }, { signal: bindings.signal });

      if (categoryTarget) {
        ChartTheme.create(categoryTarget, function(t) {
          var dark = ChartTheme.isDark();
          var sliceGap = dark ? '#151517' : '#ffffff';
          var headingColor = dark ? '#f4f4f5' : '#0f172a';
          var mutedColor = dark ? '#9ca3af' : '#64748b';
          var sliceColors = dark
            ? ['#38bdf8', '#a78bfa', '#34d399', '#f87171', '#fbbf24', '#94a3b8']
            : ['#0369a1', '#6d28d9', '#146c43', '#b91c1c', '#d97706', '#475569'];

          return ChartTheme.donut(t, {
            chart: { height: 168 },
            series: catData,
            labels: catLabels,
            colors: sliceColors,
            stroke: { width: 3, colors: [sliceGap] },
            legend: { show: false },
            states: {
              hover: { filter: { type: totalCat === 0 ? 'none' : 'darken', value: 0.92 } },
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
                      formatter: function(label) { return totalCat === 0 ? 'CASES' : String(label).toUpperCase(); }
                    },
                    value: {
                      show: true,
                      fontSize: '25px',
                      fontWeight: 700,
                      fontFamily: numericFont,
                      color: headingColor,
                      offsetY: -12,
                      formatter: function(val) { return totalCat === 0 ? '0' : String(val); }
                    },
                    total: {
                      show: true,
                      showAlways: false,
                      label: 'CASES',
                      fontSize: '10px',
                      fontWeight: 700,
                      color: mutedColor,
                      formatter: function() { return String(totalCat); }
                    }
                  }
                }
              }
            },
            tooltip: {
              enabled: totalCat > 0,
              followCursor: false,
              fixed: {
                enabled: true,
                position: 'topLeft',
                offsetX: 0,
                offsetY: 0
              },
              custom: function(ctx) {
                if (totalCat === 0) return '';
                var idx = ctx.seriesIndex;
                var label = ctx.w.globals.labels[idx] || '';
                var val = Number(ctx.series[idx] || 0);
                var pct = Math.round((val / totalCat) * 100);
                var color = sliceColors[idx] || mutedColor;
                return '<div class="cdc-tip">' +
                  '<div class="cdc-tip-head">Case Focus</div>' +
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

      ChartTheme.initialize();
    }

    function initPeriodicSummary(root) {
      var scope = root && typeof root.querySelector === 'function' ? root : document;
      var loadBtn = scope.querySelector('#gd-summary-load');
      var fromEl = scope.querySelector('#gd-summary-from');
      var toEl = scope.querySelector('#gd-summary-to');
      var csvEl = scope.querySelector('#gd-summary-csv');
      var gridEl = scope.querySelector('#gd-summary-grid');
      if (!loadBtn || !fromEl || !toEl || !gridEl || loadBtn.dataset.bound === '1') return;
      loadBtn.dataset.bound = '1';

      function updateCsvLink() {
        if (!csvEl) return;
        csvEl.href = <?= json_encode(site_url('/guidance/reports/summary')) ?>
          + '?from=' + encodeURIComponent(fromEl.value)
          + '&to=' + encodeURIComponent(toEl.value)
          + '&format=csv';
      }

      function renderSummaryCards(summary) {
        var rows = [
          ['Cases Opened', summary.cases_opened],
          ['Cases Closed', summary.cases_closed],
          ['PSP Sessions (Associates)', summary.psp_sessions_completed],
          ['Counseling Sessions', summary.counseling_sessions_completed],
          ['Intakes Submitted', summary.intakes_submitted],
          ['Call Slips Issued', summary.call_slips_issued],
          ['MI Assessments Completed', summary.mi_assessments_completed],
          ['Surveys Submitted', summary.surveys_submitted]
        ];
        var frag = document.createDocumentFragment();
        rows.forEach(function(item) {
          var col = document.createElement('div');
          col.className = 'col-6 col-md-3';
          var card = document.createElement('div');
          card.className = 'cdc-summary-kpi';
          var label = document.createElement('div');
          label.className = 'cdc-summary-kpi-label';
          label.textContent = item[0];
          var val = document.createElement('div');
          val.className = 'cdc-summary-kpi-val';
          val.textContent = String(Number(item[1] || 0));
          card.appendChild(label);
          card.appendChild(val);
          col.appendChild(card);
          frag.appendChild(col);
        });
        gridEl.replaceChildren(frag);
      }

      loadBtn.addEventListener('click', function() {
        updateCsvLink();
        var loading = document.createElement('div');
        loading.className = 'col-12 cdc-summary-placeholder';
        loading.textContent = 'Computing aggregate counts...';
        gridEl.replaceChildren(loading);

        fetch(<?= json_encode(site_url('/guidance/reports/summary')) ?> + '?from=' + encodeURIComponent(fromEl.value) + '&to=' + encodeURIComponent(toEl.value), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (!data || !data.success || !data.summary) {
            var err = document.createElement('div');
            err.className = 'col-12 text-danger small';
            err.textContent = (data && data.message) ? data.message : 'Unable to load summary.';
            gridEl.replaceChildren(err);
            return;
          }
          renderSummaryCards(data.summary);
        })
        .catch(function() {
          var err = document.createElement('div');
          err.className = 'col-12 text-danger small';
          err.textContent = 'Unable to fetch periodic summary right now.';
          gridEl.replaceChildren(err);
        });
      });
    }

    function boot(root) {
      initGuidanceDashboard(root);
      initPeriodicSummary(root);
    }

    pageLoadHandler = function(event) {
      boot(event && event.detail && event.detail.root);
    };
    document.addEventListener('synapse:page-load', pageLoadHandler);
    if (!document.body.classList.contains('is-navigating')) {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { boot(document); }, { once: true });
      } else {
        boot(document);
      }
    }
  })();
</script>
<?= $this->endSection() ?>

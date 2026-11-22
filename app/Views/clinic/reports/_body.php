<?php
$period = $summary['period'];
$totals = $summary['totals'];
$totalEncounters = (int) ($totals['encounters'] ?? 0);
$studentCount = (int) ($totals['student'] ?? 0);
$employeeCount = (int) ($totals['employee'] ?? 0);
$kindSum = max(1, $studentCount + $employeeCount);
$studentPct = $totalEncounters > 0 ? round(($studentCount / $kindSum) * 100, 1) : 0.0;
$employeePct = $totalEncounters > 0 ? round(100 - $studentPct, 1) : 0.0;

$completedCount = (int) ($totals['completed'] ?? 0);
$referredCount = (int) ($totals['referred'] ?? 0);
$inProgressCount = (int) ($totals['in_progress'] ?? 0);
$resolvedCount = $completedCount + $referredCount;
$resolvedPct = $totalEncounters > 0 ? round(($resolvedCount / $totalEncounters) * 100, 1) : 0.0;

$dispensedCount = (int) ($totals['dispensed'] ?? 0);
$avgDispensed = $totalEncounters > 0 ? number_format($dispensedCount / $totalEncounters, 2) : '0.00';

$isolationCount = (int) ($totals['isolation'] ?? 0);
$clearedCount = (int) ($summary['clearances']['cleared'] ?? 0);
$pendingClearanceCount = (int) ($summary['clearances']['pending'] ?? 0);
$holdClearanceCount = (int) ($summary['clearances']['hold_followup'] ?? 0);
$clearanceTotal = $clearedCount + $pendingClearanceCount + $holdClearanceCount;
$clearedPct = $clearanceTotal > 0 ? round(($clearedCount / $clearanceTotal) * 100, 1) : 0.0;
$pendingPct = $clearanceTotal > 0 ? round(($pendingClearanceCount / $clearanceTotal) * 100, 1) : 0.0;
$holdPct = $clearanceTotal > 0 ? round(($holdClearanceCount / $clearanceTotal) * 100, 1) : 0.0;

$monthlySeries = $summary['monthly_series'] ?? [];
$monthCount = count($monthlySeries);
$seriesTotal = array_sum(array_map(static fn (array $m): int => (int) ($m['count'] ?? 0), $monthlySeries));
$seriesAvg = $monthCount > 0 ? round($seriesTotal / $monthCount, 1) : 0.0;
$peakMonth = null;
$peakCount = 0;
foreach ($monthlySeries as $m) {
    $c = (int) ($m['count'] ?? 0);
    if ($c > $peakCount) {
        $peakCount = $c;
        $peakMonth = (string) ($m['label'] ?? '');
    }
}

$complaints = $summary['complaints'] ?? [];
$complaintsTotal = max(1, array_sum(array_map('intval', $complaints)));
$complaintsMax = $complaints !== [] ? max(1, max(array_map('intval', $complaints))) : 1;

$dispositions = $summary['dispositions'] ?? [];
$dispositionsTotal = max(1, array_sum(array_map('intval', $dispositions)));
$dispositionsMax = $dispositions !== [] ? max(1, max(array_map('intval', $dispositions))) : 1;

$topMedicines = $summary['top_medicines'] ?? [];
$topMedMax = $topMedicines !== [] ? max(1, max(array_map(static fn (array $m): int => (int) ($m['total_qty'] ?? 0), $topMedicines))) : 1;

$isolationSummary = $summary['isolation_summary'] ?? [];
$isolationMax = $isolationSummary !== [] ? max(1, max(array_map(static fn (array $i): int => (int) ($i['count'] ?? 0), $isolationSummary))) : 1;
?>
<div class="cr-body-content"
     data-cr-content
     data-preset="<?= esc($period['preset'], 'attr') ?>"
     data-from="<?= esc($period['from'], 'attr') ?>"
     data-to="<?= esc($period['to'], 'attr') ?>">

  <!-- Coverage Strip -->
  <div class="cr-coverage-bar">
    <div class="cr-coverage-item">
      <span class="cr-coverage-label">Horizon</span>
      <strong><?= esc($period['label']) ?></strong>
    </div>
    <span class="cr-coverage-sep" aria-hidden="true">·</span>
    <div class="cr-coverage-item">
      <span class="cr-coverage-label">Coverage</span>
      <span class="cr-mono"><?= esc($period['from']) ?> → <?= esc($period['to']) ?></span>
      <span class="cr-coverage-tz">(Asia/Manila)</span>
    </div>
    <span class="cr-coverage-sep" aria-hidden="true">·</span>
    <div class="cr-coverage-item">
      <span class="cr-coverage-label">Current SY</span>
      <strong class="cr-mono"><?= esc($summary['school_year']) ?></strong>
    </div>
  </div>

  <!-- Communicable Cluster Advisories (14-day window) -->
  <?php if (!empty($summary['isolation_alerts'])): ?>
  <section class="cr-advisory" aria-label="Communicable cluster advisories">
    <div class="cr-advisory-head">
      <span class="cr-advisory-title"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Communicable Cluster Advisories</span>
      <span class="cr-advisory-window">14-day active window</span>
    </div>
    <div class="cr-advisory-list">
      <?php foreach ($summary['isolation_alerts'] as $a): ?>
      <div class="cr-advisory-item">
        <div class="cr-advisory-copy">
          <strong><?= esc($a['condition_label']) ?></strong>
          <span><?= esc($a['cohort_summary'] ?? 'Campus') ?></span>
        </div>
        <span class="cr-advisory-count"><?= (int) ($a['case_count'] ?? 0) ?> <?= ((int) ($a['case_count'] ?? 0)) === 1 ? 'case' : 'cases' ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Top KPI Strip (4 Architectural Solid Cards) -->
  <section class="cr-kpi-grid" aria-label="Report summary metrics">
    <article class="cr-kpi-card">
      <div class="cr-kpi-head">
        <span class="cr-kpi-label">Total Encounters</span>
        <i class="bi bi-clipboard2-pulse cr-kpi-icon" aria-hidden="true"></i>
      </div>
      <div class="cr-kpi-value"><?= number_format($totalEncounters) ?></div>
      <?php if ($totalEncounters > 0): ?>
      <div class="cr-split-bar" aria-hidden="true">
        <span class="cr-split-seg cr-split-seg--brand" style="width: <?= $studentPct ?>%"></span>
        <?php if ($employeeCount > 0): ?><span class="cr-split-seg cr-split-seg--neutral" style="width: <?= $employeePct ?>%"></span><?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="cr-kpi-meta">
        <span>Students <strong><?= number_format($studentCount) ?></strong></span>
        <span aria-hidden="true">·</span>
        <span>Employees <strong><?= number_format($employeeCount) ?></strong></span>
      </div>
    </article>

    <article class="cr-kpi-card">
      <div class="cr-kpi-head">
        <span class="cr-kpi-label">Completed &amp; Referred</span>
        <i class="bi bi-check2-circle cr-kpi-icon" aria-hidden="true"></i>
      </div>
      <div class="cr-kpi-value-row">
        <span class="cr-kpi-value"><?= number_format($resolvedCount) ?></span>
        <?php if ($totalEncounters > 0): ?><span class="cr-kpi-rate"><?= number_format($resolvedPct, 1) ?>%</span><?php endif; ?>
      </div>
      <div class="cr-kpi-meta">
        <span>Completed <strong><?= number_format($completedCount) ?></strong></span>
        <span aria-hidden="true">·</span>
        <span>Referred <strong><?= number_format($referredCount) ?></strong></span>
        <?php if ($inProgressCount > 0): ?>
        <span aria-hidden="true">·</span>
        <span>Open <strong><?= number_format($inProgressCount) ?></strong></span>
        <?php endif; ?>
      </div>
    </article>

    <article class="cr-kpi-card">
      <div class="cr-kpi-head">
        <span class="cr-kpi-label">Medicines Dispensed</span>
        <i class="bi bi-capsule cr-kpi-icon" aria-hidden="true"></i>
      </div>
      <div class="cr-kpi-value"><?= number_format($dispensedCount) ?></div>
      <div class="cr-kpi-meta">
        <span>Net units (FEFO)</span>
        <span aria-hidden="true">·</span>
        <span>Avg <strong><?= esc($avgDispensed) ?></strong> / visit</span>
      </div>
    </article>

    <article class="cr-kpi-card">
      <div class="cr-kpi-head">
        <span class="cr-kpi-label">Communicable &amp; Clearances</span>
        <i class="bi bi-shield-check cr-kpi-icon" aria-hidden="true"></i>
      </div>
      <div class="cr-kpi-value-row">
        <span class="cr-kpi-value"><?= number_format($isolationCount) ?></span>
        <span class="cr-kpi-unit"><?= $isolationCount === 1 ? 'isolation' : 'isolations' ?></span>
      </div>
      <div class="cr-kpi-meta">
        <span>SY Cleared <strong><?= number_format($clearedCount) ?></strong></span>
        <span aria-hidden="true">·</span>
        <span>Hold <strong><?= number_format($holdClearanceCount) ?></strong></span>
      </div>
    </article>
  </section>

  <!-- Monthly Encounter Volume (Custom Interactive SVG Telemetry + Tabular Ledger) -->
  <section class="cr-panel" aria-labelledby="cr-monthly-heading">
    <div class="cr-panel-head">
      <div>
        <h2 class="cr-panel-title" id="cr-monthly-heading">Monthly Encounter Volume</h2>
        <p class="cr-panel-sub mb-0">Recorded clinic encounters by calendar month across the selected reporting horizon.</p>
      </div>
      <?php if ($monthlySeries !== []): ?>
      <div class="cr-panel-metrics">
        <?php if ($peakMonth !== null): ?>
        <div class="cr-metric-inline">
          <span class="cr-metric-k">Peak</span>
          <strong class="cr-metric-v"><?= esc($peakMonth) ?> · <?= number_format($peakCount) ?></strong>
        </div>
        <?php endif; ?>
        <div class="cr-metric-inline">
          <span class="cr-metric-k">Monthly avg</span>
          <strong class="cr-metric-v"><?= number_format($seriesAvg, 1) ?></strong>
        </div>
        <div class="cr-metric-inline">
          <span class="cr-metric-k">Period total</span>
          <strong class="cr-metric-v"><?= number_format($seriesTotal) ?></strong>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="cr-panel-body">
      <?php if ($monthlySeries === []): ?>
        <div class="cr-empty">
          <i class="bi bi-bar-chart" aria-hidden="true"></i>
          <span>No clinical encounters recorded within this reporting range.</span>
        </div>
      <?php else: ?>
        <?php
        $chartPayload = json_encode([
            'series' => array_values(array_map(static fn (array $m): array => [
                'ym'    => (string) ($m['ym'] ?? ''),
                'label' => (string) ($m['label'] ?? ''),
                'count' => (int) ($m['count'] ?? 0),
            ], $monthlySeries)),
            'total'  => $seriesTotal,
            'avg'    => $seriesAvg,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>
        <div class="cr-chart-stage" data-cr-monthly-viz data-series="<?= esc($chartPayload, 'attr') ?>">
          <div class="cr-chart-svg-wrap" data-cr-svg-host></div>
          <div class="cr-chart-tooltip" data-cr-tooltip hidden></div>
        </div>

        <div class="cr-month-grid" role="list" aria-label="Monthly encounter counts">
          <?php foreach ($monthlySeries as $idx => $m):
              $cnt = (int) ($m['count'] ?? 0);
              $share = $seriesTotal > 0 ? round(($cnt / $seriesTotal) * 100, 1) : 0.0;
          ?>
          <div class="cr-month-cell" role="listitem" data-cr-month-col="<?= $idx ?>">
            <span class="cr-month-label"><?= esc($m['label']) ?></span>
            <strong class="cr-month-val"><?= number_format($cnt) ?></strong>
            <span class="cr-month-share"><?= number_format($share, 1) ?>%</span>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- 2x2 Analytical Breakdown Grid -->
  <div class="cr-breakdown-grid">
    <!-- 1. Chief Complaints Breakdown -->
    <section class="cr-panel" aria-labelledby="cr-complaints-heading">
      <div class="cr-panel-head">
        <div>
          <h2 class="cr-panel-title" id="cr-complaints-heading">Chief Complaints Breakdown</h2>
          <p class="cr-panel-sub mb-0">Recorded presentation categories ordered by frequency.</p>
        </div>
        <span class="cr-panel-count"><?= count($complaints) ?> <?= count($complaints) === 1 ? 'category' : 'categories' ?></span>
      </div>
      <div class="cr-table-wrap">
        <table class="table table-sm align-middle mb-0 cr-data-table" data-mobile-cards="false" data-no-mobile-cards="true">
          <thead>
            <tr>
              <th scope="col">Category / Complaint</th>
              <th scope="col" class="cr-col-bar">Share</th>
              <th scope="col" class="text-end">Cases</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($complaints === []): ?>
              <tr><td colspan="3" class="cr-empty-td">No recorded complaints.</td></tr>
            <?php else: ?>
              <?php foreach ($complaints as $label => $cnt):
                  $cntInt = (int) $cnt;
                  $share = round(($cntInt / $complaintsTotal) * 100, 1);
                  $barWidth = round(($cntInt / $complaintsMax) * 100, 1);
              ?>
              <tr>
                <td class="cr-label-cell"><?= esc($label) ?></td>
                <td class="cr-col-bar">
                  <div class="cr-bar-cell">
                    <span class="cr-bar-track" aria-hidden="true"><span class="cr-bar-fill cr-bar-fill--brand" style="width: <?= $barWidth ?>%"></span></span>
                    <span class="cr-bar-pct"><?= number_format($share, 1) ?>%</span>
                  </div>
                </td>
                <td class="text-end cr-num-cell">
                  <span><?= number_format($cntInt) ?></span>
                  <span class="cr-mobile-pct"><?= number_format($share, 1) ?>%</span>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- 2. Clinical Dispositions -->
    <section class="cr-panel" aria-labelledby="cr-dispositions-heading">
      <div class="cr-panel-head">
        <div>
          <h2 class="cr-panel-title" id="cr-dispositions-heading">Clinical Dispositions</h2>
          <p class="cr-panel-sub mb-0">Recorded clinical outcomes for encounters in this horizon.</p>
        </div>
        <span class="cr-panel-count"><?= number_format($totalEncounters) ?> total</span>
      </div>
      <div class="cr-table-wrap">
        <table class="table table-sm align-middle mb-0 cr-data-table" data-mobile-cards="false" data-no-mobile-cards="true">
          <thead>
            <tr>
              <th scope="col">Outcome / Disposition</th>
              <th scope="col" class="cr-col-bar">Share</th>
              <th scope="col" class="text-end">Encounters</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($dispositions === []): ?>
              <tr><td colspan="3" class="cr-empty-td">No dispositions logged.</td></tr>
            <?php else: ?>
              <?php foreach ($dispositions as $label => $cnt):
                  $cntInt = (int) $cnt;
                  $share = round(($cntInt / $dispositionsTotal) * 100, 1);
                  $barWidth = round(($cntInt / $dispositionsMax) * 100, 1);
              ?>
              <tr>
                <td class="cr-label-cell"><?= esc($label) ?></td>
                <td class="cr-col-bar">
                  <div class="cr-bar-cell">
                    <span class="cr-bar-track" aria-hidden="true"><span class="cr-bar-fill cr-bar-fill--ink" style="width: <?= $barWidth ?>%"></span></span>
                    <span class="cr-bar-pct"><?= number_format($share, 1) ?>%</span>
                  </div>
                </td>
                <td class="text-end cr-num-cell">
                  <span><?= number_format($cntInt) ?></span>
                  <span class="cr-mobile-pct"><?= number_format($share, 1) ?>%</span>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- 3. Top Dispensed Medicines (Net FEFO) -->
    <section class="cr-panel" aria-labelledby="cr-medicines-heading">
      <div class="cr-panel-head">
        <div>
          <h2 class="cr-panel-title" id="cr-medicines-heading">Top Dispensed Medicines (Net FEFO)</h2>
          <p class="cr-panel-sub mb-0">Highest net-dispensed items excluding reversed allocations.</p>
        </div>
        <span class="cr-panel-count"><?= number_format($dispensedCount) ?> units</span>
      </div>
      <div class="cr-table-wrap">
        <table class="table table-sm align-middle mb-0 cr-data-table" data-mobile-cards="false" data-no-mobile-cards="true">
          <thead>
            <tr>
              <th scope="col">Medicine</th>
              <th scope="col" class="text-end">Encounters</th>
              <th scope="col" class="text-end">Units</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($topMedicines === []): ?>
              <tr><td colspan="3" class="cr-empty-td">No medicines dispensed in this range.</td></tr>
            <?php else: ?>
              <?php foreach ($topMedicines as $idx => $med):
                  $qty = (int) ($med['total_qty'] ?? 0);
                  $barWidth = round(($qty / $topMedMax) * 100, 1);
              ?>
              <tr>
                <td class="cr-label-cell">
                  <div class="cr-med-row">
                    <span class="cr-rank"><?= str_pad((string) ($idx + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <div class="cr-med-copy">
                      <span class="cr-med-name"><?= esc($med['name']) ?></span>
                      <span class="cr-bar-track cr-bar-track--thin" aria-hidden="true"><span class="cr-bar-fill cr-bar-fill--brand" style="width: <?= $barWidth ?>%"></span></span>
                    </div>
                  </div>
                </td>
                <td class="text-end cr-num-muted"><?= number_format((int) ($med['encounter_count'] ?? 0)) ?></td>
                <td class="text-end cr-num-cell text-nowrap"><?= number_format($qty) ?> <span class="cr-unit-text"><?= esc($med['unit']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- 4. Communicable Diseases & SY Clearances -->
    <section class="cr-panel" aria-labelledby="cr-communicable-heading">
      <div class="cr-panel-head">
        <div>
          <h2 class="cr-panel-title" id="cr-communicable-heading">Communicable Diseases &amp; SY Clearances</h2>
          <p class="cr-panel-sub mb-0">Tracked isolation cases in range and SY <?= esc($summary['school_year']) ?> enrollment clearances.</p>
        </div>
      </div>
      <div class="cr-panel-body cr-communicable-body">
        <div class="cr-subblock">
          <div class="cr-subblock-head">
            <h3 class="cr-subblock-title">Communicable Cases in Range</h3>
            <span class="cr-panel-count"><?= number_format($isolationCount) ?> logged</span>
          </div>
          <?php if ($isolationSummary === []): ?>
            <p class="cr-empty-inline mb-0">No communicable isolation cases logged within range.</p>
          <?php else: ?>
            <ul class="cr-iso-list">
              <?php foreach ($isolationSummary as $iso):
                  $isoCnt = (int) ($iso['count'] ?? 0);
                  $barWidth = round(($isoCnt / $isolationMax) * 100, 1);
              ?>
              <li class="cr-iso-item">
                <div class="cr-iso-copy">
                  <span><?= esc($iso['label']) ?></span>
                  <span class="cr-bar-track cr-bar-track--thin" aria-hidden="true"><span class="cr-bar-fill cr-bar-fill--rose" style="width: <?= $barWidth ?>%"></span></span>
                </div>
                <strong class="cr-num-cell"><?= number_format($isoCnt) ?></strong>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="cr-subblock cr-subblock--clearances">
          <div class="cr-subblock-head">
            <h3 class="cr-subblock-title">School-Year <?= esc($summary['school_year']) ?> Enrollment Health Clearances</h3>
            <span class="cr-panel-count"><?= number_format($clearanceTotal) ?> records</span>
          </div>

          <?php if ($clearanceTotal > 0): ?>
          <div class="cr-clearance-bar" role="img" aria-label="SY <?= esc($summary['school_year']) ?> clearances: <?= number_format($clearedCount) ?> cleared, <?= number_format($pendingClearanceCount) ?> pending labs, <?= number_format($holdClearanceCount) ?> on hold">
            <?php if ($clearedCount > 0): ?><span class="cr-cl-seg cr-cl-seg--cleared" style="width: <?= $clearedPct ?>%"></span><?php endif; ?>
            <?php if ($pendingClearanceCount > 0): ?><span class="cr-cl-seg cr-cl-seg--pending" style="width: <?= $pendingPct ?>%"></span><?php endif; ?>
            <?php if ($holdClearanceCount > 0): ?><span class="cr-cl-seg cr-cl-seg--hold" style="width: <?= $holdPct ?>%"></span><?php endif; ?>
          </div>
          <?php endif; ?>

          <div class="cr-clearance-grid">
            <div class="cr-clearance-stat">
              <span class="cr-status-line" data-tone="cleared"><span class="cr-dot" aria-hidden="true"></span>Cleared</span>
              <strong class="cr-clearance-num"><?= number_format($clearedCount) ?></strong>
              <span class="cr-clearance-pct"><?= number_format($clearedPct, 1) ?>%</span>
            </div>
            <div class="cr-clearance-stat">
              <span class="cr-status-line" data-tone="pending"><span class="cr-dot" aria-hidden="true"></span>Pending Labs</span>
              <strong class="cr-clearance-num"><?= number_format($pendingClearanceCount) ?></strong>
              <span class="cr-clearance-pct"><?= number_format($pendingPct, 1) ?>%</span>
            </div>
            <div class="cr-clearance-stat">
              <span class="cr-status-line" data-tone="hold"><span class="cr-dot" aria-hidden="true"></span>Hold / Follow-up</span>
              <strong class="cr-clearance-num"><?= number_format($holdClearanceCount) ?></strong>
              <span class="cr-clearance-pct"><?= number_format($holdPct, 1) ?>%</span>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</div>

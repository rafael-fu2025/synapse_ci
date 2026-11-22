<?php
$tz = new \DateTimeZone('Asia/Manila');
$todayDt = new \DateTimeImmutable($today, $tz);
$monthStartDt = new \DateTimeImmutable($monthStart, $tz);
$daysInMonth = (int) $monthStartDt->format('t');
$leadingEmpty = ((int) $monthStartDt->format('N')) - 1; // Mon = 0 .. Sun = 6

$conditionTones = [
    'chickenpox' => 'crimson',
    'measles'    => 'brand',
    'dengue'     => 'amber',
    'covid19'    => 'indigo',
    'influenza'  => 'teal',
    'ptb'        => 'slate',
    'mumps'      => 'violet',
    'other'      => 'zinc',
];

$shortConditionLabels = [
    'chickenpox' => 'Chickenpox',
    'measles'    => 'Measles',
    'dengue'     => 'Dengue',
    'covid19'    => 'COVID-19',
    'influenza'  => 'Influenza',
    'ptb'        => 'PTB',
    'mumps'      => 'Mumps',
    'other'      => 'Other',
];

$totalLoggedAll = 0;
foreach ($conditionCounts as $cc) {
    $totalLoggedAll += (int) ($cc['total'] ?? 0);
}
$activeSharePct = $totalLoggedAll > 0 ? round(((int) $activeCount / $totalLoggedAll) * 100, 1) : 0.0;

// Pick top 3 conditions by active count DESC, then total DESC, with clinical defaults as tie-breakers
$defaultOrder = ['chickenpox', 'measles', 'dengue', 'influenza', 'covid19', 'ptb', 'mumps', 'other'];
$rankedCodes = array_keys($conditions);
usort($rankedCodes, static function (string $a, string $b) use ($conditionCounts, $defaultOrder): int {
    $ca = $conditionCounts[$a] ?? ['active' => 0, 'total' => 0];
    $cb = $conditionCounts[$b] ?? ['active' => 0, 'total' => 0];
    if ($cb['active'] !== $ca['active']) {
        return $cb['active'] <=> $ca['active'];
    }
    if ($cb['total'] !== $ca['total']) {
        return $cb['total'] <=> $ca['total'];
    }
    return array_search($a, $defaultOrder, true) <=> array_search($b, $defaultOrder, true);
});
$highlightCodes = array_slice($rankedCodes, 0, 3);

// Map overlapping cases per day in month + build serialized day payload for the Day Inspector
$casesByDay = [];
$monthConditionSet = [];
$monthActiveCount = 0;
$monthClearedCount = 0;
$monthHospitalizedCount = 0;
foreach ($cases as $c) {
    $st = (string) ($c['clearance_status'] ?? 'active_isolation');
    if ($st === 'cleared') {
        $monthClearedCount++;
    } elseif ($st === 'hospitalized') {
        $monthHospitalizedCount++;
    } else {
        $monthActiveCount++;
    }
    $code = (string) ($c['condition_code'] ?? 'other');
    $monthConditionSet[$code] = true;
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $iso = $month . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
        if ($c['isolation_start_date'] <= $iso && $c['isolation_end_date'] >= $iso) {
            $casesByDay[$iso][] = $c;
        }
    }
}

// Serialize lightweight client-side lookup for Day Inspector drawer (zero <script> tags in fragment)
$inspectorCases = [];
foreach ($cases as $c) {
    $code = (string) ($c['condition_code'] ?? 'other');
    $cohort = !empty($c['program'])
        ? trim((string) $c['program'] . (!empty($c['year_level']) ? ' · ' . $c['year_level'] : ''))
        : (!empty($c['emp_dept']) ? (string) $c['emp_dept'] : ucfirst((string) ($c['kind'] ?? 'Campus')));
    $inspectorCases[(int) $c['id']] = [
        'id'            => (int) $c['id'],
        'person_id'     => (int) $c['person_id'],
        'patient_url'   => site_url('/clinic/patients/' . (int) $c['person_id']),
        'clear_url'     => site_url('/clinic/isolation/' . (int) $c['id'] . '/clear'),
        'name'          => (string) ($c['last_name'] . ', ' . $c['first_name']),
        'mis_id'        => (string) ($c['mis_id'] ?? ''),
        'cohort'        => $cohort,
        'condition'     => (string) ($conditions[$code] ?? $code),
        'condition_sub' => (string) ($c['condition_other'] ?? ''),
        'tone'          => $conditionTones[$code] ?? 'zinc',
        'start'         => (string) $c['isolation_start_date'],
        'end'           => (string) $c['isolation_end_date'],
        'days'          => (int) $c['isolation_days'],
        'status'        => (string) ($c['clearance_status'] ?? 'active_isolation'),
        'notes'         => (string) ($c['notes'] ?? ''),
    ];
}
$dayIndexMap = [];
foreach ($casesByDay as $iso => $list) {
    $dayIndexMap[$iso] = array_map(static fn (array $item): int => (int) $item['id'], $list);
}
$calendarPayload = json_encode([
    'today'    => $today,
    'canWrite' => !empty($canWrite),
    'cases'    => $inspectorCases,
    'byDay'    => $dayIndexMap,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// Pick default active day for mobile tap-to-inspect strip
$defaultSelectedDay = '';
if (isset($casesByDay[$today])) {
    $defaultSelectedDay = $today;
} elseif ($casesByDay !== []) {
    $keys = array_keys($casesByDay);
    $defaultSelectedDay = (string) end($keys);
} elseif (str_starts_with($today, $month . '-')) {
    $defaultSelectedDay = $today;
}
?>
<div class="iso-body-content"
     data-iso-content
     data-month="<?= esc($month, 'attr') ?>"
     data-month-label="<?= esc($monthLabel, 'attr') ?>"
     data-prev-month="<?= esc($prevMonth, 'attr') ?>"
     data-next-month="<?= esc($nextMonth, 'attr') ?>"
     data-condition="<?= esc($filters['condition'], 'attr') ?>"
     data-status="<?= esc($filters['status'], 'attr') ?>">

  <!-- 1. Anonymized Threshold Advisories (Solid Left-Accent Card, Zero Pastel Banner) -->
  <?php if (!empty($alerts)): ?>
  <section class="iso-advisory" aria-label="Anonymized communicable threshold alerts">
    <div class="iso-advisory-head">
      <div class="iso-advisory-heading">
        <span class="iso-advisory-title">
          <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
          Anonymized Communicable Threshold Advisories
        </span>
        <span class="iso-advisory-sub">Threshold &ge;2 cases per condition within 14 days · Aggregates exclude patient identity.</span>
      </div>
      <span class="iso-advisory-window">14-day window</span>
    </div>
    <div class="iso-advisory-grid">
      <?php foreach ($alerts as $alert):
          $aCode = (string) ($alert['condition_code'] ?? '');
          $aCount = (int) ($alert['case_count'] ?? 0);
          $isHigh = ($alert['severity'] ?? '') === 'danger';
          $isFiltered = $filters['condition'] === $aCode;
      ?>
      <button type="button"
              class="iso-advisory-card<?= $isFiltered ? ' is-selected' : '' ?>"
              data-iso-quick-condition="<?= esc($aCode, 'attr') ?>"
              title="Filter isolation calendar by <?= esc($alert['condition_label'], 'attr') ?>">
        <div class="iso-advisory-copy">
          <span class="iso-advisory-name">
            <span class="iso-cond-dot" data-tone="<?= esc($conditionTones[$aCode] ?? 'crimson', 'attr') ?>" aria-hidden="true"></span>
            <strong><?= esc($alert['condition_label']) ?></strong>
          </span>
          <span class="iso-advisory-cohort"><?= esc($alert['cohort_summary'] ?? 'Campus-wide') ?></span>
        </div>
        <span class="iso-advisory-count<?= $isHigh ? ' is-danger' : ' is-warn' ?>"><?= $aCount ?> <?= $aCount === 1 ? 'case' : 'cases' ?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- 2. Four Architectural Solid KPI Cards -->
  <section class="iso-kpi-grid" aria-label="Isolation summary metrics">
    <article class="iso-kpi-card iso-kpi-card--lead">
      <div class="iso-kpi-head">
        <span class="iso-kpi-label">Active Isolation</span>
        <i class="bi bi-shield-exclamation iso-kpi-icon" aria-hidden="true"></i>
      </div>
      <div class="iso-kpi-val-row">
        <span class="iso-kpi-value"><?= number_format((int) ($activeCount ?? 0)) ?></span>
        <span class="iso-kpi-status" data-tone="<?= ((int) ($activeCount ?? 0)) > 0 ? 'active' : 'cleared' ?>">
          <span class="iso-dot" aria-hidden="true"></span><?= ((int) ($activeCount ?? 0)) > 0 ? 'Monitoring' : 'Clear' ?>
        </span>
      </div>
      <div class="iso-kpi-bar" aria-hidden="true">
        <span class="iso-kpi-bar-fill" data-tone="crimson" style="width: <?= $activeSharePct ?>%"></span>
      </div>
      <div class="iso-kpi-meta">
        <span>In <?= esc($monthLabel) ?>: <strong><?= $monthActiveCount ?></strong> active</span>
        <span aria-hidden="true">·</span>
        <span><strong><?= count($cases) ?></strong> shown</span>
      </div>
    </article>

    <?php foreach ($highlightCodes as $code):
        $counts = $conditionCounts[$code] ?? ['active' => 0, 'total' => 0];
        $activeVal = (int) $counts['active'];
        $totalVal = (int) $counts['total'];
        $barPct = $totalVal > 0 ? round(($activeVal / $totalVal) * 100, 1) : 0.0;
        $tone = $conditionTones[$code] ?? 'zinc';
        $isSelected = $filters['condition'] === $code;
    ?>
    <button type="button"
            class="iso-kpi-card iso-kpi-card--filter<?= $isSelected ? ' is-selected' : '' ?>"
            data-iso-quick-condition="<?= esc($code, 'attr') ?>"
            title="Filter by <?= esc($conditions[$code] ?? $code, 'attr') ?>">
      <div class="iso-kpi-head">
        <span class="iso-kpi-label">
          <span class="iso-cond-dot" data-tone="<?= esc($tone, 'attr') ?>" aria-hidden="true"></span>
          <?= esc($conditions[$code] ?? $code) ?>
        </span>
        <span class="iso-kpi-total"><?= number_format($totalVal) ?> total</span>
      </div>
      <div class="iso-kpi-val-row">
        <span class="iso-kpi-value"><?= number_format($activeVal) ?></span>
        <span class="iso-kpi-unit">active</span>
      </div>
      <div class="iso-kpi-bar" aria-hidden="true">
        <span class="iso-kpi-bar-fill" data-tone="<?= esc($tone, 'attr') ?>" style="width: <?= $barPct ?>%"></span>
      </div>
      <div class="iso-kpi-meta">
        <span>Default protocol: <strong><?= (int) ($defaultDays[$code] ?? 5) ?>d</strong></span>
        <span aria-hidden="true">·</span>
        <span><?= $isSelected ? 'Filtered' : 'Click to filter' ?></span>
      </div>
    </button>
    <?php endforeach; ?>
  </section>

  <!-- 3. Interactive Monthly Isolation Calendar + Selected Day Inspector -->
  <section class="iso-panel" aria-labelledby="iso-calendar-heading"
           data-iso-calendar
           data-default-day="<?= esc($defaultSelectedDay, 'attr') ?>"
           data-calendar-payload="<?= esc($calendarPayload, 'attr') ?>">
    <div class="iso-panel-head">
      <div>
        <h2 class="iso-panel-title" id="iso-calendar-heading">Monthly Isolation Calendar · <?= esc($monthLabel) ?></h2>
        <p class="iso-panel-sub mb-0">Select any date to inspect all overlapping isolation cases and clearance windows.</p>
      </div>
      <div class="iso-calendar-meta">
        <?php if ($monthConditionSet !== []): ?>
        <div class="iso-legend" aria-label="Condition color key">
          <?php foreach (array_keys($monthConditionSet) as $lCode): ?>
          <span class="iso-legend-item">
            <span class="iso-cond-dot" data-tone="<?= esc($conditionTones[$lCode] ?? 'zinc', 'attr') ?>" aria-hidden="true"></span>
            <?= esc($shortConditionLabels[$lCode] ?? $lCode) ?>
          </span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <span class="iso-panel-count"><?= count($cases) ?> <?= count($cases) === 1 ? 'case' : 'cases' ?> overlapping</span>
      </div>
    </div>

    <div class="iso-cal-wrap">
      <div class="iso-cal-weekdays" aria-hidden="true">
        <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
      </div>

      <div class="iso-cal-grid" role="grid" aria-label="Monthly isolation calendar for <?= esc($monthLabel) ?>">
        <?php
        $totalCells = $leadingEmpty + $daysInMonth;
        $rows = (int) ceil($totalCells / 7);
        $totalGridCells = $rows * 7;
        for ($idx = 0; $idx < $totalGridCells; $idx++):
            $dayNum = $idx - $leadingEmpty + 1;
            if ($dayNum < 1 || $dayNum > $daysInMonth): ?>
              <div class="iso-cal-cell iso-cal-cell--pad" aria-hidden="true"></div>
            <?php else:
                $isoDate = $month . '-' . str_pad((string) $dayNum, 2, '0', STR_PAD_LEFT);
                $dayCases = $casesByDay[$isoDate] ?? [];
                $dayCount = count($dayCases);
                $isToday = ($isoDate === $today);
                $dayDateObj = new \DateTimeImmutable($isoDate, $tz);
                $dayFullLabel = $dayDateObj->format('l, M j, Y');
            ?>
              <button type="button"
                      class="iso-cal-cell<?= $isToday ? ' is-today' : '' ?><?= $dayCount > 0 ? ' has-cases' : '' ?>"
                      data-iso-day="<?= esc($isoDate, 'attr') ?>"
                      data-iso-day-label="<?= esc($dayFullLabel, 'attr') ?>"
                      aria-label="<?= esc($dayFullLabel . ': ' . $dayCount . ' ' . ($dayCount === 1 ? 'case' : 'cases'), 'attr') ?>"
                      aria-pressed="false">
                <span class="iso-cal-day-top">
                  <span class="iso-cal-day-num<?= $isToday ? ' is-today-num' : '' ?>"><?= $dayNum ?></span>
                  <?php if ($dayCount > 0): ?>
                    <span class="iso-cal-day-count"><?= $dayCount ?></span>
                  <?php endif; ?>
                </span>

                <?php if ($dayCount > 0): ?>
                  <!-- Desktop crisp solid left-accent event strips -->
                  <span class="iso-cal-events">
                    <?php foreach (array_slice($dayCases, 0, 2) as $dc):
                        $dcCode = (string) ($dc['condition_code'] ?? 'other');
                        $dcTone = $conditionTones[$dcCode] ?? 'zinc';
                        $dcShort = $shortConditionLabels[$dcCode] ?? ucfirst($dcCode);
                    ?>
                      <span class="iso-cal-event" data-tone="<?= esc($dcTone, 'attr') ?>">
                        <span class="iso-cal-event-name"><?= esc($dc['last_name']) ?></span>
                        <span class="iso-cal-event-sep" aria-hidden="true">·</span>
                        <span class="iso-cal-event-cond"><?= esc($dcShort) ?></span>
                      </span>
                    <?php endforeach; ?>
                    <?php if ($dayCount > 2): ?>
                      <span class="iso-cal-more">+<?= $dayCount - 2 ?> more</span>
                    <?php endif; ?>
                  </span>

                  <!-- Mobile compact solid condition dots -->
                  <span class="iso-cal-dots" aria-hidden="true">
                    <?php foreach (array_slice($dayCases, 0, 4) as $dc):
                        $dcCode = (string) ($dc['condition_code'] ?? 'other');
                    ?>
                      <span class="iso-cond-dot" data-tone="<?= esc($conditionTones[$dcCode] ?? 'zinc', 'attr') ?>"></span>
                    <?php endforeach; ?>
                  </span>
                <?php endif; ?>
              </button>
            <?php endif;
        endfor; ?>
      </div>
    </div>

    <!-- Interactive Selected Day Inspector Drawer -->
    <div class="iso-day-inspector" data-iso-day-inspector hidden>
      <div class="iso-day-inspector-head">
        <div class="iso-day-inspector-title-wrap">
          <strong class="iso-day-inspector-date" data-iso-inspector-date></strong>
          <span class="iso-day-inspector-count" data-iso-inspector-count></span>
        </div>
        <button type="button" class="iso-day-inspector-close" data-iso-inspector-close aria-label="Close day details">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </div>
      <div class="iso-day-inspector-list" data-iso-inspector-list></div>
    </div>
  </section>

  <!-- 4. Tracked Isolation Cases Roster -->
  <section class="iso-panel" aria-labelledby="iso-roster-heading">
    <div class="iso-panel-head">
      <div>
        <h2 class="iso-panel-title" id="iso-roster-heading">Tracked Isolation Cases</h2>
        <p class="iso-panel-sub mb-0">
          Showing <?= count($cases) ?> <?= count($cases) === 1 ? 'case' : 'cases' ?> overlapping <?= esc($monthLabel) ?>
          <?php if ($monthActiveCount > 0 || $monthHospitalizedCount > 0 || $monthClearedCount > 0): ?>
            · <?= $monthActiveCount ?> active · <?= $monthHospitalizedCount ?> hospitalized · <?= $monthClearedCount ?> cleared
          <?php endif; ?>
        </p>
      </div>
      <span class="iso-panel-count"><?= count($cases) ?> <?= count($cases) === 1 ? 'record' : 'records' ?></span>
    </div>

    <div class="iso-table-wrap">
      <table class="table table-sm align-middle mb-0 iso-table" data-mobile-cards="false" data-no-mobile-cards="true">
        <thead>
          <tr>
            <th scope="col">Patient</th>
            <th scope="col">Condition</th>
            <th scope="col">Isolation Window</th>
            <th scope="col">Status</th>
            <th scope="col">Notes &amp; Attribution</th>
            <?php if (!empty($canWrite)): ?><th scope="col" class="text-end">Action</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($cases)): ?>
            <tr class="iso-empty-row">
              <td colspan="<?= !empty($canWrite) ? 6 : 5 ?>" class="iso-empty-td">
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                <span>No communicable isolation cases match the current month and filters.</span>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($cases as $c):
                $code = (string) ($c['condition_code'] ?? 'other');
                $tone = $conditionTones[$code] ?? 'zinc';
                $condLabel = (string) ($conditions[$code] ?? $code);
                $statusVal = (string) ($c['clearance_status'] ?? 'active_isolation');
                $statusLabel = match ($statusVal) {
                    'cleared'      => 'Cleared',
                    'hospitalized' => 'Hospitalized',
                    default        => 'Active Isolation',
                };
                $statusTone = match ($statusVal) {
                    'cleared'      => 'cleared',
                    'hospitalized' => 'hospitalized',
                    default        => 'active',
                };

                $days = max(1, (int) ($c['isolation_days'] ?? 1));
                $startDt = new \DateTimeImmutable((string) $c['isolation_start_date'], $tz);
                $endDt = new \DateTimeImmutable((string) $c['isolation_end_date'], $tz);
                if ($statusVal === 'cleared') {
                    $progPct = 100;
                    $progTone = 'cleared';
                    $progText = 'Completed · ' . $days . 'd window';
                } elseif ($todayDt < $startDt) {
                    $progPct = 0;
                    $progTone = 'neutral';
                    $progText = 'Scheduled · ' . $days . 'd';
                } elseif ($todayDt > $endDt) {
                    $progPct = 100;
                    $progTone = $statusVal === 'hospitalized' ? 'amber' : 'crimson';
                    $progText = $statusVal === 'hospitalized' ? ($days . 'd window · Hospitalized') : 'Window elapsed · awaiting clearance';
                } else {
                    $dayNum = ((int) $startDt->diff($todayDt)->days) + 1;
                    $rem = max(0, $days - $dayNum);
                    $progPct = min(100, round(($dayNum / $days) * 100, 1));
                    $progTone = $statusVal === 'hospitalized' ? 'amber' : 'brand';
                    $progText = 'Day ' . $dayNum . ' of ' . $days . ($rem === 0 ? ' · Final day' : ' · ' . $rem . 'd left');
                }
                $cohortLabel = !empty($c['program'])
                    ? (string) $c['program']
                    : (!empty($c['emp_dept']) ? (string) $c['emp_dept'] : '');
            ?>
            <tr>
              <td class="iso-cell-patient">
                <a href="<?= esc(site_url('/clinic/patients/' . (int) $c['person_id']), 'attr') ?>" class="iso-patient-link">
                  <?= esc($c['last_name'] . ', ' . $c['first_name']) ?>
                </a>
                <div class="iso-patient-sub">
                  <span class="iso-mono"><?= esc($c['mis_id']) ?></span>
                  <?php if ($cohortLabel !== ''): ?>
                    <span aria-hidden="true">·</span>
                    <span><?= esc($cohortLabel) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="iso-cell-condition">
                <span class="iso-cond-line">
                  <span class="iso-cond-dot" data-tone="<?= esc($tone, 'attr') ?>" aria-hidden="true"></span>
                  <span class="iso-cond-name"><?= esc($condLabel) ?></span>
                </span>
                <?php if (!empty($c['condition_other'])): ?>
                  <div class="iso-cond-other"><?= esc($c['condition_other']) ?></div>
                <?php endif; ?>
              </td>
              <td class="iso-cell-window">
                <div class="iso-window-dates iso-mono"><?= esc($c['isolation_start_date']) ?> → <?= esc($c['isolation_end_date']) ?></div>
                <div class="iso-window-track" aria-hidden="true">
                  <span class="iso-window-fill" data-tone="<?= esc($progTone, 'attr') ?>" style="width: <?= $progPct ?>%"></span>
                </div>
                <div class="iso-window-meta"><?= esc($progText) ?></div>
              </td>
              <td class="iso-cell-status">
                <span class="iso-status-text" data-tone="<?= esc($statusTone, 'attr') ?>">
                  <span class="iso-dot" aria-hidden="true"></span><?= esc($statusLabel) ?>
                </span>
              </td>
              <td class="iso-cell-notes">
                <div class="iso-notes-body"><?= esc($c['notes'] ?? '—') ?></div>
                <div class="iso-notes-meta">
                  Logged by <?= esc($c['reported_by_name'] ?? 'Clinic staff') ?>
                  <?php if (!empty($c['cleared_by_name'])): ?> · Updated by <?= esc($c['cleared_by_name']) ?><?php endif; ?>
                </div>
              </td>
              <?php if (!empty($canWrite)): ?>
                <td class="text-end iso-cell-action">
                  <?php if ($statusVal === 'active_isolation'): ?>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm iso-clear-btn"
                            data-iso-clear-trigger
                            data-clear-url="<?= esc(site_url('/clinic/isolation/' . (int) $c['id'] . '/clear'), 'attr') ?>"
                            data-patient-name="<?= esc($c['last_name'] . ', ' . $c['first_name'], 'attr') ?>"
                            data-condition-label="<?= esc($condLabel, 'attr') ?>"
                            data-window-label="<?= esc($c['isolation_start_date'] . ' → ' . $c['isolation_end_date'] . ' (' . $days . 'd)', 'attr') ?>">
                      <i class="bi bi-check2-circle" aria-hidden="true"></i> Update / Clear
                    </button>
                  <?php else: ?>
                    <span class="iso-closed-label">Closed</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

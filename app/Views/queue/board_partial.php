<?php
/**
 * @var string $unit 'clinic' or 'guidance'
 * @var array{
 *     waiting: array,
 *     called: array,
 *     in_consultation: array,
 *     done: array,
 *     skipped: array,
 *     counts: array
 * } $summary
 */
$counts = $summary['counts'];
$metrics = [
    ['waiting', 'Waiting', 'bi-hourglass-split'],
    ['called', 'Called', 'bi-megaphone'],
    ['in_consultation', 'In Session', 'bi-heart-pulse'],
    ['done', 'Completed', 'bi-check2-circle'],
    ['skipped', 'Skipped', 'bi-skip-forward'],
    ['total', 'Total Patients', 'bi-people'],
];
$queueActor = session()->get('user');
$canOpenGuidanceSession = is_array($queueActor) && (($queueActor['role'] ?? '') === 'superadmin'
    || in_array('guidance.cases.create', $queueActor['permissions'] ?? [], true));
?>

<!-- Live KPI Counters -->
<div class="row g-2 g-md-3 queue-metrics" aria-label="Daily queue totals" data-queue-waiting-count="<?= count($summary['waiting']) ?>">
  <?php foreach ($metrics as [$key, $label, $icon]): ?>
    <div class="col-6 col-md-4 col-xl-2">
      <div class="card queue-metric mb-0 h-100">
        <div class="card-body">
          <div class="queue-metric-head">
            <span class="queue-metric-label"><?= esc($label) ?></span>
            <span class="queue-metric-icon" aria-hidden="true"><i class="bi <?= $icon ?>"></i></span>
          </div>
          <div class="queue-metric-value"><?= (int) $counts[$key] ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<template data-queue-no-show-history data-count="<?= count($summary['no_show'] ?? []) ?>" data-date="<?= esc($queueDate ?? '', 'attr') ?>">
  <?= view('queue/_no_show_list', ['noShows' => $summary['no_show'] ?? []]) ?>
</template>

<!-- Kanban Columns -->
<div class="row g-3 align-items-start queue-columns">

  <!-- 1. WAITING COLUMN -->
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card queue-column">
      <div class="card-header queue-column-header">
        <h6 class="queue-column-title">Waiting Line</h6>
        <span class="queue-column-count" aria-label="Waiting patients"><?= count($summary['waiting']) ?></span>
      </div>
      <div class="card-body queue-column-body">
        <?php if (empty($summary['waiting'])): ?>
          <div class="queue-column-empty">
            <i class="bi bi-inbox fs-2 d-block mb-1" aria-hidden="true"></i>
            No patients waiting
          </div>
        <?php else: ?>
          <?php foreach ($summary['waiting'] as $item): ?>
            <?php
              $priorityCardClass = match ($item['priority'] ?? 'routine') {
                  'emergency' => 'queue-ticket-card--emergency',
                  'urgent' => 'queue-ticket-card--urgent',
                  default => '',
              };
            ?>
            <div class="card queue-ticket-card <?= $priorityCardClass ?>">
              <div class="q-ticket-head">
                <span class="q-ticket-code"><?= esc($item['ticket_code']) ?></span>
                <?php if (($item['priority'] ?? '') === 'emergency'): ?>
                  <span class="q-priority-dot q-priority-dot--emergency">Emergency</span>
                <?php elseif (($item['priority'] ?? '') === 'urgent'): ?>
                  <span class="q-priority-dot q-priority-dot--urgent">Urgent</span>
                <?php endif; ?>
              </div>

              <div class="q-ticket-patient text-truncate">
                <?= esc($item['last_name'] . ', ' . $item['first_name']) ?>
              </div>
              <div class="q-ticket-meta">
                <span class="q-mono">ID: <?= esc($item['mis_id']) ?></span>
                <?php if (!empty($item['program'])): ?>
                  · <?= esc($item['program']) ?>-<?= esc($item['year_level']) ?>
                <?php elseif (!empty($item['employee_dept'])): ?>
                  · <?= esc($item['employee_dept']) ?>
                <?php endif; ?>
              </div>

              <div class="queue-card-actions">
                <button type="button"
                        class="btn btn-primary btn-sm w-100"
                        hx-post="<?= site_url($unit . '/queue/call/' . $item['id']) ?>"
                        hx-target="#queue-board-container"
                        hx-swap="innerHTML">
                  <i class="bi bi-megaphone" aria-hidden="true"></i> Call Patient
                </button>
              </div>
              <?= view('queue/no_show_action', ['item' => $item, 'unit' => $unit]) ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 2. CALLED COLUMN -->
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card queue-column">
      <div class="card-header queue-column-header">
        <h6 class="queue-column-title">Called &amp; Arriving</h6>
        <span class="queue-column-count" aria-label="Called patients"><?= count($summary['called']) ?></span>
      </div>
      <div class="card-body queue-column-body">
        <?php if (empty($summary['called'])): ?>
          <div class="queue-column-empty">
            <i class="bi bi-bell-slash fs-2 d-block mb-1" aria-hidden="true"></i>
            No active calls
          </div>
        <?php else: ?>
          <?php foreach ($summary['called'] as $item): ?>
            <div class="card queue-ticket-card queue-ticket-card--called">
              <div class="q-ticket-head">
                <span class="q-ticket-code q-ticket-code--called"><?= esc($item['ticket_code']) ?></span>
                <span class="q-ticket-time">Called <?= date('h:i A', strtotime($item['called_at'] ?? 'now')) ?></span>
              </div>

              <div class="q-ticket-patient text-truncate">
                <?= esc($item['last_name'] . ', ' . $item['first_name']) ?>
              </div>
              <div class="q-ticket-meta q-mono">
                ID: <?= esc($item['mis_id']) ?>
              </div>

              <div class="queue-card-actions">
                <?php if ($unit === 'guidance'): ?>
                  <?php if ($canOpenGuidanceSession): ?>
                    <a href="<?= site_url('/guidance/cases/choose-for-ticket/' . $item['id']) ?>" class="btn btn-success btn-sm flex-grow-1" data-app-modal-open>
                      <i class="bi bi-play-circle" aria-hidden="true"></i> Start Visit
                    </a>
                  <?php endif; ?>
                <?php else: ?>
                  <button type="button"
                          class="btn btn-success btn-sm flex-grow-1"
                          hx-post="<?= site_url($unit . '/queue/start/' . $item['id']) ?>"
                          hx-target="#queue-board-container"
                          hx-swap="innerHTML">
                    <i class="bi bi-play-circle" aria-hidden="true"></i> Start Visit
                  </button>
                <?php endif; ?>
                <form action="<?= site_url($unit . '/queue/skip/' . $item['id']) ?>" method="POST"
                      hx-post="<?= site_url($unit . '/queue/skip/' . $item['id']) ?>"
                      hx-target="#queue-board-container"
                      hx-swap="innerHTML"
                      hx-disabled-elt="find button"
                      data-confirm="Move this ticket to Skipped? You can recall the patient within 60 minutes."
                      data-confirm-title="Skip patient ticket"
                      data-confirm-label="Skip ticket"
                      data-confirm-tone="warning">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-outline-secondary btn-sm" title="Patient not answering - Recall allowed within 60 mins">
                    <i class="bi bi-skip-forward" aria-hidden="true"></i> Skip
                  </button>
                </form>
              </div>
              <?= view('queue/no_show_action', ['item' => $item, 'unit' => $unit]) ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 3. IN CONSULTATION COLUMN -->
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card queue-column">
      <div class="card-header queue-column-header">
        <h6 class="queue-column-title">In Consultation</h6>
        <span class="queue-column-count" aria-label="Patients in consultation"><?= count($summary['in_consultation']) ?></span>
      </div>
      <div class="card-body queue-column-body">
        <?php if (empty($summary['in_consultation'])): ?>
          <div class="queue-column-empty">
            <i class="bi bi-person-check fs-2 d-block mb-1" aria-hidden="true"></i>
            No active session
          </div>
        <?php else: ?>
          <?php foreach ($summary['in_consultation'] as $item): ?>
            <div class="card queue-ticket-card queue-ticket-card--session">
              <div class="q-ticket-head">
                <span class="q-ticket-code q-ticket-code--session"><?= esc($item['ticket_code']) ?></span>
                <span class="q-ticket-time">Started <?= date('h:i A', strtotime($item['started_at'] ?? 'now')) ?></span>
              </div>

              <div class="q-ticket-patient text-truncate">
                <?= esc($item['last_name'] . ', ' . $item['first_name']) ?>
              </div>
              <div class="q-ticket-meta q-mono">
                ID: <?= esc($item['mis_id']) ?>
              </div>

              <div class="queue-card-actions">
                <?php if ($unit === 'clinic'): ?>
                  <form action="<?= site_url('/clinic/encounters/start-for-ticket/' . $item['id']) ?>" method="POST" class="flex-grow-1">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                      <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i> Open Chart
                    </button>
                  </form>
                <?php elseif ($canOpenGuidanceSession): ?>
                  <a href="<?= site_url('/guidance/cases/choose-for-ticket/' . $item['id']) ?>" class="btn btn-primary btn-sm flex-grow-1" data-app-modal-open>
                    <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i> Open Chart
                  </a>
                <?php endif; ?>
                <button type="button"
                        class="btn btn-outline-success btn-sm"
                        title="Complete consultation"
                        aria-label="Complete consultation"
                        hx-post="<?= site_url($unit . '/queue/complete/' . $item['id']) ?>"
                        hx-target="#queue-board-container"
                        hx-swap="innerHTML">
                  <i class="bi bi-check2" aria-hidden="true"></i>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 4. RECALL & COMPLETED TABBED COLUMN -->
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card queue-column">
      <div class="card-header queue-column-header">
        <ul class="nav queue-history-tabs" role="tablist" aria-label="Queue history">
          <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#tab-skipped" role="tab" aria-controls="tab-skipped" aria-selected="true">
              Skipped <span class="queue-column-count"><?= count($summary['skipped']) ?></span>
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-done" role="tab" aria-controls="tab-done" aria-selected="false">
              Done <span class="queue-column-count"><?= count($summary['done']) ?></span>
            </a>
          </li>
        </ul>
      </div>

      <div class="tab-content card-body queue-column-body">
        <!-- Skipped Tab -->
        <div class="tab-pane fade show active" id="tab-skipped">
          <?php if (empty($summary['skipped'])): ?>
            <div class="queue-column-empty">
              <i class="bi bi-check2-all fs-2 d-block mb-1" aria-hidden="true"></i>
              No skipped patients
            </div>
          <?php else: ?>
            <?php foreach ($summary['skipped'] as $item): ?>
              <div class="card queue-ticket-card queue-ticket-card--skipped">
                <div class="q-ticket-head">
                  <span class="q-ticket-code"><?= esc($item['ticket_code']) ?></span>
                  <span class="q-ticket-time">Recall by <?= date('h:i A', strtotime($item['skip_until'] ?? 'now')) ?></span>
                </div>
                <div class="q-ticket-patient text-truncate mb-2">
                  <?= esc($item['last_name'] . ', ' . $item['first_name']) ?>
                </div>
                <div class="queue-card-actions">
                  <button type="button"
                          class="btn btn-outline-primary btn-sm w-100 queue-card-action-btn"
                          hx-post="<?= site_url($unit . '/queue/recall/' . $item['id']) ?>"
                          hx-target="#queue-board-container"
                          hx-swap="innerHTML">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Recall to Waiting
                  </button>
                </div>
                <?= view('queue/no_show_action', ['item' => $item, 'unit' => $unit]) ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Done Tab -->
        <div class="tab-pane fade" id="tab-done">
          <?php if (empty($summary['done'])): ?>
            <div class="queue-column-empty">
              No completed visits today
            </div>
          <?php else: ?>
            <div class="q-done-list">
              <?php foreach (array_reverse($summary['done']) as $item): ?>
                <div class="q-done-item">
                  <div class="q-done-head">
                    <span class="q-ticket-code q-ticket-code--done"><?= esc($item['ticket_code']) ?></span>
                    <span class="q-ticket-time"><?= date('h:i A', strtotime($item['completed_at'] ?? 'now')) ?></span>
                  </div>
                  <div class="q-done-name text-truncate">
                    <?= esc($item['last_name'] . ', ' . $item['first_name']) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div>

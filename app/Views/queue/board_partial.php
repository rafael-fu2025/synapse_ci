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
    ['waiting', 'Waiting', 'primary', 'bi-hourglass-split'],
    ['called', 'Called', 'warning', 'bi-megaphone'],
    ['in_consultation', 'In Session', 'info', 'bi-heart-pulse'],
    ['done', 'Completed', 'success', 'bi-check2-circle'],
    ['skipped', 'Skipped', 'secondary', 'bi-skip-forward'],
    ['total', 'Total Patients', 'primary', 'bi-people'],
];
$queueActor = session()->get('user');
$canOpenGuidanceSession = is_array($queueActor) && (($queueActor['role'] ?? '') === 'superadmin'
    || in_array('guidance.cases.create', $queueActor['permissions'] ?? [], true));
?>

<!-- Live KPI Counters -->
<div class="row g-3 mb-4 queue-metrics" aria-label="Daily queue totals">
  <?php foreach ($metrics as [$key, $label, $color, $icon]): ?>
    <div class="col-6 col-md-4 col-xl-2">
      <div class="card queue-metric mb-0 h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <span class="queue-metric-icon text-<?= $color ?> bg-<?= $color ?>-subtle" aria-hidden="true"><i class="bi <?= $icon ?>"></i></span>
          <div class="min-w-0">
            <span class="queue-metric-label text-muted"><?= esc($label) ?></span>
            <div class="queue-metric-value"><?= (int) $counts[$key] ?></div>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if (!empty($summary['no_show'])): ?>
<div class="card mb-3"><div class="card-body">
  <h6>No-show history (<?= count($summary['no_show']) ?>)</h6>
  <p class="small text-muted">Explicit staff decisions, not completed consultations. A returning patient needs a new ticket; prior tickets remain unchanged.</p>
  <?php foreach ($summary['no_show'] as $absent): ?>
    <div class="small"><span class="badge bg-secondary"><?= esc($absent['ticket_code']) ?></span> <?= esc($absent['last_name'] . ', ' . $absent['first_name']) ?></div>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>
<!-- Kanban Columns -->
<div class="row g-3 align-items-start queue-columns">

  <!-- 1. WAITING COLUMN -->
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card queue-column">
      <div class="card-header queue-column-header">
        <h6 class="queue-column-title"><span class="queue-status-dot bg-secondary" aria-hidden="true"></span> Waiting Line</h6>
        <span class="queue-column-count" aria-label="Waiting patients"><?= count($summary['waiting']) ?></span>
      </div>
      <div class="card-body p-2 queue-column-body">
        <?php if (empty($summary['waiting'])): ?>
          <div class="queue-column-empty text-muted small">
            <i class="bi bi-inbox fs-2 d-block mb-1"></i>
            No patients waiting
          </div>
        <?php else: ?>
          <?php foreach ($summary['waiting'] as $item): ?>
            <div class="card border-0 shadow-sm mb-2 p-3">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="badge bg-dark-subtle text-dark fs-6 fw-bold px-2 py-1">
                  <?= esc($item['ticket_code']) ?>
                </span>
                <?php if ($item['priority'] === 'emergency'): ?>
                  <span class="badge bg-danger">EMERGENCY</span>
                <?php elseif ($item['priority'] === 'urgent'): ?>
                  <span class="badge bg-warning text-dark">URGENT</span>
                <?php endif; ?>
              </div>

              <div class="fw-semibold text-truncate mb-1">
                <?= esc($item['last_name'] . ', ' . $item['first_name']) ?>
              </div>
              <div class="text-muted small mb-2">
                ID: <?= esc($item['mis_id']) ?>
                <?php if (!empty($item['program'])): ?>
                  · <?= esc($item['program']) ?>-<?= esc($item['year_level']) ?>
                <?php elseif (!empty($item['employee_dept'])): ?>
                  · <?= esc($item['employee_dept']) ?>
                <?php endif; ?>
              </div>

              <div class="d-flex gap-1 mt-2">
                <button type="button"
                        class="btn btn-primary btn-sm w-100"
                        hx-post="<?= site_url($unit . '/queue/call/' . $item['id']) ?>"
                        hx-target="#queue-board-container"
                        hx-swap="innerHTML">
                  <i class="bi bi-megaphone me-1"></i> Call Patient
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
        <h6 class="queue-column-title"><span class="queue-status-dot bg-warning" aria-hidden="true"></span> Called & Arriving</h6>
        <span class="queue-column-count" aria-label="Called patients"><?= count($summary['called']) ?></span>
      </div>
      <div class="card-body p-2 queue-column-body">
        <?php if (empty($summary['called'])): ?>
          <div class="queue-column-empty text-muted small">
            <i class="bi bi-bell-slash fs-2 d-block mb-1"></i>
            No active calls
          </div>
        <?php else: ?>
          <?php foreach ($summary['called'] as $item): ?>
            <div class="card border-0 shadow-sm mb-2 p-3 border-start border-warning border-4">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="badge bg-warning text-dark fs-6 fw-bold px-2 py-1">
                  <?= esc($item['ticket_code']) ?>
                </span>
                <span class="badge bg-light text-muted small border">
                  Called: <?= date('h:i A', strtotime($item['called_at'] ?? 'now')) ?>
                </span>
              </div>

              <div class="fw-semibold text-truncate mb-1">
                <?= esc($item['last_name'] . ', ' . $item['first_name']) ?>
              </div>
              <div class="text-muted small mb-3">
                ID: <?= esc($item['mis_id']) ?>
              </div>

              <div class="d-flex gap-2">
                <?php if ($unit === 'guidance'): ?>
                  <?php if ($canOpenGuidanceSession): ?>
                    <a href="<?= site_url('/guidance/cases/choose-for-ticket/' . $item['id']) ?>" class="btn btn-success btn-sm flex-grow-1" data-app-modal-open>
                      <i class="bi bi-play-circle me-1"></i> Start Visit
                    </a>
                  <?php endif; ?>
                <?php else: ?>
                <button type="button"
                        class="btn btn-success btn-sm flex-grow-1"
                        hx-post="<?= site_url($unit . '/queue/start/' . $item['id']) ?>"
                        hx-target="#queue-board-container"
                        hx-swap="innerHTML">
                  <i class="bi bi-play-circle me-1"></i> Start Visit
                </button>
                <?php endif; ?>
                <button type="button"
                        class="btn btn-outline-secondary btn-sm"
                        title="Patient not answering - Recall allowed within 60 mins"
                        hx-post="<?= site_url($unit . '/queue/skip/' . $item['id']) ?>"
                        hx-target="#queue-board-container"
                        hx-swap="innerHTML">
                  <i class="bi bi-skip-forward"></i> Skip
                </button>
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
        <h6 class="queue-column-title"><span class="queue-status-dot bg-primary" aria-hidden="true"></span> In Consultation</h6>
        <span class="queue-column-count" aria-label="Patients in consultation"><?= count($summary['in_consultation']) ?></span>
      </div>
      <div class="card-body p-2 queue-column-body">
        <?php if (empty($summary['in_consultation'])): ?>
          <div class="queue-column-empty text-muted small">
            <i class="bi bi-person-check fs-2 d-block mb-1"></i>
            No active session
          </div>
        <?php else: ?>
          <?php foreach ($summary['in_consultation'] as $item): ?>
            <div class="card border-0 shadow-sm mb-2 p-3 border-start border-info border-4">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="badge bg-info fs-6 fw-bold px-2 py-1">
                  <?= esc($item['ticket_code']) ?>
                </span>
                <span class="badge bg-light text-muted small border">
                  Started: <?= date('h:i A', strtotime($item['started_at'] ?? 'now')) ?>
                </span>
              </div>

              <div class="fw-semibold text-truncate mb-1">
                <?= esc($item['last_name'] . ', ' . $item['first_name']) ?>
              </div>
              <div class="text-muted small mb-3">
                ID: <?= esc($item['mis_id']) ?>
              </div>

              <div class="d-flex gap-2">
                <?php if ($unit === 'clinic'): ?>
                  <form action="<?= site_url('/clinic/encounters/start-for-ticket/' . $item['id']) ?>" method="POST" class="flex-grow-1">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-clipboard2-pulse me-1"></i> Open Chart</button>
                  </form>
                <?php elseif ($canOpenGuidanceSession): ?>
                  <a href="<?= site_url('/guidance/cases/choose-for-ticket/' . $item['id']) ?>" class="btn btn-primary btn-sm flex-grow-1" data-app-modal-open>
                    <i class="bi bi-clipboard2-pulse me-1"></i> Open Chart
                  </a>
                <?php endif; ?>
                <button type="button"
                        class="btn btn-outline-success btn-sm"
                        title="Complete consultation"
                        hx-post="<?= site_url($unit . '/queue/complete/' . $item['id']) ?>"
                        hx-target="#queue-board-container"
                        hx-swap="innerHTML">
                  <i class="bi bi-check2"></i>
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
              <span class="queue-status-dot bg-secondary" aria-hidden="true"></span> Skipped <span class="queue-column-count"><?= count($summary['skipped']) ?></span>
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-done" role="tab" aria-controls="tab-done" aria-selected="false">
              <span class="queue-status-dot bg-success" aria-hidden="true"></span> Done <span class="queue-column-count"><?= count($summary['done']) ?></span>
            </a>
          </li>
        </ul>
      </div>

      <div class="tab-content card-body p-2 queue-column-body">
        <!-- Skipped Tab -->
        <div class="tab-pane fade show active" id="tab-skipped">
          <?php if (empty($summary['skipped'])): ?>
            <div class="queue-column-empty text-muted small">
              <i class="bi bi-check2-all fs-2 d-block mb-1"></i>
              No skipped patients
            </div>
          <?php else: ?>
            <?php foreach ($summary['skipped'] as $item): ?>
              <div class="card border-0 shadow-sm mb-2 p-2 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <span class="badge bg-secondary-subtle text-secondary fw-bold">
                    <?= esc($item['ticket_code']) ?>
                  </span>
                  <span class="text-muted small" style="font-size: 0.75rem;">
                    Recall by: <?= date('h:i A', strtotime($item['skip_until'] ?? 'now')) ?>
                  </span>
                </div>
                <div class="text-truncate small fw-semibold">
                  <?= esc($item['last_name'] . ', ' . $item['first_name']) ?>
                </div>
                <button type="button"
                        class="btn btn-outline-primary btn-sm mt-2 w-100 py-1"
                        hx-post="<?= site_url($unit . '/queue/recall/' . $item['id']) ?>"
                        hx-target="#queue-board-container"
                        hx-swap="innerHTML">
                  <i class="bi bi-arrow-counterclockwise me-1"></i> Recall to Waiting
                </button>
                <?= view('queue/no_show_action', ['item' => $item, 'unit' => $unit]) ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Done Tab -->
        <div class="tab-pane fade" id="tab-done">
          <?php if (empty($summary['done'])): ?>
            <div class="queue-column-empty text-muted small">
              No completed visits today
            </div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach (array_reverse($summary['done']) as $item): ?>
                <div class="list-group-item bg-transparent p-2 border-bottom">
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="badge bg-success-subtle text-success fw-bold">
                      <?= esc($item['ticket_code']) ?>
                    </span>
                    <span class="text-muted small">
                      <?= date('h:i A', strtotime($item['completed_at'] ?? 'now')) ?>
                    </span>
                  </div>
                  <div class="text-truncate small text-muted mt-1">
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

<?php
$assignments = $assignments ?? [];
$anchorUsed = false;
?>
<?php foreach ($assignments as $item): ?>
  <?php
  $status = (string) ($item['status'] ?? 'assigned');
  $isCompleted = $status === 'completed' && !empty($item['submission_id']);
  $isCancelled = $status === 'cancelled';
  $isClosed = $isCompleted || $isCancelled;
  $draftCount = (int) ($item['draft_count'] ?? 0);
  $stateClass = $isCompleted ? 'pe-state-success' : ($isCancelled ? '' : 'pe-state-pending');
  $stateLabel = match ($status) {
      'in_progress' => 'In progress',
      'completed'   => 'Completed',
      'cancelled'   => 'Cancelled',
      default       => 'Assigned',
  };
  $anchorAttr = '';
  if (!$anchorUsed) {
      $anchorAttr = ' id="multiple-intelligence"';
      $anchorUsed = true;
  }
  ?>
  <article<?= $anchorAttr ?> class="pe-content-card pe-survey-card sv-mi-card <?= $isCancelled ? 'is-closed' : '' ?>" aria-labelledby="mi-assignment-title-<?= (int) $item['id'] ?>">
    <div class="pe-card-top">
      <span class="pe-department">
        <?= view('partials/unit_vector_icon', ['unit' => 'guidance']) ?>Guidance Office<span class="sv-external-note">Assessment</span>
      </span>
      <span class="pe-state <?= $stateClass ?>"><?= esc($stateLabel) ?></span>
    </div>

    <h3 class="pe-card-title" id="mi-assignment-title-<?= (int) $item['id'] ?>">Multiple Intelligences Survey</h3>

    <p class="pe-excerpt">Walter McKenzie (1999) · 80 items · Educational and non-diagnostic.</p>

    <div class="pe-survey-deadline">
      <i class="bi <?= $isClosed ? 'bi-calendar-check' : 'bi-clock' ?>" aria-hidden="true"></i>
      <div>
        <span class="pe-date-label">
          <?= esc($item['academic_term'] ?: 'Current Term') ?><?php if (!empty($item['academic_year'])): ?> · AY <?= esc($item['academic_year']) ?><?php endif; ?>
        </span>
        <?php if (!empty($item['due_at'])): ?>
          <time datetime="<?= esc(substr((string) $item['due_at'], 0, 10)) ?>">Due <?= esc(substr((string) $item['due_at'], 0, 10)) ?></time>
        <?php else: ?>
          <span><?= $isCompleted ? 'Completed assessment' : ($isCancelled ? 'Assignment cancelled' : 'No due date set') ?></span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$isCompleted && !$isCancelled): ?>
      <div class="sv-mi-progress">
        <div class="sv-mi-progress-head">
          <span>Saved draft</span>
          <strong><?= $draftCount ?> / 80</strong>
        </div>
        <div class="progress">
          <div class="progress-bar" role="progressbar" style="width: <?= round(($draftCount / 80) * 100, 1) ?>%;" aria-valuenow="<?= $draftCount ?>" aria-valuemin="0" aria-valuemax="80"></div>
        </div>
      </div>
    <?php endif; ?>

    <div class="pe-survey-action">
      <?php if ($isCompleted): ?>
        <p class="pe-completion"><i class="bi bi-check-circle" aria-hidden="true"></i>Your 8-domain profile report is ready.</p>
        <div class="sv-action-row">
          <a href="<?= esc(site_url('/portal/assessments/mi/results/' . (int) $item['submission_id']), 'attr') ?>" class="btn btn-outline-secondary sv-btn">View 8-Domain Profile</a>
          <a href="<?= esc(site_url('/portal/assessments/mi/results/' . (int) $item['submission_id'] . '/pdf'), 'attr') ?>" class="btn btn-outline-secondary sv-btn" target="_blank" rel="noopener">Download PDF</a>
        </div>
      <?php elseif (!$isCancelled): ?>
        <div class="sv-action-row">
          <a href="<?= esc(site_url('/portal/assessments/mi/' . (int) $item['id']), 'attr') ?>" class="btn btn-primary sv-btn"><?= $draftCount > 0 ? 'Resume Assessment' : 'Start Assessment' ?><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
      <?php else: ?>
        <div class="sv-action-row">
          <button class="btn btn-outline-secondary sv-btn" type="button" disabled>Assignment cancelled</button>
        </div>
        <p class="pe-action-note">This assessment is no longer active.</p>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>

<?php
$assignments = $assignments ?? [];
$miGateEnabled = (bool) ($miGateEnabled ?? false);
$badgeFor = static fn (string $status): string => match ($status) {
    'assigned'    => 'bg-info text-dark',
    'in_progress' => 'bg-warning text-dark',
    'completed'   => 'bg-success',
    'cancelled'   => 'bg-secondary',
    default       => 'bg-secondary',
};
?>
<section id="multiple-intelligence" class="mb-4" aria-labelledby="multiple-intelligence-title">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent py-3">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
          <p class="pe-eyebrow mb-1">ASSIGNED ASSESSMENTS</p>
          <h3 class="h5 fw-bold mb-1" id="multiple-intelligence-title">Multiple Intelligence</h3>
          <p class="small text-muted mb-0">Walter McKenzie (1999) · 80 items · Educational and non-diagnostic</p>
        </div>
        <span class="badge bg-primary-subtle text-primary border"><?= count($assignments) ?> assigned</span>
      </div>
    </div>
    <div class="card-body">
      <?php if (!$miGateEnabled && $assignments !== []): ?>
        <div class="alert alert-warning small" role="status">
          You may open an assigned assessment and save encrypted drafts, but final submission is paused pending Guidance, QA, and DPO sign-off.
        </div>
      <?php endif; ?>

      <?php if ($assignments === []): ?>
        <p class="small text-muted mb-0">No Multiple Intelligence assessment is currently assigned to your profile.</p>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($assignments as $item): ?>
            <?php
            $status = (string) ($item['status'] ?? 'assigned');
            $isCompleted = $status === 'completed' && !empty($item['submission_id']);
            $isCancelled = $status === 'cancelled';
            $draftCount = (int) ($item['draft_count'] ?? 0);
            ?>
            <div class="col-md-6">
              <article class="card border h-100" aria-labelledby="mi-assignment-title-<?= (int) $item['id'] ?>">
                <div class="card-body d-flex flex-column">
                  <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <h4 class="h6 fw-bold mb-0" id="mi-assignment-title-<?= (int) $item['id'] ?>">Multiple Intelligences Survey</h4>
                    <span class="badge <?= $badgeFor($status) ?>"><?= esc(ucwords(str_replace('_', ' ', $status))) ?></span>
                  </div>
                  <p class="small text-muted mb-2">
                    <?= esc($item['academic_term'] ?: 'Current Term') ?>
                    <?php if (!empty($item['academic_year'])): ?> · AY <?= esc($item['academic_year']) ?><?php endif; ?>
                    <?php if (!empty($item['due_at'])): ?> · Due <?= esc(substr((string) $item['due_at'], 0, 10)) ?><?php endif; ?>
                  </p>

                  <?php if (!$isCompleted && !$isCancelled): ?>
                    <div class="mb-3">
                      <div class="d-flex justify-content-between small mb-1"><span>Saved draft</span><strong><?= $draftCount ?> / 80</strong></div>
                      <div class="progress" style="height: 8px;">
                        <div class="progress-bar" role="progressbar" style="width: <?= round(($draftCount / 80) * 100, 1) ?>%;" aria-valuenow="<?= $draftCount ?>" aria-valuemin="0" aria-valuemax="80"></div>
                      </div>
                    </div>
                  <?php endif; ?>

                  <div class="d-grid d-sm-flex flex-sm-wrap gap-2 mt-auto">
                    <?php if ($isCompleted): ?>
                      <a href="<?= esc(site_url('/portal/assessments/mi/results/' . (int) $item['submission_id']), 'attr') ?>" class="btn btn-primary btn-sm">View 8-Domain Profile</a>
                      <a href="<?= esc(site_url('/portal/assessments/mi/results/' . (int) $item['submission_id'] . '/pdf'), 'attr') ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">Download PDF</a>
                    <?php elseif (!$isCancelled): ?>
                      <a href="<?= esc(site_url('/portal/assessments/mi/' . (int) $item['id']), 'attr') ?>" class="btn btn-primary btn-sm"><?= $draftCount > 0 ? 'Resume Assessment' : 'Start Assessment' ?></a>
                    <?php else: ?>
                      <span class="small text-muted">This assignment was cancelled.</span>
                    <?php endif; ?>
                  </div>
                </div>
              </article>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

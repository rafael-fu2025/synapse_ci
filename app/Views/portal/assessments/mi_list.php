<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= esc(site_url('/portal')) ?>" class="btn btn-outline-secondary btn-sm">
  <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Portal
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
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

<div class="alert alert-info border-0 shadow-sm mb-4">
  <i class="bi bi-stars me-2" aria-hidden="true"></i>
  <strong>Walter McKenzie (1999) Multiple Intelligences Survey (80 Items):</strong>
  This educational self-assessment measures your relative strengths across all 8 intelligence domains (0–40 per domain). It is developmental and non-diagnostic.
</div>

<?php if (!$miGateEnabled): ?>
  <div class="alert alert-warning border-0 shadow-sm mb-4" role="status">
    <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>
    <strong>Activation Gate Notice (<code>mi_reproduction_signoff</code>):</strong>
    You may open your assigned survey and save encrypted drafts, but final submission is temporarily paused pending official Guidance Head, QA Office, and DPO sign-off.
  </div>
<?php endif; ?>

<?php if ($assignments === []): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
      <i class="bi bi-clipboard2-check fs-1 d-block mb-2 opacity-50" aria-hidden="true"></i>
      <h5 class="fw-semibold">No Multiple Intelligence Surveys Assigned</h5>
      <p class="mb-0">Your guidance counselor has not assigned a Multiple Intelligence survey to your profile yet.</p>
    </div>
  </div>
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
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body d-flex flex-column justify-content-between">
            <div>
              <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <h5 class="card-title mb-0 fw-bold">Multiple Intelligences Survey</h5>
                <span class="badge <?= $badgeFor($status) ?>"><?= esc(ucwords(str_replace('_', ' ', $status))) ?></span>
              </div>
              <p class="text-muted small mb-2">
                Term: <?= esc($item['academic_term'] ?: 'Current Term') ?>
                <?php if (!empty($item['academic_year'])): ?> · AY <?= esc($item['academic_year']) ?><?php endif; ?>
              </p>
              <p class="small text-muted mb-3">
                Assigned: <?= esc(substr((string) ($item['assigned_at'] ?? ''), 0, 10)) ?>
                <?php if (!empty($item['due_at'])): ?>
                  · Due: <?= esc(substr((string) $item['due_at'], 0, 10)) ?>
                <?php endif; ?>
              </p>

              <?php if (!$isCompleted && !$isCancelled): ?>
                <div class="mb-3">
                  <div class="d-flex justify-content-between small mb-1">
                    <span>Saved Draft Progress</span>
                    <strong><?= $draftCount ?> / 80</strong>
                  </div>
                  <div class="progress" style="height: 8px;">
                    <div class="progress-bar" role="progressbar" style="width: <?= round(($draftCount / 80) * 100, 1) ?>%;" aria-valuenow="<?= $draftCount ?>" aria-valuemin="0" aria-valuemax="80"></div>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-2">
              <?php if ($isCompleted): ?>
                <a href="<?= esc(site_url('/portal/assessments/mi/results/' . (int) $item['submission_id']), 'attr') ?>" class="btn btn-primary btn-sm">
                  <i class="bi bi-bar-chart-steps me-1" aria-hidden="true"></i>View 8-Domain Profile
                </a>
                <a href="<?= esc(site_url('/portal/assessments/mi/results/' . (int) $item['submission_id'] . '/pdf'), 'attr') ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
                  <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Download PDF
                </a>
              <?php elseif (!$isCancelled): ?>
                <a href="<?= esc(site_url('/portal/assessments/mi/' . (int) $item['id']), 'attr') ?>" class="btn btn-primary btn-sm">
                  <i class="bi bi-pencil-square me-1" aria-hidden="true"></i><?= $draftCount > 0 ? 'Resume Survey' : 'Start Survey' ?>
                </a>
              <?php else: ?>
                <span class="text-muted small">Cancelled</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex gap-2">
  <a href="<?= esc(site_url('/guidance')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Guidance Dashboard
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$statusFilter = $statusFilter ?? '';
$miGateEnabled = (bool) ($miGateEnabled ?? false);
$canAssign = (bool) ($canAssign ?? false);
$assignments = $assignments ?? [];
$students = $students ?? [];

$badgeFor = static fn (string $status): string => match ($status) {
    'assigned'    => 'bg-info text-dark',
    'in_progress' => 'bg-warning text-dark',
    'completed'   => 'bg-success',
    'cancelled'   => 'bg-secondary',
    default       => 'bg-secondary',
};
?>

<?php if (!$miGateEnabled): ?>
  <div class="alert alert-warning border-0 shadow-sm d-flex align-items-start gap-2 mb-4" role="status">
    <i class="bi bi-shield-exclamation fs-5 mt-1" aria-hidden="true"></i>
    <div>
      <strong>Activation Gate Pending (<code>mi_reproduction_signoff</code>):</strong>
      Multi-authority sign-off (Guidance Head, QA Office, DPO) is still pending for Multiple Intelligence reproduction and interpretation policy. Students can view assigned surveys and save encrypted drafts, while live production finalization remains gated until sign-off is complete.
    </div>
  </div>
<?php endif; ?>

<div class="row g-4">
  <?php if ($canAssign): ?>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent py-3">
        <h5 class="card-title mb-0 fw-bold">
          <i class="bi bi-person-plus me-2 text-primary" aria-hidden="true"></i>Assign 80-Item MI Survey
        </h5>
      </div>
      <div class="card-body">
        <form method="POST" action="<?= esc(site_url('/guidance/assessments/mi/assign'), 'attr') ?>" autocomplete="off">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label for="mi-student-select" class="form-label fw-semibold">Student</label>
            <select id="mi-student-select" name="person_id" class="form-select">
              <option value="">Select from list…</option>
              <?php foreach ($students as $stu): ?>
                <?php
                $stuName = trim(($stu['last_name'] ?? '') . ', ' . ($stu['first_name'] ?? '') . (!empty($stu['middle_name']) ? ' ' . $stu['middle_name'] : ''));
                $stuMeta = trim(($stu['mis_id'] ?? '') . (!empty($stu['program']) ? ' · ' . $stu['program'] : '') . (!empty($stu['year_level']) ? ' Y' . $stu['year_level'] : ''));
                ?>
                <option value="<?= (int) $stu['id'] ?>"><?= esc($stuName) ?> (<?= esc($stuMeta) ?>)</option>
              <?php endforeach; ?>
            </select>
            <label for="mi-mis-id-input" class="form-label fw-semibold mt-2">Or Exact Student MIS ID</label>
            <input id="mi-mis-id-input" type="text" name="mis_id" class="form-control" maxlength="64" placeholder="e.g. 2026-0001">
            <div class="form-text">Select from the list or enter an exact Student MIS ID. Enforces one active assignment per student at a time.</div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-sm-6">
              <label for="mi-academic-term" class="form-label fw-semibold">Academic Term</label>
              <input id="mi-academic-term" type="text" name="academic_term" class="form-control" maxlength="64" placeholder="e.g. 1st Trimester">
            </div>
            <div class="col-sm-6">
              <label for="mi-academic-year" class="form-label fw-semibold">Academic Year</label>
              <input id="mi-academic-year" type="text" name="academic_year" class="form-control" maxlength="32" placeholder="e.g. 2026-2027">
            </div>
          </div>

          <div class="mb-3">
            <label for="mi-due-at-display" class="form-label fw-semibold">Target Due Date <span class="text-muted fw-normal">(Optional)</span></label>
            <input id="mi-due-at-display" type="text" class="form-control" data-datepicker data-target="#mi-due-at" data-min="today" placeholder="DD/MM/YYYY" autocomplete="off">
            <input id="mi-due-at" type="hidden" name="due_at" value="">
          </div>

          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-send-check me-1" aria-hidden="true"></i>Assign MI Survey
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="<?= $canAssign ? 'col-lg-8' : 'col-12' ?>">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
        <h5 class="card-title mb-0 fw-bold">
          <i class="bi bi-ui-checks-grid me-2 text-primary" aria-hidden="true"></i>Assigned Multiple Intelligence Surveys
        </h5>
        <form method="GET" action="<?= esc(site_url('/guidance/assessments/mi'), 'attr') ?>" class="d-flex gap-2 align-items-center">
          <select name="status" class="form-select form-select-sm" aria-label="Filter by status">
            <option value="">All Statuses</option>
            <?php foreach (['assigned' => 'Assigned', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $val => $label): ?>
              <option value="<?= esc($val) ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-outline-primary btn-sm">Filter</button>
        </form>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light text-muted small text-uppercase">
              <tr>
                <th class="ps-3">Student</th>
                <th>Term / Year</th>
                <th>Assigned</th>
                <th>Status</th>
                <th class="text-end pe-3">Results &amp; PDF</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($assignments === []): ?>
                <tr>
                  <td colspan="5" class="text-center py-5 text-muted">
                    <i class="bi bi-clipboard2-data fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i>
                    No Multiple Intelligence survey assignments found.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($assignments as $row): ?>
                  <?php
                  $fullName = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . (!empty($row['middle_name']) ? ' ' . $row['middle_name'] : ''));
                  $isDone = ($row['status'] ?? '') === 'completed' && !empty($row['submission_id']);
                  ?>
                  <tr>
                    <td class="ps-3">
                      <div class="fw-semibold"><?= esc($fullName) ?></div>
                      <small class="text-muted">
                        ID: <?= esc($row['mis_id'] ?? 'N/A') ?>
                        <?php if (!empty($row['program'])): ?> · <?= esc($row['program']) ?><?php endif; ?>
                      </small>
                    </td>
                    <td>
                      <span class="small"><?= esc($row['academic_term'] ?: '—') ?></span>
                      <?php if (!empty($row['academic_year'])): ?>
                        <small class="d-block text-muted"><?= esc($row['academic_year']) ?></small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="small"><?= esc(substr((string) ($row['assigned_at'] ?? ''), 0, 10)) ?></span>
                      <?php if (!empty($row['completed_at'])): ?>
                        <small class="d-block text-success">Completed <?= esc(substr((string) $row['completed_at'], 0, 10)) ?></small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge <?= $badgeFor((string) ($row['status'] ?? '')) ?>">
                        <?= esc(ucwords(str_replace('_', ' ', (string) ($row['status'] ?? '')))) ?>
                      </span>
                    </td>
                    <td class="text-end pe-3">
                      <?php if ($isDone): ?>
                        <div class="d-inline-flex gap-1">
                          <a href="<?= esc(site_url('/guidance/assessments/mi/results/' . (int) $row['submission_id']), 'attr') ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-bar-chart-steps me-1" aria-hidden="true"></i>8-Domain Profile
                          </a>
                          <a href="<?= esc(site_url('/guidance/assessments/mi/results/' . (int) $row['submission_id'] . '/pdf'), 'attr') ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
                            <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
                          </a>
                        </div>
                      <?php else: ?>
                        <span class="text-muted small">Awaiting student completion</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

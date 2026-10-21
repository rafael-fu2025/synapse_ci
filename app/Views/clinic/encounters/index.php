<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= site_url('/clinic/queue') ?>" class="btn btn-outline-primary btn-sm">
  <i class="bi bi-kanban me-1"></i> Open Queue Board
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center py-3 gap-2">
    <div>
      <h5 class="fw-bold mb-0 text-dark">Patient Clinic Encounters</h5>
      <span class="text-muted small">Clinical visits, medical charts, and consult outcomes</span>
    </div>

    <!-- Filter form -->
    <form method="GET" class="d-flex align-items-center gap-2">
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="in_progress" <?= ($status === 'in_progress') ? 'selected' : '' ?>>In Progress</option>
        <option value="completed" <?= ($status === 'completed') ? 'selected' : '' ?>>Completed</option>
        <option value="referred" <?= ($status === 'referred') ? 'selected' : '' ?>>Referred</option>
      </select>
      <!-- Custom date picker: visible trigger + ISO hidden value -->
      <input type="text"
             class="form-control form-control-sm"
             id="encounterDateFilter"
             data-datepicker
             data-target="#encounterDateFilter_iso"
             value=""
             onchange="this.form.submit()">
      <input type="hidden" name="date" id="encounterDateFilter_iso" value="<?= esc($date) ?>">
    </form>
  </div>

  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light text-muted small text-uppercase">
          <tr>
            <th class="ps-3">Date & Time</th>
            <th>Patient</th>
            <th>Chief Complaint</th>
            <th>Attending Staff</th>
            <th>Status</th>
            <th>Disposition</th>
            <th class="text-end pe-3">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($encounters)): ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">
                <i class="bi bi-clipboard2-x fs-1 d-block mb-2 text-muted opacity-50"></i>
                No clinic encounters found for the selected filter.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($encounters as $enc): ?>
              <tr>
                <td class="ps-3 fw-semibold">
                  <div><?= date('M j, Y', strtotime($enc['started_at'])) ?></div>
                  <small class="text-muted"><?= date('h:i A', strtotime($enc['started_at'])) ?></small>
                </td>
                <td>
                  <a href="<?= site_url('/clinic/patients/' . $enc['person_id']) ?>" class="fw-bold text-decoration-none">
                    <?= esc($enc['last_name'] . ', ' . $enc['first_name']) ?>
                  </a>
                  <div class="text-muted small">
                    ID: <?= esc($enc['mis_id']) ?> (<?= esc(ucfirst($enc['kind'])) ?>)
                  </div>
                </td>
                <td>
                  <span class="text-truncate d-inline-block" style="max-width: 220px;">
                    <?= esc($enc['chief_complaint'] ?? 'No complaint recorded') ?>
                  </span>
                </td>
                <td>
                  <i class="bi bi-person-check text-muted me-1"></i>
                  <?= esc($enc['attending_name']) ?>
                </td>
                <td>
                  <?php if ($enc['status'] === 'in_progress'): ?>
                    <span class="badge bg-warning text-dark">In Progress</span>
                  <?php elseif ($enc['status'] === 'completed'): ?>
                    <span class="badge bg-success">Completed</span>
                  <?php elseif ($enc['status'] === 'referred'): ?>
                    <span class="badge bg-info">Referred</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($enc['disposition'])): ?>
                    <span class="badge bg-light text-dark border">
                      <?= esc(ucwords(str_replace('_', ' ', $enc['disposition']))) ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted small">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-end pe-3">
                  <a href="<?= site_url('/clinic/encounters/' . $enc['id']) ?>" class="btn btn-sm <?= ($enc['status'] === 'in_progress') ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= ($enc['status'] === 'in_progress') ? '<i class="bi bi-pencil-square me-1"></i> Open Stepper' : '<i class="bi bi-eye me-1"></i> View Chart' ?>
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
<?= $this->endSection() ?>

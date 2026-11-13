<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex gap-2">
  <a href="<?= site_url('/guidance/queue') ?>" class="btn btn-outline-info btn-sm">
    <i class="bi bi-clock-history me-1"></i> Guidance Queue
  </a>
  <a href="<?= site_url('/guidance/cases/create') ?>" class="btn btn-primary btn-sm" data-app-modal-open>
    <i class="bi bi-folder-plus me-1"></i> Open New Case
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section data-refresh-owner="guidance.case-list" data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>"
         data-live-filters data-filter-key="guidance-cases" data-filter-url="<?= esc(site_url('/guidance/cases'), 'attr') ?>" data-mutation-refresh>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex flex-wrap align-items-center py-3 gap-2">
    <!-- Filter form -->
    <form method="GET" action="<?= esc(site_url('/guidance/cases'), 'attr') ?>" class="d-flex align-items-center gap-2" data-filter-form>
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <option value="open" <?= ($status === 'open') ? 'selected' : '' ?>>Open</option>
        <option value="active" <?= ($status === 'active') ? 'selected' : '' ?>>Active</option>
        <option value="monitoring" <?= ($status === 'monitoring') ? 'selected' : '' ?>>Monitoring</option>
        <option value="closed" <?= ($status === 'closed') ? 'selected' : '' ?>>Closed</option>
      </select>

      <select name="category" class="form-select form-select-sm">
        <option value="">All Categories</option>
        <option value="academic" <?= ($category === 'academic') ? 'selected' : '' ?>>Academic</option>
        <option value="personal" <?= ($category === 'personal') ? 'selected' : '' ?>>Personal / Mental Health</option>
        <option value="career" <?= ($category === 'career') ? 'selected' : '' ?>>Career Guidance</option>
        <option value="crisis" <?= ($category === 'crisis') ? 'selected' : '' ?>>Crisis Intervention</option>
        <option value="behavioral" <?= ($category === 'behavioral') ? 'selected' : '' ?>>Behavioral</option>
        <option value="family" <?= ($category === 'family') ? 'selected' : '' ?>>Family Concerns</option>
      </select>
      <button type="submit" class="btn btn-outline-primary btn-sm">Filter</button>
    </form>
  </div>
</div>
<p class="small text-muted" data-filter-status role="status" aria-live="polite">Showing counseling cases.</p>
<div class="alert alert-danger" data-filter-error role="alert" hidden><span data-filter-error-text></span> <button type="button" class="btn btn-outline-danger btn-sm" data-filter-retry>Retry</button></div>
<div data-filter-region="list" data-refresh-region="list" data-refresh-heading tabindex="-1">
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light text-muted small text-uppercase">
          <tr>
            <th class="ps-3">Case #</th>
            <th>Title</th>
            <th>Client</th>
            <th>Category</th>
            <th>Counsellor</th>
            <th>Status</th>
            <th>Opened</th>
            <th class="text-end pe-3">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($cases)): ?>
            <tr>
              <td colspan="8" class="text-center py-5 text-muted">
                <i class="bi bi-folder-x fs-1 d-block mb-2 opacity-50"></i>
                No counseling cases found matching the filter.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($cases as $c): ?>
              <tr>
                <td class="ps-3 fw-bold text-dark">
                  <?= esc($c['case_number']) ?>
                </td>
                <td>
                  <a href="<?= site_url('/guidance/cases/' . $c['id']) ?>" class="fw-bold text-decoration-none">
                    <?= esc($c['title']) ?>
                  </a>
                </td>
                <td>
                  <div class="fw-semibold">
                    <?= esc($c['last_name'] . ', ' . $c['first_name']) ?>
                    <span class="badge ms-1 <?= ($c['kind'] ?? 'student') === 'employee' ? 'bg-info-subtle text-info border' : 'bg-primary-subtle text-primary border' ?>" style="font-size: 0.68rem;">
                      <?= esc(strtoupper($c['kind'] ?? 'student')) ?>
                    </span>
                  </div>
                  <small class="text-muted">
                    ID: <?= esc($c['mis_id']) ?>
                    <?php if (!empty($c['program'])): ?>
                      · <?= esc($c['program']) ?>-<?= esc($c['year_level']) ?>
                    <?php elseif (!empty($c['emp_dept'])): ?>
                      · <?= esc($c['emp_dept']) ?><?= !empty($c['emp_position']) ? ' (' . esc($c['emp_position']) . ')' : '' ?>
                    <?php endif; ?>
                  </small>
                </td>
                <td>
                  <span class="badge bg-light text-dark border">
                    <?= esc(ucwords($c['category'])) ?>
                  </span>
                </td>
                <td>
                  <i class="bi bi-person text-muted me-1"></i>
                  <?= esc($c['counsellor_name']) ?>
                </td>
                <td>
                  <?php if ($c['status'] === 'open'): ?>
                    <span class="badge bg-info">Open</span>
                  <?php elseif ($c['status'] === 'active'): ?>
                    <span class="badge bg-success">Active</span>
                  <?php elseif ($c['status'] === 'monitoring'): ?>
                    <span class="badge bg-warning text-dark">Monitoring</span>
                  <?php elseif ($c['status'] === 'closed'): ?>
                    <span class="badge bg-secondary">Closed</span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted">
                  <?= date('M j, Y', strtotime($c['opened_at'])) ?>
                </td>
                <td class="text-end pe-3">
                  <a href="<?= site_url('/guidance/cases/' . $c['id']) ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-folder2-open me-1"></i> Workspace
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
<?php $casePage = $pager->getDetails(); ?>
<?= view('partials/table_pagination', [
    'pagination' => [
        'per_page' => $casePage['perPage'],
        'filtered' => $casePage['total'],
        'total' => $casePage['total'],
        'pages' => $casePage['pageCount'],
        'page' => $casePage['currentPage'],
    ],
    'rowCount' => count($cases),
    'filters' => ['status' => $status],
    'paginationExtraQuery' => ['category' => $category],
    'baseUrl' => site_url('/guidance/cases'),
    'totalLabel' => 'counseling cases',
    'paginationLabel' => 'Counseling case pages',
    'paginationLiveFilters' => true,
], ['saveData' => false]) ?>
</div>
</section>
<?= $this->endSection() ?>

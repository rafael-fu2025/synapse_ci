<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <a href="<?= site_url('/guidance/queue') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-clock-history" aria-hidden="true"></i> Guidance Queue
  </a>
  <a href="<?= site_url('/guidance/cases/create') ?>" class="btn btn-primary btn-sm" data-app-modal-open>
    <i class="bi bi-folder-plus" aria-hidden="true"></i> Open New Case
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$casePage = $pager->getDetails();
$totalCases = (int) ($casePage['total'] ?? count($cases));
$practitionerDirectory = $practitionerDirectory ?? [];
$actor = $actor ?? [];
?>
<section class="guidance-ui ce-workspace"
         data-refresh-owner="guidance.case-list"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>"
         data-live-filters
         data-filter-key="guidance-cases"
         data-filter-url="<?= esc(site_url('/guidance/cases'), 'attr') ?>"
         data-mutation-refresh>

  <div class="ce-notice ce-notice-sky">
    <div class="ce-notice-body">
      <i class="bi bi-shield-lock ce-notice-icon" aria-hidden="true"></i>
      <span>
        <strong>Confidential Counseling Registry</strong> — Case assignments, transfers, and encrypted session notes are scoped strictly to authorized Guidance personnel and audited per record access.
      </span>
    </div>
  </div>

  <!-- Filter Command Bar -->
  <div class="ce-toolbar-card">
    <form method="GET" action="<?= esc(site_url('/guidance/cases'), 'attr') ?>" class="gc-case-filter-form" data-filter-form>
      <div>
        <label for="caseStatusFilter" class="visually-hidden">Filter by status</label>
        <select id="caseStatusFilter" name="status" class="form-select form-select-sm w-100" aria-label="Filter by status">
          <option value="">All Statuses</option>
          <option value="open" <?= ($status === 'open') ? 'selected' : '' ?>>Open</option>
          <option value="active" <?= ($status === 'active') ? 'selected' : '' ?>>Active</option>
          <option value="monitoring" <?= ($status === 'monitoring') ? 'selected' : '' ?>>Monitoring</option>
          <option value="closed" <?= ($status === 'closed') ? 'selected' : '' ?>>Closed</option>
          <?php if (!empty($canArchive) || !empty($canViewArchived) || $status === 'archived'): ?>
            <option value="archived" <?= ($status === 'archived') ? 'selected' : '' ?>>Archived</option>
          <?php endif; ?>
        </select>
      </div>

      <div>
        <label for="caseCategoryFilter" class="visually-hidden">Filter by category</label>
        <select id="caseCategoryFilter" name="category" class="form-select form-select-sm w-100" aria-label="Filter by category">
          <option value="">All Categories</option>
          <option value="academic" <?= ($category === 'academic') ? 'selected' : '' ?>>Academic</option>
          <option value="personal" <?= ($category === 'personal') ? 'selected' : '' ?>>Personal / Mental Health</option>
          <option value="career" <?= ($category === 'career') ? 'selected' : '' ?>>Career Guidance</option>
          <option value="crisis" <?= ($category === 'crisis') ? 'selected' : '' ?>>Crisis Intervention</option>
          <option value="behavioral" <?= ($category === 'behavioral') ? 'selected' : '' ?>>Behavioral</option>
          <option value="family" <?= ($category === 'family') ? 'selected' : '' ?>>Family Concerns</option>
        </select>
      </div>

      <div class="ce-filter-actions">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-funnel" aria-hidden="true"></i> Filter
        </button>
        <a href="<?= site_url('/guidance/cases') ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
    </form>
  </div>

  <!-- Live Status & Error Feedback -->
  <p class="visually-hidden" data-filter-status role="status" aria-live="polite">Showing counseling cases.</p>

  <div class="alert ce-notice ce-notice-crimson" data-banner-dismiss="off" data-filter-error role="alert" hidden>
    <div class="ce-notice-body">
      <i class="bi bi-exclamation-octagon ce-notice-icon" aria-hidden="true"></i>
      <span data-filter-error-text></span>
    </div>
    <button type="button" class="btn btn-outline-danger btn-sm flex-shrink-0" data-filter-retry>Retry</button>
  </div>

  <!-- Cases Ledger -->
  <div data-filter-region="list" data-refresh-region="list" data-refresh-heading tabindex="-1">
    <div class="ce-card">
      <div class="ce-card-header">
        <h2 class="ce-card-title">
          <i class="bi bi-folder2-open" aria-hidden="true"></i> Counseling Cases
        </h2>
        <span class="ce-tag ce-mono"><?= $totalCases ?> total</span>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle ce-table ce-table-mobile" data-mobile-cards="false">
          <thead>
            <tr>
              <th>Case #</th>
              <th>Title</th>
              <th>Client</th>
              <th>Category</th>
              <th>Counsellor</th>
              <th>Status</th>
              <th>Opened</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($cases)): ?>
              <tr>
                <td colspan="8" class="ce-empty-row">
                  <i class="bi bi-folder-x" aria-hidden="true"></i>
                  No counseling cases found matching the filter.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($cases as $c): ?>
                <?php
                  $cOwnerId = (int) ($c['counsellor_user_id'] ?? 0);
                  $cPract = $practitionerDirectory[$cOwnerId] ?? null;
                  $cDisplay = $cPract ? ($cPract['name'] . ' (' . $cPract['tier_label'] . ')') : (string) ($c['counsellor_name'] ?? 'Assigned Counsellor');
                  $isConsultShare = isset($actor['id'])
                      && $cOwnerId !== (int) $actor['id']
                      && ($actor['role'] ?? '') !== 'superadmin'
                      && !in_array('guidance.cases.read_any', $actor['permissions'] ?? [], true);
                  $caseStatus = (string) ($c['status'] ?? 'open');
                ?>
                <tr>
                  <td data-label="Case #">
                    <span class="ce-mono fw-bold"><?= esc($c['case_number']) ?></span>
                  </td>
                  <td data-label="Title">
                    <a href="<?= site_url('/guidance/cases/' . $c['id']) ?>" class="ce-patient-link">
                      <?= esc($c['title']) ?>
                    </a>
                  </td>
                  <td data-label="Client">
                    <div class="d-flex flex-wrap align-items-center gap-1">
                      <span class="fw-semibold"><?= esc($c['last_name'] . ', ' . $c['first_name']) ?></span>
                      <span class="ce-tag ce-tag-upper"><?= esc($c['kind'] ?? 'student') ?></span>
                    </div>
                    <div class="ce-patient-sub">
                      <span class="ce-mono">ID: <?= esc($c['mis_id']) ?></span>
                      <?php if (!empty($c['program'])): ?>
                        <span>· <?= esc($c['program']) ?>-<?= esc($c['year_level']) ?></span>
                      <?php elseif (!empty($c['emp_dept'])): ?>
                        <span>· <?= esc($c['emp_dept']) ?><?= !empty($c['emp_position']) ? ' (' . esc($c['emp_position']) . ')' : '' ?></span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td data-label="Category">
                    <span class="ce-tag"><?= esc(ucwords((string) $c['category'])) ?></span>
                  </td>
                  <td data-label="Counsellor">
                    <div class="d-flex flex-wrap align-items-center gap-1">
                      <span class="ce-staff-cell">
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <?= esc($cDisplay) ?>
                      </span>
                      <?php if ($isConsultShare): ?>
                        <span class="ce-tag">Consultation</span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td data-label="Status">
                    <?php if (!empty($c['deleted_at'])): ?>
                      <span class="ce-status-dot ce-status-slate">Archived</span>
                    <?php elseif ($caseStatus === 'open'): ?>
                      <span class="ce-status-dot ce-status-sky">Open</span>
                    <?php elseif ($caseStatus === 'active'): ?>
                      <span class="ce-status-dot ce-status-emerald">Active</span>
                    <?php elseif ($caseStatus === 'monitoring'): ?>
                      <span class="ce-status-dot ce-status-amber">Monitoring</span>
                    <?php elseif ($caseStatus === 'closed'): ?>
                      <span class="ce-status-dot ce-status-slate">Closed</span>
                    <?php else: ?>
                      <span class="ce-status-dot ce-status-slate"><?= esc(ucwords(str_replace('_', ' ', $caseStatus))) ?></span>
                    <?php endif; ?>
                  </td>
                  <td data-label="Opened">
                    <div class="ce-date-primary ce-mono"><?= date('M j, Y', strtotime((string) $c['opened_at'])) ?></div>
                  </td>
                  <td data-label="Action" class="text-end">
                    <?php if (!empty($c['deleted_at'])): ?>
                      <?php if (!empty($canArchive)): ?>
                        <form method="POST"
                              action="<?= esc(site_url('/guidance/cases/' . $c['id'] . '/restore'), 'attr') ?>"
                              class="d-inline"
                              data-archive-async="restore"
                              data-archive-undo-url="<?= esc(site_url('/guidance/cases/' . $c['id'] . '/archive'), 'attr') ?>"
                              data-archive-undo-label="Re-archive">
                          <?= csrf_field() ?>
                          <button type="submit" class="btn btn-outline-primary btn-sm ce-action-btn">
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore
                          </button>
                        </form>
                      <?php else: ?>
                        <span class="text-muted small">Archived</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <div class="d-flex flex-nowrap justify-content-md-end gap-2 gc-card-actions">
                        <?php if (!empty($c['person_id'])): ?>
                          <a href="<?= site_url('/guidance/students/' . (int) $c['person_id'] . '/cumulative') ?>"
                             class="btn btn-outline-secondary btn-sm"
                             title="View Client Cumulative Guidance Record"
                             aria-label="View Cumulative Record for <?= esc($c['last_name'] . ', ' . $c['first_name']) ?>">
                            <i class="bi bi-person-lines-fill" aria-hidden="true"></i>
                          </a>
                        <?php endif; ?>
                        <a href="<?= site_url('/guidance/cases/' . $c['id']) ?>" class="btn btn-outline-primary btn-sm ce-action-btn">
                          <i class="bi bi-folder2-open" aria-hidden="true"></i> Workspace
                        </a>
                        <?php if (!empty($canArchive) && $caseStatus === 'closed'): ?>
                          <form method="POST"
                                action="<?= esc(site_url('/guidance/cases/' . $c['id'] . '/archive'), 'attr') ?>"
                                class="d-inline"
                                data-archive-async="archive"
                                data-archive-undo-url="<?= esc(site_url('/guidance/cases/' . $c['id'] . '/restore'), 'attr') ?>"
                                data-archive-undo-label="Undo">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-warning btn-sm ce-action-btn">
                              <i class="bi bi-archive" aria-hidden="true"></i> Archive
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="ce-pagination-bar">
        <?= view('partials/table_pagination', [
            'pagination' => [
                'per_page' => $casePage['perPage'],
                'filtered' => $casePage['total'],
                'total'    => $casePage['total'],
                'pages'    => $casePage['pageCount'],
                'page'     => $casePage['currentPage'],
            ],
            'rowCount'              => count($cases),
            'filters'               => ['status' => $status],
            'paginationExtraQuery'  => ['category' => $category],
            'baseUrl'               => site_url('/guidance/cases'),
            'totalLabel'            => 'counseling cases',
            'paginationLabel'       => 'Counseling case pages',
            'paginationLiveFilters' => true,
        ], ['saveData' => false]) ?>
      </div>
    </div>
  </div>
</section>
<?= $this->endSection() ?>

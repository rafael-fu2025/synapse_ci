<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <a href="<?= site_url('/clinic/queue') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-kanban" aria-hidden="true"></i> Open Queue Board
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$fromVal = ($filters['from'] ?? '') !== '' ? (string) $filters['from'] : (string) ($date ?? '');
$toVal = ($filters['to'] ?? '') !== '' ? (string) $filters['to'] : (string) ($date ?? '');
?>
<section class="ce-workspace"
         data-refresh-owner="clinic.encounters"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>"
         data-live-filters
         data-filter-key="clinic-encounters"
         data-filter-url="<?= esc(site_url('/clinic/encounters'), 'attr') ?>"
         data-mutation-refresh>

  <!-- Architectural Filter Command Bar -->
  <div class="ce-toolbar-card">
    <form method="GET" action="<?= esc(site_url('/clinic/encounters'), 'attr') ?>" class="ce-filter-form" data-filter-form>
      <div class="ce-filter-row ce-filter-row--primary">
        <div class="ce-search-wrap">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search"
                 name="q"
                 value="<?= esc($filters['q'] ?? '') ?>"
                 class="form-control form-control-sm w-100"
                 placeholder="Search patient, MIS ID, or complaint"
                 aria-label="Search patient, MIS ID, or complaint">
        </div>

        <div>
          <select name="status" class="form-select form-select-sm w-100" aria-label="Filter by status">
            <option value="">All Statuses</option>
            <option value="in_progress" <?= ($status === 'in_progress') ? 'selected' : '' ?>>In Progress</option>
            <option value="completed" <?= ($status === 'completed') ? 'selected' : '' ?>>Completed</option>
            <option value="referred" <?= ($status === 'referred') ? 'selected' : '' ?>>Referred</option>
            <?php if (!empty($canArchive) || $status === 'archived'): ?>
              <option value="archived" <?= ($status === 'archived') ? 'selected' : '' ?>>Archived</option>
            <?php endif; ?>
          </select>
        </div>

        <div>
          <select name="complaint" class="form-select form-select-sm w-100" aria-label="Filter by complaint">
            <option value="">All Complaints</option>
            <?php foreach (($complaintCatalog ?? []) as $cKey => $cMeta): ?>
              <option value="<?= esc($cKey) ?>" <?= (($filters['complaint'] ?? '') === $cKey) ? 'selected' : '' ?>><?= esc($cMeta['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <select name="disposition" class="form-select form-select-sm w-100" aria-label="Filter by disposition">
            <option value="">All Dispositions</option>
            <?php foreach (($dispositions ?? []) as $dKey => $dLabel): ?>
              <option value="<?= esc($dKey) ?>" <?= (($filters['disposition'] ?? '') === $dKey) ? 'selected' : '' ?>><?= esc($dLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="ce-filter-row ce-filter-row--secondary">
        <div>
          <input type="text"
                 class="form-control form-control-sm w-100"
                 id="encounterFromFilter"
                 placeholder="From date"
                 aria-label="From date"
                 data-datepicker
                 data-target="#encounterFromFilter_iso"
                 value="">
          <input type="hidden" name="from" id="encounterFromFilter_iso" value="<?= esc($fromVal) ?>">
        </div>

        <div>
          <input type="text"
                 class="form-control form-control-sm w-100"
                 id="encounterToFilter"
                 placeholder="To date"
                 aria-label="To date"
                 data-datepicker
                 data-target="#encounterToFilter_iso"
                 value="">
          <input type="hidden" name="to" id="encounterToFilter_iso" value="<?= esc($toVal) ?>">
        </div>

        <div>
          <label for="encounterPerPage" class="visually-hidden">Encounters per page</label>
          <select name="per_page" id="encounterPerPage" class="form-select form-select-sm w-100">
            <?php foreach ([20, 25, 50, 100] as $size): ?>
              <option value="<?= $size ?>" <?= $pagination['per_page'] === $size ? 'selected' : '' ?>><?= $size ?> / page</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="ce-filter-actions">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-funnel" aria-hidden="true"></i> Filter
          </button>
          <a href="<?= site_url('/clinic/encounters') ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
      </div>
    </form>
  </div>

  <!-- Live Status & Error Feedback -->
  <p class="visually-hidden" data-filter-status role="status" aria-live="polite">Showing clinic encounters.</p>

  <div class="alert ce-notice ce-notice-crimson" data-banner-dismiss="off" data-filter-error role="alert" hidden>
    <div class="ce-notice-body">
      <i class="bi bi-exclamation-octagon ce-notice-icon" aria-hidden="true"></i>
      <span data-filter-error-text></span>
    </div>
    <button type="button" class="btn btn-outline-danger btn-sm flex-shrink-0" data-filter-retry>Retry</button>
  </div>

  <!-- Encounters Ledger -->
  <div data-filter-region="list" data-refresh-region="list" data-refresh-heading tabindex="-1">
    <div class="ce-card">
      <div class="table-responsive">
        <table class="table table-hover align-middle ce-table ce-table-mobile" data-mobile-cards="false">
          <thead>
            <tr>
              <th>Date &amp; Time</th>
              <th>Patient</th>
              <th>Chief Complaint</th>
              <th>Attending Staff</th>
              <th>Status</th>
              <th>Disposition</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($encounters)): ?>
              <tr>
                <td colspan="7" class="ce-empty-row">
                  <i class="bi bi-clipboard2-x" aria-hidden="true"></i>
                  No clinic encounters found for the selected filter.
                </td>
              </tr>
            <?php else: ?>
              <?php
                $manila = new \DateTimeZone('Asia/Manila');
                $utc = new \DateTimeZone('UTC');
              ?>
              <?php foreach ($encounters as $enc): ?>
                <?php
                  $rawAt = (string) ($enc['visited_at'] ?? $enc['started_at']);
                  $dtManila = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawAt, $utc)?->setTimezone($manila);
                ?>
                <tr>
                  <td data-label="Date & Time">
                    <div class="ce-date-primary ce-mono"><?= $dtManila ? $dtManila->format('M j, Y') : date('M j, Y', strtotime($rawAt)) ?></div>
                    <div class="ce-date-sub ce-mono"><?= $dtManila ? $dtManila->format('g:i A') : date('h:i A', strtotime($rawAt)) ?></div>
                  </td>
                  <td data-label="Patient">
                    <a href="<?= site_url('/clinic/patients/' . $enc['person_id']) ?>" class="ce-patient-link">
                      <?= esc($enc['last_name'] . ', ' . $enc['first_name']) ?>
                    </a>
                    <div class="ce-patient-sub">
                      <span class="ce-mono">ID: <?= esc($enc['mis_id']) ?></span>
                      <span>· <?= esc(ucfirst((string) $enc['kind'])) ?></span>
                    </div>
                  </td>
                  <td data-label="Chief Complaint">
                    <span class="ce-complaint-text">
                      <?= esc($enc['chief_complaint'] ?? 'No complaint recorded') ?>
                    </span>
                  </td>
                  <td data-label="Attending Staff">
                    <span class="ce-staff-cell">
                      <i class="bi bi-person-check" aria-hidden="true"></i>
                      <?= esc($enc['attending_name']) ?>
                    </span>
                  </td>
                  <td data-label="Status">
                    <?php if (!empty($enc['deleted_at'])): ?>
                      <span class="ce-status-dot ce-status-slate">Archived</span>
                    <?php elseif ($enc['status'] === 'in_progress'): ?>
                      <span class="ce-status-dot ce-status-amber">In Progress</span>
                    <?php elseif ($enc['status'] === 'completed'): ?>
                      <span class="ce-status-dot ce-status-emerald">Completed</span>
                    <?php elseif ($enc['status'] === 'referred'): ?>
                      <span class="ce-status-dot ce-status-sky">Referred</span>
                    <?php else: ?>
                      <span class="ce-status-dot ce-status-slate"><?= esc(ucwords(str_replace('_', ' ', (string) $enc['status']))) ?></span>
                    <?php endif; ?>
                  </td>
                  <td data-label="Disposition">
                    <?php if (!empty($enc['disposition'])): ?>
                      <span class="ce-tag">
                        <?= esc(ucwords(str_replace('_', ' ', (string) $enc['disposition']))) ?>
                      </span>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td data-label="Action" class="text-end">
                    <?php if (!empty($enc['deleted_at'])): ?>
                      <?php if (!empty($canArchive)): ?>
                        <form method="POST"
                              action="<?= esc(site_url('/clinic/encounters/' . $enc['id'] . '/restore'), 'attr') ?>"
                              class="d-inline"
                              data-archive-async="restore"
                              data-archive-undo-url="<?= esc(site_url('/clinic/encounters/' . $enc['id'] . '/archive'), 'attr') ?>"
                              data-archive-undo-label="Re-archive">
                          <?= csrf_field() ?>
                          <button type="submit" class="btn btn-sm btn-outline-primary ce-action-btn">
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore
                          </button>
                        </form>
                      <?php else: ?>
                        <span class="text-muted small">Archived</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <div class="d-inline-flex align-items-center justify-content-end gap-1">
                        <a href="<?= site_url('/clinic/encounters/' . $enc['id']) ?>"
                           class="btn btn-sm <?= ($enc['status'] === 'in_progress') ? 'btn-primary' : 'btn-outline-secondary' ?> ce-action-btn">
                          <?= ($enc['status'] === 'in_progress')
                            ? '<i class="bi bi-pencil-square" aria-hidden="true"></i> Open Stepper'
                            : '<i class="bi bi-eye" aria-hidden="true"></i> View Chart' ?>
                        </a>
                        <?php if (!empty($canArchive) && in_array($enc['status'], ['completed', 'referred'], true)): ?>
                          <form method="POST"
                                action="<?= esc(site_url('/clinic/encounters/' . $enc['id'] . '/archive'), 'attr') ?>"
                                class="d-inline"
                                data-archive-async="archive"
                                data-archive-undo-url="<?= esc(site_url('/clinic/encounters/' . $enc['id'] . '/restore'), 'attr') ?>"
                                data-archive-undo-label="Undo">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-warning ce-action-btn">
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
            'pagination' => $pagination,
            'baseUrl' => site_url('/clinic/encounters'),
            'filters' => [
                'status' => $status,
                'q' => $filters['q'] ?? '',
                'complaint' => $filters['complaint'] ?? '',
                'disposition' => $filters['disposition'] ?? '',
            ],
            'paginationExtraQuery' => [
                'date' => $date,
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'paginationLabel' => 'Clinic encounter pages',
            'paginationLiveFilters' => true,
        ], ['saveData' => false]) ?>
      </div>
    </div>
  </div>
</section>
<?= $this->endSection() ?>

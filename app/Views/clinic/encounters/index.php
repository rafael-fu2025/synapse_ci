<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<style>
  @media (min-width: 768px) {
    .clinic-complaint-cell {
      max-width: 220px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }
  @media (max-width: 767.98px) {
    .clinic-complaint-cell {
      max-width: 100%;
      white-space: normal;
      overflow-wrap: anywhere;
    }
    .clinic-page-actions .btn,
    .clinic-filter-form .form-control,
    .clinic-filter-form .form-select,
    .clinic-filter-form .bd-select .bd-trigger,
    .clinic-filter-form .bd-select .bd-select-toggle,
    .clinic-filter-form .btn,
    .clinic-encounters-table.table-mobile-cards .btn {
      min-height: 40px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .clinic-filter-form .bd-select {
      width: 100%;
    }
    .clinic-encounters-action-btn {
      width: 100%;
    }
    .clinic-encounters-pagination .pagination {
      flex-wrap: wrap;
      gap: 0.25rem;
    }
    .clinic-encounters-pagination .page-link {
      min-height: 40px;
      min-width: 38px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
  }
  @media (min-width: 576px) and (max-width: 767.98px) {
    .clinic-encounters-action-btn {
      width: auto;
    }
  }
</style>
<?= $this->endSection() ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 clinic-page-actions">
  <a href="<?= site_url('/clinic/queue') ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-kanban me-1"></i> Open Queue Board
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section data-refresh-owner="clinic.encounters" data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>"
         data-live-filters data-filter-key="clinic-encounters" data-filter-url="<?= esc(site_url('/clinic/encounters'), 'attr') ?>" data-mutation-refresh>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent py-3">
    <!-- Filter form -->
    <form method="GET" action="<?= esc(site_url('/clinic/encounters'), 'attr') ?>" class="row g-2 align-items-center clinic-filter-form" data-filter-form>
      <div class="col-12 col-md-6 col-xl-4">
        <input type="search" name="q" value="<?= esc($filters['q'] ?? '') ?>" class="form-control form-control-sm w-100" placeholder="Patient, MIS ID, complaint" aria-label="Search patient, MIS ID, or complaint">
      </div>
      <div class="col-6 col-sm-4 col-md-3 col-xl-2">
        <select name="status" class="form-select form-select-sm w-100" aria-label="Filter by status">
          <option value="">All Statuses</option>
          <option value="in_progress" <?= ($status === 'in_progress') ? 'selected' : '' ?>>In Progress</option>
          <option value="completed" <?= ($status === 'completed') ? 'selected' : '' ?>>Completed</option>
          <option value="referred" <?= ($status === 'referred') ? 'selected' : '' ?>>Referred</option>
        </select>
      </div>
      <div class="col-6 col-sm-4 col-md-3 col-xl-3">
        <select name="complaint" class="form-select form-select-sm w-100" aria-label="Filter by complaint">
          <option value="">All Complaints</option>
          <?php foreach (($complaintCatalog ?? []) as $cKey => $cMeta): ?>
            <option value="<?= esc($cKey) ?>" <?= (($filters['complaint'] ?? '') === $cKey) ? 'selected' : '' ?>><?= esc($cMeta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-sm-4 col-md-4 col-xl-3">
        <select name="disposition" class="form-select form-select-sm w-100" aria-label="Filter by disposition">
          <option value="">All Dispositions</option>
          <?php foreach (($dispositions ?? []) as $dKey => $dLabel): ?>
            <option value="<?= esc($dKey) ?>" <?= (($filters['disposition'] ?? '') === $dKey) ? 'selected' : '' ?>><?= esc($dLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php
        $fromVal = ($filters['from'] ?? '') !== '' ? (string) $filters['from'] : (string) ($date ?? '');
        $toVal = ($filters['to'] ?? '') !== '' ? (string) $filters['to'] : (string) ($date ?? '');
      ?>
      <div class="col-6 col-sm-4 col-md-3 col-xl-3">
        <input type="text"
               class="form-control form-control-sm w-100"
               id="encounterFromFilter"
               placeholder="From date"
               data-datepicker
               data-target="#encounterFromFilter_iso"
               value="">
        <input type="hidden" name="from" id="encounterFromFilter_iso" value="<?= esc($fromVal) ?>">
      </div>
      <div class="col-6 col-sm-4 col-md-3 col-xl-3">
        <input type="text"
               class="form-control form-control-sm w-100"
               id="encounterToFilter"
               placeholder="To date"
               data-datepicker
               data-target="#encounterToFilter_iso"
               value="">
        <input type="hidden" name="to" id="encounterToFilter_iso" value="<?= esc($toVal) ?>">
      </div>
      <div class="col-6 col-sm-4 col-md-2 col-xl-2">
        <label for="encounterPerPage" class="visually-hidden">Encounters per page</label>
        <select name="per_page" id="encounterPerPage" class="form-select form-select-sm w-100">
          <?php foreach ([20, 25, 50, 100] as $size): ?>
            <option value="<?= $size ?>" <?= $pagination['per_page'] === $size ? 'selected' : '' ?>><?= $size ?> / page</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-auto col-xl-4 ms-md-auto d-flex flex-nowrap justify-content-md-end gap-2">
        <button type="submit" class="btn btn-outline-primary btn-sm flex-grow-1 flex-md-grow-0">Filter</button>
        <a href="<?= site_url('/clinic/encounters') ?>" class="btn btn-outline-secondary btn-sm flex-grow-1 flex-md-grow-0">Reset</a>
      </div>
    </form>
  </div>
</div>
<p class="small text-muted" data-filter-status role="status" aria-live="polite">Showing clinic encounters.</p>
<div class="alert alert-danger" data-filter-error role="alert" hidden><span data-filter-error-text></span> <button type="button" class="btn btn-outline-danger btn-sm" data-filter-retry>Retry</button></div>
<div data-filter-region="list" data-refresh-region="list" data-refresh-heading tabindex="-1">
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 clinic-encounters-table">
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
                <td class="ps-3 fw-semibold">
                  <div><?= $dtManila ? $dtManila->format('M j, Y') : date('M j, Y', strtotime($rawAt)) ?></div>
                  <small class="text-muted"><?= $dtManila ? $dtManila->format('g:i A') : date('h:i A', strtotime($rawAt)) ?></small>
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
                  <span class="d-block d-md-inline-block clinic-complaint-cell">
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
                  <a href="<?= site_url('/clinic/encounters/' . $enc['id']) ?>" class="btn btn-sm <?= ($enc['status'] === 'in_progress') ? 'btn-primary' : 'btn-outline-secondary' ?> clinic-encounters-action-btn">
                    <?= ($enc['status'] === 'in_progress') ? '<i class="bi bi-pencil-square me-1"></i> Open Stepper' : '<i class="bi bi-eye me-1"></i> View Chart' ?>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="p-3 pt-2 clinic-encounters-pagination">
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
</div>
</section>
<?= $this->endSection() ?>

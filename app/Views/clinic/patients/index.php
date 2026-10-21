<?= $this->extend('layouts/app') ?>
<?php
$patientBaseUrl = $baseUrl ?? site_url('/clinic/patients');
$patientFilters = ($filters ?? []) + ['q' => $searchQuery ?? '', 'kind' => 'all', 'per_page' => $pagination['per_page'] ?? 25];
?>

<?= $this->section('page_actions') ?>
<a href="<?= esc(site_url('/clinic/queue')) ?>" class="btn btn-outline-primary btn-sm">
  <i class="bi bi-kanban me-1" aria-hidden="true"></i> Clinic Queue
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section data-live-table data-endpoint="<?= esc($patientBaseUrl) ?>">
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent py-3">
      <h2 class="h5 fw-bold mb-0">Patient Registry Directory</h2>
      <span class="text-muted small">Registered students, faculty, university employees, and guests</span>
    </div>
    <div class="card-body pt-3">
      <form data-table-filters method="GET" action="<?= esc($patientBaseUrl) ?>" class="row g-3 align-items-end" role="search">
        <div class="col-md-5">
          <label for="patientSearch" class="form-label">Search patients</label>
          <input type="search" name="q" id="patientSearch" class="form-control" value="<?= esc($patientFilters['q']) ?>" placeholder="Search by ID or name" maxlength="150">
        </div>
        <div class="col-md-3">
          <label for="patientKind" class="form-label">Patient type</label>
          <select name="kind" id="patientKind" class="form-select">
            <?php foreach (['all' => 'All patients', 'student' => 'Student', 'employee' => 'Employee', 'guest' => 'Guest'] as $key => $label): ?>
              <option value="<?= esc($key) ?>" <?= $patientFilters['kind'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label for="patientPerPage" class="form-label">Per page</label>
          <select name="per_page" id="patientPerPage" class="form-select">
            <?php foreach ([25, 50, 100] as $size): ?>
              <option value="<?= $size ?>" <?= (int) $patientFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex flex-wrap gap-2">
          <button type="submit" class="btn btn-primary">Search</button>
          <a href="<?= esc($patientBaseUrl) ?>" class="btn btn-outline-secondary" data-table-reset>Reset</a>
        </div>
      </form>
    </div>
  </div>
  <p data-table-status role="status" aria-live="polite" class="small text-muted"></p>
  <div data-table-error class="alert alert-danger" role="alert" hidden>
    <span data-table-error-message></span>
    <button data-table-retry type="button" class="btn btn-outline-danger btn-sm ms-2">Retry</button>
  </div>
  <div data-table-results id="patientDirectoryResults">
    <?= view('clinic/patients/_results', [
        'patients' => $patients ?? [], 'pagination' => $pagination ?? null,
        'filters' => $patientFilters, 'baseUrl' => $patientBaseUrl,
        'canStartVisit' => $canStartVisit ?? false,
    ], ['saveData' => false]) ?>
  </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/live-table.js')) ?>"></script>
<?= $this->endSection() ?>

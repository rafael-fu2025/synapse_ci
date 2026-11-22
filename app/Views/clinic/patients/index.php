<?= $this->extend('layouts/app') ?>
<?php
$patientBaseUrl = $baseUrl ?? site_url('/clinic/patients');
$patientFilters = ($filters ?? []) + ['q' => $searchQuery ?? '', 'kind' => 'all', 'per_page' => $pagination['per_page'] ?? 25];
?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 clinic-page-actions cp-page-actions">
  <a href="<?= esc(site_url('/clinic/queue')) ?>" class="btn btn-outline-secondary">
    <i class="bi bi-kanban" aria-hidden="true"></i>Clinic Queue
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="cp-workspace" data-live-table data-endpoint="<?= esc($patientBaseUrl) ?>">
  <div class="cp-toolbar-card">
    <form data-table-filters method="GET" action="<?= esc($patientBaseUrl) ?>" class="cp-filter-form clinic-patients-filters" role="search">
      <div class="cp-filter-field cp-filter-field--search">
        <label for="patientSearch" class="cp-filter-label">Search patients</label>
        <div class="cp-search-wrap">
          <i class="bi bi-search cp-search-icon" aria-hidden="true"></i>
          <input type="search" name="q" id="patientSearch" class="form-control cp-search-input" value="<?= esc($patientFilters['q']) ?>" placeholder="Search by ID or name" maxlength="150">
        </div>
      </div>
      <div class="cp-filter-field">
        <label for="patientKind" class="cp-filter-label">Patient type</label>
        <select name="kind" id="patientKind" class="form-select">
          <?php foreach (['all' => 'All patients', 'student' => 'Student', 'employee' => 'Employee', 'guest' => 'Guest'] as $key => $label): ?>
            <option value="<?= esc($key) ?>" <?= $patientFilters['kind'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cp-filter-field">
        <label for="patientPerPage" class="cp-filter-label">Per page</label>
        <select name="per_page" id="patientPerPage" class="form-select">
          <?php foreach ([25, 50, 100] as $size): ?>
            <option value="<?= $size ?>" <?= (int) $patientFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cp-filter-actions">
        <button type="submit" class="btn btn-primary">Search</button>
        <a href="<?= esc($patientBaseUrl) ?>" class="btn btn-outline-secondary" data-table-reset>Reset</a>
      </div>
    </form>
  </div>
  <p data-table-status role="status" aria-live="polite" class="cp-live-status"></p>
  <div data-table-error class="cp-alert-error" role="alert" hidden>
    <span data-table-error-message></span>
    <button data-table-retry type="button" class="btn btn-outline-danger btn-sm">Retry</button>
  </div>
  <div data-table-results id="patientDirectoryResults">
    <?= view('clinic/patients/_results', [
        'patients' => $patients ?? [], 'pagination' => $pagination ?? null,
        'filters' => $patientFilters, 'baseUrl' => $patientBaseUrl,
        'openVisits' => $openVisits ?? [],
        'canStartVisit' => $canStartVisit ?? false,
    ], ['saveData' => false]) ?>
  </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/live-table.js')) ?>"></script>
<?= $this->endSection() ?>

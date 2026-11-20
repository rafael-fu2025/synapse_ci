<?= $this->extend('layouts/app') ?>
<?php
$clientBaseUrl = $baseUrl ?? site_url('/guidance/clients');
$clientFilters = ($filters ?? []) + ['q' => '', 'kind' => 'all', 'per_page' => 25];
?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <a href="<?= esc(site_url('/guidance/cases')) ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-folder2-open me-1" aria-hidden="true"></i> Counseling Cases
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="guidance-ui" data-refresh-owner="guidance.clients">
  <div class="alert alert-light border small mb-3">
    <i class="bi bi-person-lines-fill text-primary me-1" aria-hidden="true"></i>
    Find a campus client and open their Guidance record. Counseling histories remain subject to case and service permissions; this directory does not include Clinic medical records.
  </div>
  <section data-live-table data-endpoint="<?= esc($clientBaseUrl) ?>">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body pt-3">
        <form data-table-filters method="GET" action="<?= esc($clientBaseUrl) ?>" class="row g-3 align-items-end" role="search">
          <div class="col-12 col-md-4 col-lg-5">
            <label for="guidanceClientSearch" class="form-label">Search clients</label>
            <input type="search" name="q" id="guidanceClientSearch" class="form-control" value="<?= esc($clientFilters['q']) ?>" placeholder="Search by MIS ID or name" maxlength="150">
          </div>
          <div class="col-6 col-md-3 col-lg-3">
            <label for="guidanceClientKind" class="form-label">Client type</label>
            <select name="kind" id="guidanceClientKind" class="form-select" data-native-select>
              <?php foreach (['all' => 'Students & employees', 'student' => 'Students', 'employee' => 'Employees'] as $key => $label): ?>
                <option value="<?= esc($key) ?>" <?= $clientFilters['kind'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-2 col-lg-2">
            <label for="guidanceClientPerPage" class="form-label">Per page</label>
            <select name="per_page" id="guidanceClientPerPage" class="form-select" data-native-select>
              <?php foreach ([25, 50, 100] as $size): ?>
                <option value="<?= $size ?>" <?= (int) $clientFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-3 col-lg-2 d-flex flex-nowrap gap-2">
            <button type="submit" class="btn btn-primary flex-fill">Search</button>
            <a href="<?= esc($clientBaseUrl) ?>" class="btn btn-outline-secondary flex-fill" data-table-reset>Reset</a>
          </div>
        </form>
      </div>
    </div>
    <p data-table-status role="status" aria-live="polite" class="small text-muted"></p>
    <div data-table-error class="alert alert-danger" role="alert" hidden>
      <span data-table-error-message></span>
      <button data-table-retry type="button" class="btn btn-outline-danger btn-sm ms-2">Retry</button>
    </div>
    <div data-table-results id="guidanceClientDirectoryResults" data-refresh-region="list">
      <?= view('guidance/clients/_results', [
          'clients' => $clients ?? [], 'pagination' => $pagination ?? null,
          'filters' => $clientFilters, 'baseUrl' => $clientBaseUrl,
      ], ['saveData' => false]) ?>
    </div>
  </section>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/live-table.js')) ?>"></script>
<?= $this->endSection() ?>

<?= $this->extend('layouts/app') ?>
<?php
$clientBaseUrl = $baseUrl ?? site_url('/guidance/clients');
$clientFilters = ($filters ?? []) + ['q' => '', 'kind' => 'all', 'per_page' => 25];
?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 cp-page-actions">
  <a href="<?= esc(site_url('/guidance/cases')) ?>" class="btn btn-outline-secondary">
    <i class="bi bi-folder2-open" aria-hidden="true"></i>Counseling Cases
  </a>
  <a href="<?= esc(site_url('/guidance/queue')) ?>" class="btn btn-outline-secondary">
    <i class="bi bi-clock-history" aria-hidden="true"></i>Guidance Queue
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="guidance-ui cp-workspace" data-refresh-owner="guidance.clients" data-live-table data-endpoint="<?= esc($clientBaseUrl) ?>">
  <div class="cp-notice" role="note">
    <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
    Find a campus client and open their Guidance record. Counseling histories remain subject to case and service permissions; this directory does not include Clinic medical records.
  </div>

  <div class="cp-toolbar-card">
    <form data-table-filters method="GET" action="<?= esc($clientBaseUrl) ?>" class="cp-filter-form" role="search">
      <div class="cp-filter-field cp-filter-field--search">
        <label for="guidanceClientSearch" class="cp-filter-label">Search clients</label>
        <div class="cp-search-wrap">
          <i class="bi bi-search cp-search-icon" aria-hidden="true"></i>
          <input type="search" name="q" id="guidanceClientSearch" class="form-control cp-search-input" value="<?= esc($clientFilters['q']) ?>" placeholder="Search by MIS ID or name" maxlength="150">
        </div>
      </div>
      <div class="cp-filter-field">
        <label for="guidanceClientKind" class="cp-filter-label">Client type</label>
        <select name="kind" id="guidanceClientKind" class="form-select" data-native-select>
          <?php foreach (['all' => 'Students & employees', 'student' => 'Students', 'employee' => 'Employees'] as $key => $label): ?>
            <option value="<?= esc($key) ?>" <?= $clientFilters['kind'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cp-filter-field">
        <label for="guidanceClientPerPage" class="cp-filter-label">Per page</label>
        <select name="per_page" id="guidanceClientPerPage" class="form-select" data-native-select>
          <?php foreach ([25, 50, 100] as $size): ?>
            <option value="<?= $size ?>" <?= (int) $clientFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cp-filter-actions">
        <button type="submit" class="btn btn-primary">Search</button>
        <a href="<?= esc($clientBaseUrl) ?>" class="btn btn-outline-secondary" data-table-reset>Reset</a>
      </div>
    </form>
  </div>

  <p data-table-status role="status" aria-live="polite" class="visually-hidden"></p>
  <div data-table-error class="cp-alert-error" role="alert" hidden>
    <span data-table-error-message></span>
    <button data-table-retry type="button" class="btn btn-outline-danger btn-sm">Retry</button>
  </div>
  <div data-table-results id="guidanceClientDirectoryResults" data-refresh-region="list">
    <?= view('guidance/clients/_results', [
        'clients' => $clients ?? [], 'pagination' => $pagination ?? null,
        'filters' => $clientFilters, 'baseUrl' => $clientBaseUrl,
    ], ['saveData' => false]) ?>
  </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/live-table.js')) ?>"></script>
<?= $this->endSection() ?>

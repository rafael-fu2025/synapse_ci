<?= $this->extend('layouts/app') ?>
<?php
$inventoryBaseUrl = $baseUrl ?? site_url('/clinic/inventory');
$inventoryFilters = ($filters ?? []) + ['q' => '', 'status' => 'all', 'per_page' => $pagination['per_page'] ?? 25];
?>
<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 clinic-page-actions inv-page-actions">
  <?php if (!empty($canManage)): ?>
    <a href="<?= esc(site_url('/clinic/inventory/create')) ?>" class="btn btn-primary" data-app-modal-open>
      <i class="bi bi-plus-lg" aria-hidden="true"></i>Add Medicine
    </a>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<section class="inv-workspace" data-live-table data-endpoint="<?= esc($inventoryBaseUrl) ?>"
         data-refresh-owner="inventory.catalog"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <p class="inv-lead-note">Medicine stock is tracked in whole catalog units, not fractional doses. Usable stock excludes expired and quarantined batches.</p>
  <div class="inv-toolbar-card">
    <form data-table-filters action="<?= esc($inventoryBaseUrl) ?>" method="GET" class="inv-filter-form inventory-filters" role="search">
      <div class="inv-filter-field inv-filter-field--search">
        <label for="inventorySearch" class="inv-filter-label">Search medicines</label>
        <div class="inv-search-wrap">
          <i class="bi bi-search inv-search-icon" aria-hidden="true"></i>
          <input type="search" name="q" id="inventorySearch" class="form-control inv-search-input" value="<?= esc($inventoryFilters['q']) ?>" placeholder="Medicine or generic name" maxlength="150">
        </div>
      </div>
      <div class="inv-filter-field">
        <label for="inventoryFilter" class="inv-filter-label">Catalog filter</label>
        <select name="status" id="inventoryFilter" class="form-select">
          <?php foreach ([
              'all' => 'All medicines',
              'active' => 'Active',
              'archived' => 'Archived',
              'low_stock' => 'Low stock',
              'expiring_soon' => 'Expiring within 90 days',
              'expired' => 'Has expired stock',
          ] as $key => $label): ?>
            <option value="<?= esc($key) ?>" <?= $inventoryFilters['status'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="inv-filter-field">
        <label for="inventoryPerPage" class="inv-filter-label">Per page</label>
        <select name="per_page" id="inventoryPerPage" class="form-select">
          <?php foreach ([25, 50, 100] as $size): ?>
            <option value="<?= $size ?>" <?= (int) $inventoryFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="inv-filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="<?= esc($inventoryBaseUrl) ?>" class="btn btn-outline-secondary" data-table-reset>Reset</a>
      </div>
    </form>
  </div>
  <p data-table-status role="status" aria-live="polite" class="inv-live-status"></p>
  <div data-table-error class="inv-alert-error" role="alert" hidden>
    <span data-table-error-message></span>
    <button data-table-retry type="button" class="btn btn-outline-danger btn-sm">Retry</button>
  </div>
  <div data-table-results id="inventoryResults" data-refresh-region="catalog" data-refresh-heading tabindex="-1">
    <?= view('inventory/_results', [
        'medicines' => $medicines ?? [], 'pagination' => $pagination ?? null,
        'filters' => $inventoryFilters, 'baseUrl' => $inventoryBaseUrl,
        'counts' => $counts ?? null, 'canManage' => $canManage ?? false, 'canWrite' => $canWrite ?? false,
    ], ['saveData' => false]) ?>
  </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/live-table.js')) ?>"></script>
<?= $this->endSection() ?>

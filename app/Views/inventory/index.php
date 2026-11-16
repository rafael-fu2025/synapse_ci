<?= $this->extend('layouts/app') ?>
<?php
$inventoryBaseUrl = $baseUrl ?? site_url('/clinic/inventory');
$inventoryFilters = ($filters ?? []) + ['q' => '', 'status' => 'all', 'per_page' => $pagination['per_page'] ?? 25];
?>
<?= $this->section('styles') ?>
<style>
  @media (max-width: 767.98px) {
    .clinic-page-actions .btn,
    .inventory-filters .form-control,
    .inventory-filters .form-select,
    .inventory-filters .bd-select .bd-trigger,
    .inventory-filters .bd-select .bd-select-toggle,
    .inventory-filters .btn,
    .inventory-table.table-mobile-cards .btn {
      min-height: 40px;
    }
    .clinic-page-actions .btn,
    .inventory-filters .btn,
    .inventory-table.table-mobile-cards .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .inventory-filters .bd-select {
      width: 100%;
    }
    #inventoryResults .pagination {
      flex-wrap: wrap;
      gap: 0.25rem;
    }
    #inventoryResults .page-link {
      min-height: 40px;
      min-width: 38px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
  }
</style>
<?= $this->endSection() ?>
<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 clinic-page-actions">
  <?php if (!empty($canManage)): ?><a href="<?= esc(site_url('/clinic/inventory/create')) ?>" class="btn btn-primary" data-app-modal-open><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Medicine</a><?php endif; ?>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<section data-live-table data-endpoint="<?= esc($inventoryBaseUrl) ?>"
         data-refresh-owner="inventory.catalog"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <p class="text-muted">Medicine stock in whole catalog units, not fractional doses. Usable stock excludes expired and quarantined batches.</p>
  <div class="card mb-4">
    <div class="card-body pt-3">
      <form data-table-filters action="<?= esc($inventoryBaseUrl) ?>" method="GET" class="row g-2 g-md-3 align-items-end inventory-filters" role="search">
        <div class="col-12 col-md-5">
          <label for="inventorySearch" class="form-label">Search medicines</label>
          <input type="search" name="q" id="inventorySearch" class="form-control" value="<?= esc($inventoryFilters['q']) ?>" placeholder="Medicine or generic name" maxlength="150">
        </div>
        <div class="col-7 col-md-3">
          <label for="inventoryFilter" class="form-label">Catalog filter</label>
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
        <div class="col-5 col-md-2">
          <label for="inventoryPerPage" class="form-label">Per page</label>
          <select name="per_page" id="inventoryPerPage" class="form-select">
            <?php foreach ([25, 50, 100] as $size): ?>
              <option value="<?= $size ?>" <?= (int) $inventoryFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-2 d-flex flex-wrap gap-2">
          <button type="submit" class="btn btn-primary flex-grow-1 flex-md-grow-0">Filter</button>
          <a href="<?= esc($inventoryBaseUrl) ?>" class="btn btn-outline-secondary flex-grow-1 flex-md-grow-0" data-table-reset>Reset</a>
        </div>
      </form>
    </div>
  </div>
  <p data-table-status role="status" aria-live="polite" class="small text-muted"></p>
  <div data-table-error class="alert alert-danger" role="alert" hidden>
    <span data-table-error-message></span>
    <button data-table-retry type="button" class="btn btn-outline-danger btn-sm ms-2">Retry</button>
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

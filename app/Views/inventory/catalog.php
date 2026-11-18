<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
require __DIR__ . '/_form_values.php';
$isEdit = is_array($medicine);
$suggestions = is_array($catalogSuggestions ?? null)
    ? $catalogSuggestions
    : \App\Services\Clinic\InventoryService::defaultCatalogPresets();
$suggestionOptions = $suggestions['options'] ?? [];
$suggestionTemplates = $suggestions['templates'] ?? [];
$fields = [
    'name' => ['Medicine name', 150, true],
    'generic_name' => ['Generic name', 150, false],
    'category' => ['Category', 100, true],
    'dosage_form' => ['Dosage form', 50, true],
    'strength' => ['Strength', 50, false],
    'unit_of_measure' => ['Stock unit', 20, true],
];
?>
<p class="text-muted">Select from common clinic presets or existing catalog items, or type a new value (new entries are automatically saved to the dropdown for next time). Stock is recorded in whole stock units.</p>
<?php if ($isEdit): ?>
  <div class="alert alert-info" role="note">Once any batch exists, generic name, dosage form, strength and stock unit are frozen. The server rejects changes to these fields; create a new medicine entry for a different identity or unit. Name, category, reorder level and catalog status remain editable.</div>
<?php endif; ?>
<form action="<?= esc($formAction) ?>" method="POST" class="inventory-modal-form" data-modal-submit data-inventory-catalog-form data-catalog-templates="<?= esc((string) json_encode($suggestionTemplates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="idempotency_key" value="<?= esc($value('idempotency_key', $idempotencyKey)) ?>">
  <?php if ($isEdit): ?><input type="hidden" name="version" value="<?= esc($value('version', $medicine['version'])) ?>"><?php endif; ?>
  <div class="row g-3">
    <?php foreach ($fields as $field => [$label, $length, $required]): ?>
      <?php $fieldChoices = $suggestionOptions[$field] ?? []; ?>
      <div class="col-md-6">
        <label for="inventory_<?= esc($field) ?>" class="form-label"><?= esc($label) ?><?= $required ? ' *' : ' (optional)' ?></label>
        <div class="input-group position-relative" data-catalog-combobox="<?= esc($field) ?>">
          <input type="text" name="<?= esc($field) ?>" id="inventory_<?= esc($field) ?>" class="form-control" maxlength="<?= (int) $length ?>" value="<?= esc($value($field, $medicine[$field] ?? '')) ?>" placeholder="Select or type <?= esc(strtolower($label)) ?>" autocomplete="off" data-combobox-input="<?= esc($field) ?>" <?= $required ? 'required' : '' ?> <?= $field === 'unit_of_measure' ? 'aria-describedby="inventoryUnitHelp"' : '' ?>>
          <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" style="min-width: 40px;" data-combobox-toggle="<?= esc($field) ?>" aria-expanded="false" tabindex="-1" aria-label="Choose <?= esc(strtolower($label)) ?>"></button>
          <div class="dropdown-menu w-100 p-1 shadow-sm" data-combobox-menu="<?= esc($field) ?>" style="max-height: 240px; overflow-y: auto; top: 100%; left: 0; z-index: 1060;">
            <?php if ($field === 'name' && $suggestionTemplates !== []): ?>
              <div class="dropdown-header small text-uppercase py-1 px-2">Quick-fill medicines (auto-fills details)</div>
              <?php foreach ($suggestionTemplates as $idx => $tpl): ?>
                <button type="button" class="dropdown-item small text-wrap py-2 d-flex justify-content-between align-items-center gap-2" data-combobox-value="<?= esc((string) $tpl['name'], 'attr') ?>" data-template-index="<?= (int) $idx ?>" tabindex="-1">
                  <span class="text-break" style="min-width: 0;">
                    <strong><?= esc((string) $tpl['name']) ?></strong>
                    <span style="opacity: 0.82;">— <?= esc(trim(($tpl['generic_name'] ?? '') . ' ' . ($tpl['strength'] ?? '') . ' ' . ($tpl['dosage_form'] ?? ''))) ?></span>
                  </span>
                  <span class="badge flex-shrink-0 <?= !empty($tpl['existing']) ? 'bg-primary' : 'bg-secondary-subtle text-secondary-emphasis' ?>"><?= !empty($tpl['existing']) ? 'In catalog' : 'Preset' ?></span>
                </button>
              <?php endforeach; ?>
            <?php else: ?>
              <?php foreach ($fieldChoices as $choice): ?>
                <button type="button" class="dropdown-item small text-wrap py-2" data-combobox-value="<?= esc((string) $choice, 'attr') ?>" tabindex="-1">
                  <?= esc((string) $choice) ?>
                </button>
              <?php endforeach; ?>
            <?php endif; ?>
            <button type="button" class="dropdown-item small text-primary py-1 fw-semibold" data-combobox-custom hidden tabindex="-1"></button>
          </div>
        </div>
        <?php if ($field === 'unit_of_measure'): ?><div id="inventoryUnitHelp" class="form-text">Use one explicit stock unit, such as tablet, capsule or bottle. All quantities use this unit.</div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="col-md-6">
      <label for="inventoryReorder" class="form-label">Reorder level (whole stock units) *</label>
      <input type="number" name="minimum_stock_level" id="inventoryReorder" class="form-control" min="0" max="1000000" step="1" value="<?= esc($value('minimum_stock_level', $medicine['minimum_stock_level'] ?? 0)) ?>" aria-describedby="inventoryReorderHelp" required>
      <div id="inventoryReorderHelp" class="form-text">Low-stock alerts apply when usable stock is at or below this level.</div>
    </div>
    <div class="col-md-6">
      <label for="inventoryActive" class="form-label">Catalog status *</label>
      <select name="is_active" id="inventoryActive" class="form-select" aria-describedby="inventoryArchiveHelp" required>
        <option value="1" <?= $value('is_active', $medicine['is_active'] ?? 1) === '1' ? 'selected' : '' ?>>Active</option>
        <option value="0" <?= $value('is_active', $medicine['is_active'] ?? 1) === '0' ? 'selected' : '' ?>>Archived</option>
      </select>
      <div id="inventoryArchiveHelp" class="form-text">Archived medicines cannot be received or dispensed. Existing stock and history remain visible.</div>
    </div>
    <?php if ($isEdit): ?>
      <div class="col-12">
        <div class="border rounded p-3 bg-body-tertiary small">
          <div class="fw-semibold mb-1">Current batch expiry summary</div>
          <?php if (!empty($medicine['next_expiration_date'])): ?>
            <div>Earliest usable batch expiry: <strong><?= esc($medicine['next_expiration_date']) ?></strong> (<?= esc(date('M j, Y', strtotime((string) $medicine['next_expiration_date']))) ?>)</div>
          <?php elseif (!empty($medicine['earliest_expired_date'])): ?>
            <div class="text-danger">No active unexpired lots. Earliest expired lot on hand: <strong><?= esc($medicine['earliest_expired_date']) ?></strong></div>
          <?php else: ?>
            <div class="text-muted">No batches received yet.</div>
          <?php endif; ?>
          <div class="text-muted mt-1">Batch expiry dates and stock quantities are managed per lot via Receive Stock and batch actions.</div>
        </div>
      </div>
    <?php else: ?>
      <div class="col-12">
        <div class="alert alert-primary py-2 small mb-2 d-none" data-existing-medicine-notice role="status"></div>
        <div class="border rounded p-2 p-sm-3">
          <div class="fw-semibold mb-1">Initial stock batch &amp; expiry (optional)</div>
          <p class="form-text mt-0 mb-3">Leave these fields blank to register the medicine catalog entry only, or enter lot number, expiry date, and opening quantity to record the batch now (if this exact medicine already exists in your catalog, the new batch will be added directly to it).</p>
          <div class="row g-3">
            <div class="col-md-4">
              <label for="inventoryInitialLot" class="form-label">Initial lot / batch number</label>
              <input type="text" name="initial_batch_number" id="inventoryInitialLot" class="form-control" maxlength="50" value="<?= esc($value('initial_batch_number')) ?>" placeholder="e.g. LOT-2026-01">
            </div>
            <div class="col-md-4">
              <label for="inventoryInitialExpiry" class="form-label">Initial expiry date</label>
              <input type="text" id="inventoryInitialExpiry" class="form-control" placeholder="DD/MM/YYYY" data-datepicker data-min="<?= esc(date('Y-m-d')) ?>" data-target="#inventoryInitialExpiryIso" autocomplete="off">
              <input type="hidden" name="initial_expiration_date" id="inventoryInitialExpiryIso" value="<?= esc($value('initial_expiration_date')) ?>">
            </div>
            <div class="col-md-4">
              <label for="inventoryInitialQty" class="form-label">Opening quantity</label>
              <input type="number" name="initial_quantity" id="inventoryInitialQty" class="form-control" min="1" max="1000000" step="1" value="<?= esc($value('initial_quantity')) ?>">
            </div>
            <div class="col-md-6">
              <label for="inventoryInitialSupplier" class="form-label">Supplier (optional)</label>
              <input type="text" name="initial_supplier" id="inventoryInitialSupplier" class="form-control" maxlength="150" value="<?= esc($value('initial_supplier')) ?>">
            </div>
            <div class="col-md-6">
              <label for="inventoryInitialReference" class="form-label">Delivery / PO reference (optional)</label>
              <input type="text" name="initial_reference" id="inventoryInitialReference" class="form-control" maxlength="150" value="<?= esc($value('initial_reference')) ?>">
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
  <?= view('inventory/_form_buttons', ['cancelUrl' => $cancelUrl, 'submitLabel' => $isEdit ? 'Save Medicine' : 'Add Medicine']) ?>
</form>
<?= $this->endSection() ?>

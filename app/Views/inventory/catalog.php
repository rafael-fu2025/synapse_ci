<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
require __DIR__ . '/_form_values.php';
$isEdit = is_array($medicine);
$fields = [
    'name' => ['Medicine name', 150, true],
    'generic_name' => ['Generic name', 150, false],
    'category' => ['Category', 100, true],
    'dosage_form' => ['Dosage form', 50, true],
    'strength' => ['Strength', 50, false],
    'unit_of_measure' => ['Stock unit', 20, true],
];
?>
<p class="text-muted">Medicine catalog only. Stock is recorded as whole stock units (for example, tablets or bottles), never fractional doses. Balances cannot be edited here.</p>
<?php if ($isEdit): ?>
  <div class="alert alert-info" role="note">Once any batch exists, generic name, dosage form, strength and stock unit are frozen. The server rejects changes to these fields; create a new medicine entry for a different identity or unit. Name, category, reorder level and catalog status remain editable.</div>
<?php endif; ?>
<form action="<?= esc($formAction) ?>" method="POST" data-modal-submit>
  <?= csrf_field() ?>
  <input type="hidden" name="idempotency_key" value="<?= esc($value('idempotency_key', $idempotencyKey)) ?>">
  <?php if ($isEdit): ?><input type="hidden" name="version" value="<?= esc($value('version', $medicine['version'])) ?>"><?php endif; ?>
  <div class="row g-3">
    <?php foreach ($fields as $field => [$label, $length, $required]): ?>
      <div class="col-md-6">
        <label for="inventory_<?= esc($field) ?>" class="form-label"><?= esc($label) ?><?= $required ? ' *' : ' (optional)' ?></label>
        <input type="text" name="<?= esc($field) ?>" id="inventory_<?= esc($field) ?>" class="form-control" maxlength="<?= (int) $length ?>" value="<?= esc($value($field, $medicine[$field] ?? '')) ?>" <?= $required ? 'required' : '' ?> <?= $field === 'unit_of_measure' ? 'aria-describedby="inventoryUnitHelp"' : '' ?>>
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
  </div>
  <?= view('inventory/_form_buttons', ['cancelUrl' => $cancelUrl, 'submitLabel' => $isEdit ? 'Save Medicine' : 'Add Medicine']) ?>
</form>
<?= $this->endSection() ?>

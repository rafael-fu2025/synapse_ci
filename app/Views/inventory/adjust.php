<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
require __DIR__ . '/_form_values.php';
$maximum = min(1000000, (int) $batch['remaining_quantity']);
?>
<div class="inv-modal-shell">
  <div class="inv-modal-context">
    <p class="inv-modal-context-title"><?= esc($medicine['name']) ?> · Lot <span class="inv-mono"><?= esc($batch['batch_number']) ?></span></p>
    <p class="inv-modal-context-meta">Remaining: <strong class="inv-mono"><?= number_format((int) $batch['remaining_quantity']) ?> <?= esc($medicine['unit_of_measure']) ?></strong> · Expiry: <span class="inv-mono"><?= esc($batch['expiration_date']) ?></span> · Status: <?= esc(ucfirst($batch['status'])) ?></p>
  </div>
  <div class="inv-notice inv-notice--warn" role="note">Remove stock only. Enter a positive whole-unit quantity; the server subtracts it from this batch and records the reason. This cannot add stock or directly overwrite a balance. History is preserved.</div>

  <form action="<?= esc($formAction) ?>" method="POST" class="inventory-modal-form inv-modal-form" data-modal-submit>
    <?= csrf_field() ?>
    <input type="hidden" name="idempotency_key" value="<?= esc($value('idempotency_key', $idempotencyKey)) ?>">
    <div class="row g-3">
      <div class="col-md-6">
        <label for="inventoryRemovalQty" class="form-label">Quantity to remove (<?= esc($medicine['unit_of_measure']) ?>) *</label>
        <input type="number" name="quantity" id="inventoryRemovalQty" class="form-control" min="1" max="<?= $maximum ?>" step="1" value="<?= esc($value('quantity')) ?>" <?= $maximum < 1 ? 'disabled' : 'required' ?>>
        <div class="form-text"><?= $maximum < 1 ? 'This batch has no stock to remove.' : 'At most ' . number_format($maximum) . ' whole stock units in this removal.' ?></div>
      </div>
      <div class="col-md-6">
        <label for="inventoryRemovalReason" class="form-label">Removal reason *</label>
        <select name="reason" id="inventoryRemovalReason" class="form-select" required>
          <option value="">Choose a reason</option>
          <?php foreach (['damaged' => 'Damaged', 'expired' => 'Expired', 'count_correction' => 'Count correction (remove only)'] as $key => $label): ?>
            <option value="<?= esc($key) ?>" <?= $value('reason') === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label for="inventoryRemovalNote" class="form-label">Removal note *</label>
        <textarea name="note" id="inventoryRemovalNote" class="form-control" rows="3" maxlength="900" aria-describedby="inventoryRemovalNoteHelp" required><?= esc($value('note')) ?></textarea>
        <div id="inventoryRemovalNoteHelp" class="form-text">Explain the removal, up to 900 characters (the current server limit).</div>
      </div>
    </div>
    <?= view('inventory/_form_buttons', ['cancelUrl' => $cancelUrl, 'submitLabel' => 'Remove Stock', 'destructive' => true, 'submitDisabled' => $maximum < 1]) ?>
  </form>
</div>
<?= $this->endSection() ?>

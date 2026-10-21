<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php require __DIR__ . '/_form_values.php'; ?>
<p class="mb-1 fw-semibold"><?= esc($medicine['name']) ?></p>
<p class="text-muted">Receive whole stock units of <strong><?= esc($medicine['unit_of_measure']) ?></strong>, not fractional doses.</p>
<div class="alert alert-info" role="note">For an existing lot, the expiry date must match exactly and the batch must be active. Its received and remaining quantities will increase. Expired lots and archived medicines cannot be received.</div>
<form action="<?= esc($formAction) ?>" method="POST" data-modal-submit>
  <?= csrf_field() ?>
  <input type="hidden" name="idempotency_key" value="<?= esc($value('idempotency_key', $idempotencyKey)) ?>">
  <div class="row g-3">
    <div class="col-md-6">
      <label for="inventoryLot" class="form-label">Lot / batch number *</label>
      <input type="text" name="batch_number" id="inventoryLot" class="form-control" maxlength="50" value="<?= esc($value('batch_number')) ?>" required>
    </div>
    <div class="col-md-6">
      <label for="inventoryExpiry" class="form-label">Expiry date *</label>
      <input type="text" id="inventoryExpiry" class="form-control" data-datepicker data-target="#inventoryExpiryIso" data-min="<?= esc(date('Y-m-d')) ?>" aria-describedby="inventoryExpiryHelp" required>
      <input type="hidden" name="expiration_date" id="inventoryExpiryIso" value="<?= esc($value('expiration_date')) ?>">
      <div id="inventoryExpiryHelp" class="form-text">Enter the full expiry date, today or later.</div>
    </div>
    <div class="col-md-6">
      <label for="inventoryReceiptQty" class="form-label">Quantity (<?= esc($medicine['unit_of_measure']) ?>) *</label>
      <input type="number" name="quantity" id="inventoryReceiptQty" class="form-control" min="1" max="1000000" step="1" value="<?= esc($value('quantity')) ?>" required>
      <div class="form-text">1–1,000,000 whole stock units per receipt.</div>
    </div>
    <div class="col-md-6">
      <label for="inventorySupplier" class="form-label">Supplier (optional)</label>
      <input type="text" name="supplier" id="inventorySupplier" class="form-control" maxlength="150" value="<?= esc($value('supplier')) ?>">
    </div>
    <div class="col-12">
      <label for="inventoryReference" class="form-label">Supplier / delivery reference (optional)</label>
      <input type="text" name="reference" id="inventoryReference" class="form-control" maxlength="150" value="<?= esc($value('reference')) ?>">
    </div>
  </div>
  <?= view('inventory/_form_buttons', ['cancelUrl' => $cancelUrl, 'submitLabel' => 'Receive Stock', 'submitDisabled' => (int) $medicine['is_active'] !== 1]) ?>
</form>
<?= $this->endSection() ?>

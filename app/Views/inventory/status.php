<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php require __DIR__ . '/_form_values.php'; ?>
<p class="mb-1 fw-semibold"><?= esc($medicine['name']) ?> · Lot <?= esc($batch['batch_number']) ?></p>
<p class="text-muted">Expiry: <?= esc($batch['expiration_date']) ?> · Current status: <?= esc($batch['status']) ?> · Remaining: <?= number_format((int) $batch['remaining_quantity']) ?> <?= esc($medicine['unit_of_measure']) ?></p>
<div class="alert alert-info" role="note">Quarantined stock physically remains on hand but is blocked from usable stock and dispensing. Status changes do not change physical quantities. The server blocks release of expired batches, and active batches must also be unexpired to be usable.</div>
<form action="<?= esc($formAction) ?>" method="POST" data-modal-submit>
  <?= csrf_field() ?>
  <input type="hidden" name="idempotency_key" value="<?= esc($value('idempotency_key', $idempotencyKey)) ?>">
  <div class="mb-3">
    <label for="inventoryBatchStatus" class="form-label">New batch status *</label>
    <select name="status" id="inventoryBatchStatus" class="form-select" required>
      <option value="active" <?= $value('status', $batch['status'] === 'active' ? 'quarantined' : 'active') === 'active' ? 'selected' : '' ?>>Active — release from quarantine</option>
      <option value="quarantined" <?= $value('status', $batch['status'] === 'active' ? 'quarantined' : 'active') === 'quarantined' ? 'selected' : '' ?>>Quarantined — block usable stock</option>
    </select>
  </div>
  <div class="mb-3">
    <label for="inventoryStatusReason" class="form-label">Reason for status change *</label>
    <textarea name="reason" id="inventoryStatusReason" class="form-control" rows="3" maxlength="1000" required><?= esc($value('reason')) ?></textarea>
    <div class="form-text">Required, up to 1,000 characters. The change and reason remain in the ledger.</div>
  </div>
  <?= view('inventory/_form_buttons', ['cancelUrl' => $cancelUrl, 'submitLabel' => 'Change Batch Status']) ?>
</form>
<?= $this->endSection() ?>

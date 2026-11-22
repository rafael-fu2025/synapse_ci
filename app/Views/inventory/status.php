<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php require __DIR__ . '/_form_values.php'; ?>
<div class="inv-modal-shell">
  <div class="inv-modal-context">
    <p class="inv-modal-context-title"><?= esc($medicine['name']) ?> · Lot <span class="inv-mono"><?= esc($batch['batch_number']) ?></span></p>
    <p class="inv-modal-context-meta">Expiry: <span class="inv-mono"><?= esc($batch['expiration_date']) ?></span> · Current status: <?= esc(ucfirst($batch['status'])) ?> · Remaining: <strong class="inv-mono"><?= number_format((int) $batch['remaining_quantity']) ?> <?= esc($medicine['unit_of_measure']) ?></strong></p>
  </div>
  <div class="inv-notice" role="note">Quarantined stock physically remains on hand but is blocked from usable stock and dispensing. Status changes do not change physical quantities. The server blocks release of expired batches, and active batches must also be unexpired to be usable.</div>

  <form action="<?= esc($formAction) ?>" method="POST" class="inventory-modal-form inv-modal-form" data-modal-submit>
    <?= csrf_field() ?>
    <input type="hidden" name="idempotency_key" value="<?= esc($value('idempotency_key', $idempotencyKey)) ?>">
    <div>
      <label for="inventoryBatchStatus" class="form-label">New batch status *</label>
      <select name="status" id="inventoryBatchStatus" class="form-select" required>
        <option value="active" <?= $value('status', $batch['status'] === 'active' ? 'quarantined' : 'active') === 'active' ? 'selected' : '' ?>>Active — release from quarantine</option>
        <option value="quarantined" <?= $value('status', $batch['status'] === 'active' ? 'quarantined' : 'active') === 'quarantined' ? 'selected' : '' ?>>Quarantined — block usable stock</option>
      </select>
    </div>
    <div>
      <label for="inventoryStatusReason" class="form-label">Reason for status change *</label>
      <textarea name="reason" id="inventoryStatusReason" class="form-control" rows="3" maxlength="1000" required><?= esc($value('reason')) ?></textarea>
      <div class="form-text">Required, up to 1,000 characters. The change and reason remain in the ledger.</div>
    </div>
    <?= view('inventory/_form_buttons', ['cancelUrl' => $cancelUrl, 'submitLabel' => 'Change Batch Status']) ?>
  </form>
</div>
<?= $this->endSection() ?>

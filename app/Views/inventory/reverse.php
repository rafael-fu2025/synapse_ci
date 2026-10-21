<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php require __DIR__ . '/_form_values.php'; ?>
<p class="mb-1 fw-semibold"><?= esc($medicine['name']) ?> · Original dispensation #<?= (int) $operation['id'] ?></p>
<dl class="row small">
  <dt class="col-sm-4">Recorded</dt><dd class="col-sm-8"><?= esc($operation['created_at']) ?></dd>
  <dt class="col-sm-4">Encounter</dt><dd class="col-sm-8">#<?= (int) $operation['encounter_id'] ?></dd>
  <dt class="col-sm-4">Actor user ID</dt><dd class="col-sm-8">#<?= (int) $operation['actor_id'] ?></dd>
  <dt class="col-sm-4">Original reason</dt><dd class="col-sm-8 text-break"><?= esc($operation['reason']) ?></dd>
  <dt class="col-sm-4">Stock unit</dt><dd class="col-sm-8"><?= esc($medicine['unit_of_measure']) ?> (whole units only)</dd>
</dl>
<div class="alert alert-warning" role="note">Review before committing. Only one correction is allowed per complete dispensation operation. This adds a correction record; it never deletes the original history. Legacy allocations with no operation ID cannot be corrected here. Patient returns must never be restocked. Restoration to expired or quarantined batches is blocked by the server.</div>
<form action="<?= esc($formAction) ?>" method="POST" data-modal-submit>
  <?= csrf_field() ?>
  <input type="hidden" name="idempotency_key" value="<?= esc($value('idempotency_key', $idempotencyKey)) ?>">
  <fieldset class="mb-3">
    <legend class="fs-6 fw-semibold">Correction mode *</legend>
    <div class="form-check mb-3">
      <input type="radio" name="mode" id="inventoryRecordOnly" class="form-check-input" value="record_only" <?= $value('mode', 'record_only') === 'record_only' ? 'checked' : '' ?> required>
      <label for="inventoryRecordOnly" class="form-check-label">Record correction only — leave stock unchanged (safe default)</label>
    </div>
    <div class="form-check">
      <input type="radio" name="mode" id="inventoryRestoreUnissued" class="form-check-input" value="restore_unissued" <?= $value('mode', 'record_only') === 'restore_unissued' ? 'checked' : '' ?> required>
      <label for="inventoryRestoreUnissued" class="form-check-label">Restore ALL unissued units — I confirm ALL units in this dispensation were never issued and have remained in clinic custody</label>
      <div class="form-text">Selecting this mode is an explicit affirmation recorded with the correction. It restores the entire original allocation, not a partial quantity. Only active, unexpired batches and an active medicine are eligible.</div>
    </div>
  </fieldset>
  <div class="mb-3">
    <label for="inventoryCorrectionReason" class="form-label">Correction reason *</label>
    <textarea name="reason" id="inventoryCorrectionReason" class="form-control" rows="3" maxlength="1000" required><?= esc($value('reason')) ?></textarea>
    <div class="form-text">Required, up to 1,000 characters.</div>
  </div>
  <div class="form-check">
    <input type="checkbox" name="review_confirmed" id="inventoryCorrectionReview" class="form-check-input" value="1" <?= $value('review_confirmed') === '1' ? 'checked' : '' ?> required>
    <label for="inventoryCorrectionReview" class="form-check-label">I reviewed original operation #<?= (int) $operation['id'] ?>, my reason and the selected mode. If restoring stock, ALL units were never issued and stayed in clinic custody; none are patient returns.</label>
  </div>
  <?= view('inventory/_form_buttons', ['cancelUrl' => $cancelUrl, 'submitLabel' => 'Commit Correction', 'destructive' => true]) ?>
</form>
<?= $this->endSection() ?>

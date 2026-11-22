<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php require __DIR__ . '/_form_values.php'; ?>
<div class="inv-modal-shell">
  <div class="inv-modal-context">
    <p class="inv-modal-context-title"><?= esc($medicine['name']) ?> · Original dispensation <span class="inv-mono">#<?= (int) $operation['id'] ?></span></p>
    <dl class="inv-modal-dl">
      <div class="inv-modal-dl-item">
        <dt>Recorded</dt>
        <dd class="inv-mono"><?= esc($operation['created_at']) ?></dd>
      </div>
      <div class="inv-modal-dl-item">
        <dt>Encounter</dt>
        <dd class="inv-mono">#<?= (int) $operation['encounter_id'] ?></dd>
      </div>
      <div class="inv-modal-dl-item">
        <dt>Actor user ID</dt>
        <dd class="inv-mono">#<?= (int) $operation['actor_id'] ?></dd>
      </div>
      <div class="inv-modal-dl-item">
        <dt>Stock unit</dt>
        <dd><?= esc($medicine['unit_of_measure']) ?> (whole units only)</dd>
      </div>
      <div class="inv-modal-dl-item inv-modal-dl-item--full">
        <dt>Original reason</dt>
        <dd><?= esc($operation['reason']) ?></dd>
      </div>
    </dl>
  </div>

  <div class="inv-notice inv-notice--warn" role="note">Review before committing. Only one correction is allowed per complete dispensation operation. This adds a correction record; it never deletes the original history. Legacy allocations with no operation ID cannot be corrected here. Patient returns must never be restocked. Restoration to expired or quarantined batches is blocked by the server.</div>

  <form action="<?= esc($formAction) ?>" method="POST" class="inventory-modal-form inv-modal-form" data-modal-submit>
    <?= csrf_field() ?>
    <input type="hidden" name="idempotency_key" value="<?= esc($value('idempotency_key', $idempotencyKey)) ?>">

    <fieldset class="inv-choice-fieldset">
      <legend class="inv-choice-legend">Correction mode *</legend>
      <div class="form-check inv-choice-card">
        <input type="radio" name="mode" id="inventoryRecordOnly" class="form-check-input" value="record_only" <?= $value('mode', 'record_only') === 'record_only' ? 'checked' : '' ?> required>
        <div class="inv-choice-card-body">
          <label for="inventoryRecordOnly" class="form-check-label">Record correction only — leave stock unchanged (safe default)</label>
        </div>
      </div>
      <div class="form-check inv-choice-card">
        <input type="radio" name="mode" id="inventoryRestoreUnissued" class="form-check-input" value="restore_unissued" <?= $value('mode', 'record_only') === 'restore_unissued' ? 'checked' : '' ?> required>
        <div class="inv-choice-card-body">
          <label for="inventoryRestoreUnissued" class="form-check-label">Restore ALL unissued units — I confirm ALL units in this dispensation were never issued and have remained in clinic custody</label>
          <div class="form-text">Selecting this mode is an explicit affirmation recorded with the correction. It restores the entire original allocation, not a partial quantity. Only active, unexpired batches and an active medicine are eligible.</div>
        </div>
      </div>
    </fieldset>

    <div>
      <label for="inventoryCorrectionReason" class="form-label">Correction reason *</label>
      <textarea name="reason" id="inventoryCorrectionReason" class="form-control" rows="3" maxlength="1000" required><?= esc($value('reason')) ?></textarea>
      <div class="form-text">Required, up to 1,000 characters.</div>
    </div>

    <div class="form-check inv-attestation-box">
      <input type="checkbox" name="review_confirmed" id="inventoryCorrectionReview" class="form-check-input" value="1" <?= $value('review_confirmed') === '1' ? 'checked' : '' ?> required>
      <label for="inventoryCorrectionReview" class="form-check-label">I reviewed original operation #<?= (int) $operation['id'] ?>, my reason and the selected mode. If restoring stock, ALL units were never issued and stayed in clinic custody; none are patient returns.</label>
    </div>

    <?= view('inventory/_form_buttons', ['cancelUrl' => $cancelUrl, 'submitLabel' => 'Commit Correction', 'destructive' => true]) ?>
  </form>
</div>
<?= $this->endSection() ?>

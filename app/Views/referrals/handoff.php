<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php $clinic = $unit === 'clinic'; $options = $clinic ? $encounters : $cases; ?>
<div class="border rounded p-3 mb-3">
  <strong class="d-block"><?= esc($patient['last_name'] . ', ' . $patient['first_name']) ?></strong>
  <small class="text-muted">MIS ID: <?= esc($patient['mis_id']) ?> · <?= esc($referral['referral_code']) ?></small>
  <p class="small mb-0 mt-2">This handoff remains attached to the same person. No appointment or queue ticket is created automatically.</p>
</div>
<form action="<?= esc($formAction) ?>" method="POST" data-modal-submit x-data="{ mode: '<?= $options === [] ? 'create' : 'link' ?>' }">
  <?= csrf_field() ?>
  <div class="mb-3">
    <label for="referralHandoffMode" class="form-label">How do you want to continue?</label>
    <select name="mode" id="referralHandoffMode" class="form-select" x-model="mode" required>
      <option value="link" <?= $options === [] ? 'disabled' : '' ?>>Link an existing <?= $clinic ? 'open clinic visit' : 'accessible active guidance case' ?></option>
      <option value="create"><?= $clinic ? 'Start a new clinic visit' : 'Open a new guidance case' ?></option>
    </select>
  </div>
  <div class="mb-3" x-show="mode === 'link'">
    <label for="referralHandoffTarget" class="form-label"><?= $clinic ? 'Open clinic visit' : 'Guidance case' ?></label>
    <select name="target_id" id="referralHandoffTarget" class="form-select" :required="mode === 'link'" :disabled="mode !== 'link'">
      <option value="">Choose an existing record</option>
      <?php foreach ($options as $option): ?>
        <option value="<?= (int) $option['id'] ?>"><?= $clinic ? esc('Visit #' . $option['id'] . ' · ' . ($option['started_at'] ?? '')) : esc(($option['case_number'] ?? ('Case #' . $option['id'])) . ' · ' . ($option['title'] ?? '')) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($clinic): ?>
    <div class="alert alert-info small" x-show="mode === 'create'">Start a new visit only when the patient is present and you are ready to begin care. Otherwise, keep the referral under review and continue when appropriate.</div>
  <?php else: ?>
    <div x-show="mode === 'create'" class="row g-3 mb-3">
      <div class="col-12"><label for="referralCaseTitle" class="form-label">Case title</label><input name="title" id="referralCaseTitle" class="form-control" maxlength="255" :required="mode === 'create'" :disabled="mode !== 'create'"></div>
      <div class="col-12"><label for="referralCaseCategory" class="form-label">Category</label><select name="category" id="referralCaseCategory" class="form-select" :required="mode === 'create'" :disabled="mode !== 'create'">
        <option value="">Choose a category</option>
        <?php foreach (['personal', 'academic', 'career', 'family', 'behavioral', 'crisis'] as $category): ?><option value="<?= $category ?>"><?= ucfirst($category) ?></option><?php endforeach; ?>
      </select></div>
    </div>
    <p class="small text-muted">Only cases you are authorized to access are available for linking. The shared referral summary is not a copy of the source medical record.</p>
  <?php endif; ?>
  <div class="form-actions">
    <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
    <button type="submit" class="btn btn-primary" x-text="mode === 'link' ? 'Link existing record' : '<?= $clinic ? 'Start clinic visit' : 'Open guidance case' ?>'">Continue handoff</button>
  </div>
</form>
<?= $this->endSection() ?>

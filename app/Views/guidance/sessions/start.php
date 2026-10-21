<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<p class="text-muted">Choose the type for this queue consultation. Resuming an existing session keeps its original type and appointment link.</p>
<form action="<?= esc($formAction) ?>" method="POST" data-modal-submit data-guidance-session-start>
  <?= csrf_field() ?>
  <div class="mb-3">
    <label for="sessionType" class="form-label">Session type</label>
    <select id="sessionType" name="session_type" class="form-select" required>
      <option value="">Select a session type</option>
      <?php foreach (\App\Services\Guidance\GuidanceCaseService::SESSION_TYPES as $type): ?>
        <option value="<?= esc($type) ?>"><?= esc(ucwords(str_replace('_', ' ', $type))) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-actions">
    <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
    <button type="submit" class="btn btn-primary">Start / Resume Session</button>
  </div>
</form>
<?= $this->endSection() ?>

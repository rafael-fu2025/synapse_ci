<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<div class="p-1">
  <h2 class="h5">Finalized Note Version History</h2>
  <p class="small text-muted">Finalized amendments only. The current draft, if any, is shown separately in the session reader. Access to this history is audited.</p>
  <?php if ($history === []): ?>
    <div class="alert alert-light">No finalized versions have been recorded.</div>
  <?php endif; ?>
  <?php foreach ($history as $version): ?>
    <section class="card bg-light border-0 p-3 mb-3">
      <h3 class="h6">Version <?= (int) $version['version'] ?></h3>
      <p class="small text-muted"><?= esc($version['created_at']) ?> · <?= esc($version['author_name'] ?? 'Unavailable author') ?></p>
      <div class="p-3 bg-white border rounded" style="white-space: pre-wrap;"><?= esc($version['text']) ?></div>
    </section>
  <?php endforeach; ?>
  <div class="form-actions">
    <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Close</a>
  </div>
</div>
<?= $this->endSection() ?>

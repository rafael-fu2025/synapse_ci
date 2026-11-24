<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<div class="p-1 guidance-ui gc-modal-scope">
  <h2 class="h5 fw-bold mb-1">Finalized Note Version History</h2>
  <p class="small text-muted mb-2">Finalized amendments only. The current draft, if any, is shown separately in the session reader.</p>
  <?php if (!empty($historyReadAudited)): ?>
    <p class="small text-muted mb-3">This access has been audited<?= !empty($oversightMode) ? ' · Oversight mode' : '' ?>.</p>
  <?php endif; ?>
  <?php if ($history === []): ?>
    <div class="ce-notice ce-notice-sky mb-3">
      <div class="ce-notice-body">
        <i class="bi bi-info-circle ce-notice-icon" aria-hidden="true"></i>
        <span>No finalized versions have been recorded.</span>
      </div>
    </div>
  <?php endif; ?>
  <?php foreach ($history as $version): ?>
    <section class="gc-note-panel mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h3 class="h6 fw-semibold mb-0">Version <?= (int) $version['version'] ?></h3>
        <span class="small text-muted"><?= esc($version['created_at']) ?> · <?= esc($version['author_name'] ?? 'Unavailable author') ?></span>
      </div>
      <div class="gc-note-body"><?= esc($version['text']) ?></div>
    </section>
  <?php endforeach; ?>
  <div class="form-actions">
    <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Close</a>
  </div>
</div>
<?= $this->endSection() ?>

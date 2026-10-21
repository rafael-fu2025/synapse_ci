<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="card">
  <div class="card-body p-4">
    <h2 class="h5"><i class="bi bi-tools me-2" aria-hidden="true"></i>Inventory setup pending</h2>
    <p><?= esc($message) ?></p>
    <p class="text-muted">Your existing clinic records are unchanged. Inventory receiving, dispensing, and stock corrections remain disabled until setup has been validated and completed.</p>
    <a href="<?= site_url('/clinic/encounters') ?>" class="btn btn-outline-secondary">Back to Clinic Encounters</a>
  </div>
</div>
<?= $this->endSection() ?>

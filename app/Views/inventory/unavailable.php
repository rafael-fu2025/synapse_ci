<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<style>
  @media (max-width: 767.98px) {
    .clinic-mobile-full {
      min-height: 40px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
  }
  @media (max-width: 575.98px) {
    .clinic-mobile-full {
      width: 100% !important;
    }
  }
</style>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card">
  <div class="card-body p-3 p-sm-4">
    <h2 class="h5"><i class="bi bi-tools me-2" aria-hidden="true"></i>Inventory setup pending</h2>
    <p><?= esc($message) ?></p>
    <p class="text-muted">Your existing clinic records are unchanged. Inventory receiving, dispensing, and stock corrections remain disabled until setup has been validated and completed.</p>
    <a href="<?= site_url('/clinic/encounters') ?>" class="btn btn-outline-secondary clinic-mobile-full">Back to Clinic Encounters</a>
  </div>
</div>
<?= $this->endSection() ?>

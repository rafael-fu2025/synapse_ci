<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<section class="inv-unavailable">
  <div class="inv-unavailable-card">
    <div class="inv-unavailable-head">
      <span class="inv-unavailable-icon" aria-hidden="true"><i class="bi bi-tools"></i></span>
      <h2 class="inv-unavailable-title">Inventory setup pending</h2>
    </div>
    <p class="inv-unavailable-msg"><?= esc($message) ?></p>
    <p class="inv-lead-note">Your existing clinic records are unchanged. Inventory receiving, dispensing, and stock corrections remain disabled until setup has been validated and completed.</p>
    <div>
      <a href="<?= site_url('/clinic/encounters') ?>" class="btn btn-outline-secondary clinic-mobile-full">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Clinic Encounters
      </a>
    </div>
  </div>
</section>
<?= $this->endSection() ?>

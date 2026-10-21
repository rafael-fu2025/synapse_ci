<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="card"><div class="card-body p-4">
  <h2 class="h5">Choose the workstation you are acting for</h2>
  <p class="text-muted">Your platform-owner account can access both workstations. Select one explicitly so referral routing is unambiguous.</p>
  <div class="d-flex flex-wrap gap-3">
    <a href="<?= esc(site_url('/clinic/referrals' . ($suffix ?? ''))) ?>" class="btn btn-primary">Clinic Workstation</a>
    <a href="<?= esc(site_url('/guidance/referrals' . ($suffix ?? ''))) ?>" class="btn btn-outline-primary">Guidance Workstation</a>
  </div>
</div></div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<section class="rw-workstation-chooser">
  <div class="rw-chooser-head">
    <h2 class="h5 mb-1">Choose the workstation you are acting for</h2>
    <p class="text-muted mb-0">Your platform-owner account can access both workstations. Select one explicitly so referral routing is unambiguous.</p>
  </div>
  <div class="rw-chooser-grid">
    <div class="rw-chooser-card">
      <div class="rw-chooser-card-top">
        <span class="rw-chooser-icon" aria-hidden="true"><i class="bi bi-heart-pulse"></i></span>
        <div class="rw-chooser-body">
          <strong>University Clinic</strong>
          <small>Send referrals to Guidance and manage incoming clinical handoffs.</small>
        </div>
      </div>
      <a href="<?= esc(site_url('/clinic/referrals' . ($suffix ?? ''))) ?>" class="btn btn-primary btn-sm">Clinic Workstation</a>
    </div>
    <div class="rw-chooser-card">
      <div class="rw-chooser-card-top">
        <span class="rw-chooser-icon" aria-hidden="true"><i class="bi bi-chat-heart"></i></span>
        <div class="rw-chooser-body">
          <strong>Guidance &amp; Counseling</strong>
          <small>Send referrals to Clinic and manage incoming counseling handoffs.</small>
        </div>
      </div>
      <a href="<?= esc(site_url('/guidance/referrals' . ($suffix ?? ''))) ?>" class="btn btn-outline-primary btn-sm">Guidance Workstation</a>
    </div>
  </div>
</section>
<?= $this->endSection() ?>

<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<!-- Floating Theme Toggle in Upper Right -->
<div class="position-fixed top-0 end-0 p-3 auth-theme-control">
  <button class="header-action theme-toggle auth-theme-toggle"
          type="button"
          id="authThemeToggle"
          data-bs-title="Toggle Theme (Light / Dark mode)"
          data-bs-toggle="tooltip"
          data-bs-placement="bottom"
          aria-label="Toggle dark mode">
    <i class="bi bi-moon-stars theme-icon-dark" aria-hidden="true"></i>
    <i class="bi bi-sun theme-icon-light" aria-hidden="true"></i>
  </button>
</div>

<main class="auth-policy-shell">
  <div class="auth-policy-card">
    <header class="auth-policy-header">
      <div class="auth-policy-brand">
        <img src="<?= base_url('assets/img/brand/synapse-maroon.png') ?>"
             alt="SYNAPSE Logo"
             class="no-select synapse-logo-light auth-policy-logo"
             draggable="false"
             data-no-contextmenu>
        <img src="<?= base_url('assets/img/brand/synapse-white.png') ?>"
             alt="SYNAPSE Logo"
             class="no-select synapse-logo-dark auth-policy-logo"
             draggable="false"
             data-no-contextmenu>
        <div>
          <p class="auth-policy-eyebrow">FOUNDATION UNIVERSITY</p>
          <h1 class="auth-policy-title">Data Privacy Policy</h1>
        </div>
      </div>
      <a href="<?= esc($backUrl ?? site_url('auth/login'), 'attr') ?>" class="btn btn-outline-secondary btn-sm auth-policy-back">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i><?= esc($backLabel ?? 'Back to Sign In') ?>
      </a>
    </header>

    <div class="auth-policy-body">
      <?= $this->include('partials/privacy_policy_content') ?>
    </div>
  </div>
</main>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/pages/auth-login.js') ?>"></script>
<?= $this->endSection() ?>

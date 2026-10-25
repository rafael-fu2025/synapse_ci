<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<main class="container min-vh-100 d-flex align-items-center justify-content-center">
  <section class="auth-pane p-4" aria-labelledby="logout-title">
    <h1 id="logout-title">Confirm Sign Out</h1>
    <?php if ($warning = session()->getFlashdata('logout_warning')): ?>
      <div class="alert alert-warning" role="alert"><?= esc($warning) ?></div>
    <?php endif; ?>
    <p>Are you sure you want to sign out?</p>
    <form method="post" action="<?= esc(site_url('auth/logout'), 'attr') ?>">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary">Sign Out</button>
      <a class="btn btn-secondary" href="<?= esc(site_url('/'), 'attr') ?>">Cancel</a>
    </form>
  </section>
</main>
<?= $this->endSection() ?>

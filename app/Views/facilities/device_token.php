<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/bmg.css'), 'attr') ?>">
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="bmg-workspace">
  <section class="card bmg-panel bmg-credential-panel" aria-labelledby="deviceCredentialHeading">
    <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between gap-2 bmg-panel-heading"><div class="d-flex align-items-center gap-3"><span class="text-primary fs-4" aria-hidden="true"><i class="bi bi-key"></i></span><div><div class="small text-muted">Controller provisioning</div><h2 class="card-title mb-0 p-0" id="deviceCredentialHeading">Device credential issued</h2></div></div><span class="badge text-bg-warning">Shown once</span></div>
    <div class="card-body pt-3 bmg-panel-body">
      <p class="small text-muted">Device #<?= (int) $tokenResult['device_id'] ?> · Drum #<?= (int) $tokenResult['unit_id'] ?></p>
      <div class="alert alert-warning mb-4" role="alert"><strong>Store this credential securely.</strong> Transfer it to the intended device through a secure provisioning process. Do not share it in messages, screenshots or logs. If lost, issue a new credential; the previous one will stop working.</div>
      <label for="deviceToken" class="form-label">Device token</label>
      <textarea id="deviceToken" class="form-control font-monospace mb-3" rows="3" readonly autocomplete="off" spellcheck="false" data-bmg-token><?= esc($tokenResult['token']) ?></textarea>
      <p class="small text-muted mb-4"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>This page does not save the token in browser storage. Navigating away clears it from the page.</p>
      <a href="<?= esc(site_url('/facilities/unit/' . (int) $tokenResult['unit_id'])) ?>" class="btn btn-primary" data-navigation="false">Return to drum<i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></a>
    </div>
  </section>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= esc(base_url('assets/js/bmg.js')) ?>"></script><?= $this->endSection() ?>

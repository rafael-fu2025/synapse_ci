<?php
$formContent = $this->renderSection('content');
$isFragment = service('request')->getGet('fragment') === '1';
$dialogSize = in_array($modalSize ?? 'modal-lg', ['', 'modal-lg', 'modal-xl'], true) ? ($modalSize ?? 'modal-lg') : 'modal-lg';
if (!$isFragment):
    echo $this->extend('layouts/app');
    echo $this->section('content');
?>
<div class="card"><div class="card-body py-3">
  <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary">Back to list</a>
  <span class="text-muted ms-2">Complete the form in the dialog.</span>
</div></div>
<?php endif; ?>
<div class="modal fade" id="appFormModal" data-app-form-modal
     <?= !$isFragment ? 'data-modal-direct="true"' : '' ?>
     data-return-url="<?= esc($cancelUrl) ?>"
     data-bs-backdrop="static" data-bs-keyboard="false"
     tabindex="-1" aria-labelledby="appFormModalTitle" aria-hidden="true">
  <div class="modal-dialog <?= esc($dialogSize) ?> modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fs-6 fw-bold" id="appFormModalTitle"><?= esc($title) ?></h5>
        <button type="button" class="btn-close" aria-label="Close" data-app-modal-close></button>
      </div>
      <div class="modal-body">
        <?= $this->include('partials/alerts') ?>
        <div class="alert alert-danger d-none" role="alert" data-modal-error></div>
        <?= $formContent ?>
      </div>
    </div>
  </div>
</div>
<?php if (!$isFragment) echo $this->endSection(); ?>

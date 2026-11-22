<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success alert-dismissible fade show synapse-flash-alert synapse-flash-success" role="alert">
    <i class="bi bi-check-circle synapse-flash-icon" aria-hidden="true"></i>
    <span class="synapse-flash-text"><?= esc(session()->getFlashdata('success')) ?></span>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger alert-dismissible fade show synapse-flash-alert synapse-flash-danger" role="alert">
    <i class="bi bi-exclamation-triangle synapse-flash-icon" aria-hidden="true"></i>
    <span class="synapse-flash-text"><?= esc(session()->getFlashdata('error')) ?></span>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php if (session()->getFlashdata('warning')): ?>
  <div class="alert alert-warning alert-dismissible fade show synapse-flash-alert synapse-flash-warning" role="alert">
    <i class="bi bi-exclamation-circle synapse-flash-icon" aria-hidden="true"></i>
    <span class="synapse-flash-text"><?= esc(session()->getFlashdata('warning')) ?></span>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php if (session()->getFlashdata('info')): ?>
  <div class="alert alert-info alert-dismissible fade show synapse-flash-alert synapse-flash-info" role="alert">
    <i class="bi bi-info-circle synapse-flash-icon" aria-hidden="true"></i>
    <span class="synapse-flash-text"><?= esc(session()->getFlashdata('info')) ?></span>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php if (session()->getFlashdata('errors')): ?>
  <div class="alert alert-danger alert-dismissible fade show synapse-flash-alert synapse-flash-danger synapse-flash-list" role="alert">
    <i class="bi bi-x-octagon synapse-flash-icon" aria-hidden="true"></i>
    <div class="synapse-flash-body">
      <div class="synapse-flash-title">Please correct the following errors:</div>
      <ul class="synapse-flash-errors">
        <?php foreach ((array) session()->getFlashdata('errors') as $fieldError): ?>
          <li><?= esc($fieldError) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

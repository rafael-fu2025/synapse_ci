<style>
  @media (max-width: 575.98px) {
    .inventory-form-actions {
      flex-direction: column-reverse;
      align-items: stretch;
    }
    .inventory-form-actions > .btn {
      width: 100%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
  }
  @media (max-width: 767.98px) {
    .inventory-modal-form .form-control,
    .inventory-modal-form .form-select,
    .inventory-modal-form .bd-select .bd-trigger,
    .inventory-modal-form .btn,
    .inventory-form-actions > .btn {
      min-height: 40px;
    }
    .inventory-modal-form .bd-select {
      width: 100%;
    }
    .inventory-modal-form [data-combobox-menu] {
      max-width: 100%;
    }
  }
</style>
<div class="form-actions inventory-form-actions">
  <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
  <button type="submit" class="btn <?= !empty($destructive) ? 'btn-danger' : 'btn-primary' ?>" <?= !empty($submitDisabled) ? 'disabled' : '' ?>><?= esc($submitLabel) ?></button>
</div>

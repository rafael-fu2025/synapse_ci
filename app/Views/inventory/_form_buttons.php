<div class="form-actions">
  <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
  <button type="submit" class="btn <?= !empty($destructive) ? 'btn-danger' : 'btn-primary' ?>" <?= !empty($submitDisabled) ? 'disabled' : '' ?>><?= esc($submitLabel) ?></button>
</div>

<?php $editing = $editUnit !== null; ?>
<div class="modal fade" id="unitModal" tabindex="-1" aria-labelledby="unitModalTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" data-bmg-modal>
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="unitModalTitle"><?= $editing ? 'Edit drum' : 'Add drum' ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <form action="<?= esc(site_url($editing ? '/facilities/unit/' . (int) $editUnit['id'] . '/update' : '/facilities/units/store')) ?>" method="POST" data-bmg-submit <?= !$editing ? 'data-bmg-create' : '' ?>>
      <?= csrf_field() ?>
      <?php if ($editing): ?><input type="hidden" name="expected_version" value="<?= (int) ($schedule['version'] ?? 0) ?>"><?php endif; ?>
      <div class="modal-body">
        <div class="mb-3"><label for="unitCode" class="form-label">Unit code</label><input id="unitCode" name="unit_code" class="form-control" value="<?= esc($editUnit['unit_code'] ?? '') ?>" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9_-]*" title="Start with a letter or number; use only letters, numbers, hyphens and underscores." required></div>
        <div class="mb-3"><label for="unitName" class="form-label">Drum name</label><input id="unitName" name="name" class="form-control" value="<?= esc($editUnit['name'] ?? '') ?>" maxlength="100" required></div>
        <div class="mb-3"><label for="unitCapacity" class="form-label">Capacity (kg)</label><input id="unitCapacity" name="drum_capacity_kg" type="number" min="0.01" max="999999.99" step="0.01" class="form-control" value="<?= esc($editUnit['drum_capacity_kg'] ?? '') ?>" aria-describedby="unitCapacityHelp" required><div id="unitCapacityHelp" class="form-text">Enter the verified drum capacity, greater than zero and up to 999999.99 kg, with at most two decimal places.</div></div>
        <label for="unitStatus" class="form-label">Availability</label>
        <select id="unitStatus" name="status" class="form-select" aria-describedby="unitStatusHelp" required>
          <?php foreach (['active' => 'Active — available', 'maintenance' => 'Maintenance', 'offline' => 'Offline'] as $value => $label): ?>
          <option value="<?= esc($value) ?>" <?= ($editUnit['status'] ?? 'active') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
        <p id="unitStatusHelp" class="form-text">Administrative availability only, not the current motor state. Only active drums can start a batch.</p>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save drum</button></div>
    </form>
  </div></div>
</div>

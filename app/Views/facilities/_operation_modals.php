<?php if ($configure): ?>
<div class="modal fade" id="deviceModal" tabindex="-1" aria-labelledby="deviceModalTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" data-bmg-modal>
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="deviceModalTitle"><?= $device === null ? 'Register device' : 'Rotate device credential' ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <form action="<?= esc(site_url($base . '/device')) ?>" method="POST" data-confirm="Issue a new credential for this device? Any existing credential will stop working. Store the new token securely when it is shown." data-confirm-title="Issue device credential" data-confirm-label="Issue credential" data-confirm-tone="warning">
      <?= csrf_field() ?>
      <input type="hidden" name="expected_version" value="<?= (int) ($schedule['version'] ?? 0) ?>">
      <?php if ($device !== null): ?><input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>"><?php endif; ?>
      <div class="modal-body"><p class="text-muted small">Registration and credential rotation require configuration access, not motor-control access. The saved schedule will be disabled and pending commands cancelled. This does not start the motor. The new token is shown only on the result page; store it securely.</p><label for="chipMac" class="form-label">Chip MAC address</label><input id="chipMac" name="chip_mac" class="form-control" value="<?= esc($device['chip_mac'] ?? '') ?>" placeholder="AA:BB:CC:DD:EE:FF" pattern="[0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5}" maxlength="17" autocomplete="off" spellcheck="false" required></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Issue credential</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>
<?php if ($manage): ?>
<?php if ($activeBatch === null && $unit['status'] === 'active'): ?>
<div class="modal fade" id="startBatchModal" tabindex="-1" aria-labelledby="startBatchTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" data-bmg-modal>
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 id="startBatchTitle" class="modal-title fs-5">Start batch</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <form action="<?= esc(site_url($base . '/batch')) ?>" method="POST" data-bmg-submit>
      <?= csrf_field() ?>
      <div class="modal-body"><p class="text-muted small">Create a batch record. This does not start the motor.</p><div class="mb-3"><label for="batchInput" class="form-label">Input weight (kg)</label><input id="batchInput" name="input_weight_kg" type="number" min="0.01" step="0.01" class="form-control" required></div><label for="batchNotes" class="form-label">Notes (optional)</label><textarea id="batchNotes" name="notes" class="form-control" rows="3" maxlength="2000"></textarea></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Start batch record</button></div>
    </form>
  </div></div>
</div>
<?php elseif ($activeBatch !== null): ?>
<div class="modal fade" id="completeBatchModal" tabindex="-1" aria-labelledby="completeBatchTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" data-bmg-modal>
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 id="completeBatchTitle" class="modal-title fs-5">Complete batch</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <form action="<?= esc(site_url('/facilities/batch/' . (int) $activeBatch['id'] . '/release')) ?>" method="POST" data-bmg-submit data-confirm="Complete this batch record? This does not stop the motor or certify the material's quality or suitability for use." data-confirm-title="Complete batch" data-confirm-label="Complete batch" data-confirm-tone="warning">
      <?= csrf_field() ?>
      <div class="modal-body"><p>Batch <strong><?= esc($activeBatch['batch_code']) ?></strong> · <?= esc($activeBatch['total_input_weight_kg']) ?> kg input</p><div class="mb-3"><label for="batchOutput" class="form-label">Output weight (kg, optional)</label><input id="batchOutput" name="output_weight_kg" type="number" min="0" max="<?= esc($activeBatch['total_input_weight_kg']) ?>" step="0.01" class="form-control" aria-describedby="batchOutputHelp"><div id="batchOutputHelp" class="form-text">Leave blank if not measured. Entered output cannot exceed input weight.</div></div><label for="batchReleaseNotes" class="form-label">Completion notes (optional)</label><textarea id="batchReleaseNotes" name="release_notes" class="form-control" rows="3" maxlength="2000"></textarea></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button><button type="submit" class="btn btn-primary">Complete batch</button></div>
    </form>
  </div></div>
</div>
<div class="modal fade" id="cancelBatchModal" tabindex="-1" aria-labelledby="cancelBatchTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" data-bmg-modal>
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 id="cancelBatchTitle" class="modal-title fs-5">Cancel batch</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <form action="<?= esc(site_url('/facilities/batch/' . (int) $activeBatch['id'] . '/cancel')) ?>" method="POST" data-bmg-submit data-confirm="Cancel this batch record? History is retained. This does not stop the motor." data-confirm-title="Cancel batch" data-confirm-label="Cancel batch record" data-confirm-tone="danger">
      <?= csrf_field() ?>
      <div class="modal-body"><p>Cancel <strong><?= esc($activeBatch['batch_code']) ?></strong>. The batch history will be retained.</p><label for="batchCancelReason" class="form-label">Reason for cancellation</label><textarea id="batchCancelReason" name="reason" class="form-control" rows="3" maxlength="2000" required></textarea></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button><button type="submit" class="btn btn-danger">Cancel batch record</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>
<?php endif; ?>

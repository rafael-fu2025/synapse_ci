<div class="modal fade" id="archiveUnitModal" tabindex="-1" aria-labelledby="archiveUnitTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" data-bmg-modal>
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="archiveUnitTitle">Archive drum</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <form action="<?= esc(site_url($base . '/archive'), 'attr') ?>" method="POST" data-bmg-submit
          data-confirm="Archive this drum, revoke its controller credentials, disable saved schedule intent and cancel pending commands? History is retained. This does not prove the motor has stopped."
          data-confirm-title="Archive drum" data-confirm-label="Archive drum" data-confirm-tone="danger">
      <?= csrf_field() ?>
      <input type="hidden" name="expected_version" value="<?= (int) $schedule['version'] ?>">
      <div class="modal-body">
        <p>Archive <strong><?= esc($unit['unit_code']) ?> · <?= esc($unit['name']) ?></strong> from the current drum list?</p>
        <ul class="small">
          <li>Batch, command, device-report and audit history is retained.</li>
          <li>Bound controller credentials are revoked and pending commands canceled.</li>
          <li>Saved schedule intent is disabled. Restoration leaves the drum offline and requires controller registration again.</li>
        </ul>
        <div class="alert alert-warning small">Archiving is not a physical stop or emergency stop. A controller can retain old offline behavior. Verify safe retirement locally before removing this record from service.</div>
        <?php if ($activeBatch !== null): ?>
        <div class="alert alert-info small" role="status">This drum has an active batch. Complete or cancel that batch record before archiving. Changing a batch record does not stop the motor.</div>
        <?php endif; ?>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="drumRetirementConfirmed" name="retirement_confirmed" value="1" required <?= $activeBatch !== null ? 'disabled' : '' ?>>
          <label class="form-check-label" for="drumRetirementConfirmed">I have checked safe retirement locally and understand that archiving does not stop the motor.</label>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger" <?= $activeBatch !== null ? 'disabled' : '' ?>>Archive drum</button></div>
    </form>
  </div></div>
</div>

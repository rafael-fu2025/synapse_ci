<?php
$allowedTypes = $allowedTypes ?? [];
$modules = $modules ?? [];
$scope = $scope ?? [];
$currentReturn = '/admin/archive?tab=' . esc($tab ?? 'records', 'url');
?>

<!-- Archive Record Modal -->
<div class="modal fade" id="arcArchiveModal" tabindex="-1" aria-labelledby="arcArchiveTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fs-6 fw-bold" id="arcArchiveTitle">Archive Record (Reversible)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST"
            action="<?= esc(site_url('/admin/archive/records/archive'), 'attr') ?>"
            data-archive-async="archive"
            data-archive-modal="arcArchiveModal"
            data-archive-undo-url="<?= esc(site_url('/admin/archive/records/restore'), 'attr') ?>"
            data-archive-undo-label="Undo"
            data-archive-undo-reason="Undo archive from notification toast"
            data-archive-counter-inc="[data-arc-kpi='archived_total']">
        <?= csrf_field() ?>
        <input type="hidden" name="return_url" value="<?= esc($currentReturn, 'attr') ?>">
        <input type="hidden" name="entity_type" id="arcArchiveEntityType" value="">
        <input type="hidden" name="entity_id" id="arcArchiveEntityId" value="">
        <div class="modal-body">
          <p class="small mb-2">Target: <strong id="arcArchiveRefLabel">—</strong></p>
          <p class="small text-muted">This removes the record from active lists while preserving audit history and allowing later restoration.</p>
          <div class="mb-2">
            <label for="arcArchiveReason" class="form-label small fw-semibold">Archive reason <span class="text-danger">*</span></label>
            <textarea id="arcArchiveReason" name="reason" class="form-control form-control-sm" rows="3" minlength="5" maxlength="500" required
                      placeholder="e.g., Term ended and retention schedule applied"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm">Confirm Archive</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Restore Record Modal -->
<div class="modal fade" id="arcRestoreModal" tabindex="-1" aria-labelledby="arcRestoreTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fs-6 fw-bold" id="arcRestoreTitle">Restore Archived Record</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST"
            action="<?= esc(site_url('/admin/archive/records/restore'), 'attr') ?>"
            data-archive-async="restore"
            data-archive-modal="arcRestoreModal"
            data-archive-undo-url="<?= esc(site_url('/admin/archive/records/archive'), 'attr') ?>"
            data-archive-undo-label="Re-archive"
            data-archive-undo-reason="Re-archived from notification toast"
            data-archive-counter-dec="[data-arc-kpi='archived_total']"
            data-archive-counter-inc="[data-arc-kpi='restored_30d']">
        <?= csrf_field() ?>
        <input type="hidden" name="return_url" value="<?= esc($currentReturn, 'attr') ?>">
        <input type="hidden" name="entity_type" id="arcRestoreEntityType" value="">
        <input type="hidden" name="entity_id" id="arcRestoreEntityId" value="">
        <div class="modal-body">
          <p class="small mb-2">Target: <strong id="arcRestoreRefLabel">—</strong></p>
          <p class="small text-muted">Announcements return as drafts, carousel slides remain hidden, and BMG drums stay offline until re-verified.</p>
          <div class="mb-2">
            <label for="arcRestoreReason" class="form-label small fw-semibold">Restore reason <span class="text-danger">*</span></label>
            <textarea id="arcRestoreReason" name="reason" class="form-control form-control-sm" rows="3" minlength="3" maxlength="500" required
                      placeholder="e.g., Needed for follow-up review or audit inspection"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Restore Record</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Place Legal Hold Modal -->
<div class="modal fade" id="arcPlaceHoldModal" tabindex="-1" aria-labelledby="arcHoldTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fs-6 fw-bold" id="arcHoldTitle">Place Legal Hold</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="<?= esc(site_url('/admin/archive/holds/place'), 'attr') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="return_url" value="/admin/archive?tab=holds">
        <div class="modal-body">
          <div class="row g-2 mb-2">
            <div class="col-md-7">
              <label for="arcHoldEntityType" class="form-label small fw-semibold">Record type <span class="text-danger">*</span></label>
              <select id="arcHoldEntityType" name="entity_type" class="form-select form-select-sm" required>
                <option value="">Select type...</option>
                <?php foreach ($allowedTypes as $typeKey => $typeLabel): ?>
                  <option value="<?= esc($typeKey, 'attr') ?>"><?= esc($typeLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label for="arcHoldEntityId" class="form-label small fw-semibold">Record ID <span class="text-danger">*</span></label>
              <input id="arcHoldEntityId" type="number" name="entity_id" min="1" class="form-control form-control-sm" required placeholder="ID">
            </div>
          </div>
          <p class="small text-muted mb-2" id="arcHoldPreview" hidden>Selected: <strong id="arcHoldRefLabel"></strong></p>
          <div class="mb-2">
            <label for="arcHoldReferenceCode" class="form-label small fw-semibold">Docket / Memo Reference (optional)</label>
            <input id="arcHoldReferenceCode" type="text" name="reference_code" maxlength="64" class="form-control form-control-sm" placeholder="e.g., DPO-HOLD-2026-01">
          </div>
          <div class="mb-2">
            <label for="arcHoldReason" class="form-label small fw-semibold">Legal hold rationale <span class="text-danger">*</span></label>
            <textarea id="arcHoldReason" name="reason" class="form-control form-control-sm" rows="3" minlength="5" maxlength="1000" required
                      placeholder="Avoid patient names or diagnoses; reference case docket or official directive"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Place Legal Hold</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Lift Legal Hold Modal -->
<div class="modal fade" id="arcLiftHoldModal" tabindex="-1" aria-labelledby="arcLiftTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fs-6 fw-bold" id="arcLiftTitle">Lift Legal Hold</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" id="arcLiftHoldForm" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="return_url" value="/admin/archive?tab=holds">
        <div class="modal-body">
          <p class="small mb-2">Target: <strong id="arcLiftRefLabel">—</strong></p>
          <div class="mb-2">
            <label for="arcLiftReason" class="form-label small fw-semibold">Authorization / Lift Reason <span class="text-danger">*</span></label>
            <textarea id="arcLiftReason" name="lift_reason" class="form-control form-control-sm" rows="3" minlength="5" maxlength="500" required
                      placeholder="Document clearance reference before lifting this hold"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm">Lift Legal Hold</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if (!empty($scope['can_policies'])): ?>
<!-- Retention Policy Modal -->
<div class="modal fade" id="arcPolicyModal" tabindex="-1" aria-labelledby="arcPolicyModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fs-6 fw-bold" id="arcPolicyModalTitle">Configure Retention Policy</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="<?= esc(site_url('/admin/archive/policies/save'), 'attr') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="return_url" value="/admin/archive?tab=policies">
        <input type="hidden" name="policy_id" id="arcPolicyId" value="">
        <div class="modal-body">
          <div class="row g-2 mb-2">
            <div class="col-md-5">
              <label for="arcPolicyCode" class="form-label small fw-semibold">Policy Code <span class="text-danger">*</span></label>
              <input id="arcPolicyCode" type="text" name="policy_code" class="form-control form-control-sm" required maxlength="64" placeholder="RET-CLINIC-002">
            </div>
            <div class="col-md-7">
              <label for="arcPolicyTitle" class="form-label small fw-semibold">Title <span class="text-danger">*</span></label>
              <input id="arcPolicyTitle" type="text" name="title" class="form-control form-control-sm" required maxlength="255">
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-md-6">
              <label for="arcPolicyModule" class="form-label small fw-semibold">Module <span class="text-danger">*</span></label>
              <select id="arcPolicyModule" name="module" class="form-select form-select-sm" required>
                <option value="all">All modules</option>
                <?php foreach ($modules as $mKey => $mLabel): ?>
                  <option value="<?= esc($mKey, 'attr') ?>"><?= esc($mLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label for="arcPolicyEntity" class="form-label small fw-semibold">Entity Scope <span class="text-danger">*</span></label>
              <select id="arcPolicyEntity" name="entity_type" class="form-select form-select-sm" required>
                <option value="*">All entity types (*)</option>
                <?php foreach (\App\Services\Archive\ArchiveEntityRegistry::ENTITIES as $eKey => $eDef): ?>
                  <option value="<?= esc($eKey, 'attr') ?>"><?= esc($eDef['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-md-6">
              <label for="arcPolicyDuration" class="form-label small fw-semibold">Retention Months <span class="text-danger">*</span></label>
              <input id="arcPolicyDuration" type="number" name="duration_months" min="1" max="360" value="60" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-6">
              <label for="arcPolicyAuto" class="form-label small fw-semibold">Review After Months (optional)</label>
              <input id="arcPolicyAuto" type="number" name="auto_archive_after_months" min="1" max="360" class="form-control form-control-sm" placeholder="Optional">
            </div>
          </div>
          <div class="mb-2">
            <label for="arcPolicyBasis" class="form-label small fw-semibold">Legal / Institutional Basis <span class="text-danger">*</span></label>
            <input id="arcPolicyBasis" type="text" name="legal_basis" class="form-control form-control-sm" required maxlength="255">
          </div>
          <p class="small text-muted mb-0">Purge automation is permanently disabled; expired items remain recoverable for review.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Save Policy</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  if (window.__synapseArchiveBound) return;
  window.__synapseArchiveBound = true;
  var liftBase = <?= json_encode(rtrim(site_url('/admin/archive/holds'), '/') . '/') ?>;

  document.addEventListener('click', function (ev) {
    var archiveBtn = ev.target.closest('[data-arc-open-archive-modal]');
    if (archiveBtn) {
      window.__synapseArchiveActiveRow = archiveBtn.closest('tr');
      document.getElementById('arcArchiveEntityType').value = archiveBtn.getAttribute('data-arc-entity-type') || '';
      document.getElementById('arcArchiveEntityId').value = archiveBtn.getAttribute('data-arc-entity-id') || '';
      document.getElementById('arcArchiveRefLabel').textContent = archiveBtn.getAttribute('data-arc-record-ref') || 'Selected record';
      document.getElementById('arcArchiveReason').value = '';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('arcArchiveModal')).show();
      return;
    }

    var restoreBtn = ev.target.closest('[data-arc-open-restore-modal]');
    if (restoreBtn) {
      window.__synapseArchiveActiveRow = restoreBtn.closest('tr');
      document.getElementById('arcRestoreEntityType').value = restoreBtn.getAttribute('data-arc-entity-type') || '';
      document.getElementById('arcRestoreEntityId').value = restoreBtn.getAttribute('data-arc-entity-id') || '';
      document.getElementById('arcRestoreRefLabel').textContent = restoreBtn.getAttribute('data-arc-record-ref') || 'Selected record';
      document.getElementById('arcRestoreReason').value = '';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('arcRestoreModal')).show();
      return;
    }

    var holdBtn = ev.target.closest('[data-arc-open-hold-modal]');
    if (holdBtn) {
      var type = holdBtn.getAttribute('data-arc-entity-type') || '';
      var id = holdBtn.getAttribute('data-arc-entity-id') || '';
      var ref = holdBtn.getAttribute('data-arc-record-ref') || '';
      document.getElementById('arcHoldEntityType').value = type;
      document.getElementById('arcHoldEntityId').value = id;
      var preview = document.getElementById('arcHoldPreview');
      if (ref) {
        document.getElementById('arcHoldRefLabel').textContent = ref;
        preview.hidden = false;
      } else {
        preview.hidden = true;
      }
      document.getElementById('arcHoldReason').value = '';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('arcPlaceHoldModal')).show();
      return;
    }

    var liftBtn = ev.target.closest('[data-arc-open-lift-modal]');
    if (liftBtn) {
      var holdId = liftBtn.getAttribute('data-arc-hold-id') || '0';
      document.getElementById('arcLiftHoldForm').setAttribute('action', liftBase + encodeURIComponent(holdId) + '/lift');
      document.getElementById('arcLiftRefLabel').textContent = liftBtn.getAttribute('data-arc-record-ref') || ('Hold #' + holdId);
      document.getElementById('arcLiftReason').value = '';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('arcLiftHoldModal')).show();
      return;
    }

    var policyBtn = ev.target.closest('[data-arc-open-policy-modal]');
    if (policyBtn && document.getElementById('arcPolicyModal')) {
      document.getElementById('arcPolicyId').value = policyBtn.getAttribute('data-arc-policy-id') || '';
      document.getElementById('arcPolicyCode').value = policyBtn.getAttribute('data-arc-policy-code') || '';
      document.getElementById('arcPolicyModule').value = policyBtn.getAttribute('data-arc-policy-module') || 'all';
      document.getElementById('arcPolicyEntity').value = policyBtn.getAttribute('data-arc-policy-entity') || '*';
      document.getElementById('arcPolicyTitle').value = policyBtn.getAttribute('data-arc-policy-title') || '';
      document.getElementById('arcPolicyDuration').value = policyBtn.getAttribute('data-arc-policy-duration') || '60';
      document.getElementById('arcPolicyAuto').value = policyBtn.getAttribute('data-arc-policy-auto') || '';
      document.getElementById('arcPolicyBasis').value = policyBtn.getAttribute('data-arc-policy-basis') || '';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('arcPolicyModal')).show();
    }
  });
})();
</script>

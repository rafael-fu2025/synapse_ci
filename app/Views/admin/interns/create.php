<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
$allowedUnits = $allowedUnits ?? [];
$unitKeys = array_keys($allowedUnits);
$defaultUnit = $unitKeys[0] ?? 'clinic';
$supervisorsByUnit = $supervisors ?? [];
$permissions = $permissions ?? [];
?>
<div class="adm-modal" data-intern-form-modal>
  <div class="adm-caution" role="note">
    <i class="bi bi-mortarboard" aria-hidden="true"></i>
    <div>Student intern placements grant time-bounded staff workstation access to active enrolled students while keeping their personal student identity intact. Interns cannot access their own clinical or guidance records on staff workstations.</div>
  </div>

  <form action="<?= esc($baseUrl . '/store') ?>" method="POST" data-modal-submit data-confirm="Assign this student intern placement and activate their supervised workstation permissions?" data-confirm-title="Confirm intern placement" data-confirm-label="Assign intern" data-confirm-tone="primary">
    <?= csrf_field() ?>
    <input type="hidden" id="intern-selected-user-id" name="user_id" value="">

    <div class="adm-field">
      <label class="adm-label" for="intern-student-lookup">Search enrolled student</label>
      <div class="adm-intern-picker" data-intern-student-picker data-endpoint="<?= esc($baseUrl . '/students') ?>">
        <input id="intern-student-lookup"
               name="student_query"
               type="search"
               class="form-control"
               autocomplete="off"
               maxlength="100"
               placeholder="Type at least 2 characters (name or student ID)..."
               aria-describedby="intern-student-help">
        <div class="adm-intern-selected-pill" id="intern-selected-summary" data-intern-selected-summary hidden></div>
        <div class="adm-intern-picker-results" data-intern-picker-results hidden role="listbox" aria-label="Eligible students"></div>
      </div>
      <small id="intern-student-help" class="adm-help">Student must have an active SYNAPSE student account with no open internship placement.</small>
    </div>

    <div class="adm-field">
      <?php if (count($allowedUnits) === 1): ?>
        <label class="adm-label" for="intern-unit-readonly">Assigned unit</label>
        <input type="hidden" id="intern-unit" name="unit" value="<?= esc($defaultUnit) ?>">
        <input type="text" id="intern-unit-readonly" name="unit_display" class="form-control" value="<?= esc($allowedUnits[$defaultUnit]) ?>" readonly>
      <?php else: ?>
        <label class="adm-label" for="intern-unit">Assigned unit</label>
        <select id="intern-unit" name="unit" class="form-select" required data-intern-unit-select>
          <?php foreach ($allowedUnits as $uKey => $uLabel): ?>
            <option value="<?= esc($uKey) ?>" <?= $uKey === $defaultUnit ? 'selected' : '' ?>><?= esc($uLabel) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>

    <div class="adm-field">
      <label class="adm-label" for="intern-supervisor">Assigned supervising staff</label>
      <select id="intern-supervisor" name="supervisor_user_id" class="form-select" required data-intern-supervisor-select>
        <option value="">Select active unit supervisor</option>
        <?php foreach ($supervisorsByUnit as $uKey => $list): ?>
          <?php foreach ($list as $sup): ?>
            <option value="<?= (int) $sup['user_id'] ?>" data-unit="<?= esc($uKey) ?>" <?= ($uKey !== $defaultUnit) ? 'hidden disabled' : '' ?>>
              <?= esc($sup['name']) ?> (<?= esc(ucwords(str_replace('_', ' ', $sup['role']))) ?>)
            </option>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </select>
      <small class="adm-help">For Guidance, this supervisor automatically becomes the mandatory co-signer (`counselor_associate`).</small>
    </div>

    <div class="row g-2">
      <div class="col-md-6">
        <div class="adm-field">
          <label class="adm-label" for="intern-starts-at">Starts at (Asia/Manila)</label>
          <input id="intern-starts-at" name="starts_at" type="datetime-local" class="form-control" value="<?= esc($defaultStart ?? '') ?>" required>
        </div>
      </div>
      <div class="col-md-6">
        <div class="adm-field">
          <label class="adm-label" for="intern-ends-at">Ends at (Asia/Manila)</label>
          <input id="intern-ends-at" name="ends_at" type="datetime-local" class="form-control" value="<?= esc($defaultEnd ?? '') ?>" required>
        </div>
      </div>
    </div>

    <div class="adm-field">
      <label class="adm-label" for="intern-academic-term">Academic term / rotation cohort (optional)</label>
      <input id="intern-academic-term" name="academic_term" type="text" class="form-control" maxlength="64" placeholder="e.g. AY 2026-2027 1st Sem · Batch A">
    </div>

    <div class="adm-field">
      <label class="adm-label" for="intern-reason">Assignment endorsement / audit reason</label>
      <textarea id="intern-reason" name="reason" class="form-control adm-textarea" maxlength="500" rows="3" required placeholder="Brief endorsement or practicum memo reference for the audit ledger"></textarea>
    </div>

    <details class="adm-permissions">
      <summary>Review scoped intern role permissions</summary>
      <div class="adm-permissions-body">
        <p class="adm-permissions-note">Permissions are enforced per request against the active placement window. Guidance interns always require supervisor co-sign to finalize clinical records; BMG interns cannot void batches or configure drums.</p>
        <?php foreach ($permissions as $permRole => $grants): ?>
          <section class="adm-perm-group">
            <h3 class="h6"><?= esc(\App\Services\StudentInternService::ROLE_LABELS[$permRole] ?? $permRole) ?></h3>
            <ul class="adm-perm-list">
              <?php foreach ($grants as $grant): ?>
                <li><?= esc($grant['name']) ?> <code><?= esc($grant['code']) ?></code></li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endforeach; ?>
      </div>
    </details>

    <div class="form-actions adm-modal-actions">
      <a class="btn btn-outline-secondary adm-btn" href="<?= esc($baseUrl) ?>" data-app-modal-close>Cancel</a>
      <button class="btn btn-primary adm-btn" type="submit">Assign Intern Placement</button>
    </div>
  </form>
</div>
<?= $this->endSection() ?>

<?php if (service('request')->getGet('fragment') !== '1'): ?>
<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/admin-interns.js')) ?>"></script>
<?= $this->endSection() ?>
<?php endif; ?>

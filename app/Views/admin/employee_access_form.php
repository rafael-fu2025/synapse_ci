<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
$empStatus = (string) ($employee['status'] ?? 'inactive');
$empStatusTone = match (strtolower($empStatus)) {
    'active' => 'is-active',
    'suspended', 'locked', 'disabled' => 'is-suspended',
    default => 'is-inactive',
};
$empRoleLabel = \App\Services\EmployeeAccessService::ROLES[$employee['role']] ?? $employee['role'];
?>
<div class="adm-modal">
  <div class="adm-identity">
    <div class="adm-identity-top">
      <h2 class="adm-identity-name"><?= esc(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?></h2>
      <span class="adm-status <?= esc($empStatusTone) ?>"><?= esc(ucfirst($empStatus)) ?></span>
    </div>
    <p class="adm-identity-sub">
      <span class="adm-identity-id"><?= esc($employee['mis_id'] ?: $employee['username']) ?></span>
      <span aria-hidden="true"> · </span>
      <span><?= esc($employee['department'] ?: 'Department not provided') ?></span>
    </p>
    <div class="adm-identity-meta">
      <span>Current role: <strong><?= esc($empRoleLabel) ?></strong></span>
      <span>Account: <strong><?= esc($empStatus) ?></strong></span>
      <span>Employment: <strong><?= esc($employee['employment_status'] ?: 'Unknown') ?></strong></span>
    </div>
  </div>

  <div class="adm-caution" role="note">
    <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
    <div>Changing the role replaces existing workstation access and signs the employee out of all Synapse hosts on their next request. This does not erase records or change MIS credentials. Only active employees with active accounts can receive privileged access.</div>
  </div>

  <?php if ((int) $employee['id'] !== $actor['id']): ?>
    <form action="<?= esc($baseUrl . '/' . (int) $employee['id'] . '/update') ?>" method="POST" data-modal-submit data-confirm="Replace this employee's access and revoke their existing sessions? Review the selected role and its permissions first." data-confirm-title="Change employee access" data-confirm-label="Change access" data-confirm-tone="warning">
      <?= csrf_field() ?>
      <input type="hidden" name="version" value="<?= (int) $employee['auth_version'] ?>">

      <div class="adm-field">
        <label class="adm-label" for="employee-role">New role</label>
        <select id="employee-role" name="role" class="form-select" required>
          <option value="">Choose a different role</option>
          <?php foreach ($options as $value => $label): if ($value === $employee['role']) continue; ?>
            <option value="<?= esc($value) ?>"><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="adm-field">
        <label for="access-reason" class="adm-label">Reason for access change</label>
        <textarea id="access-reason" name="reason" class="form-control adm-textarea" maxlength="500" rows="3" required aria-describedby="access-reason-help" placeholder="Brief administrative reason for the audit ledger"></textarea>
        <small id="access-reason-help" class="adm-help">Recorded in the audit ledger. Do not include medical or counselling information.</small>
      </div>

      <details class="adm-permissions">
        <summary>Review role permissions before saving</summary>
        <div class="adm-permissions-body">
          <p class="adm-permissions-note">These are the current database grants. Record ownership and department restrictions still apply. Superadmin has special platform-wide authority; this is not limited to the listed grants. Hardware commissioning remains separately controlled.</p>
          <?php foreach ($permissions as $permissionRole => $grants): ?>
            <section class="adm-perm-group">
              <h3 class="h6"><?= esc(\App\Services\EmployeeAccessService::ROLES[$permissionRole] ?? \App\Services\EmployeeAccessService::CATALOGUE_LABELS[$permissionRole] ?? $permissionRole) ?></h3>
              <ul class="adm-perm-list">
                <?php foreach ($grants as $grant): ?>
                  <li><?= esc($grant['name']) ?> <code><?= esc($grant['code']) ?></code></li>
                <?php endforeach; ?>
                <?php if ($grants === []): ?>
                  <li>No explicit permissions configured.</li>
                <?php endif; ?>
              </ul>
            </section>
          <?php endforeach; ?>
        </div>
      </details>

      <div class="form-actions adm-modal-actions">
        <a class="btn btn-outline-secondary adm-btn" href="<?= esc($baseUrl) ?>" data-app-modal-close>Cancel</a>
        <button class="btn btn-primary adm-btn" type="submit">Save access change</button>
      </div>
    </form>
  <?php else: ?>
    <p class="text-muted mb-0">You cannot change your own access.</p>
  <?php endif; ?>

  <p class="adm-modal-note">Appointment providers need separate department enrollment and availability. Multi-workstation roles, account activation and MIS profile edits are not managed here.</p>
</div>
<?= $this->endSection() ?>

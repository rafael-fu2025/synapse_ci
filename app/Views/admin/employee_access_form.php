<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>

<h2 class="h5"><?= esc(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?></h2>
<p class="text-muted"><?= esc($employee['mis_id'] ?: $employee['username']) ?> · <?= esc($employee['department'] ?: 'Department not provided') ?></p>
<p>Current role: <strong><?= esc(\App\Services\EmployeeAccessService::ROLES[$employee['role']] ?? $employee['role']) ?></strong><br>Account: <?= esc($employee['status']) ?> · Employment: <?= esc($employee['employment_status'] ?: 'Unknown') ?></p>
<div class="alert alert-warning">Changing the role replaces existing workstation access and signs the employee out of all Synapse hosts on their next request. This does not erase records or change MIS credentials. Only active employees with active accounts can receive privileged access.</div>
<?php if ((int) $employee['id'] !== $actor['id']): ?>
<form action="<?= esc($baseUrl . '/' . (int) $employee['id'] . '/update') ?>" method="POST" data-modal-submit data-confirm="Replace this employee's access and revoke their existing sessions? Review the selected role and its permissions first." data-confirm-title="Change employee access" data-confirm-label="Change access" data-confirm-tone="warning">
<?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $employee['auth_version'] ?>">
<div class="mb-3"><label class="form-label" for="employee-role">New role</label><select id="employee-role" name="role" class="form-select" required>
<option value="">Choose a different role</option>
<?php foreach ($options as $value => $label): if ($value === $employee['role']) continue; ?>
<option value="<?= esc($value) ?>"><?= esc($label) ?></option>
<?php endforeach; ?></select></div>
<div class="mb-3"><label for="access-reason" class="form-label">Reason for access change</label><textarea id="access-reason" name="reason" class="form-control" maxlength="500" rows="3" required aria-describedby="access-reason-help"></textarea><small id="access-reason-help" class="text-muted">Recorded in the audit ledger. Do not include medical or counselling information.</small></div>
<details class="mb-3"><summary>Review role permissions before saving</summary>
<p class="small text-muted mt-2">These are the current database grants. Record ownership and department restrictions still apply. Superadmin has special platform-wide authority; this is not limited to the listed grants. Hardware commissioning remains separately controlled.</p>
<?php foreach ($permissions as $permissionRole => $grants): ?>
<section class="mt-3"><h3 class="h6"><?= esc(\App\Services\EmployeeAccessService::ROLES[$permissionRole] ?? $permissionRole) ?></h3><ul class="small">
<?php foreach ($grants as $grant): ?><li><?= esc($grant['name']) ?> <code><?= esc($grant['code']) ?></code></li><?php endforeach; ?>
<?php if ($grants === []): ?><li>No explicit permissions configured.</li><?php endif; ?>
</ul></section><?php endforeach; ?></details>
<div class="form-actions"><a class="btn btn-outline-secondary" href="<?= esc($baseUrl) ?>" data-app-modal-close>Cancel</a><button class="btn btn-primary" type="submit">Save access change</button></div>
</form>
<?php else: ?><p class="text-muted">You cannot change your own access.</p><?php endif; ?>
<p class="small text-muted mt-3">Appointment providers need separate department enrollment and availability. Multi-workstation roles, account activation and MIS profile edits are not managed here.</p>
<?= $this->endSection() ?>

<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="alert alert-info"><strong>Onboard real employees:</strong> ask them to sign in through MIS on the main portal first, then find their account here. This page changes Synapse access only—not MIS identities, passwords, employment details or appointment-provider enrollment.</div>
<?php if ($actor['role'] !== 'superadmin'): ?>
<p class="text-muted">You can assign or revoke the staff role for employees in your department only. Administrator, supervisor and cross-department appointments require a superadmin.</p>
<?php else: ?>
<p class="text-muted">One role per employee account. Replacing a role removes its previous workstation access. Returning an account to Employee revokes workstation access but preserves its records.</p>
<?php endif; ?>
<section data-live-table data-endpoint="<?= esc($baseUrl) ?>">
<div class="card"><div class="card-body pt-3">
<form data-table-filters action="<?= esc($baseUrl) ?>" method="GET" class="row g-2 mb-3" role="search">
<div class="col-sm-6"><label for="employee-search" class="form-label">Find an employee</label><input id="employee-search" type="search" name="q" maxlength="100" value="<?= esc($search) ?>" class="form-control" placeholder="Name, employee ID or username"></div>
<div class="col-sm-2"><label for="employee-per-page" class="form-label">Per page</label><select id="employee-per-page" name="per_page" class="form-select">
<?php foreach ([25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= (int) ($perPage ?? 25) === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
</select></div>
<div class="col-sm-4 d-flex align-items-end gap-2"><button class="btn btn-primary" type="submit">Search</button><a class="btn btn-outline-secondary" href="<?= esc($baseUrl) ?>" data-table-reset>Clear</a></div>
</form>
<p data-table-status role="status" aria-live="polite" class="small text-muted"></p>
<div data-table-error class="alert alert-danger" role="alert" hidden>
<span data-table-error-message></span>
<button data-table-retry type="button" class="btn btn-outline-danger btn-sm ms-2">Retry</button>
</div>
<div data-table-results id="employeeAccessResults">
<?= view('admin/_employee_access_results', [
    'employees' => $employees ?? [], 'pagination' => $pagination ?? null,
    'filters' => ['q' => $search ?? ''], 'search' => $search ?? '',
    'actor' => $actor, 'baseUrl' => $baseUrl,
], ['saveData' => false]) ?>
</div>
</div></div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/live-table.js')) ?>"></script>
<?= $this->endSection() ?>

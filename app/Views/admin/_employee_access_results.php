<?php
$employeeRows = $employees ?? [];
$employeeFiltered = (int) ($pagination['filtered'] ?? count($employeeRows));
?>
<p class="small text-muted"><?= number_format($employeeFiltered) ?> employee account(s) shown<?= ($search ?? '') !== '' ? ' for this search' : ' in your management scope' ?></p>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Employee</th><th>Department</th><th>Current role</th><th>Status</th><th>Access</th></tr></thead><tbody>
<?php foreach ($employeeRows as $employee): ?>
<tr><td><strong><?= esc(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?></strong><small class="d-block text-muted"><?= esc($employee['mis_id'] ?: $employee['username']) ?></small></td>
<td><?= esc($employee['department'] ?: 'Not provided by MIS') ?></td><td><?= esc(\App\Services\EmployeeAccessService::ROLES[$employee['role']] ?? $employee['role']) ?></td>
<td><span class="badge bg-secondary"><?= esc(ucfirst($employee['status'])) ?></span><small class="d-block">Employment: <?= esc($employee['employment_status'] ?: 'Unknown') ?></small></td>
<td><?php if ((int) $employee['id'] !== $actor['id']): ?><a class="btn btn-outline-primary btn-sm" href="<?= esc($baseUrl . '/' . (int) $employee['id'] . '/edit') ?>" data-app-modal-open>Manage access</a><?php else: ?><span class="text-muted small">Your account (read only)</span><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if ($employeeRows === []): ?><tr><td colspan="5" class="text-muted">No matching employee accounts. The employee may need to sign in through MIS first, or may be outside your management scope.</td></tr><?php endif; ?>
</tbody></table></div>
<?= view('partials/table_pagination', [
    'pagination' => $pagination ?? null, 'rowCount' => count($employeeRows),
    'filters' => ['q' => $search ?? ''],
    'baseUrl' => $baseUrl ?? site_url('admin/access'),
    'totalLabel' => 'employee accounts', 'paginationLabel' => 'Employee account pages',
], ['saveData' => false]) ?>

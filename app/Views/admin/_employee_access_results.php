<?php
$employeeRows = $employees ?? [];
$employeeFiltered = (int) ($pagination['filtered'] ?? count($employeeRows));
$statusTone = static fn (string $status): string => match (strtolower($status)) {
    'active' => 'is-active',
    'suspended', 'locked', 'disabled' => 'is-suspended',
    default => 'is-inactive',
};
?>
<div class="adm-results-meta">
  <?= number_format($employeeFiltered) ?> employee account(s) shown<?= ($search ?? '') !== '' ? ' for this search' : ' in your management scope' ?>
</div>
<div class="adm-table-wrap">
  <table class="adm-table">
    <thead>
      <tr>
        <th>Employee</th>
        <th>Department</th>
        <th>Current role</th>
        <th>Status</th>
        <th class="text-end">Access</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($employeeRows as $employee): ?>
        <?php
        $empStatus = (string) ($employee['status'] ?? 'inactive');
        $empDept = trim((string) ($employee['department'] ?? ''));
        ?>
        <tr>
          <td>
            <span class="adm-emp-name"><?= esc(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?></span>
            <span class="adm-emp-id"><?= esc($employee['mis_id'] ?: $employee['username']) ?></span>
          </td>
          <td>
            <span class="adm-dept <?= $empDept === '' ? 'is-missing' : '' ?>"><?= esc($empDept !== '' ? $empDept : 'Not provided by MIS') ?></span>
          </td>
          <td>
            <span class="adm-role"><?= esc(\App\Services\EmployeeAccessService::ROLES[$employee['role']] ?? $employee['role']) ?></span>
          </td>
          <td>
            <span class="adm-status <?= esc($statusTone($empStatus)) ?>"><?= esc(ucfirst($empStatus)) ?></span>
            <span class="adm-employment">Employment: <?= esc($employee['employment_status'] ?: 'Unknown') ?></span>
          </td>
          <td class="text-end">
            <?php if ((int) $employee['id'] !== $actor['id']): ?>
              <a class="adm-row-btn" href="<?= esc($baseUrl . '/' . (int) $employee['id'] . '/edit') ?>" data-app-modal-open>Manage access</a>
            <?php else: ?>
              <span class="adm-readonly-note">Your account (read only)</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($employeeRows === []): ?>
        <tr>
          <td colspan="5" class="adm-empty-cell">No matching employee accounts. The employee may need to sign in through MIS first, or may be outside your management scope.</td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<div class="adm-pagination-band">
  <?= view('partials/table_pagination', [
      'pagination' => $pagination ?? null, 'rowCount' => count($employeeRows),
      'filters' => ['q' => $search ?? ''],
      'baseUrl' => $baseUrl ?? site_url('admin/access'),
      'totalLabel' => 'employee accounts', 'paginationLabel' => 'Employee account pages',
  ], ['saveData' => false]) ?>
</div>

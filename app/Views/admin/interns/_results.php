<?php
$rows = $placements ?? [];
$filteredCount = (int) ($pagination['filtered'] ?? count($rows));
$statusTone = static fn (string $status): string => match (strtolower($status)) {
    'active'    => 'is-active',
    'scheduled' => 'is-scheduled',
    'revoked'   => 'is-suspended',
    default     => 'is-inactive',
};
$formatManila = static function (?string $dt): string {
    if ($dt === null || trim($dt) === '') {
        return '—';
    }
    try {
        return (new \DateTimeImmutable($dt, new \DateTimeZone('Asia/Manila')))->format('M j, Y g:i A');
    } catch (\Throwable) {
        return $dt;
    }
};
?>
<div class="adm-results-meta">
  <?= number_format($filteredCount) ?> student intern placement(s) shown<?= ($search ?? '') !== '' ? ' for this search' : ' in your unit scope' ?>
</div>
<div class="adm-table-wrap">
  <table class="adm-table">
    <thead>
      <tr>
        <th>Student Intern</th>
        <th>Unit &amp; Role</th>
        <th>Assigned Supervisor</th>
        <th>Placement Window (Asia/Manila)</th>
        <th>Status</th>
        <th class="text-end">Manage</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <?php
        $status = (string) ($row['status'] ?? 'expired');
        $studentName = trim((string) ($row['student_first_name'] ?? '') . ' ' . (string) ($row['student_last_name'] ?? ''));
        $studentId = (string) ($row['student_mis_id'] ?: $row['student_username']);
        $programMeta = trim((string) ($row['student_program'] ?? '') . (!empty($row['student_year_level']) ? ' · Year ' . $row['student_year_level'] : ''));
        $supervisorName = trim((string) ($row['supervisor_first_name'] ?? '') . ' ' . (string) ($row['supervisor_last_name'] ?? ''));
        if ($supervisorName === '') {
            $supervisorName = (string) ($row['supervisor_username'] ?? '—');
        }
        $unitLabel = \App\Services\StudentInternService::UNITS[$row['unit']] ?? $row['unit'];
        $roleLabel = \App\Services\StudentInternService::ROLE_LABELS[$row['intern_role']] ?? $row['intern_role'];
        $isOpen = in_array($status, ['active', 'scheduled'], true);
        ?>
        <tr>
          <td>
            <span class="adm-emp-name"><?= esc($studentName) ?></span>
            <span class="adm-emp-id"><?= esc($studentId) ?><?= $programMeta !== '' ? ' · ' . esc($programMeta) : '' ?></span>
          </td>
          <td>
            <span class="adm-role"><?= esc($unitLabel) ?></span>
            <span class="adm-employment"><?= esc($row['intern_role']) ?><?= !empty($row['academic_term']) ? ' · ' . esc($row['academic_term']) : '' ?></span>
          </td>
          <td>
            <span class="adm-dept"><?= esc($supervisorName) ?></span>
            <?php if ($row['unit'] === 'guidance'): ?>
              <span class="adm-employment">Mandatory Co-Sign</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="adm-dept"><?= esc($formatManila($row['starts_at'] ?? null)) ?></span>
            <span class="adm-employment">Until <?= esc($formatManila($row['ends_at'] ?? null)) ?></span>
          </td>
          <td>
            <span class="adm-status <?= esc($statusTone($status)) ?>"><?= esc(ucfirst($status)) ?></span>
            <?php if ($status === 'revoked' && !empty($row['revoke_reason'])): ?>
              <span class="adm-employment" title="<?= esc($row['revoke_reason']) ?>">Revoked early</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <a class="adm-row-btn" href="<?= esc($baseUrl . '/' . (int) $row['id'] . '/edit') ?>" data-app-modal-open>
              <?= $isOpen ? 'Manage' : 'Details' ?>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?>
        <tr>
          <td colspan="6" class="adm-empty-cell">No student intern placements match the current filters. Use <strong>Assign Student Intern</strong> to place an active enrolled student under unit supervision.</td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<div class="adm-pagination-band">
  <?= view('partials/table_pagination', [
      'pagination'      => $pagination ?? null,
      'rowCount'        => count($rows),
      'filters'         => ['q' => $search ?? '', 'unit' => $unitFilter ?? 'all', 'status' => $statusFilter ?? 'all'],
      'baseUrl'         => $baseUrl ?? site_url('admin/interns'),
      'totalLabel'      => 'intern placements',
      'paginationLabel' => 'Student intern placement pages',
  ], ['saveData' => false]) ?>
</div>

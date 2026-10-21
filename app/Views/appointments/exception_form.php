<?php
$row = $exception ?? null;
$editing = $row !== null;
$prefix = 'exception' . ($row['id'] ?? 'New');
$type = $editing && empty($row['is_unavailable']) ? 'custom_hours' : 'leave';
?>
<form action="<?= esc(site_url('/' . $unit . '/appointments/availability/exceptions')) ?>" method="POST" data-availability-save x-data="{ exceptionType: '<?= $type ?>' }">
  <?= csrf_field() ?>
  <?= view('appointments/_availability_context', ['workspaceContext' => $workspaceContext ?? \App\Services\Scheduling\AvailabilityWorkspaceContext::DEFAULTS, 'returnSection' => 'exceptions']) ?>
  <input type="hidden" name="workspace_form" value="exception">
  <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
  <?php if ($editing): ?>
    <input type="hidden" name="token" value="<?= esc(\App\Models\AvailabilityExceptionModel::snapshotToken($row)) ?>">
    <input type="hidden" name="staff_assignment_id" value="<?= (int) $row['staff_assignment_id'] ?>">
    <input type="hidden" name="exception_date" value="<?= esc($row['exception_date']) ?>">
    <p class="fw-semibold"><?= esc($row['provider_name']) ?> · <?= esc($row['exception_date']) ?> (Manila)</p>
  <?php else: ?>
    <div class="mb-3"><label for="<?= $prefix ?>Provider" class="form-label">Staff member</label><select id="<?= $prefix ?>Provider" name="staff_assignment_id" class="form-select" required><option value="">Choose active enrolled staff</option><?php foreach ($providers as $provider): ?><option value="<?= (int) $provider['id'] ?>" <?= (string) $provider['id'] === ($workspaceContext['provider'] ?? '') ? 'selected' : '' ?>><?= esc($provider['name']) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label for="<?= $prefix ?>DateDisplay" class="form-label">Date (Manila)</label><input id="<?= $prefix ?>DateDisplay" type="text" class="form-control" data-datepicker data-target="#<?= $prefix ?>Date" data-min="<?= \App\Services\Scheduling\SchedulingClock::today() ?>" autocomplete="off" placeholder="DD/MM/YYYY" required><input id="<?= $prefix ?>Date" name="exception_date" type="hidden" value=""></div>
  <?php endif; ?>
  <div class="mb-3"><label for="<?= $prefix ?>Type" class="form-label">Exception</label><select id="<?= $prefix ?>Type" name="exception_type" class="form-select" x-model="exceptionType">
    <?php foreach (['leave' => 'Leave / full day unavailable', 'closed_day' => 'Closed day for this staff member', 'custom_hours' => 'Custom hours (30-minute slots)'] as $value => $label): ?><option value="<?= $value ?>" <?= $type === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?>
  </select></div>
  <template x-if="exceptionType === 'custom_hours'"><div class="row g-3 mb-3">
    <div class="col-sm-6"><label for="<?= $prefix ?>Start" class="form-label">Custom start</label><?= view('partials/schedule_time', ['id' => $prefix . 'Start', 'name' => 'custom_start_time', 'label' => 'Custom start', 'value' => substr((string) ($row['custom_start_time'] ?? ''), 0, 5)]) ?></div>
    <div class="col-sm-6"><label for="<?= $prefix ?>End" class="form-label">Custom end</label><?= view('partials/schedule_time', ['id' => $prefix . 'End', 'name' => 'custom_end_time', 'label' => 'Custom end', 'value' => substr((string) ($row['custom_end_time'] ?? ''), 0, 5)]) ?></div>
  </div></template>
  <div class="mb-4"><label for="<?= $prefix ?>Reason" class="form-label">Reason (record leave or closure here)</label><input id="<?= $prefix ?>Reason" name="reason" class="form-control" maxlength="255" required value="<?= esc($row['reason'] ?? '') ?>"></div>
  <div class="av-form-footer justify-content-end"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save exception</button></div>
</form>
<?php if ($editing): ?>
<form action="<?= esc(site_url('/' . $unit . '/appointments/availability/exceptions/remove')) ?>" method="POST" data-availability-save class="border-top pt-3 mt-3" data-confirm="Remove this date-specific exception? Existing appointments will not be cancelled or moved." data-confirm-title="Remove availability exception" data-confirm-label="Remove exception" data-confirm-tone="danger">
  <?= csrf_field() ?>
  <?= view('appointments/_availability_context', ['workspaceContext' => $workspaceContext ?? \App\Services\Scheduling\AvailabilityWorkspaceContext::DEFAULTS, 'returnSection' => 'exceptions']) ?>
  <input type="hidden" name="workspace_form" value="exception-remove">
  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
  <input type="hidden" name="token" value="<?= esc(\App\Models\AvailabilityExceptionModel::snapshotToken($row)) ?>">
  <input type="hidden" name="staff_assignment_id" value="<?= (int) $row['staff_assignment_id'] ?>">
  <input type="hidden" name="exception_date" value="<?= esc($row['exception_date']) ?>">
  <p class="small text-muted">Remove the exception for <?= esc($row['provider_name']) ?> on <?= esc($row['exception_date']) ?>. The server checks this record has not changed.</p>
  <button type="submit" class="btn btn-outline-danger btn-sm">Remove exception</button>
</form>
<?php endif; ?>

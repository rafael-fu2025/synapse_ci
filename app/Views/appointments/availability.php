<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary btn-sm av-back-link"><i class="bi bi-arrow-left me-2" aria-hidden="true"></i>Appointments</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$workspaceContext = $workspaceContext ?? \App\Services\Scheduling\AvailabilityWorkspaceContext::DEFAULTS;
$base = site_url('/' . $unit . '/appointments/availability');
$matches = static fn (string $name): bool => $workspaceContext['q'] === '' || mb_stripos($name, $workspaceContext['q']) !== false;
$allStaff = $departmentStaff ?? [];
$departmentStaff = array_values(array_filter($allStaff, static fn ($staff) => $matches($staff['name']) && ($workspaceContext['provider'] === '' || (string) ($staff['staff_assignment_id'] ?? '') === $workspaceContext['provider'])));
$windows = array_values(array_filter($availability, static fn ($window) => $matches($window['provider_name'] ?? $window['username'] ?? '') && ($workspaceContext['provider'] === '' || (string) ($window['staff_assignment_id'] ?? '') === $workspaceContext['provider'])));
$visibleExceptions = array_values(array_filter($exceptions ?? [], static fn ($row) => $matches($row['provider_name']) && ($workspaceContext['provider'] === '' || (string) $row['staff_assignment_id'] === $workspaceContext['provider'])));
$eligibleCount = count(array_filter($departmentStaff, static fn ($staff) => $staff['eligible']));
$enrollmentCount = count(array_filter($departmentStaff, static fn ($staff) => $staff['can_enroll']));
$section = $workspaceContext['section'];
$sectionUrl = static fn (string $section): string => $base . '?' . \App\Services\Scheduling\AvailabilityWorkspaceContext::query(array_replace($workspaceContext, ['section' => $section]));
$selectedWindows = array_values(array_filter($availability, static fn ($row) => (string) ($row['staff_assignment_id'] ?? '') === $workspaceContext['provider'] && (int) $row['day_of_week'] === $workspaceContext['day']));
$selectedWindow = count($selectedWindows) === 1 ? $selectedWindows[0] : [];
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
?>
<section id="availability-workspace" class="availability-workspace" data-availability-workspace data-workspace-url="<?= esc($base) ?>" aria-label="<?= esc(ucfirst($unit)) ?> provider availability">
  <p class="text-muted mb-4">Manage <?= $unit === 'clinic' ? 'Clinic' : 'Guidance' ?> staff enrollment, recurring weekly hours, and date-specific exceptions. All scheduling times use <strong>Asia/Manila</strong>.</p>
  <div class="card"><div class="card-body p-3 p-lg-4">
    <form method="get" action="<?= esc($base) ?>" class="row g-3 align-items-end" role="search" aria-label="Filter availability" data-availability-filters>
      <input type="hidden" name="section" value="<?= esc($section) ?>"><input type="hidden" name="day" value="<?= $workspaceContext['day'] ?>">
      <div class="col-lg-5"><label for="workspaceSearch" class="form-label">Find staff</label><input type="search" id="workspaceSearch" name="q" class="form-control" maxlength="120" value="<?= esc($workspaceContext['q']) ?>" placeholder="Search by staff name"></div>
      <div class="col-lg-4"><label for="workspaceProvider" class="form-label">Provider</label><select id="workspaceProvider" name="provider" class="form-select"><option value="">All enrolled providers</option><?php foreach ($providers as $provider): ?><option value="<?= (int) $provider['id'] ?>" <?= (string) $provider['id'] === $workspaceContext['provider'] ? 'selected' : '' ?>><?= esc($provider['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-lg-3 d-flex gap-2"><button class="btn btn-primary" type="submit" data-availability-apply>Apply filters</button><a class="btn btn-outline-secondary" href="<?= esc($base . '?section=' . $section) ?>" data-availability-reset>Reset</a></div>
    </form>
  </div></div>
  <p class="small text-muted" data-availability-filter-status role="status" aria-live="polite">Choose a staff name or provider to narrow this workspace.</p>
  <div class="alert alert-danger" data-availability-filter-error role="alert" hidden><span data-availability-filter-error-text></span> <button type="button" class="btn btn-outline-secondary btn-sm ms-2" data-availability-filter-retry>Retry</button></div>
  <div data-availability-results>
  <nav class="av-section-nav" aria-label="Availability sections">
    <?php foreach (['enrollment' => 'Department setup', 'weekly' => 'Weekly schedules', 'exceptions' => 'Leave & exceptions'] as $key => $label): ?><a class="av-section-link <?= $section === $key ? 'is-active' : '' ?>" href="<?= esc($sectionUrl($key)) ?>" <?= $section === $key ? 'aria-current="page"' : '' ?>><?= esc($label) ?></a><?php endforeach; ?>
  </nav>
  <?php if ($section === 'enrollment'): ?>
  <div x-data="{ selected: [], enrollment: [], total: <?= $eligibleCount ?>, unenrolled: <?= $enrollmentCount ?> }">
    <section class="card" aria-labelledby="department-roster-title"><div class="card-body p-3 p-lg-4">
      <h2 id="department-roster-title" class="h5">Detected department staff</h2>
      <p class="text-muted small">Employees in <strong><?= $unit === 'clinic' ? 'Health Services' : 'Guidance' ?></strong> are listed from the local MIS directory. Enroll employees who provide appointments, then choose staff and set hours. Enrollment never creates a login account or grants workstation permissions. MIS login is not required.</p>
      <p class="small text-muted">Showing <?= count($departmentStaff) ?> of <?= count($allStaff) ?> department employees. Select-all controls affect only the displayed, eligible rows.</p>
      <div class="table-responsive mb-3"><table class="table align-middle" data-department-staff x-ref="roster"><thead><tr>
        <th scope="col"><span class="d-block small mb-1">Enroll</span><input type="checkbox" class="form-check-input" aria-label="Select all unenrolled staff" <?= $enrollmentCount === 0 ? 'disabled' : '' ?> :checked="unenrolled > 0 && enrollment.length === unenrolled" x-effect="$el.indeterminate = enrollment.length > 0 && enrollment.length < unenrolled" @change="enrollment = $el.checked ? Array.from($refs.roster.querySelectorAll('[name=&quot;person_ids[]&quot;]:not(:disabled)')).map(input => input.value) : []"></th>
        <th scope="col"><span class="d-block small mb-1">Schedule</span><input type="checkbox" class="form-check-input" aria-label="Select all eligible staff" <?= $eligibleCount === 0 ? 'disabled' : '' ?> :checked="total > 0 && selected.length === total" x-effect="$el.indeterminate = selected.length > 0 && selected.length < total" @change="selected = $el.checked ? Array.from($refs.roster.querySelectorAll('[name=&quot;staff_assignment_ids[]&quot;]:not(:disabled)')).map(input => input.value) : []"></th>
        <th scope="col">Employee</th><th scope="col">Department</th><th scope="col">Position</th><th scope="col">Scheduling status</th>
      </tr></thead><tbody>
        <?php if ($departmentStaff === []): ?><tr><td colspan="6" class="text-muted">No employees match these filters. Reset filters or check the local employee directory.</td></tr><?php endif; ?>
        <?php foreach ($departmentStaff as $staff): ?><tr>
          <td><input type="checkbox" class="form-check-input" name="person_ids[]" form="departmentEnrollment" value="<?= (int) $staff['person_id'] ?>" aria-label="<?= esc('Enroll ' . $staff['name'], 'attr') ?>" <?= $staff['can_enroll'] ? 'x-model="enrollment"' : 'disabled' ?>></td>
          <td><input type="checkbox" class="form-check-input" name="staff_assignment_ids[]" form="departmentTemplate" value="<?= $staff['eligible'] ? (int) $staff['staff_assignment_id'] : '' ?>" aria-label="<?= esc('Select ' . $staff['name'], 'attr') ?>" <?= $staff['eligible'] ? 'x-model="selected"' : 'disabled' ?> aria-describedby="departmentSelectionHelp"></td>
          <td><?= esc($staff['name']) ?></td><td><?= esc($staff['department']) ?></td><td><?= esc($staff['position'] ?: 'Not recorded') ?></td><td><span class="badge <?= $staff['eligible'] ? 'bg-success' : 'bg-secondary' ?>"><?= esc($staff['status']) ?></span></td>
        </tr><?php endforeach; ?>
      </tbody></table></div>
      <form id="departmentEnrollment" action="<?= esc($enrollmentAction ?? $base . '/enroll') ?>" method="POST" data-availability-save data-department-enrollment>
        <?= csrf_field() ?><?= view('appointments/_availability_context', compact('workspaceContext') + ['returnSection' => 'enrollment']) ?><input type="hidden" name="workspace_form" value="enrollment">
        <div class="av-form-footer"><span class="small" role="status" aria-live="polite"><strong x-text="enrollment.length">0</strong> employees selected for enrollment</span><button type="submit" class="btn btn-outline-primary" disabled :disabled="enrollment.length === 0 || $el.form.getAttribute('aria-busy') === 'true'" x-text="'Enroll ' + enrollment.length + ' Selected Employees'">Enroll 0 Selected Employees</button></div>
      </form>
    </div></section>
    <section class="card" aria-labelledby="department-hours-title"><div class="card-body p-3 p-lg-4">
      <h2 id="department-hours-title" class="h5">Set hours for enrolled staff</h2>
      <p class="text-muted small" id="departmentSelectionHelp">Use the Schedule checkboxes above, then choose working days and hours. Only selected staff receive missing weekday windows. Existing schedules—including inactive or split shifts—are never overwritten. For different hours, apply separate selections or use individual adjustments. Leave exceptions remain in effect.</p>
      <?php if ($eligibleCount === 0): ?><div class="alert alert-info small">No displayed staff are ready to schedule yet. Enroll active employees above; the page will refresh with their scheduling assignments. MIS login is not required.</div><?php endif; ?>
      <form id="departmentTemplate" action="<?= esc($templateAction ?? $base . '/template') ?>" method="POST" data-availability-save data-department-template>
        <?= csrf_field() ?><?= view('appointments/_availability_context', compact('workspaceContext') + ['returnSection' => 'enrollment']) ?><input type="hidden" name="workspace_form" value="template">
        <p class="small" role="status" aria-live="polite"><strong x-text="selected.length">0</strong> selected of <?= $eligibleCount ?> eligible staff</p>
        <fieldset class="mb-4"><legend class="form-label fs-6 float-none">Working days</legend><div class="d-flex flex-wrap gap-3"><?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="days[]" value="<?= $day ?>" id="departmentDay<?= $day ?>"><label class="form-check-label" for="departmentDay<?= $day ?>"><?= $day ?></label></div><?php endforeach; ?></div></fieldset>
        <div class="row g-3 mb-4">
          <div class="col-md-4"><label for="departmentStart" class="form-label">Start time</label><?= view('partials/schedule_time', ['id' => 'departmentStart', 'name' => 'start_time', 'label' => 'Start time', 'value' => '']) ?></div>
          <div class="col-md-4"><label for="departmentEnd" class="form-label">End time</label><?= view('partials/schedule_time', ['id' => 'departmentEnd', 'name' => 'end_time', 'label' => 'End time', 'value' => '']) ?></div>
          <div class="col-md-4"><label for="departmentDuration" class="form-label">Appointment duration</label><select name="slot_duration_minutes" id="departmentDuration" class="form-select" required><?php foreach ([15, 30, 45, 60] as $minutes): ?><option value="<?= $minutes ?>" <?= $minutes === 30 ? 'selected' : '' ?>><?= $minutes ?> minutes</option><?php endforeach; ?></select></div>
        </div>
        <div class="av-form-footer justify-content-end"><button type="submit" class="btn btn-primary" disabled :disabled="selected.length === 0 || $el.form.getAttribute('aria-busy') === 'true'" x-text="'Apply to ' + selected.length + ' Selected Staff'">Apply to 0 Selected Staff</button></div>
      </form>
    </div></section>
  </div>
  <?php elseif ($section === 'weekly'): ?>
  <section class="card" aria-labelledby="weekly-hours-title"><div class="card-body p-3 p-lg-4">
    <div class="av-section-heading"><div><h2 id="weekly-hours-title" class="h5">Configured weekly windows</h2><p class="small text-muted mb-0">Review recurring schedules before making an individual adjustment. <?= count($windows) ?> matching windows.</p></div><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#availability-individual-modal" <?= $providers === [] ? 'disabled' : '' ?>><i class="bi bi-calendar-plus me-2" aria-hidden="true"></i>Adjust weekly hours</button></div>
    <?php if ($providers === []): ?><div class="alert alert-info">No eligible providers are enrolled. Use <a href="<?= esc($sectionUrl('enrollment')) ?>">Department setup</a> first.</div><?php endif; ?>
    <div class="table-responsive"><table class="table align-middle" data-configured-windows><thead><tr><th scope="col">Staff</th><th scope="col">Day</th><th scope="col">Window (Manila)</th><th scope="col">Duration</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead><tbody>
      <?php if ($windows === []): ?><tr><td colspan="6" class="text-muted py-4">No recurring windows match this view. Use Department setup to add missing weekdays or reset your filters.</td></tr><?php endif; ?>
      <?php foreach ($windows as $window): ?><tr><td><?= esc($window['provider_name'] ?? $window['username'] ?? ('Staff assignment #' . ($window['staff_assignment_id'] ?? 'unknown'))) ?></td><td><?= $days[(int) $window['day_of_week']] ?></td><td class="text-nowrap"><?= esc(date('g:i A', strtotime($window['start_time'])) . ' – ' . date('g:i A', strtotime($window['end_time']))) ?></td><td><?= (int) $window['slot_duration_minutes'] ?> min</td><td><?= $window['is_active'] ? 'Active' : 'Inactive' ?></td><td><?php if (in_array((int) ($window['staff_assignment_id'] ?? 0), array_map('intval', array_column($providers, 'id')), true)): ?><a class="btn btn-outline-secondary btn-sm" href="<?= esc($base . '?' . \App\Services\Scheduling\AvailabilityWorkspaceContext::query(array_replace($workspaceContext, ['provider' => (string) $window['staff_assignment_id'], 'day' => (int) $window['day_of_week'], 'section' => 'weekly'])) . '#availability-individual-modal') ?>" data-availability-edit-link>Review / adjust</a><?php else: ?><span class="small text-muted">Inactive enrollment</span><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div></section>
  <div class="modal fade" id="availability-individual-modal" tabindex="-1" aria-labelledby="availability-individual-title" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 id="availability-individual-title" class="modal-title fs-5">Individual schedule adjustment</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">
    <p class="small text-muted">Saving updates the selected staff member’s single window for that weekday. Existing multi-window schedules are preserved and require separate review. All times are Asia/Manila. Changing the staff/day dropdown does not load another saved window; review the time values before saving, or use Review / adjust on the schedule list.</p>
    <?php if (count($selectedWindows) > 1): ?><p class="alert alert-warning">This provider has multiple windows on the selected day. This editor cannot merge them; the server will refuse to collapse split shifts.</p><?php endif; ?>
    <form id="individualAvailability" action="<?= esc($formAction) ?>" method="POST" data-availability-save data-individual-availability>
      <?= csrf_field() ?><?= view('appointments/_availability_context', compact('workspaceContext') + ['returnSection' => 'weekly']) ?><input type="hidden" name="workspace_form" value="individual">
      <div class="row g-3 mb-4">
        <div class="col-md-7"><label for="availabilityProvider" class="form-label">Staff member</label><select name="staff_assignment_id" id="availabilityProvider" class="form-select" required><option value="">Choose staff</option><?php foreach ($providers as $provider): ?><option value="<?= (int) $provider['id'] ?>" <?= (string) $provider['id'] === $workspaceContext['provider'] ? 'selected' : '' ?>><?= esc($provider['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label for="availabilityDay" class="form-label">Weekday</label><select name="day_of_week" id="availabilityDay" class="form-select" required><?php foreach ($days as $number => $day): ?><option value="<?= $number ?>" <?= $number === $workspaceContext['day'] ? 'selected' : '' ?>><?= $day ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label for="availabilityStart" class="form-label">Start time</label><?= view('partials/schedule_time', ['id' => 'availabilityStart', 'name' => 'start_time', 'label' => 'Start time', 'value' => substr($selectedWindow['start_time'] ?? '08:00', 0, 5)]) ?></div>
        <div class="col-md-6"><label for="availabilityEnd" class="form-label">End time</label><?= view('partials/schedule_time', ['id' => 'availabilityEnd', 'name' => 'end_time', 'label' => 'End time', 'value' => substr($selectedWindow['end_time'] ?? '17:00', 0, 5)]) ?></div>
        <div class="col-md-6"><label for="availabilityDuration" class="form-label">Appointment duration</label><select name="slot_duration_minutes" id="availabilityDuration" class="form-select" required><?php foreach ([15, 30, 45, 60] as $minutes): ?><option value="<?= $minutes ?>" <?= $minutes === (int) ($selectedWindow['slot_duration_minutes'] ?? 30) ? 'selected' : '' ?>><?= $minutes ?> minutes</option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label for="availabilityActive" class="form-label">Availability</label><select name="is_active" id="availabilityActive" class="form-select"><option value="1" <?= ($selectedWindow['is_active'] ?? 1) ? 'selected' : '' ?>>Active</option><option value="0" <?= !($selectedWindow['is_active'] ?? 1) ? 'selected' : '' ?>>Inactive</option></select></div>
      </div>
      <div class="av-form-footer justify-content-end"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" <?= empty($providers) ? 'disabled' : '' ?>>Save Availability</button></div>
    </form>
  </div></div></div></div>
  <?php else: ?>
  <section class="card" aria-labelledby="exception-list-title"><div class="card-body p-3 p-lg-4">
    <div class="av-section-heading"><div><h2 id="exception-list-title" class="h5">Leave, closed days and custom hours</h2><p class="small text-muted mb-0">Manage date-specific changes without altering recurring weekly hours.</p></div><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#availability-exception-new-modal" <?= $providers === [] ? 'disabled' : '' ?>><i class="bi bi-plus-circle me-2" aria-hidden="true"></i>Add exception</button></div>
    <div class="alert alert-warning small" role="note">Exceptions change future slot availability only. Existing appointments are never cancelled or moved; review affected bookings separately. For a department closure, add a closed-day exception for each enrolled provider. Custom hours require an active recurring schedule.</div>
    <?php if ($visibleExceptions === []): ?><p class="text-muted py-3 mb-0">No recorded exceptions match this view.</p><?php endif; ?>
    <div class="av-exception-list">
    <?php foreach ($visibleExceptions as $exception): $editable = $exception['exception_date'] >= \App\Services\Scheduling\SchedulingClock::today() && in_array((int) $exception['staff_assignment_id'], array_map('intval', array_column($providers, 'id')), true); ?>
      <article class="av-exception-row"><div><h3 class="h6 mb-1"><?= esc($exception['provider_name']) ?></h3><p class="small mb-1"><?= esc($exception['exception_date']) ?> · <?= $exception['is_unavailable'] ? 'Unavailable' : 'Custom hours' ?></p><p class="small text-muted mb-0 text-break"><?= esc($exception['reason'] ?? '') ?></p></div><?php if ($editable): ?><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#availability-exception-<?= (int) $exception['id'] ?>-modal">Edit exception</button><?php else: ?><span class="small text-muted">Read-only history / inactive enrollment</span><?php endif; ?></article>
    <?php endforeach; ?>
    </div>
  </div></section>
  <?php foreach ([null, ...$visibleExceptions] as $exception): if ($exception !== null && ($exception['exception_date'] < \App\Services\Scheduling\SchedulingClock::today() || !in_array((int) $exception['staff_assignment_id'], array_map('intval', array_column($providers, 'id')), true))) continue; $modalId = $exception === null ? 'availability-exception-new-modal' : 'availability-exception-' . (int) $exception['id'] . '-modal'; ?>
    <div class="modal fade" id="<?= $modalId ?>" tabindex="-1" aria-labelledby="<?= $modalId ?>-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="<?= $modalId ?>-title"><?= $exception === null ? 'Add exception' : 'Edit exception' ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><?= view('appointments/exception_form', compact('unit', 'providers', 'exception', 'workspaceContext')) ?></div></div></div></div>
  <?php endforeach; ?>
  <?php endif; ?>
  </div>
</section>
<?= $this->endSection() ?>

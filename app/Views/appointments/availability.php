<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/appointments-workspace.css') . '?v=' . (@filemtime(FCPATH . 'assets/css/pages/appointments-workspace.css') ?: '1'), 'attr') ?>">
<?= $this->endSection() ?>
<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 appt-page-actions">
  <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary btn-sm av-back-link">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>Appointments
  </a>
</div>
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
<section id="availability-workspace" class="availability-workspace" data-availability-workspace data-workspace-url="<?= esc($base) ?>" data-refresh-owner="availability.<?= esc($unit, 'attr') ?>" aria-label="<?= esc(ucfirst($unit)) ?> provider availability">
  <p class="av-lead-note">Manage <?= $unit === 'clinic' ? 'Clinic' : 'Guidance' ?> staff enrollment, recurring weekly hours, and date-specific exceptions. All scheduling times use <strong>Asia/Manila</strong>.</p>

  <div class="av-toolbar-card">
    <form method="get" action="<?= esc($base) ?>" class="av-filter-form" role="search" aria-label="Filter availability" data-availability-filters>
      <input type="hidden" name="section" value="<?= esc($section) ?>">
      <input type="hidden" name="day" value="<?= $workspaceContext['day'] ?>">
      <div class="av-filter-field">
        <label for="workspaceSearch" class="av-filter-label">Find staff</label>
        <div class="av-search-wrap">
          <i class="bi bi-search av-search-icon" aria-hidden="true"></i>
          <input type="search" id="workspaceSearch" name="q" class="form-control av-search-input" maxlength="120" value="<?= esc($workspaceContext['q']) ?>" placeholder="Search by staff name">
        </div>
      </div>
      <div class="av-filter-field">
        <label for="workspaceProvider" class="av-filter-label">Provider</label>
        <select id="workspaceProvider" name="provider" class="form-select">
          <option value="">All enrolled providers</option>
          <?php foreach ($providers as $provider): ?>
            <option value="<?= (int) $provider['id'] ?>" <?= (string) $provider['id'] === $workspaceContext['provider'] ? 'selected' : '' ?>><?= esc($provider['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="d-flex flex-wrap gap-2 av-filter-actions">
        <button class="btn btn-primary" type="submit" data-availability-apply>Apply filters</button>
        <a class="btn btn-outline-secondary" href="<?= esc($base . '?section=' . $section) ?>" data-availability-reset>Reset</a>
      </div>
    </form>
  </div>

  <p class="visually-hidden" data-availability-filter-status role="status" aria-live="polite">Choose a staff name or provider to narrow this workspace.</p>
  <div class="alert alert-danger" data-availability-filter-error role="alert" hidden>
    <span data-availability-filter-error-text></span>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-availability-filter-retry>Retry</button>
  </div>

  <div data-availability-results data-refresh-region="results">
    <nav class="av-section-nav" aria-label="Availability sections">
      <?php foreach (['enrollment' => 'Department setup', 'weekly' => 'Weekly schedules', 'exceptions' => 'Leave & exceptions'] as $key => $label): ?>
        <a class="av-section-link <?= $section === $key ? 'is-active' : '' ?>" href="<?= esc($sectionUrl($key)) ?>" <?= $section === $key ? 'aria-current="page"' : '' ?>><?= esc($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($section === 'enrollment'): ?>
      <div class="av-results-stack" x-data="{ selected: [], enrollment: [], total: <?= $eligibleCount ?>, unenrolled: <?= $enrollmentCount ?> }">
        <section class="appt-card" aria-labelledby="department-roster-title">
          <div class="appt-card-header">
            <div class="appt-card-header-stack">
              <div class="appt-card-title-row">
                <h2 id="department-roster-title" class="appt-card-title">Detected department staff</h2>
                <span class="appt-date-chip"><?= count($departmentStaff) ?> of <?= count($allStaff) ?></span>
              </div>
              <p class="appt-card-sub">Employees in <strong><?= $unit === 'clinic' ? 'Health Services' : 'Guidance' ?></strong> are listed from the local MIS directory. Enroll employees who provide appointments, then choose staff and set hours. Enrollment never creates a login account or grants workstation permissions. MIS login is not required. Select-all controls affect only displayed, eligible rows.</p>
            </div>
          </div>
          <div class="appt-table-wrap">
            <table class="table align-middle mb-0 appt-table" data-department-staff data-mobile-cards="false" x-ref="roster">
              <thead>
                <tr>
                  <th scope="col"><span class="d-block small mb-1">Enroll</span><input id="avSelectAllEnroll" type="checkbox" class="form-check-input" aria-label="Select all unenrolled staff" <?= $enrollmentCount === 0 ? 'disabled' : '' ?> :checked="unenrolled > 0 && enrollment.length === unenrolled" x-effect="$el.indeterminate = enrollment.length > 0 && enrollment.length < unenrolled" @change="enrollment = $el.checked ? Array.from($refs.roster.querySelectorAll('[name=&quot;person_ids[]&quot;]:not(:disabled)')).map(input => input.value) : []"></th>
                  <th scope="col"><span class="d-block small mb-1">Schedule</span><input id="avSelectAllSchedule" type="checkbox" class="form-check-input" aria-label="Select all eligible staff" <?= $eligibleCount === 0 ? 'disabled' : '' ?> :checked="total > 0 && selected.length === total" x-effect="$el.indeterminate = selected.length > 0 && selected.length < total" @change="selected = $el.checked ? Array.from($refs.roster.querySelectorAll('[name=&quot;staff_assignment_ids[]&quot;]:not(:disabled)')).map(input => input.value) : []"></th>
                  <th scope="col">Employee</th>
                  <th scope="col">Department</th>
                  <th scope="col">Position</th>
                  <th scope="col">Scheduling status</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($departmentStaff === []): ?>
                  <tr class="appt-empty-row">
                    <td colspan="6" class="appt-empty-cell">No employees match these filters. Reset filters or check the local employee directory.</td>
                  </tr>
                <?php endif; ?>
                <?php foreach ($departmentStaff as $staff): ?>
                  <?php
                    $staffTone = $staff['eligible']
                        ? (stripos((string) $staff['status'], 'configured') !== false ? 'appt-status-inline--active' : 'appt-status-inline--warn')
                        : 'appt-status-inline--muted';
                  ?>
                  <tr class="appt-row">
                    <td data-label="Enroll"><input type="checkbox" class="form-check-input" name="person_ids[]" form="departmentEnrollment" value="<?= (int) $staff['person_id'] ?>" aria-label="<?= esc('Enroll ' . $staff['name'], 'attr') ?>" <?= $staff['can_enroll'] ? 'x-model="enrollment"' : 'disabled' ?>></td>
                    <td data-label="Schedule"><input type="checkbox" class="form-check-input" name="staff_assignment_ids[]" form="departmentTemplate" value="<?= $staff['eligible'] ? (int) $staff['staff_assignment_id'] : '' ?>" aria-label="<?= esc('Select ' . $staff['name'], 'attr') ?>" <?= $staff['eligible'] ? 'x-model="selected"' : 'disabled' ?> aria-describedby="departmentSelectionHelp"></td>
                    <td data-label="Employee" class="appt-cell-header fw-semibold"><?= esc($staff['name']) ?></td>
                    <td data-label="Department"><?= esc($staff['department']) ?></td>
                    <td data-label="Position"><?= esc($staff['position'] ?: 'Not recorded') ?></td>
                    <td data-label="Scheduling status" class="appt-cell-full">
                      <span class="appt-status-inline <?= esc($staffTone) ?>"><span class="appt-dot" aria-hidden="true"></span><?= esc($staff['status']) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <form id="departmentEnrollment" action="<?= esc($enrollmentAction ?? $base . '/enroll') ?>" method="POST" data-availability-save data-department-enrollment>
            <?= csrf_field() ?><?= view('appointments/_availability_context', compact('workspaceContext') + ['returnSection' => 'enrollment']) ?><input type="hidden" name="workspace_form" value="enrollment">
            <div class="av-form-bar av-form-footer">
              <span class="av-selection-status" role="status" aria-live="polite"><strong x-text="enrollment.length">0</strong> employees selected for enrollment</span>
              <button type="submit" class="btn btn-outline-primary" disabled :disabled="enrollment.length === 0 || $el.form.getAttribute('aria-busy') === 'true'" x-text="'Enroll ' + enrollment.length + ' Selected Employees'">Enroll 0 Selected Employees</button>
            </div>
          </form>
        </section>

        <section class="appt-card" aria-labelledby="department-hours-title">
          <div class="appt-card-header">
            <div class="appt-card-header-stack">
              <h2 id="department-hours-title" class="appt-card-title">Set hours for enrolled staff</h2>
              <p class="appt-card-sub" id="departmentSelectionHelp">Use the Schedule checkboxes above, then choose working days and hours. Only selected staff receive missing weekday windows. Existing schedules—including inactive or split shifts—are never overwritten. For different hours, apply separate selections or use individual adjustments. Leave exceptions remain in effect.</p>
            </div>
          </div>
          <form id="departmentTemplate" action="<?= esc($templateAction ?? $base . '/template') ?>" method="POST" data-availability-save data-department-template>
            <?= csrf_field() ?><?= view('appointments/_availability_context', compact('workspaceContext') + ['returnSection' => 'enrollment']) ?><input type="hidden" name="workspace_form" value="template">
            <div class="appt-card-body">
              <?php if ($eligibleCount === 0): ?>
                <div class="appt-notice">No displayed staff are ready to schedule yet. Enroll active employees above; the page will refresh with their scheduling assignments. MIS login is not required.</div>
              <?php endif; ?>
              <p class="av-selection-status mb-0" role="status" aria-live="polite"><strong x-text="selected.length">0</strong> selected of <?= $eligibleCount ?> eligible staff</p>
              <fieldset class="av-fieldset">
                <legend>Working days</legend>
                <div class="av-day-grid">
                  <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day): ?>
                    <div class="form-check av-day-chip">
                      <input class="form-check-input" type="checkbox" name="days[]" value="<?= $day ?>" id="departmentDay<?= $day ?>">
                      <label class="form-check-label" for="departmentDay<?= $day ?>"><?= $day ?></label>
                    </div>
                  <?php endforeach; ?>
                </div>
              </fieldset>
              <div class="row g-3">
                <div class="col-md-4">
                  <label for="departmentStart" class="form-label small fw-semibold">Start time</label>
                  <?= view('partials/schedule_time', ['id' => 'departmentStart', 'name' => 'start_time', 'label' => 'Start time', 'value' => '']) ?>
                </div>
                <div class="col-md-4">
                  <label for="departmentEnd" class="form-label small fw-semibold">End time</label>
                  <?= view('partials/schedule_time', ['id' => 'departmentEnd', 'name' => 'end_time', 'label' => 'End time', 'value' => '']) ?>
                </div>
                <div class="col-md-4">
                  <label for="departmentDuration" class="form-label small fw-semibold">Appointment duration</label>
                  <select name="slot_duration_minutes" id="departmentDuration" class="form-select" required>
                    <?php foreach ([15, 30, 45, 60] as $minutes): ?>
                      <option value="<?= $minutes ?>" <?= $minutes === 30 ? 'selected' : '' ?>><?= $minutes ?> minutes</option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="av-form-bar av-form-bar--end av-form-footer">
              <button type="submit" class="btn btn-primary" disabled :disabled="selected.length === 0 || $el.form.getAttribute('aria-busy') === 'true'" x-text="'Apply to ' + selected.length + ' Selected Staff'">Apply to 0 Selected Staff</button>
            </div>
          </form>
        </section>
      </div>
    <?php elseif ($section === 'weekly'): ?>
      <?php
      $workweekOrder = [1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5, 0 => 6];
      $dayShortLabels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'];
      $enrolledIds = array_map('intval', array_column($providers, 'id'));
      $dayCounts = array_fill(0, 7, 0);
      $groupedWindows = [];

      foreach ($windows as $window) {
          $dayNum = (int) ($window['day_of_week'] ?? 0);
          if (isset($dayCounts[$dayNum])) {
              ++$dayCounts[$dayNum];
          }
          $assignmentId = isset($window['staff_assignment_id']) ? (int) $window['staff_assignment_id'] : 0;
          $groupKey = $assignmentId > 0 ? (string) $assignmentId : ('unknown-' . (int) ($window['id'] ?? 0));
          $providerName = trim((string) ($window['provider_name'] ?? $window['username'] ?? ('Staff assignment #' . ($assignmentId > 0 ? $assignmentId : 'unknown'))));
          if (!isset($groupedWindows[$groupKey])) {
              $groupedWindows[$groupKey] = [
                  'id'            => $groupKey,
                  'assignment_id' => $assignmentId,
                  'person_id'     => (int) ($window['provider_person_id'] ?? 0),
                  'name'          => $providerName,
                  'can_manage'    => $assignmentId > 0 && in_array($assignmentId, $enrolledIds, true),
                  'windows'       => [],
              ];
          }
          $groupedWindows[$groupKey]['windows'][] = $window;
      }

      $staffGroups = array_values($groupedWindows);
      usort($staffGroups, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

      $groupMeta = [];
      foreach ($staffGroups as &$group) {
          usort($group['windows'], static function (array $a, array $b) use ($workweekOrder): int {
              $da = $workweekOrder[(int) ($a['day_of_week'] ?? 0)] ?? 99;
              $db = $workweekOrder[(int) ($b['day_of_week'] ?? 0)] ?? 99;
              if ($da !== $db) {
                  return $da <=> $db;
              }
              return strcmp((string) ($a['start_time'] ?? ''), (string) ($b['start_time'] ?? ''));
          });

          $nameParts = preg_split('/\s+/u', trim($group['name']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
          $firstInitial = $nameParts !== [] ? mb_strtoupper(mb_substr($nameParts[0], 0, 1)) : 'S';
          $lastInitial = count($nameParts) > 1 ? mb_strtoupper(mb_substr($nameParts[count($nameParts) - 1], 0, 1)) : '';
          $group['initials'] = $firstInitial . $lastInitial;

          $daysMap = [];
          foreach ([1, 2, 3, 4, 5, 6, 0] as $d) {
              $daysMap[$d] = ['status' => 'off', 'title' => ($days[$d] ?? 'Day') . ': Not scheduled'];
          }

          $activeCount = 0;
          $inactiveCount = 0;
          $activeRanges = [];
          $allRanges = [];
          $durations = [];
          $presentDays = [];

          foreach ($group['windows'] as $w) {
              $d = (int) ($w['day_of_week'] ?? 0);
              $presentDays[$d] = $d;
              $isActive = !empty($w['is_active']);
              $timeRange = date('g:i A', strtotime($w['start_time'])) . ' – ' . date('g:i A', strtotime($w['end_time']));
              $dur = (int) ($w['slot_duration_minutes'] ?? 0);
              $durations[$dur] = $dur . ' min';
              $allRanges[$timeRange] = $timeRange;
              if ($isActive) {
                  ++$activeCount;
                  $activeRanges[$timeRange] = $timeRange;
              } else {
                  ++$inactiveCount;
              }

              if (($daysMap[$d]['status'] ?? 'off') !== 'active') {
                  $daysMap[$d]['status'] = $isActive ? 'active' : 'inactive';
              }
              $statusText = $isActive ? '' : ' (Inactive)';
              $daysMap[$d]['title'] = ($days[$d] ?? 'Day') . ': ' . $timeRange . ' · ' . $dur . ' min' . $statusText;
          }

          if ($activeCount > 0) {
              $hoursSummary = count($activeRanges) === 1 ? (string) reset($activeRanges) : 'Varies by day';
          } elseif ($inactiveCount > 0) {
              $hoursSummary = 'Inactive (' . (count($allRanges) === 1 ? (string) reset($allRanges) : 'varies') . ')';
          } else {
              $hoursSummary = 'No windows';
          }

          $group['active_count'] = $activeCount;
          $group['inactive_count'] = $inactiveCount;
          $group['days_map'] = $daysMap;
          $group['hours_summary'] = $hoursSummary;
          $group['duration_summary'] = implode(' / ', array_values($durations));

          $groupMeta[] = [
              'id'   => $group['id'],
              'days' => array_values($presentDays),
          ];
      }
      unset($group);

      $fallbackWeeklyXData = "{ dayFilter: 'all', page: 1, perPage: 10, openIds: [], isGroupVisible: () => true, isDayVisible: () => true, isOpen: () => true, toggle() {}, setDay() {}, allExpanded: () => true, toggleAll() {}, matchingGroupIds: () => [], totalPages: () => 1, setPage() {}, setPerPage() {}, pageNumbers: () => [1], rangeLabel: () => '' }";
      ?>
      <section class="appt-card" aria-labelledby="weekly-hours-title" data-weekly-groups="<?= esc(json_encode($groupMeta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') ?>" x-data="window.SynapseAvailabilityWorkspace ? window.SynapseAvailabilityWorkspace.weeklyState($el) : <?= esc($fallbackWeeklyXData, 'attr') ?>">
        <div class="appt-card-header av-section-heading mb-0">
          <div class="appt-card-header-stack">
            <div class="appt-card-title-row">
              <h2 id="weekly-hours-title" class="appt-card-title">Configured weekly windows</h2>
              <span class="appt-date-chip"><?= count($staffGroups) ?> staff · <?= count($windows) ?> <?= count($windows) === 1 ? 'window' : 'windows' ?></span>
            </div>
            <p class="appt-card-sub">Grouped by staff member. Expand a staff member to review, adjust, or remove individual days.</p>
          </div>
          <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#availability-individual-modal" <?= $providers === [] ? 'disabled' : '' ?>>
            <i class="bi bi-calendar-plus" aria-hidden="true"></i>Adjust weekly hours
          </button>
        </div>
        <?php if ($providers === []): ?>
          <div class="appt-card-body">
            <div class="appt-notice">No eligible providers are enrolled. Use <a href="<?= esc($sectionUrl('enrollment')) ?>">Department setup</a> first.</div>
          </div>
        <?php endif; ?>
        <?php if ($windows !== []): ?>
          <div class="av-weekly-bar">
            <div class="av-day-filter-group" role="group" aria-label="Filter by weekday">
              <button type="button" class="av-day-filter-pill" :class="{ 'is-active': dayFilter === 'all' }" :aria-pressed="dayFilter === 'all' ? 'true' : 'false'" @click="setDay('all')">
                All <span class="av-filter-count"><?= count($windows) ?></span>
              </button>
              <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): ?>
                <button type="button" class="av-day-filter-pill" :class="{ 'is-active': dayFilter === '<?= $d ?>' }" :aria-pressed="dayFilter === '<?= $d ?>' ? 'true' : 'false'" @click="setDay('<?= $d ?>')" <?= $dayCounts[$d] === 0 ? 'disabled' : '' ?>>
                  <?= esc($dayShortLabels[$d]) ?> <span class="av-filter-count"><?= $dayCounts[$d] ?></span>
                </button>
              <?php endforeach; ?>
            </div>
            <?php if (count($staffGroups) > 1): ?>
              <button type="button" class="btn btn-outline-secondary btn-sm av-expand-all-btn" @click="toggleAll()" x-text="allExpanded() ? 'Collapse all' : 'Expand all'">Expand all</button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="av-staff-list" data-configured-windows>
          <?php if ($windows === []): ?>
            <div class="av-weekly-empty">No recurring windows match this view. Use Department setup to add missing weekdays or reset your filters.</div>
          <?php else: ?>
            <?php
              $avPhotoTenantId = (int) (session()->get('user')['tenant_id'] ?? 0);
              $avPhotoService = $avPhotoTenantId > 0 ? new \App\Services\ProfilePhotoService() : null;
            ?>
            <?php foreach ($staffGroups as $group): ?>
              <?php
                $groupIdJs = str_replace("'", "\\'", $group['id']);
                $groupPhotoUrl = $avPhotoService?->personPhotoUrl($avPhotoTenantId, (int) ($group['person_id'] ?? 0));
              ?>
              <article class="av-staff-group" data-staff-group="<?= esc($group['id'], 'attr') ?>" x-show="isGroupVisible('<?= esc($groupIdJs, 'attr') ?>')" :class="{ 'is-open': isOpen('<?= esc($groupIdJs, 'attr') ?>') }">
                <button type="button" class="av-staff-summary" @click="toggle('<?= esc($groupIdJs, 'attr') ?>')" :aria-expanded="isOpen('<?= esc($groupIdJs, 'attr') ?>') ? 'true' : 'false'">
                  <div class="av-staff-identity">
                    <span class="av-staff-avatar" aria-hidden="true"><?= \App\Services\ProfileAvatar::contentHtml($groupPhotoUrl, (string) $group['initials'], ['name' => $group['name']]) ?></span>
                    <div class="av-staff-meta-stack">
                      <div class="av-staff-name-row">
                        <strong class="av-staff-name"><?= esc($group['name']) ?></strong>
                        <?php if (!$group['can_manage']): ?>
                          <span class="av-readonly-label">Inactive enrollment</span>
                        <?php endif; ?>
                      </div>
                      <span class="av-staff-sub">
                        <?= $group['active_count'] ?> active <?= $group['active_count'] === 1 ? 'day' : 'days' ?><?= $group['inactive_count'] > 0 ? ' (' . $group['inactive_count'] . ' inactive)' : '' ?> · <?= esc($group['hours_summary']) ?><?= $group['duration_summary'] !== '' ? ' · ' . esc($group['duration_summary']) : '' ?>
                      </span>
                    </div>
                  </div>
                  <div class="av-staff-summary-right">
                    <div class="av-day-strip" aria-label="Weekly coverage">
                      <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): ?>
                        <?php $dayState = $group['days_map'][$d]; ?>
                        <span class="av-day-pill <?= $dayState['status'] === 'active' ? 'is-active' : ($dayState['status'] === 'inactive' ? 'is-inactive' : 'is-off') ?>" title="<?= esc($dayState['title'], 'attr') ?>"><?= esc($dayShortLabels[$d]) ?></span>
                      <?php endforeach; ?>
                    </div>
                    <i class="bi bi-chevron-down av-staff-chevron" aria-hidden="true"></i>
                  </div>
                </button>
                <div class="av-staff-body" x-show="isOpen('<?= esc($groupIdJs, 'attr') ?>')"<?= count($staffGroups) > 1 ? ' x-cloak' : '' ?>>
                  <div class="appt-table-wrap">
                    <table class="table align-middle mb-0 appt-table av-staff-table" data-staff-windows data-mobile-cards="false">
                      <thead>
                        <tr>
                          <th scope="col">Day</th>
                          <th scope="col">Window (Manila)</th>
                          <th scope="col">Duration</th>
                          <th scope="col">Status</th>
                          <th scope="col" class="text-end">Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($group['windows'] as $window): ?>
                          <?php
                          $windowDayNum = (int) $window['day_of_week'];
                          $windowDayName = $days[$windowDayNum] ?? 'Weekday';
                          $windowTimeRange = date('g:i A', strtotime($window['start_time'])) . ' – ' . date('g:i A', strtotime($window['end_time']));
                          ?>
                          <tr class="appt-row" data-window-row data-day-of-week="<?= $windowDayNum ?>" x-show="isDayVisible(<?= $windowDayNum ?>)">
                            <td data-label="Day" class="appt-cell-header fw-semibold"><?= esc($windowDayName) ?></td>
                            <td data-label="Window (Manila)" class="text-nowrap appt-mono"><?= esc($windowTimeRange) ?></td>
                            <td data-label="Duration" class="appt-mono"><?= (int) $window['slot_duration_minutes'] ?> min</td>
                            <td data-label="Status"><span class="appt-status-inline <?= $window['is_active'] ? 'appt-status-inline--active' : 'appt-status-inline--muted' ?>"><span class="appt-dot" aria-hidden="true"></span><?= $window['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                            <td class="text-end appt-cell-actions">
                              <?php if ($group['can_manage']): ?>
                                <div class="av-row-actions">
                                  <a class="btn btn-outline-secondary btn-sm" href="<?= esc($base . '?' . \App\Services\Scheduling\AvailabilityWorkspaceContext::query(array_replace($workspaceContext, ['provider' => (string) $window['staff_assignment_id'], 'day' => $windowDayNum, 'section' => 'weekly'])) . '#availability-individual-modal') ?>" data-availability-edit-link>Review / adjust</a>
                                  <form action="<?= esc($base . '/remove') ?>" method="POST" data-availability-save data-availability-remove-window class="d-inline" data-confirm="<?= esc('Remove ' . $group['name'] . '’s ' . $windowDayName . ' (' . $windowTimeRange . ') recurring schedule? Existing appointments will not be cancelled or moved.', 'attr') ?>" data-confirm-title="Remove weekly window" data-confirm-label="Remove window" data-confirm-tone="danger">
                                    <?= csrf_field() ?><?= view('appointments/_availability_context', compact('workspaceContext') + ['returnSection' => 'weekly']) ?><input type="hidden" name="workspace_form" value="window-remove">
                                    <input type="hidden" name="id" value="<?= (int) $window['id'] ?>">
                                    <input type="hidden" name="staff_assignment_id" value="<?= (int) $window['staff_assignment_id'] ?>">
                                    <input type="hidden" name="token" value="<?= esc(\App\Models\ProviderAvailabilityModel::snapshotToken($window), 'attr') ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Remove</button>
                                  </form>
                                </div>
                              <?php else: ?>
                                <span class="av-readonly-label">Inactive enrollment</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php if ($windows !== []): ?>
          <div class="av-weekly-pagination appt-pagination-band" x-show="matchingGroupIds().length > 10 || totalPages() > 1" x-cloak>
            <div class="av-weekly-pagination-meta">
              <span class="small text-muted" x-text="rangeLabel()"></span>
              <select id="avWeeklyPerPage" class="form-select form-select-sm av-per-page-select" :value="String(perPage)" @change="setPerPage($event.target.value)" aria-label="Staff per page">
                <option value="10">10 per page</option>
                <option value="25">25 per page</option>
                <option value="50">50 per page</option>
              </select>
            </div>
            <nav aria-label="Weekly schedule staff pages">
              <ul class="pagination pagination-sm mb-0 synapse-pagination">
                <li class="page-item" :class="{ 'disabled': page <= 1 }">
                  <button type="button" class="page-link" @click="setPage(page - 1)" :disabled="page <= 1">Previous</button>
                </li>
                <template x-for="num in pageNumbers()" :key="num">
                  <li class="page-item" :class="{ 'active': num === page }">
                    <button type="button" class="page-link" @click="setPage(num)" x-text="num" :aria-current="num === page ? 'page' : null"></button>
                  </li>
                </template>
                <li class="page-item" :class="{ 'disabled': page >= totalPages() }">
                  <button type="button" class="page-link" @click="setPage(page + 1)" :disabled="page >= totalPages()">Next</button>
                </li>
              </ul>
            </nav>
          </div>
        <?php endif; ?>
      </section>

      <div class="modal fade" id="availability-individual-modal" tabindex="-1" aria-labelledby="availability-individual-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h2 id="availability-individual-title" class="modal-title fs-5">Individual schedule adjustment</h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <p class="small text-muted mb-3">Saving updates the selected staff member’s single window for that weekday. Existing multi-window schedules are preserved and require separate review. All times are Asia/Manila. Changing the staff/day dropdown does not load another saved window; review the time values before saving, or use Review / adjust on the schedule list.</p>
              <?php if (count($selectedWindows) > 1): ?>
                <div class="appt-notice appt-notice--warn mb-3">This provider has multiple windows on the selected day. This editor cannot merge them; the server will refuse to collapse split shifts.</div>
              <?php endif; ?>
              <form id="individualAvailability" action="<?= esc($formAction) ?>" method="POST" data-availability-save data-individual-availability>
                <?= csrf_field() ?><?= view('appointments/_availability_context', compact('workspaceContext') + ['returnSection' => 'weekly']) ?><input type="hidden" name="workspace_form" value="individual">
                <div class="row g-3">
                  <div class="col-md-7">
                    <label for="availabilityProvider" class="form-label">Staff member</label>
                    <select name="staff_assignment_id" id="availabilityProvider" class="form-select" required>
                      <option value="">Choose staff</option>
                      <?php foreach ($providers as $provider): ?>
                        <option value="<?= (int) $provider['id'] ?>" <?= (string) $provider['id'] === $workspaceContext['provider'] ? 'selected' : '' ?>><?= esc($provider['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-5">
                    <label for="availabilityDay" class="form-label">Weekday</label>
                    <select name="day_of_week" id="availabilityDay" class="form-select" required>
                      <?php foreach ($days as $number => $day): ?>
                        <option value="<?= $number ?>" <?= $number === $workspaceContext['day'] ? 'selected' : '' ?>><?= $day ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label for="availabilityStart" class="form-label">Start time</label>
                    <?= view('partials/schedule_time', ['id' => 'availabilityStart', 'name' => 'start_time', 'label' => 'Start time', 'value' => substr($selectedWindow['start_time'] ?? '08:00', 0, 5)]) ?>
                  </div>
                  <div class="col-md-6">
                    <label for="availabilityEnd" class="form-label">End time</label>
                    <?= view('partials/schedule_time', ['id' => 'availabilityEnd', 'name' => 'end_time', 'label' => 'End time', 'value' => substr($selectedWindow['end_time'] ?? '17:00', 0, 5)]) ?>
                  </div>
                  <div class="col-md-6">
                    <label for="availabilityDuration" class="form-label">Appointment duration</label>
                    <select name="slot_duration_minutes" id="availabilityDuration" class="form-select" required>
                      <?php foreach ([15, 30, 45, 60] as $minutes): ?>
                        <option value="<?= $minutes ?>" <?= $minutes === (int) ($selectedWindow['slot_duration_minutes'] ?? 30) ? 'selected' : '' ?>><?= $minutes ?> minutes</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label for="availabilityActive" class="form-label">Availability</label>
                    <select name="is_active" id="availabilityActive" class="form-select">
                      <option value="1" <?= ($selectedWindow['is_active'] ?? 1) ? 'selected' : '' ?>>Active</option>
                      <option value="0" <?= !($selectedWindow['is_active'] ?? 1) ? 'selected' : '' ?>>Inactive</option>
                    </select>
                  </div>
                </div>
              </form>
              <?php if ($selectedWindow !== []): ?>
                <?php
                $selectedProviderName = (string) ($selectedWindow['provider_name'] ?? $selectedWindow['username'] ?? ('Staff assignment #' . ($selectedWindow['staff_assignment_id'] ?? '')));
                $selectedDayName = $days[(int) $selectedWindow['day_of_week']] ?? 'Weekday';
                $selectedTimeRange = date('g:i A', strtotime($selectedWindow['start_time'])) . ' – ' . date('g:i A', strtotime($selectedWindow['end_time']));
                ?>
                <form action="<?= esc($base . '/remove') ?>" method="POST" data-availability-save data-availability-remove-window class="av-danger-zone" data-confirm="<?= esc('Remove ' . $selectedProviderName . '’s ' . $selectedDayName . ' (' . $selectedTimeRange . ') recurring schedule? Existing appointments will not be cancelled or moved.', 'attr') ?>" data-confirm-title="Remove weekly window" data-confirm-label="Remove window" data-confirm-tone="danger">
                  <?= csrf_field() ?><?= view('appointments/_availability_context', compact('workspaceContext') + ['returnSection' => 'weekly']) ?><input type="hidden" name="workspace_form" value="window-remove">
                  <input type="hidden" name="id" value="<?= (int) $selectedWindow['id'] ?>">
                  <input type="hidden" name="staff_assignment_id" value="<?= (int) $selectedWindow['staff_assignment_id'] ?>">
                  <input type="hidden" name="token" value="<?= esc(\App\Models\ProviderAvailabilityModel::snapshotToken($selectedWindow), 'attr') ?>">
                  <p>Remove the recurring schedule for <strong><?= esc($selectedProviderName) ?></strong> on <strong><?= esc($selectedDayName) ?></strong> (<span class="appt-mono"><?= esc($selectedTimeRange) ?></span>). Existing appointments will not be cancelled or moved.</p>
                  <div>
                    <button type="submit" class="btn btn-outline-danger btn-sm">Remove weekly window</button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
            <div class="modal-footer av-modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary" form="individualAvailability" <?= empty($providers) ? 'disabled' : '' ?>>Save Availability</button>
            </div>
          </div>
        </div>
      </div>
    <?php else: ?>
      <section class="appt-card" aria-labelledby="exception-list-title">
        <div class="appt-card-header av-section-heading mb-0">
          <div class="appt-card-header-stack">
            <div class="appt-card-title-row">
              <h2 id="exception-list-title" class="appt-card-title">Leave, closed days and custom hours</h2>
              <span class="appt-date-chip"><?= count($visibleExceptions) ?> <?= count($visibleExceptions) === 1 ? 'record' : 'records' ?></span>
            </div>
            <p class="appt-card-sub">Manage date-specific changes without altering recurring weekly hours.</p>
          </div>
          <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#availability-exception-new-modal" <?= $providers === [] ? 'disabled' : '' ?>>
            <i class="bi bi-plus-lg" aria-hidden="true"></i>Add exception
          </button>
        </div>
        <div class="appt-card-body pb-0">
          <div class="appt-notice appt-notice--warn" role="note">Exceptions change future slot availability only. Existing appointments are never cancelled or moved; review affected bookings separately. For a department closure, add a closed-day exception for each enrolled provider. Custom hours require an active recurring schedule.</div>
        </div>
        <?php if ($visibleExceptions === []): ?>
          <div class="appt-card-body">
            <p class="text-muted mb-0">No recorded exceptions match this view.</p>
          </div>
        <?php else: ?>
          <div class="av-exception-list mt-2">
            <?php foreach ($visibleExceptions as $exception): $editable = $exception['exception_date'] >= \App\Services\Scheduling\SchedulingClock::today() && in_array((int) $exception['staff_assignment_id'], array_map('intval', array_column($providers, 'id')), true); ?>
              <article class="av-exception-row">
                <div class="av-exception-main">
                  <div class="av-exception-top">
                    <h3 class="av-exception-name"><?= esc($exception['provider_name']) ?></h3>
                    <span class="av-exception-date"><?= esc($exception['exception_date']) ?></span>
                    <span class="appt-status-inline <?= $exception['is_unavailable'] ? 'appt-status-inline--danger' : 'appt-status-inline--warn' ?>">
                      <span class="appt-dot" aria-hidden="true"></span><?= $exception['is_unavailable'] ? 'Unavailable' : 'Custom hours' ?>
                    </span>
                  </div>
                  <p class="av-exception-reason"><?= esc($exception['reason'] ?? '') ?></p>
                </div>
                <?php if ($editable): ?>
                  <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#availability-exception-<?= (int) $exception['id'] ?>-modal">Edit exception</button>
                <?php else: ?>
                  <span class="av-readonly-label">Read-only history / inactive enrollment</span>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php foreach ([null, ...$visibleExceptions] as $exception): if ($exception !== null && ($exception['exception_date'] < \App\Services\Scheduling\SchedulingClock::today() || !in_array((int) $exception['staff_assignment_id'], array_map('intval', array_column($providers, 'id')), true))) continue; $modalId = $exception === null ? 'availability-exception-new-modal' : 'availability-exception-' . (int) $exception['id'] . '-modal'; ?>
        <?php $exceptionFormId = 'exception' . ($exception['id'] ?? 'New') . 'Form'; ?>
        <div class="modal fade" id="<?= $modalId ?>" tabindex="-1" aria-labelledby="<?= $modalId ?>-title" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h2 class="modal-title fs-5" id="<?= $modalId ?>-title"><?= $exception === null ? 'Add exception' : 'Edit exception' ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <?= view('appointments/exception_form', compact('unit', 'providers', 'exception', 'workspaceContext')) ?>
              </div>
              <div class="modal-footer av-modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" form="<?= esc($exceptionFormId, 'attr') ?>">Save exception</button>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
<?= $this->endSection() ?>

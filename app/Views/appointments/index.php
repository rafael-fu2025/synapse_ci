<?php $today = \App\Services\Scheduling\SchedulingClock::today(); ?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 appt-page-actions">
  <?php if (in_array(session()->get('user.role'), ['superadmin', 'clinic_admin', 'guidance_admin', 'guidance_supervisor'], true)): ?>
    <a href="<?= site_url('/' . $unit . '/appointments/availability') ?>" class="btn btn-outline-secondary">
      <i class="bi bi-clock" aria-hidden="true"></i>Staff Availability
    </a>
  <?php endif; ?>
  <a href="<?= site_url('/' . $unit . '/appointments/create') ?>" class="btn btn-primary" data-app-modal-open>
    <i class="bi bi-calendar-plus" aria-hidden="true"></i>New <?= $unit === 'clinic' ? 'Clinic' : 'Guidance' ?> Request
  </a>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$refreshOwner = 'appointments.staff.' . $unit;
$pendingCount = (int) ($pendingPagination['filtered'] ?? count($requests ?? []));
$scheduledCount = (int) ($scheduledPagination['filtered'] ?? count($appointments ?? []));
$selectedDateLabel = date('D, M j, Y', strtotime($date));
?>
<section class="appt-workspace" data-live-filters
         data-mutation-refresh
         data-refresh-owner="<?= esc($refreshOwner, 'attr') ?>"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>"
         data-filter-url="<?= esc(site_url('/' . $unit . '/appointments')) ?>"
         data-filter-key="staff-appointments-<?= esc($unit) ?>"
         data-live-events="notifications.changed queue.invalidated">
  <?= view('partials/live_filter_feedback') ?>

  <div data-filter-region="requests" data-refresh-region="appointments.pending">
    <div class="appt-card <?= $pendingCount > 0 ? 'appt-card--pending-active' : '' ?>">
      <div class="appt-card-header">
        <div class="appt-card-header-stack">
          <div class="appt-card-title-row">
            <h2 class="appt-card-title">Pending <?= $unit === 'clinic' ? 'Clinic' : 'Guidance' ?> Requests</h2>
            <span class="badge bg-light text-muted border appt-count-plain"><?= number_format($pendingCount) ?></span>
          </div>
          <p class="appt-card-sub">All requested dates. Review the request, assign eligible <?= $unit === 'clinic' ? 'clinic staff' : 'counselors' ?>, and confirm a time before check-in.</p>
        </div>
      </div>
      <div class="appt-table-wrap">
        <table class="table align-middle mb-0 appt-table" data-mobile-cards="false">
          <thead>
            <tr>
              <th scope="col"><?= $unit === 'clinic' ? 'Patient' : 'Student / employee' ?></th>
              <th scope="col">Preferred date &amp; time</th>
              <th scope="col">Type</th>
              <th scope="col">Brief reason</th>
              <th scope="col" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($requests)): ?>
              <tr class="appt-empty-row">
                <td colspan="5" class="appt-empty-cell">No pending requests.</td>
              </tr>
            <?php endif; ?>
            <?php foreach ($requests ?? [] as $request): ?>
              <tr class="appt-row">
                <td class="appt-cell-header">
                  <span class="appt-person-name"><?= esc($request['last_name'] . ', ' . $request['first_name']) ?></span>
                  <small class="appt-person-id">ID: <?= esc($request['mis_id']) ?></small>
                </td>
                <td data-label="Preferred date &amp; time" class="appt-cell-full">
                  <span class="appt-time-primary"><?= esc(date('M j, Y · h:i A', strtotime($request['starts_at']))) ?></span>
                  <span class="appt-sub-line">
                    <span class="appt-status-inline appt-status-inline--warn"><span class="appt-dot" aria-hidden="true"></span>Not yet confirmed</span>
                  </span>
                </td>
                <td data-label="Type">
                  <span class="appt-type-text"><?= esc(ucwords(str_replace('_', ' ', $request['type']))) ?></span>
                </td>
                <td data-label="Brief reason" class="appt-reason-cell appt-cell-full">
                  <?= esc($request['reason'] ?? 'Not provided') ?>
                </td>
                <td class="text-end appt-cell-actions">
                  <div class="table-card-actions appt-row-actions">
                    <a href="<?= site_url('/' . $unit . '/appointments/' . $request['id'] . '/confirm') ?>" class="btn btn-primary btn-sm" data-app-modal-open>Review &amp; Confirm</a>
                    <form action="<?= site_url('/' . $unit . '/appointments/cancel/' . $request['id']) ?>" method="POST" class="d-inline" data-background-submit data-confirm-reason data-confirm="Cancel this pending request? No time has been reserved." data-confirm-title="Cancel request" data-confirm-label="Cancel request" data-confirm-tone="danger">
                      <?= csrf_field() ?><input type="hidden" name="reason" value="">
                      <button type="submit" class="btn btn-outline-danger btn-sm appt-icon-btn" aria-label="Cancel request" title="Cancel request">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="appt-pagination-band">
        <?= view('partials/table_pagination', [
            'pagination' => $pendingPagination, 'baseUrl' => site_url('/' . $unit . '/appointments'), 'filters' => [],
            'paginationPageParam' => 'pending_page', 'paginationLabel' => 'Pending request pages',
            'paginationExtraQuery' => ['date' => $date, 'scheduled_page' => $scheduledPagination['page']],
            'paginationLiveFilters' => true,
        ], ['saveData' => false]) ?>
      </div>
    </div>
  </div>

  <div class="appt-card">
    <div class="appt-card-header">
      <div class="appt-card-header-stack">
        <div class="appt-card-title-row">
          <h2 class="appt-card-title"><?= $unit === 'clinic' ? 'Clinic Scheduled Appointments' : 'Guidance Counseling Appointments' ?></h2>
          <span class="appt-date-chip"><?= esc($selectedDateLabel) ?> · <?= number_format($scheduledCount) ?></span>
        </div>
        <p class="appt-card-sub">Confirmed visits and existing bookings for the selected date</p>
      </div>
      <form method="GET" action="<?= esc(site_url('/' . $unit . '/appointments')) ?>" class="appt-filter-bar appointment-filter" data-filter-form>
        <label for="appointmentDateFilter" class="visually-hidden">Appointment date</label>
        <div class="appointment-date-field">
          <input type="text" class="form-control form-control-sm" id="appointmentDateFilter" data-datepicker data-target="#appointmentDateFilter_iso" data-datepicker-counts-url="<?= esc(site_url('/' . $unit . '/appointments/calendar-counts')) ?>">
          <input type="hidden" name="date" id="appointmentDateFilter_iso" value="<?= esc($date) ?>" data-reset-pages>
        </div>
        <input type="hidden" name="pending_page" value="<?= $pendingPagination['page'] ?>">
        <input type="hidden" name="scheduled_page" value="<?= $scheduledPagination['page'] ?>">
        <label for="appointmentPerPage" class="visually-hidden">Rows per page</label>
        <div class="appointment-page-size">
          <select name="per_page" id="appointmentPerPage" class="form-select form-select-sm" data-reset-pages>
            <?php foreach ([20, 25, 50, 100] as $size): ?>
              <option value="<?= $size ?>" <?= $scheduledPagination['per_page'] === $size ? 'selected' : '' ?>><?= $size ?> per page</option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>
    <div data-filter-region="schedule" data-refresh-region="appointments.schedule">
      <div class="appt-table-wrap">
        <table class="table align-middle mb-0 appt-table" data-mobile-cards="false">
          <thead>
            <tr>
              <th scope="col">Time</th>
              <th scope="col">Patient</th>
              <th scope="col"><?= $unit === 'clinic' ? 'Assigned clinician' : 'Assigned counselor' ?></th>
              <th scope="col">Type</th>
              <th scope="col">Status</th>
              <th scope="col" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php $calendar = $appointments; ?>
            <?php if (empty($calendar)): ?>
              <tr class="appt-empty-row">
                <td colspan="6" class="appt-empty-cell">No appointments scheduled for <?= esc(date('F j, Y', strtotime($date))) ?>.</td>
              </tr>
            <?php endif; ?>
            <?php foreach ($calendar as $appt): ?>
              <?php
                $statusKey = (string) ($appt['status'] ?? '');
                $statusLabel = $statusKey === 'checked_in' ? 'In queue' : ucwords(str_replace('_', ' ', $statusKey));
                $statusClass = match ($statusKey) {
                    'scheduled', 'confirmed' => 'appt-status-inline--brand',
                    'checked_in' => 'appt-status-inline--sky',
                    'completed' => 'appt-status-inline--active',
                    'no_show' => 'appt-status-inline--danger',
                    default => 'appt-status-inline--muted',
                };
              ?>
              <tr class="appt-row">
                <td data-label="Time" class="appt-time-primary">
                  <?= esc(date('h:i A', strtotime($appt['starts_at']))) ?>
                </td>
                <td class="appt-cell-header">
                  <span class="appt-person-name"><?= esc($appt['last_name'] . ', ' . $appt['first_name']) ?></span>
                  <small class="appt-person-id">ID: <?= esc($appt['mis_id']) ?></small>
                </td>
                <td data-label="<?= $unit === 'clinic' ? 'Assigned clinician' : 'Assigned counselor' ?>">
                  <?php if (!empty($appt['provider_name'])): ?>
                    <span><?= esc($appt['provider_name']) ?></span>
                  <?php else: ?>
                    <span class="text-muted">Unassigned</span>
                  <?php endif; ?>
                </td>
                <td data-label="Type">
                  <span class="appt-type-text"><?= esc(ucwords(str_replace('_', ' ', $appt['type']))) ?></span>
                </td>
                <td data-label="Status">
                  <span class="appt-status-inline <?= esc($statusClass) ?>">
                    <span class="appt-dot" aria-hidden="true"></span><?= esc($statusLabel) ?>
                  </span>
                </td>
                <td class="text-end appt-cell-actions">
                  <div class="table-card-actions appt-row-actions">
                    <?php if (in_array($appt['status'], ['scheduled', 'confirmed', 'checked_in'], true) && $appt['local_date'] <= $today && $appt['starts_at'] <= \App\Services\Scheduling\SchedulingClock::timestamp()): ?>
                      <form action="<?= site_url('/' . $unit . '/appointments/no-show/' . $appt['id']) ?>" method="POST" class="d-inline" data-background-submit data-confirm="Explicitly mark this absence as no-show? Unstarted linked queue tickets will be terminal. Active consultations cannot be marked no-show." data-confirm-title="Mark no-show" data-confirm-label="Mark no-show" data-confirm-tone="danger">
                        <?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $appt['version'] ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Mark no-show</button>
                      </form>
                    <?php endif; ?>
                    <?php if (in_array($appt['status'], ['scheduled', 'confirmed'], true)): ?>
                      <?php if ($appt['local_date'] === $today && !empty($appt['staff_assignment_id'])): ?>
                        <form action="<?= site_url('/' . $unit . '/appointments/check-in/' . $appt['id']) ?>" method="POST" class="d-inline">
                          <?= csrf_field() ?>
                          <button type="submit" class="btn btn-success btn-sm">Check-in to Queue</button>
                        </form>
                      <?php endif; ?>
                      <form action="<?= site_url('/' . $unit . '/appointments/cancel/' . $appt['id']) ?>" method="POST" class="d-inline" data-background-submit data-confirm-reason data-confirm="Cancel this appointment? The scheduled slot will be released." data-confirm-title="Cancel appointment" data-confirm-label="Cancel appointment" data-confirm-tone="danger">
                        <?= csrf_field() ?><input type="hidden" name="reason" value="">
                        <button type="submit" class="btn btn-outline-danger btn-sm appt-icon-btn" aria-label="Cancel appointment" title="Cancel appointment">
                          <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                      </form>
                    <?php elseif ($appt['status'] === 'checked_in'): ?>
                      <a href="<?= site_url('/' . $unit . '/queue') ?>" class="btn btn-outline-secondary btn-sm">View on Queue</a>
                      <?php if ($unit === 'guidance'): ?>
                        <a href="<?= site_url('/guidance/call-slips') ?>" class="btn btn-outline-secondary btn-sm">Call Slips</a>
                        <?php if (!empty($appt['person_id'])): ?>
                          <a href="<?= site_url('/guidance/students/' . (int) $appt['person_id'] . '/cumulative') ?>" class="btn btn-outline-secondary btn-sm">Cumulative</a>
                        <?php endif; ?>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="appt-pagination-band">
        <?= view('partials/table_pagination', [
            'pagination' => $scheduledPagination, 'baseUrl' => site_url('/' . $unit . '/appointments'), 'filters' => [],
            'paginationPageParam' => 'scheduled_page', 'paginationLabel' => 'Scheduled appointment pages',
            'paginationExtraQuery' => ['date' => $date, 'pending_page' => $pendingPagination['page']],
            'paginationLiveFilters' => true,
        ], ['saveData' => false]) ?>
      </div>
    </div>
  </div>
</section>
<?= $this->endSection() ?>

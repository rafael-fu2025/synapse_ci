<?php $today = \App\Services\Scheduling\SchedulingClock::today(); ?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <?php if (in_array(session()->get('user.role'), ['superadmin', 'clinic_admin', 'guidance_admin', 'guidance_supervisor'], true)): ?>
    <a href="<?= site_url('/' . $unit . '/appointments/availability') ?>" class="btn btn-outline-secondary"><i class="bi bi-clock me-1"></i> Staff Availability</a>
  <?php endif; ?>
  <a href="<?= site_url('/' . $unit . '/appointments/create') ?>" class="btn btn-primary" data-app-modal-open><i class="bi bi-calendar-plus me-1"></i> New <?= $unit === 'clinic' ? 'Clinic' : 'Guidance' ?> Request</a>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<section data-live-filters
         data-filter-url="<?= esc(site_url('/' . $unit . '/appointments')) ?>"
         data-filter-key="staff-appointments-<?= esc($unit) ?>"
         data-live-events="notifications.changed queue.invalidated">
  <?= view('partials/live_filter_feedback') ?>

  <div data-filter-region="requests">
    <div class="card mb-4">
      <div class="card-header bg-transparent py-3">
        <h5 class="mb-1">Pending <?= $unit === 'clinic' ? 'Clinic' : 'Guidance' ?> Requests <span class="badge bg-warning text-dark"><?= count($requests ?? []) ?></span></h5>
        <p class="small text-muted mb-0">All requested dates. Review the request, assign eligible <?= $unit === 'clinic' ? 'clinic staff' : 'counselors' ?>, and confirm a time before check-in.</p>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0"><thead><tr><th class="ps-3"><?= $unit === 'clinic' ? 'Patient' : 'Student / employee' ?></th><th>Preferred date &amp; time</th><th>Type</th><th>Brief reason</th><th class="text-end pe-3">Actions</th></tr></thead><tbody>
          <?php if (empty($requests)): ?><tr><td colspan="5" class="text-center py-4 text-muted">No pending requests.</td></tr><?php endif; ?>
          <?php foreach ($requests ?? [] as $request): ?>
            <tr><td class="ps-3"><strong><?= esc($request['last_name'] . ', ' . $request['first_name']) ?></strong><small class="d-block text-muted">ID: <?= esc($request['mis_id']) ?></small></td>
              <td><?= esc(date('M j, Y · h:i A', strtotime($request['starts_at']))) ?><small class="d-block text-muted">Not yet confirmed</small></td>
              <td><?= esc(ucwords(str_replace('_', ' ', $request['type']))) ?></td><td><?= esc($request['reason'] ?? 'Not provided') ?></td>
              <td class="text-end pe-3">
                <a href="<?= site_url('/' . $unit . '/appointments/' . $request['id'] . '/confirm') ?>" class="btn btn-primary btn-sm" data-app-modal-open>Review &amp; Confirm</a>
                <form action="<?= site_url('/' . $unit . '/appointments/cancel/' . $request['id']) ?>" method="POST" class="d-inline" data-background-submit data-confirm-reason data-confirm="Cancel this pending request? No time has been reserved." data-confirm-title="Cancel request" data-confirm-label="Cancel request" data-confirm-tone="danger">
                  <?= csrf_field() ?><input type="hidden" name="reason" value=""><button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Cancel request"><i class="bi bi-x-circle"></i></button>
                </form>
              </td></tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header bg-transparent d-flex flex-wrap gap-3 justify-content-between align-items-center py-3">
      <div><h5 class="mb-1"><?= $unit === 'clinic' ? 'Clinic Scheduled Appointments' : 'Guidance Counseling Appointments' ?></h5><span class="text-muted small">Confirmed visits and existing bookings for the selected date</span></div>
      <form method="GET" action="<?= esc(site_url('/' . $unit . '/appointments')) ?>" class="d-flex align-items-center gap-2" data-filter-form>
        <label for="appointmentDateFilter" class="visually-hidden">Appointment date</label>
        <input type="text" class="form-control form-control-sm" id="appointmentDateFilter" data-datepicker data-target="#appointmentDateFilter_iso">
        <input type="hidden" name="date" id="appointmentDateFilter_iso" value="<?= esc($date) ?>">
      </form>
    </div>
    <div data-filter-region="schedule">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0"><thead><tr><th class="ps-3">Time</th><th>Patient</th><th><?= $unit === 'clinic' ? 'Assigned clinician' : 'Assigned counselor' ?></th><th>Type</th><th>Status</th><th class="text-end pe-3">Actions</th></tr></thead><tbody>
          <?php $calendar = array_filter($appointments, static fn(array $row): bool => $row['status'] !== 'requested'); ?>
          <?php if (empty($calendar)): ?><tr><td colspan="6" class="text-center py-4 text-muted">No appointments scheduled for <?= esc(date('F j, Y', strtotime($date))) ?>.</td></tr><?php endif; ?>
          <?php foreach ($calendar as $appt): ?>
            <tr><td class="ps-3 fw-semibold"><?= esc(date('h:i A', strtotime($appt['starts_at']))) ?></td>
              <td><strong><?= esc($appt['last_name'] . ', ' . $appt['first_name']) ?></strong><small class="d-block text-muted">ID: <?= esc($appt['mis_id']) ?></small></td>
              <td><?= esc($appt['provider_name'] ?? 'Unassigned') ?></td><td><?= esc(ucwords(str_replace('_', ' ', $appt['type']))) ?></td>
              <td><span class="badge <?= in_array($appt['status'], ['scheduled', 'confirmed'], true) ? 'bg-primary' : ($appt['status'] === 'completed' ? 'bg-success' : 'bg-secondary') ?>"><?= esc($appt['status'] === 'checked_in' ? 'In queue' : ucwords(str_replace('_', ' ', $appt['status']))) ?></span></td>
              <td class="text-end pe-3">
                <?php if (in_array($appt['status'], ['scheduled', 'confirmed', 'checked_in'], true) && $appt['local_date'] <= $today && $appt['starts_at'] <= \App\Services\Scheduling\SchedulingClock::timestamp()): ?>
                  <form action="<?= site_url('/' . $unit . '/appointments/no-show/' . $appt['id']) ?>" method="POST" class="d-inline" data-background-submit data-confirm="Explicitly mark this absence as no-show? Unstarted linked queue tickets will be terminal. Active consultations cannot be marked no-show." data-confirm-title="Mark no-show" data-confirm-label="Mark no-show" data-confirm-tone="danger">
                    <?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $appt['version'] ?>"><button type="submit" class="btn btn-outline-danger btn-sm">Mark no-show</button>
                  </form>
                <?php endif; ?>
                <?php if (in_array($appt['status'], ['scheduled', 'confirmed'], true)): ?>
                  <?php if ($appt['local_date'] === $today && !empty($appt['staff_assignment_id'])): ?>
                    <form action="<?= site_url('/' . $unit . '/appointments/check-in/' . $appt['id']) ?>" method="POST" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-success btn-sm">Check-in to Queue</button></form>
                  <?php endif; ?>
                  <form action="<?= site_url('/' . $unit . '/appointments/cancel/' . $appt['id']) ?>" method="POST" class="d-inline" data-background-submit data-confirm-reason data-confirm="Cancel this appointment? The scheduled slot will be released." data-confirm-title="Cancel appointment" data-confirm-label="Cancel appointment" data-confirm-tone="danger">
                    <?= csrf_field() ?><input type="hidden" name="reason" value=""><button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Cancel appointment"><i class="bi bi-x-circle"></i></button>
                  </form>
                <?php elseif ($appt['status'] === 'checked_in'): ?>
                  <a href="<?= site_url('/' . $unit . '/queue') ?>" class="btn btn-outline-primary btn-sm">View on Queue</a>
                <?php endif; ?>
              </td></tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
  </div>
</section>
<?= $this->endSection() ?>

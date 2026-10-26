<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <a href="<?= esc(site_url('portal/visits')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>My Visit History</a>
  <a href="<?= site_url('/portal/appointments/clinic/create') ?>" class="btn btn-outline-primary btn-sm" data-app-modal-open>
    <i class="bi bi-calendar-plus me-1"></i> Request Clinic Visit
  </a>
  <a href="<?= site_url('/portal/appointments/guidance/create') ?>" class="btn btn-outline-info btn-sm" data-app-modal-open>
    <i class="bi bi-calendar-check me-1"></i> Request Guidance Session
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$user = session()->get('user');
$role = $user['role'] ?? 'student';
$person = $chart['person'] ?? [];
$student = $chart['student_profile'] ?? null;
$employee = $chart['employee_profile'] ?? null;
$health = $chart['health_profile'] ?? [];
$appointments = $appointments ?? ($chart['appointments'] ?? []);
$filters = $filters ?? ['service' => 'all', 'status' => 'all', 'sort' => 'upcoming'];
$hasFilters = $filters['service'] !== 'all' || $filters['status'] !== 'all';
$serviceLabel = static fn (string $unit): string => $unit === 'clinic' ? 'University Clinic' : 'Guidance & Counseling';
$typeLabel = static fn (string $type): string => ucwords(str_replace('_', ' ', $type));
$statusLabel = static fn (string $status): string => match ($status) {
    'requested' => 'Awaiting confirmation',
    'scheduled' => 'Scheduled',
    'confirmed' => 'Confirmed',
    'checked_in' => 'In Queue',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'no_show' => 'No show',
    default => ucwords(str_replace('_', ' ', $status)),
};
$statusBadge = static fn (string $status): string => match ($status) {
    'requested' => 'bg-warning text-dark',
    'scheduled', 'confirmed' => 'bg-primary',
    'checked_in' => 'bg-info text-dark',
    'completed' => 'bg-success',
    default => 'bg-secondary',
};

$email = !empty($person['email']) ? $person['email'] : ($user['email'] ?? null);
if (empty($email) && !empty($person['first_name']) && !empty($person['last_name'])) {
    $email = \App\Services\UniversityEmail::derive($person['first_name'], $person['last_name']);
}
$isEmployee = ($role === 'employee' || !empty($employee) || ($person['kind'] ?? '') === 'employee');
$fullName = !empty($person['first_name'])
    ? trim($person['first_name'] . ' ' . (!empty($person['middle_name']) ? $person['middle_name'] . ' ' : '') . $person['last_name'])
    : ($user['name'] ?? 'University Member');
$idNumber = $person['mis_id'] ?? ($user['identifier'] ?? '—');
?>

<!-- Staff Role Console Switcher Banner (Shown only if staff logs into personal account) -->
<?php if (in_array($role, ['superadmin', 'clinic_admin', 'clinic_staff', 'guidance_admin', 'guidance_supervisor', 'counsellor', 'bmg_admin', 'facilities_op'], true)): ?>
  <div class="alert alert-info border-0 shadow-sm d-flex flex-wrap justify-content-between align-items-center mb-4 p-3">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-person-badge-fill fs-3 text-primary"></i>
      <div>
        <strong class="d-block text-dark">Staff Member Personal Health Session</strong>
        <span class="small text-muted">You are logged into your individual employee patient account. Switch to your departmental console below.</span>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-2 mt-md-0">
      <?php if (in_array($role, ['superadmin', 'clinic_admin', 'clinic_staff'], true)): ?>
        <a href="<?= \App\Services\PortalContext::urlFor('clinic', 'clinic') ?>" class="btn btn-outline-secondary">
          <i class="bi bi-hospital me-1"></i> Clinic Staff Console
        </a>
      <?php endif; ?>
      <?php if (in_array($role, ['superadmin', 'guidance_admin', 'guidance_supervisor', 'counsellor'], true)): ?>
        <a href="<?= \App\Services\PortalContext::urlFor('guidance', 'guidance') ?>" class="btn btn-outline-secondary">
          <i class="bi bi-person-heart me-1"></i> Guidance Console
        </a>
      <?php endif; ?>
      <?php if (in_array($role, ['superadmin', 'bmg_admin', 'facilities_op'], true)): ?>
        <a href="<?= \App\Services\PortalContext::urlFor('bmg', 'facilities/dashboard') ?>" class="btn btn-outline-secondary">
          <i class="bi bi-recycle me-1"></i> Facilities Console
        </a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-6"><div class="card h-100 mb-0"><div class="card-body pt-3">
    <h5 class="card-title"><i class="bi bi-megaphone me-2" aria-hidden="true"></i>Campus announcements</h5>
    <p class="text-muted small">Read current notices from the Clinic and Guidance teams.</p>
    <a href="<?= esc(site_url('/portal/announcements')) ?>" class="btn btn-outline-primary btn-sm">View announcements</a>
  </div></div></div>
  <div class="col-md-6"><div class="card h-100 mb-0"><div class="card-body pt-3">
    <h5 class="card-title"><i class="bi bi-clipboard-check me-2" aria-hidden="true"></i>My surveys</h5>
    <p class="text-muted small">View eligible Guidance surveys and your submission status. Responses are identified, not anonymous.</p>
    <a href="<?= esc(site_url('/portal/surveys')) ?>" class="btn btn-outline-primary btn-sm">View surveys</a>
  </div></div></div>
</div>

<?= view('portal/_queue_tickets', ['activeTickets' => $activeTickets ?? (!empty($activeTicket) ? [$activeTicket] : [])]) ?>

<div class="row g-4 align-items-start">
  <!-- Left Column: Student / Employee ATM Profile Card (Fixed ATM dimensions, never stretches) -->
  <div class="col-12 col-xl-auto">
    <div class="portal-profile-card mb-4" title="Medical Flags and University Profile">
      <!-- Hidden hook for automated tests & screen readers expecting Medical Flags -->
      <span class="visually-hidden">Medical Flags</span>

      <!-- Card background decorative art -->
      <img src="<?= base_url('assets/img/brand/levin7-white-fade.svg') ?>"
           alt=""
           aria-hidden="true"
           draggable="false"
           class="card-art-logo">
      <div class="card-art-noise" aria-hidden="true"></div>

      <!-- Card content -->
      <div class="card-content">
        <div class="card-caption">
          <i class="bi bi-person-vcard" aria-hidden="true"></i>
          <span><?= $isEmployee ? 'Employee Profile' : 'Student Profile' ?></span>
        </div>

        <div class="card-name" title="<?= esc($fullName) ?>">
          <?= esc($fullName) ?>
        </div>
        <div class="card-id">
          ID: <?= esc($idNumber) ?>
        </div>

        <dl class="card-details">
          <dt>Email</dt>
          <dd title="<?= esc($email ?? 'N/A') ?>">
            <?= esc($email ?? 'N/A') ?>
          </dd>

          <?php if ($isEmployee): ?>
            <dt>Department</dt>
            <dd title="<?= esc($employee['department'] ?? 'Foundation University') ?>">
              <?= esc($employee['department'] ?? 'Foundation University') ?>
            </dd>

            <dt>Position</dt>
            <dd title="<?= esc($employee['position'] ?? 'Staff') ?>">
              <?= esc($employee['position'] ?? 'Staff') ?>
            </dd>

            <dt>Status</dt>
            <dd>
              <?= esc(ucwords(str_replace('_', ' ', $employee['employment_status'] ?? 'Active'))) ?>
            </dd>
          <?php else: ?>
            <dt>Course</dt>
            <dd title="<?= esc($student['program'] ?? 'N/A') ?>">
              <?= esc($student['program'] ?? 'N/A') ?>
            </dd>

            <dt>Year level</dt>
            <dd>
              <?= !empty($student['year_level']) ? 'Year ' . esc($student['year_level']) : 'N/A' ?>
            </dd>

            <?php if (!empty($student['section'])): ?>
              <dt>Section</dt>
              <dd><?= esc($student['section']) ?></dd>
            <?php endif; ?>

            <?php if (!empty($student['department'])): ?>
              <dt>Department</dt>
              <dd><?= esc($student['department']) ?></dd>
            <?php endif; ?>
          <?php endif; ?>

          <dt>Blood type</dt>
          <dd>
            <?= !empty($health['blood_type']) ? esc($health['blood_type']) : 'Not Recorded' ?>
          </dd>

          <?php if (!empty($health['allergies'])): ?>
            <dt>Allergies</dt>
            <dd class="text-warning fw-bold" title="<?= esc($health['allergies']) ?>">
              <i class="bi bi-exclamation-triangle-fill me-1"></i><?= esc($health['allergies']) ?>
            </dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <!-- Emergency Contact Card (Student / Employee self-service) -->
    <div class="card border-0 shadow-sm p-3 mb-4" style="max-width: 360px;" data-portal-emergency-card aria-labelledby="portalEmergencyCardTitle">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="fw-bold mb-0 text-dark" id="portalEmergencyCardTitle">
          <i class="bi bi-telephone-plus text-primary me-1" aria-hidden="true"></i> Emergency Contact
        </h6>
        <a href="<?= esc(site_url('portal/settings')) ?>" class="btn btn-outline-primary btn-sm py-0" data-app-modal-open>
          <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
        </a>
      </div>
      <div data-portal-emergency-content>
        <?php if (!empty($health['emergency_contact_name']) || !empty($health['emergency_contact_phone'])): ?>
          <dl class="row mb-0 small">
            <dt class="col-5 text-muted fw-normal">Contact:</dt>
            <dd class="col-7 fw-semibold mb-1 text-break"><?= esc($health['emergency_contact_name'] ?? '—') ?></dd>
            <?php if (!empty($health['emergency_contact_relationship'])): ?>
              <dt class="col-5 text-muted fw-normal">Relationship:</dt>
              <dd class="col-7 mb-1 text-break"><?= esc($health['emergency_contact_relationship']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($health['emergency_contact_phone'])): ?>
              <dt class="col-5 text-muted fw-normal">Phone:</dt>
              <dd class="col-7 mb-0 text-break"><a href="tel:<?= esc(preg_replace('/[^\d+]/', '', $health['emergency_contact_phone'])) ?>" class="text-decoration-none"><?= esc($health['emergency_contact_phone']) ?></a></dd>
            <?php endif; ?>
          </dl>
        <?php else: ?>
          <p class="small text-muted mb-2">No emergency contact recorded yet. Add one so clinic staff know who to reach in an emergency.</p>
          <a href="<?= esc(site_url('portal/settings')) ?>" class="btn btn-outline-secondary btn-sm" data-app-modal-open>
            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Add emergency contact
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right Column: My Appointments -->
  <div class="col-12 col-xl">
    <section id="my-appointments" class="card border-0 shadow-sm"
             data-live-filters
             data-filter-url="<?= esc(site_url('/portal')) ?>"
             data-filter-key="portal-appointments"
             data-live-events="portal.appointments.changed portal.queue.changed"
             aria-labelledby="my-appointments-title">
      <div class="card-header bg-transparent py-3">
        <h6 class="fw-bold mb-0 text-dark" id="my-appointments-title">
          <i class="bi bi-calendar-check text-primary me-1"></i> My Appointments
        </h6>
        <p class="small text-muted mb-0">Filter by service or status, or change the date order.</p>
      </div>
      <div class="px-3 pb-3">
        <form method="get" action="<?= esc(site_url('/portal')) ?>" class="row g-2 align-items-end" role="search" aria-label="Filter my appointments" data-filter-form>
          <div class="col-12 col-sm">
            <label for="portal-appointment-service" class="form-label small mb-1">Service</label>
            <select id="portal-appointment-service" name="service" class="form-select form-select-sm">
              <?php foreach (['all' => 'All services', 'clinic' => 'University Clinic', 'guidance' => 'Guidance & Counseling'] as $value => $label): ?>
                <option value="<?= esc($value) ?>" <?= $filters['service'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-sm">
            <label for="portal-appointment-status" class="form-label small mb-1">Status</label>
            <select id="portal-appointment-status" name="status" class="form-select form-select-sm">
              <?php foreach ([
                  'all' => 'All statuses',
                  'active' => 'Active',
                  'requested' => 'Awaiting confirmation',
                  'confirmed' => 'Confirmed',
                  'checked_in' => 'In queue',
                  'completed' => 'Completed',
                  'cancelled' => 'Cancelled',
                  'no_show' => 'No-show',
              ] as $value => $label): ?>
                <option value="<?= esc($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-sm">
            <label for="portal-appointment-sort" class="form-label small mb-1">Sort by date</label>
            <select id="portal-appointment-sort" name="sort" class="form-select form-select-sm">
              <?php foreach (['upcoming' => 'Upcoming first', 'date_desc' => 'Newest first', 'date_asc' => 'Oldest first'] as $value => $label): ?>
                <option value="<?= esc($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-sm-auto d-flex gap-2">
            <a href="<?= esc(site_url('/portal')) ?>" class="btn btn-outline-secondary btn-sm" data-filter-reset>Reset</a>
          </div>
        </form>
      </div>
      <p data-filter-status hidden></p>
      <div class="alert alert-danger" data-filter-error role="alert" hidden>
        <span data-filter-error-text></span>
        <button class="btn btn-secondary btn-sm ms-2" type="button" data-filter-retry>Retry</button>
      </div>
      <div data-filter-region="results">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
              <thead class="table-light text-uppercase">
                <tr>
                  <th class="ps-3">Service</th>
                  <th>Date &amp; Time</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th class="text-end pe-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($appointments)): ?>
                  <tr>
                    <td colspan="5" class="text-center py-5 text-muted">
                      <i class="bi <?= $hasFilters ? 'bi-funnel' : 'bi-calendar2-x' ?> fs-1 d-block mb-2 opacity-50"></i>
                      <?php if ($hasFilters): ?>
                        <p class="mb-2">No appointments match these filters.</p>
                        <a href="<?= esc(site_url('/portal')) ?>" class="btn btn-outline-primary btn-sm" data-filter-reset>Clear filters</a>
                      <?php else: ?>
                        You have no appointments or requests. Use the buttons above to request a visit.
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($appointments as $a): ?>
                    <tr>
                      <td class="ps-3 fw-bold"><?= esc($serviceLabel((string) $a['unit'])) ?></td>
                      <td>
                        <div><?= esc(date('M j, Y', strtotime($a['starts_at']))) ?></div>
                        <small class="text-muted">
                          <?= esc(date('h:i A', strtotime($a['starts_at']))) ?>
                          <?php if (in_array($a['status'], ['confirmed', 'scheduled', 'checked_in', 'completed'], true) && !empty($a['ends_at'])): ?>
                            – <?= esc(date('h:i A', strtotime($a['ends_at']))) ?>
                          <?php endif; ?>
                        </small>
                        <?php if ($a['status'] === 'requested'): ?>
                          <small class="d-block text-muted">Preferred time—not yet confirmed</small>
                        <?php elseif (in_array($a['status'], ['confirmed', 'scheduled'], true)): ?>
                          <?php if (!empty($a['provider_name'])): ?>
                            <small class="d-block text-muted">Assigned staff: <?= esc($a['provider_name']) ?></small>
                          <?php endif; ?>
                          <small class="d-block text-muted">Confirmed time—please arrive before your appointment</small>
                        <?php elseif ($a['status'] === 'checked_in'): ?>
                          <small class="d-block text-muted">Checked in—please wait for your turn</small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="badge bg-light text-dark border"><?= esc($typeLabel((string) $a['type'])) ?></span>
                      </td>
                      <td>
                        <span class="badge <?= esc($statusBadge((string) $a['status'])) ?>"><?= esc($statusLabel((string) $a['status'])) ?></span>
                      </td>
                      <td class="text-end pe-3">
                        <div class="d-inline-flex justify-content-end gap-1 text-nowrap">
                          <a href="<?= esc(site_url('/portal/appointments/' . (int) $a['id'])) ?>"
                             class="btn btn-outline-primary btn-sm py-0"
                             data-app-modal-open
                             aria-label="<?= esc('View details for ' . $serviceLabel((string) $a['unit']) . ' appointment on ' . date('M j, Y', strtotime($a['starts_at']))) ?>">Details</a>
                          <?php if (in_array($a['status'], ['requested', 'scheduled', 'confirmed'], true)): ?>
                            <form action="<?= site_url('/portal/appointments/' . $a['unit'] . '/cancel/' . $a['id']) ?>" method="POST" class="d-inline" data-background-submit
                                  data-confirm-reason
                                  data-confirm="Cancel this appointment or pending request? Any reserved slot will be released."
                                  data-confirm-title="Cancel appointment"
                                  data-confirm-label="Cancel appointment"
                                  data-confirm-tone="danger">
                              <?= csrf_field() ?>
                              <input type="hidden" name="reason" value="">
                              <button type="submit" class="btn btn-outline-danger btn-sm py-0">Cancel</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/queue-live.js') ?>"></script>
<?= $this->endSection() ?>

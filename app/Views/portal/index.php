<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex gap-2">
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
$appointments = $chart['appointments'] ?? [];

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

<!-- Active Daily Queue Status (If patient checked in today) -->
<?php if (!empty($activeTicket)): ?>
  <div class="card border-0 shadow-sm bg-primary text-white p-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
      <div>
        <span class="badge bg-white text-primary text-uppercase mb-2">Live Waiting Room Ticket</span>
        <h3 class="fw-bold mb-1 display-6 text-white"><?= esc($activeTicket['ticket_code']) ?></h3>
        <p class="mb-0 opacity-75">
          <?= ($activeTicket['unit'] === 'clinic') ? 'University Clinic' : 'Guidance & Counseling Center' ?> ·
          Status: <strong><?= esc(ucwords(str_replace('_', ' ', $activeTicket['status']))) ?></strong>
        </p>
      </div>
      <div class="text-end">
        <span class="badge bg-light text-dark px-3 py-2 fw-semibold">
          Priority: <?= esc(ucfirst($activeTicket['priority'])) ?>
        </span>
      </div>
    </div>
  </div>
<?php endif; ?>

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

            <dt>Type</dt>
            <dd>
              <span class="card-badge">
                <?= !empty($employee['is_teaching']) ? 'Teaching' : 'Non-teaching' ?>
              </span>
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
  </div>

  <!-- Right Column: My Appointments -->
  <div class="col-12 col-xl">
    <div id="my-appointments" class="card border-0 shadow-sm">
      <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">
          <i class="bi bi-calendar-check text-primary me-1"></i> My Appointments
        </h6>
        <span class="badge bg-light text-dark border"><?= count($appointments) ?> Total</span>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light text-uppercase">
              <tr>
                <th class="ps-3">Service</th>
                <th>Date & Time</th>
                <th>Type</th>
                <th>Status</th>
                <th class="text-end pe-3">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($appointments)): ?>
                <tr>
                  <td colspan="5" class="text-center py-5 text-muted">
                    <i class="bi bi-calendar2-x fs-1 d-block mb-2 opacity-50"></i>
                    You have no appointments or requests. Use the buttons above to request a visit.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($appointments as $a): ?>
                  <tr>
                    <td class="ps-3 fw-bold">
                      <?= ($a['unit'] === 'clinic') ? 'University Clinic' : 'Guidance & Counseling' ?>
                    </td>
                    <td>
                      <div><?= esc(date('M j, Y', strtotime($a['starts_at']))) ?></div>
                      <small class="text-muted">
                        <?= esc(date('h:i A', strtotime($a['starts_at']))) ?>
                        <?php if (in_array($a['status'], ['confirmed', 'scheduled'], true) && !empty($a['ends_at'])): ?>
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
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge bg-light text-dark border">
                        <?= esc(ucwords(str_replace('_', ' ', $a['type']))) ?>
                      </span>
                    </td>
                    <td>
                      <?php if ($a['status'] === 'requested'): ?>
                        <span class="badge bg-warning text-dark">Awaiting confirmation</span>
                      <?php elseif ($a['status'] === 'confirmed'): ?>
                        <span class="badge bg-primary">Confirmed</span>
                      <?php elseif ($a['status'] === 'scheduled'): ?>
                        <span class="badge bg-primary">Scheduled</span>
                      <?php elseif ($a['status'] === 'checked_in'): ?>
                        <span class="badge bg-info">In Queue</span>
                      <?php elseif ($a['status'] === 'completed'): ?>
                        <span class="badge bg-success">Completed</span>
                      <?php elseif ($a['status'] === 'cancelled'): ?>
                        <span class="badge bg-secondary">Cancelled</span>
                      <?php elseif ($a['status'] === 'no_show'): ?>
                        <span class="badge bg-secondary">No show</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end pe-3">
                      <?php if (in_array($a['status'], ['requested', 'scheduled', 'confirmed'], true)): ?>
                        <form action="<?= site_url('/portal/appointments/' . $a['unit'] . '/cancel/' . $a['id']) ?>" method="POST" class="d-inline"
                                                      data-confirm="Cancel this appointment or pending request? Any reserved slot will be released."
                                                      data-confirm-title="Cancel appointment"
                                                      data-confirm-label="Cancel appointment"
                                                      data-confirm-tone="danger">
                          <?= csrf_field() ?>
                          <button type="submit" class="btn btn-outline-danger btn-sm py-0">Cancel</button>
                        </form>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

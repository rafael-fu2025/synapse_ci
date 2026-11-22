<?= $this->extend('layouts/app') ?>
<?php
$user = session()->get('user');
$role = $user['role'] ?? 'student';
$person = $chart['person'] ?? [];
$student = $chart['student_profile'] ?? null;
$employee = $chart['employee_profile'] ?? null;
$health = $chart['health_profile'] ?? [];
$appointments = $appointments ?? ($chart['appointments'] ?? []);
$filters = array_replace(['service' => 'all', 'status' => 'all', 'sort' => 'upcoming', 'per_page' => 25], $filters ?? []);
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
$statusTone = static fn (string $status): string => match ($status) {
    'requested' => 'is-requested',
    'scheduled', 'confirmed' => 'is-confirmed',
    'checked_in' => 'is-queue',
    'completed' => 'is-completed',
    default => 'is-inactive',
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

$unreadAnnouncementsCount = max(0, (int) ($unreadAnnouncementsCount ?? 0));
$availableSurveysCount = max(0, (int) ($availableSurveysCount ?? 0));
$miOpenCount = is_array($activeMiAssignments ?? null) ? count($activeMiAssignments) : 0;
$surveysBadgeCount = $availableSurveysCount + $miOpenCount;
$callSlipsList = is_array($pendingCallSlips ?? null) ? $pendingCallSlips : [];
$callSlipsCount = count($callSlipsList);
$unacknowledgedCallSlipsCount = 0;
foreach ($callSlipsList as $csItem) {
    if (($csItem['attendance_status'] ?? 'pending') === 'pending') {
        $unacknowledgedCallSlipsCount++;
    }
}
$callSlipsBadgeCount = $unacknowledgedCallSlipsCount > 0 ? $unacknowledgedCallSlipsCount : $callSlipsCount;
$appointmentsTooltip = 'My appointments: View and filter your Clinic and Guidance appointment schedule.';
$announcementsTooltip = 'Campus announcements: Read current notices from the Clinic and Guidance teams.'
    . ($unreadAnnouncementsCount > 0 ? ' (' . $unreadAnnouncementsCount . ' unread)' : '');
$surveysTooltip = 'My surveys: View eligible Guidance surveys, assigned Multiple Intelligence assessments, and your submission status.'
    . ($surveysBadgeCount > 0 ? ' (' . $surveysBadgeCount . ' available or assigned)' : '');
$referralTooltip = 'Guidance referral: Submit confidential referrals for one or more students without leaving the portal.';
$evaluationTooltip = 'Service evaluation: Redeem a pseudonymous Guidance evaluation token to share confidential feedback.';
$settingsTooltip = 'Profile & settings: Manage your account photo, emergency contact, and personal settings.';
$callSlipsTooltip = $unacknowledgedCallSlipsCount > 0
    ? 'Guidance Call Slips: ' . $unacknowledgedCallSlipsCount . ' call slip(s) awaiting acknowledgment. Click to view call slips.'
    : ($callSlipsCount > 0
        ? 'Guidance Call Slips: ' . $callSlipsCount . ' active call slip(s). Click to view all call slips.'
        : 'Guidance Call Slips: View active and past Guidance Office call slips.');
?>

<?= $this->section('page_actions') ?>
<nav class="portal-app-dock" aria-label="Student and Employee quick services">
  <a href="<?= esc(site_url('/portal')) ?>"
     class="portal-app-tile is-active"
     data-portal-pane-link="appointments"
     data-app-tooltip="<?= esc($appointmentsTooltip, 'attr') ?>"
     aria-label="<?= esc($appointmentsTooltip, 'attr') ?>"
     aria-current="page">
    <span class="portal-app-icon" aria-hidden="true">
      <i class="bi bi-calendar2-check-fill"></i>
    </span>
    <span class="portal-app-label">Appointments</span>
    <span class="visually-hidden">My Appointments</span>
  </a>

  <a href="<?= esc(site_url('/portal/announcements')) ?>"
     class="portal-app-tile"
     data-portal-pane-link="announcements"
     data-app-tooltip="<?= esc($announcementsTooltip, 'attr') ?>"
     aria-label="<?= esc($announcementsTooltip, 'attr') ?>">
    <?php if ($unreadAnnouncementsCount > 0): ?>
      <span class="portal-app-badge" data-portal-badge="announcements" aria-label="<?= esc((string) $unreadAnnouncementsCount, 'attr') ?> unread announcements">
        <?= $unreadAnnouncementsCount > 99 ? '99+' : $unreadAnnouncementsCount ?>
      </span>
    <?php endif; ?>
    <span class="portal-app-icon" aria-hidden="true">
      <i class="bi bi-megaphone-fill"></i>
    </span>
    <span class="portal-app-label">Announcements</span>
    <span class="visually-hidden">Campus announcements</span>
  </a>

  <a href="<?= esc(site_url('/portal/surveys')) ?>"
     class="portal-app-tile"
     data-portal-pane-link="surveys"
     data-app-tooltip="<?= esc($surveysTooltip, 'attr') ?>"
     aria-label="<?= esc($surveysTooltip, 'attr') ?>">
    <?php if ($surveysBadgeCount > 0): ?>
      <span class="portal-app-badge" data-portal-badge="surveys" aria-label="<?= esc((string) $surveysBadgeCount, 'attr') ?> available or assigned surveys">
        <?= $surveysBadgeCount > 99 ? '99+' : $surveysBadgeCount ?>
      </span>
    <?php endif; ?>
    <span class="portal-app-icon" aria-hidden="true">
      <i class="bi bi-clipboard2-check-fill"></i>
    </span>
    <span class="portal-app-label">My Surveys</span>
  </a>

  <?php if ($isEmployee): ?>
    <a href="<?= esc(site_url('/portal/referrals/create')) ?>"
       class="portal-app-tile"
       data-portal-pane-link="referrals"
       data-app-tooltip="<?= esc($referralTooltip, 'attr') ?>"
       aria-label="<?= esc($referralTooltip, 'attr') ?>">
      <span class="portal-app-icon" aria-hidden="true">
        <i class="bi bi-envelope-paper-heart-fill"></i>
      </span>
      <span class="portal-app-label">Referral</span>
      <span class="visually-hidden">Guidance referral</span>
    </a>
  <?php endif; ?>

  <a href="<?= esc(site_url('/portal/evaluations/redeem')) ?>"
     class="portal-app-tile"
     data-portal-pane-link="evaluations"
     data-app-tooltip="<?= esc($evaluationTooltip, 'attr') ?>"
     aria-label="<?= esc($evaluationTooltip, 'attr') ?>">
    <span class="portal-app-icon" aria-hidden="true">
      <i class="bi bi-chat-square-heart-fill"></i>
    </span>
    <span class="portal-app-label">Evaluation</span>
    <span class="visually-hidden">Service evaluation</span>
  </a>

  <?php if (!empty($person['id']) || !empty($user['person_id'])): ?>
    <a href="<?= esc(site_url('/portal/settings')) ?>"
       class="portal-app-tile"
       data-portal-pane-link="settings"
       data-app-tooltip="<?= esc($settingsTooltip, 'attr') ?>"
       aria-label="<?= esc($settingsTooltip, 'attr') ?>">
      <span class="portal-app-icon" aria-hidden="true">
        <i class="bi bi-person-gear"></i>
      </span>
      <span class="portal-app-label">Settings</span>
      <span class="visually-hidden">Profile and settings</span>
    </a>
  <?php endif; ?>

  <a href="<?= esc(site_url('/portal/call-slips')) ?>"
     class="portal-app-tile"
     data-portal-pane-link="callslips"
     data-portal-callslips-tile
     data-app-tooltip="<?= esc($callSlipsTooltip, 'attr') ?>"
     aria-label="<?= esc($callSlipsTooltip, 'attr') ?>">
    <?php if ($unacknowledgedCallSlipsCount > 0): ?>
      <span class="portal-app-badge" data-portal-badge="callslips"
            aria-label="<?= esc((string) $unacknowledgedCallSlipsCount, 'attr') ?> unacknowledged guidance call slips">
        <?= $unacknowledgedCallSlipsCount > 99 ? '99+' : $unacknowledgedCallSlipsCount ?>
      </span>
    <?php endif; ?>
    <span class="portal-app-icon" aria-hidden="true">
      <i class="bi bi-ticket-perforated-fill"></i>
    </span>
    <span class="portal-app-label">Call Slips</span>
    <span class="visually-hidden">Guidance Call Slips</span>
  </a>
</nav>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<!-- Staff Role Console Switcher Banner (Shown only if staff logs into personal account) -->
<?php if (in_array($role, ['superadmin', 'clinic_admin', 'clinic_staff', 'guidance_admin', 'guidance_supervisor', 'counsellor', 'bmg_admin', 'facilities_op'], true)): ?>
  <div class="portal-staff-session-banner login-banner mb-4" role="region" aria-label="Departmental console switch">
    <div class="portal-staff-banner-left">
      <div class="portal-staff-banner-icon" aria-hidden="true">
        <i class="bi bi-person-badge"></i>
      </div>
      <div class="portal-staff-banner-copy">
        <div class="portal-staff-banner-header">
          <strong class="portal-staff-banner-title">Staff Member Personal Health Session</strong>
          <span class="portal-staff-banner-badge">Personal Account</span>
        </div>
        <p class="portal-staff-banner-desc mb-0">You are logged into your individual employee patient account. Switch to your departmental console below.</p>
      </div>
    </div>
    <div class="portal-staff-banner-actions">
      <?php if (in_array($role, ['superadmin', 'clinic_admin', 'clinic_staff'], true)): ?>
        <a href="<?= \App\Services\PortalContext::urlFor('clinic', 'clinic') ?>" class="portal-staff-console-btn">
          <?= view('partials/unit_vector_icon', ['unit' => 'clinic', 'class' => 'unit-btn-icon me-1']) ?> Clinic Staff Console
        </a>
      <?php endif; ?>
      <?php if (in_array($role, ['superadmin', 'guidance_admin', 'guidance_supervisor', 'counsellor'], true)): ?>
        <a href="<?= \App\Services\PortalContext::urlFor('guidance', 'guidance') ?>" class="portal-staff-console-btn">
          <?= view('partials/unit_vector_icon', ['unit' => 'guidance', 'class' => 'unit-btn-icon me-1']) ?> Guidance Console
        </a>
      <?php endif; ?>
      <?php if (in_array($role, ['superadmin', 'bmg_admin', 'facilities_op'], true)): ?>
        <a href="<?= \App\Services\PortalContext::urlFor('bmg', 'facilities/dashboard') ?>" class="portal-staff-console-btn">
          <i class="bi bi-recycle me-1" aria-hidden="true"></i> Facilities Console
        </a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($pinnedAdvisories)): ?>
  <div class="mb-4" data-pinned-advisories>
    <?php foreach ($pinnedAdvisories as $adv): ?>
      <div class="alert <?= $adv['unit'] === 'clinic' ? 'alert-warning border-warning' : 'alert-info border-info' ?> border-start border-4 shadow-sm mb-2">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <span class="badge <?= $adv['unit'] === 'clinic' ? 'bg-danger' : 'bg-primary' ?> text-uppercase me-2"><?= esc(ucfirst($adv['unit'])) ?> Advisory</span>
            <strong><?= esc($adv['title']) ?></strong>
          </div>
          <a href="<?= esc(site_url('/portal/announcements')) ?>" class="btn btn-outline-dark btn-sm py-0" data-portal-pane-target="announcements">Read Full Notice</a>
        </div>
        <p class="small mb-0 mt-2 text-break" style="white-space: pre-line;"><?= esc(mb_strimwidth((string) $adv['body'], 0, 280, '…')) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="row g-4 align-items-start portal-main-row">
  <!-- Left Column: Live Waiting Room Tickets, Student / Employee ATM Profile Card, and Request Actions -->
  <div class="col-12 col-xl-auto portal-left-rail">
    <?= view('portal/_queue_tickets', ['activeTickets' => $activeTickets ?? (!empty($activeTicket) ? [$activeTicket] : [])]) ?>

    <div class="portal-profile-stack mb-3" data-portal-profile-stack>
      <div class="portal-profile-card" title="Medical Flags and University Profile">
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

        <button type="button"
                class="portal-emergency-toggle"
                data-portal-emergency-toggle
                aria-expanded="false"
                aria-controls="portalEmergencyDrawer"
                title="Emergency Contact"
                aria-label="Toggle Emergency Contact card">
          <i class="bi bi-telephone-plus-fill" aria-hidden="true"></i>
        </button>
      </div>

      <!-- Emergency Contact Card (Stacked drawer behind the profile card) -->
      <div id="portalEmergencyDrawer"
           class="portal-emergency-drawer"
           data-portal-emergency-drawer
           aria-hidden="true">
        <div class="portal-emergency-drawer-inner">
          <div class="card border-0 shadow-sm p-3 mb-0 portal-emergency-card" data-portal-emergency-card aria-labelledby="portalEmergencyCardTitle">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="fw-bold mb-0 portal-emergency-title" id="portalEmergencyCardTitle">
                <i class="bi bi-telephone-plus me-1" aria-hidden="true"></i> Emergency Contact
              </h6>
              <a href="<?= esc(site_url('portal/settings')) ?>" class="btn btn-sm py-0 portal-emergency-edit-btn">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
              </a>
            </div>
            <div data-portal-emergency-content>
              <?php if (!empty($health['emergency_contact_name']) || !empty($health['emergency_contact_phone'])): ?>
                <dl class="row mb-0 small portal-emergency-dl">
                  <dt class="col-5 fw-normal">Contact:</dt>
                  <dd class="col-7 fw-semibold mb-1 text-break"><?= esc($health['emergency_contact_name'] ?? '—') ?></dd>
                  <?php if (!empty($health['emergency_contact_relationship'])): ?>
                    <dt class="col-5 fw-normal">Relationship:</dt>
                    <dd class="col-7 mb-1 text-break"><?= esc($health['emergency_contact_relationship']) ?></dd>
                  <?php endif; ?>
                  <?php if (!empty($health['emergency_contact_phone'])): ?>
                    <dt class="col-5 fw-normal">Phone:</dt>
                    <dd class="col-7 mb-0 text-break"><a href="tel:<?= esc(preg_replace('/[^\d+]/', '', $health['emergency_contact_phone'])) ?>" class="text-decoration-none portal-emergency-phone"><?= esc($health['emergency_contact_phone']) ?></a></dd>
                  <?php endif; ?>
                </dl>
              <?php else: ?>
                <p class="small mb-2 portal-emergency-empty">No emergency contact recorded yet. Add one so clinic staff know who to reach in an emergency.</p>
                <a href="<?= esc(site_url('portal/settings')) ?>" class="btn btn-sm portal-emergency-edit-btn">
                  <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Add emergency contact
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <section class="portal-request-actions" aria-label="Request a campus visit">
      <a href="<?= esc(site_url('/portal/appointments/clinic/create'), 'attr') ?>" class="btn btn-primary portal-request-btn" data-app-modal-open>
        <?= view('partials/unit_vector_icon', ['unit' => 'clinic', 'class' => 'portal-request-icon']) ?>
        <span>Request Clinic Visit</span>
      </a>
      <a href="<?= esc(site_url('/portal/appointments/guidance/create'), 'attr') ?>" class="btn btn-primary portal-request-btn" data-app-modal-open>
        <?= view('partials/unit_vector_icon', ['unit' => 'guidance', 'class' => 'portal-request-icon']) ?>
        <span>Request Guidance Session</span>
      </a>
    </section>
  </div>

  <!-- Right Column: Dynamic Workspace Pane -->
  <div class="col-12 col-xl portal-workspace-col">
    <div id="portal-pane-styles" hidden></div>

    <div id="portal-workspace-pane"
         class="portal-workspace-pane"
         data-portal-workspace-pane
         data-active-pane="appointments"
         data-workspace-url="<?= esc(site_url('/portal'), 'attr') ?>"
         aria-live="polite">
      <div class="portal-workspace-header pb-2 mb-3 border-bottom">
        <div>
          <h2 class="h5 fw-bold mb-0 portal-workspace-title" id="my-appointments-title">My Appointments</h2>
        </div>
      </div>
      <?php
      $pendingOnlySlips = array_values(array_filter(
          $callSlipsList,
          static fn (array $cs): bool => ($cs['attendance_status'] ?? 'pending') === 'pending'
      ));
      ?>
      <div class="pa-workspace">
        <?php if ($unacknowledgedCallSlipsCount > 0): ?>
          <section class="pa-callslip-sheet mb-3 portal-callslip-strip" data-portal-callslips-strip aria-label="Guidance Call Slips awaiting acknowledgment">
            <div class="pa-callslip-head">
              <div class="pa-callslip-head-left">
                <i class="bi bi-ticket-perforated" aria-hidden="true"></i>
                <span class="pa-callslip-title">Guidance Call Slips</span>
                <span class="pa-callslip-sep" aria-hidden="true">·</span>
                <span class="pa-callslip-status">
                  <?= $unacknowledgedCallSlipsCount ?> awaiting acknowledgment
                </span>
              </div>
              <div class="pa-callslip-head-right">
                <a href="<?= esc(site_url('/portal/call-slips')) ?>" class="pa-callslip-link" data-portal-pane-target="callslips">
                  View all<?= $callSlipsCount > 0 ? ' (' . $callSlipsCount . ')' : '' ?>
                </a>
                <button type="button"
                        class="pa-callslip-toggle"
                        data-bs-toggle="collapse"
                        data-bs-target="#portalCallSlipsBody"
                        aria-expanded="true"
                        aria-controls="portalCallSlipsBody">
                  <span>Toggle</span>
                  <i class="bi bi-chevron-down small" aria-hidden="true"></i>
                </button>
              </div>
            </div>
            <div id="portalCallSlipsBody" class="collapse show">
              <div class="pa-callslip-list">
                <?php foreach ($pendingOnlySlips as $cs): ?>
                  <?php
                  $csStatus = (string) ($cs['attendance_status'] ?? 'pending');
                  $csSlipNumber = (string) ($cs['call_slip_number'] ?? '#' . (int) ($cs['id'] ?? 0));
                  $csSchedule = trim((string) ($cs['call_date'] ?? '') . ' ' . (string) ($cs['call_time'] ?? ''));
                  $csPurpose = ucwords(str_replace('_', ' ', (string) ($cs['purpose'] ?? 'counseling')));
                  ?>
                  <div class="pa-callslip-row portal-callslip-item">
                    <div class="pa-callslip-meta">
                      <span class="pa-callslip-num"><?= esc($csSlipNumber) ?></span>
                      <?php if ($csSchedule !== ''): ?>
                        <span class="pa-callslip-sep" aria-hidden="true">·</span>
                        <span class="pa-callslip-time"><?= esc($csSchedule) ?></span>
                      <?php endif; ?>
                      <span class="pa-callslip-sep" aria-hidden="true">·</span>
                      <span class="pa-callslip-purpose"><?= esc($csPurpose) ?></span>
                      <span class="pa-callslip-sep" aria-hidden="true">·</span>
                      <span class="pa-callslip-state"><?= esc(ucfirst($csStatus)) ?></span>
                    </div>
                    <div class="pa-callslip-action">
                      <form method="post" action="<?= esc(site_url('/portal/guidance/call-slips/' . (int) ($cs['id'] ?? 0) . '/acknowledge'), 'attr') ?>" class="d-inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-primary btn-sm pa-btn pa-btn-sm">Acknowledge receipt</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </section>
        <?php endif; ?>

        <section id="my-appointments" class="pa-sheet"
                 data-live-filters
                 data-mutation-refresh
                 data-refresh-owner="appointments.portal"
                 data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>"
                 data-filter-url="<?= esc(site_url('/portal')) ?>"
                 data-filter-key="portal-appointments"
                 data-live-events="portal.appointments.changed portal.queue.changed"
                 aria-labelledby="my-appointments-title">
          <div class="pa-band pa-filter-band">
            <div class="pa-intro">
              <h3 class="pa-intro-title">Appointment Schedule &amp; Visit Requests</h3>
              <p class="pa-intro-desc">Filter by service or status, or change the date order.</p>
            </div>

            <form method="get" action="<?= esc(site_url('/portal')) ?>" class="pa-filter-grid" role="search" aria-label="Filter my appointments" data-filter-form>
              <div class="pa-filter-field">
                <label for="portal-appointment-service" class="pa-filter-label">Service</label>
                <select id="portal-appointment-service" name="service" class="form-select form-select-sm">
                  <?php foreach (['all' => 'All services', 'clinic' => 'University Clinic', 'guidance' => 'Guidance & Counseling'] as $value => $label): ?>
                    <option value="<?= esc($value) ?>" <?= $filters['service'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="pa-filter-field">
                <label for="portal-appointment-status" class="pa-filter-label">Status</label>
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
              <div class="pa-filter-field">
                <label for="portal-appointment-sort" class="pa-filter-label">Sort by date</label>
                <select id="portal-appointment-sort" name="sort" class="form-select form-select-sm">
                  <?php foreach (['upcoming' => 'Upcoming first', 'date_desc' => 'Newest first', 'date_asc' => 'Oldest first'] as $value => $label): ?>
                    <option value="<?= esc($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="pa-filter-field">
                <label for="portal-appointment-per-page" class="pa-filter-label">Rows per page</label>
                <select id="portal-appointment-per-page" name="per_page" class="form-select form-select-sm">
                  <?php foreach ([20, 25, 50, 100] as $size): ?>
                    <option value="<?= $size ?>" <?= (int) $filters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="pa-filter-actions">
                <a href="<?= esc(site_url('/portal')) ?>" class="btn btn-outline-secondary btn-sm pa-btn portal-filter-reset-btn" data-filter-reset>Reset</a>
              </div>
            </form>

            <p data-filter-status hidden></p>
            <div class="alert alert-danger" data-filter-error role="alert" hidden>
              <span data-filter-error-text></span>
              <button class="btn btn-secondary btn-sm ms-2" type="button" data-filter-retry>Retry</button>
            </div>
          </div>

          <div class="pa-results-region" data-filter-region="results" data-refresh-region="appointments.results">
            <?php if (empty($appointments)): ?>
              <div class="pa-empty">
                <i class="bi <?= $hasFilters ? 'bi-funnel' : 'bi-calendar2-x' ?>" aria-hidden="true"></i>
                <h3><?= $hasFilters ? 'No matching appointments' : 'No appointments or requests yet' ?></h3>
                <?php if ($hasFilters): ?>
                  <p>No appointments match these filters.</p>
                  <a href="<?= esc(site_url('/portal')) ?>" class="btn btn-outline-secondary btn-sm pa-btn" data-filter-reset>Clear filters</a>
                <?php else: ?>
                  <p>You have no appointments or requests. Use the Request Clinic Visit or Request Guidance Session buttons to book a visit.</p>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="pa-table-wrap">
                <table class="pa-table">
                  <thead>
                    <tr>
                      <th>Service</th>
                      <th>Date &amp; Time</th>
                      <th>Type</th>
                      <th>Status</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($appointments as $a): ?>
                      <?php $unitKey = (string) ($a['unit'] ?? 'clinic'); ?>
                      <tr>
                        <td>
                          <div class="pa-service-cell">
                            <?= view('partials/unit_vector_icon', ['unit' => $unitKey === 'clinic' ? 'clinic' : 'guidance', 'class' => 'pa-unit-icon']) ?>
                            <div class="pa-service-copy">
                              <span class="pa-service-name"><?= esc($serviceLabel($unitKey)) ?></span>
                              <?php if (in_array($a['status'], ['confirmed', 'scheduled'], true) && !empty($a['provider_name'])): ?>
                                <span class="pa-service-provider">Assigned staff: <?= esc($a['provider_name']) ?></span>
                              <?php endif; ?>
                            </div>
                          </div>
                        </td>
                        <td>
                          <div class="pa-datetime-cell">
                            <span class="pa-date"><?= esc(date('M j, Y', strtotime($a['starts_at']))) ?></span>
                            <span class="pa-time">
                              <?= esc(date('h:i A', strtotime($a['starts_at']))) ?><?php if (in_array($a['status'], ['confirmed', 'scheduled', 'checked_in', 'completed'], true) && !empty($a['ends_at'])): ?> – <?= esc(date('h:i A', strtotime($a['ends_at']))) ?><?php endif; ?>
                            </span>
                            <?php if ($a['status'] === 'requested'): ?>
                              <span class="pa-schedule-note">Preferred time—not yet confirmed</span>
                            <?php elseif (in_array($a['status'], ['confirmed', 'scheduled'], true)): ?>
                              <span class="pa-schedule-note">Confirmed time—please arrive before your appointment</span>
                            <?php elseif ($a['status'] === 'checked_in'): ?>
                              <span class="pa-schedule-note">Checked in—please wait for your turn</span>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td>
                          <span class="pa-type-label"><?= esc($typeLabel((string) $a['type'])) ?></span>
                        </td>
                        <td>
                          <span class="pa-status <?= esc($statusTone((string) $a['status'])) ?>"><?= esc($statusLabel((string) $a['status'])) ?></span>
                        </td>
                        <td class="text-end">
                          <div class="pa-actions">
                            <a href="<?= esc(site_url('/portal/appointments/' . (int) $a['id'])) ?>"
                               class="btn btn-outline-secondary btn-sm pa-action-btn"
                               data-app-modal-open
                               aria-label="<?= esc('View details for ' . $serviceLabel($unitKey) . ' appointment on ' . date('M j, Y', strtotime($a['starts_at']))) ?>">Details</a>
                            <?php if (in_array($a['status'], ['requested', 'scheduled', 'confirmed'], true)): ?>
                              <form action="<?= site_url('/portal/appointments/' . $a['unit'] . '/cancel/' . $a['id']) ?>" method="POST" class="d-inline" data-background-submit
                                    data-confirm-reason
                                    data-confirm="Cancel this appointment or pending request? Any reserved slot will be released."
                                    data-confirm-title="Cancel appointment"
                                    data-confirm-label="Cancel appointment"
                                    data-confirm-tone="danger">
                                <?= csrf_field() ?>
                                <input type="hidden" name="reason" value="">
                                <button type="submit" class="btn btn-outline-danger btn-sm pa-action-btn">Cancel</button>
                              </form>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <div class="pa-pagination-band">
              <?= view('partials/table_pagination', [
                  'pagination' => $pagination ?? [],
                  'filters' => $filters,
                  'rowCount' => count($appointments),
                  'baseUrl' => site_url('/portal'),
                  'paginationPageParam' => 'page',
                  'paginationExtraQuery' => ['service' => $filters['service'], 'sort' => $filters['sort']],
                  'paginationLiveFilters' => true,
                  'paginationLabel' => 'My appointment pages',
                  'totalLabel' => 'total appointments',
              ]) ?>
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
  /* overflow-x: clip preserves horizontal containment without creating a scroll container that blocks sticky. */
  #main.main,
  #main.main > .main-content {
    overflow-x: clip;
    overflow-y: visible;
  }
  .portal-workspace-col {
    min-width: 0;
  }
  @media (min-width: 1200px) {
    .portal-left-rail {
      position: sticky;
      top: calc(var(--header-height, 64px) + 1rem);
      align-self: flex-start;
      z-index: 10;
    }
    .portal-request-actions {
      max-width: 360px;
    }
  }
  .portal-profile-stack {
    position: relative;
    z-index: 1;
    width: 360px;
    max-width: 100%;
  }
  .portal-profile-stack .portal-profile-card {
    position: relative;
    z-index: 2;
  }
  .portal-profile-stack .portal-profile-card .card-details {
    padding-right: 2.5rem;
  }
  .portal-emergency-toggle {
    position: absolute;
    right: 0;
    bottom: 0;
    z-index: 3;
    width: 40px;
    height: 34px;
    padding: 0;
    border-radius: 10px 0 13px 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    color: var(--fu-maroon, #800000);
    border: 2px solid #ffffff;
    font-size: 0.92rem;
    cursor: pointer;
    transition: background-color 0.22s ease, color 0.22s ease, border-color 0.22s ease;
  }
  .portal-emergency-toggle:hover,
  .portal-emergency-toggle:focus-visible {
    background: rgba(255, 255, 255, 0.92);
    color: #5a0000;
    outline: 2px solid rgba(255, 255, 255, 0.7);
    outline-offset: -4px;
  }
  .portal-profile-stack.is-emergency-open .portal-emergency-toggle {
    background: transparent;
    color: #ffffff;
    border-top: 2px solid #ffffff;
    border-left: 2px solid #ffffff;
    border-right: 0;
    border-bottom: 0;
  }
  .portal-profile-stack.is-emergency-open .portal-emergency-toggle:hover,
  .portal-profile-stack.is-emergency-open .portal-emergency-toggle:focus-visible {
    background: rgba(255, 255, 255, 0.14);
    color: #ffffff;
  }
  .portal-emergency-drawer {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-rows: 0fr;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    margin-top: 0;
    transition:
      grid-template-rows 0.34s cubic-bezier(0.22, 1, 0.36, 1),
      margin-top 0.34s cubic-bezier(0.22, 1, 0.36, 1),
      opacity 0.24s ease,
      visibility 0.34s;
  }
  .portal-emergency-drawer-inner {
    min-height: 0;
    overflow: hidden;
  }
  .portal-emergency-card {
    padding-top: calc(1rem + 14px) !important;
    border-radius: 0 0 14px 14px !important;
    background: linear-gradient(180deg, #a62424 0%, #841818 100%);
    color: #ffffff;
    box-shadow: inset 0 8px 14px -6px rgba(0, 0, 0, 0.38) !important;
    transform: translateY(-24px);
    transition: transform 0.34s cubic-bezier(0.22, 1, 0.36, 1);
  }
  .portal-profile-stack.is-emergency-open .portal-emergency-drawer {
    grid-template-rows: 1fr;
    margin-top: -14px;
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    filter: drop-shadow(0 12px 18px rgba(128, 0, 0, 0.25));
  }
  .portal-profile-stack.is-emergency-open .portal-emergency-card {
    transform: translateY(0);
  }
  .portal-emergency-title {
    color: #ffffff;
    font-size: 0.85rem;
  }
  .portal-emergency-dl dt {
    color: rgba(255, 255, 255, 0.72);
  }
  .portal-emergency-dl dd,
  .portal-emergency-phone,
  .portal-emergency-empty {
    color: #ffffff;
  }
  .portal-emergency-phone:hover {
    text-decoration: underline !important;
  }
  .portal-emergency-empty {
    color: rgba(255, 255, 255, 0.85);
  }
  .portal-emergency-edit-btn {
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.45);
    background: rgba(255, 255, 255, 0.1);
  }
  .portal-emergency-edit-btn:hover,
  .portal-emergency-edit-btn:focus-visible {
    color: #800000;
    background: #ffffff;
    border-color: #ffffff;
  }
  [data-theme="dark"] .portal-emergency-card {
    background: linear-gradient(180deg, #2d2020 0%, #1f1717 100%);
    border: 1px solid rgba(255, 255, 255, 0.12) !important;
    border-top: 0 !important;
    box-shadow: inset 0 8px 14px -6px rgba(0, 0, 0, 0.55) !important;
  }
  [data-theme="dark"] .portal-profile-stack.is-emergency-open .portal-emergency-drawer {
    filter: drop-shadow(0 12px 18px rgba(0, 0, 0, 0.45));
  }
  [data-theme="dark"] .portal-emergency-toggle {
    background: #f0f0f0;
    color: #1c1c1c;
    border-color: #f0f0f0;
  }
  [data-theme="dark"] .portal-emergency-toggle:hover,
  [data-theme="dark"] .portal-emergency-toggle:focus-visible {
    background: #ffffff;
    color: #000000;
  }
  [data-theme="dark"] .portal-profile-stack.is-emergency-open .portal-emergency-toggle {
    background: transparent;
    color: #f0f0f0;
    border-top: 2px solid rgba(255, 255, 255, 0.72);
    border-left: 2px solid rgba(255, 255, 255, 0.72);
    border-right: 0;
    border-bottom: 0;
  }
  [data-theme="dark"] .portal-emergency-edit-btn:hover,
  [data-theme="dark"] .portal-emergency-edit-btn:focus-visible {
    color: #1c1c1c;
    background: #f0f0f0;
    border-color: #f0f0f0;
  }
  @media (prefers-reduced-motion: reduce) {
    .portal-emergency-toggle,
    .portal-emergency-drawer,
    .portal-emergency-card {
      transition-duration: 0.01ms !important;
    }
  }
  .portal-request-actions {
    width: 360px;
    max-width: 100%;
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0.55rem;
  }
  .portal-request-actions .portal-request-btn {
    --action-color: #ffffff;
    --action-bg: var(--fu-brand, #800000);
    --action-hover-bg: var(--fu-brand-hover, #6b0000);
    --action-active-bg: var(--fu-brand-active, #5a0000);
    width: 100%;
    gap: 0.55rem;
    min-height: 42px;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
  }
  .portal-request-icon {
    width: 1.15rem;
    height: 1.15rem;
    flex-shrink: 0;
    display: inline-block;
  }
  :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .portal-request-actions .portal-request-btn {
    --action-color: #f0f0f0;
    --action-bg: #1e1e1e;
    --action-hover-bg: #2a2a2a;
    --action-active-bg: #333333;
    --bs-btn-border-color: rgba(255, 255, 255, 0.14);
    --bs-btn-hover-border-color: rgba(255, 255, 255, 0.28);
    --bs-btn-active-border-color: rgba(255, 255, 255, 0.34);
  }
  #main .page-actions:has(.portal-app-dock) {
    overflow: visible;
  }
  .portal-app-dock {
    position: relative;
    z-index: 20;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-start;
    gap: 0.4rem;
  }
  .portal-app-tile {
    position: relative;
    width: 76px;
    height: 76px;
    aspect-ratio: 1 / 1;
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.3rem;
    padding: 0.35rem 0.15rem;
    border-radius: 8px;
    border: 1px solid var(--na-border, #e5e7ef);
    background: var(--surface-color, #ffffff);
    color: var(--default-color, #2d3a4e);
    text-decoration: none;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease, background-color 0.16s ease;
  }
  .portal-app-tile:hover,
  .portal-app-tile:focus-visible {
    z-index: 5;
    transform: translateY(-2px);
    border-color: var(--control-border, #767676);
    background: var(--surface-color, #ffffff);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    color: var(--heading-color, #111827);
    outline: none;
  }
  .portal-app-tile.is-active {
    border-color: var(--fu-maroon, #800000);
    background: var(--surface-color, #ffffff);
    box-shadow: inset 0 0 0 1px var(--fu-maroon, #800000), 0 4px 10px rgba(15, 23, 42, 0.08);
    color: var(--fu-maroon, #800000);
  }
  .portal-workspace-pane {
    transition: opacity 0.15s ease;
  }
  .portal-workspace-pane.is-loading {
    opacity: 0.6;
    pointer-events: none;
  }
  .portal-workspace-header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    min-height: 41px;
    margin-bottom: 1rem;
  }
  .portal-app-icon {
    width: auto;
    height: auto;
    border-radius: 0;
    background: transparent;
    color: currentColor;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    line-height: 1;
  }

  .portal-app-label {
    font-size: 0.6rem;
    font-weight: 600;
    line-height: 1.1;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-align: center;
  }
  .portal-app-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    min-width: 19px;
    height: 19px;
    padding: 0 4px;
    border-radius: 999px;
    background: var(--fu-maroon, #800000);
    color: #ffffff;
    border: 2px solid var(--card-bg, #ffffff);
    font-size: 0.62rem;
    font-weight: 700;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
    z-index: 2;
  }

  /* Hover / keyboard-focus tooltip opening downward so the fixed header never clips it */
  .portal-app-tile[data-app-tooltip]::before,
  .portal-app-tile[data-app-tooltip]::after {
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.14s ease, transform 0.14s ease;
    position: absolute;
    left: 50%;
    z-index: 1080;
  }
  .portal-app-tile[data-app-tooltip]::before {
    content: "";
    top: calc(100% + 2px);
    transform: translateX(-50%) translateY(-4px);
    border: 5px solid transparent;
    border-bottom-color: #181818;
  }
  .portal-app-tile[data-app-tooltip]::after {
    content: attr(data-app-tooltip);
    top: calc(100% + 12px);
    transform: translateX(-50%) translateY(-4px);
    width: max-content;
    max-width: 240px;
    padding: 0.42rem 0.62rem;
    border-radius: 6px;
    background: #181818;
    color: #f8fafc;
    font-size: 0.74rem;
    font-weight: 500;
    line-height: 1.35;
    text-align: center;
    white-space: normal;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.3);
  }
  .portal-app-tile[data-app-tooltip]:hover::before,
  .portal-app-tile[data-app-tooltip]:hover::after,
  .portal-app-tile[data-app-tooltip]:focus-visible::before,
  .portal-app-tile[data-app-tooltip]:focus-visible::after {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
  }
  .portal-app-tile:nth-last-child(-n+2)[data-app-tooltip]::after {
    left: auto;
    right: 0;
    transform: translateX(0) translateY(-4px);
    text-align: right;
  }
  .portal-app-tile:nth-last-child(-n+2)[data-app-tooltip]:hover::after,
  .portal-app-tile:nth-last-child(-n+2)[data-app-tooltip]:focus-visible::after {
    transform: translateX(0) translateY(0);
  }

  .portal-callslip-item {
    background: transparent;
  }

  [data-theme="dark"] .portal-app-tile {
    border-color: rgba(255, 255, 255, 0.12);
    background: var(--surface-color, #1c1c1c);
    color: var(--default-color, #f0f0f0);
    box-shadow: none;
  }
  [data-theme="dark"] .portal-app-tile:hover,
  [data-theme="dark"] .portal-app-tile:focus-visible {
    border-color: rgba(255, 255, 255, 0.3);
    background: #282828;
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.35);
    color: #ffffff;
  }
  [data-theme="dark"] .portal-app-tile.is-active {
    border-color: #c94a4a;
    background: #2a2224;
    box-shadow: inset 0 0 0 1px rgba(201, 74, 74, 0.65), 0 6px 14px rgba(0, 0, 0, 0.32);
    color: #ffffff;
  }
  [data-theme="dark"] .portal-app-badge {
    background: var(--fu-maroon, #800000);
    color: #ffffff;
    border-color: var(--card-bg, #1c1c1c);
  }
  [data-theme="dark"] .portal-app-tile[data-app-tooltip]::before {
    border-bottom-color: #2a2a2a;
  }
  [data-theme="dark"] .portal-app-tile[data-app-tooltip]::after {
    background: #2a2a2a;
    border: 1px solid rgba(255, 255, 255, 0.12);
  }

  /* Tablet / compact desktop: stack Tickets above Profile Card in col 1, Request actions alongside in col 2. */
  @media (min-width: 768px) and (max-width: 1199.98px) {
    .portal-left-rail {
      display: grid;
      grid-template-columns: 360px minmax(0, 1fr);
      column-gap: 1rem;
      row-gap: 0.75rem;
      align-items: start;
    }
    .portal-left-rail > [data-personal-queue] {
      grid-column: 1;
      grid-row: 1;
      width: 360px;
      max-width: 100%;
      margin-bottom: 0 !important;
    }
    .portal-profile-stack {
      grid-column: 1;
      grid-row: 2;
      margin-bottom: 0 !important;
    }
    .portal-request-actions {
      grid-column: 2;
      grid-row: 1 / span 2;
      max-width: none;
      width: 100%;
    }
    .portal-app-tile:first-child[data-app-tooltip]::after {
      left: 0;
      right: auto;
      transform: translateX(0) translateY(-4px);
      text-align: left;
    }
    .portal-app-tile:first-child[data-app-tooltip]:hover::after,
    .portal-app-tile:first-child[data-app-tooltip]:focus-visible::after {
      transform: translateX(0) translateY(0);
    }
  }

  /* Mobile layout (< 768px): full-width cards, compact 4-column Quick Services square grid in header. */
  @media (max-width: 767.98px) {
    .portal-main-row {
      --bs-gutter-y: 0.85rem;
    }
    [data-pinned-advisories],
    [data-personal-queue] {
      margin-bottom: 0.85rem !important;
    }
    [data-personal-queue] {
      width: 100%;
      max-width: 100%;
    }
    .portal-profile-stack {
      width: 100%;
      max-width: 100%;
      margin-bottom: 0.85rem !important;
    }
    .portal-profile-stack .portal-profile-card {
      width: 100%;
      min-width: 0;
      aspect-ratio: auto;
      min-height: 0;
    }
    .portal-request-actions {
      max-width: none;
      width: 100%;
    }
    .portal-app-dock {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      width: 100%;
      gap: 0.45rem;
    }
    .portal-app-tile {
      width: 100%;
      height: auto;
      aspect-ratio: 1 / 1;
    }
  }

  /* Prevent sticky CSS hover tooltips after tapping Quick Services tiles on touch screens. */
  @media (hover: none), (pointer: coarse) {
    .portal-app-tile[data-app-tooltip]::before,
    .portal-app-tile[data-app-tooltip]::after {
      display: none;
    }
  }

  @media (max-width: 575.98px) {
    .portal-profile-stack .portal-profile-card {
      border-radius: 12px;
    }
    .portal-profile-stack .portal-profile-card .card-content {
      padding: 0.9rem 1rem;
    }
    .portal-profile-stack .portal-profile-card .card-id {
      margin-bottom: 0.55rem;
    }
    .portal-profile-stack .portal-profile-card .card-details {
      font-size: 0.72rem;
      padding-right: 2.35rem;
    }
    .portal-emergency-toggle {
      width: 38px;
      height: 32px;
      border-radius: 9px 0 11px 0;
    }
    .portal-emergency-card {
      padding: calc(0.85rem + 12px) 1rem 0.9rem !important;
      border-radius: 0 0 12px 12px !important;
    }
    .portal-app-dock {
      gap: 0.4rem;
    }
    .portal-app-tile {
      border-radius: 6px;
      padding: 0.3rem 0.2rem;
      gap: 0.22rem;
    }
    .portal-app-icon {
      font-size: 1.1rem;
    }
    .portal-app-label {
      font-size: 0.6rem;
    }
    .portal-app-badge {
      top: -5px;
      right: -5px;
      min-width: 18px;
      height: 18px;
      padding: 0 4px;
      font-size: 0.6rem;
    }
    .portal-app-tile[data-app-tooltip]::after {
      max-width: 200px;
    }
    .portal-filter-reset-btn {
      width: 100%;
    }
    .portal-workspace-header {
      flex-direction: column;
      align-items: stretch;
      gap: 0.5rem;
      margin-bottom: 0.75rem;
    }
    .portal-workspace-actions {
      margin-left: 0 !important;
      width: 100%;
    }
    .portal-workspace-actions .btn {
      flex: 1 1 auto;
      text-align: center;
    }
  }
</style>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/queue-live.js') ?>"></script>
<?= $this->endSection() ?>

<div class="row g-4">
  <div class="col-lg-5">
    <h4 class="h6 fw-bold mb-3">Client profile</h4>
    <dl class="row small mb-0">
      <dt class="col-5 text-muted">Date of birth</dt><dd class="col-7"><?= esc($formatDate($person['date_of_birth'] ?? null, false)) ?><?= $age !== null ? ' · Age ' . (int) $age : '' ?></dd>
      <dt class="col-5 text-muted">Gender on file</dt><dd class="col-7"><?= esc($displayStatus($person['gender'] ?? 'Not provided')) ?></dd>
      <dt class="col-5 text-muted">Department</dt><dd class="col-7"><?= esc((string) ($studentProfile['department'] ?? $employeeProfile['department'] ?? 'Not on file')) ?></dd>
      <?php if (($person['kind'] ?? '') === 'student'): ?>
        <dt class="col-5 text-muted">Program / year</dt><dd class="col-7"><?= esc((string) ($studentProfile['program'] ?? 'Not on file')) ?><?= !empty($studentProfile['year_level']) ? ' · Year ' . (int) $studentProfile['year_level'] : '' ?></dd>
        <dt class="col-5 text-muted">Section</dt><dd class="col-7"><?= esc((string) ($studentProfile['section'] ?? 'Not on file')) ?></dd>
      <?php else: ?>
        <dt class="col-5 text-muted">Position</dt><dd class="col-7"><?= esc((string) ($employeeProfile['position'] ?? 'Not on file')) ?></dd>
      <?php endif; ?>
    </dl>
    <p class="small text-muted mt-3">Identity and contact details come from the shared campus profile. This record does not create a separate person or medical chart.</p>
  </div>
  <div class="col-lg-7">
    <h4 class="h6 fw-bold mb-3">Permitted Guidance engagement</h4>
    <div class="row g-3">
      <?php foreach ([
          ['Queue visits completed', $metrics['queue_visits_completed'], $can['queue'], 'overview'],
          ['MI assessment attempts', $metrics['mi_attempts_total'], $can['assessments'], 'assessments'],
          ['Pending MI assessments', $metrics['mi_pending_total'], $can['assessments'], 'assessments'],
          ['Survey responses', $metrics['surveys_completed'], $can['surveys'], 'assessments'],
          ['Intake referrals', $metrics['intake_referrals_total'], $can['intake'], 'coordination'],
          ['Forms & documents', $metrics['forms_total'], $can['forms'], 'forms'],
          ['Scheduled appointments', $metrics['shared_appointments_total'], $can['schedule'], 'appointments'],
          ['Controlled appointment slips', $metrics['guidance_appointments'], $can['schedule'], 'appointments'],
          ['Call slips issued', $metrics['call_slips_issued'], $can['callSlips'], 'appointments'],
      ] as [$label, $value, $allowed, $tab]): ?>
        <?php if ($allowed): ?>
          <div class="col-6 col-xl-4"><a href="<?= esc($sectionUrl($tab)) ?>" data-record-tab-link="<?= esc($tab) ?>" class="border rounded p-3 d-block h-100 text-body text-decoration-none">
            <div class="small text-muted"><?= esc($label) ?></div><div class="h5 fw-bold mb-0 mt-1"><?= number_format((int) $value) ?></div>
          </a></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<div class="alert alert-light border small mt-4 mb-0">
  <i class="bi bi-lock me-1" aria-hidden="true"></i> Counts describe records you may view. Restricted counseling records, their contents, and their counts are not disclosed here. Use the record sections to open existing authorized workspaces.
</div>

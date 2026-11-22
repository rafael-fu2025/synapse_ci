<div class="cp-overview-grid">
  <div class="cp-side-card">
    <div class="cp-side-head">
      <i class="bi bi-person-lines-fill" aria-hidden="true"></i>
      <h4 class="cp-side-title">Client profile</h4>
    </div>
    <div class="cp-side-body">
      <div class="cp-kv-list">
        <div class="cp-kv-row">
          <span class="cp-kv-key">Date of birth</span>
          <strong class="cp-kv-val cp-mono"><?= esc($formatDate($person['date_of_birth'] ?? null, false)) ?><?= $age !== null ? ' · Age ' . (int) $age : '' ?></strong>
        </div>
        <div class="cp-kv-row">
          <span class="cp-kv-key">Gender on file</span>
          <strong class="cp-kv-val"><?= esc($displayStatus($person['gender'] ?? 'Not provided')) ?></strong>
        </div>
        <div class="cp-kv-row">
          <span class="cp-kv-key">Department</span>
          <strong class="cp-kv-val"><?= esc((string) ($studentProfile['department'] ?? $employeeProfile['department'] ?? 'Not on file')) ?></strong>
        </div>
        <?php if (($person['kind'] ?? '') === 'student'): ?>
          <div class="cp-kv-row">
            <span class="cp-kv-key">Program / year</span>
            <strong class="cp-kv-val"><?= esc((string) ($studentProfile['program'] ?? 'Not on file')) ?><?= !empty($studentProfile['year_level']) ? ' · Year ' . (int) $studentProfile['year_level'] : '' ?></strong>
          </div>
          <div class="cp-kv-row">
            <span class="cp-kv-key">Section</span>
            <strong class="cp-kv-val"><?= esc((string) ($studentProfile['section'] ?? 'Not on file')) ?></strong>
          </div>
        <?php else: ?>
          <div class="cp-kv-row">
            <span class="cp-kv-key">Position</span>
            <strong class="cp-kv-val"><?= esc((string) ($employeeProfile['position'] ?? 'Not on file')) ?></strong>
          </div>
        <?php endif; ?>
      </div>
      <p class="cp-record-section-note">Identity and contact details come from the shared campus profile. This record does not create a separate person or medical chart.</p>
    </div>
  </div>

  <div class="cp-side-card">
    <div class="cp-side-head">
      <i class="bi bi-activity" aria-hidden="true"></i>
      <h4 class="cp-side-title">Permitted Guidance engagement</h4>
    </div>
    <div class="cp-side-body">
      <div class="cp-engagement-grid">
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
            <a href="<?= esc($sectionUrl($tab)) ?>" data-record-tab-link="<?= esc($tab) ?>" class="cp-engagement-card">
              <span class="cp-engagement-label"><?= esc($label) ?></span>
              <strong class="cp-engagement-val"><?= number_format((int) $value) ?></strong>
            </a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="cp-notice mt-3" role="note">
  <i class="bi bi-lock me-1" aria-hidden="true"></i> Counts describe records you may view. Restricted counseling records, their contents, and their counts are not disclosed here. Use the record sections to open existing authorized workspaces.
</div>

<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <a href="<?= site_url('/guidance/cases') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-folder2-open me-1"></i> Cases List
  </a>
  <a href="<?= site_url('/guidance/intake') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-inbox me-1"></i> Intake Queue
  </a>
  <a href="<?= site_url('/guidance/assessments/mi') ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-bar-chart-line me-1"></i> MI Assessments
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$fullName = trim((string) ($person['last_name'] ?? '') . ', ' . (string) ($person['first_name'] ?? '') . (!empty($person['middle_name']) ? ' ' . $person['middle_name'] : ''));
$age = null;
if (!empty($person['date_of_birth'])) {
    try {
        $age = (new DateTimeImmutable((string) $person['date_of_birth']))->diff(new DateTimeImmutable('today'))->y;
    } catch (Throwable) {
        $age = null;
    }
}
$domainKeys = [
    'linguistic'     => 'Linguistic',
    'logical'        => 'Logical',
    'spatial'        => 'Spatial',
    'bodily'         => 'Bodily',
    'musical'        => 'Musical',
    'interpersonal'  => 'Interpers.',
    'intrapersonal'  => 'Intrapers.',
    'naturalistic'   => 'Naturalist',
];
?>

<div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div class="small">
    <i class="bi bi-shield-lock text-primary me-1"></i>
    <strong>Consolidated Guidance Cumulative Record</strong> — Read-only longitudinal profile. Clinical counseling notes remain gated to case owners and authorized consultation/oversight staff. This access is logged in the audit ledger.
  </div>
  <span class="badge bg-secondary-subtle text-secondary border"><?= esc($person['kind'] === 'employee' ? 'Employee' : 'Student') ?></span>
</div>

<!-- 1. Demographics / PDS Summary -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
      <div>
        <h2 class="h4 fw-bold mb-1"><?= esc($fullName !== '' ? $fullName : 'Student Profile') ?></h2>
        <div class="text-muted small">
          ID: <strong><?= esc((string) ($person['mis_id'] ?? 'N/A')) ?></strong>
          <?php if (!empty($studentProfile)): ?>
            · <?= esc((string) ($studentProfile['program'] ?? 'Program N/A')) ?>
            <?php if (!empty($studentProfile['year_level'])): ?> — Year <?= esc((string) $studentProfile['year_level']) ?><?php endif; ?>
            <?php if (!empty($studentProfile['section'])): ?> (Sec <?= esc((string) $studentProfile['section']) ?>)<?php endif; ?>
            · <?= esc((string) ($studentProfile['department'] ?? 'University')) ?>
          <?php elseif (!empty($employeeProfile)): ?>
            · <?= esc((string) ($employeeProfile['department'] ?? 'University')) ?>
            <?= !empty($employeeProfile['position']) ? ' (' . esc((string) $employeeProfile['position']) . ')' : '' ?>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($age !== null): ?>
        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-3 py-2">
          Age <?= (int) $age ?> · <?= esc(ucwords(str_replace('_', ' ', (string) ($person['gender'] ?? 'Unspecified')))) ?>
        </span>
      <?php endif; ?>
    </div>

    <div class="row g-3 small border-top pt-3">
      <div class="col-md-3">
        <div class="text-muted">University Email (MIS)</div>
        <div class="fw-semibold text-break"><?= esc((string) ($person['email'] ?? 'Not set')) ?></div>
      </div>
      <div class="col-md-3">
        <div class="text-muted">Current Contact Phone</div>
        <div class="fw-semibold"><?= esc(!empty($person['phone']) ? (string) $person['phone'] : 'Not provided') ?></div>
      </div>
      <div class="col-md-6">
        <div class="text-muted">Current / Boarding Address (Student-Updated)</div>
        <div class="fw-semibold text-break"><?= esc(!empty($person['address']) ? (string) $person['address'] : 'Not provided') ?></div>
      </div>
    </div>
  </div>
</div>

<!-- 2. Service Visit & Engagement Frequency -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card border-0 shadow-sm h-100"><div class="card-body p-3">
      <div class="small text-muted">Queue Visits Completed</div>
      <div class="h4 fw-bold mb-0"><?= (int) ($metrics['queue_visits_completed'] ?? 0) ?></div>
      <div class="small text-muted"><?= (int) ($metrics['queue_visits_total'] ?? 0) ?> total queue tickets</div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card border-0 shadow-sm h-100"><div class="card-body p-3">
      <div class="small text-muted">PSP / Counseling Sessions</div>
      <div class="h4 fw-bold mb-0"><?= (int) ($metrics['sessions_total'] ?? 0) ?></div>
      <div class="small text-muted"><?= (int) ($metrics['cases_total'] ?? 0) ?> case(s) total</div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card border-0 shadow-sm h-100"><div class="card-body p-3">
      <div class="small text-muted">Guidance Appointments</div>
      <div class="h4 fw-bold mb-0"><?= (int) ($metrics['guidance_appointments'] ?? 0) ?></div>
      <div class="small text-muted"><?= (int) ($metrics['shared_appointments_total'] ?? 0) ?> scheduled slot(s)</div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card border-0 shadow-sm h-100"><div class="card-body p-3">
      <div class="small text-muted">Call Slips Issued</div>
      <div class="h4 fw-bold mb-0"><?= (int) ($metrics['call_slips_issued'] ?? 0) ?></div>
      <div class="small text-muted">F-OGC-CAL-007-00</div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card border-0 shadow-sm h-100"><div class="card-body p-3">
      <div class="small text-muted">MI Assessments</div>
      <div class="h4 fw-bold mb-0"><?= (int) ($metrics['mi_attempts_total'] ?? 0) ?></div>
      <div class="small text-muted">Preserved across terms</div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card border-0 shadow-sm h-100"><div class="card-body p-3">
      <div class="small text-muted">Intake / Surveys</div>
      <div class="h4 fw-bold mb-0"><?= (int) ($metrics['surveys_completed'] ?? 0) ?></div>
      <div class="small text-muted"><?= (int) ($metrics['intake_referrals_total'] ?? 0) ?> referral(s)</div>
    </div></div>
  </div>
</div>

<!-- 3. Longitudinal Multiple Intelligence History (All 8 Domains 0-40 preserved) -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center">
    <h3 class="h6 fw-bold mb-0"><i class="bi bi-stars text-primary me-2"></i>Longitudinal Multiple Intelligence Profile (All 8 Domains, 0–40)</h3>
    <span class="badge bg-light text-dark border"><?= count($miAttempts) ?> attempt<?= count($miAttempts) === 1 ? '' : 's' ?></span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($miAttempts)): ?>
      <div class="p-4 text-center text-muted small">No Multiple Intelligence assessment attempts recorded yet.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Term / School Year</th>
              <th>Status</th>
              <th>Completed / Updated</th>
              <?php foreach ($domainKeys as $label): ?>
                <th class="text-center small"><?= esc($label) ?></th>
              <?php endforeach; ?>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($miAttempts as $attempt): ?>
              <?php $scores = is_array($attempt['domain_scores'] ?? null) ? $attempt['domain_scores'] : []; ?>
              <tr>
                <td class="small fw-semibold">
                  <?= esc(trim((string) ($attempt['academic_term'] ?? 'Assessment') . (!empty($attempt['academic_year']) ? ' · ' . $attempt['academic_year'] : ''))) ?>
                </td>
                <td><span class="badge bg-<?= ($attempt['status'] ?? '') === 'finalized' ? 'success' : 'warning text-dark' ?> text-capitalize"><?= esc((string) ($attempt['status'] ?? 'draft')) ?></span></td>
                <td class="small text-muted"><?= esc(!empty($attempt['attested_at']) ? date('M j, Y', strtotime((string) $attempt['attested_at'])) : date('M j, Y', strtotime((string) $attempt['created_at']))) ?></td>
                <?php foreach (array_keys($domainKeys) as $k): ?>
                  <td class="text-center small fw-semibold"><?= isset($scores[$k]) ? (int) $scores[$k] : '—' ?></td>
                <?php endforeach; ?>
                <td class="text-end">
                  <?php if (($attempt['status'] ?? '') === 'finalized'): ?>
                    <a href="<?= site_url('/guidance/assessments/mi/results/' . (int) $attempt['assignment_id']) ?>" class="btn btn-outline-primary btn-sm">Results</a>
                  <?php else: ?>
                    <span class="text-muted small">In Progress</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- 4. Survey / Routine Interview & Personnel Remarks Status -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center">
        <h3 class="h6 fw-bold mb-0"><i class="bi bi-clipboard-check text-primary me-2"></i>Surveys &amp; Routine Intake Interviews</h3>
        <span class="badge bg-light text-dark border"><?= count($surveys) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($surveys)): ?>
          <div class="p-4 text-center text-muted small">No identified survey or routine intake responses submitted yet.</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($surveys as $sv): ?>
              <div class="list-group-item py-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div>
                    <div class="fw-semibold small"><?= esc((string) $sv['title']) ?></div>
                    <div class="text-muted small">Submitted <?= date('M j, Y g:i A', strtotime((string) $sv['submitted_at'])) ?> UTC</div>
                    <?php if (!empty($sv['interviewed_by_user_id'])): ?>
                      <div class="small text-success mt-1">
                        <i class="bi bi-person-check-fill me-1"></i>
                        Interviewed by <strong><?= esc((string) $sv['interviewer_name']) ?></strong>
                        <?php if (!empty($sv['interviewer_tier'])): ?> (<?= esc((string) $sv['interviewer_tier']) ?>)<?php endif; ?>
                        on <?= date('M j, Y', strtotime((string) $sv['interviewed_at'])) ?>
                      </div>
                    <?php else: ?>
                      <div class="small text-warning-emphasis mt-1"><i class="bi bi-hourglass-split me-1"></i>Pending staff interview verification</div>
                    <?php endif; ?>
                  </div>
                  <?php if ($canViewResponses): ?>
                    <a href="<?= site_url('/guidance/surveys/' . (int) $sv['survey_id'] . '/responses/' . (int) $sv['response_id']) ?>" class="btn btn-outline-primary btn-sm">Open &amp; Interview</a>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 5. Authorized Counseling / PSP Cases -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center">
        <h3 class="h6 fw-bold mb-0"><i class="bi bi-journal-medical text-primary me-2"></i>Authorized Guidance Cases</h3>
        <span class="badge bg-light text-dark border"><?= count($authorizedCases) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($authorizedCases) && $restrictedCasesCount === 0): ?>
          <div class="p-4 text-center text-muted small">No Guidance cases recorded for this student.</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($authorizedCases as $c): ?>
              <div class="list-group-item py-3 d-flex justify-content-between align-items-center gap-2">
                <div>
                  <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge bg-dark-subtle text-dark"><?= esc((string) $c['case_number']) ?></span>
                    <strong class="small"><?= esc((string) $c['title']) ?></strong>
                    <?php if (!empty($c['is_consultation'])): ?>
                      <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Consultation (Read-Only)</span>
                    <?php endif; ?>
                  </div>
                  <div class="small text-muted mt-1">
                    <?= esc((string) $c['service_mode']) ?> · Handled by <?= esc((string) $c['handler_name']) ?> (<?= esc((string) $c['handler_tier']) ?>)
                  </div>
                </div>
                <a href="<?= site_url('/guidance/cases/' . (int) $c['id']) ?>" class="btn btn-outline-primary btn-sm">Workspace</a>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($restrictedCasesCount > 0): ?>
            <div class="p-3 bg-light border-top small text-muted">
              <i class="bi bi-lock me-1"></i>
              <?= (int) $restrictedCasesCount ?> additional case record(s) exist under another practitioner's confidentiality boundary.
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- 6. Intake Referrals, Guidance Appointments & Call Slips -->
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent py-3"><h3 class="h6 fw-bold mb-0">General Referrals (GGR-005)</h3></div>
      <div class="card-body p-0">
        <?php if (empty($intakes)): ?>
          <div class="p-4 text-center text-muted small">No Guidance intake referrals.</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($intakes as $in): ?>
              <div class="list-group-item py-2 px-3 d-flex justify-content-between align-items-center">
                <div class="small">
                  <div class="fw-semibold"><?= esc((string) $in['referral_number']) ?></div>
                  <div class="text-muted"><?= esc(ucwords(str_replace('_', ' ', (string) $in['source_type']))) ?> · <?= esc(ucwords(str_replace('_', ' ', (string) $in['status']))) ?></div>
                </div>
                <?php if ($canProcessIntake): ?>
                  <a href="<?= site_url('/guidance/intake/' . (int) $in['id']) ?>" class="btn btn-outline-secondary btn-sm">View</a>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent py-3"><h3 class="h6 fw-bold mb-0">Guidance Appointments (GAP-006)</h3></div>
      <div class="card-body p-0">
        <?php if (empty($guidanceAppointments)): ?>
          <div class="p-4 text-center text-muted small">No controlled Guidance appointment slips yet.</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($guidanceAppointments as $ga): ?>
              <div class="list-group-item py-2 px-3 d-flex justify-content-between align-items-center">
                <div class="small">
                  <div class="fw-semibold"><?= esc((string) $ga['appointment_number']) ?></div>
                  <div class="text-muted"><?= esc((string) $ga['scheduled_date']) ?> <?= esc(substr((string) $ga['scheduled_time'], 0, 5)) ?> · <?= esc(ucwords(str_replace('_', ' ', (string) $ga['purpose']))) ?></div>
                </div>
                <a href="<?= site_url('/guidance/appointments/' . (int) $ga['id'] . '/pdf?layout=single&mode=preview') ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">Slip PDF</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent py-3"><h3 class="h6 fw-bold mb-0">Call Slips (CAL-007)</h3></div>
      <div class="card-body p-0">
        <?php if (empty($callSlips)): ?>
          <div class="p-4 text-center text-muted small">No Guidance call slips issued yet.</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($callSlips as $cs): ?>
              <div class="list-group-item py-2 px-3 d-flex justify-content-between align-items-center">
                <div class="small">
                  <div class="fw-semibold"><?= esc((string) $cs['call_slip_number']) ?></div>
                  <div class="text-muted"><?= esc((string) $cs['call_date']) ?> · <?= esc(ucfirst((string) $cs['attendance_status'])) ?></div>
                </div>
                <a href="<?= site_url('/guidance/call-slips/' . (int) $cs['id'] . '/pdf?layout=single&mode=preview') ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">Slip PDF</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>


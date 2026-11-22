<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 clinic-page-actions cp-page-actions">
  <?php $referralActor = session()->get('user') ?? []; ?>
  <?php if (($referralActor['role'] ?? '') === 'superadmin' || in_array('referrals.create', $referralActor['permissions'] ?? [], true)): ?>
    <a href="<?= esc(site_url('/clinic/referrals/create?' . http_build_query(['person_id' => $chart['person']['id']]))) ?>" class="btn btn-outline-secondary" data-app-modal-open>
      <i class="bi bi-send" aria-hidden="true"></i>Refer to Guidance
    </a>
  <?php endif; ?>
  <a href="<?= site_url('/clinic/patients') ?>" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>Patient Directory
  </a>
  <?php $openVisit = null;
  foreach ($chart['encounters'] as $visit) {
      if ($visit['status'] === 'in_progress') { $openVisit = $visit; break; }
  } ?>
  <?php if ($openVisit !== null): ?>
    <a href="<?= site_url('/clinic/encounters/' . (int) $openVisit['id']) ?>" class="btn btn-success">Resume Open Visit</a>
  <?php elseif (!empty($canStartVisit)): ?>
    <form action="<?= site_url('/clinic/encounters/start-direct/' . $chart['person']['id']) ?>" method="POST" class="d-inline">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-plus-circle" aria-hidden="true"></i>Start New Visit
      </button>
    </form>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$person = $chart['person'];
$health = $chart['health_profile'] ?? [];
$student = $chart['student_profile'] ?? null;
$employee = $chart['employee_profile'] ?? null;
$encounters = $chart['encounters'] ?? [];
$vitalsHistory = $chart['vitals_history'] ?? [];
$visitSummary = $chart['visit_summary'] ?? ['total_visits' => count($encounters), 'current_sy_visits' => 0, 'current_school_year' => ($currentSchoolYear ?? ''), 'last_visit_at' => null, 'top_complaints' => []];
$labResults = $chart['lab_results'] ?? [];
$enrollmentClearances = $chart['enrollment_clearances'] ?? [];
$isolationCases = $chart['isolation_cases'] ?? [];
$manila = new \DateTimeZone('Asia/Manila');
$utc = new \DateTimeZone('UTC');
$formatManila = static function (?string $ts) use ($manila, $utc): string {
    if ($ts === null || trim($ts) === '') {
        return '—';
    }
    try {
        return (new \DateTimeImmutable($ts, $utc))->setTimezone($manila)->format('M j, Y · g:i A');
    } catch (\Throwable) {
        return $ts;
    }
};
$treatmentsByEncounter = [];
foreach ($chart['treatment_history'] ?? [] as $item) {
    $treatmentsByEncounter[$item['encounter_id']][] = $item;
}
$dispensationsByEncounter = [];
foreach ($chart['dispensation_history'] ?? [] as $item) {
    $dispensationsByEncounter[$item['encounter_id']][] = $item;
}
$chartEmail = !empty($person['email'])
    ? $person['email']
    : \App\Services\UniversityEmail::derive($person['first_name'] ?? null, $person['last_name'] ?? null);
$totalVisits = (int) ($visitSummary['total_visits'] ?? count($encounters));
$syVisits = (int) ($visitSummary['current_sy_visits'] ?? 0);
$bloodType = trim((string) ($health['blood_type'] ?? ''));
$allergiesText = trim((string) ($health['allergies'] ?? ''));
?>
<section class="clinic-chart-section cp-chart" data-patient-chart="<?= (int) $person['id'] ?>"
         data-refresh-owner="clinic.patient.<?= (int) $person['id'] ?>"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">

  <div data-refresh-region="profile" data-refresh-heading tabindex="-1">
    <div class="cp-hero">
      <div class="cp-hero-top">
        <div class="cp-hero-identity">
          <div class="cp-avatar" aria-hidden="true">
            <?= esc(strtoupper(substr((string) $person['first_name'], 0, 1) . substr((string) $person['last_name'], 0, 1))) ?>
          </div>
          <div class="cp-hero-copy">
            <div class="cp-hero-eyebrow">
              <?= esc(ucfirst((string) $person['kind'])) ?> · <?= esc($student ? ($student['program'] . ' (Year ' . $student['year_level'] . ')') : ($employee['department'] ?? 'University Member')) ?>
            </div>
            <h2 class="cp-hero-name"><?= esc(trim($person['last_name'] . ', ' . $person['first_name'] . ' ' . ($person['middle_name'] ?? ''))) ?></h2>
            <div class="cp-hero-meta">
              <span>MIS ID: <strong class="cp-mono"><?= esc($person['mis_id']) ?></strong></span>
              <?php if (!empty($chartEmail)): ?>
                <span class="text-break">· Email: <strong><?= esc($chartEmail) ?></strong></span>
              <?php endif; ?>
              <span>· Gender: <?= esc(ucfirst((string) ($person['gender'] ?? 'Unspecified'))) ?></span>
              <?php if (!empty($person['date_of_birth'])): ?>
                <span>· DOB: <span class="cp-mono"><?= esc(date('M j, Y', strtotime((string) $person['date_of_birth']))) ?></span></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm d-print-none" data-print-chart>
          <i class="bi bi-printer" aria-hidden="true"></i>Print Chart
        </button>
      </div>

      <div class="cp-snapshot-grid">
        <div class="cp-snapshot-cell">
          <span class="cp-snapshot-label">Clinic Visits</span>
          <span class="cp-snapshot-value cp-mono"><?= number_format($totalVisits) ?> total</span>
          <span class="cp-snapshot-sub"><?= number_format($syVisits) ?> in SY <?= esc((string) ($visitSummary['current_school_year'] ?? '')) ?></span>
        </div>
        <div class="cp-snapshot-cell">
          <span class="cp-snapshot-label">Blood Type</span>
          <?php if ($bloodType !== '' && strcasecmp($bloodType, 'Unknown') !== 0): ?>
            <span class="cp-snapshot-value cp-mono" style="color: var(--cp-crimson);"><?= esc($bloodType) ?></span>
          <?php else: ?>
            <span class="cp-snapshot-value text-muted">Unknown</span>
          <?php endif; ?>
          <span class="cp-snapshot-sub">Baseline profile</span>
        </div>
        <div class="cp-snapshot-cell <?= $allergiesText !== '' ? 'cp-snapshot-cell--alert' : '' ?>">
          <span class="cp-snapshot-label">Known Allergies</span>
          <?php if ($allergiesText !== ''): ?>
            <span class="cp-snapshot-value">
              <span class="cp-status-inline cp-status-inline--danger" style="white-space: normal;"><span class="cp-dot" aria-hidden="true"></span><?= esc($allergiesText) ?></span>
            </span>
          <?php else: ?>
            <span class="cp-snapshot-value text-muted">None recorded</span>
          <?php endif; ?>
        </div>
        <div class="cp-snapshot-cell">
          <span class="cp-snapshot-label">Last Visit</span>
          <span class="cp-snapshot-value cp-mono"><?= esc($formatManila($visitSummary['last_visit_at'] ?? null)) ?></span>
          <span class="cp-snapshot-sub">Asia/Manila time</span>
        </div>
      </div>
    </div>
  </div>

  <div data-refresh-region="history">
    <div class="cp-chart-grid">
      <!-- Left Column: Clinical Summary, Visit Frequency, Isolation, Emergency Contact -->
      <aside class="cp-sidebar-stack">
        <div class="cp-side-card">
          <div class="cp-side-head">
            <i class="bi bi-shield-plus" aria-hidden="true"></i>
            <h3 class="cp-side-title">Clinical Profile</h3>
          </div>
          <div class="cp-side-body">
            <div class="cp-field-group">
              <span class="cp-field-label">Blood Type</span>
              <div>
                <?php if ($bloodType !== '' && strcasecmp($bloodType, 'Unknown') !== 0): ?>
                  <span class="cp-tag cp-tag--blood"><?= esc($bloodType) ?></span>
                <?php else: ?>
                  <span class="text-muted">Unknown</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="cp-field-group">
              <span class="cp-field-label">Known Allergies</span>
              <?php if ($allergiesText !== ''): ?>
                <div class="cp-notice cp-notice--danger">
                  <strong style="color: var(--cp-crimson);"><?= esc($allergiesText) ?></strong>
                </div>
              <?php else: ?>
                <span class="text-muted">No allergies recorded.</span>
              <?php endif; ?>
            </div>
            <div class="cp-field-group">
              <span class="cp-field-label">Chronic Conditions</span>
              <span class="cp-field-text fw-semibold"><?= esc($health['chronic_conditions'] ?? 'None on file') ?></span>
            </div>
            <?php if (!empty($health['medical_history_notes'])): ?>
              <div class="cp-field-group">
                <span class="cp-field-label">Medical History</span>
                <span class="cp-field-text"><?= nl2br(esc($health['medical_history_notes'])) ?></span>
              </div>
            <?php endif; ?>
            <?php if (!empty($health['immunization_notes'])): ?>
              <div class="cp-field-group">
                <span class="cp-field-label">Immunizations</span>
                <span class="cp-field-text"><?= nl2br(esc($health['immunization_notes'])) ?></span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="cp-side-card">
          <div class="cp-side-head">
            <i class="bi bi-activity" aria-hidden="true"></i>
            <h3 class="cp-side-title">Visit Frequency Summary</h3>
          </div>
          <div class="cp-side-body">
            <div class="cp-kv-list">
              <div class="cp-kv-row">
                <span class="cp-kv-key">All-Time Visits</span>
                <strong class="cp-kv-val cp-mono"><?= (int) ($visitSummary['total_visits'] ?? 0) ?></strong>
              </div>
              <div class="cp-kv-row">
                <span class="cp-kv-key">SY <?= esc((string) ($visitSummary['current_school_year'] ?? '')) ?></span>
                <strong class="cp-kv-val cp-mono"><?= $syVisits ?> <?= $syVisits === 1 ? 'visit' : 'visits' ?></strong>
              </div>
              <div class="cp-kv-row">
                <span class="cp-kv-key">Last Visit</span>
                <strong class="cp-kv-val cp-mono"><?= esc($formatManila($visitSummary['last_visit_at'] ?? null)) ?></strong>
              </div>
            </div>
            <?php if (!empty($visitSummary['top_complaints'])): ?>
              <div class="cp-field-group">
                <span class="cp-field-label">Frequent Complaints</span>
                <div class="cp-complaints-wrap">
                  <?php foreach ($visitSummary['top_complaints'] as $tc): ?>
                    <span class="cp-tag"><?= esc($tc['label']) ?> <span class="cp-mono text-muted"><?= (int) $tc['count'] ?></span></span>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!empty($isolationCases)): ?>
          <div class="cp-side-card cp-side-card--warn">
            <div class="cp-side-head">
              <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
              <h3 class="cp-side-title">Isolation / Communicable History</h3>
            </div>
            <div class="cp-side-body">
              <ul class="cp-iso-list">
                <?php foreach ($isolationCases as $iso): ?>
                  <?php
                    $condLabel = \App\Services\Clinic\IsolationTrackingService::CONDITIONS[$iso['condition_code']]
                        ?? ucwords(str_replace('_', ' ', (string) $iso['condition_code']));
                    $isoStatus = (string) ($iso['clearance_status'] ?? '');
                    $isoTone = match ($isoStatus) {
                        'cleared' => 'cp-status-inline--active',
                        'hospitalized' => 'cp-status-inline--warn',
                        default => 'cp-status-inline--danger',
                    };
                  ?>
                  <li class="cp-iso-item">
                    <div class="cp-iso-item-top">
                      <strong><?= esc($condLabel) ?></strong>
                      <span class="cp-status-inline <?= esc($isoTone) ?>">
                        <span class="cp-dot" aria-hidden="true"></span><?= esc(ucwords(str_replace('_', ' ', $isoStatus))) ?>
                      </span>
                    </div>
                    <div class="text-muted cp-mono small"><?= esc($iso['isolation_start_date']) ?> to <?= esc($iso['isolation_end_date']) ?></div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        <?php endif; ?>

        <div class="cp-side-card">
          <div class="cp-side-head">
            <i class="bi bi-telephone-plus" aria-hidden="true"></i>
            <h3 class="cp-side-title">Emergency Contact</h3>
          </div>
          <div class="cp-side-body">
            <div class="cp-kv-list">
              <div class="cp-kv-row">
                <span class="cp-kv-key">Name</span>
                <strong class="cp-kv-val"><?= esc($health['emergency_contact_name'] ?? '—') ?></strong>
              </div>
              <div class="cp-kv-row">
                <span class="cp-kv-key">Relationship</span>
                <strong class="cp-kv-val"><?= esc($health['emergency_contact_relationship'] ?? '—') ?></strong>
              </div>
              <div class="cp-kv-row">
                <span class="cp-kv-key">Phone</span>
                <strong class="cp-kv-val cp-mono"><?= esc($health['emergency_contact_phone'] ?? '—') ?></strong>
              </div>
            </div>
          </div>
        </div>
      </aside>

      <!-- Right Column: Tabbed Clinical History & Editors -->
      <div class="cp-main-panel">
        <div class="cp-tabs-bar">
          <ul class="nav nav-tabs clinic-chart-tabs cp-chart-tabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-visits" type="button" role="tab" aria-selected="true">
                <i class="bi bi-clock-history" aria-hidden="true"></i>Visit History <span class="cp-tab-count"><?= count($encounters) ?></span>
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-vitals" type="button" role="tab" aria-selected="false">
                <i class="bi bi-graph-up" aria-hidden="true"></i>Vitals <span class="cp-tab-count"><?= count($vitalsHistory) ?></span>
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-labs" type="button" role="tab" aria-selected="false">
                <i class="bi bi-flask" aria-hidden="true"></i>Labs <span class="cp-tab-count"><?= count($labResults) ?></span>
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-clearances" type="button" role="tab" aria-selected="false">
                <i class="bi bi-patch-check" aria-hidden="true"></i>Clearances <span class="cp-tab-count"><?= count($enrollmentClearances) ?></span>
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-edit-health" type="button" role="tab" aria-selected="false">
                <i class="bi bi-pencil-square" aria-hidden="true"></i>Health Profile
              </button>
            </li>
          </ul>
        </div>

        <div class="cp-tab-body tab-content">
          <!-- Tab 1: Visit History -->
          <div class="tab-pane fade show active" id="tab-visits" role="tabpanel">
            <?php if (empty($encounters)): ?>
              <div class="cp-empty-pane">
                <i class="bi bi-inbox" aria-hidden="true"></i>
                No clinic encounters recorded for this patient yet.
              </div>
            <?php else: ?>
              <div class="cp-visit-list">
                <?php foreach ($encounters as $e): ?>
                  <?php
                    $disp = (string) ($e['disposition'] ?? '');
                    $dispTone = match ($disp) {
                        'discharged' => 'cp-status-inline--active',
                        'sent_home_isolation', 'referred_hospital', 'emergency_transfer' => 'cp-status-inline--warn',
                        default => 'cp-status-inline--muted',
                    };
                  ?>
                  <article class="cp-visit-card">
                    <div class="cp-visit-head">
                      <div class="cp-visit-title-col">
                        <h4 class="cp-visit-title"><?= esc($e['chief_complaint'] ?? 'General Consult') ?></h4>
                        <span class="cp-visit-meta">
                          <span class="cp-mono"><?= esc($formatManila($e['visited_at'] ?? $e['started_at'])) ?></span> · Attending: <?= esc($e['attending_name'] ?? 'Unavailable') ?>
                          <?php if (!empty($e['encoder_name']) && $e['encoder_name'] !== ($e['attending_name'] ?? null)): ?>
                            · Encoded by: <?= esc($e['encoder_name']) ?>
                          <?php endif; ?>
                        </span>
                      </div>
                      <div class="cp-visit-actions">
                        <?php if ($disp !== ''): ?>
                          <span class="cp-status-inline <?= esc($dispTone) ?>">
                            <span class="cp-dot" aria-hidden="true"></span><?= esc(ucwords(str_replace('_', ' ', $disp))) ?>
                          </span>
                        <?php endif; ?>
                        <a href="<?= site_url('/clinic/encounters/' . $e['id']) ?>" class="btn btn-outline-secondary btn-sm">
                          View Visit
                        </a>
                      </div>
                    </div>

                    <div class="cp-visit-body">
                      <?php foreach ($treatmentsByEncounter[$e['id']] ?? [] as $item): ?>
                        <dl class="cp-treatment-dl">
                          <?php foreach (['diagnosis' => 'Diagnosis', 'treatment_plan' => 'Treatment plan', 'services_rendered' => 'Services rendered'] as $field => $label): ?>
                            <div class="cp-treatment-item">
                              <dt><?= esc($label) ?></dt>
                              <dd><?= nl2br(esc($item[$field] ?? '—')) ?></dd>
                            </div>
                          <?php endforeach; ?>
                        </dl>
                      <?php endforeach; ?>

                      <?php if (!empty($dispensationsByEncounter[$e['id']])): ?>
                        <div class="cp-dispense-block">
                          <h5 class="cp-dispense-heading">Medicines dispensed</h5>
                          <ul class="cp-dispense-list">
                            <?php foreach ($dispensationsByEncounter[$e['id']] as $item): ?>
                              <li>
                                <strong><?= esc(trim($item['medicine_name'] . ' ' . ($item['strength'] ?? ''))) ?></strong>:
                                <span class="cp-mono"><?= esc($item['quantity'] . ' ' . $item['unit_of_measure']) ?></span>
                                <span class="text-muted">(Lot <span class="cp-mono"><?= esc($item['batch_number']) ?></span><?php if (!empty($item['expiration_date'])): ?>; exp <span class="cp-mono"><?= esc($item['expiration_date']) ?></span><?php endif; ?>)</span> — <?= esc($item['instructions'] ?? 'As directed') ?>
                                <?php if (!empty($item['reversed_by_operation_id'])): ?>
                                  <span class="cp-status-inline cp-status-inline--warn ms-1">
                                    <span class="cp-dot" aria-hidden="true"></span>Corrected: <?= $item['correction_mode'] === 'restore_unissued' ? 'unissued units restored' : 'stock unchanged' ?>
                                  </span>
                                <?php endif; ?>
                              </li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      <?php endif; ?>

                      <?php if (!empty($e['assessment_notes'])): ?>
                        <p class="cp-assessment-note"><?= esc($e['assessment_notes']) ?></p>
                      <?php endif; ?>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- Tab 2: Vitals History -->
          <div class="tab-pane fade" id="tab-vitals" role="tabpanel">
            <?php if (empty($vitalsHistory)): ?>
              <div class="cp-empty-pane">
                <i class="bi bi-activity" aria-hidden="true"></i>
                No vital records available for this patient.
              </div>
            <?php else: ?>
              <div class="cp-table-card">
                <div class="cp-table-wrap">
                  <table class="table align-middle mb-0 cp-table" data-mobile-cards="false">
                    <thead>
                      <tr>
                        <th scope="col">Date</th>
                        <th scope="col">BP (mmHg)</th>
                        <th scope="col">Pulse</th>
                        <th scope="col">Temp</th>
                        <th scope="col">SpO2</th>
                        <th scope="col">Weight</th>
                        <th scope="col">BMI</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($vitalsHistory as $v): ?>
                        <?php $tempVal = isset($v['temp_c']) && is_numeric($v['temp_c']) ? (float) $v['temp_c'] : null; ?>
                        <tr class="cp-row">
                          <td class="cp-cell-header cp-mono fw-semibold"><?= esc(date('M j, Y · h:i A', strtotime((string) $v['recorded_at']))) ?></td>
                          <td data-label="BP (mmHg)" class="cp-mono">
                            <?= (!empty($v['bp_systolic']) && !empty($v['bp_diastolic'])) ? esc($v['bp_systolic'] . '/' . $v['bp_diastolic']) : '—' ?>
                          </td>
                          <td data-label="Pulse" class="cp-mono"><?= esc($v['pulse_bpm'] ?? '—') ?> bpm</td>
                          <td data-label="Temp" class="cp-mono <?= ($tempVal !== null && $tempVal >= 37.5) ? 'fw-bold' : '' ?>" <?= ($tempVal !== null && $tempVal >= 37.5) ? 'style="color: var(--cp-crimson);"' : '' ?>>
                            <?= esc($v['temp_c'] ?? '—') ?> &deg;C
                          </td>
                          <td data-label="SpO2" class="cp-mono"><?= esc($v['spo2_pct'] ?? '—') ?>%</td>
                          <td data-label="Weight" class="cp-mono"><?= esc($v['weight_kg'] ?? '—') ?> kg</td>
                          <td data-label="BMI" class="cp-mono fw-bold"><?= esc($v['bmi'] ?? '—') ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <?= view('clinic/patients/_labs_tab', get_defined_vars(), ['saveData' => false]) ?>

          <?= view('clinic/patients/_clearances_tab', get_defined_vars(), ['saveData' => false]) ?>

          <!-- Tab 5: Update Health Profile Form -->
          <div class="tab-pane fade" id="tab-edit-health" role="tabpanel">
            <?php if (empty($canWriteHealth)): ?>
              <div class="cp-notice mb-3">
                <i class="bi bi-lock me-1" aria-hidden="true"></i>Editing baseline Health Profile records requires full Clinic Staff or Physician permissions.
              </div>
            <?php endif; ?>
            <form action="<?= site_url('/clinic/patients/' . $person['id'] . '/health') ?>" method="POST" data-background-submit>
              <?= csrf_field() ?>
              <fieldset <?= empty($canWriteHealth) ? 'disabled' : '' ?>>
                <div class="cp-health-section">
                  <div class="cp-section-head">
                    <span class="cp-section-num">01</span>
                    <h4 class="cp-section-title">Baseline clinical alerts</h4>
                  </div>
                  <div class="row g-3">
                    <div class="col-md-4">
                      <label for="blood_type" class="form-label">Blood Type</label>
                      <select name="blood_type" id="blood_type" class="form-select">
                        <option value="">Unknown</option>
                        <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bt): ?>
                          <option value="<?= $bt ?>" <?= (($health['blood_type'] ?? '') === $bt) ? 'selected' : '' ?>><?= $bt ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-8">
                      <label for="allergies" class="form-label">Drug &amp; Food Allergies</label>
                      <input type="text" name="allergies" id="allergies" class="form-control" placeholder="e.g. Penicillin, Aspirin, Shellfish, Peanuts" value="<?= esc($health['allergies'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                      <label for="chronic_conditions" class="form-label">Chronic Conditions &amp; Baseline Alerts</label>
                      <textarea name="chronic_conditions" id="chronic_conditions" rows="2" class="form-control" placeholder="e.g. Asthma, Type 2 Diabetes, Hypertension"><?= esc($health['chronic_conditions'] ?? '') ?></textarea>
                    </div>
                  </div>
                </div>

                <div class="cp-health-section">
                  <div class="cp-section-head">
                    <span class="cp-section-num">02</span>
                    <h4 class="cp-section-title">Narrative medical history</h4>
                  </div>
                  <div class="row g-3">
                    <div class="col-md-6">
                      <label for="medical_history_notes" class="form-label">Past Medical History</label>
                      <textarea name="medical_history_notes" id="medical_history_notes" rows="2" class="form-control" placeholder="Hospitalizations, childhood illnesses..."><?= esc($health['medical_history_notes'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-6">
                      <label for="surgical_history" class="form-label">Surgical History</label>
                      <textarea name="surgical_history" id="surgical_history" rows="2" class="form-control" placeholder="Prior surgeries & year"><?= esc($health['surgical_history'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-6">
                      <label for="immunization_notes" class="form-label">Immunization History</label>
                      <textarea name="immunization_notes" id="immunization_notes" rows="2" class="form-control" placeholder="e.g. COVID-19, Hep B, Tetanus booster"><?= esc($health['immunization_notes'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-6">
                      <label for="family_history" class="form-label">Family Medical History</label>
                      <textarea name="family_history" id="family_history" rows="2" class="form-control" placeholder="e.g. Hypertension, Diabetes in family"><?= esc($health['family_history'] ?? '') ?></textarea>
                    </div>
                  </div>
                </div>

                <div class="cp-health-section">
                  <div class="cp-section-head">
                    <span class="cp-section-num">03</span>
                    <h4 class="cp-section-title">Emergency contact</h4>
                  </div>
                  <div class="row g-3">
                    <div class="col-md-5">
                      <label for="emergency_contact_name" class="form-label">Emergency Contact Person</label>
                      <input type="text" name="emergency_contact_name" id="emergency_contact_name" class="form-control" value="<?= esc($health['emergency_contact_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                      <label for="emergency_contact_phone" class="form-label">Emergency Phone Number</label>
                      <input type="text" name="emergency_contact_phone" id="emergency_contact_phone" class="form-control" value="<?= esc($health['emergency_contact_phone'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                      <label for="emergency_contact_relationship" class="form-label">Relationship</label>
                      <input type="text" name="emergency_contact_relationship" id="emergency_contact_relationship" class="form-control" placeholder="e.g. Mother, Spouse" value="<?= esc($health['emergency_contact_relationship'] ?? '') ?>">
                    </div>
                  </div>
                </div>

                <?php if (!empty($canWriteHealth)): ?>
                  <div class="d-flex flex-column-reverse flex-sm-row justify-content-sm-end gap-2 pt-2 border-top">
                    <button type="submit" class="btn btn-primary">
                      <i class="bi bi-save" aria-hidden="true"></i>Update Health Profile
                    </button>
                  </div>
                <?php endif; ?>
              </fieldset>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
  if (window.synapsePatientTabs) return;
  window.synapsePatientTabs = true;
  document.addEventListener('synapse:before-swap', event => {
    const old = event.detail.root.querySelector('[data-patient-chart]');
    const next = event.detail.incoming?.querySelector('[data-patient-chart]');
    if (!old || !next || old.dataset.patientChart !== next.dataset.patientChart) return;
    const target = old.querySelector('[data-bs-toggle="tab"].active')?.dataset.bsTarget;
    const tabs = [...next.querySelectorAll('[data-bs-toggle="tab"]')];
    if (!tabs.some(tab => tab.dataset.bsTarget === target)) return;
    tabs.forEach(tab => {
      const active = tab.dataset.bsTarget === target;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', String(active));
      const pane = next.querySelector(tab.dataset.bsTarget);
      pane?.classList.toggle('active', active);
      pane?.classList.toggle('show', active);
    });
  });

  function activateHashTab() {
    const hash = window.location.hash;
    if (!hash) return;
    const btn = document.querySelector(`[data-patient-chart] [data-bs-toggle="tab"][data-bs-target="${hash}"]`);
    if (btn && window.bootstrap?.Tab) {
      window.bootstrap.Tab.getOrCreateInstance(btn).show();
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', activateHashTab);
  else activateHashTab();
  document.addEventListener('click', event => {
    if (event.target.closest('[data-print-chart]')) {
      window.print();
    }
  });
})();
</script>
<?= $this->endSection() ?>

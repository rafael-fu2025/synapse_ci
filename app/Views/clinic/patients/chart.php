<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <?php $referralActor = session()->get('user') ?? []; ?>
  <?php if (($referralActor['role'] ?? '') === 'superadmin' || in_array('referrals.create', $referralActor['permissions'] ?? [], true)): ?>
  <a href="<?= esc(site_url('/clinic/referrals/create?' . http_build_query(['person_id' => $chart['person']['id']]))) ?>" class="btn btn-outline-primary btn-sm" data-app-modal-open>Refer to Guidance</a>
  <?php endif; ?>
  <a href="<?= site_url('/clinic/patients') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i> Patient Directory
  </a>
  <?php $openVisit = null;
  foreach ($chart['encounters'] as $visit) {
      if ($visit['status'] === 'in_progress') { $openVisit = $visit; break; }
  } ?>
  <?php if ($openVisit !== null): ?>
    <a href="<?= site_url('/clinic/encounters/' . (int) $openVisit['id']) ?>" class="btn btn-primary btn-sm">Resume Open Visit</a>
  <?php elseif (!empty($canStartVisit)): ?>
  <form action="<?= site_url('/clinic/encounters/start-direct/' . $chart['person']['id']) ?>" method="POST" class="d-inline">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-primary btn-sm ms-1">
      <i class="bi bi-plus-circle me-1"></i> Start New Visit
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
$treatmentsByEncounter = [];
foreach ($chart['treatment_history'] ?? [] as $item) {
    $treatmentsByEncounter[$item['encounter_id']][] = $item;
}
$dispensationsByEncounter = [];
foreach ($chart['dispensation_history'] ?? [] as $item) {
    $dispensationsByEncounter[$item['encounter_id']][] = $item;
}
?>

<!-- 1. NiceAdmin v3 Profile Board Banner Component -->
<div class="page-profile mb-4">
  <div class="card border-0 shadow-sm p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
      <div class="d-flex align-items-center gap-3">
        <div class="position-relative flex-shrink-0">
          <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-3 shadow-sm" style="width: 72px; height: 72px;">
            <?= strtoupper(substr($person['first_name'], 0, 1) . substr($person['last_name'], 0, 1)) ?>
          </div>
          <span class="position-absolute bottom-0 end-0 bg-success border border-white rounded-circle" style="width: 14px; height: 14px;"></span>
        </div>
        <div>
          <div class="text-uppercase small fw-bold text-primary" style="letter-spacing: 0.05em;">
            <?= esc(ucfirst($person['kind'])) ?> · <?= esc($student ? ($student['program'] . ' (Year ' . $student['year_level'] . ')') : ($employee['department'] ?? 'University Member')) ?>
          </div>
          <h2 class="h4 fw-bold mb-1 text-dark"><?= esc($person['last_name'] . ', ' . $person['first_name'] . ' ' . ($person['middle_name'] ?? '')) ?></h2>
          <?php
            $chartEmail = !empty($person['email'])
                ? $person['email']
                : \App\Services\UniversityEmail::derive($person['first_name'] ?? null, $person['last_name'] ?? null);
          ?>
          <div class="text-muted small">
            <span>MIS ID: <strong><?= esc($person['mis_id']) ?></strong></span>
            <?php if (!empty($chartEmail)): ?>
              <span class="ms-2">· Email: <strong><?= esc($chartEmail) ?></strong></span>
            <?php endif; ?>
            <span class="ms-2">· Gender: <?= esc(ucfirst($person['gender'] ?? 'Unspecified')) ?></span>
            <?php if (!empty($person['date_of_birth'])): ?>
              <span class="ms-2">· DOB: <?= date('M j, Y', strtotime($person['date_of_birth'])) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Quick Health Pill Tags -->
      <div class="d-flex flex-wrap gap-2">
        <span class="badge bg-light text-dark border px-3 py-2">
          <i class="bi bi-droplet-half text-danger me-1"></i> Blood: <strong><?= esc($health['blood_type'] ?? 'Unknown') ?></strong>
        </span>
        <?php if (!empty($health['allergies'])): ?>
          <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> Allergies: <?= esc($health['allergies']) ?>
          </span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Left Side: Health Profile & Emergency Contacts -->
  <div class="col-lg-4">
    <!-- Medical Summary Card -->
    <div class="card border-0 shadow-sm p-3 mb-4">
      <h6 class="fw-bold mb-3"><i class="bi bi-shield-plus text-danger me-1"></i> Clinical Profile</h6>
      <div class="small">
        <div class="mb-3">
          <span class="text-muted d-block">Blood Type:</span>
          <span class="badge bg-danger-subtle text-danger fw-bold fs-6 mt-1">
            <?= esc($health['blood_type'] ?? 'Unknown') ?>
          </span>
        </div>
        <div class="mb-3">
          <span class="text-muted d-block">Known Allergies:</span>
          <?php if (!empty($health['allergies'])): ?>
            <div class="text-danger fw-semibold small bg-danger-subtle p-2 rounded mt-1">
              <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= esc($health['allergies']) ?>
            </div>
          <?php else: ?>
            <span class="text-muted">No allergies recorded.</span>
          <?php endif; ?>
        </div>
        <div>
          <span class="text-muted d-block">Chronic Conditions:</span>
          <span class="fw-semibold text-dark"><?= esc($health['chronic_conditions'] ?? 'None on file') ?></span>
        </div>
      </div>
    </div>

    <!-- Emergency Contacts Card -->
    <div class="card border-0 shadow-sm p-3">
      <h6 class="fw-bold mb-3"><i class="bi bi-telephone-plus text-primary me-1"></i> Emergency Contact</h6>
      <div class="small">
        <div class="d-flex justify-content-between py-1 border-bottom">
          <span class="text-muted">Name:</span>
          <strong><?= esc($health['emergency_contact_name'] ?? '—') ?></strong>
        </div>
        <div class="d-flex justify-content-between py-1 border-bottom">
          <span class="text-muted">Relationship:</span>
          <strong><?= esc($health['emergency_contact_relationship'] ?? '—') ?></strong>
        </div>
        <div class="d-flex justify-content-between py-1">
          <span class="text-muted">Phone:</span>
          <strong><?= esc($health['emergency_contact_phone'] ?? '—') ?></strong>
        </div>
      </div>
    </div>
  </div>

  <!-- Right Side: Tabbed History & Details -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent border-bottom p-0">
        <ul class="nav nav-tabs nav-tabs-bordered" role="tablist">
          <li class="nav-item">
            <button class="nav-link active py-3 px-4 fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-visits">
              <i class="bi bi-clock-history me-1"></i> Visit History (<?= count($encounters) ?>)
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link py-3 px-4 fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-vitals">
              <i class="bi bi-graph-up me-1"></i> Vital Trends (<?= count($vitalsHistory) ?>)
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link py-3 px-4 fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-edit-health">
              <i class="bi bi-pencil-square me-1"></i> Update Health Profile
            </button>
          </li>
        </ul>
      </div>

      <div class="card-body p-4 tab-content">

        <!-- Tab 1: Visit History -->
        <div class="tab-pane fade show active" id="tab-visits">
          <?php if (empty($encounters)): ?>
            <div class="text-center py-5 text-muted small">
              <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
              No clinic encounters recorded for this patient yet.
            </div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach ($encounters as $e): ?>
                <div class="list-group-item bg-transparent p-3 border-bottom">
                  <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                      <h6 class="fw-bold mb-0 text-dark"><?= esc($e['chief_complaint'] ?? 'General Consult') ?></h6>
                      <small class="text-muted">
                        <?= date('F j, Y · h:i A', strtotime($e['started_at'])) ?> · Attending: <?= esc($e['attending_name'] ?? 'Unavailable') ?>
                      </small>
                    </div>
                    <div>
                      <?php if (!empty($e['disposition'])): ?>
                        <span class="badge bg-light text-dark border">
                          <?= esc(ucwords(str_replace('_', ' ', $e['disposition']))) ?>
                        </span>
                      <?php endif; ?>
                      <a href="<?= site_url('/clinic/encounters/' . $e['id']) ?>" class="btn btn-outline-primary btn-sm ms-2">
                        View Visit
                      </a>
                    </div>
                  </div>
                  <?php foreach ($treatmentsByEncounter[$e['id']] ?? [] as $item): ?>
                    <dl class="small mt-2 mb-2">
                      <?php foreach (['diagnosis' => 'Diagnosis', 'treatment_plan' => 'Treatment plan', 'services_rendered' => 'Services rendered'] as $field => $label): ?>
                        <dt><?= esc($label) ?></dt><dd class="text-break"><?= nl2br(esc($item[$field] ?? '—')) ?></dd>
                      <?php endforeach; ?>
                    </dl>
                  <?php endforeach; ?>
                  <?php if (!empty($dispensationsByEncounter[$e['id']])): ?>
                    <h6 class="small fw-bold mt-3">Medicines dispensed</h6>
                    <ul class="small">
                      <?php foreach ($dispensationsByEncounter[$e['id']] as $item): ?>
                        <li class="text-break">
                          <?= esc($item['medicine_name'] . ' ' . ($item['strength'] ?? '')) ?>:
                          <?= esc($item['quantity'] . ' ' . $item['unit_of_measure']) ?>
                          (Lot <?= esc($item['batch_number']) ?>) — <?= esc($item['instructions'] ?? 'As directed') ?>
                          <?php if (!empty($item['reversed_by_operation_id'])): ?>
                            <span class="badge bg-secondary">Corrected: <?= $item['correction_mode'] === 'restore_unissued' ? 'unissued units restored' : 'stock unchanged' ?></span>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                  <?php if (!empty($e['assessment_notes'])): ?>
                    <p class="mb-0 text-muted small bg-light p-2 rounded">
                      <?= esc($e['assessment_notes']) ?>
                    </p>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Tab 2: Vitals History -->
        <div class="tab-pane fade" id="tab-vitals">
          <?php if (empty($vitalsHistory)): ?>
            <div class="text-center py-5 text-muted small">
              No vital records available for this patient.
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle small mb-0">
                <thead class="table-light text-uppercase">
                  <tr>
                    <th>Date</th>
                    <th>BP (mmHg)</th>
                    <th>Pulse</th>
                    <th>Temp</th>
                    <th>SpO2</th>
                    <th>Weight</th>
                    <th>BMI</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($vitalsHistory as $v): ?>
                    <tr>
                      <td class="fw-semibold"><?= date('M j, Y h:i A', strtotime($v['recorded_at'])) ?></td>
                      <td>
                        <?= (!empty($v['bp_systolic']) && !empty($v['bp_diastolic'])) ? esc($v['bp_systolic'] . '/' . $v['bp_diastolic']) : '—' ?>
                      </td>
                      <td><?= esc($v['pulse_bpm'] ?? '—') ?> bpm</td>
                      <td><?= esc($v['temp_c'] ?? '—') ?> &deg;C</td>
                      <td><?= esc($v['spo2_pct'] ?? '—') ?>%</td>
                      <td><?= esc($v['weight_kg'] ?? '—') ?> kg</td>
                      <td>
                        <strong><?= esc($v['bmi'] ?? '—') ?></strong>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <!-- Tab 3: Update Health Profile Form -->
        <div class="tab-pane fade" id="tab-edit-health">
          <form action="<?= site_url('/clinic/patients/' . $person['id'] . '/health') ?>" method="POST">
            <?= csrf_field() ?>

            <div class="row g-3 mb-4">
              <div class="col-md-4">
                <label for="blood_type" class="form-label fw-semibold">Blood Type</label>
                <select name="blood_type" id="blood_type" class="form-select">
                  <option value="">Unknown</option>
                  <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bt): ?>
                    <option value="<?= $bt ?>" <?= (($health['blood_type'] ?? '') === $bt) ? 'selected' : '' ?>><?= $bt ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-8">
                <label for="allergies" class="form-label fw-semibold">Drug & Food Allergies</label>
                <input type="text"
                       name="allergies"
                       id="allergies"
                       class="form-control"
                       placeholder="e.g. Penicillin, Aspirin, Shellfish, Peanuts"
                       value="<?= esc($health['allergies'] ?? '') ?>">
              </div>

              <div class="col-12">
                <label for="chronic_conditions" class="form-label fw-semibold">Chronic Conditions & Medical History</label>
                <textarea name="chronic_conditions"
                          id="chronic_conditions"
                          rows="2"
                          class="form-control"
                          placeholder="e.g. Asthma, Type 2 Diabetes, Hypertension"><?= esc($health['chronic_conditions'] ?? '') ?></textarea>
              </div>

              <div class="col-md-5">
                <label for="emergency_contact_name" class="form-label fw-semibold">Emergency Contact Person</label>
                <input type="text"
                       name="emergency_contact_name"
                       id="emergency_contact_name"
                       class="form-control"
                       value="<?= esc($health['emergency_contact_name'] ?? '') ?>">
              </div>

              <div class="col-md-4">
                <label for="emergency_contact_phone" class="form-label fw-semibold">Emergency Phone Number</label>
                <input type="text"
                       name="emergency_contact_phone"
                       id="emergency_contact_phone"
                       class="form-control"
                       value="<?= esc($health['emergency_contact_phone'] ?? '') ?>">
              </div>

              <div class="col-md-3">
                <label for="emergency_contact_relationship" class="form-label fw-semibold">Relationship</label>
                <input type="text"
                       name="emergency_contact_relationship"
                       id="emergency_contact_relationship"
                       class="form-control"
                       placeholder="e.g. Mother, Spouse"
                       value="<?= esc($health['emergency_contact_relationship'] ?? '') ?>">
              </div>
            </div>

            <div class="d-flex justify-content-end">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i> Update Health Profile
              </button>
            </div>
          </form>
        </div>

      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

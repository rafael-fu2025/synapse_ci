<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <a href="<?= site_url('/clinic/encounters') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Encounters
  </a>
  <?php $referralActor = session()->get('user') ?? []; ?>
  <?php if (($referralActor['role'] ?? '') === 'superadmin' || in_array('referrals.create', $referralActor['permissions'] ?? [], true)): ?>
    <a href="<?= esc(site_url('/clinic/referrals/create?' . http_build_query(['person_id' => $encounter['patient']['id'], 'source_encounter_id' => $encounter['encounter']['id']]))) ?>"
       class="btn btn-outline-primary btn-sm"
       data-app-modal-open>
      <i class="bi bi-send" aria-hidden="true"></i> Refer to Guidance
    </a>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$patient = $encounter['patient'];
$enc = $encounter['encounter'];
$vitals = $encounter['vitals'] ?? [];
$treatment = $encounter['treatment'] ?? [];
$dispensations = $encounter['dispensations'] ?? [];
$health = $healthProfile ?? [];
$canSeal = $canSeal ?? true;
$canAddendum = $canAddendum ?? false;
$complaintCatalog = $complaintCatalog ?? \App\Services\Clinic\EncounterService::COMPLAINT_CATALOG;
$visitCount = (int) ($encounter['patient_visit_count'] ?? 1);
$visitedRaw = (string) ($enc['visited_at'] ?? $enc['started_at'] ?? '');
$visitedManilaIso = '';
if ($visitedRaw !== '') {
    try {
        $dtUtc = new \DateTimeImmutable($visitedRaw, new \DateTimeZone('UTC'));
        $visitedManilaIso = $dtUtc->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d\TH:i');
    } catch (\Throwable) {
        $visitedManilaIso = '';
    }
}
$selectedCategory = (string) ($enc['complaint_category'] ?? '');
$selectedQualifier = (string) ($enc['complaint_qualifier'] ?? '');
if ($selectedCategory === '' && !empty($enc['chief_complaint'])) {
    $lowerCc = strtolower(trim((string) $enc['chief_complaint']));
    foreach ($complaintCatalog as $catKey => $catMeta) {
        if ($catKey !== 'other' && str_starts_with($lowerCc, strtolower($catKey))) {
            $selectedCategory = $catKey;
            break;
        }
    }
}
$dispenseOldValue = static function (string $field, string $default = ''): string {
    $value = old($field, $default, false);
    return is_string($value) || is_int($value) ? (string) $value : $default;
};
$initialVitalsJson = (string) json_encode([
    'bpSys' => $vitals['bp_systolic'] ?? null,
    'bpDia' => $vitals['bp_diastolic'] ?? null,
    'tempC' => $vitals['temp_c'] ?? null,
    'weight' => $vitals['weight_kg'] ?? null,
    'height' => $vitals['height_cm'] ?? null,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>

<section class="ce-encounter">
  <!-- 1. Patient Context Header -->
  <div class="ce-patient-card clinic-patient-header">
    <div class="ce-patient-top">
      <div class="ce-patient-identity">
        <div class="ce-avatar" aria-hidden="true">
          <?= esc(strtoupper(substr((string) $patient['first_name'], 0, 1) . substr((string) $patient['last_name'], 0, 1))) ?>
        </div>
        <div style="min-width: 0;">
          <div class="ce-patient-heading">
            <h4 class="ce-patient-name"><?= esc($patient['last_name'] . ', ' . $patient['first_name']) ?></h4>
            <span class="ce-tag ce-tag-upper"><?= esc($patient['kind']) ?></span>
            <?php if (!empty($enc['status']) && $enc['status'] === 'in_progress'): ?>
              <span class="ce-status-dot ce-status-amber">In Visit</span>
            <?php else: ?>
              <span class="ce-status-dot ce-status-emerald">Closed</span>
            <?php endif; ?>
          </div>
          <div class="ce-patient-meta">
            <span>MIS ID: <strong class="ce-mono"><?= esc($patient['mis_id']) ?></strong></span>
            <?php if (!empty($studentProfile)): ?>
              <span class="ce-meta-sep" aria-hidden="true">·</span>
              <span><?= esc($studentProfile['program']) ?> (Year <?= esc($studentProfile['year_level']) ?>)</span>
            <?php elseif (!empty($employeeProfile)): ?>
              <span class="ce-meta-sep" aria-hidden="true">·</span>
              <span><?= esc($employeeProfile['department']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <a href="<?= site_url('/clinic/patients/' . $patient['id']) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm flex-shrink-0">
        <i class="bi bi-file-earmark-medical" aria-hidden="true"></i> Full Chart
      </a>
    </div>

    <!-- Clinical Alert Flags Bar -->
    <div class="ce-flags-bar">
      <div class="ce-flag-cell" title="Total clinic visits on record">
        <span class="ce-flag-label"><i class="bi bi-calendar2-check" aria-hidden="true"></i> Visits</span>
        <span class="ce-flag-value ce-mono <?= $visitCount >= 3 ? 'ce-flag-value--warn' : '' ?>"><?= $visitCount ?></span>
      </div>
      <div class="ce-flag-cell">
        <span class="ce-flag-label"><i class="bi bi-droplet" aria-hidden="true"></i> Blood Type</span>
        <span class="ce-flag-value ce-mono"><?= esc($health['blood_type'] ?? 'Unknown') ?></span>
      </div>
      <?php if (!empty($health['allergies'])): ?>
        <div class="ce-flag-cell ce-flag-cell--wide ce-flag-cell--allergy" title="Known allergies">
          <span class="ce-flag-label"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Allergies</span>
          <span class="ce-flag-value"><?= esc($health['allergies']) ?></span>
        </div>
      <?php endif; ?>
      <?php if (!empty($health['chronic_conditions'])): ?>
        <div class="ce-flag-cell ce-flag-cell--wide" title="Chronic conditions">
          <span class="ce-flag-label"><i class="bi bi-heart-pulse" aria-hidden="true"></i> Chronic Conditions</span>
          <span class="ce-flag-value"><?= esc($health['chronic_conditions']) ?></span>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($enc['status'] !== 'in_progress'): ?>
    <?= view('clinic/encounters/_closed', compact('enc', 'vitals', 'treatment', 'dispensations', 'encounter', 'canAddendum'), ['saveData' => false]) ?>
  <?php else: ?>
    <!-- 2. The 4-Step Consultation Stepper -->
    <div class="ce-card"
         x-data="encounterStepper(<?= esc($initialVitalsJson, 'attr') ?>)"
         data-encounter-stepper
         data-navigation-dirty="false"
         data-encounter-id="<?= (int) $enc['id'] ?>"
         data-complaint-catalog="<?= esc((string) json_encode($complaintCatalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') ?>"
         data-refresh-owner="clinic.encounter.<?= (int) $enc['id'] ?>"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>"
         data-draft-url="<?= site_url('/clinic/encounters/' . $enc['id'] . '/draft') ?>">

      <!-- Stepper Utility & Autosave Strip -->
      <div class="ce-stepper-utility">
        <?php if (empty($canSeal)): ?>
          <div class="ce-notice ce-notice-sky" role="status">
            <div class="ce-notice-body">
              <i class="bi bi-info-circle ce-notice-icon" aria-hidden="true"></i>
              <div><strong>Student Nurse / Intern Mode:</strong> You can record vitals, structured complaints, and clinical draft notes. An attending nurse or physician must finalize dispensing and seal the encounter.</div>
            </div>
          </div>
        <?php endif; ?>

        <div data-local-backup-banner hidden>
          <div class="ce-notice ce-notice-amber">
            <div class="ce-notice-body">
              <i class="bi bi-cloud-arrow-up ce-notice-icon" aria-hidden="true"></i>
              <span>Unsaved local draft recovered from browser backup.</span>
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" data-discard-local-backup>Dismiss</button>
          </div>
        </div>

        <div class="ce-save-bar">
          <div class="ce-save-left">
            <span class="ce-save-indicator">
              <span role="status" aria-live="polite" data-encounter-save-status>Saved</span>
            </span>
            <button type="button" class="btn btn-outline-primary btn-sm" data-save-draft hidden>Retry saving draft</button>
            <a href="<?= site_url('/clinic/encounters/' . $enc['id']) ?>" target="_blank" rel="noopener" class="ce-review-link" data-review-completion hidden>Check saved encounter in a new tab</a>
          </div>
          <p class="ce-save-hint">Clinical entries auto-save after inactivity and when switching steps. Medicine dispensing always requires confirmation.</p>
        </div>
      </div>

      <!-- Solid Maroon 4-Step Tab Navigation -->
      <div class="ce-stepper-track">
        <ul class="nav ce-stepper-tabs clinic-stepper-tabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#step-triage" type="button" role="tab" aria-selected="true">
              <span class="ce-step-num" aria-hidden="true">01</span>
              <i class="bi bi-heart-pulse" aria-hidden="true"></i>
              <span>Triage &amp; Vitals</span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#step-assessment" type="button" role="tab" aria-selected="false">
              <span class="ce-step-num" aria-hidden="true">02</span>
              <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
              <span>Assessment &amp; Plan</span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#step-dispense" type="button" role="tab" aria-selected="false">
              <span class="ce-step-num" aria-hidden="true">03</span>
              <i class="bi bi-capsule" aria-hidden="true"></i>
              <span>Medicine &amp; Dispensing</span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#step-outcome" type="button" role="tab" aria-selected="false">
              <span class="ce-step-num" aria-hidden="true">04</span>
              <i class="bi bi-check2-circle" aria-hidden="true"></i>
              <span>Outcome &amp; Referral</span>
            </button>
          </li>
        </ul>
      </div>

      <div class="ce-stepper-body tab-content">

        <!-- STEP 1: TRIAGE & VITALS -->
        <div class="tab-pane fade show active" id="step-triage" role="tabpanel">
          <form action="<?= site_url('/clinic/encounters/' . $enc['id'] . '/vitals') ?>" method="POST" data-clinical-form data-next-step="#step-assessment">
            <?= csrf_field() ?>

            <div class="ce-visit-time-band">
              <div>
                <label for="visitedAtDisplay" class="form-label">Actual Visit Date &amp; Time <span class="text-muted fw-normal">(Asia/Manila)</span></label>
                <input type="text"
                       class="form-control ce-mono"
                       id="visitedAtDisplay"
                       data-datetimepicker
                       data-target="#visited_at_iso"
                       placeholder="DD/MM/YYYY hh:mm AM/PM">
                <input type="hidden" name="visited_at" id="visited_at_iso" value="<?= esc($visitedManilaIso) ?>">
              </div>
              <div class="form-text m-0">
                Adjust only when encoding a paper/brownout backlog visit from an earlier hour or date. Current school year: <strong class="ce-mono"><?= esc($schoolYear ?? '') ?></strong>.
              </div>
            </div>

            <div class="ce-vitals-input-grid">
              <div class="ce-vital-field">
                <label for="vital_bp_systolic" class="form-label">Systolic BP (mmHg)</label>
                <input type="number"
                       id="vital_bp_systolic"
                       name="bp_systolic" min="30" max="300" step="1"
                       class="form-control"
                       placeholder="120"
                       x-model.number="bpSys"
                       value="<?= esc($vitals['bp_systolic'] ?? '') ?>">
                <span x-show="bpSys >= 140" class="ce-vital-flag ce-vital-flag--crimson">High BP Warning</span>
              </div>

              <div class="ce-vital-field">
                <label for="vital_bp_diastolic" class="form-label">Diastolic BP (mmHg)</label>
                <input type="number"
                       id="vital_bp_diastolic"
                       name="bp_diastolic" min="10" max="200" step="1"
                       class="form-control"
                       placeholder="80"
                       x-model.number="bpDia"
                       value="<?= esc($vitals['bp_diastolic'] ?? '') ?>">
              </div>

              <div class="ce-vital-field">
                <label for="vital_pulse_bpm" class="form-label">Pulse Rate (bpm)</label>
                <input type="number"
                       id="vital_pulse_bpm"
                       name="pulse_bpm" min="20" max="300" step="1"
                       class="form-control"
                       placeholder="72"
                       value="<?= esc($vitals['pulse_bpm'] ?? '') ?>">
              </div>

              <div class="ce-vital-field">
                <label for="vital_temp_c" class="form-label">Temperature (°C)</label>
                <input type="number"
                       id="vital_temp_c"
                       step="0.1"
                       name="temp_c" min="25" max="45"
                       class="form-control"
                       placeholder="36.5"
                       x-model.number="tempC"
                       value="<?= esc($vitals['temp_c'] ?? '') ?>">
                <span x-show="tempC >= 37.8" class="ce-vital-flag ce-vital-flag--amber">Fever</span>
              </div>

              <div class="ce-vital-field">
                <label for="vital_spo2_pct" class="form-label">SpO2 (%)</label>
                <input type="number"
                       id="vital_spo2_pct"
                       name="spo2_pct" min="0" max="100" step="1"
                       class="form-control"
                       placeholder="98"
                       value="<?= esc($vitals['spo2_pct'] ?? '') ?>">
              </div>

              <div class="ce-vital-field">
                <label for="vital_weight_kg" class="form-label">Weight (kg)</label>
                <input type="number"
                       id="vital_weight_kg"
                       step="0.1"
                       name="weight_kg" min="0.1" max="500"
                       class="form-control"
                       placeholder="65.0"
                       x-model.number="weight"
                       value="<?= esc($vitals['weight_kg'] ?? '') ?>">
              </div>

              <div class="ce-vital-field">
                <label for="vital_height_cm" class="form-label">Height (cm)</label>
                <input type="number"
                       id="vital_height_cm"
                       step="0.1"
                       name="height_cm" min="10" max="300"
                       class="form-control"
                       placeholder="168.0"
                       x-model.number="height"
                       value="<?= esc($vitals['height_cm'] ?? '') ?>">
              </div>

              <div>
                <div class="form-label">Body Mass Index (BMI)</div>
                <div class="ce-bmi-box">
                  <span class="ce-bmi-val" x-text="calcBmi() || '<?= esc($vitals['bmi'] ?? '—') ?>'"></span>
                  <span class="badge ce-bmi-tag" :class="bmiBadgeClass()" x-text="bmiCategory()"></span>
                </div>
              </div>
            </div>

            <div class="ce-step-footer">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-save" aria-hidden="true"></i> Save &amp; Continue
              </button>
            </div>
          </form>
        </div>

        <!-- STEP 2: ASSESSMENT & DIAGNOSIS -->
        <div class="tab-pane fade" id="step-assessment" role="tabpanel">
          <form action="<?= site_url('/clinic/encounters/' . $enc['id'] . '/assessment') ?>" method="POST" data-clinical-form data-next-step="#step-dispense">
            <?= csrf_field() ?>

            <div class="row g-3 mb-3">
              <div class="col-md-4">
                <label for="complaint_category" class="form-label">Structured Complaint Category</label>
                <select name="complaint_category" id="complaint_category" class="form-select" data-complaint-category>
                  <option value="">-- Select category --</option>
                  <?php foreach ($complaintCatalog as $catKey => $catMeta): ?>
                    <option value="<?= esc($catKey) ?>" <?= $selectedCategory === $catKey ? 'selected' : '' ?>><?= esc($catMeta['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label for="complaint_qualifier" class="form-label">Body Location / Qualifier</label>
                <?php $initialQualifiers = $selectedCategory !== '' && isset($complaintCatalog[$selectedCategory]['qualifiers']) ? $complaintCatalog[$selectedCategory]['qualifiers'] : []; ?>
                <select name="complaint_qualifier" id="complaint_qualifier" class="form-select" data-complaint-qualifier data-initial-value="<?= esc($selectedQualifier) ?>">
                  <option value="">-- Optional qualifier --</option>
                  <?php foreach ($initialQualifiers as $qOpt): ?>
                    <option value="<?= esc($qOpt) ?>" <?= $selectedQualifier === $qOpt ? 'selected' : '' ?>><?= esc($qOpt) ?></option>
                  <?php endforeach; ?>
                  <?php if ($selectedQualifier !== '' && !in_array($selectedQualifier, $initialQualifiers, true)): ?>
                    <option value="<?= esc($selectedQualifier) ?>" selected><?= esc($selectedQualifier) ?></option>
                  <?php endif; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label for="chief_complaint" class="form-label">Chief Complaint / Specifics</label>
                <input type="text"
                       name="chief_complaint" maxlength="255"
                       id="chief_complaint"
                       class="form-control"
                       placeholder="e.g. Fever, persistent headache, dizziness"
                       value="<?= esc($enc['chief_complaint'] ?? '') ?>"
                       data-chief-complaint-input
                       required>
              </div>
            </div>

            <div class="mb-3">
              <label for="assessment_notes" class="form-label">Clinical Examination &amp; Subjective Notes</label>
              <textarea name="assessment_notes" maxlength="10000"
                        id="assessment_notes"
                        rows="4"
                        class="form-control"
                        placeholder="Enter physical observations, symptom duration, history..."><?= esc($enc['assessment_notes'] ?? '') ?></textarea>
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label for="diagnosis" class="form-label">Clinical Diagnosis / Impression</label>
                <input type="text"
                       name="diagnosis" maxlength="255"
                       id="diagnosis"
                       class="form-control"
                       placeholder="e.g. Upper Respiratory Tract Infection (URTI), Tension Headache"
                       value="<?= esc($treatment['diagnosis'] ?? '') ?>">
              </div>

              <div class="col-md-6">
                <label for="services_rendered" class="form-label">Services / Procedures Rendered</label>
                <input type="text"
                       name="services_rendered" maxlength="10000"
                       id="services_rendered"
                       class="form-control"
                       placeholder="e.g. Wound dressing, ice pack application, vitals check"
                       value="<?= esc($treatment['services_rendered'] ?? '') ?>">
              </div>

              <div class="col-12">
                <label for="treatment_plan" class="form-label">Treatment Plan &amp; Non-Pharmacological Advice</label>
                <textarea name="treatment_plan" maxlength="10000"
                          id="treatment_plan"
                          rows="3"
                          class="form-control"
                          placeholder="Bed rest, increase fluid intake, avoid heavy lifting..."><?= esc($treatment['treatment_plan'] ?? '') ?></textarea>
              </div>
            </div>

            <div class="ce-step-footer">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-save" aria-hidden="true"></i> Save &amp; Continue
              </button>
            </div>
          </form>
        </div>

        <!-- STEP 3: MEDICINE & FEFO DISPENSING -->
        <div class="tab-pane fade" id="step-dispense" role="tabpanel">
          <div class="row g-4">
            <!-- Dispensed items list -->
            <div class="col-lg-7">
              <h6 class="ce-section-heading">
                <i class="bi bi-card-checklist" aria-hidden="true"></i> Medicines Dispensed for this Visit
              </h6>
              <div class="ce-dispense-table-wrap" data-refresh-region="dispensed-items">
                <div class="table-responsive">
                  <table class="table table-hover align-middle ce-table ce-table-mobile" data-mobile-cards="false">
                    <thead>
                      <tr>
                        <th>Medicine</th>
                        <th>Batch / Expiry</th>
                        <th>Quantity</th>
                        <th>Directions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($dispensations)): ?>
                        <tr>
                          <td colspan="4" class="ce-empty-row">
                            <i class="bi bi-capsule" aria-hidden="true"></i>
                            No medicines dispensed yet for this encounter.
                          </td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($dispensations as $d): ?>
                          <tr>
                            <td data-label="Medicine">
                              <div class="ce-disp-med-name">
                                <span><?= esc($d['medicine_name']) ?></span>
                                <span class="badge ce-strength-tag"><?= esc($d['strength']) ?></span>
                              </div>
                            </td>
                            <td data-label="Batch / Expiry">
                              <div class="ce-disp-meta">Lot: <?= esc($d['batch_number']) ?></div>
                              <div class="ce-disp-meta">Exp: <?= date('M j, Y', strtotime((string) $d['expiration_date'])) ?></div>
                              <?php if (!empty($d['snapshot_reconstructed'])): ?>
                                <span class="ce-disp-legacy">Legacy description reconstructed at inventory cutover</span>
                              <?php endif; ?>
                            </td>
                            <td data-label="Quantity">
                              <div class="ce-disp-qty"><?= esc($d['quantity']) ?> <?= esc($d['unit_of_measure']) ?></div>
                              <?php if (!empty($d['reversed_by_operation_id'])): ?>
                                <span class="badge ce-corrected-tag">Corrected: <?= $d['correction_mode'] === 'restore_unissued' ? 'unissued units restored' : 'stock unchanged' ?></span>
                              <?php endif; ?>
                            </td>
                            <td data-label="Directions" class="text-muted">
                              <?= esc($d['instructions'] ?? 'As directed') ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- Dispense new item form -->
            <div class="col-lg-5" data-refresh-region="dispense-form">
              <?php if (!empty($canDispense) && $enc['status'] === 'in_progress'): ?>
                <div class="ce-dispense-panel">
                  <h6 class="ce-section-heading">
                    <i class="bi bi-plus-circle" aria-hidden="true"></i> Dispense from Stock (FEFO)
                  </h6>
                  <form action="<?= site_url('/clinic/encounters/' . $enc['id'] . '/dispense') ?>" method="POST"
                        data-confirm="Dispense the selected quantity? Usable stock will be deducted and this action recorded. Patient returns must not be restocked."
                        data-confirm-title="Confirm dispensing"
                        data-confirm-label="Dispense medicine"
                        data-confirm-tone="primary">
                    <?= csrf_field() ?>
                    <input type="hidden" name="idempotency_key" value="<?= esc($dispenseKey) ?>">

                    <div class="mb-3">
                      <label for="medicine_id" class="form-label">Select Medicine</label>
                      <select name="medicine_id" id="medicine_id" class="form-select" required>
                        <option value="">-- Choose Medicine --</option>
                        <?php foreach ($availableMedicines as $med): ?>
                          <?php
                            $usable = (int) ($med['usable_stock'] ?? 0);
                            $rawExpiry = (string) ($med['next_expiration_date'] ?? $med['next_expiry'] ?? '');
                            $nextExp = $rawExpiry !== '' ? date('M j, Y', strtotime($rawExpiry)) : null;
                            $expDays = $rawExpiry !== '' ? (int) ((strtotime($rawExpiry) - strtotime(date('Y-m-d'))) / 86400) : null;
                            $stockText = $usable <= 0 ? 'OUT OF STOCK' : 'Usable: ' . $usable . ' ' . $med['unit_of_measure'];
                            $expText = $usable > 0 && $nextExp !== null ? ($expDays !== null && $expDays <= 90 ? ' · Expiring ' . $nextExp : ' · Exp ' . $nextExp) : '';
                          ?>
                          <option value="<?= (int) $med['id'] ?>" <?= $usable <= 0 ? 'disabled' : '' ?> <?= $dispenseOldValue('medicine_id') === (string) $med['id'] ? 'selected' : '' ?>>
                            <?= esc($med['name']) ?> (<?= esc($med['generic_name']) ?> <?= esc($med['strength']) ?>) — <?= esc($stockText . $expText) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <div class="form-text">Only usable stock is shown. Whole stock units are allocated from active, unexpired batches in earliest-expiry order. No fractional dosing or pack conversion is performed.</div>
                    </div>

                    <div class="mb-3">
                      <label for="quantity" class="form-label">Quantity to Dispense</label>
                      <input type="number" name="quantity" id="quantity" class="form-control ce-mono" min="1" max="1000000" step="1" value="<?= esc($dispenseOldValue('quantity', '1')) ?>" required>
                    </div>

                    <div class="mb-3">
                      <label for="instructions" class="form-label">Usage Instructions / Dosage</label>
                      <input type="text"
                             name="instructions"
                             id="instructions"
                             class="form-control"
                             maxlength="255"
                             value="<?= esc($dispenseOldValue('instructions')) ?>"
                             placeholder="Enter the clinician’s usage instructions">
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                      <i class="bi bi-box-seam" aria-hidden="true"></i> Dispense &amp; Deduct Inventory
                    </button>
                  </form>
                </div>
              <?php else: ?>
                <div class="alert ce-notice" data-banner-dismiss="off" role="status">
                  <div class="ce-notice-body">
                    <i class="bi bi-lock ce-notice-icon" aria-hidden="true"></i>
                    <span>Dispensing is unavailable. The encounter must be open and your active account must have inventory write access.</span>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="ce-step-footer">
            <button type="button" class="btn btn-outline-secondary" data-discard-medicine>Discard unfinished medicine entry</button>
            <button type="button" class="btn btn-primary" data-continue-outcome>Continue to Outcome</button>
          </div>
        </div>

        <!-- STEP 4: OUTCOME & DISPOSITION -->
        <div class="tab-pane fade" id="step-outcome" role="tabpanel">
          <form action="<?= site_url('/clinic/encounters/' . $enc['id'] . '/complete') ?>" method="POST" data-clinical-form data-complete-encounter
                data-confirm="Save your clinical entries and permanently seal this encounter? Once sealed, it becomes read-only and later updates must be appended as addenda."
                data-confirm-title="Seal clinical encounter"
                data-confirm-label="Save & seal encounter"
                data-confirm-tone="success">
            <?= csrf_field() ?>

            <div class="row g-3 mb-4">
              <div class="col-md-6">
                <label for="disposition" class="form-label">Final Disposition / Outcome</label>
                <select name="disposition" id="disposition" class="form-select" required>
                  <option value="discharged" <?= ($enc['disposition'] === 'discharged') ? 'selected' : '' ?>>Discharged (Back to Class / Work)</option>
                  <option value="sent_home" <?= ($enc['disposition'] === 'sent_home') ? 'selected' : '' ?>>Sent Home (Rest Advised)</option>
                  <option value="referred_to_guidance" <?= ($enc['disposition'] === 'referred_to_guidance') ? 'selected' : '' ?>>Refer to Guidance &amp; Counseling</option>
                  <option value="admitted" <?= ($enc['disposition'] === 'admitted') ? 'selected' : '' ?>>Admitted to Clinic Bed</option>
                  <option value="referred_to_hospital" <?= ($enc['disposition'] === 'referred_to_hospital') ? 'selected' : '' ?>>Hospital Transfer</option>
                </select>
              </div>

              <div class="col-md-6">
                <label for="follow_up_date" class="form-label">Recommended Follow-up Date (Optional)</label>
                <input type="text"
                       class="form-control ce-mono"
                       id="follow_up_date"
                       data-datepicker
                       data-target="#follow_up_date_iso"
                       data-min="today"
                       placeholder="Select follow-up date"
                       value="">
                <input type="hidden" name="follow_up_date" id="follow_up_date_iso" value="<?= esc($enc['follow_up_date'] ?? '') ?>">
              </div>

              <div class="col-12">
                <label for="disposition_notes" class="form-label">Discharge / Transfer Notes</label>
                <textarea name="disposition_notes" maxlength="10000"
                          id="disposition_notes"
                          rows="3"
                          class="form-control"
                          placeholder="Notes on student condition upon discharge or guidance referral context..."><?= esc($enc['disposition_notes'] ?? '') ?></textarea>
              </div>
            </div>

            <?php if (!empty($canSeal)): ?>
              <div class="ce-notice ce-notice-emerald">
                <div class="ce-notice-body">
                  <i class="bi bi-shield-check ce-notice-icon" aria-hidden="true"></i>
                  <div>
                    <strong>Closing Clinical Encounter:</strong> Saves your clinical entries and outcome, then seals the visit and completes the queue ticket. Medicine is only dispensed using “Dispense &amp; Deduct Inventory”.
                  </div>
                </div>
              </div>

              <div class="ce-step-footer">
                <button type="button" class="btn btn-outline-secondary" data-save-draft-manual>
                  <i class="bi bi-save" aria-hidden="true"></i> Save Draft Only
                </button>
                <button type="submit" class="btn btn-success">
                  <i class="bi bi-check-circle-fill" aria-hidden="true"></i> Save &amp; Complete Encounter
                </button>
              </div>
            <?php else: ?>
              <div class="ce-notice ce-notice-sky">
                <div class="ce-notice-body">
                  <i class="bi bi-lock ce-notice-icon" aria-hidden="true"></i>
                  <div>
                    <strong>Attending Signature Required to Seal:</strong> Student nurse / intern accounts cannot finalize or seal encounters. Save your draft so the attending physician or nurse can review and complete this visit.
                  </div>
                </div>
              </div>

              <div class="ce-step-footer">
                <button type="button" class="btn btn-primary" data-save-draft-manual>
                  <i class="bi bi-save" aria-hidden="true"></i> Save Clinical Draft for Attending Review
                </button>
              </div>
            <?php endif; ?>
          </form>
        </div>

      </div>
    </div>
  <?php endif; ?>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/encounter-stepper.js') ?>"></script>
<?= $this->endSection() ?>

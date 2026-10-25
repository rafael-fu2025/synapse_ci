<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= site_url('/clinic/encounters') ?>" class="btn btn-outline-secondary btn-sm">
  <i class="bi bi-arrow-left me-1"></i> Back to Encounters
</a>
<?php $referralActor = session()->get('user') ?? []; ?>
<?php if ($encounter['encounter']['status'] === 'in_progress' && (($referralActor['role'] ?? '') === 'superadmin' || in_array('referrals.create', $referralActor['permissions'] ?? [], true))): ?>
<a href="<?= esc(site_url('/clinic/referrals/create?' . http_build_query(['person_id' => $encounter['patient']['id'], 'source_encounter_id' => $encounter['encounter']['id']]))) ?>" class="btn btn-outline-primary btn-sm ms-2" data-app-modal-open><i class="bi bi-send me-1" aria-hidden="true"></i> Refer to Guidance</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$patient = $encounter['patient'];
$enc = $encounter['encounter'];
$vitals = $encounter['vitals'] ?? [];
$treatment = $encounter['treatment'] ?? [];
$dispensations = $encounter['dispensations'] ?? [];
$health = $healthProfile ?? [];
$dispenseOldValue = static function (string $field, string $default = ''): string {
    $value = old($field, $default, false);
    return is_string($value) || is_int($value) ? (string) $value : $default;
};
?>

<!-- 1. Patient Context Strip -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body p-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
      <div class="d-flex align-items-center gap-3">
        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-4" style="width: 50px; height: 50px;">
          <?= strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1)) ?>
        </div>
        <div>
          <div class="d-flex align-items-center gap-2">
            <h4 class="h5 fw-bold mb-0 text-dark"><?= esc($patient['last_name'] . ', ' . $patient['first_name']) ?></h4>
            <span class="badge bg-primary text-white text-uppercase"><?= esc($patient['kind']) ?></span>
            <?php if (!empty($enc['status']) && $enc['status'] === 'in_progress'): ?>
              <span class="badge bg-warning text-dark"><i class="bi bi-circle-fill me-1 small"></i> In Visit</span>
            <?php else: ?>
              <span class="badge bg-success">Closed</span>
            <?php endif; ?>
          </div>
          <div class="text-muted small mt-1">
            <span>MIS ID: <strong><?= esc($patient['mis_id']) ?></strong></span>
            <?php if (!empty($studentProfile)): ?>
              <span class="ms-2">· <?= esc($studentProfile['program']) ?> (Year <?= esc($studentProfile['year_level']) ?>)</span>
            <?php elseif (!empty($employeeProfile)): ?>
              <span class="ms-2">· <?= esc($employeeProfile['department']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Quick health flags -->
      <div class="d-flex align-items-center gap-2">
        <span class="badge bg-light text-dark border px-3 py-2">
          Blood: <strong><?= esc($health['blood_type'] ?? 'Unknown') ?></strong>
        </span>
        <?php if (!empty($health['allergies'])): ?>
          <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2" title="Known allergies">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> Allergies: <?= esc($health['allergies']) ?>
          </span>
        <?php endif; ?>
        <a href="<?= site_url('/clinic/patients/' . $patient['id']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
          <i class="bi bi-file-earmark-medical me-1"></i> Full Chart
        </a>
      </div>
    </div>
  </div>
</div>

<?php if ($enc['status'] !== 'in_progress'): ?>
  <?= view('clinic/encounters/_closed', compact('enc', 'vitals', 'treatment', 'dispensations'), ['saveData' => false]) ?>
<?php else: ?>
<!-- 2. The 4-Step Consultation Stepper -->
<div class="card border-0 shadow-sm" x-data="encounterStepper()" data-encounter-stepper data-navigation-dirty="false"
     data-draft-url="<?= site_url('/clinic/encounters/' . $enc['id'] . '/draft') ?>">
  <div class="px-4 pt-3">
    <p class="small text-muted mb-1">Clinical entries save when you change steps. Medicine dispensing always requires its own confirmation.</p>
    <div class="small mb-2" role="status" aria-live="polite" data-encounter-save-status>Saved</div>
    <button type="button" class="btn btn-outline-primary btn-sm mb-2" data-save-draft hidden>Retry saving draft</button>
    <a href="<?= site_url('/clinic/encounters/' . $enc['id']) ?>" target="_blank" rel="noopener" class="small mb-2" data-review-completion hidden>Check saved encounter in a new tab</a>
  </div>
  <!-- Nav tabs -->
  <div class="card-header bg-transparent border-bottom p-0">
    <ul class="nav nav-tabs nav-tabs-bordered d-flex text-center" role="tablist">
      <li class="nav-item flex-fill">
        <button class="nav-link w-100 py-3 active fw-semibold" data-bs-toggle="tab" data-bs-target="#step-triage" type="button">
          <i class="bi bi-heart-pulse me-1"></i> 1. Triage & Vitals
        </button>
      </li>
      <li class="nav-item flex-fill">
        <button class="nav-link w-100 py-3 fw-semibold" data-bs-toggle="tab" data-bs-target="#step-assessment" type="button">
          <i class="bi bi-clipboard2-pulse me-1"></i> 2. Assessment & Plan
        </button>
      </li>
      <li class="nav-item flex-fill">
        <button class="nav-link w-100 py-3 fw-semibold" data-bs-toggle="tab" data-bs-target="#step-dispense" type="button">
          <i class="bi bi-capsule me-1"></i> 3. Medicine & Dispensing
        </button>
      </li>
      <li class="nav-item flex-fill">
        <button class="nav-link w-100 py-3 fw-semibold" data-bs-toggle="tab" data-bs-target="#step-outcome" type="button">
          <i class="bi bi-check2-circle me-1"></i> 4. Outcome & Referral
        </button>
      </li>
    </ul>
  </div>

  <div class="card-body p-4 tab-content">

    <!-- STEP 1: TRIAGE & VITALS -->
    <div class="tab-pane fade show active" id="step-triage">
      <form action="<?= site_url('/clinic/encounters/' . $enc['id'] . '/vitals') ?>" method="POST" data-clinical-form data-next-step="#step-assessment">
        <?= csrf_field() ?>

        <div class="row g-3 mb-4">
          <!-- Blood pressure -->
          <div class="col-md-3">
            <label class="form-label fw-semibold">Systolic BP (mmHg)</label>
            <input type="number"
                   name="bp_systolic" min="30" max="300" step="1"
                   class="form-control"
                   placeholder="120"
                   x-model.number="bpSys"
                   value="<?= esc($vitals['bp_systolic'] ?? '') ?>">
            <span x-show="bpSys >= 140" class="badge bg-danger mt-1">High BP Warning</span>
          </div>

          <div class="col-md-3">
            <label class="form-label fw-semibold">Diastolic BP (mmHg)</label>
            <input type="number"
                   name="bp_diastolic" min="10" max="200" step="1"
                   class="form-control"
                   placeholder="80"
                   x-model.number="bpDia"
                   value="<?= esc($vitals['bp_diastolic'] ?? '') ?>">
          </div>

          <!-- Pulse & Temp -->
          <div class="col-md-3">
            <label class="form-label fw-semibold">Pulse Rate (bpm)</label>
            <input type="number"
                   name="pulse_bpm" min="20" max="300" step="1"
                   class="form-control"
                   placeholder="72"
                   value="<?= esc($vitals['pulse_bpm'] ?? '') ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label fw-semibold">Temperature (°C)</label>
            <input type="number"
                   step="0.1"
                   name="temp_c" min="25" max="45"
                   class="form-control"
                   placeholder="36.5"
                   x-model.number="tempC"
                   value="<?= esc($vitals['temp_c'] ?? '') ?>">
            <span x-show="tempC >= 37.8" class="badge bg-warning text-dark mt-1">Fever</span>
          </div>

          <!-- SpO2 -->
          <div class="col-md-3">
            <label class="form-label fw-semibold">SpO2 (%)</label>
            <input type="number"
                   name="spo2_pct" min="0" max="100" step="1"
                   class="form-control"
                   placeholder="98"
                   value="<?= esc($vitals['spo2_pct'] ?? '') ?>">
          </div>

          <!-- Weight & Height with auto-BMI -->
          <div class="col-md-3">
            <label class="form-label fw-semibold">Weight (kg)</label>
            <input type="number"
                   step="0.1"
                   name="weight_kg" min="0.1" max="500"
                   class="form-control"
                   placeholder="65.0"
                   x-model.number="weight"
                   value="<?= esc($vitals['weight_kg'] ?? '') ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label fw-semibold">Height (cm)</label>
            <input type="number"
                   step="0.1"
                   name="height_cm" min="10" max="300"
                   class="form-control"
                   placeholder="168.0"
                   x-model.number="height"
                   value="<?= esc($vitals['height_cm'] ?? '') ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label fw-semibold">Body Mass Index (BMI)</label>
            <div class="form-control bg-light d-flex align-items-center justify-content-between">
              <strong x-text="calcBmi() || '<?= esc($vitals['bmi'] ?? '—') ?>'"></strong>
              <span class="badge" :class="bmiBadgeClass()" x-text="bmiCategory()"></span>
            </div>
          </div>
        </div>

        <div class="d-flex justify-content-end">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1"></i> Save & Continue
          </button>
        </div>
      </form>
    </div>

    <!-- STEP 2: ASSESSMENT & DIAGNOSIS -->
    <div class="tab-pane fade" id="step-assessment">
      <form action="<?= site_url('/clinic/encounters/' . $enc['id'] . '/assessment') ?>" method="POST" data-clinical-form data-next-step="#step-dispense">
        <?= csrf_field() ?>

        <div class="mb-3">
          <label for="chief_complaint" class="form-label fw-semibold">Chief Complaint / Reason for Visit</label>
          <input type="text"
                 name="chief_complaint" maxlength="255"
                 id="chief_complaint"
                 class="form-control"
                 placeholder="e.g. Fever, persistent headache, dizziness"
                 value="<?= esc($enc['chief_complaint'] ?? '') ?>"
                 required>
        </div>

        <div class="mb-3">
          <label for="assessment_notes" class="form-label fw-semibold">Clinical Examination & Subjective Notes</label>
          <textarea name="assessment_notes" maxlength="10000"
                    id="assessment_notes"
                    rows="4"
                    class="form-control"
                    placeholder="Enter physical observations, symptom duration, history..."><?= esc($enc['assessment_notes'] ?? '') ?></textarea>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-md-6">
            <label for="diagnosis" class="form-label fw-semibold">Clinical Diagnosis / Impression</label>
            <input type="text"
                   name="diagnosis" maxlength="255"
                   id="diagnosis"
                   class="form-control"
                   placeholder="e.g. Upper Respiratory Tract Infection (URTI), Tension Headache"
                   value="<?= esc($treatment['diagnosis'] ?? '') ?>">
          </div>

          <div class="col-md-6">
            <label for="services_rendered" class="form-label fw-semibold">Services / Procedures Rendered</label>
            <input type="text"
                   name="services_rendered" maxlength="10000"
                   id="services_rendered"
                   class="form-control"
                   placeholder="e.g. Wound dressing, ice pack application, vitals check"
                   value="<?= esc($treatment['services_rendered'] ?? '') ?>">
          </div>

          <div class="col-12">
            <label for="treatment_plan" class="form-label fw-semibold">Treatment Plan & Non-Pharmacological Advice</label>
            <textarea name="treatment_plan" maxlength="10000"
                      id="treatment_plan"
                      rows="3"
                      class="form-control"
                      placeholder="Bed rest, increase fluid intake, avoid heavy lifting..."><?= esc($treatment['treatment_plan'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="d-flex justify-content-end">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1"></i> Save & Continue
          </button>
        </div>
      </form>
    </div>

    <!-- STEP 3: MEDICINE & FEFO DISPENSING -->
    <div class="tab-pane fade" id="step-dispense">
      <div class="row g-4">
        <!-- Dispensed items list -->
        <div class="col-lg-7">
          <h6 class="fw-bold mb-3"><i class="bi bi-card-checklist text-primary me-1"></i> Medicines Dispensed for this Visit</h6>
          <div class="table-responsive border rounded">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light small text-uppercase">
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
                    <td colspan="4" class="text-center py-4 text-muted small">
                      No medicines dispensed yet for this encounter.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($dispensations as $d): ?>
                    <tr>
                      <td class="fw-semibold">
                        <?= esc($d['medicine_name']) ?> <span class="badge bg-light text-dark border"><?= esc($d['strength']) ?></span>
                      </td>
                      <td>
                        <small class="d-block text-muted">Lot: <?= esc($d['batch_number']) ?></small>
                        <small class="text-muted">Exp: <?= date('M j, Y', strtotime($d['expiration_date'])) ?></small>
                                                <?php if (!empty($d['snapshot_reconstructed'])): ?><small class="d-block text-muted">Legacy description reconstructed at inventory cutover</small><?php endif; ?>
                      </td>
                      <td class="fw-bold text-primary">
                        <?= esc($d['quantity']) ?> <?= esc($d['unit_of_measure']) ?>
                                                <?php if (!empty($d['reversed_by_operation_id'])): ?><span class="badge bg-secondary d-block">Corrected: <?= $d['correction_mode'] === 'restore_unissued' ? 'unissued units restored' : 'stock unchanged' ?></span><?php endif; ?>
                      </td>
                      <td class="small text-muted">
                        <?= esc($d['instructions'] ?? 'As directed') ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Dispense new item form -->
        <div class="col-lg-5">
          <?php if (!empty($canDispense) && $enc['status'] === 'in_progress'): ?>
          <div class="card bg-light border-0 p-3">
            <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle text-primary me-1"></i> Dispense from Stock (FEFO)</h6>
            <form action="<?= site_url('/clinic/encounters/' . $enc['id'] . '/dispense') ?>" method="POST"
                  data-confirm="Dispense the selected quantity? Usable stock will be deducted and this action recorded. Patient returns must not be restocked."
                  data-confirm-title="Confirm dispensing" data-confirm-label="Dispense medicine" data-confirm-tone="primary">
              <?= csrf_field() ?>
              <input type="hidden" name="idempotency_key" value="<?= esc($dispenseKey) ?>">

              <div class="mb-3">
                <label for="medicine_id" class="form-label fw-semibold">Select Medicine</label>
                <select name="medicine_id" id="medicine_id" class="form-select" required>
                  <option value="">-- Choose Medicine --</option>
                  <?php foreach ($availableMedicines as $med): ?>
                    <option value="<?= (int) $med['id'] ?>" <?= $dispenseOldValue('medicine_id') === (string) $med['id'] ? 'selected' : '' ?>>
                      <?= esc($med['name']) ?> (<?= esc($med['generic_name']) ?> <?= esc($med['strength']) ?>) - Usable: <?= (int) $med['usable_stock'] ?> <?= esc($med['unit_of_measure']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text small text-muted">Only usable stock is shown. Whole stock units are allocated from active, unexpired batches in earliest-expiry order. No fractional dosing or pack conversion is performed.</div>
              </div>

              <div class="mb-3">
                <label for="quantity" class="form-label fw-semibold">Quantity to Dispense</label>
                <input type="number" name="quantity" id="quantity" class="form-control" min="1" max="1000000" step="1" value="<?= esc($dispenseOldValue('quantity', '1')) ?>" required>
              </div>

              <div class="mb-3">
                <label for="instructions" class="form-label fw-semibold">Usage Instructions / Dosage</label>
                <input type="text"
                       name="instructions"
                       id="instructions"
                       class="form-control"
                       maxlength="255" value="<?= esc($dispenseOldValue('instructions')) ?>"
                       placeholder="Enter the clinician’s usage instructions">
              </div>

              <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-box-seam me-1"></i> Dispense & Deduct Inventory
              </button>
            </form>
          </div>
          <?php else: ?>
            <div class="alert alert-secondary">Dispensing is unavailable. The encounter must be open and your active account must have inventory write access.</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="d-flex justify-content-end gap-2 mt-4">
        <button type="button" class="btn btn-outline-secondary" data-discard-medicine>Discard unfinished medicine entry</button>
        <button type="button" class="btn btn-primary" data-continue-outcome>Continue to Outcome</button>
      </div>
    </div>

    <!-- STEP 4: OUTCOME & DISPOSITION -->
    <div class="tab-pane fade" id="step-outcome">
      <form action="<?= site_url('/clinic/encounters/' . $enc['id'] . '/complete') ?>" method="POST" data-clinical-form data-complete-encounter>
        <?= csrf_field() ?>

        <div class="row g-3 mb-4">
          <div class="col-md-6">
            <label for="disposition" class="form-label fw-semibold">Final Disposition / Outcome</label>
            <select name="disposition" id="disposition" class="form-select" required>
              <option value="discharged" <?= ($enc['disposition'] === 'discharged') ? 'selected' : '' ?>>Discharged (Back to Class / Work)</option>
              <option value="sent_home" <?= ($enc['disposition'] === 'sent_home') ? 'selected' : '' ?>>Sent Home (Rest Advised)</option>
              <option value="referred_to_guidance" <?= ($enc['disposition'] === 'referred_to_guidance') ? 'selected' : '' ?>>Refer to Guidance & Counseling</option>
              <option value="admitted" <?= ($enc['disposition'] === 'admitted') ? 'selected' : '' ?>>Admitted to Clinic Bed</option>
              <option value="referred_to_hospital" <?= ($enc['disposition'] === 'referred_to_hospital') ? 'selected' : '' ?>>Hospital Transfer</option>
            </select>
          </div>

          <div class="col-md-6">
            <label for="follow_up_date" class="form-label fw-semibold">Recommended Follow-up Date (Optional)</label>
            <!-- Custom date picker: visible trigger + ISO hidden value -->
            <input type="text"
                   class="form-control"
                   id="follow_up_date"
                   data-datepicker
                   data-target="#follow_up_date_iso"
                   data-min="today"
                   value="">
            <input type="hidden" name="follow_up_date" id="follow_up_date_iso" value="<?= esc($enc['follow_up_date'] ?? '') ?>">
          </div>

          <div class="col-12">
            <label for="disposition_notes" class="form-label fw-semibold">Discharge / Transfer Notes</label>
            <textarea name="disposition_notes" maxlength="10000"
                      id="disposition_notes"
                      rows="3"
                      class="form-control"
                      placeholder="Notes on student condition upon discharge or guidance referral context..."><?= esc($enc['disposition_notes'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="card bg-success-subtle border-success-subtle p-3 mb-4">
          <div class="d-flex align-items-center">
            <i class="bi bi-shield-check text-success fs-3 me-3"></i>
            <div class="small">
              <strong>Closing Clinical Encounter:</strong> Saves your clinical entries and outcome, then seals the visit and completes the queue ticket. Medicine is only dispensed using “Dispense &amp; Deduct Inventory”.
            </div>
          </div>
        </div>

        <div class="d-flex justify-content-end">
          <button type="submit" class="btn btn-success">
            <i class="bi bi-check-circle-fill me-1"></i> Save & Complete Encounter
          </button>
        </div>
      </form>
    </div>

  </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/encounter-stepper.js') ?>"></script>
<script>
  function encounterStepper() {
    return {
      init() {
        this.$nextTick(() => { this.stopDraftSaving = window.SynapseEncounterStepper.init(this.$el); });
      },
      destroy() { this.stopDraftSaving?.(); },
      bpSys: <?= !empty($vitals['bp_systolic']) ? (int) $vitals['bp_systolic'] : 'null' ?>,
      bpDia: <?= !empty($vitals['bp_diastolic']) ? (int) $vitals['bp_diastolic'] : 'null' ?>,
      tempC: <?= !empty($vitals['temp_c']) ? (float) $vitals['temp_c'] : 'null' ?>,
      weight: <?= !empty($vitals['weight_kg']) ? (float) $vitals['weight_kg'] : 'null' ?>,
      height: <?= !empty($vitals['height_cm']) ? (float) $vitals['height_cm'] : 'null' ?>,

      calcBmi() {
        if (this.weight && this.height && this.height > 0) {
          const hMeter = this.height / 100.0;
          return (this.weight / (hMeter * hMeter)).toFixed(2);
        }
        return null;
      },

      bmiCategory() {
        const bmi = parseFloat(this.calcBmi());
        if (!bmi) return '';
        if (bmi < 18.5) return 'Underweight';
        if (bmi < 25.0) return 'Normal';
        if (bmi < 30.0) return 'Overweight';
        return 'Obese';
      },

      bmiBadgeClass() {
        const cat = this.bmiCategory();
        if (cat === 'Normal') return 'bg-success';
        if (cat === 'Overweight') return 'bg-warning text-dark';
        if (cat === 'Obese' || cat === 'Underweight') return 'bg-danger';
        return 'd-none';
      }
    };
  }
</script>
<?= $this->endSection() ?>

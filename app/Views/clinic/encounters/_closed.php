<?php
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
$addenda = $encounter['addenda'] ?? [];
$labResults = $encounter['lab_results'] ?? [];
$attendingName = $encounter['attending_user_name'] ?? null;
$encoderName = $encounter['encoder_user_name'] ?? null;
?>
<div class="alert alert-secondary d-flex flex-wrap justify-content-between align-items-center gap-2" role="status">
  <span><i class="bi bi-lock-fill me-1"></i> This encounter is closed and read-only. Use Addenda / Lab Follow-up below for delayed updates.</span>
  <a href="<?= site_url('/clinic/encounters/' . (int) $enc['id'] . '/certificate') ?>" class="btn btn-outline-dark btn-sm" target="_blank" rel="noopener">
    <i class="bi bi-file-earmark-pdf me-1"></i> Print Medical Certificate PDF
  </a>
</div>
<div class="card border-0 shadow-sm p-4 mb-4">
  <div class="row g-3 mb-4 pb-3 border-bottom small">
    <div class="col-md-4">
      <div class="text-muted">Actual Visit Time (Asia/Manila)</div>
      <div class="fw-semibold"><?= esc($formatManila($enc['visited_at'] ?? $enc['started_at'] ?? null)) ?></div>
    </div>
    <div class="col-md-4">
      <div class="text-muted">Closed / Sealed At (Asia/Manila)</div>
      <div class="fw-semibold"><?= esc($formatManila($enc['completed_at'] ?? null)) ?></div>
    </div>
    <div class="col-md-4">
      <div class="text-muted">Clinician Attribution</div>
      <div class="fw-semibold">
        Attending: <?= esc($attendingName ?: 'Recorded clinician') ?>
        <?php if ($encoderName && $encoderName !== $attendingName): ?>
          <span class="text-muted fw-normal d-block">Draft encoded by: <?= esc($encoderName) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <h5>Vitals</h5>
  <dl class="row small">
    <?php foreach (['bp_systolic' => 'Systolic BP (mmHg)', 'bp_diastolic' => 'Diastolic BP (mmHg)', 'pulse_bpm' => 'Pulse (bpm)', 'temp_c' => 'Temperature (°C)', 'spo2_pct' => 'SpO2 (%)', 'weight_kg' => 'Weight (kg)', 'height_cm' => 'Height (cm)', 'bmi' => 'BMI'] as $field => $label): ?>
      <dt class="col-sm-4"><?= esc($label) ?></dt><dd class="col-sm-8"><?= esc($vitals[$field] ?? '—') ?></dd>
    <?php endforeach; ?>
  </dl>
  <h5>Assessment &amp; treatment</h5>
  <dl class="small">
    <?php if (!empty($enc['complaint_category']) || !empty($enc['complaint_qualifier'])): ?>
      <dt>Structured Complaint</dt>
      <dd><?= esc(ucwords((string) ($enc['complaint_category'] ?? '')) . (!empty($enc['complaint_qualifier']) ? ' — ' . $enc['complaint_qualifier'] : '')) ?></dd>
    <?php endif; ?>
    <?php foreach (['chief_complaint' => 'Chief complaint', 'assessment_notes' => 'Assessment'] as $field => $label): ?>
      <dt><?= esc($label) ?></dt><dd class="text-break"><?= nl2br(esc($enc[$field] ?? '—')) ?></dd>
    <?php endforeach; ?>
    <?php foreach (['diagnosis' => 'Diagnosis', 'treatment_plan' => 'Treatment plan', 'services_rendered' => 'Services rendered'] as $field => $label): ?>
      <dt><?= esc($label) ?></dt><dd class="text-break"><?= nl2br(esc($treatment[$field] ?? '—')) ?></dd>
    <?php endforeach; ?>
  </dl>
  <h5>Medicines dispensed</h5>
  <?php if ($dispensations === []): ?><p class="text-muted">No medicines dispensed.</p><?php endif; ?>
  <ul class="small">
    <?php foreach ($dispensations as $item): ?>
      <li class="text-break">
        <?= esc($item['medicine_name'] . ' ' . ($item['strength'] ?? '')) ?>:
        <?= esc($item['quantity'] . ' ' . $item['unit_of_measure']) ?>
        (Lot <?= esc($item['batch_number']) ?>; expiry <?= esc($item['expiration_date']) ?>)
        — <?= esc($item['instructions'] ?? 'As directed') ?>
        <?php if (!empty($item['snapshot_reconstructed'])): ?><span class="text-muted">Legacy description reconstructed at inventory cutover</span><?php endif; ?>
        <?php if (!empty($item['reversed_by_operation_id'])): ?><span class="badge bg-secondary">Corrected: <?= $item['correction_mode'] === 'restore_unissued' ? 'unissued units restored' : 'stock unchanged' ?></span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <h5>Outcome</h5>
  <dl class="small mb-0">
    <?php foreach (['status' => 'Status', 'disposition' => 'Disposition', 'disposition_notes' => 'Disposition notes', 'follow_up_date' => 'Follow-up date'] as $field => $label): ?>
      <dt><?= esc($label) ?></dt><dd class="text-break"><?= nl2br(esc($enc[$field] ?? '—')) ?></dd>
    <?php endforeach; ?>
  </dl>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent py-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-journal-medical me-1 text-primary"></i> Post-Visit Addenda &amp; Lab Follow-ups</h6>
      </div>
      <div class="card-body">
        <?php if (empty($labResults) && empty($addenda)): ?>
          <p class="text-muted small mb-0">No post-visit lab results or signed addenda recorded yet.</p>
        <?php else: ?>
          <?php foreach ($labResults as $lab): ?>
            <div class="border rounded p-2 mb-2 bg-light-subtle small">
              <div class="d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-flask me-1"></i> <?= esc($lab['test_name']) ?></strong>
                <span class="badge bg-info-subtle text-info-emphasis text-uppercase"><?= esc(str_replace('_', ' ', (string) $lab['result_status'])) ?></span>
              </div>
              <?php if (!empty($lab['result_summary'])): ?>
                <div class="mt-1"><?= nl2br(esc($lab['result_summary'])) ?></div>
              <?php endif; ?>
              <?php if (!empty($lab['clinical_notes'])): ?>
                <div class="text-muted mt-1"><?= nl2br(esc($lab['clinical_notes'])) ?></div>
              <?php endif; ?>
              <div class="text-muted mt-1" style="font-size: 0.78rem;">
                Recorded <?= esc($formatManila($lab['submitted_at'] ?? $lab['created_at'] ?? null)) ?> by <?= esc($lab['recorded_by_name'] ?? 'Clinic staff') ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php foreach ($addenda as $add): ?>
            <div class="border rounded p-2 mb-2 small">
              <div class="d-flex justify-content-between align-items-center">
                <span class="badge bg-secondary-subtle text-dark text-uppercase"><?= esc(str_replace('_', ' ', (string) $add['addendum_type'])) ?></span>
                <span class="text-muted" style="font-size: 0.78rem;"><?= esc($formatManila($add['created_at'] ?? null)) ?></span>
              </div>
              <div class="mt-1"><?= nl2br(esc($add['note'])) ?></div>
              <div class="text-muted mt-1" style="font-size: 0.78rem;">Signed by <?= esc($add['author_name'] ?? 'Clinic staff') ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (!empty($canAddendum)): ?>
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100" x-data="{ kind: 'lab_followup' }">
        <div class="card-header bg-transparent py-3">
          <h6 class="fw-bold mb-0"><i class="bi bi-plus-circle me-1 text-primary"></i> Append Lab Result or Signed Addendum</h6>
        </div>
        <div class="card-body">
          <form method="POST" action="<?= site_url('/clinic/encounters/' . (int) $enc['id'] . '/addendum') ?>"
                data-confirm="Append this signed update to the closed encounter? Addenda cannot be deleted once recorded."
                data-confirm-title="Record post-visit addendum"
                data-confirm-label="Append Addendum"
                data-confirm-tone="primary">
            <?= csrf_field() ?>
            <div class="mb-2">
              <label for="addendum_type" class="form-label small fw-semibold">Update Type</label>
              <select name="addendum_type" id="addendum_type" class="form-select form-select-sm" x-model="kind" required>
                <option value="lab_followup">Delayed Lab / Diagnostic Result</option>
                <option value="clinical_note">Signed Clinical Addendum</option>
                <option value="time_correction">Visit Time / Log Clarification</option>
              </select>
            </div>
            <div x-show="kind === 'lab_followup'">
              <div class="row g-2 mb-2">
                <div class="col-sm-7">
                  <label for="addendum_test_name" class="form-label small fw-semibold">Diagnostic / Lab Test Name</label>
                  <input type="text" name="test_name" id="addendum_test_name" class="form-control form-control-sm" placeholder="e.g. CBC, Chest X-ray, Urinalysis" maxlength="120" :required="kind === 'lab_followup'">
                </div>
                <div class="col-sm-5">
                  <label for="addendum_result_status" class="form-label small fw-semibold">Result Classification</label>
                  <select name="result_status" id="addendum_result_status" class="form-select form-select-sm">
                    <option value="normal">Normal</option>
                    <option value="abnormal">Abnormal / Follow-up</option>
                    <option value="inconclusive">Inconclusive / Repeat</option>
                    <option value="pending">Pending Result</option>
                  </select>
                </div>
              </div>
              <div class="mb-2">
                <label for="addendum_result_summary" class="form-label small fw-semibold">Lab Summary / Values</label>
                <input type="text" name="result_summary" id="addendum_result_summary" class="form-control form-control-sm" placeholder="e.g. WBC elevated; advised hydration & rest" maxlength="255">
              </div>
            </div>
            <div class="mb-3">
              <label for="addendum_note" class="form-label small fw-semibold">Clinical Addendum Note</label>
              <textarea name="note" id="addendum_note" rows="3" class="form-control form-control-sm" placeholder="Enter physician / nurse follow-up note..." maxlength="5000" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
              <i class="bi bi-check2-square me-1"></i> Save Signed Addendum
            </button>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

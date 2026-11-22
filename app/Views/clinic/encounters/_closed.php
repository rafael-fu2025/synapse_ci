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

$vitalTiles = [
    ['key' => 'bp_systolic', 'label' => 'Systolic BP', 'unit' => 'mmHg'],
    ['key' => 'bp_diastolic', 'label' => 'Diastolic BP', 'unit' => 'mmHg'],
    ['key' => 'pulse_bpm', 'label' => 'Pulse Rate', 'unit' => 'bpm'],
    ['key' => 'temp_c', 'label' => 'Temperature', 'unit' => '°C'],
    ['key' => 'spo2_pct', 'label' => 'SpO2', 'unit' => '%'],
    ['key' => 'weight_kg', 'label' => 'Weight', 'unit' => 'kg'],
    ['key' => 'height_cm', 'label' => 'Height', 'unit' => 'cm'],
    ['key' => 'bmi', 'label' => 'BMI', 'unit' => ''],
];

$dispositionLabel = !empty($enc['disposition'])
    ? ucwords(str_replace('_', ' ', (string) $enc['disposition']))
    : '—';
$statusRaw = (string) ($enc['status'] ?? '');
$statusLabel = $statusRaw !== '' ? ucwords(str_replace('_', ' ', $statusRaw)) : '—';
$statusDotClass = match ($statusRaw) {
    'completed' => 'ce-status-emerald',
    'referred' => 'ce-status-sky',
    default => 'ce-status-slate',
};
?>

<div class="ce-closed-wrap clinic-closed-card">
  <!-- Locked Read-Only Status Banner -->
  <div class="ce-locked-banner" role="status">
    <div class="ce-locked-banner-text">
      <i class="bi bi-lock-fill" aria-hidden="true"></i>
      <span>This encounter is closed and read-only. Use Addenda / Lab Follow-up below for delayed updates.</span>
    </div>
    <a href="<?= site_url('/clinic/encounters/' . (int) $enc['id'] . '/certificate') ?>"
       class="btn btn-outline-secondary btn-sm clinic-cert-btn text-nowrap"
       target="_blank"
       rel="noopener">
      <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Print Medical Certificate PDF
    </a>
  </div>

  <!-- Sealed Encounter Clinical Record Card -->
  <div class="ce-card">
    <!-- Audit & Clinician Attribution Strip -->
    <div class="ce-audit-strip">
      <div class="ce-audit-cell">
        <div class="ce-audit-label">Actual Visit Time (Asia/Manila)</div>
        <div class="ce-audit-value ce-mono"><?= esc($formatManila($enc['visited_at'] ?? $enc['started_at'] ?? null)) ?></div>
      </div>
      <div class="ce-audit-cell">
        <div class="ce-audit-label">Closed / Sealed At (Asia/Manila)</div>
        <div class="ce-audit-value ce-mono"><?= esc($formatManila($enc['completed_at'] ?? null)) ?></div>
      </div>
      <div class="ce-audit-cell">
        <div class="ce-audit-label">Clinician Attribution</div>
        <div class="ce-audit-value">Attending: <?= esc($attendingName ?: 'Recorded clinician') ?></div>
        <?php if ($encoderName && $encoderName !== $attendingName): ?>
          <div class="ce-audit-sub">Draft encoded by: <?= esc($encoderName) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Vitals Metric Grid -->
    <div class="ce-record-section">
      <h5 class="ce-record-section-title">
        <i class="bi bi-heart-pulse" aria-hidden="true"></i> Vitals
      </h5>
      <div class="ce-vitals-grid">
        <?php foreach ($vitalTiles as $tile): ?>
          <?php $val = $vitals[$tile['key']] ?? null; ?>
          <div class="ce-vital-tile">
            <div class="ce-vital-tile-label"><?= esc($tile['label']) ?></div>
            <div class="ce-vital-tile-value">
              <?= esc($val !== null && $val !== '' ? (string) $val : '—') ?>
              <?php if ($val !== null && $val !== '' && $tile['unit'] !== ''): ?>
                <span class="ce-vital-tile-unit"><?= esc($tile['unit']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Assessment & Treatment -->
    <div class="ce-record-section">
      <h5 class="ce-record-section-title">
        <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i> Assessment &amp; Treatment
      </h5>
      <div class="ce-kv-grid">
        <?php if (!empty($enc['complaint_category']) || !empty($enc['complaint_qualifier'])): ?>
          <div>
            <div class="ce-kv-label">Structured Complaint</div>
            <div class="ce-kv-value">
              <?= esc(ucwords((string) ($enc['complaint_category'] ?? '')) . (!empty($enc['complaint_qualifier']) ? ' — ' . $enc['complaint_qualifier'] : '')) ?>
            </div>
          </div>
        <?php endif; ?>
        <div>
          <div class="ce-kv-label">Chief Complaint</div>
          <div class="ce-kv-value"><?= nl2br(esc($enc['chief_complaint'] ?? '—')) ?></div>
        </div>
        <div>
          <div class="ce-kv-label">Diagnosis</div>
          <div class="ce-kv-value"><?= nl2br(esc($treatment['diagnosis'] ?? '—')) ?></div>
        </div>
        <div>
          <div class="ce-kv-label">Services Rendered</div>
          <div class="ce-kv-value"><?= nl2br(esc($treatment['services_rendered'] ?? '—')) ?></div>
        </div>
        <div class="ce-kv-item--full">
          <div class="ce-kv-label">Assessment</div>
          <div class="ce-kv-value ce-kv-box"><?= nl2br(esc($enc['assessment_notes'] ?? '—')) ?></div>
        </div>
        <div class="ce-kv-item--full">
          <div class="ce-kv-label">Treatment Plan</div>
          <div class="ce-kv-value ce-kv-box"><?= nl2br(esc($treatment['treatment_plan'] ?? '—')) ?></div>
        </div>
      </div>
    </div>

    <!-- Medicines Dispensed -->
    <div class="ce-record-section">
      <h5 class="ce-record-section-title">
        <i class="bi bi-capsule" aria-hidden="true"></i> Medicines Dispensed
      </h5>
      <?php if ($dispensations === []): ?>
        <p class="text-muted small mb-0">No medicines dispensed.</p>
      <?php else: ?>
        <div class="ce-dispense-table-wrap">
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
                <?php foreach ($dispensations as $item): ?>
                  <tr>
                    <td data-label="Medicine">
                      <div class="ce-disp-med-name">
                        <span><?= esc($item['medicine_name']) ?></span>
                        <?php if (!empty($item['strength'])): ?>
                          <span class="badge ce-strength-tag"><?= esc($item['strength']) ?></span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td data-label="Batch / Expiry">
                      <div class="ce-disp-meta">Lot: <?= esc($item['batch_number']) ?></div>
                      <div class="ce-disp-meta">Exp: <?= date('M j, Y', strtotime((string) $item['expiration_date'])) ?></div>
                      <?php if (!empty($item['snapshot_reconstructed'])): ?>
                        <span class="ce-disp-legacy">Legacy description reconstructed at inventory cutover</span>
                      <?php endif; ?>
                    </td>
                    <td data-label="Quantity">
                      <div class="ce-disp-qty"><?= esc($item['quantity'] . ' ' . $item['unit_of_measure']) ?></div>
                      <?php if (!empty($item['reversed_by_operation_id'])): ?>
                        <span class="badge ce-corrected-tag">Corrected: <?= $item['correction_mode'] === 'restore_unissued' ? 'unissued units restored' : 'stock unchanged' ?></span>
                      <?php endif; ?>
                    </td>
                    <td data-label="Directions" class="text-muted">
                      <?= esc($item['instructions'] ?? 'As directed') ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Outcome -->
    <div class="ce-record-section">
      <h5 class="ce-record-section-title">
        <i class="bi bi-check2-circle" aria-hidden="true"></i> Outcome
      </h5>
      <div class="ce-kv-grid ce-kv-grid--three">
        <div>
          <div class="ce-kv-label">Status</div>
          <div class="ce-kv-value">
            <span class="ce-status-dot <?= $statusDotClass ?>"><?= esc($statusLabel) ?></span>
          </div>
        </div>
        <div>
          <div class="ce-kv-label">Disposition</div>
          <div class="ce-kv-value">
            <span class="ce-tag"><?= esc($dispositionLabel) ?></span>
          </div>
        </div>
        <div>
          <div class="ce-kv-label">Follow-up Date</div>
          <div class="ce-kv-value ce-mono"><?= esc($enc['follow_up_date'] ?? '—') ?></div>
        </div>
        <div class="ce-kv-item--full">
          <div class="ce-kv-label">Disposition Notes</div>
          <div class="ce-kv-value ce-kv-box"><?= nl2br(esc($enc['disposition_notes'] ?? '—')) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Post-Visit Addenda & Lab Follow-ups Row -->
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="ce-card h-100">
        <div class="ce-card-header">
          <h6 class="ce-card-title">
            <i class="bi bi-journal-medical" aria-hidden="true"></i> Post-Visit Addenda &amp; Lab Follow-ups
          </h6>
        </div>
        <div class="ce-card-body">
          <?php if (empty($labResults) && empty($addenda)): ?>
            <p class="text-muted small mb-0">No post-visit lab results or signed addenda recorded yet.</p>
          <?php else: ?>
            <div class="ce-entry-list">
              <?php foreach ($labResults as $lab): ?>
                <?php
                  $labStatus = strtolower((string) ($lab['result_status'] ?? ''));
                  $cardMod = match ($labStatus) {
                      'normal' => 'ce-entry-card--normal',
                      'abnormal' => 'ce-entry-card--abnormal',
                      'pending', 'inconclusive' => 'ce-entry-card--pending',
                      default => '',
                  };
                  $dotMod = match ($labStatus) {
                      'normal' => 'ce-status-emerald',
                      'abnormal' => 'ce-status-crimson',
                      'pending', 'inconclusive' => 'ce-status-amber',
                      default => 'ce-status-slate',
                  };
                ?>
                <div class="ce-entry-card <?= $cardMod ?>">
                  <div class="ce-entry-head">
                    <span class="ce-entry-title">
                      <i class="bi bi-flask" aria-hidden="true"></i> <?= esc($lab['test_name']) ?>
                    </span>
                    <span class="ce-status-dot <?= $dotMod ?>"><?= esc(ucwords(str_replace('_', ' ', $labStatus))) ?></span>
                  </div>
                  <?php if (!empty($lab['result_summary'])): ?>
                    <div class="ce-entry-body"><?= nl2br(esc($lab['result_summary'])) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($lab['clinical_notes'])): ?>
                    <div class="ce-entry-notes"><?= nl2br(esc($lab['clinical_notes'])) ?></div>
                  <?php endif; ?>
                  <div class="ce-entry-foot">
                    Recorded <span class="ce-mono"><?= esc($formatManila($lab['submitted_at'] ?? $lab['created_at'] ?? null)) ?></span> by <?= esc($lab['recorded_by_name'] ?? 'Clinic staff') ?>
                  </div>
                </div>
              <?php endforeach; ?>

              <?php foreach ($addenda as $add): ?>
                <div class="ce-entry-card ce-entry-card--addendum">
                  <div class="ce-entry-head">
                    <span class="ce-tag ce-tag-upper"><?= esc(str_replace('_', ' ', (string) $add['addendum_type'])) ?></span>
                    <span class="text-muted ce-mono" style="font-size: 0.74rem;"><?= esc($formatManila($add['created_at'] ?? null)) ?></span>
                  </div>
                  <div class="ce-entry-body"><?= nl2br(esc($add['note'])) ?></div>
                  <div class="ce-entry-foot">Signed by <?= esc($add['author_name'] ?? 'Clinic staff') ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!empty($canAddendum)): ?>
      <div class="col-lg-6">
        <div class="ce-card h-100" x-data="{ kind: 'lab_followup' }">
          <div class="ce-card-header">
            <h6 class="ce-card-title">
              <i class="bi bi-plus-circle" aria-hidden="true"></i> Append Lab Result or Signed Addendum
            </h6>
          </div>
          <div class="ce-card-body">
            <form method="POST" action="<?= site_url('/clinic/encounters/' . (int) $enc['id'] . '/addendum') ?>"
                  data-confirm="Append this signed update to the closed encounter? Addenda cannot be deleted once recorded."
                  data-confirm-title="Record post-visit addendum"
                  data-confirm-label="Append Addendum"
                  data-confirm-tone="primary">
              <?= csrf_field() ?>

              <div class="mb-3">
                <label for="addendum_type" class="form-label">Update Type</label>
                <select name="addendum_type" id="addendum_type" class="form-select form-select-sm" x-model="kind" required>
                  <option value="lab_followup">Delayed Lab / Diagnostic Result</option>
                  <option value="clinical_note">Signed Clinical Addendum</option>
                  <option value="time_correction">Visit Time / Log Clarification</option>
                </select>
              </div>

              <div x-show="kind === 'lab_followup'">
                <div class="row g-2 mb-3">
                  <div class="col-sm-7">
                    <label for="addendum_test_name" class="form-label">Diagnostic / Lab Test Name</label>
                    <input type="text" name="test_name" id="addendum_test_name" class="form-control form-control-sm" placeholder="e.g. CBC, Chest X-ray, Urinalysis" maxlength="120" :required="kind === 'lab_followup'">
                  </div>
                  <div class="col-sm-5">
                    <label for="addendum_result_status" class="form-label">Result Classification</label>
                    <select name="result_status" id="addendum_result_status" class="form-select form-select-sm">
                      <option value="normal">Normal</option>
                      <option value="abnormal">Abnormal / Follow-up</option>
                      <option value="inconclusive">Inconclusive / Repeat</option>
                      <option value="pending">Pending Result</option>
                    </select>
                  </div>
                </div>
                <div class="mb-3">
                  <label for="addendum_result_summary" class="form-label">Lab Summary / Values</label>
                  <input type="text" name="result_summary" id="addendum_result_summary" class="form-control form-control-sm" placeholder="e.g. WBC elevated; advised hydration &amp; rest" maxlength="255">
                </div>
              </div>

              <div class="mb-3">
                <label for="addendum_note" class="form-label">Clinical Addendum Note</label>
                <textarea name="note" id="addendum_note" rows="3" class="form-control form-control-sm" placeholder="Enter physician / nurse follow-up note..." maxlength="5000" required></textarea>
              </div>

              <button type="submit" class="btn btn-primary btn-sm ce-addendum-submit">
                <i class="bi bi-check2-square" aria-hidden="true"></i> Save Signed Addendum
              </button>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

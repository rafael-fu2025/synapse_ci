<div class="alert alert-secondary" role="status">This encounter is closed. Clinical records are read-only.</div>
<div class="card border-0 shadow-sm p-4">
  <h5>Vitals</h5>
  <dl class="row small">
    <?php foreach (['bp_systolic' => 'Systolic BP (mmHg)', 'bp_diastolic' => 'Diastolic BP (mmHg)', 'pulse_bpm' => 'Pulse (bpm)', 'temp_c' => 'Temperature (°C)', 'spo2_pct' => 'SpO2 (%)', 'weight_kg' => 'Weight (kg)', 'height_cm' => 'Height (cm)', 'bmi' => 'BMI'] as $field => $label): ?>
      <dt class="col-sm-4"><?= esc($label) ?></dt><dd class="col-sm-8"><?= esc($vitals[$field] ?? '—') ?></dd>
    <?php endforeach; ?>
  </dl>
  <h5>Assessment &amp; treatment</h5>
  <dl class="small">
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
  <dl class="small">
    <?php foreach (['status' => 'Status', 'disposition' => 'Disposition', 'disposition_notes' => 'Disposition notes', 'follow_up_date' => 'Follow-up date', 'completed_at' => 'Closed at'] as $field => $label): ?>
      <dt><?= esc($label) ?></dt><dd class="text-break"><?= nl2br(esc($enc[$field] ?? '—')) ?></dd>
    <?php endforeach; ?>
  </dl>
</div>

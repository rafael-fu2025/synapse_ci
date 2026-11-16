<?php if (!$can['schedule'] && !$can['callSlips']): ?>
  <div class="alert alert-light border small mb-0">Appointment and call-slip histories require their respective Guidance service permissions.</div>
<?php endif; ?>
<?php if ($can['schedule']): ?>
<section class="mb-4" aria-labelledby="guidance-client-scheduled-title">
  <h4 class="h6 fw-bold" id="guidance-client-scheduled-title">Shared scheduling appointments <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['sharedAppointments']) ?></span></h4>
  <p class="small text-muted">Guidance calendar bookings are listed separately from controlled appointment slips. They are not added together as visits.</p>
  <?php if ($sharedAppointments === []): ?>
    <div class="border rounded p-3 text-muted small">No Guidance calendar appointments are recorded.</div>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle"><caption class="visually-hidden">Guidance calendar appointments</caption>
      <thead><tr><th scope="col">Scheduled (Manila)</th><th scope="col">Practitioner</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Schedule</th></tr></thead>
      <tbody><?php foreach ($sharedAppointments as $appointment): ?>
        <tr><td><?= esc($formatDate($appointment['starts_at'])) ?></td><td><?= esc($staffName($appointment['provider_user_id'])) ?></td>
          <td><?= esc($displayStatus($appointment['type'])) ?></td><td><span class="badge bg-body-secondary text-body border"><?= esc($displayStatus($appointment['status'])) ?></span></td>
          <td><div class="table-card-actions"><a href="<?= esc(site_url('/guidance/appointments')) ?>" class="btn btn-outline-primary btn-sm">Guidance Schedule</a></div></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
  <?= $historyPager('sharedAppointments') ?>
</section>
<section class="mb-4" aria-labelledby="guidance-client-appointment-slips-title">
  <h4 class="h6 fw-bold" id="guidance-client-appointment-slips-title">Controlled appointment slips <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['guidanceAppointments']) ?></span></h4>
  <?php if ($guidanceAppointments === []): ?>
    <div class="border rounded p-3 text-muted small">No controlled appointment slips are available to you.</div>
  <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($guidanceAppointments as $appointment): ?>
        <div class="list-group-item px-0 py-3 d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-3">
          <div><div class="fw-semibold"><?= esc((string) $appointment['appointment_number']) ?></div>
            <div class="small text-muted"><?= esc($formatDate($appointment['scheduled_date'], false)) ?> · <?= esc(substr((string) $appointment['scheduled_time'], 0, 5)) ?> Manila · <?= esc($displayStatus($appointment['status'])) ?></div>
            <div class="small text-muted"><?= esc($staffName($appointment['counselor_user_id'])) ?></div>
          </div>
          <?php if ($can['documents']): ?><a href="<?= esc(site_url('/guidance/appointments/' . (int) $appointment['id'] . '/pdf?layout=single&mode=preview')) ?>" class="btn btn-outline-primary btn-sm flex-shrink-0" target="_blank" rel="noopener">Slip Preview</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('guidanceAppointments') ?>
</section>
<?php endif; ?>
<?php if ($can['callSlips']): ?>
<section aria-labelledby="guidance-client-callslips-title">
  <h4 class="h6 fw-bold" id="guidance-client-callslips-title">Call slips &amp; attendance <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['callSlips']) ?></span></h4>
  <?php if ($callSlips === []): ?>
    <div class="border rounded p-3 text-muted small">No call slips are available to you.</div>
  <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($callSlips as $slip): ?>
        <div class="list-group-item px-0 py-3 d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-3">
          <div><div class="fw-semibold"><?= esc((string) $slip['call_slip_number']) ?> <span class="badge bg-body-secondary text-body border ms-1"><?= esc($displayStatus($slip['attendance_status'])) ?></span></div>
            <div class="small text-muted"><?= esc($formatDate($slip['call_date'], false)) ?> · <?= esc(substr((string) $slip['call_time'], 0, 5)) ?> Manila · <?= esc($staffName($slip['issuing_counselor_user_id'])) ?></div>
          </div>
          <?php if ($can['documents']): ?><a href="<?= esc(site_url('/guidance/call-slips/' . (int) $slip['id'] . '/pdf?layout=single&mode=preview')) ?>" class="btn btn-outline-primary btn-sm flex-shrink-0" target="_blank" rel="noopener">Slip Preview</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('callSlips') ?>
</section>
<?php endif; ?>

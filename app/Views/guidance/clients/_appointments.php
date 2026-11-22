<?php if (!$can['schedule'] && !$can['callSlips']): ?>
  <div class="cp-notice" role="note">Appointment and call-slip histories require their respective Guidance service permissions.</div>
<?php endif; ?>

<?php if ($can['schedule']): ?>
<section class="cp-record-section" aria-labelledby="guidance-client-scheduled-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-scheduled-title">
      Shared scheduling appointments
      <span class="cp-card-count"><?= number_format((int) $totals['sharedAppointments']) ?></span>
    </h4>
  </div>
  <p class="cp-record-section-note">Guidance calendar bookings are listed separately from controlled appointment slips. They are not added together as visits.</p>
  <?php if ($sharedAppointments === []): ?>
    <div class="cp-empty-pane">No Guidance calendar appointments are recorded.</div>
  <?php else: ?>
    <div class="cp-table-card">
      <div class="cp-table-wrap">
        <table class="table align-middle mb-0 cp-table" data-mobile-cards="false">
          <caption class="visually-hidden">Guidance calendar appointments</caption>
          <thead>
            <tr>
              <th scope="col">Scheduled (Manila)</th>
              <th scope="col">Practitioner</th>
              <th scope="col">Type</th>
              <th scope="col">Status</th>
              <th scope="col" class="text-end">Schedule</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sharedAppointments as $appointment): ?>
              <?php
                $apStatus = (string) ($appointment['status'] ?? '');
                $apTone = match ($apStatus) {
                    'confirmed', 'completed' => 'cp-status-inline--active',
                    'scheduled', 'checked_in' => 'cp-status-inline--sky',
                    default => 'cp-status-inline--muted',
                };
              ?>
              <tr class="cp-row">
                <td class="cp-cell-header" data-label="Scheduled (Manila)">
                  <span class="cp-person-name cp-mono"><?= esc($formatDate($appointment['starts_at'])) ?></span>
                </td>
                <td data-label="Practitioner"><?= esc($staffName($appointment['provider_user_id'])) ?></td>
                <td data-label="Type"><?= esc($displayStatus($appointment['type'])) ?></td>
                <td data-label="Status">
                  <span class="cp-status-inline <?= esc($apTone) ?>"><span class="cp-dot" aria-hidden="true"></span><?= esc($displayStatus($apStatus)) ?></span>
                </td>
                <td class="text-end cp-cell-actions">
                  <div class="table-card-actions cp-row-actions">
                    <a href="<?= esc(site_url('/guidance/appointments')) ?>" class="btn btn-outline-primary btn-sm">Guidance Schedule</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
  <?= $historyPager('sharedAppointments') ?>
</section>

<section class="cp-record-section" aria-labelledby="guidance-client-appointment-slips-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-appointment-slips-title">
      Controlled appointment slips
      <span class="cp-card-count"><?= number_format((int) $totals['guidanceAppointments']) ?></span>
    </h4>
  </div>
  <?php if ($guidanceAppointments === []): ?>
    <div class="cp-empty-pane">No controlled appointment slips are available to you.</div>
  <?php else: ?>
    <div class="cp-visit-list">
      <?php foreach ($guidanceAppointments as $appointment): ?>
        <article class="cp-visit-card">
          <div class="cp-visit-head">
            <div class="cp-visit-title-col">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <span class="cp-id-code"><?= esc((string) $appointment['appointment_number']) ?></span>
                <span class="cp-tag"><?= esc($displayStatus($appointment['status'])) ?></span>
              </div>
              <span class="cp-visit-meta">
                <span class="cp-mono"><?= esc($formatDate($appointment['scheduled_date'], false)) ?> · <?= esc(substr((string) $appointment['scheduled_time'], 0, 5)) ?> Manila</span> · <?= esc($staffName($appointment['counselor_user_id'])) ?>
              </span>
            </div>
            <?php if ($can['documents']): ?>
              <div class="cp-visit-actions">
                <a href="<?= esc(site_url('/guidance/appointments/' . (int) $appointment['id'] . '/pdf?layout=single&mode=preview')) ?>" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener">Slip Preview</a>
              </div>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('guidanceAppointments') ?>
</section>
<?php endif; ?>

<?php if ($can['callSlips']): ?>
<section class="cp-record-section" aria-labelledby="guidance-client-callslips-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-callslips-title">
      Call slips &amp; attendance
      <span class="cp-card-count"><?= number_format((int) $totals['callSlips']) ?></span>
    </h4>
  </div>
  <?php if ($callSlips === []): ?>
    <div class="cp-empty-pane">No call slips are available to you.</div>
  <?php else: ?>
    <div class="cp-visit-list">
      <?php foreach ($callSlips as $slip): ?>
        <article class="cp-visit-card">
          <div class="cp-visit-head">
            <div class="cp-visit-title-col">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <span class="cp-id-code"><?= esc((string) $slip['call_slip_number']) ?></span>
                <span class="cp-tag"><?= esc($displayStatus($slip['attendance_status'])) ?></span>
              </div>
              <span class="cp-visit-meta">
                <span class="cp-mono"><?= esc($formatDate($slip['call_date'], false)) ?> · <?= esc(substr((string) $slip['call_time'], 0, 5)) ?> Manila</span> · <?= esc($staffName($slip['issuing_counselor_user_id'])) ?>
              </span>
            </div>
            <?php if ($can['documents']): ?>
              <div class="cp-visit-actions">
                <a href="<?= esc(site_url('/guidance/call-slips/' . (int) $slip['id'] . '/pdf?layout=single&mode=preview')) ?>" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener">Slip Preview</a>
              </div>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('callSlips') ?>
</section>
<?php endif; ?>

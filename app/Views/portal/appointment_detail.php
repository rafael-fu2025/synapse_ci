<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
$status = (string) $appointment['status'];
$unit = (string) $appointment['unit'];
$statusLabel = match ($status) {
    'requested' => 'Awaiting confirmation',
    'scheduled' => 'Scheduled',
    'confirmed' => 'Confirmed',
    'checked_in' => 'In Queue',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'no_show' => 'No show',
    default => ucwords(str_replace('_', ' ', $status)),
};
$statusTone = match ($status) {
    'requested' => 'is-requested',
    'scheduled', 'confirmed' => 'is-confirmed',
    'checked_in' => 'is-queue',
    'completed' => 'is-completed',
    default => 'is-inactive',
};
$reason = trim((string) ($appointment['reason'] ?? ''));
$cancellationReason = trim((string) ($appointment['cancellation_reason'] ?? ''));
$formatDateTime = static function (?string $value, \DateTimeZone $storedTimezone): string {
    if (empty($value)) {
        return '';
    }
    $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $storedTimezone);
    if ($dateTime === false || $dateTime->format('Y-m-d H:i:s') !== $value) {
        return '';
    }
    return $dateTime->setTimezone(\App\Services\Scheduling\SchedulingClock::timezone())->format('M j, Y · g:i A');
};
$startsAt = strtotime((string) $appointment['starts_at']);
$endsAt = empty($appointment['ends_at']) ? false : strtotime((string) $appointment['ends_at']);
$timeLabel = $startsAt === false ? 'Time unavailable' : date('g:i A', $startsAt);
if ($status !== 'requested' && $endsAt !== false) {
    $timeLabel .= ' – ' . date('g:i A', $endsAt);
}
$createdAt = $formatDateTime($appointment['created_at'] ?? null, new \DateTimeZone('UTC'));
$confirmedAt = $formatDateTime($appointment['confirmed_at'] ?? null, \App\Services\Scheduling\SchedulingClock::timezone());
$unitName = $unit === 'clinic' ? 'University Clinic' : 'Guidance & Counseling';
?>

<div class="pa-detail">
  <div class="pa-detail-top">
    <span class="pa-status <?= esc($statusTone) ?>"><?= esc($statusLabel) ?></span>
    <span class="pa-detail-ref">Reference #<?= (int) $appointment['id'] ?></span>
  </div>

  <div class="pa-detail-schedule">
    <div class="pa-detail-unit">
      <?= view('partials/unit_vector_icon', ['unit' => $unit === 'clinic' ? 'clinic' : 'guidance', 'class' => 'pa-unit-icon']) ?>
      <span><?= esc($unitName) ?></span>
    </div>
    <div class="pa-detail-date">
      <?= $startsAt === false ? 'Date unavailable' : esc(date('l, F j, Y', $startsAt)) ?>
    </div>
    <div class="pa-detail-time">
      <?= esc($timeLabel) ?>
      <?php if ($status === 'requested'): ?>
        <span class="pa-detail-hint">Preferred time—not yet confirmed</span>
      <?php elseif ($status === 'checked_in'): ?>
        <span class="pa-detail-hint">Checked in—please wait for your turn</span>
      <?php endif; ?>
    </div>
  </div>

  <dl class="row mb-0 pa-detail-dl">
    <dt class="col-sm-4">Service</dt>
    <dd class="col-sm-8"><?= esc($unitName) ?></dd>

    <dt class="col-sm-4">Type</dt>
    <dd class="col-sm-8"><?= esc(ucwords(str_replace('_', ' ', (string) $appointment['type']))) ?></dd>

    <dt class="col-sm-4">Assigned staff</dt>
    <dd class="col-sm-8"><?= esc(!empty($appointment['provider_name']) ? $appointment['provider_name'] : 'Not yet assigned') ?></dd>

    <dt class="col-sm-4">Status</dt>
    <dd class="col-sm-8"><?= esc($statusLabel) ?></dd>

    <dt class="col-sm-4">Appointment reason</dt>
    <dd class="col-sm-8 text-break"><?= nl2br(esc($reason !== '' ? $reason : 'Not provided')) ?></dd>

    <?php if ($status === 'cancelled'): ?>
      <dt class="col-sm-4">Cancellation reason</dt>
      <dd class="col-sm-8 text-break"><?= nl2br(esc($cancellationReason !== '' ? $cancellationReason : 'Not recorded')) ?></dd>
    <?php endif; ?>

    <?php if ($createdAt !== ''): ?>
      <dt class="col-sm-4">Requested on</dt>
      <dd class="col-sm-8"><?= esc($createdAt) ?></dd>
    <?php endif; ?>

    <?php if ($confirmedAt !== ''): ?>
      <dt class="col-sm-4">Confirmed on</dt>
      <dd class="col-sm-8"><?= esc($confirmedAt) ?></dd>
    <?php endif; ?>
  </dl>

  <?php if (in_array($status, ['requested', 'scheduled', 'confirmed'], true)): ?>
    <p class="pa-detail-note">You can cancel this appointment below or from My Health Portal.</p>
  <?php elseif ($status === 'completed'): ?>
    <p class="pa-detail-note">This visit is complete. Contact the department if you need a follow-up appointment.</p>
  <?php elseif (in_array($status, ['cancelled', 'no_show'], true)): ?>
    <p class="pa-detail-note">This appointment is no longer active. You can request a new appointment from My Health Portal.</p>
  <?php endif; ?>

  <div class="pa-detail-actions">
    <?php if (in_array($status, ['requested', 'scheduled', 'confirmed'], true)): ?>
      <form action="<?= site_url('/portal/appointments/' . $unit . '/cancel/' . (int) $appointment['id']) ?>" method="POST" class="d-inline" data-background-submit
            data-confirm-reason
            data-confirm="Cancel this appointment or pending request? Any reserved slot will be released."
            data-confirm-title="Cancel appointment"
            data-confirm-label="Cancel appointment"
            data-confirm-tone="danger">
        <?= csrf_field() ?>
        <input type="hidden" name="reason" value="">
        <button type="submit" class="btn btn-outline-danger btn-sm">Cancel appointment</button>
      </form>
    <?php endif; ?>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-app-modal-close>Close</button>
  </div>
</div>
<?= $this->endSection() ?>

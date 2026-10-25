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
$statusBadge = match ($status) {
    'requested' => 'bg-warning text-dark',
    'scheduled', 'confirmed' => 'bg-primary',
    'checked_in' => 'bg-info text-dark',
    'completed' => 'bg-success',
    default => 'bg-secondary',
};
$reason = trim((string) ($appointment['reason'] ?? ''));
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
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <span class="badge <?= esc($statusBadge) ?>"><?= esc($statusLabel) ?></span>
  <span class="text-muted small">Reference #<?= (int) $appointment['id'] ?></span>
</div>

<div class="border rounded p-3 mb-4">
  <div class="fw-bold mb-1"><?= esc($unit === 'clinic' ? 'University Clinic' : 'Guidance & Counseling') ?></div>
  <div class="fs-5 fw-semibold">
    <?= $startsAt === false ? 'Date unavailable' : esc(date('l, F j, Y', $startsAt)) ?>
  </div>
  <div class="text-muted">
    <?= esc($timeLabel) ?>
    <?php if ($status === 'requested'): ?>
      <span class="d-block small">Preferred time—not yet confirmed</span>
    <?php elseif ($status === 'checked_in'): ?>
      <span class="d-block small">Checked in—please wait for your turn</span>
    <?php endif; ?>
  </div>
</div>

<dl class="row mb-0">
  <dt class="col-sm-4">Service</dt>
  <dd class="col-sm-8"><?= esc($unit === 'clinic' ? 'University Clinic' : 'Guidance & Counseling') ?></dd>

  <dt class="col-sm-4">Type</dt>
  <dd class="col-sm-8"><?= esc(ucwords(str_replace('_', ' ', (string) $appointment['type']))) ?></dd>

  <dt class="col-sm-4">Assigned staff</dt>
  <dd class="col-sm-8"><?= esc(!empty($appointment['provider_name']) ? $appointment['provider_name'] : 'Not yet assigned') ?></dd>

  <dt class="col-sm-4">Status</dt>
  <dd class="col-sm-8"><?= esc($statusLabel) ?></dd>

  <dt class="col-sm-4">Reason provided</dt>
  <dd class="col-sm-8"><?= esc($reason !== '' ? $reason : 'Not provided') ?></dd>

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
  <p class="small text-muted mt-4 mb-0">To change or cancel this appointment, use the Cancel button on My Health Portal.</p>
<?php elseif ($status === 'completed'): ?>
  <p class="small text-muted mt-4 mb-0">This visit is complete. Contact the department if you need a follow-up appointment.</p>
<?php elseif (in_array($status, ['cancelled', 'no_show'], true)): ?>
  <p class="small text-muted mt-4 mb-0">This appointment is no longer active. You can request a new appointment from My Health Portal.</p>
<?php endif; ?>

<div class="d-flex justify-content-end mt-4">
  <button type="button" class="btn btn-outline-secondary" data-app-modal-close>Close</button>
</div>
<?= $this->endSection() ?>

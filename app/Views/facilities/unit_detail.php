<?= $this->extend('layouts/app') ?>
<?php
$id = (int) $unit['id'];
$base = '/facilities/unit/' . $id;
$manage = !empty($canManage);
$configure = !empty($canConfigure);
$hardwareAllowed = $configure && !empty($canHardware) && !empty($hardwareEnabled) && !empty($hardware_enabled);
$canCommand = $hardwareAllowed && $unit['status'] === 'active' && $device !== null;
$activeBatch = $activeBatch ?? null;
$pastBatches = $pastBatches ?? [];
?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/bmg.css')) ?>">
<?= $this->endSection() ?>
<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <a href="<?= esc(site_url('/facilities')) ?>" class="btn btn-outline-secondary btn-sm">All drums</a>
  <?php if ($configure): ?>
  <a href="<?= esc(site_url('/facilities') . '?create=1', 'attr') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add drum</a>
  <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#unitModal"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit drum</button>
  <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#archiveUnitModal"><i class="bi bi-archive me-1" aria-hidden="true"></i>Archive drum</button>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="bmg-workspace" data-bmg-base-url="<?= esc(site_url('/facilities'), 'attr') ?>">
<header class="card mb-3"><div class="card-body pt-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
  <div class="d-flex align-items-center gap-3">
    <?php $gearSeed = $id; require __DIR__ . '/_gear.php'; ?>
    <div><div class="small text-muted"><?= esc($unit['unit_code']) ?> · Drum operations</div><h2 class="card-title py-2 mb-0"><?= esc($unit['name']) ?></h2><p class="small text-muted mb-0">Plan rotation, request commands and manage batch records.</p></div>
  </div>
  <span class="badge bg-light text-dark border"><?= esc(ucfirst($unit['status'])) ?></span>
</div></header>
<div class="row g-3 mb-3" aria-label="Drum at a glance">
  <div class="col-sm-6 col-xl-3"><div class="card h-100 mb-0"><div class="card-body pt-3"><div class="small text-muted">Capacity</div><div class="fw-semibold"><?= esc($unit['drum_capacity_kg']) ?> kg</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card h-100 mb-0"><div class="card-body pt-3"><div class="small text-muted">Current batch</div><div class="fw-semibold text-break"><?= $activeBatch === null ? 'No active batch' : esc($activeBatch['batch_code']) ?></div><div class="small text-muted"><?= $activeBatch === null ? 'No batch record in progress' : esc($activeBatch['total_input_weight_kg']) . ' kg input · ' . esc($activeBatch['status']) ?></div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card h-100 mb-0"><div class="card-body pt-3"><div class="small text-muted">Saved schedule intent</div><div class="fw-semibold"><?= !empty($schedule['enabled']) ? 'Enabled' : 'Disabled' ?></div><div class="small text-muted"><?= count($schedule['times']) ?> daily times · <?= (int) $schedule['duration_seconds'] ?> s per run</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card h-100 mb-0"><div class="card-body pt-3"><div class="small text-muted">Device binding</div><div class="fw-semibold"><?= $device === null ? 'Not registered' : 'Registered' ?></div><div class="small text-muted">Binding does not establish motor state</div></div></div></div>
</div>
<nav class="nav nav-pills gap-2 mb-3" aria-label="Drum sections">
  <a class="nav-link" href="#bmg-setup">Setup checklist</a><a class="nav-link" href="#bmg-schedule">Rotation schedule</a><a class="nav-link" href="#bmg-controls">Manual controls</a><a class="nav-link" href="#bmg-batch">Current batch</a><a class="nav-link" href="#bmg-device">Device &amp; setup</a><a class="nav-link" href="#bmg-history">History</a>
</nav>
<div class="alert alert-warning mb-3" role="note"><strong>Web stop is not an emergency stop.</strong> Use the local physical emergency stop for immediate safety. Device-reported motor events are not verified drum rotation. Connectivity does not establish motor state.</div>
<?php if (!$hardwareAllowed): ?><div class="alert alert-secondary mb-3" role="note"><?= empty($hardwareEnabled) || empty($hardware_enabled) ? 'Hardware control is disabled by the server gate.' : 'Configuration access and explicit hardware-control permission are required.' ?> Run, stop and schedule editing/enabling are unavailable. Configuration admins may disable existing schedule intent without changing its times or duration. Configuration admins can still register a device without enabling motor operation. Existing schedule intent may still be reviewed.</div><?php elseif (!$canCommand): ?><div class="alert alert-info mb-3" role="note">Motor commands require an active drum and one active bound device.</div><?php endif; ?>
<?php require __DIR__ . '/_setup_steps.php'; ?>
<div class="bmg-detail-grid">
  <div class="bmg-main">
    <section class="card bmg-panel mb-4" id="bmg-schedule" aria-labelledby="bmg-schedule-title">
      <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between gap-2 bmg-panel-heading"><h2 class="card-title mb-0 p-0" id="bmg-schedule-title"><i class="bi bi-calendar-week" aria-hidden="true"></i> Daily rotation schedule</h2><span class="badge bg-light text-dark border">UTC+08:00</span></div>
      <div class="card-body pt-3 bmg-panel-body">
      <p class="text-muted small">Asia/Manila (UTC+08:00). Up to eight distinct daily times; runs must not overlap, including across midnight. Saving replaces the schedule and cancels pending commands.</p>
      <p class="small">Saved version: <strong><?= (int) $schedule['version'] ?></strong> · Saved intent: <strong><?= !empty($schedule['enabled']) ? 'Enabled' : 'Disabled' ?></strong></p>
      <?php if ($hardwareAllowed): ?>
      <form action="<?= esc(site_url($base . '/schedule')) ?>" method="POST" data-bmg-submit data-bmg-schedule data-confirm="Replace this daily schedule and cancel pending commands? Saved intent does not prove the device has applied it." data-confirm-title="Save rotation schedule" data-confirm-label="Save schedule" data-confirm-tone="warning">
        <?= csrf_field() ?>
        <input type="hidden" name="expected_version" value="<?= (int) ($schedule['version'] ?? 0) ?>">
        <fieldset class="mb-3"><legend class="fs-6">Daily start times</legend>
          <div data-bmg-times>
            <?php foreach ($schedule['times'] as $i => $time): ?>
            <div class="d-flex align-items-end gap-2 mb-2" data-bmg-time-row><div class="flex-grow-1"><label class="form-label" for="rotationTime<?= (int) $i ?>">Start time</label><?= view('partials/schedule_time', ['id' => 'rotationTime' . (int) $i, 'name' => 'times[]', 'label' => 'Start time', 'value' => $time]) ?></div><button class="btn btn-outline-secondary" type="button" data-bmg-remove-time aria-label="Remove start time">Remove</button></div>
            <?php endforeach; ?>
          </div>
          <button class="btn btn-outline-secondary btn-sm" type="button" data-bmg-add-time>Add time</button>
          <p class="form-text mb-0" data-bmg-time-count aria-live="polite"><?= count($schedule['times']) ?> of 8 times</p>
        </fieldset>
        <div class="mb-3"><label class="form-label" for="scheduleDuration">Run duration (seconds)</label><input id="scheduleDuration" class="form-control" type="number" name="duration_seconds" min="1" max="600" step="1" value="<?= (int) $schedule['duration_seconds'] ?>" required></div>
        <div class="mb-3"><label class="form-label" for="schedulePwm">PWM (optional, 1–255)</label><input id="schedulePwm" class="form-control" type="number" name="pwm" min="1" max="255" step="1" value="<?= isset($schedule['pwm']) ? (int) $schedule['pwm'] : '' ?>"></div>
        <div class="form-check mb-3"><input id="scheduleEnabled" class="form-check-input" name="enabled" type="checkbox" value="1" <?= $hardwareAllowed && $device !== null && $unit['status'] === 'active' ? '' : 'disabled' ?> <?= !empty($schedule['enabled']) ? 'checked' : '' ?> aria-describedby="scheduleEnableHelp"><label class="form-check-label" for="scheduleEnabled">Enable daily rotation</label></div>
        <p id="scheduleEnableHelp" class="form-text">Enabling requires hardware permission, an open hardware gate, an active drum and a bound device. If enabling is unavailable, saving records disabled intent; it does not stop the motor.</p>
        <div class="alert alert-danger d-none small" data-bmg-schedule-error role="alert"></div>
        <button class="btn btn-primary" type="submit">Save schedule</button>
      </form>
      <?php else: ?>
      <p><?= $schedule['times'] === [] ? 'No daily times saved.' : esc(implode(', ', $schedule['times'])) ?> · <?= (int) $schedule['duration_seconds'] ?> seconds per run · PWM: <?= isset($schedule['pwm']) ? (int) $schedule['pwm'] : 'Not set' ?></p>
            <p class="text-muted small">Editing times, duration, PWM or enabling requires configuration and hardware-control permission with the server hardware gate enabled.</p>
            <?php if ($configure && !empty($schedule['enabled'])): ?>
            <form action="<?= esc(site_url($base . '/schedule')) ?>" method="POST" data-bmg-submit data-confirm="Disable saved schedule intent and cancel pending commands? This does not confirm a motor stop." data-confirm-title="Disable schedule intent" data-confirm-label="Disable intent" data-confirm-tone="warning">
              <?= csrf_field() ?>
              <input type="hidden" name="expected_version" value="<?= (int) $schedule['version'] ?>">
              <input type="hidden" name="enabled" value="0">
              <?php foreach ($schedule['times'] as $time): ?><input type="hidden" name="times[]" value="<?= esc($time, 'attr') ?>"><?php endforeach; ?>
              <input type="hidden" name="duration_seconds" value="<?= (int) $schedule['duration_seconds'] ?>">
              <button class="btn btn-outline-warning" type="submit">Disable saved schedule intent</button>
              <p class="form-text mb-0">Times and duration are retained; PWM is cleared. This is not a physical stop.</p>
            </form>
            <?php endif; ?>
      <?php endif; ?>
    </div></section>
    <section class="card bmg-panel mb-4" id="bmg-controls" aria-labelledby="bmg-controls-title">
      <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between gap-2 bmg-panel-heading"><h2 class="card-title mb-0 p-0" id="bmg-controls-title"><i class="bi bi-sliders" aria-hidden="true"></i> Manual commands</h2></div>
      <div class="card-body pt-3 bmg-panel-body"><p class="small text-muted">Requests are queued for the device. Delivery or acknowledgement is not proof of physical rotation or a safe stop.</p>
      <div class="bmg-command-grid">
      <div class="bmg-command-box border rounded p-3"><h3 class="h6">Timed run request</h3>
      <form action="<?= esc(site_url($base . '/command')) ?>" method="POST" data-bmg-submit class="mb-3" data-confirm="Queue a run request for this drum? Confirm that the equipment and surrounding area are safe before continuing." data-confirm-title="Request rotation" data-confirm-label="Queue run" data-confirm-tone="warning">
        <?= csrf_field() ?><input type="hidden" name="expected_version" value="<?= (int) ($schedule['version'] ?? 0) ?>"><input type="hidden" name="command" value="run_now">
        <div class="mb-2"><label for="manualDuration" class="form-label">Run duration (seconds)</label><input id="manualDuration" type="number" name="duration_seconds" class="form-control" min="1" max="600" step="1" value="60" required <?= $canCommand ? '' : 'disabled' ?>></div>
        <button class="btn btn-primary" type="submit" <?= $canCommand ? '' : 'disabled' ?>>Request run now</button>
      </form>
      </div>
      <div class="bmg-command-box border rounded p-3"><h3 class="h6">Web stop request</h3><p class="small text-muted">Delivery is not guaranteed. For immediate safety, use the local physical emergency stop.</p>
      <form action="<?= esc(site_url($base . '/command')) ?>" method="POST" data-bmg-submit data-confirm="Queue a web stop request? This is not an emergency stop and delivery is not guaranteed. Use the local physical emergency stop for immediate safety." data-confirm-title="Request web stop" data-confirm-label="Queue stop" data-confirm-tone="danger">
        <?= csrf_field() ?><input type="hidden" name="expected_version" value="<?= (int) ($schedule['version'] ?? 0) ?>"><input type="hidden" name="command" value="stop"><button class="btn btn-outline-danger" type="submit" <?= $canCommand ? '' : 'disabled' ?>>Request web stop</button>
      </form>
      </div></div>
    </div></section>
  </div>
  <aside class="bmg-aside" aria-label="Drum setup and batch">
    <section class="card bmg-panel mb-4" id="bmg-batch" aria-labelledby="bmg-batch-title">
      <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between gap-2 bmg-panel-heading"><h2 class="card-title mb-0 p-0" id="bmg-batch-title"><i class="bi bi-box-seam" aria-hidden="true"></i> Current batch</h2></div>
      <div class="card-body pt-3 bmg-panel-body">
      <?php if ($activeBatch === null): ?><p class="text-muted">No active batch. <?= $unit['status'] === 'active' ? 'This drum is available for a new batch record.' : 'Set the drum to active before starting a batch.' ?></p>
      <?php if ($manage && $unit['status'] === 'active'): ?><button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#startBatchModal">Start batch</button><?php endif; ?>
      <?php else: ?>
      <h3 class="h6 text-break"><?= esc($activeBatch['batch_code']) ?></h3><dl class="bmg-fact-grid small"><div class="bmg-fact"><dt>Status</dt><dd><?= esc($activeBatch['status']) ?></dd></div><div class="bmg-fact"><dt>Started</dt><dd><?= esc($activeBatch['started_at']) ?></dd></div><div class="bmg-fact"><dt>Input weight</dt><dd><?= esc($activeBatch['total_input_weight_kg']) ?> kg</dd></div></dl><p class="small text-muted text-break"><strong>Creation notes:</strong> <?= nl2br(esc($activeBatch['notes'] ?? 'Not recorded')) ?></p>
      <?php if ($manage): ?><div class="d-flex flex-wrap gap-2"><button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#completeBatchModal">Complete batch</button><button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#cancelBatchModal">Cancel batch</button></div><?php endif; endif; ?>
    </div></section>
    <section class="card bmg-panel mb-4" id="bmg-device" aria-labelledby="bmg-device-title">
      <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between gap-2 bmg-panel-heading"><h2 class="card-title mb-0 p-0" id="bmg-device-title"><i class="bi bi-cpu" aria-hidden="true"></i> Device registration</h2></div>
      <div class="card-body pt-3 bmg-panel-body">
      <?php if (!empty($canUsb)): ?><a class="btn btn-outline-primary btn-sm mb-3" href="<?= esc(site_url($base . '/usb'), 'attr') ?>">USB installer &amp; serial monitor</a><?php endif; ?>
      <p id="deviceGateHelp" class="text-muted small">Configuration admins can register a device or rotate its credential while motor control is disabled. Registration disables the saved schedule and cancels pending commands; it does not start the motor. Keep the hardware gate off until firmware and safety verification is complete.</p>
      <?php if ($device === null): ?><p class="text-muted">No active device bound. Register a device before enabling rotation.</p><?php else: ?>
      <dl class="bmg-fact-grid small">
        <div class="bmg-fact"><dt>Chip MAC</dt><dd class="text-break"><?= esc($device['chip_mac']) ?></dd></div>
        <div class="bmg-fact"><dt>Last contact</dt><dd><?= esc($device['last_seen_at'] ?? 'Not reported') ?></dd></div>
      </dl>
      <p class="text-muted small">Last contact means communication only, not motor state. Field timestamps below are server receipt times (Asia/Manila), not device sampling times. Contact without a field update does not refresh that report.</p>
      <dl class="row small" data-bmg-telemetry>
        <?php
        $reports = [
            ['Firmware', $device['firmware_version'] ?? null, $device['firmware_reported_at'] ?? null],
            ['Device clock', !isset($device['rtc_valid']) ? null : ($device['rtc_valid'] ? 'Reported valid' : 'Reported invalid'), $device['rtc_reported_at'] ?? null],
            ['Applied schedule version', $device['applied_schedule_version'] ?? null, $device['schedule_reported_at'] ?? null],
        ];
        ?>
        <?php foreach ($reports as [$reportLabel, $reportValue, $reportedAt]): ?>
        <dt class="col-5"><?= esc($reportLabel) ?></dt>
        <dd class="col-7 text-break">
          <?php if ($reportValue === null || empty($reportedAt)): ?>
          <span>Unknown — no timestamped report</span>
          <?php else: ?>
          <span><?= esc((string) $reportValue) ?></span><br>
          <span class="text-muted">Last received: <?= esc($reportedAt) ?></span><br>
          <span class="text-muted"><?= !empty($device['last_seen_at']) && $reportedAt < $device['last_seen_at'] ? 'Not updated at last contact; may be stale.' : 'Historical report; freshness not verified.' ?></span>
          <?php endif; ?>
        </dd>
        <?php endforeach; ?>
      </dl>
      <?php endif; ?>
      <?php if ($configure): ?>
      <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#deviceModal" aria-describedby="deviceGateHelp"><?= $device === null ? 'Register device' : 'Rotate device credential' ?></button>
      <?php if ($device !== null): ?>
      <form class="mt-3" action="<?= esc(site_url('/facilities/device/' . (int) $device['id'] . '/revoke')) ?>" method="POST" data-bmg-submit data-confirm="Revoke this device credential? Device access will be removed, the schedule disabled and pending commands cancelled. This is not a physical emergency stop." data-confirm-title="Revoke device" data-confirm-label="Revoke credential" data-confirm-tone="danger"><?= csrf_field() ?><input type="hidden" name="expected_version" value="<?= (int) ($schedule['version'] ?? 0) ?>"><button type="submit" class="btn btn-outline-danger btn-sm">Revoke device</button></form>
      <?php endif; endif; ?>
    </div></section>

  </aside>
</div>
<?php require __DIR__ . '/_history.php'; ?>
</div>
<?php if ($configure): ?>
<?php $editUnit = $unit; require __DIR__ . '/_unit_modal.php'; ?>
<?php require __DIR__ . '/_archive_modal.php'; ?>
<?php endif; ?>
<?php if ($configure || $manage): require __DIR__ . '/_operation_modals.php'; endif; ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= esc(base_url('assets/js/bmg.js')) ?>"></script><script src="<?= esc(base_url('assets/js/bmg-gear.js')) ?>"></script><?= $this->endSection() ?>

<?= $this->extend('layouts/app') ?>
<?php
$id = (int) $unit['id'];
$base = '/facilities/unit/' . $id;
$manage = !empty($canManage);
$cancelBatch = array_key_exists('canCancelBatch', get_defined_vars()) ? !empty($canCancelBatch) : $manage;
$configure = !empty($canConfigure);
$gateOpen = !empty($hardwareEnabled) && !empty($hardware_enabled);
$hardwareAllowed = $configure && !empty($canHardware) && $gateOpen;
$commandHardwareAllowed = $manage && !empty($canHardware) && $gateOpen;
$canCommand = $hardwareAllowed && $unit['status'] === 'active' && $device !== null;
$canRunNow = $commandHardwareAllowed && $unit['status'] === 'active' && $device !== null;
$canStop = $commandHardwareAllowed && $device !== null;
$activeBatch = $activeBatch ?? null;
$pastBatches = $pastBatches ?? [];
$commandsPagination ??= ['page' => 1, 'per_page' => 20, 'total' => count($commands ?? [])];
$runsPagination ??= ['page' => 1, 'per_page' => 20, 'total' => count($runs ?? [])];
$batchesPagination ??= ['page' => 1, 'per_page' => 50, 'total' => count($pastBatches)];
$historyQuery = [
    'commands_page' => $commandsPagination['page'], 'commands_per_page' => $commandsPagination['per_page'],
    'runs_page' => $runsPagination['page'], 'runs_per_page' => $runsPagination['per_page'],
    'batches_page' => $batchesPagination['page'], 'batches_per_page' => $batchesPagination['per_page'],
];
$historyUrl = site_url($base) . '?' . http_build_query($historyQuery, '', '&', PHP_QUERY_RFC3986);
$formatBatchTime = static function (?string $utc): string {
    if ($utc === null || $utc === '') {
        return 'Not recorded';
    }
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
    if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $utc) {
        return $utc;
    }
    return \App\Services\Scheduling\SchedulingClock::formatManila($utc, 'Y-m-d H:i:s');
};
?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/bmg.css')) ?>">
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/bmg-workstation.css'), 'attr') ?>">
<?= $this->endSection() ?>
<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <a href="<?= esc(site_url('/facilities')) ?>" class="btn btn-outline-secondary btn-sm">All drums</a>
  <?php if ($configure): ?>
  <a href="<?= esc(site_url('/facilities') . '?create=1', 'attr') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add drum</a>
  <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#unitModal"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit drum</button>
  <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#archiveUnitModal"><i class="bi bi-archive me-1" aria-hidden="true"></i>Archive drum</button>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<section class="bmg-workspace bmg-workstation ce-workspace" aria-label="Drum operations workstation" data-bmg-base-url="<?= esc(site_url('/facilities'), 'attr') ?>" data-refresh-owner="facilities.unit.<?= $id ?>" data-refresh-url="<?= esc($historyUrl, 'attr') ?>">
<div data-refresh-region="unit">
<header class="card ce-card bmg-unit-intro mb-3"><div class="card-body bmg-intro-body">
  <div class="bmg-unit-identity">
    <?php $gearSeed = $id; require __DIR__ . '/_gear.php'; ?>
    <div><span class="bmg-eyebrow"><span class="bmg-code"><?= esc($unit['unit_code']) ?></span> · Drum operations</span><h2 class="card-title"><?= esc($unit['name']) ?></h2><p class="bmg-caption mb-0">Plan rotation, request commands and manage batch records.</p></div>
  </div>
  <span class="ce-tag"><span class="ce-status-dot ce-status-<?= $unit['status'] === 'active' ? 'emerald' : ($unit['status'] === 'maintenance' ? 'amber' : 'slate') ?>"><?= esc(ucfirst($unit['status'])) ?></span></span>
</div></header>
<div class="row bmg-metrics" aria-label="Drum at a glance">
  <div class="col-sm-6 col-xl-3"><div class="card ce-card bmg-stat"><div class="card-body"><span class="bmg-stat-label"><i class="bi bi-speedometer2" aria-hidden="true"></i>Capacity</span><div class="bmg-stat-value bmg-number"><?= esc($unit['drum_capacity_kg']) ?> kg</div><span class="bmg-caption">Configured drum volume</span></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card ce-card bmg-stat"><div class="card-body"><span class="bmg-stat-label"><i class="bi bi-box-seam" aria-hidden="true"></i>Current batch</span><div class="bmg-stat-value text-break"><?= $activeBatch === null ? 'No active batch' : esc($activeBatch['batch_code']) ?></div><span class="bmg-caption"><?= $activeBatch === null ? 'No batch record in progress' : esc($activeBatch['total_input_weight_kg']) . ' kg input · ' . esc($activeBatch['status']) ?></span></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card ce-card bmg-stat"><div class="card-body"><span class="bmg-stat-label"><i class="bi bi-calendar-week" aria-hidden="true"></i>Saved schedule intent</span><div class="bmg-stat-value"><?= !empty($schedule['enabled']) ? 'Enabled' : 'Disabled' ?></div><span class="bmg-caption"><?= count($schedule['times']) ?> daily times · <?= (int) $schedule['rotations'] ?> rotations per run</span></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card ce-card bmg-stat"><div class="card-body"><span class="bmg-stat-label"><i class="bi bi-cpu" aria-hidden="true"></i>Device binding</span><div class="bmg-stat-value"><?= $device === null ? 'Not registered' : 'Registered' ?></div><span class="bmg-caption">Binding does not establish motor state</span></div></div></div>
</div>
<nav class="bmg-section-nav" aria-label="Drum sections">
  <a class="nav-link" href="#bmg-setup"><i class="bi bi-list-check" aria-hidden="true"></i>Setup checklist</a>
  <a class="nav-link" href="#bmg-schedule"><i class="bi bi-calendar-week" aria-hidden="true"></i>Rotation schedule</a>
  <a class="nav-link" href="#bmg-controls"><i class="bi bi-sliders" aria-hidden="true"></i>Manual controls</a>
  <a class="nav-link" href="#bmg-batch"><i class="bi bi-box-seam" aria-hidden="true"></i>Current batch</a>
  <a class="nav-link" href="#bmg-device"><i class="bi bi-cpu" aria-hidden="true"></i>Device &amp; setup</a>
  <a class="nav-link" href="#bmg-history"><i class="bi bi-clock-history" aria-hidden="true"></i>History</a>
</nav>
<div class="alert alert-warning bmg-safety-note mb-3" role="note"><strong>Web stop is not an emergency stop.</strong> Use the local physical emergency stop for immediate safety. Device-reported motor events are not verified drum rotation. Connectivity does not establish motor state.</div>
<?php if (!$gateOpen): ?><div class="alert alert-secondary mb-3" role="note">Hardware motor gate is disabled (<code>BMG_HARDWARE_ENABLED=false</code>). You can still configure and publish the drum's daily rotation schedule below and register devices; live motor commands (Run now / Stop) and device delivery stay paused until the hardware gate is opened.</div><?php elseif (!$hardwareAllowed && !$commandHardwareAllowed): ?><div class="alert alert-secondary mb-3" role="note">Manual motor commands require explicit hardware-control permission. <?php if ($configure): ?>You can still configure and publish the daily rotation schedule below and register devices.<?php endif; ?></div><?php elseif (!$hardwareAllowed && $commandHardwareAllowed): ?><div class="alert alert-info mb-3" role="note">Schedule editing requires configuration access. Manual commands remain gated by facilities management and hardware-control permission. <?php if (!$canStop): ?>Motor commands require one active bound device.<?php elseif (!$canRunNow): ?>Rotation run requests require an active drum; web stop remains available while a device is bound, but is not an emergency stop.<?php endif; ?></div><?php elseif ($hardwareAllowed && !$commandHardwareAllowed): ?><div class="alert alert-info mb-3" role="note">Manual commands require facilities management (<code>facilities.manage</code>) and hardware-control permission.</div><?php elseif (!$canStop): ?><div class="alert alert-info mb-3" role="note">Motor commands require one active bound device.</div><?php elseif (!$canRunNow): ?><div class="alert alert-info mb-3" role="note">Rotation run requests require an active drum and one active bound device. Web stop remains available while a device is bound, but is not an emergency stop.</div><?php endif; ?>
<?php require __DIR__ . '/_setup_steps.php'; ?>
<div class="bmg-detail-grid">
  <div class="bmg-main">
    <?php
    $savedTimes = is_array($schedule['times'] ?? null) ? array_values($schedule['times']) : [];
    $isDraftSchedule = $savedTimes === [];
    $editorTimes = $isDraftSchedule ? ['08:00', '12:00', '16:00'] : $savedTimes;
    $savedRotations = max(1, (int) ($schedule['rotations'] ?? 7));
    $editorSessions = count($editorTimes);
    $editorDailyTotal = $editorSessions * $savedRotations;
    $savedDailyTotal = count($savedTimes) * $savedRotations;
    $nextRunLabel = 'Schedule off';
    if (!empty($schedule['enabled'])) {
        $nextAt = $schedule['next_run_at'] ?? null;
        if (($nextAt === null || $nextAt === '') && $savedTimes !== []) {
            $tz = new \DateTimeZone('Asia/Manila');
            $nowManila = \App\Services\Scheduling\SchedulingClock::now();
            $upcoming = [];
            foreach ($savedTimes as $hhmm) {
                if (!is_string($hhmm) || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $hhmm)) { continue; }
                foreach ([$nowManila->format('Y-m-d'), $nowManila->modify('+1 day')->format('Y-m-d')] as $day) {
                    $cand = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $day . ' ' . $hhmm, $tz);
                    if ($cand !== false && $cand > $nowManila) { $upcoming[] = $cand; break; }
                }
            }
            if ($upcoming !== []) {
                usort($upcoming, static fn (\DateTimeImmutable $a, \DateTimeImmutable $b): int => $a <=> $b);
                $nextAt = $upcoming[0]->format('Y-m-d H:i:s');
            }
        }
        if (!empty($nextAt)) {
            $nextDt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $nextAt, new \DateTimeZone('Asia/Manila'));
            $nextRunLabel = $nextDt !== false ? $nextDt->format('g:i A · M j') : (string) $nextAt;
        } else {
            $nextRunLabel = 'No run times set';
        }
    }
    $rtcLabel = $device === null || !isset($device['rtc_valid'])
        ? 'RTC unknown'
        : ($device['rtc_valid'] ? 'RTC OK' : 'RTC invalid');
    ?>
    <section class="card ce-card bmg-panel mb-4" id="bmg-schedule" aria-labelledby="bmg-schedule-title">
      <div class="card-header bmg-panel-heading">
        <h2 class="card-title" id="bmg-schedule-title"><i class="bi bi-calendar-week" aria-hidden="true"></i> Drum automation &amp; schedule</h2>
        <div class="d-flex flex-wrap align-items-center gap-2">
          <span class="ce-tag"><span class="ce-status-dot ce-status-<?= !empty($schedule['enabled']) ? 'emerald' : 'slate' ?>"><?= !empty($schedule['enabled']) ? 'Schedule v' . (int) $schedule['version'] : 'Schedule off' ?></span></span>
          <span class="ce-tag">21 rot/day · UTC+08:00</span>
        </div>
      </div>
      <div class="card-body bmg-panel-body">
      <dl class="bmg-automation-stats">
        <div class="bmg-automation-stat">
          <dt>Controller</dt>
          <dd class="bmg-code"><?= $device === null ? 'No board bound' : esc($device['chip_mac']) ?></dd>
          <span class="bmg-caption"><?= $device === null ? 'Register a device to sync' : 'Last contact: ' . esc($device['last_seen_at'] ?? 'Never checked in') ?></span>
        </div>
        <div class="bmg-automation-stat">
          <dt>Clock &amp; sync</dt>
          <dd><?= esc($rtcLabel) ?></dd>
          <span class="bmg-caption"><?= $device !== null && isset($device['applied_schedule_version']) ? 'Applied v' . (int) $device['applied_schedule_version'] . ' · Saved v' . (int) $schedule['version'] : 'Saved v' . (int) $schedule['version'] . ' · Not yet reported' ?></span>
        </div>
        <div class="bmg-automation-stat">
          <dt>Next run</dt>
          <dd class="bmg-number"><?= esc($nextRunLabel) ?></dd>
          <span class="bmg-caption"><?= $savedTimes === [] ? 'No published times yet' : esc(implode(', ', $savedTimes)) ?></span>
        </div>
      </dl>

      <p class="text-muted small">BMG daily rule: <strong>21 rotations/day</strong> divided into <strong>3 sessions × 7 rotations</strong> (Asia/Manila UTC+08:00). Choose any session count (1–8) and custom times below; publishing bumps the schedule version the controller polls for.</p>

      <?php if ($configure): ?>
      <div class="bmg-schedule-summary" data-bmg-schedule-summary>
        <div>
          <span class="bmg-schedule-summary-label">Daily rotation plan<?= $isDraftSchedule ? ' · Suggested 3×7 draft (unsaved)' : '' ?></span>
          <strong class="bmg-number" data-bmg-daily-summary><?= $editorSessions ?> <?= $editorSessions === 1 ? 'session' : 'sessions' ?> × <?= $savedRotations ?> <?= $savedRotations === 1 ? 'rotation' : 'rotations' ?> = <?= $editorDailyTotal ?> rotations / day</strong>
        </div>
        <span class="ce-tag"><span class="ce-status-dot ce-status-<?= $editorDailyTotal === 21 ? 'emerald' : 'amber' ?>" data-bmg-daily-target><?= $editorDailyTotal === 21 ? '21 / 21 · Daily target met' : $editorDailyTotal . ' / 21 · Target 3 × 7' ?></span></span>
      </div>
      <form action="<?= esc(site_url($base . '/schedule')) ?>" method="POST" data-bmg-submit data-bmg-schedule data-confirm="Publish this daily rotation schedule and cancel pending commands? Saved intent does not prove the device has applied it." data-confirm-title="Save rotation schedule" data-confirm-label="Publish schedule" data-confirm-tone="warning">
        <?= csrf_field() ?>
        <input type="hidden" name="expected_version" value="<?= (int) ($schedule['version'] ?? 0) ?>">
        <div class="bmg-dynamic-controls">
          <div class="bmg-session-picker">
            <label class="form-label mb-0" for="scheduleSessionCount">Sessions / day</label>
            <select id="scheduleSessionCount" class="form-select form-select-sm" data-bmg-session-count aria-label="Sessions per day">
              <?php for ($s = 1; $s <= 8; $s++): ?>
                <option value="<?= $s ?>" <?= $editorSessions === $s ? 'selected' : '' ?>><?= $s ?> <?= $s === 1 ? 'session' : 'sessions' ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="bmg-preset-bar" role="group" aria-label="Quick rotation presets">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bmg-preset="08:00,12:00,16:00" data-bmg-preset-rotations="7"><i class="bi bi-magic" aria-hidden="true"></i> Standard 3×7 (8 AM, 12 PM, 4 PM)</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bmg-preset="07:00,13:00,19:00" data-bmg-preset-rotations="7"><i class="bi bi-clock" aria-hidden="true"></i> 3×7 Extended (7 AM, 1 PM, 7 PM)</button>
          </div>
        </div>
        <fieldset class="mb-3"><legend class="fs-6">Daily session times</legend>
          <div data-bmg-times>
            <?php foreach ($editorTimes as $i => $time): ?>
            <div class="d-flex align-items-end gap-2 mb-2" data-bmg-time-row><div class="flex-grow-1"><label class="form-label" for="rotationTime<?= (int) $i ?>" data-bmg-session-label>Session <?= (int) $i + 1 ?> start time</label><?= view('partials/schedule_time', ['id' => 'rotationTime' . (int) $i, 'name' => 'times[]', 'label' => 'Start time', 'value' => $time]) ?></div><button class="btn btn-outline-secondary" type="button" data-bmg-remove-time aria-label="Remove start time">Remove</button></div>
            <?php endforeach; ?>
          </div>
          <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
            <button class="btn btn-outline-secondary btn-sm" type="button" data-bmg-add-time><i class="bi bi-plus-lg" aria-hidden="true"></i> Add time</button>
            <span class="form-text mb-0" data-bmg-time-count aria-live="polite"><?= $editorSessions ?> of 8 times</span>
          </div>
        </fieldset>
        <div class="bmg-form-grid">
          <div class="mb-3"><label class="form-label" for="scheduleRotations">Rotations per run</label><input id="scheduleRotations" class="form-control" type="number" name="rotations" min="1" max="100" step="1" value="<?= $savedRotations ?>" required></div>
          <div class="mb-3"><label class="form-label" for="schedulePwm">Motor speed (PWM 1–255, optional)</label><input id="schedulePwm" class="form-control" type="number" name="pwm" min="1" max="255" step="1" value="<?= isset($schedule['pwm']) ? (int) $schedule['pwm'] : '' ?>" placeholder="e.g. 200"></div>
        </div>
        <div class="bmg-toggle-card mb-3">
          <div>
            <label class="form-check-label fw-semibold d-block" for="scheduleEnabled">Schedule enabled</label>
            <span id="scheduleEnableHelp" class="form-text d-block mt-0">Off keeps your saved times and rotations without running the motor. Enabling requires an active drum and a bound controller.</span>
          </div>
          <div class="form-check form-switch m-0">
            <input id="scheduleEnabled" class="form-check-input" name="enabled" type="checkbox" role="switch" value="1" <?= $device !== null && $unit['status'] === 'active' ? '' : 'disabled' ?> <?= !empty($schedule['enabled']) ? 'checked' : '' ?> aria-describedby="scheduleEnableHelp">
          </div>
        </div>
        <div class="alert alert-danger d-none small" data-bmg-schedule-error role="alert"></div>
        <button class="btn btn-primary" type="submit">Save schedule</button>
      </form>
      <?php else: ?>
      <div class="bmg-schedule-summary">
        <div>
          <span class="bmg-schedule-summary-label">Daily rotation plan</span>
          <strong class="bmg-number"><?= count($savedTimes) ?> <?= count($savedTimes) === 1 ? 'session' : 'sessions' ?> × <?= $savedRotations ?> <?= $savedRotations === 1 ? 'rotation' : 'rotations' ?> = <?= $savedDailyTotal ?> rotations / day</strong>
        </div>
        <span class="ce-tag"><span class="ce-status-dot ce-status-<?= $savedDailyTotal === 21 ? 'emerald' : 'amber' ?>"><?= $savedDailyTotal === 21 ? '21 / 21 · Daily target met' : $savedDailyTotal . ' / 21 · Target 3 × 7' ?></span></span>
      </div>
      <div class="bmg-saved-schedule mb-3">
        <div class="bmg-saved-schedule-heading">
          <span><i class="bi bi-clock" aria-hidden="true"></i> Saved session times</span>
          <span class="ce-tag bmg-number"><?= $savedRotations ?> rotations / session</span>
        </div>
        <div class="bmg-time-tags">
          <?php if ($savedTimes === []): ?><span class="bmg-caption">No daily times saved.</span><?php else: foreach ($savedTimes as $time): ?><span class="ce-tag bmg-number"><?= esc($time) ?></span><?php endforeach; endif; ?>
        </div>
        <p class="bmg-caption mb-0">PWM: <?= isset($schedule['pwm']) ? (int) $schedule['pwm'] : 'Not set' ?></p>
      </div>
      <p class="text-muted small mb-0">Editing the schedule requires facilities configuration access.</p>
      <?php endif; ?>
    </div></section>
    <section class="card ce-card bmg-panel mb-4" id="bmg-controls" aria-labelledby="bmg-controls-title">
      <div class="card-header bmg-panel-heading"><h2 class="card-title" id="bmg-controls-title"><i class="bi bi-sliders" aria-hidden="true"></i> Manual commands</h2></div>
      <div class="card-body bmg-panel-body"><p class="small text-muted">Requests are queued for the device. Delivery or acknowledgement is not proof of physical rotation or a safe stop.</p>
      <div class="bmg-command-grid">
      <div class="bmg-command-box border rounded p-3"><h3 class="h6">Rotation run request</h3>
      <form action="<?= esc(site_url($base . '/command')) ?>" method="POST" data-bmg-submit class="mb-3" data-confirm="Queue a run request for this drum? Confirm that the equipment and surrounding area are safe before continuing." data-confirm-title="Request rotation" data-confirm-label="Queue run" data-confirm-tone="warning">
        <?= csrf_field() ?><input type="hidden" name="expected_version" value="<?= (int) ($schedule['version'] ?? 0) ?>"><input type="hidden" name="command" value="run_now">
        <div class="mb-2"><label for="manualRotations" class="form-label">Rotations</label><input id="manualRotations" type="number" name="rotations" class="form-control" min="1" max="100" step="1" value="7" required <?= $canRunNow ? '' : 'disabled' ?>></div>
        <button class="btn btn-primary" type="submit" <?= $canRunNow ? '' : 'disabled' ?>>Request run now</button>
      </form>
      </div>
      <div class="bmg-command-box border rounded p-3"><h3 class="h6">Web stop request</h3><p class="small text-muted">Delivery is not guaranteed. For immediate safety, use the local physical emergency stop.</p>
      <form action="<?= esc(site_url($base . '/command')) ?>" method="POST" data-bmg-submit data-confirm="Queue a web stop request? This is not an emergency stop and delivery is not guaranteed. Use the local physical emergency stop for immediate safety." data-confirm-title="Request web stop" data-confirm-label="Queue stop" data-confirm-tone="danger">
        <?= csrf_field() ?><input type="hidden" name="expected_version" value="<?= (int) ($schedule['version'] ?? 0) ?>"><input type="hidden" name="command" value="stop"><button class="btn btn-outline-danger" type="submit" <?= $canStop ? '' : 'disabled' ?>>Request web stop</button>
      </form>
      </div></div>
    </div></section>
  </div>
  <aside class="bmg-aside" aria-label="Drum setup and batch">
    <section class="card ce-card bmg-panel mb-4" id="bmg-batch" aria-labelledby="bmg-batch-title">
      <div class="card-header bmg-panel-heading"><h2 class="card-title" id="bmg-batch-title"><i class="bi bi-box-seam" aria-hidden="true"></i> Current batch</h2></div>
      <div class="card-body bmg-panel-body">
      <?php if ($activeBatch === null): ?><p class="text-muted">No active batch. <?= $unit['status'] === 'active' ? 'This drum is available for a new batch record.' : 'Set the drum to active before starting a batch.' ?></p>
      <?php if ($manage && $unit['status'] === 'active'): ?><button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#startBatchModal">Start batch</button><?php endif; ?>
      <?php else: ?>
      <h3 class="h6 text-break"><?= esc($activeBatch['batch_code']) ?></h3><dl class="bmg-fact-grid small"><div class="bmg-fact"><dt>Status</dt><dd><?= esc($activeBatch['status']) ?></dd></div><div class="bmg-fact"><dt>Started (Asia/Manila)</dt><dd><?= esc($formatBatchTime($activeBatch['started_at'] ?? null)) ?></dd></div><div class="bmg-fact"><dt>Input weight</dt><dd><?= esc($activeBatch['total_input_weight_kg']) ?> kg</dd></div></dl><p class="small text-muted text-break"><strong>Creation notes:</strong> <?= nl2br(esc($activeBatch['notes'] ?? 'Not recorded')) ?></p>
      <?php if ($manage): ?><div class="d-flex flex-wrap gap-2"><button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#completeBatchModal">Complete batch</button><?php if ($cancelBatch): ?><button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#cancelBatchModal">Cancel batch</button><?php endif; ?></div><?php endif; endif; ?>
    </div></section>
    <section class="card ce-card bmg-panel mb-4" id="bmg-device" aria-labelledby="bmg-device-title">
      <div class="card-header bmg-panel-heading"><h2 class="card-title" id="bmg-device-title"><i class="bi bi-cpu" aria-hidden="true"></i> Device registration</h2></div>
      <div class="card-body bmg-panel-body">
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
<?php if ($configure): ?>
<?php $editUnit = $unit; require __DIR__ . '/_unit_modal.php'; ?>
<?php require __DIR__ . '/_archive_modal.php'; ?>
<?php endif; ?>
<?php if ($configure || $manage): require __DIR__ . '/_operation_modals.php'; endif; ?>
</div>
</section>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= esc(base_url('assets/js/bmg.js')) ?>"></script><script src="<?= esc(base_url('assets/js/bmg-gear.js')) ?>"></script><?= $this->endSection() ?>

<?= $this->extend('layouts/app') ?>
<?php
$unit = $detail['unit'];
$schedule = $detail['schedule'];
$device = $detail['device'] ?? null;
$id = (int) $unit['id'];
$base = '/facilities/unit/' . $id;
$releases = $releases ?? [];
$flashEnabled = !empty($flashEnabled);
?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/bmg.css'), 'attr') ?>">
<link rel="stylesheet" href="<?= esc(base_url('assets/css/bmg-usb.css'), 'attr') ?>">
<?= $this->endSection() ?>
<?= $this->section('page_actions') ?>
<a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url($base), 'attr') ?>" data-navigation="false">Back to drum</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<section class="bmg-workspace bmg-usb-workspace" data-bmg-usb
         data-unit-id="<?= $id ?>"
         data-expected-version="<?= (int) ($schedule['version'] ?? 0) ?>"
         data-authorize-url="<?= esc(site_url($base . '/usb/authorize-flash'), 'attr') ?>"
         data-releases-base="<?= esc(site_url($base . '/usb/releases'), 'attr') ?>"
         data-esptool-url="<?= esc(base_url('assets/vendor/esptool-js/bundle.js'), 'attr') ?>"
         data-bound-mac="<?= esc($device['chip_mac'] ?? '', 'attr') ?>"
         data-flash-enabled="<?= $flashEnabled ? '1' : '0' ?>"
         aria-labelledby="usbHeading">
  <form id="bmgUsbCsrf" hidden>
    <?= csrf_field() ?>
  </form>
  <header class="bmg-hero">
    <div class="bmg-eyebrow">USB commissioning · <?= esc($unit['unit_code'] ?? '') ?></div>
    <h2 class="h4" id="usbHeading">USB commissioning · <?= esc($unit['name'] ?? 'Drum') ?></h2>
    <p class="bmg-caption mb-0">Identify an ESP32, install approved firmware, or inspect receive-only serial output. This is not a code editor or a motor-control console.</p>
  </header>

  <ol class="bmg-usb-workflow" aria-label="Commissioning workflow">
    <li><span class="bmg-usb-step" aria-hidden="true">1</span><div><strong>Connect</strong><span>Select the intended USB port</span></div></li>
    <li><span class="bmg-usb-step" aria-hidden="true">2</span><div><strong>Identify</strong><span>Confirm isolation and chip identity</span></div></li>
    <li><span class="bmg-usb-step" aria-hidden="true">3</span><div><strong>Install</strong><span>Use an approved firmware release</span></div></li>
  </ol>

  <div class="bmg-usb-connection-status">
    <div class="bmg-eyebrow"><i class="bi bi-usb-symbol" aria-hidden="true"></i> Connection status</div>
    <p class="bmg-usb-status" data-usb-status role="status" aria-live="polite" aria-atomic="true">No USB port selected. Nothing opens or resets automatically.</p>
    <p class="bmg-caption mb-0" data-usb-support role="status">Browser support has not been checked. Web Serial requires a supported desktop browser (such as Chrome or Edge), a secure context (HTTPS or localhost), and permission to access a USB serial port.</p>

    <noscript><p class="alert alert-warning mt-3 mb-0">JavaScript is required for USB commissioning. No USB operation will run without it.</p></noscript>
  </div>

  <aside class="bmg-callout bmg-callout-warning bmg-usb-safety" role="note">
    <h3 class="h6"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Physically isolate motor power before opening the port</h3>
    <p>Opening a serial port or identifying the chip can reset the controller. A reset may restart existing firmware and motor behavior. A disabled web control or schedule is not physical isolation. Use the local isolation procedure and keep the work area clear.</p>
    <p class="mb-0"><strong>USB release and Stop monitor are not emergency stops.</strong> Use the local physical emergency stop when needed. Connectivity and serial output do not prove that the motor is stopped.</p>
  </aside>

  <div class="row g-4">
    <div class="col-xl-6">
      <section class="card bmg-panel h-100">
        <div class="bmg-panel-heading">
          <span class="bmg-icon" aria-hidden="true"><i class="bi bi-usb-plug"></i></span>
          <div><div class="bmg-eyebrow">Steps 1 &amp; 2 · Connection</div><h3 class="bmg-panel-title">Select and identify</h3></div>
        </div>
        <div class="bmg-panel-body">
        <p id="usbSelectHelp" class="text-muted small">Selecting a port only grants browser access; it does not open it, reset it, or verify that it is an ESP32. Check the physical USB connection to the intended drum.</p>
        <button type="button" class="btn btn-primary mb-3" data-usb-select aria-describedby="usbSelectHelp">Select USB port</button>
        <div class="form-check mb-3">
          <input type="checkbox" class="form-check-input" id="usbIsolated" data-usb-isolated aria-describedby="usbIsolationHelp">
          <label class="form-check-label" for="usbIsolated">Motor power is physically isolated and the work area is safe</label>
        </div>
        <p id="usbIsolationHelp" class="small text-muted">Confirm physical isolation before identification, monitoring, or installation. This acknowledgement does not isolate power or establish a safe motor state.</p>
        <p id="usbIdentifyHelp" class="small">Identification enters the bootloader and can reset the board. A separate reset-safety confirmation is required before proceeding; selecting a port is not that confirmation.</p>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <button type="button" class="btn btn-outline-primary" data-usb-identify disabled aria-describedby="usbIsolationHelp usbIdentifyHelp">Identify ESP32</button>
          <button type="button" class="btn btn-outline-secondary" data-usb-disconnect disabled>Release USB port</button>
        </div>
        <div class="bmg-empty bmg-usb-identity">
          <h4 class="h6">Hardware identity</h4>
          <p data-usb-identity class="text-break mb-0">Not identified. A selected USB port is not a verified ESP32.</p>
        </div>
        <p class="small text-muted mt-3 mb-0">Configuration-admin access is required for this page. Identification and monitoring do not require a hardware-control grant; neither operation authorizes motor commands.</p>
      </div></section>
    </div>
    <div class="col-xl-6">
      <section class="card bmg-panel h-100">
        <div class="bmg-panel-heading">
          <span class="bmg-icon" aria-hidden="true"><i class="bi bi-box-arrow-in-down"></i></span>
          <div><div class="bmg-eyebrow">Step 3 · Firmware</div><h3 class="bmg-panel-title">Install approved firmware</h3></div>
        </div>
        <div class="bmg-panel-body">
        <p class="text-muted small">Only server-approved releases can be installed. No arbitrary upload, code editing, manual address entry, or guessed flash offsets.</p>
        <label class="form-label" for="usbRelease">Approved firmware release</label>
        <select id="usbRelease" class="form-select mb-3" data-usb-release aria-describedby="usbFlashHelp">
          <?php if ($releases === []): ?>
          <option value="">No approved firmware available</option>
          <?php else: ?>
          <option value="">Select approved firmware</option>
          <?php foreach ($releases as $release): ?>
          <option value="<?= esc((string) $release['id'], 'attr') ?>"><?= esc((string) $release['name']) ?> · <?= esc((string) $release['chip_family']) ?> · <?= esc((string) $release['flash_size']) ?></option>
          <?php endforeach; ?>
          <?php endif; ?>
        </select>
        <div id="usbFlashHelp" class="small bmg-usb-gates">
          <p>Installation requires explicit commissioning permission and server authorization, independently of configuration access.</p>
          <ul class="ps-3">
            <li><code>BMG_USB_FLASH_ENABLED</code> must be enabled separately.</li>
            <li><code>BMG_HARDWARE_ENABLED</code> must be <code>false</code>.</li>
            <li>The unit must be in maintenance with its saved schedule disabled.</li>
            <li>A bound device is required, and its chip identity must match the identified ESP32.</li>
            <li>Motor power must remain physically isolated throughout commissioning.</li>
          </ul>
        </div>
        <?php if (!$flashEnabled): ?>
        <div class="bmg-empty bmg-usb-unavailable">
          <div class="bmg-usb-unavailable-heading"><i class="bi bi-lock" aria-hidden="true"></i> Installation unavailable</div>
          <p class="small mb-0">Installation prerequisites are not yet satisfied: check the commissioning permission, USB flash gate, maintenance status, disabled schedule, device binding and pending commands. Identification and receive-only monitoring remain available separately.</p>
        </div>
        <?php endif; ?>
        <button type="button" class="btn btn-primary mb-3" data-usb-flash disabled aria-describedby="usbFlashHelp">Install approved firmware</button>
        <label for="usbProgress" class="form-label d-block">Installation progress</label>
        <progress id="usbProgress" class="bmg-usb-progress" data-usb-progress max="100" value="0" aria-describedby="usbProgressLabel">0%</progress>
        <p id="usbProgressLabel" data-usb-progress-label class="small text-muted mb-0" role="status">No installation started.</p>
      </div></section>
    </div>
    <div class="col-12">
      <section class="card bmg-panel bmg-usb-monitor">
        <div class="bmg-panel-heading">
          <span class="bmg-icon" aria-hidden="true"><i class="bi bi-terminal"></i></span>
          <div><div class="bmg-eyebrow">Diagnostics · Separate from installation</div><h3 class="bmg-panel-title" id="usbLogHeading">Receive-only serial monitor</h3></div>
          <span class="bmg-status-pill is-muted bmg-usb-monitor-mode">Receive only</span>
        </div>
        <div class="bmg-panel-body">
        <p id="usbMonitorHelp">Starting the monitor opens the serial port and may reset the controller. Physical isolation confirmation is required. Monitoring never starts automatically.</p>
        <p class="small"><strong>No command entry or serial transmit controls.</strong> Legacy serial commands <code>s</code> and <code>r</code> can activate the motor. Do not use another serial terminal to send commands while commissioning.</p>
        <p id="usbLogPrivacy" class="small text-muted">Logs stay local to this page and may contain credentials or other secrets. They are not automatically sent or downloaded. Review and redact before sharing; clear sensitive output when finished. The log area is bounded and scrollable; new lines are not announced by screen readers.</p>
        <div class="bmg-usb-terminal">
        <div class="bmg-usb-log-header">
          <span class="bmg-usb-log-title"><i class="bi bi-terminal" aria-hidden="true"></i> Serial output</span>
          <div class="bmg-usb-log-toolbar">
          <button type="button" class="btn btn-outline-primary" data-usb-monitor disabled aria-describedby="usbMonitorHelp usbIsolationHelp">Start serial monitor</button>
          <button type="button" class="btn btn-outline-secondary" data-usb-stop-monitor disabled>Stop monitor</button>
          <button type="button" class="btn btn-outline-secondary" data-usb-clear>Clear log</button>
          </div>
        </div>
        <pre class="bmg-usb-log p-3 mb-0" data-usb-log tabindex="0" role="region" aria-labelledby="usbLogHeading" aria-describedby="usbLogPrivacy" aria-live="off"></pre>
        </div>
      </div></section>
    </div>
  </div>
</section>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/bmg-usb.js'), 'attr') ?>"></script>
<?= $this->endSection() ?>

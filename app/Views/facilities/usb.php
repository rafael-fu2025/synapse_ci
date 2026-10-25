<?= $this->extend('layouts/app') ?>
<?php
$unit = $detail['unit'];
$schedule = $detail['schedule'];
$device = $detail['device'] ?? null;
$id = (int) $unit['id'];
$base = '/facilities/unit/' . $id;
$releases = $releases ?? [];
$flashEnabled = !empty($flashEnabled);
$flashBlockers = $flashBlockers ?? [];
$builder = $builder ?? [];
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
         data-usb-build-url="<?= esc(site_url($base . '/usb/build'), 'attr') ?>"
         data-usb-setup-status-url="<?= esc(site_url($base . '/usb/setup-status'), 'attr') ?>"
         data-usb-sketch-url="<?= esc(site_url($base . '/usb/sketch'), 'attr') ?>"
         data-builder-available="<?= !empty($builder['available']) && ($builder['motor_outputs_enabled'] ?? null) === false ? '1' : '0' ?>"
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
  <header class="card"><div class="card-body pt-3">
    <div class="small text-muted">USB commissioning · <?= esc($unit['unit_code'] ?? '') ?></div>
    <h2 class="card-title" id="usbHeading">USB commissioning · <?= esc($unit['name'] ?? 'Drum') ?></h2>
    <p class="small text-muted mb-0">Set up Zynapse compost networking, build the reviewed template, then explicitly install it. Commissioning firmware has motor outputs hard-disabled. No RTC verification, motor approval, automatic connection or automatic reboot is provided.</p>
  </div></header>

  <ol class="bmg-usb-workflow" aria-label="Commissioning workflow">
    <li><span class="bmg-usb-step" aria-hidden="true">1</span><div><strong>Connect &amp; identify</strong><span>Select the intended USB port</span></div></li>
    <li><span class="bmg-usb-step" aria-hidden="true">2</span><div><strong>Configure &amp; build</strong><span>Wi-Fi and pasted device token</span></div></li>
    <li><span class="bmg-usb-step" aria-hidden="true">3</span><div><strong>Install</strong><span>Use a reviewed firmware build</span></div></li>
    <li><span class="bmg-usb-step" aria-hidden="true">4</span><div><strong>Verify network</strong><span>Manually check backend heartbeat</span></div></li>
  </ol>

  <div class="card"><div class="card-body pt-3 bmg-usb-connection-status">
    <div class="small text-muted"><i class="bi bi-usb-symbol" aria-hidden="true"></i> Connection status</div>
    <p class="bmg-usb-status" data-usb-status role="status" aria-live="polite" aria-atomic="true">No USB port selected. Nothing opens or resets automatically.</p>
    <p class="small text-muted mb-0" data-usb-support role="status">Browser support has not been checked. Web Serial requires a supported desktop browser (such as Chrome or Edge), a secure context (HTTPS or localhost), and permission to access a USB serial port.</p>

    <noscript><p class="alert alert-warning mt-3 mb-0">JavaScript is required for USB commissioning. No USB operation will run without it.</p></noscript>
  </div></div>

  <aside class="alert alert-warning bmg-usb-safety" role="note">
    <h3 class="h6"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Physically isolate motor power before opening the port</h3>
    <p>Opening a serial port or identifying the chip can reset the controller. A reset may restart existing firmware and motor behavior. A disabled web control or schedule is not physical isolation. Use the local isolation procedure and keep the work area clear.</p>
    <p class="mb-0"><strong>USB release and Stop monitor are not emergency stops.</strong> Use the local physical emergency stop when needed. Connectivity and serial output do not prove that the motor is stopped.</p>
  </aside>

  <div class="row g-4">
    <div class="col-xl-6">
      <section class="card bmg-panel h-100">
        <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between gap-2 bmg-panel-heading">
          <span class="text-primary fs-4" aria-hidden="true"><i class="bi bi-usb-plug"></i></span>
          <div><div class="small text-muted">Step 1 · Connection</div><h3 class="card-title mb-0 p-0">Select and identify</h3></div>
        </div>
        <div class="card-body pt-3 bmg-panel-body">
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
        <div class="border rounded p-3 bmg-empty bmg-usb-identity">
          <h4 class="h6">Hardware identity</h4>
          <p data-usb-identity class="text-break mb-0">Not identified. A selected USB port is not a verified ESP32.</p>
        </div>
        <p class="small text-muted mt-3 mb-0">Configuration-admin access is required for this page. Identification and monitoring do not require a hardware-control grant; neither operation authorizes motor commands.</p>
      </div></section>
    </div>
    <div class="col-xl-6">
      <section class="card bmg-panel h-100">
        <div class="card-header py-3">
          <div class="small text-muted">Step 2 · Network commissioning only</div>
          <h3 class="card-title mb-0 p-0">Wi-Fi &amp; device token</h3>
        </div>
        <div class="card-body pt-3">
        <div class="alert alert-info small">Before compiling, set this drum to <strong>maintenance</strong>, keep its schedule disabled, and register its controller. Paste the current token from registration; this page never retrieves or reuses an old token. <a href="<?= esc(site_url($base), 'attr') ?>" data-navigation="false">Review drum setup</a>.</div>
        <p data-setup-builder role="status"><?= esc($builder['message'] ?? 'Builder prerequisites have not been checked. Check status before building.') ?></p>
        <p class="small">Reviewed board profile: <span data-setup-profile><?= esc($builder['board_profile'] ?? 'Not confirmed') ?></span>. Firmware: <span data-setup-version><?= esc($builder['firmware_version'] ?? 'Not confirmed') ?></span>. Motors remain hard-disabled.</p>
        <button type="button" class="btn btn-outline-secondary mb-3" data-setup-check>Check builder prerequisites</button>
        <form data-setup-form autocomplete="off" novalidate>
          <fieldset data-setup-fields disabled>
            <label for="setupSsid" class="form-label">Wi-Fi network name (SSID)</label>
            <input id="setupSsid" class="form-control mb-2" data-setup-ssid maxlength="32" required aria-describedby="setupWifiHelp">
            <p id="setupWifiHelp" class="small text-muted">Use a 2.4 GHz Wi-Fi network accessible to the ESP32. SSID limit: 32 UTF-8 bytes (some characters use more than one byte).</p>
            <label for="setupPassword" class="form-label">Wi-Fi password</label>
            <input id="setupPassword" type="password" class="form-control mb-3" data-setup-password autocomplete="new-password" required minlength="8" maxlength="64" aria-describedby="setupPasswordHelp">
            <p id="setupPasswordHelp" class="small text-muted">Required: 8–64 characters.</p>
            <label for="setupApi" class="form-label">Device API base URL</label>
            <input id="setupApi" type="url" class="form-control mb-2" data-setup-api required aria-describedby="setupApiHelp">
            <p id="setupApiHelp" class="small text-muted">Use the backend address reachable from the ESP32. localhost and 127.0.0.1 point to the ESP32 itself, not this server. No URL credentials, query or fragment.</p>
            <label for="setupToken" class="form-label">Device token (paste current token)</label>
            <input id="setupToken" type="password" class="form-control mb-2" data-setup-token required minlength="64" maxlength="64" pattern="[0-9a-f]{64}" autocomplete="new-password" aria-describedby="setupTokenHelp">
            <p id="setupTokenHelp" class="small text-muted">Paste the current 64-character lowercase hexadecimal device token.</p>
            <p class="small"><a href="<?= esc(site_url($base) . '#bmg-device', 'attr') ?>" data-navigation="false">Issue a token in device registration</a>. Never reuse or copy a legacy serial token. Secrets are not saved in this browser or inserted into the source editor; they are sent to the private server build.</p>
            <div class="form-check mb-3"><input id="setupHttp" type="checkbox" class="form-check-input" data-setup-http><label for="setupHttp" class="form-check-label">Allow plain HTTP on my trusted LAN. I understand the device token travels unencrypted.</label></div>
            <details class="mb-3">
              <summary>HTTPS trust · required for HTTPS</summary>
              <label for="setupCa" class="form-label mt-2">Root CA PEM</label>
              <textarea id="setupCa" class="form-control" rows="5" data-setup-ca spellcheck="false" aria-describedby="setupCaHelp"></textarea>
              <p id="setupCaHelp" class="small text-muted">For HTTPS, paste the reviewed root CA certificate that trusts the backend. There is no insecure TLS fallback. Not required for explicitly acknowledged LAN HTTP.</p>
            </details>
            <p class="small">Confirm physical motor isolation in Step 1 before compiling. Building never enables hardware, opens USB, installs firmware or reboots.</p>
            <p class="small text-danger" data-setup-validation role="alert"></p>
            <button type="submit" class="btn btn-primary" data-setup-build disabled aria-describedby="setupBuildHelp">Compile reviewed network firmware</button>
          </fieldset>
        </form>
        <p id="setupBuildHelp" class="small text-muted mt-2">The first compile may take 3 minutes. Keep this page open; there is no automatic retry or installation.</p>
        <div data-setup-waiting hidden aria-hidden="true"><div class="progress"><div class="progress-bar progress-bar-striped progress-bar-animated w-100">Waiting for compiler</div></div></div>
        <p data-setup-message class="small mt-3" role="status" aria-live="polite">No build requested.</p>
        <noscript><p class="alert alert-warning">Guided setup requires JavaScript. Use the reviewed board profile in the local Arduino IDE instead; this form never submits credentials without JavaScript.</p></noscript>
        <details><summary>Advanced · reviewed source / local Arduino IDE export</summary>
          <p class="small mt-2">This sanitized source contains no entered credentials. Editing or uploading a sketch disables guided compilation: custom edits are export-only for local review in the Arduino IDE with the reviewed board profile. Edits do not imply API compatibility or hardware approval. Export includes your exact editor contents: a local sketch you open or edit may already contain secrets, so protect its ZIP even though form credentials are not added.</p>
          <button type="button" class="btn btn-outline-secondary mb-2" data-setup-source>Load / reset reviewed source</button>
          <label for="setupUpload" class="form-label">Open local .ino (browser only)</label><input id="setupUpload" type="file" accept=".ino" class="form-control mb-2" data-setup-upload>
          <label for="setupEditor" class="form-label">Local source editor</label><textarea id="setupEditor" rows="12" class="form-control mb-2" data-setup-editor spellcheck="false" disabled></textarea>
          <p data-setup-source-status class="small" role="status">Reviewed source not loaded.</p>
          <button type="button" class="btn btn-outline-secondary mb-2" data-setup-support>Load reviewed support files (keep local edits)</button>
          <button type="button" class="btn btn-outline-secondary" data-setup-download disabled>Download project (.zip, no entered credentials)</button>
          <p class="small mt-2">Load reviewed support files before exporting a locally opened sketch. The ZIP contains your editor source, commissioning_policy.h, a credential-free device_config.h.example and README. No form credentials are inserted into the editor or ZIP. Review local source for any secrets you added yourself. Copy the example to device_config.h and configure it locally before compiling in the Arduino IDE with the reviewed profile.</p>
        </details>
      </div></section>
    </div>
    <div class="col-12">
      <section class="card bmg-panel h-100">
        <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between gap-2 bmg-panel-heading">
          <span class="text-primary fs-4" aria-hidden="true"><i class="bi bi-box-arrow-in-down"></i></span>
          <div><div class="small text-muted">Step 3 · Firmware</div><h3 class="card-title mb-0 p-0">Install approved firmware</h3></div>
        </div>
        <div class="card-body pt-3 bmg-panel-body">
        <p class="text-muted small">Only server-approved releases, including your private trusted-template build, can be installed. Local editor exports cannot be compiled or installed here. No manual address entry or guessed flash offsets.</p>
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
        <div class="alert alert-secondary bmg-empty bmg-usb-unavailable">
          <div class="bmg-usb-unavailable-heading"><i class="bi bi-lock" aria-hidden="true"></i> Installation unavailable</div>
          <?php if ($flashBlockers !== []): ?>
          <ul class="small ps-3 mb-2"><?php foreach ($flashBlockers as $blocker): ?><li><?= esc($blocker) ?></li><?php endforeach; ?></ul>
          <p class="small mb-0">Resolve these prerequisites, then reload this workspace. Building and identification do not grant installation access.</p>
          <?php else: ?>
          <p class="small mb-0">Installation prerequisites are not yet satisfied: check the commissioning permission, USB flash gate, maintenance status, disabled schedule, device binding and pending commands. Identification and receive-only monitoring remain available separately.</p>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <button type="button" class="btn btn-primary mb-3" data-usb-flash disabled aria-describedby="usbFlashHelp">Install approved firmware</button>
        <label for="usbProgress" class="form-label d-block">Installation progress</label>
        <progress id="usbProgress" class="bmg-usb-progress" data-usb-progress max="100" value="0" aria-describedby="usbProgressLabel">0%</progress>
        <p id="usbProgressLabel" data-usb-progress-label class="small text-muted mb-0" role="status">No installation started.</p>
      </div></section>
    </div>
    <div class="col-12">
      <section class="card">
        <div class="card-body pt-3">
          <div class="small text-muted">Step 4 · Verify backend heartbeat</div>
          <h3 class="card-title">Check network reporting</h3>
          <p class="small">After installation, keep motor power isolated and review reset safety before any physical reset. Nothing reboots automatically. A historical last-seen timestamp is not proof of a new heartbeat, RTC correctness or a safe motor state.</p>
          <button type="button" class="btn btn-outline-primary" data-setup-verify>Check backend heartbeat now</button>
          <p data-setup-heartbeat class="small mt-3" role="status" aria-live="polite">No backend status received in this session.</p>
        </div>
      </section>
    </div>
    <div class="col-12">
      <section class="card bmg-panel bmg-usb-monitor">
        <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between gap-2 bmg-panel-heading">
          <span class="text-primary fs-4" aria-hidden="true"><i class="bi bi-terminal"></i></span>
          <div><div class="small text-muted">Diagnostics · Separate from installation</div><h3 class="card-title mb-0 p-0" id="usbLogHeading">Receive-only serial monitor</h3></div>
          <span class="badge bg-light text-dark border bmg-usb-monitor-mode">Receive only</span>
        </div>
        <div class="card-body pt-3 bmg-panel-body">
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
<script src="<?= esc(base_url('assets/js/bmg-setup.js'), 'attr') ?>"></script>
<?= $this->endSection() ?>

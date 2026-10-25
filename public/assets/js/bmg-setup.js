/** Trusted-template network commissioning. No serial ownership or persistent secrets. */
(function () {
  'use strict';
  if (window.SynapseBmgSetup) return;
  let current;
  function initialize() {
    const root = document.querySelector('[data-bmg-usb]');
    if (current?.root === root) return;
    current?.dispose(); current = null;
    if (!root?.dataset.usbBuildUrl || !root.querySelector('[data-setup-form]')) return;
    current = workspace(root);
  }
  function endpoints(root) {
    const unit = root.dataset.unitId;
    if (!/^[1-9]\d*$/.test(unit)) throw new Error();
    const result = {};
    for (const [key, suffix] of [['usbBuildUrl', 'build'], ['usbSetupStatusUrl', 'setup-status'], ['usbSketchUrl', 'sketch']]) {
      const raw = root.dataset[key];
      if (!raw || /[\\\s?#]/.test(raw) || /%2e|%2f|%5c/i.test(raw) || raw.split('/').some(part => part === '.' || part === '..')) throw new Error();
      const url = new URL(raw);
      if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin || url.username || url.password
        || !new RegExp('^(?:/[^/]+)*/facilities/unit/' + unit + '/usb/' + suffix + '$').test(url.pathname)) throw new Error();
      result[suffix] = url.href;
    }
    if (result.build.replace(/build$/, 'setup-status') !== result['setup-status'] || result.build.replace(/build$/, 'sketch') !== result.sketch) throw new Error();
    return result;
  }
  // Small, deterministic STORE-only ZIP: fixed filenames, UTF-8 text, no compression dependency.
  function projectZip(files) {
    const encoder = new TextEncoder(), local = [], central = [];
    let offset = 0, centralSize = 0;
    function header(length) {
      const bytes = new Uint8Array(length), view = new DataView(bytes.buffer);
      return { bytes, u16: (at, value) => view.setUint16(at, value, true), u32: (at, value) => view.setUint32(at, value, true) };
    }
    for (const [filename, text] of Object.entries(files)) {
      const name = encoder.encode(filename), data = encoder.encode(text);
      let crc = 0xffffffff;
      for (const byte of data) {
        crc ^= byte;
        for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0);
      }
      crc = (crc ^ 0xffffffff) >>> 0;
      const entry = header(30);
      entry.u32(0, 0x04034b50); entry.u16(4, 20); entry.u16(6, 0x800); entry.u16(12, 0x21);
      entry.u32(14, crc); entry.u32(18, data.length); entry.u32(22, data.length); entry.u16(26, name.length);
      local.push(entry.bytes, name, data);
      const directory = header(46);
      directory.u32(0, 0x02014b50); directory.u16(4, 20); directory.u16(6, 20); directory.u16(8, 0x800); directory.u16(14, 0x21);
      directory.u32(16, crc); directory.u32(20, data.length); directory.u32(24, data.length); directory.u16(28, name.length); directory.u32(42, offset);
      central.push(directory.bytes, name);
      offset += 30 + name.length + data.length; centralSize += 46 + name.length;
    }
    const end = header(22), count = Object.keys(files).length;
    end.u32(0, 0x06054b50); end.u16(8, count); end.u16(10, count); end.u32(12, centralSize); end.u32(16, offset);
    return new Blob([...local, ...central, end.bytes], { type: 'application/zip' });
  }
  const CONFIG_EXAMPLE = `#pragma once
// Placeholders only. Copy to PRIVATE device_config.h and configure locally.
static const char WIFI_SSID[] = "REPLACE_WITH_WIFI_NAME";
static const char WIFI_PASS[] = "REPLACE_WITH_WIFI_PASSWORD";
// Origin + optional deployment subdirectory; no trailing slash or API path.
static const char API_BASE_URL[] = "https://humus.example.invalid";
static const char DEVICE_TOKEN[] = "REPLACE_WITH_NEW_64_LOWERCASE_HEX_DEVICE_TOKEN";
// HTTPS requires a reviewed root CA. No insecure TLS fallback.
// #define BMG_ROOT_CA_PEM R"PEM(-----BEGIN CERTIFICATE-----
// ...reviewed CA certificate...
// -----END CERTIFICATE-----
// )PEM"
// ONLY for explicitly acknowledged trusted-LAN HTTP, never public networks:
// #define BMG_ALLOW_HTTP true
`;
  function workspace(root) {
    const el = name => root.querySelector(`[data-setup-${name}]`);
    let urls, pending = root.dataset.setupBusy === '1', building = pending, dead = false, custom = false, loaded = false;
    let available = root.dataset.builderAvailable === '1', policy = null, requestingBuild = false;
    const message = text => { el('message').textContent = text; };
    try { urls = endpoints(root); } catch (_) {
      el('fields').disabled = true;
      ['check', 'verify', 'source', 'support', 'upload', 'editor', 'download'].forEach(name => { el(name).disabled = true; });
      message('Invalid setup endpoint configuration. Setup is disabled.'); return { root, dispose() {} };
    }
    const idle = () => !dead && !pending && window.SynapseBmgUsb?.canSetup(root) === true;
    function update() {
      const locked = !idle();
      el('fields').disabled = locked;
      el('build').disabled = locked || !available || custom || !root.querySelector('[data-usb-isolated]').checked;
      ['check', 'verify', 'source', 'support', 'upload'].forEach(name => { el(name).disabled = locked; });
      el('editor').disabled = locked || !loaded;
      el('download').disabled = locked || !loaded || policy === null;
      el('form').setAttribute('aria-busy', String(requestingBuild));
      el('build').setAttribute('aria-busy', String(requestingBuild));
      el('build').textContent = requestingBuild ? 'Compiling reviewed firmware…' : 'Compile reviewed network firmware';
      el('waiting').hidden = !requestingBuild;
    }
    async function request(url, options = {}) {
      // Never abort a mutating build: a timeout would not mean the server stopped compiling.
      const response = await fetch(url, { ...options, signal: options.method === 'POST' ? undefined : AbortSignal.timeout(15000), credentials: 'same-origin', cache: 'no-store', redirect: 'error',
        headers: { ...options.headers, 'X-Requested-With': 'XMLHttpRequest' } });
      window.SynapseCsrf?.fromResponse(response);
      if (response.redirected || response.url !== url) throw new Error();
      const text = await response.text();
      if (text.length > 1048576) throw new Error();
      const json = JSON.parse(text);
      window.SynapseCsrf?.sync(json.csrf);
      if (!response.ok && !(options.method === 'POST' && [422, 409, 503].includes(response.status) && confirmedFailure(json))) throw new Error();
      return json;
    }
    function confirmedFailure(json) {
      return json?.success === false && typeof json.message === 'string' && json.message.trim().length > 0 && json.message.length <= 1000;
    }
    function safeFailure(text) {
      // Never render HTML or echo pasted credentials from a validation response.
      for (const name of ['password', 'token', 'ca']) {
        const secret = el(name).value;
        if (secret) text = text.split(secret).join('[redacted]');
      }
      return text.replace(/[\u0000-\u001f\u007f]/g, ' ').trim();
    }
    function builder(status) {
      available = status?.available === true && status.motor_outputs_enabled === false;
      el('builder').textContent = typeof status?.message === 'string' ? status.message : 'Builder prerequisites unavailable.';
      el('profile').textContent = typeof status?.board_profile === 'string' ? status.board_profile : 'Not confirmed';
      el('version').textContent = typeof status?.firmware_version === 'string' ? status.firmware_version : 'Not confirmed';
    }
    async function run(task, mutation = false) {
      if (!idle()) return;
      pending = true; building = mutation; requestingBuild = mutation; root.dataset.setupBusy = '1';
      window.SynapseBmgUsb.refresh(root); update();
      try { await task(); }
      catch (_) { if (!dead) message(mutation
        ? 'Build outcome could not be confirmed. The server may still be compiling; do not retry blindly. Check with an administrator before retrying. No installation was started.'
        : 'Setup request could not be completed. No USB operation was started.'); }
      finally {
        requestingBuild = false;
        // An uncertain POST must remain locked rather than permitting duplicate builds.
        if (!building) { pending = false; delete root.dataset.setupBusy; }
        window.SynapseBmgUsb?.refresh(root); update();
      }
    }
    function payload() {
      const invalid = (name, text) => { el(name).setCustomValidity(text); return text; };
      ['ssid', 'password', 'token', 'api', 'http', 'ca'].forEach(name => el(name).setCustomValidity(''));
      let api, error = '';
      try { api = new URL(el('api').value); } catch (_) { error = invalid('api', 'Enter an absolute HTTP or HTTPS API URL.'); }
      if (api && (!['https:', 'http:'].includes(api.protocol) || api.username || api.password || api.search || api.hash
        || /^(localhost|127\..*|\[::1\])$/i.test(api.hostname))) error = invalid('api', 'Use a reachable backend HTTP(S) URL without credentials, query or fragment; not localhost.');
      el('ca').required = api?.protocol === 'https:';
      if (api?.protocol === 'http:' && !el('http').checked) error = invalid('http', 'Plain HTTP requires explicit trusted-LAN acknowledgement.');
      if (el('ca').required && !/^-----BEGIN CERTIFICATE-----[\s\S]+-----END CERTIFICATE-----$/.test(el('ca').value.trim())) {
        error = invalid('ca', 'HTTPS requires a reviewed root CA PEM certificate.');
        el('ca').closest('details').open = true;
      }
      if (!el('ssid').value || new TextEncoder().encode(el('ssid').value).length > 32) error = invalid('ssid', 'Enter a Wi-Fi SSID of 1–32 UTF-8 bytes.');
      if (el('password').value.length < 8 || el('password').value.length > 64) error = invalid('password', 'Wi-Fi password must contain 8–64 characters.');
      if (!/^[0-9a-f]{64}$/.test(el('token').value)) error = invalid('token', 'Device token must be exactly 64 lowercase hexadecimal characters.');
      el('validation').textContent = error;
      if (!el('form').reportValidity()) return null;
      if (!window.SynapseCsrf) { message('Shared CSRF service is unavailable. Reload before building.'); return null; }
      const data = new FormData();
      for (const [key, value] of Object.entries({ wifi_ssid: el('ssid').value, wifi_password: el('password').value,
        api_base_url: api.href, device_token: el('token').value, allow_http: el('http').checked ? '1' : '0',
        expected_version: root.dataset.expectedVersion, physical_isolation: '1' })) data.set(key, value);
      if (el('ca').value.trim()) data.set('root_ca_pem', el('ca').value.trim());
      return data;
    }
    const submit = event => {
      event.preventDefault();
      if (!idle() || custom || !available || !root.querySelector('[data-usb-isolated]').checked) return;
      const body = payload(); if (!body) return;
      run(async () => {
        message('Compiling the trusted network template; the first build may take 3 minutes. Keep this page open. No automatic retry, USB installation or reboot.');
        const json = await request(urls.build, { method: 'POST', body, headers: window.SynapseCsrf.headers() });
        if (dead) return;
        if (confirmedFailure(json)) {
          building = false;
          message(`Build rejected: ${safeFailure(json.message)} Correct the configuration, then explicitly choose Compile to retry. No automatic retry or installation.`);
          return;
        }
        if (json.success !== true || !window.SynapseBmgUsb.selectBuild(root, json.release)) throw new Error();
        building = false;
        el('password').value = ''; el('token').value = '';
        message('Private reviewed build selected in Step 3. Review all installation gates and explicitly choose Install. No flash or reboot has occurred.');
      }, true);
    };
    const click = event => {
      const button = event.target.closest('button'); if (!button || button.disabled) return;
      if (button === el('check') || button === el('verify')) run(async () => {
        const json = await request(urls['setup-status']); if (dead) return;
        builder(json.builder);
        if (button === el('verify')) {
          const device = json.device || {};
          const value = key => typeof device[key] === 'string' ? device[key] : 'not reported';
          el('heartbeat').textContent = `Backend status received now. Historical last seen: ${value('last_seen_at')}. Reported firmware: ${value('firmware_version')}; firmware reported at: ${value('firmware_reported_at')}. This does not verify a new heartbeat, RTC or motor safety.`;
        }
        message('Backend status received. No USB operation started.');
      });
      else if (button === el('source') || button === el('support')) run(async () => {
        const json = await request(urls.sketch); if (dead) return;
        if (typeof json.source !== 'string' || json.filename !== 'compost.ino' || json.motor_outputs_enabled !== false
          || typeof json.support_files?.['commissioning_policy.h'] !== 'string' || !json.support_files['commissioning_policy.h'].trim()) throw new Error();
        policy = json.support_files['commissioning_policy.h'];
        if (button === el('source')) {
          el('editor').value = json.source; loaded = true; custom = false;
          el('upload').value = ''; el('source-status').textContent = 'Reviewed sanitized source and support files loaded. Guided build uses the canonical server template, not editor contents.';
        } else el('source-status').textContent = custom
          ? 'Reviewed support files loaded; custom editor source preserved. Custom source remains export-only, with no API compatibility or hardware approval.'
          : 'Reviewed support files loaded. Editor contents unchanged.';
      });
      else if (button === el('download') && idle() && loaded && policy !== null) {
        const readme = `LOCAL ARDUINO IDE PROJECT - NETWORK COMMISSIONING ONLY\n\nExtract the compost folder and open compost.ino in the Arduino IDE.\nReviewed board profile: ${el('profile').textContent}\nUse the reviewed toolchain: Espressif Arduino ESP32 core 3.3.3 and ArduinoJson 7.4.2.\nConfirm the complete reviewed profile and flash settings before compiling.\n\nCopy device_config.h.example to device_config.h in this folder.\nReplace placeholders LOCALLY with Wi-Fi credentials, reachable backend origin\nplus optional deployment subdirectory (not an API path), and a new 64-character\nlowercase hexadecimal device token. HTTPS requires the reviewed root CA PEM.\nHTTP requires explicit trusted-LAN consent; it sends the token unencrypted.\nNo entered form credentials are inserted into this project. Keep your private\nheader out of source control and sharing. Review local edits for secrets.\n\nThe included policy header is from the reviewed source endpoint.\nCustom editor contents are local/export-only, never sent to the server compiler.\nLocal edits do not establish API compatibility or hardware approval.\nMotor outputs must remain hard-disabled. Physically isolate motor power before\nUSB/reset/installation. No RTC verification or motor-safety approval is provided.\n`;
        const url = URL.createObjectURL(projectZip({ 'compost/compost.ino': el('editor').value,
          'compost/commissioning_policy.h': policy, 'compost/device_config.h.example': CONFIG_EXAMPLE, 'compost/README.txt': readme }));
        const link = document.createElement('a'); link.href = url; link.download = 'compost-project.zip'; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      }
    };
    function markCustom() {
      custom = true; loaded = true;
      el('source-status').textContent = 'Custom local source: export only. Reset reviewed source to re-enable guided compilation. No API compatibility or hardware approval is implied.';
      update();
    }
    const change = event => {
      if (event.target !== el('upload') || !idle()) return;
      const file = el('upload').files?.[0]; if (!file) return;
      if (!/\.ino$/i.test(file.name) || file.size > 524288) { message('Choose an .ino file no larger than 512 KiB.'); return; }
      run(async () => { const source = await file.text(); if (!dead) { el('editor').value = source; markCustom(); } });
    };
    const input = event => {
      if (event.target.closest('[data-setup-form]')) {
        event.target.setCustomValidity?.(''); el('validation').textContent = '';
        if (event.target === el('api')) {
          el('ca').setCustomValidity('');
          el('ca').required = /^https:/i.test(el('api').value);
        }
      }
    };
    el('form').addEventListener('submit', submit);
    root.addEventListener('input', input);
    root.addEventListener('click', click); root.addEventListener('change', change);
    root.addEventListener('change', update); root.addEventListener('synapse:bmg-usb-state', update);
    el('editor').addEventListener('input', markCustom);
    update();
    return { root, get active() { return building; }, dispose() {
      dead = true; el('password').value = ''; el('token').value = ''; el('editor').value = '';
      el('form').removeEventListener('submit', submit); root.removeEventListener('click', click); root.removeEventListener('input', input);
      root.removeEventListener('change', change); root.removeEventListener('change', update);
      root.removeEventListener('synapse:bmg-usb-state', update); el('editor').removeEventListener('input', markCustom);
    } };
  }
  window.SynapseBmgSetup = { initialize };
  document.addEventListener('synapse:page-load', initialize);
  document.addEventListener('synapse:before-navigate', event => { if (current?.active) event.preventDefault(); });
  document.addEventListener('click', event => {
    if (current?.active && event.target.closest('a[href]')) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, true);
  document.addEventListener('submit', event => {
    if (current?.active) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, true);
  window.addEventListener('beforeunload', event => { if (current?.active) { event.preventDefault(); event.returnValue = ''; } });
  const dispose = () => { current?.dispose(); current = null; };
  document.addEventListener('synapse:before-swap', dispose); window.addEventListener('pagehide', dispose);
  window.addEventListener('pageshow', initialize);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true }); else initialize();
})();

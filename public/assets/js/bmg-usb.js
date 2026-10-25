/** Local-only USB commissioning. No cached permissions, serial console, or automatic reset. */
(function () {
  'use strict';
  if (window.SynapseBmgUsb) return;
  const LIMIT = 16 * 1024 * 1024;
  let current = null;
  const retiring = new Set();
  function retire(instance) {
    if (!instance) return;
    const done = Promise.resolve(instance.dispose());
    retiring.add(done);
    done.finally(() => retiring.delete(done));
  }

  function initialize() {
    if (retiring.size) { Promise.all(retiring).then(initialize); return; }
    const root = document.querySelector('[data-bmg-usb]');
    if (current?.root === root) return;
    if (current) {
      retire(current); current = null;
      Promise.all(retiring).then(initialize); return;
    }
    if (!root) return;
    current = workspace(root);
  }

  function configuredUrl(raw) {
    // Inspect the original path before URL normalization can remove dot segments.
    if (typeof raw !== 'string' || raw.length > 2048 || /[\\\s?#]/.test(raw)) throw new Error('Invalid USB URL configuration.');
    const match = raw.match(/^https?:\/\/[^/]+(\/[^?#]*)?$/);
    if (!match) throw new Error('Invalid USB URL configuration.');
    const path = match[1] || '/';
    if (path.split('/').some(segment => {
      const decoded = decodeURIComponent(segment);
      return decoded === '.' || decoded === '..' || /[/\\]/.test(decoded);
    })) throw new Error('USB URL traversal is not allowed.');
    const url = new URL(raw);
    if (url.origin !== location.origin || url.username || url.password || url.search || url.hash) throw new Error('USB URLs must be same-origin without credentials, queries, or fragments.');
    return url;
  }

  function configuration(root) {
    const unit = root.dataset.unitId;
    if (!/^[1-9][0-9]*$/.test(unit)) throw new Error('Invalid USB unit configuration.');
    const releases = configuredUrl(root.dataset.releasesBase);
    const authorize = configuredUrl(root.dataset.authorizeUrl);
    const esptool = configuredUrl(root.dataset.esptoolUrl);
    if (!new RegExp('^(?:/[^/]+)*/facilities/unit/' + unit + '/usb/releases$').test(releases.pathname)
      || authorize.href !== releases.href.slice(0, -'/releases'.length) + '/authorize-flash'
      || !/^(?:\/[^/]+)*\/assets\/vendor\/esptool-js\/bundle\.js$/.test(esptool.pathname)) throw new Error('Unexpected USB endpoint configuration.');
    return { releases: releases.href, authorize: authorize.href, esptool: esptool.href };
  }

  function workspace(root) {
    const el = name => root.querySelector(`[data-usb-${name}]`);
    let urls;
    try { urls = configuration(root); }
    catch (_) {
      el('status').textContent = 'USB workspace URL configuration is invalid. No USB operation is available; reload or contact an administrator.';
      root.querySelectorAll('button, select').forEach(control => { control.disabled = true; });
      return { root, active: false, needsCleanup: false, dispose() {} };
    }
    const supported = window.isSecureContext && !!navigator.serial?.requestPort;
    let port = null, transport = null, reader = null, monitorTask = null;
    let identity = null, busy = false, writing = false, monitoring = false, dead = false;
    let operation = null, closing = null, lost = false;
    const requests = new Set();
    const log = value => { el('log').textContent = (el('log').textContent + String(value)).slice(-65536); };
    const status = value => { el('status').textContent = value; };
    function clearIdentity() {
      identity = null;
      el('identity').textContent = 'Not identified. A selected USB serial adapter is not a verified ESP32.';
    }
    function gate() {
      return port && identity?.family === 'ESP32-S3' && identity.flash === '16MB'
        && identity.mac === root.dataset.boundMac.toLowerCase() && root.dataset.flashEnabled === '1'
        && el('isolated').checked && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(el('release').value);
    }
    function update() {
      const setupBusy = root.dataset.setupBusy === '1';
      el('select').disabled = !supported || busy || monitoring || dead || setupBusy;
      el('identify').disabled = !port || !el('isolated').checked || busy || monitoring || dead;
      el('monitor').disabled = !port || !el('isolated').checked || busy || monitoring || dead;
      el('disconnect').disabled = !port || busy || dead;
      el('stop-monitor').disabled = !monitoring || busy || dead;
      el('flash').disabled = !gate() || busy || monitoring || dead;
      el('release').disabled = busy || dead;
      el('isolated').disabled = busy || dead;
      if (setupBusy) ['identify', 'monitor', 'disconnect', 'stop-monitor', 'flash', 'release', 'isolated'].forEach(name => { el(name).disabled = true; });
      root.dispatchEvent(new CustomEvent('synapse:bmg-usb-state', { detail: { busy, writing, monitoring } }));
    }
    function alive() { if (dead || lost || !root.isConnected) throw new Error('Workspace left; operation stopped. Partial firmware may be present.'); }
    function isolated() { alive(); if (!el('isolated').checked) throw new Error('Physical motor isolation is required.'); }
    async function consent(message) {
      isolated();
      if (!window.SynapseConfirm?.confirm) throw new Error('Shared safety confirmation is unavailable.');
      const accepted = await window.SynapseConfirm.confirm({ title: 'Motor isolation and reset safety',
        message, confirmLabel: 'Isolation checked — continue', tone: 'danger' });
      isolated();
      return accepted;
    }
    async function closeHardware() {
      if (closing) return closing;
      closing = (async () => {
        monitoring = false;
        if (reader) await reader.cancel().catch(error => log(`\nReader cancellation failed: ${error.message}\n`));
        if (monitorTask) await monitorTask.catch(error => log(`\nMonitor cleanup failed: ${error.message}\n`));
        const connection = transport; transport = null;
        try {
          if (connection) await connection.disconnect();
          else if (port?.readable || port?.writable) await port.close();
        } catch (error) {
          log(`\nUSB disconnect failed: ${error.message}. Check and release the adapter manually.\n`);
          status('USB disconnect failed. Keep motor power isolated; check the adapter manually.');
          throw error;
        }
      })();
      try { await closing; } finally { closing = null; }
    }
    function run(task) {
      if (busy || dead || root.dataset.setupBusy === '1') return;
      busy = true; update();
      operation = (async () => {
        try { await task(); }
        catch (error) {
          clearIdentity();
          status(`${writing ? 'Installation incomplete; partial firmware may be present. ' : ''}${error.message}`);
          log(`\n${error.message}\nKeep motor power isolated. No automatic reboot was requested.\n`);
          try { await closeHardware(); } catch (_) { /* Reported by closeHardware. */ }
        } finally { writing = false; busy = false; update(); }
      })();
    }
    async function connectLoader() {
      clearIdentity();
      const { ESPLoader, Transport } = await import(urls.esptool);
      isolated();
      transport = new Transport(port, false);
      const loader = new ESPLoader({ transport, baudrate: 115200, romBaudrate: 115200,
        terminal: { clean: () => {}, write: log, writeLine: value => log(value + '\n') } });
      // Bound protocol retries themselves; never unlock on a Promise.race timeout.
      loader.WRITE_BLOCK_ATTEMPTS = 3;
      loader.MAX_TIMEOUT = 30000;
      loader.FLASH_READ_TIMEOUT = 5000;
      await loader.detectChip('default_reset', 3);
      isolated();
      if (loader.secureDownloadMode) throw new Error('Secure Download Mode is not supported.');
      const family = loader.chip?.CHIP_NAME;
      const mac = String(await loader.chip.readMac(loader)).toLowerCase();
      if (!/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/.test(mac)) throw new Error('Invalid chip MAC identity.');
      let flash = 'unknown';
      try {
        const id = await loader.readFlashId();
        if (Number.isInteger(id) && ((id >>> 16) & 255) === 0x18) flash = '16MB';
      } catch (_) { log('\nFlash capacity could not be proven; installation is disabled.\n'); }
      isolated();
      identity = { family, mac, flash };
      el('identity').textContent = `${family} · MAC ${mac} · flash ${flash} · bootloader mode; no application reset requested.`;
      return loader;
    }
    async function request(url, options = {}, binarySize = null) {
      const parsed = new URL(url, location.href);
      if (parsed.origin !== location.origin || parsed.search || parsed.hash || parsed.username || parsed.password) throw new Error('Unexpected firmware URL.');
      const controller = new AbortController(); requests.add(controller);
      const timer = setTimeout(() => controller.abort(), 20000);
      try {
        alive();
        const response = await fetch(parsed.href, { ...options, credentials: 'same-origin', cache: 'no-store',
          redirect: 'error', signal: controller.signal, headers: { ...options.headers, 'X-Requested-With': 'XMLHttpRequest' } });
        window.SynapseCsrf?.fromResponse(response);
        if (!response.ok || response.redirected || response.url !== parsed.href) throw new Error('Firmware request denied or redirected.');
        const max = binarySize ?? 65536;
        const stream = response.body.getReader();
        const chunks = []; let size = 0;
        try {
          while (true) {
            const { value, done } = await stream.read(); if (done) break;
            size += value.length;
            if (size > max) { await stream.cancel(); throw new Error('Firmware response exceeds its size bound.'); }
            chunks.push(value);
          }
        } finally { stream.releaseLock(); }
        const bytes = new Uint8Array(size); let offset = 0;
        chunks.forEach(chunk => { bytes.set(chunk, offset); offset += chunk.length; });
        alive();
        if (binarySize !== null) {
          if (size !== binarySize) throw new Error('Firmware part size mismatch.');
          return bytes;
        }
        const json = JSON.parse(new TextDecoder().decode(bytes));
        window.SynapseCsrf?.sync(json.csrf);
        return json;
      } finally { clearTimeout(timer); requests.delete(controller); }
    }
    function manifest(value, release) {
      if (!value || typeof value !== 'object' || Array.isArray(value) || value.id !== release
        || value.chip_family !== 'ESP32-S3' || value.flash_size !== '16MB'
        || Object.keys(value).some(key => !['id', 'name', 'chip_family', 'flash_size', 'parts'].includes(key))
        || typeof value.name !== 'string' || !value.name.length || value.name.length > 100
        || !Array.isArray(value.parts) || !value.parts.length || value.parts.length > 32) throw new Error('Invalid approved manifest.');
      let total = 0; const ranges = [];
      const parts = value.parts.map((part, index) => {
        if (!part || typeof part !== 'object' || Array.isArray(part)
          || Object.keys(part).some(key => !['name', 'address', 'size', 'sha256', 'url'].includes(key))
          || typeof part.url !== 'string') throw new Error('Invalid firmware part metadata.');
        const expectedUrl = `${urls.releases}/${release}/parts/${index}`;
        const url = configuredUrl(part.url);
        if (url.href !== expectedUrl
          || !Number.isSafeInteger(part.address) || part.address < 0 || part.address % 4096 !== 0
          || !Number.isSafeInteger(part.size) || part.size < 1 || part.size > LIMIT
          || part.address + part.size > LIMIT || typeof part.sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(part.sha256)
          || typeof part.name !== 'string' || part.name.length > 100) throw new Error('Invalid firmware part or protected URL.');
        const end = part.address + Math.ceil(part.size / 4096) * 4096;
        if (end > LIMIT || ranges.some(([start, stop]) => part.address < stop && end > start)) throw new Error('Firmware sector erase ranges overlap.');
        ranges.push([part.address, end]); total += part.size;
        if (total > LIMIT) throw new Error('Firmware exceeds 16MB.');
        return { name: part.name, address: part.address, size: part.size, sha256: part.sha256, url: expectedUrl };
      });
      return { id: value.id, name: value.name, chip_family: value.chip_family, flash_size: value.flash_size, parts };
    }
    async function sha(bytes) {
      if (!crypto.subtle) throw new Error('WebCrypto SHA-256 is required.');
      return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), n => n.toString(16).padStart(2, '0')).join('');
    }
    async function install() {
      if (!gate()) throw new Error('Installation safety gates are not satisfied.');
      const release = el('release').value, expected = { ...identity };
      if (!await consent('Installation reopens and resets the controller into its bootloader. Confirm physical motor isolation. No automatic reboot follows installation.')) return;
      const loader = await connectLoader();
      if (!gate() || identity.mac !== expected.mac) throw new Error('Chip identity or safety conditions changed.');
      const initial = manifest(await request(`${urls.releases}/${release}/manifest`), release);
      const files = [];
      for (const part of initial.parts) {
        const data = await request(part.url, {}, part.size);
        if (await sha(data) !== part.sha256) throw new Error('Downloaded firmware SHA-256 mismatch. Nothing was written.');
        files.push({ address: part.address, data });
      }
      isolated();
      if (!gate()) throw new Error('Installation safety conditions changed.');
      if (!window.SynapseCsrf) throw new Error('Shared CSRF service is unavailable.');
      const form = new FormData(root.querySelector('#bmgUsbCsrf'));
      form.set('release_id', release); form.set('chip_family', identity.family); form.set('chip_mac', identity.mac);
      form.set('isolated_confirmed', '1'); form.set('expected_version', root.dataset.expectedVersion);
      const auth = await request(urls.authorize, { method: 'POST', body: form, headers: window.SynapseCsrf.headers() });
      if (auth.success !== true || JSON.stringify(manifest(auth.manifest, release)) !== JSON.stringify(initial)) throw new Error('Authorization denied or approved metadata changed. Nothing was written.');
      isolated();
      // detectChip omits main's postConnect hook, which sizes native USB OTG RAM uploads.
      if (typeof loader.chip.postConnect === 'function') await loader.chip.postConnect(loader);
      isolated();
      // readFlash uses the stub protocol. Upload only after every file and authorization passed.
      await loader.runStub();
      isolated();
      if (loader.secureDownloadMode || !loader.IS_STUB) throw new Error('Readback-capable flasher stub is unavailable.');
      writing = true;
      for (let index = 0; index < files.length; index++) {
        isolated();
        await loader.writeFlash({ fileArray: [files[index]], flashSize: 'keep', flashMode: 'keep', flashFreq: 'keep',
          eraseAll: false, compress: true, reportProgress: (_file, written, total) => {
            const progress = total > 0 ? Math.min(99, Math.floor(100 * (index + written / total) / files.length)) : 0;
            el('progress').value = progress; el('progress-label').textContent = `Writing ${progress}%; readback verification pending.`;
          } });
        isolated();
        const bytes = await loader.readFlash(files[index].address, files[index].data.length);
        if (!(bytes instanceof Uint8Array) || bytes.length !== files[index].data.length || await sha(bytes) !== initial.parts[index].sha256) throw new Error('Flash readback SHA-256 mismatch. Installation is NOT verified.');
      }
      alive();
      await closeHardware(); clearIdentity();
      el('progress').value = 100; el('progress-label').textContent = 'All approved parts verified by SHA-256 readback.';
      status('Installation readback verified. No reboot requested. Check motor isolation before physically resetting the controller.');
      log('\nInstallation readback verified. Physically reset only after checking motor isolation.\n');
    }
    async function startMonitor() {
      if (!await consent('Opening the receive-only serial monitor may reset the controller and restart existing motor firmware. Confirm motor power is physically isolated.')) return;
      clearIdentity(); await closeHardware(); isolated();
      await port.open({ baudRate: 115200 });
      isolated();
      reader = port.readable.getReader(); monitoring = true;
      const decoder = new TextDecoder();
      monitorTask = (async () => {
        try {
          while (monitoring && !dead) {
            const { value, done } = await reader.read(); if (done) break;
            log(decoder.decode(value, { stream: true }));
          }
        } catch (error) { log(`\nSerial receive stopped: ${error.message}\n`); }
        finally {
                  try { reader.releaseLock(); }
                  catch (error) { log(`\nSerial reader release failed: ${error.message}\n`); }
                  finally { reader = null; monitoring = false; update(); }
                }
      })();
      // EOF/disconnection also releases the open port; no writer is ever acquired.
      const finishMonitor = () => {
              if (!closing) run(async () => { await closeHardware(); status('Monitor stopped; port closed. No reset requested.'); });
            };
            monitorTask.then(finishMonitor, error => {
              log(`\nSerial monitor failed: ${error.message}\n`);
              finishMonitor();
            });
      status('Receive-only monitor at 115200 baud. Motor state is not established by serial output.');
    }
    const click = event => {
      const button = event.target.closest('button'); if (!button || button.disabled || root.dataset.setupBusy === '1') return;
      if (button === el('clear')) { el('log').textContent = ''; return; }
      if (button === el('select')) {
        // requestPort must remain directly inside this user gesture, before any await.
        if (busy || monitoring || !supported) return;
        clearIdentity();
        const selected = navigator.serial.requestPort();
        run(async () => {
          const next = await selected; lost = false; alive(); await closeHardware(); port = next;
          const info = port.getInfo();
          const hex = n => Number.isInteger(n) ? '0x' + n.toString(16).padStart(4, '0') : 'unknown';
          status(`USB serial adapter selected: vendor ${hex(info.usbVendorId)}, product ${hex(info.usbProductId)}. Not a chip identity; port remains closed.`);
        });
      } else if (button === el('identify')) run(async () => {
        if (!port || monitoring) return;
        if (!await consent('Identification opens the port and automatically resets into the bootloader. Existing firmware may run during reset. Confirm physical motor isolation.')) return;
        await connectLoader(); await closeHardware();
        status('Identified in bootloader mode; port closed. No application reboot requested. Physically reset only after checking motor isolation.');
      });
      else if (button === el('monitor')) run(startMonitor);
      else if (button === el('stop-monitor') || button === el('disconnect')) run(async () => {
        clearIdentity(); await closeHardware();
        if (button === el('disconnect')) port = null;
        status('USB port closed. No reset requested. Check motor isolation before any physical reset.');
      });
      else if (button === el('flash')) run(install);
    };
    const change = () => { if (!el('isolated').checked) clearIdentity(); update(); };
    const disconnect = event => {
      if (event.target !== port && event.port !== port) return;
      lost = true; clearIdentity(); requests.forEach(controller => controller.abort());
      status('USB device disconnected. Installation may be partial; keep motor power isolated.');
      if (!busy) run(async () => { await closeHardware(); port = null; });
    };
    root.addEventListener('click', click); root.addEventListener('change', change);
    navigator.serial?.addEventListener('disconnect', disconnect);
    clearIdentity();
    el('isolated').checked = false;
    status('No USB port selected. Nothing opens or resets automatically.');
    el('support').textContent = supported
      ? 'Web Serial available. Select a USB serial adapter explicitly; nothing connects automatically.'
      : 'USB unavailable. Use desktop Chrome or Edge in a secure context (HTTPS or localhost) with Web Serial enabled.';
    update();
    return { root, update, get active() { return busy; }, get needsCleanup() { return monitoring || !!reader || !!transport; },
      dispose() {
        if (dead) return operation;
        dead = true; clearIdentity(); el('log').textContent = ''; requests.forEach(controller => controller.abort());
        root.removeEventListener('click', click); root.removeEventListener('change', change);
        navigator.serial?.removeEventListener('disconnect', disconnect);
        // Do not race a live protocol command with a close/reset. Wait for its bounded retries.
        const done = (async () => { if (busy) await operation; try { await closeHardware(); } catch (_) { /* Logged above. */ } port = null; })();
        update(); return done;
      } };
  }
  window.SynapseBmgUsb = {
    initialize,
    canSetup(root) { return current?.root === root && !!current.update && !current.active && !current.needsCleanup && !retiring.size; },
    refresh(root) { if (current?.root === root) current.update?.(); },
    selectBuild(root, release) {
      if (current?.root !== root || !current.update || current.active || current.needsCleanup
        || !release || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(release.id)
        || !['name', 'chip_family', 'flash_size'].every(key => typeof release[key] === 'string' && release[key].length > 0 && release[key].length <= 200)) return false;
      const select = root.querySelector('[data-usb-release]');
      let option = Array.from(select.options).find(item => item.value === release.id);
      if (!option) { option = document.createElement('option'); option.value = release.id; select.append(option); }
      option.textContent = `${release.name} · ${release.chip_family} · ${release.flash_size}`;
      select.value = release.id;
      current.update();
      return true;
    }
  };
  document.addEventListener('synapse:page-load', initialize);
  document.addEventListener('synapse:before-navigate', event => {
      if (current?.active) { event.preventDefault(); return; }
      if (!current?.needsCleanup) return;
      event.preventDefault();
      const outgoing = current;
      outgoing.dispose().then(() => {
        if (current !== outgoing) return;
        current = null;
        // Navigation restores cancelled popstate itself. Do not fabricate its history entry.
        if (!event.detail?.popstate) window.SynapseNavigation?.navigate(event.detail.url);
        else initialize(); // The user can retry Back after the monitor has closed.
      });
    });
  document.addEventListener('synapse:before-swap', () => { retire(current); current = null; });
  document.addEventListener('click', event => {
    if (current?.active && event.target.closest('a[href]')) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, true);
  document.addEventListener('submit', event => {
    if (current?.active) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, true);
  window.addEventListener('beforeunload', event => { if (current?.active) { event.preventDefault(); event.returnValue = ''; } });
  window.addEventListener('pagehide', () => { retire(current); current = null; });
    window.addEventListener('pageshow', initialize);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
  else initialize();
})();

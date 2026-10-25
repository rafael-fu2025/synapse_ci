<script>
(function() {
  if (window.SynapseCarouselForm) return;
  const initialized = new Map();
  function init(root = document) {
  const form = root.querySelector('#slideForm');
  if (!form || initialized.has(form)) return;
  const alertBox = document.getElementById('slideFormAlert');

  const progress = form.querySelector('.carousel-upload-progress');
  const bar = progress.querySelector('.progress-bar');
  const status = progress.querySelector('.dz-status');
  const percent = progress.querySelector('.dz-pct');
  let busy = false;
  let committed = false;
  const urls = new Set();
  initialized.set(form, urls);

  function showAlert(message, success = false) {
    alertBox.textContent = message;
    alertBox.classList.remove('d-none', 'alert-success', 'alert-danger');
    alertBox.classList.add(success ? 'alert-success' : 'alert-danger');
  }
  function localPath(value) {
    const path = value.trim().replace(/\\/g, '/');
    const match = /^(assets\/img\/brand|uploads\/carousel)\/([A-Za-z0-9_-][A-Za-z0-9._-]*(?:\/[A-Za-z0-9_-][A-Za-z0-9._-]*)*)$/.exec(path);
    if (!match || path.length > 255 || (match[1] === 'uploads/carousel' && match[2].includes('/'))) return null;
    return path;
  }
  function setProgress(value) {
    const rounded = Math.min(100, Math.max(0, Math.round(value)));
    bar.style.width = rounded + '%';
    bar.setAttribute('aria-valuenow', String(rounded));
    percent.textContent = rounded + '%';
  }
  function validateImages() {
    let hasLight = false;
    ['light', 'dark'].forEach(function(slot) {
      const input = form.querySelector('#' + slot + '_file');
      const pathField = form.querySelector('#' + slot + '_path');
      const validPath = pathField && localPath(pathField.value);
      const remove = slot === 'dark' && document.getElementById('remove_dark')?.checked;
      if (slot === 'light' && (input.files.length > 0 || validPath)) hasLight = true;

      if (pathField && pathField.value.trim() && !validPath) pathField.setCustomValidity('Choose a safe local image path from an approved directory.');
      else if (pathField) pathField.setCustomValidity('');
      if (remove && pathField) pathField.value = '';
    });
    const light = form.querySelector('#light_file');
    const lightPath = form.querySelector('#light_path');
    const editForm = <?= !empty($slide['id']) ? 'true' : 'false' ?>;
    if (!editForm && !light.files.length && !localPath(lightPath.value)) light.setCustomValidity('Choose an image or enter an approved local image path.');
    else light.setCustomValidity('');
  }
  form.querySelectorAll('#light_path, #dark_path, #remove_dark').forEach(control => {
    control?.addEventListener('input', validateImages);
    control?.addEventListener('change', validateImages);
  });
  form.querySelectorAll('.upload-dropzone').forEach(function(zone) {
    const input = document.getElementById(zone.dataset.input);
    const body = zone.querySelector('.dz-body');
    const preview = zone.querySelector('.dz-preview');
    const current = zone.dataset.current;
    let objectUrl = null;
    zone.tabIndex = 0;
    zone.setAttribute('role', 'button');
    zone.setAttribute('aria-label', input.name === 'light_file' ? 'Choose light mode image' : 'Choose dark mode image');

    function render(src, label, selected) {
      preview.replaceChildren();
      preview.classList.toggle('d-none', !src);
      body.classList.toggle('d-none', Boolean(src));
      if (!src) return;
      const image = document.createElement('img');
      image.src = src;
      image.alt = label;
      image.className = 'dz-thumb';
      const text = document.createElement('div');
      text.className = 'small fw-semibold text-break mt-2';
      text.textContent = label;
      const hint = document.createElement('div');
      hint.className = 'text-muted small';
      hint.textContent = 'Click or drop an image to replace';
      preview.append(image, text, hint);
      if (selected) {
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'btn btn-sm btn-outline-secondary mt-2';
        clear.textContent = 'Undo selection';
        clear.addEventListener('click', function(e) {
          e.stopPropagation();
          if (busy) return;
          input.value = '';
          release();
          render(current, 'Current image', false);
        });
        preview.append(clear);
      }
    }
    function release() {
      if (objectUrl) { URL.revokeObjectURL(objectUrl); urls.delete(objectUrl); objectUrl = null; }
    }
    function selectFile(file) {
      if (busy || !file) return;
      if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) {
        input.value = '';
        showAlert('Choose a PNG, JPG, WEBP, or GIF image.');
        release();
        render(current, 'Current image', false);
        return;
      }
      release();
      objectUrl = URL.createObjectURL(file);
      urls.add(objectUrl);
      render(objectUrl, file.name, true);
      if (input.name === 'dark_file') {
        const remove = document.getElementById('remove_dark');
        if (remove) remove.checked = false;
      }
    }
    zone.addEventListener('click', function(e) {
      if (!busy && e.target !== input) input.click();
    });
    zone.addEventListener('keydown', function(e) {
      if (e.target === zone && (e.key === 'Enter' || e.key === ' ')) {
        e.preventDefault();
        if (!busy) input.click();
      }
    });
    ['dragenter', 'dragover'].forEach(event => zone.addEventListener(event, function(e) {
      e.preventDefault();
      if (!busy) zone.classList.add('drag-over');
    }));
    ['dragleave', 'drop'].forEach(event => zone.addEventListener(event, function(e) {
      e.preventDefault();
      zone.classList.remove('drag-over');
    }));
    zone.addEventListener('drop', function(e) {
      if (busy) return;
      const files = e.dataTransfer.files;
      if (files.length !== 1) { showAlert('Drop one image in each image slot.'); return; }
      const transfer = new DataTransfer();
      transfer.items.add(files[0]);
      input.files = transfer.files;
      selectFile(files[0]);
    });
    input.addEventListener('change', () => { selectFile(input.files[0]); validateImages(); });
    render(current, 'Current image', false);
  });


  form.addEventListener('submit', function(e) {
    e.preventDefault();
    validateImages();
    if (busy || committed || !form.reportValidity()) return;
    const mutations = window.SynapseMutations;

    busy = true;
    alertBox.classList.add('d-none');
    const data = new FormData(form);
    const controls = Array.from(form.elements).filter(control => 'disabled' in control);
    const disabled = controls.map(control => control.disabled);
    const inert = form.inert;
    form.inert = true;
    controls.forEach(control => { control.disabled = true; });
    form.setAttribute('aria-busy', 'true');
    progress.classList.remove('d-none');
    setProgress(0);
    status.textContent = 'Uploading…';
    let saved = false;
    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    if (mutations) xhr.setRequestHeader('X-Synapse-Feedback', 'toast');
    Object.entries(window.SynapseCsrf?.headers() || {}).forEach(([name, value]) => xhr.setRequestHeader(name, value));
    xhr.upload.addEventListener('progress', function(event) {
      if (event.lengthComputable) {
        setProgress(event.loaded / event.total * 100);
        if (event.loaded === event.total) status.textContent = 'Upload sent — saving slide…';
      } else {
        percent.textContent = 'Sending…';
      }
    });
    xhr.addEventListener('load', async function() {
      window.SynapseCsrf?.fromResponse(xhr);
      let payload;
      try { payload = JSON.parse(xhr.responseText); } catch (_) { payload = null; }
      if (payload?.csrf && window.SynapseCsrf) window.SynapseCsrf.sync(payload.csrf);
      else if (payload?.csrf) {
        document.querySelectorAll('input[type="hidden"]').forEach(function(input) {
          if (input.name === payload.csrf.name) input.value = payload.csrf.hash;
        });
        document.querySelector('meta[name="csrf-token"]').content = payload.csrf.hash;
      }
      if (xhr.status >= 200 && xhr.status < 300 && payload?.success === true) {
        saved = true;
        setProgress(100);
        status.textContent = 'Saved';
        showAlert(payload.message, true);
        committed = true;
        busy = false;
        form.removeAttribute('aria-busy');
        let closed = Promise.resolve();
        document.dispatchEvent(new CustomEvent('synapse:modal-saved', {
          detail: { form, done: promise => { closed = promise; } }
        }));
        await closed;
        if (mutations) await mutations.refresh(payload.redirect, payload.message, { form });
        else window.location.assign(payload.redirect);
      } else {
        status.textContent = 'Not saved';
        showAlert(payload?.message || (xhr.status === 413
          ? 'The server rejected the upload size. Check PHP and web-server upload limits.'
          : 'Upload was not saved. Your session may have expired; refresh and try again.'));
      }
    });
    xhr.addEventListener('error', () => {
      status.textContent = 'Upload failed';
      showAlert('Network error. Your image selection is retained so you can retry.');
    });
    xhr.addEventListener('abort', () => { status.textContent = 'Upload cancelled'; });
    xhr.addEventListener('loadend', function() {
      // A committed save must stay locked while the next page is loading;
      // unlocking here would allow a second create request on slow navigation.
      if (saved) return;
      busy = false;
      controls.forEach((control, i) => { control.disabled = disabled[i]; });
      form.inert = inert;
      form.removeAttribute('aria-busy');
    });
    xhr.send(data);
  });
  }
  function destroy(root = document) {
    initialized.forEach((urls, form) => {
      if (root.contains(form)) {
        urls.forEach(url => URL.revokeObjectURL(url));
        initialized.delete(form);
      }
    });
  }
  window.SynapseCarouselForm = { init, destroy };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  else init();
  window.addEventListener('pagehide', () => destroy());
})();
</script>
<style>
.upload-dropzone {
  border: 2px dashed var(--na-border, var(--bs-border-color));
  border-radius: .75rem;
  padding: 1.25rem;
  text-align: center;
  cursor: pointer;
  background: var(--na-card-bg, var(--bs-body-bg));
}
.upload-dropzone:hover, .upload-dropzone.drag-over, .upload-dropzone:focus-visible {
  border-color: var(--bs-primary);
}
.upload-browse-link { color: var(--bs-primary); text-decoration: underline; }
.dz-thumb {
  display: block;
  width: 100%;
  height: 180px;
  object-fit: contain;
  border-radius: .5rem;
}
</style>

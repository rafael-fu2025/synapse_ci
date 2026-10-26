/** Profile modal: local previews, guarded photo writes and explicit draft disposal. */
(function () {
  'use strict';
  if (window.SynapseProfileSettings) return;
  const instances = new Map();
  function init(root = document) {
    for (const [panel, state] of instances) if (!panel.isConnected) state.destroy();
    root.querySelectorAll('[data-profile-settings]').forEach(panel => {
      if (instances.has(panel)) return;
      const form = panel.querySelector('[data-profile-photo-form]');
      const remove = panel.querySelector('[data-profile-photo-remove]');
      const modal = panel.closest('#appFormModal');
      const input = form.querySelector('input[name="photo"]');
      const preview = form.querySelector('[data-profile-photo-preview]');
      const feedback = form.querySelector('[data-profile-photo-feedback]');
      const events = new AbortController();
      let url = null, version = 0, saving = false, asking = false, allowClose = false, controller = null;
      function clear() {
        ++version;
        if (url) URL.revokeObjectURL(url);
        url = null; preview.hidden = true; preview.removeAttribute('src');
      }
      function reject(message) { clear(); input.setCustomValidity(message); feedback.textContent = message; }
      input.addEventListener('change', () => {
        clear(); input.setCustomValidity('');
        const file = input.files?.[0];
        if (!file) { feedback.textContent = 'Choose a new picture to preview. Your saved photo has not changed.'; return; }
        if (file.size < 1 || file.size > 10 * 1024 * 1024) { reject('Choose a picture of 10 MB or less before compression.'); return; }
        if (file.type && !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { reject('Choose a JPEG, PNG, or WebP picture.'); return; }
        const token = version;
        url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
          if (token !== version || !panel.isConnected) return;
          if (image.naturalWidth > 10000 || image.naturalHeight > 10000 || image.naturalWidth * image.naturalHeight > 20000000) {
            reject('Choose a picture of at most 20 megapixels and 10,000 pixels per side.'); return;
          }
          preview.src = url; preview.hidden = false;
          feedback.textContent = `${(file.size / (1024 * 1024)).toFixed(2)} MB selected. Preview only—upload to save the compressed picture.`;
        };
        image.onerror = () => { if (token === version) reject('This picture could not be read. Choose another JPEG, PNG, or WebP file.'); };
        image.src = url;
      }, { signal: events.signal });
      form.addEventListener('reset', () => { clear(); input.setCustomValidity(''); feedback.textContent = 'Your saved photo has not changed.'; }, { signal: events.signal });

      async function save(event) {
        event.preventDefault();
        const owner = event.currentTarget;
        if (saving || asking || !owner.reportValidity()) return;
        const deleting = owner === remove;
        const expected = new URL(deleting ? panel.dataset.removeUrl : panel.dataset.uploadUrl, location.href);
        const action = new URL(owner.action, location.href);
        if (action.origin !== location.origin || action.href !== expected.href || action.username || action.password || action.hash) return;
        const data = new FormData(owner);
        const controls = Array.from(modal?.querySelectorAll('input, button, select, textarea') || panel.querySelectorAll('input, button'));
        const disabled = controls.map(control => control.disabled);
        const box = modal?.querySelector('[data-modal-error]');
        box?.classList.add('d-none');
        saving = true;
        owner.setAttribute('aria-busy', 'true');
        controller = new AbortController();
        const request = controller;
        const timeout = setTimeout(() => request.abort(), 120000);
        const finish = window.SynapseLoading?.begin(owner, { label: deleting ? 'Removing picture…' : 'Compressing and saving…', button: event.submitter }) || (() => {});
        controls.forEach(control => { control.disabled = true; });
        feedback.textContent = deleting ? 'Removing your picture…' : 'Uploading and compressing your picture…';
        try {
          const response = await fetch(action.href, { method: 'POST', body: data, credentials: 'same-origin', redirect: 'error', signal: request.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...window.SynapseCsrf?.headers() } });
          window.SynapseCsrf?.fromResponse(response);
          if (response.redirected || !response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Unexpected save response. Check your saved picture before retrying.');
          const payload = await response.json();
          window.SynapseCsrf?.sync(payload.csrf);
          if (!response.ok || payload.success !== true) throw new Error(typeof payload.message === 'string' ? payload.message.slice(0, 1500) : 'The picture could not be saved. Your selection was kept.');
          if (typeof payload.has_photo !== 'boolean' || payload.has_photo === deleting || typeof payload.photo_url !== 'string') throw new Error('The save response was incomplete. Reload to check your picture before retrying.');
          const fallback = panel.dataset.defaultAvatar;
          let photo = fallback;
          if (payload.has_photo) {
            const destination = new URL(payload.photo_url, location.href);
            const endpoint = new URL(panel.dataset.photoUrl, location.href);
            if (destination.origin !== location.origin || destination.pathname !== endpoint.pathname || destination.username || destination.password || destination.hash) throw new Error('The saved picture address was invalid. Reload to check your picture.');
            photo = destination.href;
          }
          if (!panel.isConnected || request.signal.aborted) return;
          clear(); input.value = ''; input.setCustomValidity('');
          remove.hidden = !payload.has_photo;
          for (const image of [...document.querySelectorAll('[data-profile-avatar]'), panel.querySelector('[data-profile-photo-current]')]) {
            if (!image) continue;
            image.onerror = () => { image.onerror = null; image.src = fallback; };
            image.src = photo;
          }
          feedback.textContent = typeof payload.message === 'string' ? payload.message.slice(0, 1500) : 'Profile picture updated.';
        } catch (error) {
          if (!panel.isConnected) return;
          const message = request.signal.aborted || error instanceof TypeError
            ? 'The save response could not be received. Your selection was kept; the write may have completed. Check your picture before retrying.' : error.message;
          feedback.textContent = message;
          if (box) { box.textContent = message; box.classList.remove('d-none'); }
        } finally {
          clearTimeout(timeout);
          finish(); owner.removeAttribute('aria-busy'); saving = false; controller = null;
          controls.forEach((control, i) => { control.disabled = disabled[i]; });
          remove.querySelector('button[type="submit"]').disabled = remove.hidden;
        }
      }
      form.addEventListener('submit', save, { signal: events.signal });
      remove.addEventListener('submit', save, { signal: events.signal });
      modal?.addEventListener('hide.bs.modal', event => {
        if (event.target !== modal) return;
        if (saving) { event.preventDefault(); return; }
        if (allowClose) { allowClose = false; return; }
        if (!input.files?.length) return;
        event.preventDefault();
        if (asking) return;
        asking = true;
        const token = version;
        const message = 'You selected a new profile picture but have not uploaded it. Discard this selection and close?';
        const confirmation = window.SynapseConfirm?.confirm({ title: 'Unsaved profile picture', message, confirmLabel: 'Discard and close', tone: 'warning' }) ?? Promise.resolve(window.confirm(message));
        Promise.resolve(confirmation).then(approved => {
          if (approved && token === version && panel.isConnected && !saving) {
            allowClose = true;
            modal.querySelector('[data-app-modal-close]')?.click();
          }
        }).catch(() => { /* Confirmation failure keeps the selected picture. */ }).finally(() => { asking = false; });
      }, { signal: events.signal });
      instances.set(panel, { destroy() { clear(); controller?.abort(); events.abort(); instances.delete(panel); } });
    });

    root.querySelectorAll('[data-emergency-contact-form]').forEach(form => {
      if (form.dataset.emergencyBound === 'true') return;
      form.dataset.emergencyBound = 'true';
      const feedback = form.querySelector('[data-emergency-contact-feedback]');
      const submitBtn = form.querySelector('button[type="submit"]');
      let saving = false;

      form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saving || !form.reportValidity()) return;
        const action = new URL(form.action, location.href);
        if (action.origin !== location.origin || action.username || action.password || action.hash) return;
        saving = true;
        if (submitBtn) submitBtn.disabled = true;
        form.setAttribute('aria-busy', 'true');
        const finish = window.SynapseLoading?.begin(form, { label: 'Saving…', button: submitBtn }) || (() => {});
        if (feedback) {
          feedback.textContent = 'Saving emergency contact…';
          feedback.className = 'small text-muted mb-0';
        }
        try {
          const data = new FormData(form);
          const response = await fetch(action.href, {
            method: 'POST', body: data, credentials: 'same-origin', redirect: 'error',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...window.SynapseCsrf?.headers() }
          });
          window.SynapseCsrf?.fromResponse(response);
          const payload = await response.json();
          window.SynapseCsrf?.sync(payload.csrf);
          if (!response.ok || payload.success !== true) {
            throw new Error(typeof payload.message === 'string' ? payload.message : 'Unable to save emergency contact.');
          }
          if (feedback) {
            feedback.textContent = payload.message || 'Emergency contact updated.';
            feedback.className = 'small text-success mb-0';
          }
          const contact = payload.emergency_contact || {};
          const card = document.querySelector('[data-portal-emergency-card]');
          if (card) {
            const hasInfo = Boolean(contact.emergency_contact_name || contact.emergency_contact_phone);
            const content = card.querySelector('[data-portal-emergency-content]');
            if (content) {
              const esc = str => String(str || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
              if (hasInfo) {
                const name = esc(contact.emergency_contact_name || '—');
                const rel = esc(contact.emergency_contact_relationship || '');
                const phone = esc(contact.emergency_contact_phone || '');
                const cleanPhone = esc(phone.replace(/[^\d+]/g, ''));
                content.innerHTML = `<dl class="row mb-0 small">
                  <dt class="col-5 text-muted fw-normal">Contact:</dt>
                  <dd class="col-7 fw-semibold mb-1 text-break">${name}</dd>
                  ${rel ? `<dt class="col-5 text-muted fw-normal">Relationship:</dt><dd class="col-7 mb-1 text-break">${rel}</dd>` : ''}
                  ${phone ? `<dt class="col-5 text-muted fw-normal">Phone:</dt><dd class="col-7 mb-0 text-break"><a href="tel:${cleanPhone}" class="text-decoration-none">${phone}</a></dd>` : ''}
                </dl>`;
              } else {
                content.innerHTML = `<p class="small text-muted mb-2">No emergency contact recorded yet. Add one so clinic staff know who to reach in an emergency.</p>
                <a href="${location.origin}/portal/settings" class="btn btn-outline-secondary btn-sm" data-app-modal-open>
                  <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Add emergency contact
                </a>`;
              }
            }
          }
        } catch (err) {
          if (feedback) {
            feedback.textContent = err.message || 'Unable to save emergency contact.';
            feedback.className = 'small text-danger mb-0';
          }
        } finally {
          finish();
          form.removeAttribute('aria-busy');
          saving = false;
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    });
  }
  function destroy(root) {
    for (const [panel, state] of instances) if (root === panel || root.contains(panel)) state.destroy();
  }
  document.addEventListener('synapse:before-swap', event => { if (event.detail?.root) destroy(event.detail.root); });
  document.addEventListener('synapse:page-load', event => init(event.detail?.root || document));
  window.addEventListener('pagehide', () => { for (const state of instances.values()) state.destroy(); });
  window.addEventListener('pageshow', () => init());
  window.SynapseProfileSettings = { init, destroy };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true }); else init();
})();

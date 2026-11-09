/** Profile settings: explicit saves, independent drafts and page-owned previews. */
(function () {
  'use strict';
  if (window.SynapseProfileSettings || !window.fetch || !window.AbortController) return;
  const instances = new Map();
  const contactNames = ['emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone'];

  function init(root = document) {
    for (const [panel, state] of instances) if (!panel.isConnected) state.destroy();
    const panels = [...(root.matches?.('[data-profile-settings]') ? [root] : []), ...root.querySelectorAll('[data-profile-settings]')];
    panels.forEach(panel => {
      if (instances.has(panel)) return;
      const form = panel.querySelector('[data-profile-photo-form]');
      const remove = panel.querySelector('[data-profile-photo-remove]');
      const contact = panel.querySelector('[data-emergency-contact-form]');
      if (!form || !remove || !contact) return;
      const input = form.querySelector('input[name="photo"]');
      const preview = form.querySelector('[data-profile-photo-preview]');
      const feedback = form.querySelector('[data-profile-photo-feedback]');
      const contactFeedback = contact.querySelector('[data-emergency-contact-feedback]');
      const clearButton = panel.querySelector('[data-profile-photo-clear]');
      const fields = contactNames.map(name => contact.elements.namedItem(name));
      const events = new AbortController();
      let baseline = fields.map(field => field.defaultValue);
      let url = null, version = 0, pending = null, stopped = false, suspended = false, cleanTimer = null;
      const photoDirty = () => Boolean(input.files?.length);
      const contactDirty = () => fields.some((field, index) => field.value !== baseline[index]);
      const active = () => !stopped && !suspended && panel.isConnected;

      function markClean() {
        if (!photoDirty()) window.SynapseNavigation?.markSaved(form);
        if (!contactDirty()) window.SynapseNavigation?.markSaved(contact);
        if (clearButton) clearButton.hidden = !photoDirty();
      }
      const deferred = [];
      function afterSharedListener(callback = () => {}) {
        deferred.push(callback);
        if (cleanTimer !== null) return;
        // The document's bubbling listener records POST edits after our form listeners.
        cleanTimer = setTimeout(() => {
          cleanTimer = null;
          const callbacks = deferred.splice(0);
          if (active()) { callbacks.forEach(run => run()); markClean(); }
        }, 0);
      }
      function clearPreview() {
        ++version;
        if (url) URL.revokeObjectURL(url);
        url = null; preview.hidden = true; preview.removeAttribute('src');
      }
      function photoMessage(message, error = false) {
        feedback.textContent = message;
        feedback.className = `small ${error ? 'text-danger' : 'text-muted'}`;
      }
      function contactMessage(message, tone = 'muted') {
        contactFeedback.textContent = message;
        contactFeedback.className = `small text-${tone} mb-0`;
      }
      function previewSelection(announce = true) {
        clearPreview(); input.setCustomValidity('');
        if (clearButton) clearButton.hidden = !photoDirty();
        const file = input.files?.[0];
        if (!file) {
          if (announce) photoMessage('Choose a new picture to preview. Your saved photo has not changed.');
          return;
        }
        function reject(message) { clearPreview(); input.setCustomValidity(message); photoMessage(message, true); }
        if (file.size < 1 || file.size > 10 * 1024 * 1024) { reject('Choose a picture of 10 MB or less before compression.'); return; }
        if (file.type && !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { reject('Choose a JPEG, PNG, or WebP picture.'); return; }
        const token = version;
        url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
          if (token !== version || !active()) return;
          if (image.naturalWidth > 10000 || image.naturalHeight > 10000 || image.naturalWidth * image.naturalHeight > 20000000) {
            reject('Choose a picture of at most 20 megapixels and 10,000 pixels per side.'); return;
          }
          preview.src = url; preview.hidden = false;
          if (announce) photoMessage(`${(file.size / (1024 * 1024)).toFixed(2)} MB selected. Preview only—upload to save the compressed picture.`);
        };
        image.onerror = () => { if (token === version && active()) reject('This picture could not be read. Choose another JPEG, PNG, or WebP file.'); };
        image.src = url;
      }
      input.addEventListener('change', () => { previewSelection(); afterSharedListener(); }, { signal: events.signal });
      form.addEventListener('reset', event => {
        afterSharedListener(() => { if (!event.defaultPrevented) previewSelection(); });
      }, { signal: events.signal });
      function updateContactDraft() {
        contactMessage(contactDirty() ? 'Unsaved changes. Save emergency contact to keep them.' : 'Saved contact details restored.');
        afterSharedListener();
      }
      contact.addEventListener('input', updateContactDraft, { signal: events.signal });
      contact.addEventListener('change', updateContactDraft, { signal: events.signal });
      contact.addEventListener('reset', event => {
        afterSharedListener(() => { if (!event.defaultPrevented) contactMessage('Saved contact details restored.'); });
      }, { signal: events.signal });

      function guardNavigation(event) {
        if (!active()) return;
        markClean();
        if (pending) {
          event.preventDefault();
          const message = 'Please wait for the current save to finish before leaving this page.';
          if (pending.owner === contact) contactMessage(message); else photoMessage(message);
          return;
        }
        // Browser-restored values can be dirty without having dispatched an input event.
        if ((photoDirty() || contactDirty()) && !window.SynapseNavigation?.hasUnsavedChanges() && !window.confirm('You have unsaved changes. Leave this page?')) event.preventDefault();
      }
      function guardUnload(event) {
        if (!active() || (!pending && !photoDirty() && !contactDirty())) return;
        event.preventDefault();
        event.returnValue = '';
      }
      document.addEventListener('synapse:before-navigate', guardNavigation, { signal: events.signal });
      window.addEventListener('beforeunload', guardUnload, { signal: events.signal });

      async function save(event) {
        event.preventDefault();
        const owner = event.currentTarget;
        const editingContact = owner === contact;
        const deleting = owner === remove;
        const tell = (message, error = false) => editingContact ? contactMessage(message, error ? 'danger' : 'muted') : photoMessage(message, error);
        if (!active() || pending || (deleting && remove.hidden)) return;
        if (document.body.classList.contains('is-navigating')) {
          tell('Please wait for page navigation to finish before saving.'); return;
        }
        if (!owner.reportValidity()) return;
        let action, expected;
        try {
          expected = new URL(editingContact ? panel.dataset.emergencyContactUrl : deleting ? panel.dataset.removeUrl : panel.dataset.uploadUrl, location.href);
          action = new URL(event.submitter?.getAttribute('formaction') || owner.action, location.href);
        } catch (_) { tell('The save address was invalid. Reload this page before trying again.', true); return; }
        const method = event.submitter?.getAttribute('formmethod') || owner.method;
        const target = event.submitter?.getAttribute('formtarget') || owner.target;
        if (method.toUpperCase() !== 'POST' || (target && target !== '_self') || action.origin !== location.origin || action.href !== expected.href || action.username || action.password || action.hash) {
          tell('This action must be submitted to a valid page on this site.', true); return;
        }
        const data = new FormData(owner);
        const submitted = fields.map(field => field.value);
        const selected = input.files?.[0];
        const selectionVersion = version;
        const controls = [...panel.querySelectorAll('input, button, select, textarea')].map(control => [control, control.disabled]);
        const job = { owner, controller: new AbortController(), finish: () => {} };
        pending = job;
        owner.setAttribute('aria-busy', 'true');
        let finishLoading = () => {};
        try {
          finishLoading = window.SynapseLoading?.begin(owner, { label: editingContact ? 'Saving…' : deleting ? 'Removing picture…' : 'Compressing and saving…', button: event.submitter }) || finishLoading;
        } catch (_) { /* Inline feedback remains available without the loading UI. */ }
        controls.forEach(([control]) => { control.disabled = true; });
        tell(editingContact ? 'Saving emergency contact…' : deleting ? 'Removing your picture…' : 'Uploading and compressing your picture…');
        const timeout = setTimeout(() => job.controller.abort(), editingContact ? 45000 : 120000);
        let finished = false;
        job.finish = () => {
          if (finished) return;
          finished = true;
          clearTimeout(timeout);
          finishLoading(); owner.removeAttribute('aria-busy');
          controls.forEach(([control, disabled]) => { control.disabled = disabled; });
          remove.querySelector('button[type="submit"]').disabled = remove.hidden;
          if (pending === job) pending = null;
        };
        const current = () => active() && pending === job && !job.controller.signal.aborted;
        try {
          const response = await fetch(action.href, { method: 'POST', body: data, credentials: 'same-origin', redirect: 'error', signal: job.controller.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...window.SynapseCsrf?.headers() } });
          if (!current()) return;
          if (window.SynapseCsrf?.fromResponse(response)) return;
          if (response.redirected || !response.headers.get('Content-Type')?.toLowerCase().includes('application/json')) throw new Error('Unexpected save response. Check your saved details before retrying.');
          const payload = await response.json();
          if (!current()) return;
          window.SynapseCsrf?.sync(payload?.csrf);
          if (!response.ok || payload?.success !== true) throw new Error(typeof payload?.message === 'string' ? payload.message.slice(0, 1500) : 'The change could not be saved. Your edits were kept.');
          if (editingContact) {
            const saved = payload.emergency_contact;
            if (!saved || contactNames.some((name, index) => saved[name] !== null && (typeof saved[name] !== 'string' || [...saved[name]].length > Number(fields[index].maxLength)))) {
              throw new Error('The save response was incomplete. Reload to check your contact details before retrying.');
            }
            const unchanged = fields.every((field, index) => field.value === submitted[index]);
            baseline = contactNames.map(name => saved[name] ?? '');
            fields.forEach((field, index) => {
              if (unchanged) field.value = baseline[index];
              field.defaultValue = baseline[index];
            });
            contactMessage(typeof payload.message === 'string' ? payload.message.slice(0, 1500) : 'Emergency contact updated.', 'success');
          } else {
            if (typeof payload.has_photo !== 'boolean' || payload.has_photo === deleting || typeof payload.photo_url !== 'string') throw new Error('The save response was incomplete. Reload to check your picture before retrying.');
            const fallback = panel.dataset.defaultAvatar;
            let photo = fallback;
            if (payload.has_photo) {
              const destination = new URL(payload.photo_url, location.href);
              const endpoint = new URL(panel.dataset.photoUrl, location.href);
              if (destination.origin !== location.origin || destination.pathname !== endpoint.pathname || destination.username || destination.password || destination.hash) throw new Error('The saved picture address was invalid. Reload to check your picture.');
              photo = destination.href;
            }
            if (selectionVersion === version && selected === input.files?.[0]) {
              clearPreview(); input.value = ''; input.setCustomValidity('');
            }
            remove.hidden = !payload.has_photo;
            for (const image of [...document.querySelectorAll('[data-profile-avatar]'), panel.querySelector('[data-profile-photo-current]')]) {
              if (!image) continue;
              image.onerror = () => { image.onerror = null; image.src = fallback; };
              image.src = photo;
            }
            photoMessage(typeof payload.message === 'string' ? payload.message.slice(0, 1500) : 'Profile picture updated.');
            window.SynapseNavigation?.markSaved(remove);
          }
          markClean();
        } catch (error) {
          if (!active() || pending !== job) return;
          const message = job.controller.signal.aborted || error instanceof TypeError
            ? 'The save response could not be received. Your edits were kept; the write may have completed. Check your saved details before retrying.' : error.message;
          tell(message, true);
        } finally {
          job.finish();
        }
      }
      form.addEventListener('submit', save, { signal: events.signal });
      remove.addEventListener('submit', save, { signal: events.signal });
      contact.addEventListener('submit', save, { signal: events.signal });
      instances.set(panel, {
        suspend() {
          suspended = true;
          clearTimeout(cleanTimer); cleanTimer = null; deferred.length = 0; clearPreview();
          if (pending) {
            if (pending.owner === contact) contactMessage('The save was interrupted and may have completed. Check your saved contact details before retrying.', 'danger');
            else photoMessage('The save was interrupted and may have completed. Check your saved picture before retrying.', true);
            pending.controller.abort(); pending.finish();
          }
        },
        resume() {
          if (!suspended || stopped) return;
          suspended = false;
          previewSelection(false); markClean();
        },
        destroy() {
          stopped = true;
          // Incoming scripts can load before the outgoing main is detached.
          panel.inert = true;
          panel.addEventListener('submit', event => { event.preventDefault(); event.stopImmediatePropagation(); }, true);
          clearTimeout(cleanTimer); cleanTimer = null; deferred.length = 0; clearPreview();
          pending?.controller.abort(); pending?.finish();
          events.abort(); instances.delete(panel);
        }
      });
      if (photoDirty()) previewSelection(false);
      markClean();
    });
  }
  function destroy(root) {
    for (const [panel, state] of instances) if (root === panel || root.contains(panel)) state.destroy();
  }
  document.addEventListener('synapse:before-swap', event => { if (event.detail?.root) destroy(event.detail.root); });
  document.addEventListener('synapse:page-load', event => init(event.detail?.root || document));
  window.addEventListener('pagehide', event => { for (const state of instances.values()) event.persisted ? state.suspend() : state.destroy(); });
  window.addEventListener('pageshow', () => { for (const state of instances.values()) state.resume(); init(); });
  window.SynapseProfileSettings = { init, destroy };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true }); else init();
})();

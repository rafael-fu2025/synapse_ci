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
      const adjuster = form.querySelector('[data-profile-photo-adjuster]');
      const stage = form.querySelector('[data-profile-photo-stage]');
      const stageImg = form.querySelector('[data-profile-photo-stage-img]');
      const zoomInput = form.querySelector('[data-profile-photo-zoom]');
      const zoomOut = form.querySelector('[data-profile-photo-zoom-out]');
      const zoomIn = form.querySelector('[data-profile-photo-zoom-in]');
      const zoomValue = form.querySelector('[data-profile-photo-zoom-value]');
      const adjustReset = form.querySelector('[data-profile-photo-adjust-reset]');
      const fields = contactNames.map(name => contact.elements.namedItem(name));
      const events = new AbortController();
      let baseline = fields.map(field => field.defaultValue);
      let url = null, version = 0, pending = null, stopped = false, suspended = false, cleanTimer = null;
      let crop = { image: null, width: 0, height: 0, zoom: 1, offsetX: 0, offsetY: 0, adjusted: false };
      let drag = null;
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
      const stageSize = () => (stage?.clientWidth > 0 ? stage.clientWidth : 220);
      function clampCrop() {
        if (!crop.image) return;
        const size = stageSize();
        const baseScale = Math.max(size / crop.width, size / crop.height);
        crop.zoom = Math.max(1, Math.min(3, crop.zoom));
        const scale = baseScale * crop.zoom;
        const maxX = Math.max(0, (crop.width * scale - size) / 2);
        const maxY = Math.max(0, (crop.height * scale - size) / 2);
        crop.offsetX = Math.max(-maxX, Math.min(maxX, crop.offsetX));
        crop.offsetY = Math.max(-maxY, Math.min(maxY, crop.offsetY));
      }
      function renderCrop() {
        if (!crop.image) return;
        clampCrop();
        const size = stageSize();
        const baseScale = Math.max(size / crop.width, size / crop.height);
        const w = crop.width * baseScale;
        const h = crop.height * baseScale;
        if (stageImg) {
          stageImg.style.width = `${w}px`;
          stageImg.style.height = `${h}px`;
          stageImg.style.transform = `translate(-50%, -50%) translate(${crop.offsetX}px, ${crop.offsetY}px) scale(${crop.zoom})`;
        }
        const previewFrameSize = preview.parentElement?.clientWidth || 84;
        const ratio = previewFrameSize / size;
        preview.classList.add('is-framed');
        preview.style.width = `${w * ratio}px`;
        preview.style.height = `${h * ratio}px`;
        preview.style.transform = `translate(-50%, -50%) translate(${crop.offsetX * ratio}px, ${crop.offsetY * ratio}px) scale(${crop.zoom})`;
        if (zoomInput) zoomInput.value = crop.zoom.toFixed(2);
        if (zoomValue) zoomValue.textContent = `${Math.round(crop.zoom * 100)}%`;
        if (zoomOut) zoomOut.disabled = crop.zoom <= 1.001;
        if (zoomIn) zoomIn.disabled = crop.zoom >= 2.999;
        if (adjustReset) adjustReset.disabled = !crop.adjusted;
      }
      function resetCrop() {
        crop.zoom = 1;
        crop.offsetX = 0;
        crop.offsetY = 0;
        crop.adjusted = false;
        renderCrop();
      }
      function clearPreview() {
        ++version;
        drag = null;
        stage?.classList.remove('is-dragging');
        crop = { image: null, width: 0, height: 0, zoom: 1, offsetX: 0, offsetY: 0, adjusted: false };
        if (url) URL.revokeObjectURL(url);
        url = null;
        preview.hidden = true;
        preview.removeAttribute('src');
        preview.classList.remove('is-framed');
        preview.style.width = preview.style.height = preview.style.transform = '';
        if (stageImg) {
          stageImg.removeAttribute('src');
          stageImg.style.width = stageImg.style.height = stageImg.style.transform = '';
        }
        if (adjuster) adjuster.hidden = true;
      }
      function photoMessage(message, error = false) {
        feedback.textContent = message;
        feedback.className = `small ${error ? 'text-danger' : 'text-muted'}`;
      }
      function contactMessage(message, tone = 'muted') {
        const text = typeof message === 'string' ? message.trim() : '';
        const hasToast = typeof window.SynapseToast?.show === 'function';
        if (!text || hasToast) {
          contactFeedback.textContent = '';
          contactFeedback.className = 'small text-muted mb-0';
          contactFeedback.hidden = true;
          if (text && hasToast && (tone === 'success' || tone === 'danger')) {
            window.SynapseToast.show(text, {
              type: tone === 'success' ? 'success' : 'error',
              key: 'emergency-contact',
            });
          }
          return;
        }
        contactFeedback.textContent = text;
        contactFeedback.className = `small text-${tone} mb-0`;
        contactFeedback.hidden = false;
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
          crop = { image, width: image.naturalWidth, height: image.naturalHeight, zoom: 1, offsetX: 0, offsetY: 0, adjusted: false };
          preview.src = url; preview.hidden = false;
          if (adjuster && stageImg) {
            stageImg.src = url;
            adjuster.hidden = false;
            renderCrop();
          }
          if (announce) photoMessage(`${(file.size / (1024 * 1024)).toFixed(2)} MB selected. Adjust framing if needed—upload to save the compressed 1:1 picture.`);
        };
        image.onerror = () => { if (token === version && active()) reject('This picture could not be read. Choose another JPEG, PNG, or WebP file.'); };
        image.src = url;
      }
      if (stage) {
        stage.addEventListener('pointerdown', event => {
          if (!crop.image || event.button !== 0 || adjuster?.hidden) return;
          drag = { id: event.pointerId, startX: event.clientX, startY: event.clientY, baseX: crop.offsetX, baseY: crop.offsetY };
          stage.classList.add('is-dragging');
          stage.setPointerCapture?.(event.pointerId);
          event.preventDefault();
        }, { signal: events.signal });
        stage.addEventListener('pointermove', event => {
          if (!drag || drag.id !== event.pointerId) return;
          const dx = event.clientX - drag.startX;
          const dy = event.clientY - drag.startY;
          if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5) crop.adjusted = true;
          crop.offsetX = drag.baseX + dx;
          crop.offsetY = drag.baseY + dy;
          renderCrop();
        }, { signal: events.signal });
        const endDrag = event => {
          if (!drag || (event.pointerId !== undefined && drag.id !== event.pointerId)) return;
          drag = null;
          stage.classList.remove('is-dragging');
        };
        stage.addEventListener('pointerup', endDrag, { signal: events.signal });
        stage.addEventListener('pointercancel', endDrag, { signal: events.signal });
        stage.addEventListener('wheel', event => {
          if (!crop.image || adjuster?.hidden) return;
          event.preventDefault();
          crop.zoom = Math.max(1, Math.min(3, crop.zoom + (event.deltaY < 0 ? 0.1 : -0.1)));
          crop.adjusted = true;
          renderCrop();
        }, { passive: false, signal: events.signal });
        stage.addEventListener('keydown', event => {
          if (!crop.image || adjuster?.hidden) return;
          const step = event.shiftKey ? 2 : 10;
          if (event.key === 'ArrowLeft') { crop.offsetX -= step; crop.adjusted = true; }
          else if (event.key === 'ArrowRight') { crop.offsetX += step; crop.adjusted = true; }
          else if (event.key === 'ArrowUp') { crop.offsetY -= step; crop.adjusted = true; }
          else if (event.key === 'ArrowDown') { crop.offsetY += step; crop.adjusted = true; }
          else if (event.key === '+' || event.key === '=') { crop.zoom = Math.min(3, crop.zoom + 0.1); crop.adjusted = true; }
          else if (event.key === '-') { crop.zoom = Math.max(1, crop.zoom - 0.1); crop.adjusted = true; }
          else return;
          event.preventDefault();
          renderCrop();
        }, { signal: events.signal });
      }
      zoomInput?.addEventListener('input', () => {
        if (!crop.image) return;
        crop.zoom = Number(zoomInput.value) || 1;
        crop.adjusted = true;
        renderCrop();
      }, { signal: events.signal });
      zoomOut?.addEventListener('click', () => {
        if (!crop.image) return;
        crop.zoom = Math.max(1, crop.zoom - 0.2);
        crop.adjusted = true;
        renderCrop();
      }, { signal: events.signal });
      zoomIn?.addEventListener('click', () => {
        if (!crop.image) return;
        crop.zoom = Math.min(3, crop.zoom + 0.2);
        crop.adjusted = true;
        renderCrop();
      }, { signal: events.signal });
      adjustReset?.addEventListener('click', () => {
        if (crop.image) resetCrop();
      }, { signal: events.signal });
      window.addEventListener('resize', () => {
        if (crop.image && adjuster && !adjuster.hidden) renderCrop();
      }, { signal: events.signal });
      input.addEventListener('change', () => { previewSelection(); afterSharedListener(); }, { signal: events.signal });
      form.addEventListener('reset', event => {
        afterSharedListener(() => { if (!event.defaultPrevented) previewSelection(); });
      }, { signal: events.signal });
      function updateContactDraft() {
        contactMessage('');
        afterSharedListener();
      }
      contact.addEventListener('input', updateContactDraft, { signal: events.signal });
      contact.addEventListener('change', updateContactDraft, { signal: events.signal });
      contact.addEventListener('reset', event => {
        afterSharedListener(() => { if (!event.defaultPrevented) contactMessage(''); });
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
        if ((photoDirty() || contactDirty()) && !window.SynapseNavigation?.hasUnsavedChanges()) {
          if (event.detail && typeof event.detail === 'object') event.detail.dirty = true;
          else if (!window.confirm('You have unsaved changes. Leave this page?')) event.preventDefault();
        }
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
        const cropSnapshot = (!editingContact && !deleting && crop.image && (crop.width !== crop.height || crop.adjusted))
          ? { image: crop.image, width: crop.width, height: crop.height, zoom: crop.zoom, offsetX: crop.offsetX, offsetY: crop.offsetY, stage: stageSize() }
          : null;
        const controls = [...panel.querySelectorAll('input, button, select, textarea')].map(control => [control, control.disabled]);
        const job = { owner, controller: new AbortController(), finish: () => {} };
        pending = job;
        owner.setAttribute('aria-busy', 'true');
        let finishLoading = () => {};
        try {
          finishLoading = window.SynapseLoading?.begin(owner, { label: editingContact ? 'Saving…' : deleting ? 'Removing picture…' : 'Compressing and saving…', button: event.submitter }) || finishLoading;
        } catch (_) { /* Inline feedback remains available without the loading UI. */ }
        controls.forEach(([control]) => { control.disabled = true; });
        if (editingContact) contactMessage('');
        else tell(deleting ? 'Removing your picture…' : 'Uploading and compressing your picture…');
        const timeout = setTimeout(() => job.controller.abort(), editingContact ? 45000 : 120000);
        let finished = false;
        job.finish = () => {
          if (finished) return;
          finished = true;
          clearTimeout(timeout);
          finishLoading(); owner.removeAttribute('aria-busy');
          controls.forEach(([control, disabled]) => { control.disabled = disabled; });
          remove.querySelector('button[type="submit"]').disabled = remove.hidden;
          if (crop.image) renderCrop();
          if (pending === job) pending = null;
        };
        const current = () => active() && pending === job && !job.controller.signal.aborted;
        try {
          if (cropSnapshot) {
            const baseScale = Math.max(cropSnapshot.stage / cropSnapshot.width, cropSnapshot.stage / cropSnapshot.height);
            const scale = baseScale * cropSnapshot.zoom;
            const srcSize = Math.min(cropSnapshot.width, cropSnapshot.height, cropSnapshot.stage / scale);
            const srcX = Math.max(0, Math.min(cropSnapshot.width - srcSize, ((cropSnapshot.width * scale - cropSnapshot.stage) / 2 - cropSnapshot.offsetX) / scale));
            const srcY = Math.max(0, Math.min(cropSnapshot.height - srcSize, ((cropSnapshot.height * scale - cropSnapshot.stage) / 2 - cropSnapshot.offsetY) / scale));
            const outSize = Math.max(1, Math.min(1024, Math.round(srcSize)));
            const canvas = document.createElement('canvas');
            canvas.width = outSize;
            canvas.height = outSize;
            const ctx = canvas.getContext('2d');
            if (ctx) {
              ctx.drawImage(cropSnapshot.image, srcX, srcY, srcSize, srcSize, 0, 0, outSize, outSize);
              const encode = type => new Promise(resolve => canvas.toBlob(resolve, type, 0.92));
              let blob = await encode('image/webp');
              if (!blob || !['image/webp', 'image/png', 'image/jpeg'].includes(blob.type)) blob = await encode('image/png');
              if (!current()) return;
              if (blob && blob.size > 0 && blob.size <= 10 * 1024 * 1024) {
                const ext = blob.type === 'image/webp' ? 'webp' : 'png';
                const base = (selected?.name || 'profile').replace(/\.[^.]+$/, '');
                data.set('photo', blob, `${base}.${ext}`);
              }
            }
          }
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
            document.dispatchEvent(new CustomEvent('synapse:emergency-contact-updated', { detail: saved }));
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
            const savedMessage = typeof payload.message === 'string' ? payload.message.slice(0, 1500) : (deleting ? 'Profile picture removed.' : 'Profile picture updated.');
            photoMessage(savedMessage);
            if (typeof window.SynapseToast?.show === 'function') {
              window.SynapseToast.show(savedMessage, { type: 'success', key: 'profile-photo' });
            }
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

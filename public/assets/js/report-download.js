/** Receive bounded report attachments before handing them to the browser's save flow. */
(function () {
  'use strict';
  if (window.SynapseReportDownloads) return;
  const MAX_BYTES = 8 * 1024 * 1024;
  let active = null;
  let previousPanel = null;
  const objectUrls = new Set();

  function panel() {
    previousPanel?.remove();
    const root = document.createElement('section');
    root.className = 'report-download-panel';
    root.setAttribute('aria-label', 'Report download');
    const heading = document.createElement('strong');
    heading.textContent = 'Preparing report';
    const message = document.createElement('p');
    message.className = 'report-download-message';
    message.setAttribute('role', 'status');
    message.setAttribute('aria-live', 'polite');
    message.textContent = 'Waiting for the server to generate your file…';
    const progress = document.createElement('progress');
    progress.max = 100;
    progress.setAttribute('aria-label', 'Report file transfer progress');
    const detail = document.createElement('small');
    detail.textContent = 'The browser save starts after the file is fully received.';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-outline-secondary btn-sm';
    button.textContent = 'Cancel';
    root.append(heading, message, progress, detail, button);
    document.body.append(root);
    previousPanel = root;
    return { root, heading, message, progress, detail, button };
  }

  function release(url) {
    URL.revokeObjectURL(url);
    objectUrls.delete(url);
  }

  async function receive(response, job, limit, expectedLength) {
    if (!response.body?.getReader) throw new Error('This browser cannot receive report downloads. Please use a current browser.');
    const reader = response.body.getReader();
    const chunks = [];
    let received = 0;
    let updated = 0;
    try {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        received += value.byteLength;
        if (received > limit) throw new Error('The report exceeds the download size limit. Choose a smaller date range.');
        chunks.push(value);
        if (response.ok && performance.now() - updated > 120) {
          updated = performance.now();
          const kb = Math.ceil(received / 1024);
          if (expectedLength > 0) {
            // Content-Length may describe compressed bytes. Only EOF establishes completion.
            const percent = Math.min(99, Math.floor(received / expectedLength * 100));
            job.ui.progress.value = percent;
            job.ui.message.textContent = `Receiving file… ${percent}%`;
          } else {
            job.ui.message.textContent = `Receiving file… ${kb.toLocaleString()} KB received`;
          }
        }
      }
      return new Blob(chunks);
    } catch (error) {
      await reader.cancel().catch(() => {});
      throw error;
    } finally {
      reader.releaseLock();
    }
  }

  async function start(form, submitter) {
    const ui = panel();
    const job = { form, ui, controller: new AbortController(), disposed: false, cancelled: false, timedOut: false };
    active = job;
    const controls = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])'))
      .map(button => [button, button.disabled]);
    let finish = () => {};
    const timeout = setTimeout(() => {
      job.timedOut = true;
      job.controller.abort();
    }, 120000);
    ui.button.addEventListener('click', () => {
      if (active === job) {
        job.cancelled = true;
        job.controller.abort();
      } else ui.root.remove();
    });
    try {
      const action = new URL(submitter?.getAttribute('formaction') || form.action, window.location.href);
      if (action.origin !== window.location.origin || form.method.toLowerCase() !== 'post') throw new Error('Report downloads must use a secure form on this site.');
      // Serialize before disabling either format button, preserving its name/value and CSRF token.
      const body = submitter ? new FormData(form, submitter) : new FormData(form);
      const format = body.get('format') || 'csv';
      if (!['csv', 'pdf'].includes(format)) throw new Error('Choose CSV or PDF format.');
      finish = window.SynapseLoading?.begin(form, { button: submitter, label: 'Preparing report…', disableButton: false }) || finish;
      controls.forEach(([button]) => { button.disabled = true; });
      const response = await fetch(action.href, {
        method: 'POST', body, credentials: 'same-origin', cache: 'no-store', redirect: 'error',
        signal: job.controller.signal,
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...window.SynapseCsrf?.headers() }
      });
      window.SynapseCsrf?.fromResponse(response);
      if (!response.ok) {
        const blob = await receive(response, job, 64 * 1024, 0);
        let payload;
        try { payload = JSON.parse(await blob.text()); } catch (_) { /* Never render server HTML. */ }
        window.SynapseCsrf?.sync(payload?.csrf);
        const fallback = [401, 403].includes(response.status) ? 'Access denied or your session has expired. Refresh and sign in again.'
          : 'The report could not be generated. Please retry or contact support.';
        throw new Error(typeof payload?.message === 'string' ? payload.message.slice(0, 250) : fallback);
      }
      const type = response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase();
      const disposition = response.headers.get('Content-Disposition') || '';
      const match = /^attachment;\s*filename="([a-z0-9_-]+\.(csv|pdf))"$/i.exec(disposition);
      if (type !== (format === 'pdf' ? 'application/pdf' : 'text/csv') || !match || match[2].toLowerCase() !== format) {
        throw new Error('The server did not return the expected report file. Refresh the page and try again.');
      }
      const length = Number(response.headers.get('Content-Length'));
      if (length > MAX_BYTES) throw new Error('The report exceeds the download size limit. Choose a smaller date range.');
      ui.heading.textContent = 'Receiving report';
      ui.message.textContent = 'Receiving file…';
      const file = await receive(response, job, MAX_BYTES, Number.isFinite(length) ? length : 0);
      if (!file.size) throw new Error('The server returned an empty report. Please try again.');
      if (format === 'pdf' && !(await file.slice(0, 5).text()).startsWith('%PDF-')) throw new Error('The server returned an invalid PDF report.');
      ui.progress.value = 100;
      ui.heading.textContent = 'Report ready';
      ui.message.textContent = '100% received. Starting browser download…';
      // Give the completed bar a paint before the browser opens its download UI.
      await new Promise(resolve => setTimeout(resolve, 120));
      if (job.controller.signal.aborted) throw new Error('Download cancelled.');
      const url = URL.createObjectURL(new Blob([file], { type }));
      objectUrls.add(url);
      const link = document.createElement('a');
      link.href = url;
      link.download = match[1];
      link.hidden = true;
      document.body.append(link);
      try { link.click(); } finally { link.remove(); }
      setTimeout(() => release(url), 60000);
      ui.heading.textContent = 'Download started';
      ui.message.textContent = match[1];
      ui.detail.textContent = 'Your browser now handles saving the file. Check its Downloads list.';
      ui.root.dataset.state = 'complete';
    } catch (error) {
      job.controller.abort();
      if (!job.disposed) {
        ui.root.dataset.state = job.cancelled ? 'cancelled' : 'error';
        ui.heading.textContent = job.cancelled ? 'Download cancelled' : 'Download failed';
        ui.message.textContent = job.timedOut ? 'The report request timed out. Try a smaller date range.'
          : job.cancelled ? 'No file was sent to the browser save flow.'
            : error instanceof TypeError ? 'Connection lost or sign-in redirect encountered. Refresh the page and try again.' : error.message;
        ui.progress.hidden = true;
        ui.detail.textContent = 'The export buttons are available again.';
      }
    } finally {
      clearTimeout(timeout);
      finish();
      controls.forEach(([button, disabled]) => { button.disabled = disabled; });
      ui.button.textContent = 'Dismiss';
      if (active === job) active = null;
      if (job.disposed) ui.root.remove();
    }
  }

  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-report-download], [data-operational-export]') || event.defaultPrevented) return;
    event.preventDefault();
    if (!active) void start(form, event.submitter);
  });

  function dispose(root) {
    if (active && (!root || root === active.form || root.contains(active.form))) {
      active.disposed = true;
      active.controller.abort();
    }
    previousPanel?.remove();
  }
  document.addEventListener('synapse:before-swap', event => dispose(event.detail?.root));
  window.addEventListener('pagehide', () => {
    dispose();
    objectUrls.forEach(release);
  });
  window.SynapseReportDownloads = { cancel: () => {
    if (active) {
      active.cancelled = true;
      active.controller.abort();
    }
  } };
})();

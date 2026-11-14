/** Staff AI insights: fixed endpoints, scoped updates, stable review modal, and no mutation replay. */
(function () {
  'use strict';
  if (window.SynapseAiInsights) return;

  const instances = new Map();
  const MAX_FRAGMENT = 524288;

  function endpoint(value, basePath, mutation) {
    const url = new URL(value, location.href);
    const suffix = url.pathname.slice(basePath.length);
    if (url.origin !== location.origin || !/^https?:$/.test(url.protocol) || url.username || url.password || url.search || url.hash ||
        !/\/(?:clinic|guidance|facilities)\/insights$/.test(basePath) || !url.pathname.startsWith(basePath) ||
        !(mutation ? /^\/(?:prepare|generate|recover|[1-9][0-9]*\/review|jobs\/[1-9][0-9]*\/(?:cancel|retry))$/.test(suffix) : suffix === '/panel')) {
      throw new Error('Invalid analysis endpoint.');
    }
    return url.href;
  }

  function fragment(html) {
    if (typeof html !== 'string' || html.length > MAX_FRAGMENT) throw new Error('Invalid analysis fragment.');
    const template = document.createElement('template');
    template.innerHTML = html;
    if (template.content.querySelector('script, iframe, object, embed')) throw new Error('Invalid analysis fragment.');
    return template;
  }

  function mount(root) {
    const basePath = location.pathname.replace(/\/$/, '');
    const events = new AbortController();
    const feedback = root.querySelector('[data-ai-feedback]');
    const panel = root.querySelector('[data-ai-panel]');
    const baseline = root.querySelector('[data-ai-baseline]');
    const modalElement = root.querySelector('[data-ai-review-modal]');
    const modalTitle = modalElement?.querySelector('[data-ai-review-modal-title]');
    const modalBody = modalElement?.querySelector('[data-ai-review-modal-body]');
    const modalActions = modalElement?.querySelector('[data-ai-review-modal-actions]');
    const staleNotice = modalElement?.querySelector('[data-ai-review-stale]');
    const pathUnit = basePath.match(/\/(clinic|guidance|facilities)\/insights$/)?.[1];
    const unit = pathUnit === 'facilities' ? 'bmg' : pathUnit;

    let read;
    let write;
    let timer;
    let debounce;
    let stopped = false;
    let suspended = false;
    let writing = false;
    let auth = false;
    let uncertain = false;
    let dirty = false;
    let ready = window.SynapseRealtime?.status === 'ready';
    let selectedAnalysis = '';
    let review = null;
    let modal;
    let modalClosingReview = null;

    const active = () => !stopped && !suspended && !auth && root.isConnected && !document.hidden;
    const modalShown = () => Boolean(modalElement?.classList.contains('show'));

    function say(message, type = 'info', notify = false) {
      if (!feedback) return;
      feedback.textContent = message;
      feedback.className = `alert alert-${type === 'error' ? 'danger' : type} small mb-3`;
      feedback.hidden = false;
      if (notify && typeof window.SynapseToast?.show === 'function') {
        try {
          window.SynapseToast.show(message, { type, key: 'ai-insights' });
          feedback.hidden = true;
        } catch (_) { /* Retain concise inline feedback when the shared service fails. */ }
      }
    }

    function keyed(element) {
      return element?.closest?.('[data-ai-key]')?.dataset.aiKey || null;
    }

    function capture(target) {
      const focused = target.contains(document.activeElement) ? document.activeElement : null;
      const focusOwner = focused?.closest?.('[data-ai-key]');
      const focusKey = focusOwner?.dataset.aiKey || null;
      const focusSelector = focused?.matches?.('button, summary, select, input, a[href]') ? focused.tagName.toLowerCase() : null;
      const open = [...target.querySelectorAll('details[data-ai-key][open]')].map(item => item.dataset.aiKey);
      return { focusKey, focusSelector, open };
    }

    function restore(target, state) {
      for (const key of state.open) {
        const details = [...target.querySelectorAll('details[data-ai-key]')].find(item => item.dataset.aiKey === key);
        if (details) details.open = true;
      }
      if (!state.focusKey) return;
      const owner = [...target.querySelectorAll('[data-ai-key]')].find(item => item.dataset.aiKey === state.focusKey);
      const candidate = owner?.matches?.(state.focusSelector || '') ? owner : owner?.querySelector(state.focusSelector || 'button, summary, select, input, a[href]');
      candidate?.focus({ preventScroll: true });
    }

    function selectorValue(target = panel) {
      return target.querySelector('[data-ai-analysis-selector]')?.value || '';
    }

    function applyAnalysis(target = panel, preferred = selectedAnalysis) {
      const selector = target.querySelector('[data-ai-analysis-selector]');
      if (!selector) return;
      if (preferred && [...selector.options].some(option => option.value === preferred)) selector.value = preferred;
      selectedAnalysis = selector.value;
      target.querySelectorAll('[data-ai-analysis-value]').forEach(input => { input.value = selectedAnalysis; });
      const option = [...target.querySelectorAll('[data-ai-analysis-option]')].find(item => item.dataset.aiAnalysisOption === selectedAnalysis);
      const description = target.querySelector('[data-ai-analysis-description]');
      if (description && option) description.textContent = option.dataset.description || '';
    }

    function clearModal({ restoreFocus = false } = {}) {
      if (restoreFocus) restoreReviewFocus();
      if (modalShown()) {
        modalClosingReview = null;
        modal?.hide();
      }
      review = null;
      if (modalTitle) modalTitle.textContent = 'AI draft';
      if (modalBody) modalBody.replaceChildren();
      if (modalActions) modalActions.replaceChildren();
      if (staleNotice) staleNotice.hidden = true;
    }

    function restoreReviewFocus(closedReview = review) {
      if (!closedReview) return;
      const opener = [...panel.querySelectorAll('[data-ai-open-review]')]
        .find(button => button.dataset.aiInsightId === closedReview.id);
      opener?.focus({ preventScroll: true });
    }

    function markReviewStale(message = 'This draft changed while it was open. Close it and open the current version before recording a decision.') {
      if (!review || review.stale) return;
      review.stale = true;
      if (staleNotice) {
        staleNotice.textContent = message;
        staleNotice.hidden = false;
      }
      modalActions?.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = true; });
      modalElement?.setAttribute('data-ai-review-stale', 'true');
    }

    function reconcileReview(nextPanel) {
      if (!review || !modalShown()) return;
      const template = [...nextPanel.querySelectorAll('template[data-ai-review-template]')]
        .find(item => item.dataset.aiReviewTemplate === review.id);
      const version = template?.dataset.aiInsightVersion;
      if (!template) markReviewStale('This draft is no longer available. Close it to continue.');
      else if (version !== review.version) markReviewStale();
    }

    function replace(target, html, kind) {
      const template = fragment(html);
      if (target.innerHTML === template.innerHTML) return false;
      const state = capture(target);
      const previousAnalysis = kind === 'panel' ? (selectedAnalysis || selectorValue(target)) : '';
      if (kind === 'panel') reconcileReview(template.content);
      target.replaceChildren(template.content);
      restore(target, state);
      if (kind === 'panel') applyAnalysis(target, previousAnalysis);
      return true;
    }

    function openReview(button) {
      if (auth || stopped || !modalElement || !modalBody || !modalActions || !window.bootstrap?.Modal) return;
      const id = button.dataset.aiInsightId;
      const version = button.dataset.aiInsightVersion;
      const content = [...panel.querySelectorAll('template[data-ai-review-template]')]
        .find(item => item.dataset.aiReviewTemplate === id && item.dataset.aiInsightVersion === version);
      const actions = [...panel.querySelectorAll('template[data-ai-review-actions]')]
        .find(item => item.dataset.aiReviewActions === id && item.dataset.aiInsightVersion === version);
      if (!content || !actions) {
        say('The draft changed. Refresh status and open its current version.', 'warning', true);
        return;
      }
      review = { id, version, openerKey: keyed(button), stale: false };
      modalElement.removeAttribute('data-ai-review-stale');
      if (staleNotice) staleNotice.hidden = true;
      if (modalTitle) modalTitle.textContent = content.dataset.title || `AI insight #${id}`;
      modalBody.replaceChildren(content.content.cloneNode(true));
      modalActions.replaceChildren(actions.content.cloneNode(true));
      const csrfName = document.querySelector('meta[name="csrf-name"]')?.content;
      const csrfHash = document.querySelector('meta[name="csrf-token"]')?.content;
      if (csrfName && csrfHash) {
        modalActions.querySelectorAll('form[data-ai-action]').forEach(form => {
          const csrf = [...form.querySelectorAll('input[type="hidden"]')]
            .find(input => !['version', 'decision', 'analysis_code', 'confirm_recovery', 'confirm_retry'].includes(input.name));
          if (csrf) { csrf.name = csrfName; csrf.value = csrfHash; }
        });
      }
      modal = window.bootstrap.Modal.getOrCreateInstance(modalElement, { backdrop: true, keyboard: true, focus: true });
      modal.show();
    }

    function schedule() {
      clearTimeout(timer);
      if (!active() || writing) return;
      const hasPending = panel.querySelector('[data-ai-pending="true"]');
      timer = setTimeout(() => { void refresh(); }, hasPending ? (ready ? 15000 : 5000) : (ready ? 60000 : 30000));
    }

    function clearPrivate() {
      auth = true;
      dirty = false;
      clearTimeout(timer);
      clearTimeout(debounce);
      read?.abort();
      read = null;
      clearModal();
      panel.replaceChildren();
      baseline.replaceChildren();
    }

    async function request(url, owner, body) {
      const timeout = setTimeout(() => owner.abort(), 12000);
      try {
        const response = await fetch(endpoint(url, basePath, Boolean(body)), {
          method: body ? 'POST' : 'GET', body, signal: owner.signal, credentials: 'same-origin', cache: 'no-store', redirect: 'error',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(body ? window.SynapseCsrf?.headers() : {}) },
        });
        if (owner.signal.aborted || stopped) throw new DOMException('Cancelled', 'AbortError');
        if (window.SynapseCsrf?.fromResponse(response)) {
          clearPrivate();
          say('Authentication changed. Sign-in navigation is in progress.', 'warning');
          throw new Error('Sign-in navigation is in progress.');
        }
        if (response.status === 401 || response.status === 403) {
          clearPrivate();
          const message = response.status === 403
            ? 'Access or the security token changed. Refresh before continuing.'
            : 'Authentication is required. Please sign in again.';
          say(message, 'warning');
          throw new Error(message);
        }
        if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Analysis returned an unexpected response.');
        const text = await response.text();
        if (owner.signal.aborted || stopped) throw new DOMException('Cancelled', 'AbortError');
        if (text.length > MAX_FRAGMENT) throw new Error('Analysis response is too large.');
        const payload = JSON.parse(text);
        if (!payload || typeof payload.success !== 'boolean') throw new Error('Invalid analysis response.');
        window.SynapseCsrf?.sync(payload.csrf);
        if (!response.ok || !payload.success) {
          const error = new Error(typeof payload.message === 'string' ? payload.message.slice(0, 1500) : 'This action is temporarily unavailable.');
          error.confirmed = true;
          error.status = response.status;
          error.reason = payload.reason;
          error.feedbackType = payload.reason === 'insufficient_data' || payload.feedback_type === 'warning' ? 'warning' : 'error';
          throw error;
        }
        return payload;
      } finally {
        clearTimeout(timeout);
      }
    }

    async function refresh() {
      if (!active() || writing || read) return;
      dirty = false;
      clearTimeout(timer);
      clearTimeout(debounce);
      debounce = null;
      const owner = new AbortController();
      read = owner;
      try {
        const payload = await request(root.dataset.panelUrl, owner);
        if (!owner.signal.aborted && active() && read === owner) replace(panel, payload.html, 'panel');
      } catch (error) {
        if (!stopped && !writing && !auth && !owner.signal.aborted) say(error.message || 'Status refresh failed.');
      } finally {
        if (read === owner) {
          read = null;
          if (dirty && active()) invalidate();
          else schedule();
        }
      }
    }

    function mutationButtons() {
      return [...root.querySelectorAll('form[data-ai-action] button[type="submit"]')];
    }

    root.addEventListener('change', event => {
      if (event.target.matches('[data-ai-analysis-selector]')) applyAnalysis(panel, event.target.value);
    }, { signal: events.signal });

    root.addEventListener('click', event => {
      const opener = event.target.closest('[data-ai-open-review]');
      if (opener && panel.contains(opener)) {
        event.preventDefault();
        openReview(opener);
        return;
      }
      if (event.target.closest('[data-ai-refresh]')) {
        event.preventDefault();
        void refresh();
      }
    }, { signal: events.signal });

    root.addEventListener('submit', async event => {
      const form = event.target.closest('form[data-ai-action]');
      if (!form || !root.contains(form)) return;
      event.preventDefault();
      if (writing || auth || stopped || (form.hasAttribute('data-ai-review-form') && review?.stale)) return;
      if (form.dataset.aiConfirm && !window.confirm(form.dataset.aiConfirm)) return;

      writing = true;
      uncertain = false;
      clearTimeout(timer);
      read?.abort();
      read = null;
      const buttons = mutationButtons().map(button => [button, button.disabled]);
      buttons.forEach(([button]) => { button.disabled = true; });
      // A review may be dismissed with Escape or either Close control while its mutation is pending.
      modalElement?.querySelectorAll('[data-bs-dismiss="modal"]').forEach(button => { button.disabled = false; });

      const rawAction = form.getAttribute('action') || location.href;
      let action = 'action';
      try { action = new URL(rawAction, location.href).pathname.split('/').pop(); } catch (_) { /* request() reports invalid endpoints. */ }
      const progress = {
        prepare: 'Checking the data and preparing your local preview…',
        generate: 'Checking the data and requesting an AI draft…',
        review: 'Saving your review…', cancel: 'Cancelling the queued draft…',
        recover: 'Checking identical evidence and renewing its retention interval…',
        retry: 'Checking whether a new attempt is safe…',
      };
      root.setAttribute('aria-busy', 'true');
      say(progress[action] || 'Saving your action…');
      const owner = new AbortController();
      write = owner;
      const wasReview = form.hasAttribute('data-ai-review-form');
      const submittedId = review?.id;
      const submittedVersion = review?.version;

      try {
        const body = new URLSearchParams(new FormData(form));
        const payload = await request(rawAction, owner, body);
        if (typeof payload.baseline === 'string') replace(baseline, payload.baseline, 'baseline');
        const message = typeof payload.message === 'string' ? payload.message.slice(0, 1500) : 'Your action was saved.';
        say(message, payload.reused || action === 'generate' ? 'info' : 'success', true);
      } catch (error) {
        uncertain = !error.confirmed;
        if (wasReview && error.confirmed && (error.status === 409 || ['review_conflict', 'invalid_review_transition', 'pipeline_changed', 'endpoint_changed'].includes(error.reason))) {
          markReviewStale('This draft changed or the decision is no longer available. Close it and open the current version.');
        }
        if (!stopped && !auth) {
          if (error.confirmed) say(error.message, error.feedbackType, true);
          else say('We could not confirm whether your action finished. Check Refresh status before trying again. No automatic retry was made.', 'warning', true);
        }
      } finally {
        write = null;
        writing = false;
        root.removeAttribute('aria-busy');
        buttons.forEach(([button, disabled]) => { if (button.isConnected) button.disabled = disabled; });
        if (review?.stale) modalActions?.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = true; });
        if (!stopped && !auth) {
          await refresh();
          if (wasReview && modalShown() && review?.id === submittedId && review?.version === submittedVersion) {
            // The authoritative panel decides whether the submitted version still exists.
            const current = [...panel.querySelectorAll('template[data-ai-review-template]')]
              .find(item => item.dataset.aiReviewTemplate === submittedId);
            if (!current || current.dataset.aiInsightVersion !== submittedVersion) markReviewStale('Your decision was saved. Close this draft and open its current status.');
          }
          if (uncertain) schedule();
        }
      }
    }, { signal: events.signal });

    function invalidate() {
      if (stopped || auth) return;
      dirty = true;
      if (!active() || writing || read || debounce) return;
      debounce = setTimeout(() => { debounce = null; void refresh(); }, 250);
    }

    function pause() {
      clearTimeout(timer);
      clearTimeout(debounce);
      debounce = null;
      read?.abort();
      read = null;
    }

    modalElement?.addEventListener('hide.bs.modal', () => {
      modalClosingReview = review;
    }, { signal: events.signal });

    modalElement?.addEventListener('hidden.bs.modal', () => {
      restoreReviewFocus(modalClosingReview);
      modalClosingReview = null;
      review = null;
      modalBody?.replaceChildren();
      modalActions?.replaceChildren();
      modalElement.removeAttribute('data-ai-review-stale');
      if (staleNotice) staleNotice.hidden = true;
    }, { signal: events.signal });

    document.addEventListener('synapse:realtime-event', event => {
      if (event.detail?.event === 'ai.insights.changed' && event.detail.data?.unit === unit) invalidate();
    }, { signal: events.signal });
    document.addEventListener('synapse:realtime-status', event => {
      const next = event.detail?.status === 'ready';
      if (next === ready) return;
      ready = next;
      schedule();
      if (ready) invalidate();
    }, { signal: events.signal });
    document.addEventListener('visibilitychange', () => { if (document.hidden) pause(); else void refresh(); }, { signal: events.signal });
    window.addEventListener('pagehide', () => { suspended = true; pause(); }, { signal: events.signal });
    window.addEventListener('pageshow', () => {
      if (!suspended) return;
      suspended = false;
      ready = window.SynapseRealtime?.status === 'ready';
      void refresh();
    }, { signal: events.signal });

    applyAnalysis();
    schedule();

    return () => {
      stopped = true;
      pause();
      write?.abort();
      clearModal();
      modal?.dispose();
      events.abort();
    };
  }

  function init(scope = document) {
    for (const [root, destroy] of instances) {
      if (!root.isConnected) {
        destroy();
        instances.delete(root);
      }
    }
    const roots = [...scope.querySelectorAll('[data-ai-workspace]')];
    if (scope.matches?.('[data-ai-workspace]')) roots.unshift(scope);
    for (const root of roots) if (!instances.has(root)) instances.set(root, mount(root));
  }

  window.SynapseAiInsights = { init };
  document.addEventListener('synapse:page-load', event => init(event.detail?.root || document));
  document.addEventListener('synapse:before-swap', event => {
    const scope = event.detail?.root;
    for (const [root, destroy] of instances) {
      if (!scope || scope === root || scope.contains(root)) {
        destroy();
        instances.delete(root);
      }
    }
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  else init();
})();

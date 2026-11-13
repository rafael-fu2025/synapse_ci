/** Authoritative named-region refreshes. Disabled unless the server enables an owner. */
(function () {
  'use strict';
  if (window.SynapseRefreshRegions || !window.fetch || !window.DOMParser || !window.AbortController) return;

  const tokenPattern = /^[a-z][a-z0-9._-]{0,63}$/;
  const authPath = /(?:^|\/)(?:auth|login|logout|sign-in|sign-out|signin|signout)(?:\/|$)/i;
  const adapters = new Map();
  const pending = new Map();
  const editVersions = new WeakMap();
  const markedSaved = new WeakSet();
  let sequence = 0;

  const enabledMeta = document.querySelector('meta[name="synapse-refresh-regions-enabled"]')?.content;
  const ownersMeta = document.querySelector('meta[name="synapse-refresh-region-owners"]')?.content || '';
  const rolloutEnabled = enabledMeta === '1';
  const allowedOwners = new Set(ownersMeta.split(/[\s,]+/).filter(Boolean));

  function validToken(value) {
    return typeof value === 'string' && tokenPattern.test(value);
  }
  function ownerEnabled(owner) {
    return rolloutEnabled && validToken(owner) && (allowedOwners.has('*') || allowedOwners.has(owner));
  }
  function emit(name, detail, cancelable = false) {
    return document.dispatchEvent(new CustomEvent(name, { detail, cancelable }));
  }
  function telemetry(name, detail) {
    emit(name, {
      owner: detail.owner,
      regions: detail.regions,
      cause: detail.cause,
      history: detail.history,
      status: detail.status,
      reason: detail.reason,
      duration: detail.startedAt ? Math.max(0, performance.now() - detail.startedAt) : undefined,
      correlationId: detail.correlationId,
    });
  }
  function sameOriginUrl(value, base = location.href) {
    if (!(value instanceof URL) && (typeof value !== 'string' || !value.trim())) return null;
    try {
      const url = new URL(value, base);
      if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin || url.username || url.password) return null;
      return url;
    } catch (_) { return null; }
  }
  function tokens(value) {
    const items = Array.isArray(value) ? value : typeof value === 'string' ? value.split(/\s+/) : [];
    if (!items.length || items.some(item => !validToken(item)) || new Set(items).size !== items.length) return null;
    return items;
  }
  function normalizeDescriptor(value, fallbackUrl) {
    if (!value || typeof value !== 'object') return null;
    if (value.mode === 'none') return { mode: 'none' };
    if (value.mode === 'navigate') {
      const url = sameOriginUrl(value.url || fallbackUrl);
      const history = value.history === 'replace' ? 'replace' : 'push';
      return url ? { mode: 'navigate', url: url.href, history } : null;
    }
    if (value.mode !== 'regions' || !validToken(value.owner)) return null;
    const regions = tokens(value.regions);
    const url = sameOriginUrl(value.url || fallbackUrl);
    if (!regions || !url) return null;
    url.hash = '';
    return { mode: 'regions', owner: value.owner, regions, url: url.href,
      history: ['push', 'replace'].includes(value.history) ? value.history : 'none' };
  }
  function descriptorFromForm(form, fallbackUrl) {
    if (!(form instanceof HTMLFormElement)) return null;
    const owner = form.dataset.refreshTargetOwner || form.dataset.mutationRefreshOwner;
    const regions = form.dataset.refreshRegions || form.dataset.mutationRefreshRegions;
    if (!owner && !regions) return null;
    return normalizeDescriptor({ mode: 'regions', owner, regions, url: fallbackUrl }, fallbackUrl);
  }
  function allIncluding(root, selector) {
    return [...(root.matches?.(selector) ? [root] : []), ...root.querySelectorAll(selector)];
  }
  function rootsFor(owner, regionNames, source = document) {
    const main = source instanceof Document ? source.getElementById('main')
      : source.matches?.('#main') ? source : source.closest?.('#main') || source.querySelector?.('#main');
    if (!main) return null;
    const roots = allIncluding(source, '[data-refresh-owner]').filter(root => root.dataset.refreshOwner === owner &&
      (root === main || main.contains(root)));
    if (roots.length !== 1) return null;
    const root = roots[0];
    const regions = new Map();
    for (const name of regionNames) {
      const matches = allIncluding(root, '[data-refresh-region]').filter(region => region.dataset.refreshRegion === name &&
        region.closest('[data-refresh-owner]') === root);
      if (matches.length !== 1) return null;
      regions.set(name, matches[0]);
    }
    const values = [...regions.values()];
    if (values.some((region, index) => values.some((other, otherIndex) => index !== otherIndex &&
      (region.contains(other) || other.contains(region))))) return null;
    return { root, regions };
  }
  function formTouchesRoots(form, roots) {
    if (!(form instanceof HTMLFormElement)) return false;
    return roots.some(root => root === form || root.contains(form) || form.contains(root) ||
      Array.from(form.elements).some(control => root.contains(control)));
  }
  function hasUnsavedChanges(exceptForm, roots) {
    if (window.SynapseNavigation?.hasUnsavedChanges) {
      return window.SynapseNavigation.hasUnsavedChanges(exceptForm, { within: roots });
    }
    return roots.some(root => root.querySelector('form[data-refresh-dirty="true"], [contenteditable][data-refresh-dirty="true"]'));
  }
  function unsafeContent(root) {
    const visit = container => {
      const elements = allIncluding(container, '*');
      for (const element of elements) {
        if (element.matches('script, iframe, object, embed, base, style, link[rel~="stylesheet"]')) return true;
        for (const attribute of element.attributes) {
          const name = attribute.name.toLowerCase();
          const value = attribute.value.trim();
          if (name.startsWith('on') || name === 'srcdoc' ||
              (['href', 'src', 'action', 'formaction', 'poster'].includes(name) && /^javascript:/i.test(value))) return true;
        }
        if (element.matches('template') && visit(element.content)) return true;
      }
      return false;
    };
    return visit(root);
  }
  function resolveUrls(root, base) {
    allIncluding(root, '[href], [src], [action], [formaction], [poster]').forEach(element => {
      for (const name of ['href', 'src', 'action', 'formaction', 'poster']) {
        if (!element.hasAttribute(name)) continue;
        const value = element.getAttribute(name);
        if (!value || value.startsWith('#')) continue;
        try { element.setAttribute(name, new URL(value, base).href); } catch (_) { /* Validation handles unusable values. */ }
      }
    });
  }
  function sidebarSignature(doc, base) {
    const nav = doc.querySelectorAll('.sidebar-nav');
    if (nav.length !== 1) return null;
    return JSON.stringify(Array.from(nav[0].querySelectorAll('a')).map(link => {
      let href;
      try { href = new URL(link.getAttribute('href') || '', base).href; } catch (_) { href = ''; }
      return [href, link.getAttribute('target'), link.hasAttribute('download'), link.classList.contains('nav-link'),
        Boolean(link.parentElement?.classList.contains('has-submenu')), link.getAttribute('data-navigation'),
        link.getAttribute('data-bs-toggle'), link.getAttribute('hx-get')];
    }));
  }
  function inspectDocument(doc, finalUrl) {
    const currentMain = document.querySelectorAll('#main');
    const incomingMain = doc.querySelectorAll('#main');
    const currentScope = document.body?.dataset.navigationScope;
    const incomingScope = doc.body?.dataset.navigationScope;
    if (currentMain.length !== 1 || incomingMain.length !== 1 || !currentScope || !incomingScope || doc.querySelector('base')) {
      return { status: 'incompatible' };
    }
    const currentSignature = sidebarSignature(document, location.href);
    const incomingSignature = sidebarSignature(doc, finalUrl);
    if (!currentSignature || !incomingSignature) return { status: 'incompatible' };
    if (currentScope !== incomingScope || currentSignature !== incomingSignature) return { status: 'security' };
    return { status: 'compatible' };
  }
  function csrfFromDocument(doc) {
    const content = name => doc.querySelector(`meta[name="${name}"]`)?.content;
    const name = content('csrf-name'), hash = content('csrf-token');
    return name && hash ? { name, hash, header: content('csrf-header') } : null;
  }
  function disposeBootstrap(root) {
    const types = ['Alert', 'Button', 'Carousel', 'Collapse', 'Dropdown', 'Modal', 'Offcanvas', 'Popover', 'ScrollSpy', 'Tab', 'Toast', 'Tooltip'];
    allIncluding(root, '*').forEach(element => types.forEach(type => {
      try { window.bootstrap?.[type]?.getInstance?.(element)?.dispose(); }
      catch (error) { console.error('Regional Bootstrap cleanup failed.', error); }
    }));
  }
  function scrollSnapshots(root) {
    return allIncluding(root, '[data-refresh-scroll-key]').map(element => ({
      key: element.dataset.refreshScrollKey,
      left: element.scrollLeft,
      top: element.scrollTop,
    })).filter(item => validToken(item.key));
  }
  function tabSnapshot(root) {
    return allIncluding(root, '[data-bs-toggle="tab"].active').map(tab => tab.dataset.bsTarget || tab.getAttribute('href'))
      .filter(target => typeof target === 'string' && target.startsWith('#'));
  }
  function restoreTabs(root, targets) {
    for (const selector of targets) {
      let tab;
      try { tab = allIncluding(root, '[data-bs-toggle="tab"]').find(candidate =>
        (candidate.dataset.bsTarget || candidate.getAttribute('href')) === selector); } catch (_) { continue; }
      if (!tab) continue;
      const pane = root.querySelector(selector);
      if (!pane) continue;
      if (window.bootstrap?.Tab) window.bootstrap.Tab.getOrCreateInstance(tab).show();
      else {
        allIncluding(root, '[data-bs-toggle="tab"]').forEach(candidate => {
          const active = candidate === tab;
          candidate.classList.toggle('active', active);
          candidate.setAttribute('aria-selected', String(active));
        });
        root.querySelectorAll('.tab-pane').forEach(candidate => {
          const active = candidate === pane;
          candidate.classList.toggle('active', active);
          candidate.classList.toggle('show', active);
        });
      }
      break;
    }
  }
  function focusSnapshot(root, regions) {
    const active = document.activeElement;
    if (!active || !root.contains(active) || !regions.some(region => region.contains(active))) return null;
    const keyed = active.closest?.('[data-refresh-focus-key]');
    return { key: keyed?.dataset.refreshFocusKey || null, id: active.id || null };
  }
  function findByData(root, name, value) {
    return allIncluding(root, `[${name}]`).find(element => element.getAttribute(name) === value) || null;
  }
  function restorePresentation(root, snapshot) {
    restoreTabs(root, snapshot.tabs || []);
    for (const item of snapshot.scrolls) {
      const element = findByData(root, 'data-refresh-scroll-key', item.key);
      if (element) { element.scrollLeft = item.left; element.scrollTop = item.top; }
    }
    window.scrollTo({ left: snapshot.window[0], top: snapshot.window[1], behavior: 'instant' });
    if (!snapshot.focus) return;
    let target = snapshot.focus.key ? findByData(root, 'data-refresh-focus-key', snapshot.focus.key) : null;
    if (!target && snapshot.focus.id) {
      const candidate = document.getElementById(snapshot.focus.id);
      if (candidate && root.contains(candidate)) target = candidate;
    }
    target ||= root.querySelector('[data-refresh-heading], h1, h2, h3');
    if (target) {
      if (!target.matches('a, button, input, select, textarea, [tabindex]')) target.setAttribute('tabindex', '-1');
      target.focus({ preventScroll: true });
    }
  }
  function noteEdit(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    const region = target.closest('[data-refresh-region]');
    const owner = region?.closest('[data-refresh-owner]');
    if (!owner) return;
    let versions = editVersions.get(owner);
    if (!versions) { versions = new Map(); editVersions.set(owner, versions); }
    const name = region.dataset.refreshRegion;
    if (validToken(name)) versions.set(name, (versions.get(name) || 0) + 1);
  }
  function editSnapshot(owner, regions) {
    const versions = editVersions.get(owner) || new Map();
    return new Map(regions.map(name => [name, versions.get(name) || 0]));
  }
  function editsChanged(owner, snapshot) {
    const versions = editVersions.get(owner) || new Map();
    return [...snapshot].some(([name, version]) => (versions.get(name) || 0) !== version);
  }
  document.addEventListener('input', noteEdit, true);
  document.addEventListener('change', noteEdit, true);

  function securityNavigation(url) {
    if (window.SynapseNavigation?.requireFullDocument) {
      window.SynapseNavigation.requireFullDocument(url, { replace: true });
    } else location.replace(url);
  }
  function status(result, request, extra = {}) {
    const value = { status: result, owner: request.owner, regions: request.regions, ...extra };
    telemetry(result === 'refreshed' ? 'refresh_committed' : result === 'security' ? 'refresh_security_escalated'
      : result === 'superseded' ? 'refresh_stale_ignored' : result === 'not-owned' ? 'refresh_unclaimed_fallback'
        : result === 'blocked' ? 'refresh_canceled' : 'refresh_failed', { ...request, ...value });
    return value;
  }
  function isCurrent(request) {
    return typeof request.isCurrent !== 'function' || request.isCurrent();
  }
  async function genericRefresh(request) {
    if (!isCurrent(request)) return status('superseded', request);
    const current = rootsFor(request.owner, request.regions);
    if (!current) return status('failed', request, { reason: 'current-regions' });
    const currentRegions = [...current.regions.values()];
    if (hasUnsavedChanges(request.source, currentRegions)) return status('blocked', request, { reason: 'unsaved' });

    pending.get(request.owner)?.controller.abort();
    const controller = new AbortController();
    const token = ++sequence;
    const job = { controller, token, cause: request.cause };
    pending.set(request.owner, job);
    const startedVersion = editSnapshot(current.root, request.regions);
    const snapshot = { window: [window.scrollX, window.scrollY], scrolls: scrollSnapshots(current.root),
      focus: focusSnapshot(current.root, currentRegions), tabs: tabSnapshot(current.root) };
    const finishes = currentRegions.map(region => window.SynapseLoading?.begin(region, { label: 'Updating…' }) || (() => {}));
    const correlationId = window.crypto?.randomUUID?.() || `${Date.now()}-${token}`;
    request = { ...request, correlationId, startedAt: performance.now() };
    telemetry('refresh_claimed', { ...request, status: 'claimed' });
    let timedOut = false, destructive = false;
    const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, 20000);
    try {
      const response = await fetch(request.url, { method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'follow',
        referrer: location.href, referrerPolicy: 'same-origin', signal: controller.signal,
        headers: { Accept: 'text/html', 'X-Synapse-Navigation': '1', 'X-Synapse-Refresh': '1', ...window.SynapseCsrf?.headers() } });
      if (pending.get(request.owner) !== job || controller.signal.aborted) return status('superseded', request);
      const finalUrl = sameOriginUrl(response.url || request.url);
      if (!finalUrl) return status('failed', request, { reason: 'unsafe-final-url' });
      if (window.SynapseCsrf?.fromResponse(response)) return status('security', request, { reason: 'auth' });
      if ([401, 403, 419].includes(response.status) || authPath.test(finalUrl.pathname)) {
        securityNavigation(finalUrl.href);
        return status('security', request, { reason: authPath.test(finalUrl.pathname) ? 'auth-route' : `http-${response.status}` });
      }
      if (!response.ok) throw new Error(`Region request failed (${response.status}).`);
      if (!/\btext\/html\b/i.test(response.headers.get('Content-Type') || '')) throw new Error('Unexpected region response.');
      const text = await response.text();
      if (pending.get(request.owner) !== job || controller.signal.aborted) return status('superseded', request);
      const doc = new DOMParser().parseFromString(text, 'text/html');
      const inspection = window.SynapseNavigation?.inspectDocument?.(doc, finalUrl.href) || inspectDocument(doc, finalUrl.href);
      if (inspection.status === 'security') {
        securityNavigation(finalUrl.href);
        return status('security', request, { reason: 'scope' });
      }
      if (inspection.status !== 'compatible') throw new Error('The page layout changed.');

      const incoming = rootsFor(request.owner, request.regions, doc);
      const latest = rootsFor(request.owner, request.regions);
      if (!incoming || !latest || latest.root !== current.root) throw new Error('The refresh regions changed.');
      const staged = new Map();
      for (const [name, region] of incoming.regions) {
        if (unsafeContent(region)) throw new Error('Unsafe refresh content.');
        const nodes = Array.from(region.childNodes, node => document.importNode(node, true));
        nodes.filter(node => node.nodeType === Node.ELEMENT_NODE).forEach(node => resolveUrls(node, finalUrl.href));
        staged.set(name, nodes);
      }
      if (!isCurrent(request)) return status('superseded', request);
      if (editsChanged(current.root, startedVersion) || hasUnsavedChanges(request.source, [...latest.regions.values()])) {
        return status('blocked', request, { reason: 'new-edits' });
      }
      if (request.history !== 'none' && window.SynapseNavigation?.canUpdateHistory &&
          !window.SynapseNavigation.canUpdateHistory(finalUrl.href)) {
        return status('blocked', request, { reason: 'history-busy' });
      }
      const detail = { owner: request.owner, regions: request.regions, root: current.root,
        current: latest.regions, incoming: staged, url: finalUrl.href, cause: request.cause };
      if (!emit('synapse:before-region-swap', detail, true)) return status('blocked', request, { reason: 'vetoed' });
      if (controller.signal.aborted || !isCurrent(request)) return status('superseded', request);

      finishes.forEach(finish => finish());
      destructive = true;
      const outgoing = new Map([...latest.regions].map(([name, region]) => [name, Array.from(region.childNodes)]));
      for (const region of latest.regions.values()) {
        window.SynapseLoading?.clear(region);
        window.SynapsePageLifecycle?.destroy(region);
        disposeBootstrap(region);
      }
      const replace = () => {
        for (const region of latest.regions.values()) window.Alpine?.destroyTree(region);
        try {
          for (const [name, region] of latest.regions) region.replaceChildren(...staged.get(name));
        } catch (error) {
          for (const [name, region] of latest.regions) region.replaceChildren(...outgoing.get(name));
          throw error;
        }
        for (const region of latest.regions.values()) window.Alpine?.initTree(region);
      };
      if (window.Alpine?.mutateDom) window.Alpine.mutateDom(replace); else replace();
      const csrf = csrfFromDocument(doc);
      if (csrf) window.SynapseCsrf?.sync(csrf);
      window.SynapseCsrf?.fromResponse(response);
      for (const region of latest.regions.values()) {
        window.SynapsePageLifecycle?.init(region);
        window.htmx?.process(region);
        window.htmx?.trigger(region, 'htmx:load', { elt: region });
      }
      if (request.history !== 'none') {
        if (window.SynapseNavigation) {
          if (!window.SynapseNavigation.updateHistory(finalUrl.href, request.history, { owner: request.owner, regions: request.regions })) {
            throw new Error('History changed during refresh.');
          }
        } else history[request.history === 'push' ? 'pushState' : 'replaceState'](history.state, '', finalUrl.href);
      }
      restorePresentation(current.root, snapshot);
      emit('synapse:region-load', detail);
      destructive = false;
      return status('refreshed', request, { roots: [...latest.regions.values()] });
    } catch (error) {
      if (destructive) {
        securityNavigation(request.url);
        return status('security', request, { reason: 'post-swap', recovery: 'document' });
      }
      if (pending.get(request.owner) !== job || (controller.signal.aborted && !timedOut)) return status('superseded', request);
      return status('failed', request, { reason: timedOut ? 'timeout' : error.message || 'failed' });
    } finally {
      clearTimeout(timeout);
      finishes.forEach(finish => finish());
      if (pending.get(request.owner) === job) pending.delete(request.owner);
    }
  }

  async function refresh(value) {
    const request = { cause: 'mutation', history: 'none', source: null, ...value };
    if (!validToken(request.owner) || !tokens(request.regions)) return status('failed', request, { reason: 'descriptor' });
    request.regions = tokens(request.regions);
    const url = sameOriginUrl(request.url);
    if (!url) return status('failed', request, { reason: 'url' });
    url.hash = '';
    request.url = url.href;
    if (!ownerEnabled(request.owner)) return status('not-owned', request, { reason: 'disabled' });

    if (!isCurrent(request)) return status('superseded', request);
    const adapter = adapters.get(request.owner);
    if (!adapter) return genericRefresh(request);
    if (!adapter.root?.isConnected || (adapter.owns && !adapter.owns(request))) return status('not-owned', request);
    if (adapter.canRefresh && !await adapter.canRefresh(request)) return status('blocked', request, { reason: 'owner-busy' });
    try {
      const result = await adapter.refresh(request);
      if (result && typeof result === 'object' && typeof result.status === 'string') return result;
      return status(result === true ? 'refreshed' : 'failed', request, { reason: result === true ? undefined : 'adapter' });
    } catch (error) {
      return status('failed', request, { reason: error.message || 'adapter' });
    }
  }
  function register(adapter) {
    if (!adapter || !validToken(adapter.owner) || !(adapter.root instanceof Element) || typeof adapter.refresh !== 'function') {
      throw new TypeError('A regional adapter needs a valid owner, root and refresh function.');
    }
    const main = document.getElementById('main');
    if (!main || (adapter.root !== main && !main.contains(adapter.root)) || adapters.has(adapter.owner)) {
      throw new Error('A regional refresh owner must be unique and contained by #main.');
    }
    adapters.set(adapter.owner, adapter);
    return () => {
      if (adapters.get(adapter.owner) !== adapter) return;
      pending.get(adapter.owner)?.controller.abort();
      adapter.destroy?.();
      adapters.delete(adapter.owner);
    };
  }
  function abortWithin(root) {
    for (const [owner, job] of pending) {
      const adapterRoot = adapters.get(owner)?.root;
      const declarative = rootsFor(owner, [], document)?.root;
      const target = adapterRoot || declarative;
      if (!root || !target || root === target || root.contains(target)) job.controller.abort();
    }
  }
  function cancelHistory() {
    for (const job of pending.values()) {
      if (job.cause === 'history') job.controller.abort();
    }
    for (const adapter of adapters.values()) adapter.cancel?.();
  }
  document.addEventListener('synapse:before-navigate', event => {
    if (!event.detail?.regional) abortWithin(event.detail?.root || document.getElementById('main'));
  });
  document.addEventListener('synapse:before-swap', event => abortWithin(event.detail?.root));
  window.addEventListener('pagehide', () => abortWithin(null));

  function lockSavedForm(form) {
    if (!(form instanceof HTMLFormElement) || markedSaved.has(form)) return;
    markedSaved.add(form);
    Array.from(form.elements).forEach(control => { if ('disabled' in control) control.disabled = true; });
    window.SynapseNavigation?.markSaved(form);
  }
  function canRefresh(form, { descriptor } = {}) {
    const fallbackUrl = location.href;
    const plan = normalizeDescriptor(descriptor, fallbackUrl) || descriptorFromForm(form, fallbackUrl);
    if (plan?.mode === 'regions' && ownerEnabled(plan.owner)) {
      const target = rootsFor(plan.owner, plan.regions);
      if (!target) return false;
      return !hasUnsavedChanges(form, [...target.regions.values()]);
    }
    return Boolean(window.SynapseNavigation && !window.SynapseNavigation.hasUnsavedChanges(form));
  }
  function warning(target, message, options) {
    window.SynapseToast?.show('Saved, but the page could not be refreshed. Do not submit again. Finish any affected edits, then retry the page refresh.', {
      type: 'warning', key: options.key, duration: 0,
      action: target ? { label: 'Retry refresh', run: () => reconcile(target.href, message, options) } : undefined,
    });
  }
  async function legacyRefresh(target, message, options) {
    if (!canRefresh(options.form)) return false;
    const regional = await window.SynapseLiveFilters?.refresh(target.href);
    if (regional === true) return true;
    if (regional === false) return false;
    return await window.SynapseNavigation?.navigate(target.href, { history: 'replace', scroll: options.scroll, fallback: false }) === true;
  }
  async function reconcile(value, message, options = {}) {
    const settings = { form: null, descriptor: null, scroll: [window.scrollX, window.scrollY], key: 'mutation-refresh', ...options };
    lockSavedForm(settings.form);
    const target = sameOriginUrl(value);
    const explicitDescriptor = settings.descriptor !== null && settings.descriptor !== undefined;
    const descriptor = explicitDescriptor
      ? normalizeDescriptor(settings.descriptor, target?.href)
      : descriptorFromForm(settings.form, target?.href);
    try {
      if (explicitDescriptor && !descriptor) throw new Error('Invalid refresh descriptor');
      if (descriptor?.mode === 'none') {
        window.SynapseToast?.show(message || 'Saved successfully.', { type: 'success', key: settings.key });
        return true;
      }
      if (descriptor?.mode === 'navigate') {
        const moved = await window.SynapseNavigation?.navigate(descriptor.url, { history: descriptor.history, fallback: false });
        if (!moved) throw new Error('Navigation failed');
        window.SynapseToast?.show(message || 'Saved successfully.', { type: 'success', key: settings.key });
        return true;
      }
      if (descriptor?.mode === 'regions') {
        const result = await refresh({ ...descriptor, source: settings.form, cause: 'mutation' });
        if (result.status === 'refreshed') {
          window.SynapseToast?.show(message || 'Saved successfully.', { type: 'success', key: settings.key });
          return true;
        }
        if (result.status === 'security') return false;
        warning(target, message, { ...settings, descriptor });
        return false;
      }
      if (!target) throw new Error('Invalid return address');
      if (!await legacyRefresh(target, message, settings)) throw new Error('Refresh failed');
      window.SynapseToast?.show(message || 'Saved successfully.', { type: 'success', key: settings.key });
      return true;
    } catch (_) {
      warning(target, message, settings);
      return false;
    }
  }

  window.SynapseRefreshRegions = Object.freeze({ register, refresh, cancelHistory, enabled: ownerEnabled,
    descriptor: normalizeDescriptor, descriptorFromForm });
  if (rolloutEnabled) window.SynapseMutations = { canRefresh, refresh: reconcile };
})();

/** Progressive document navigation. PHP owns authorization, shell identity and active links. */
(function () {
  'use strict';
  if (window.SynapseNavigation || !window.fetch || !window.DOMParser || !window.AbortController) return;

  const stateKey = '__synapseNavigation';
  const owner = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  const cleanups = new Set();
  const dirty = new Set();
  const scripts = new Map();
  let sequence = 0, pending = null, committing = false, restoring = false, failed = null, editVersion = 0;
  let currentUrl = location.href, currentEntry = null;
  const authPath = /(?:^|\/)(?:auth|login|logout|sign-in|sign-out|signin|signout)(?:\/|$)/i;

  function emit(name, detail, cancelable = false) {
    return document.dispatchEvent(new CustomEvent(name, { detail, cancelable }));
  }
  function urlFor(value, base = location.href) {
    const url = new URL(value, base);
    if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin || url.username || url.password) return null;
    return url;
  }
  function fullNavigation(url) {
    if (url === location.href) location.reload();
    else location.assign(url);
  }
  function ownState(state) {
    const entry = state?.[stateKey];
    return entry?.owner === owner && Number.isInteger(entry.index) && typeof entry.url === 'string' ? entry : null;
  }
  function stateWith(entry) {
    return { ...(history.state && typeof history.state === 'object' ? history.state : {}), [stateKey]: entry };
  }
  function rememberScroll() {
    // Other features can push their own URLs. Never relabel one of their entries.
    const entry = ownState(history.state);
    if (restoring || !entry || entry.url !== location.href || currentUrl !== location.href) return;
    currentEntry = { ...entry, scroll: [window.scrollX, window.scrollY] };
    history.replaceState(stateWith(currentEntry), '', location.href);
  }
  function restorePop(entry) {
    const distance = currentEntry && entry ? currentEntry.index - entry.index : 0;
    if (!distance) {
      fullNavigation(currentUrl);
      return;
    }
    restoring = true;
    history.go(distance);
  }
  function seedScripts() {
    document.querySelectorAll('script[src]').forEach(script => {
      if (script.src && !scripts.has(script.src)) scripts.set(script.src, Promise.resolve());
    });
  }
  seedScripts();

  function statusElements() {
    let status = document.getElementById('navigation-status');
    if (!status) {
      status = document.createElement('div');
      status.id = 'navigation-status';
      status.className = 'visually-hidden';
      document.body.append(status);
    }
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-atomic', 'true');
    let retry = document.getElementById('navigation-retry');
    if (!retry) {
      retry = document.createElement('button');
      retry.id = 'navigation-retry';
      retry.type = 'button';
      retry.textContent = 'Retry navigation';
      retry.hidden = true;
      status.after(retry);
    }
    let fallback = document.getElementById('navigation-fallback');
    if (!fallback) {
      fallback = document.createElement('a');
      fallback.id = 'navigation-fallback';
      fallback.textContent = 'Open the full page';
      fallback.dataset.navigation = 'false';
      fallback.hidden = true;
      retry.after(fallback);
    }
    return { status, retry, fallback };
  }
  let finishLoading = null;
  function busy(value) {
    finishLoading?.();
    finishLoading = null;
    if (value) {
      const section = document.querySelector('#main .main-content > .section');
      finishLoading = window.SynapseLoading?.begin(section, { label: 'Loading page…', skeleton: true });
    }
    document.body.classList.toggle('is-navigating', value);
    document.getElementById('main')?.setAttribute('aria-busy', String(value));
  }
  function announce(message, errorUrl) {
    const { status, retry, fallback } = statusElements();
    status.textContent = message;
    status.classList.toggle('visually-hidden', !errorUrl);
    retry.hidden = !errorUrl;
    fallback.hidden = !errorUrl;
    if (errorUrl) fallback.href = errorUrl;
    else fallback.removeAttribute('href');
  }
  function shell(doc) {
    const main = doc.querySelectorAll('#main');
    const pageScripts = doc.querySelectorAll('#page-scripts');
    const pageStyles = doc.querySelectorAll('#page-styles');
    const nav = doc.querySelectorAll('.sidebar-nav');
    const scope = doc.body?.getAttribute('data-navigation-scope');
    if (!scope || main.length !== 1 || nav.length !== 1 || pageScripts.length !== 1 || pageStyles.length !== 1 ||
        main[0].contains(pageScripts[0]) || main[0].contains(pageStyles[0])) return null;
    return { scope, main: main[0], nav: nav[0], pageScripts: pageScripts[0], pageStyles: pageStyles[0] };
  }
  function linkSignature(nav, base) {
    return JSON.stringify(Array.from(nav.querySelectorAll('a')).map(link => [
      new URL(link.getAttribute('href') || '', base).href,
      link.getAttribute('target'), link.hasAttribute('download'),
      link.classList.contains('nav-link'), Boolean(link.parentElement?.classList.contains('has-submenu')),
      link.getAttribute('data-navigation'), link.getAttribute('data-bs-toggle'), link.getAttribute('hx-get')
    ]));
  }
  function syncSidebar(oldNav, newNav) {
    const scroll = oldNav.scrollTop;
    const oldLinks = oldNav.querySelectorAll('a');
    newNav.querySelectorAll('a').forEach((incoming, index) => {
      const link = oldLinks[index];
      link.classList.toggle('active', incoming.classList.contains('active'));
      if (incoming.hasAttribute('aria-current')) link.setAttribute('aria-current', incoming.getAttribute('aria-current'));
      else if (incoming.classList.contains('active')) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
    oldNav.querySelectorAll('.has-submenu').forEach(item => {
      const open = Boolean(item.querySelector('a.active'));
      item.classList.toggle('open', open);
      item.querySelector(':scope > .nav-link')?.setAttribute('aria-expanded', String(open));
      const submenu = item.querySelector(':scope > .nav-submenu');
      if (submenu) submenu.style.maxHeight = open ? 'none' : '';
    });
    oldNav.scrollTop = scroll;
  }
  function resolveUrls(root, base) {
    // DOMParser documents have no response URL. Preserve relative links/assets across redirects.
    [root, ...root.querySelectorAll('[href], [src], [action], [formaction], [poster]')].forEach(element => {
      ['href', 'src', 'action', 'formaction', 'poster'].forEach(name => {
        if (!element.hasAttribute(name)) return;
        const value = element.getAttribute(name);
        if (value.startsWith('#')) return;
        element.setAttribute(name, new URL(value, base).href);
      });
    });
  }
  function csrfFromDocument(doc) {
    const content = name => doc.querySelector(`meta[name="${name}"]`)?.content;
    const name = content('csrf-name'), hash = content('csrf-token');
    return name && hash ? { name, hash, header: content('csrf-header') } : null;
  }
  function dispose(root) {
    const callbacks = Array.from(cleanups);
    cleanups.clear();
    callbacks.forEach(callback => {
      try { callback(); } catch (error) { console.error('Navigation cleanup failed.', error); }
    });
    // Only dispose page-owned instances; the shell's live dropdowns/listeners stay intact.
    const types = ['Alert', 'Button', 'Carousel', 'Collapse', 'Dropdown', 'Modal', 'Offcanvas', 'Popover', 'ScrollSpy', 'Tab', 'Toast', 'Tooltip'];
    [root, ...root.querySelectorAll('*')].forEach(element => {
      types.forEach(type => {
        try { window.bootstrap?.[type]?.getInstance?.(element)?.dispose(); }
        catch (error) { console.error('Navigation Bootstrap cleanup failed.', error); }
      });
    });
  }
  function executable(script) {
    const type = (script.getAttribute('type') || '').trim().toLowerCase();
    return !type || type === 'module' || /^(?:text|application)\/(?:java|ecma)script$/.test(type);
  }
  async function installScript(source, container, base, signal) {
    if (!executable(source)) {
      container.append(document.importNode(source, true));
      return;
    }
    if (source.noModule && 'noModule' in HTMLScriptElement.prototype) return;
    const src = source.getAttribute('src');
    const url = src ? new URL(src, base).href : null;
    if (url && scripts.has(url)) {
      await scripts.get(url);
      return;
    }
    const script = document.createElement('script');
    for (const attribute of source.attributes) {
      if (!['src', 'async', 'defer'].includes(attribute.name)) script.setAttribute(attribute.name, attribute.value);
    }
    // Sequential insertion preserves dependency order even for originally deferred scripts.
    script.async = false;
    if (url) script.src = url;
    else script.textContent = source.textContent;
    const wait = url || source.type === 'module';
    const loaded = new Promise((resolve, reject) => {
      let inlineError;
      const onError = event => { inlineError = event.error || new Error('A page script failed.'); };
      const finish = error => {
        script.onload = script.onerror = null;
        signal.removeEventListener('abort', aborted);
        window.removeEventListener('error', onError);
        if (error) reject(error);
        else resolve();
      };
      const aborted = () => { script.remove(); finish(new Error('Page script loading was interrupted.')); };
      if (signal.aborted) { aborted(); return; }
      signal.addEventListener('abort', aborted, { once: true });
      script.onload = () => finish();
      script.onerror = () => finish(new Error('Unable to load a page script.'));
      if (!wait) window.addEventListener('error', onError);
      container.append(script);
      if (!wait) finish(inlineError);
    });
    if (url) scripts.set(url, loaded);
    try { await loaded; }
    catch (error) { if (url) scripts.delete(url); throw error; }
  }
  function isDirtyForm(form) {
    return form && form.method.toUpperCase() !== 'GET' &&
      !form.closest('[data-navigation-dirty="false"], [hx-get], [hx-post], [hx-put], [hx-patch], [hx-delete], [data-table-filters]');
  }
  function mayLeave(detail) {
    // A parent dirty-form controller can cancel here, before the basic fallback prompt.
    if (!emit('synapse:before-navigate', detail, true)) return false;
    const changed = Array.from(dirty).some(form => form.isConnected && isDirtyForm(form));
    return !changed || window.confirm('You have unsaved changes. Leave this page?');
  }

  /** options: { history: 'push'|'replace', scroll: [x,y] }. Resolves true only on a completed swap. */
  async function navigate(value, options = {}) {
    let url;
    try { url = urlFor(value); } catch (_) { return false; }
    if (!url) return false;
    const popEntry = options.popEntry || null;
    const detail = { url: url.href, from: currentUrl, root: document.getElementById('main'), popstate: Boolean(popEntry) };
    if (!mayLeave(detail)) {
      if (popEntry) restorePop(popEntry);
      return false;
    }
    const approvedEdits = editVersion;
    // A script already executing cannot be rolled back safely. Use a clean document in that case.
    if (committing) { if (options.fallback !== false) fullNavigation(url.href); return false; }
    const old = shell(document);
    if (authPath.test(url.pathname)) { fullNavigation(url.href); return false; }
    if (!old) { if (options.fallback !== false) fullNavigation(url.href); return false; }
    if (!popEntry) rememberScroll();
    pending?.abort();
    const controller = new AbortController();
    pending = controller;
    const token = ++sequence;
    let timedOut = false, destructive = false;
    const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, 20000);
    failed = null;
    busy(true);
    announce('Loading page…');
    try {
      const response = await fetch(url.href, {
        method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'follow',
        referrer: currentUrl, referrerPolicy: 'same-origin', signal: controller.signal,
        // Do not send HX-Request/X-Requested-With: this request needs the full server document.
        headers: { Accept: 'text/html', 'X-Synapse-Navigation': '1', ...window.SynapseCsrf?.headers() }
      });
      if (token !== sequence) return false;
      if (controller.signal.aborted) throw new Error('Navigation was interrupted.');
      const finalUrl = urlFor(response.url || url.href);
      if (!finalUrl) { if (options.fallback !== false) fullNavigation(url.href); return false; }
      if (window.SynapseCsrf?.fromResponse(response)) return false;
      if ([401, 403, 419].includes(response.status) || authPath.test(finalUrl.pathname)) {
        fullNavigation(finalUrl.href);
        return false;
      }
      if (!response.ok) throw new Error(`Page request failed (${response.status}).`);
      if (!/\btext\/html\b/i.test(response.headers.get('Content-Type') || '')) {
        if (options.fallback !== false) fullNavigation(finalUrl.href);
        return false;
      }
      const text = await response.text();
      if (token !== sequence) return false;
      if (controller.signal.aborted) throw new Error('Navigation was interrupted.');
      const incomingDocument = new DOMParser().parseFromString(text, 'text/html');
      const incoming = shell(incomingDocument);
      // An incoming base would change every relative URL. Let the browser handle that document.
      if (incoming && (incoming.scope !== old.scope ||
          linkSignature(old.nav, currentUrl) !== linkSignature(incoming.nav, finalUrl.href))) {
        // An identity/permission change must replace the privileged shell as well.
        fullNavigation(finalUrl.href);
        return false;
      }
      if (!incoming || incomingDocument.querySelector('base')) {
        if (options.fallback !== false) fullNavigation(finalUrl.href);
        return false;
      }
      // URL fragments are never sent to the server. Preserve the requested anchor
      // across a same-origin redirect unless the final URL explicitly replaced it.
      if (!finalUrl.hash) finalUrl.hash = url.hash;
      const main = document.importNode(incoming.main, true);
      const pageScripts = document.importNode(incoming.pageScripts, false);
      const pageStyles = document.importNode(incoming.pageStyles, true);
      resolveUrls(main, finalUrl.href);
      resolveUrls(pageStyles, finalUrl.href);
      // Keep script execution controlled, including any legacy scripts embedded in main.
      const sources = [...Array.from(incoming.main.querySelectorAll('script')).filter(executable), ...incoming.pageScripts.querySelectorAll('script')];
      main.querySelectorAll('script').forEach(script => { if (executable(script)) script.remove(); });
      if (pageStyles.querySelector('script')) { if (options.fallback !== false) fullNavigation(finalUrl.href); return false; }
      const csrf = csrfFromDocument(incomingDocument);
      // The outgoing page stays usable during a request. Do not discard edits
      // made after the user originally approved leaving it.
      if (editVersion !== approvedEdits && !mayLeave(detail)) {
        if (popEntry) restorePop(popEntry);
        return false;
      }
      const swapDetail = { ...detail, url: finalUrl.href, root: old.main, incoming: main };
      committing = true;
      destructive = true;
      emit('synapse:before-swap', swapDetail);
      dispose(old.main);
      old.pageScripts.replaceWith(pageScripts);
      old.pageStyles.replaceWith(pageStyles);
      seedScripts();
      for (const source of sources) {
        await installScript(source, pageScripts, finalUrl.href, controller.signal);
        if (token !== sequence || controller.signal.aborted) throw new Error('Navigation was interrupted.');
      }
      if (controller.signal.aborted) throw new Error('Navigation timed out.');

      // Factories now exist before Alpine sees the inserted main through its mutation observer.
      const slot = document.createComment('navigation-main');
      old.main.before(slot);
      if (window.htmx?.remove) window.htmx.remove(old.main);
      // HTMX removes synchronously by default and owns cleanup of its nested widgets.
      old.main.remove();
      slot.replaceWith(main);
      syncSidebar(old.nav, incoming.nav);
      document.title = incomingDocument.title;
      if (csrf) window.SynapseCsrf?.sync(csrf);
      window.SynapseCsrf?.fromResponse(response);
      dirty.clear();
      if (popEntry) {
        currentEntry = { ...popEntry, url: finalUrl.href };
        history.replaceState(stateWith(currentEntry), '', finalUrl.href);
      } else {
        const replace = options.history === 'replace';
        currentEntry = { owner, id: `${owner}:${token}`, index: (ownState(history.state)?.index ?? currentEntry?.index ?? 0) + (replace ? 0 : 1),
          url: finalUrl.href, scroll: Array.isArray(options.scroll) ? options.scroll : [0, 0] };
        history[replace ? 'replaceState' : 'pushState'](stateWith(currentEntry), '', finalUrl.href);
      }
      currentUrl = finalUrl.href;
      document.body.classList.remove('sidebar-open');
      window.htmx?.process(main);
      window.htmx?.trigger(main, 'htmx:load', { elt: main });
      emit('synapse:page-load', { root: main, url: currentUrl, popstate: Boolean(popEntry) });
      main.querySelector('h1')?.setAttribute('tabindex', '-1');
      main.querySelector('h1')?.focus({ preventScroll: true });
      const scroll = popEntry?.scroll || options.scroll || [0, 0];
      window.scrollTo({ left: Number(scroll[0]) || 0, top: Number(scroll[1]) || 0, behavior: 'instant' });
      if (!popEntry && finalUrl.hash && !options.scroll) {
        try { document.getElementById(decodeURIComponent(finalUrl.hash.slice(1)))?.scrollIntoView(); } catch (_) { /* Invalid fragment encoding. */ }
      }
      announce('Page loaded.');
      return true;
    } catch (error) {
      if (token !== sequence) return false;
      if (destructive) { fullNavigation(url.href); return false; }
      if (controller.signal.aborted && !timedOut) return false;
      if (popEntry) restorePop(popEntry);
      failed = { url: url.href, options: { history: options.history, scroll: options.scroll, fallback: options.fallback } };
      announce(timedOut ? 'Loading timed out. Retry or open the full page.' : 'Unable to load the page. Your current page has been kept. Retry or open the full page.', url.href);
      return false;
    } finally {
      clearTimeout(timeout);
      if (token === sequence) {
        pending = null;
        committing = false;
        busy(false);
      }
    }
  }

  window.SynapseNavigation = {
    navigate,
    hasUnsavedChanges(exceptForm) {
      return Array.from(dirty).some(form => form !== exceptForm && form.isConnected && isDirtyForm(form));
    },
    markSaved(form) {
      dirty.delete(form);
    },
    // Fragment owners update the address without replacing their current page.
    // Keep those entries in the same history sequence as full content swaps.
    updateHistory(value, mode = 'replace') {
      const url = urlFor(value);
      if (!url || pending || restoring || !currentEntry) return false;
      rememberScroll();
      const push = mode === 'push' && url.href !== location.href;
      currentEntry = { ...currentEntry, id: `${owner}:${++sequence}`, index: currentEntry.index + (push ? 1 : 0),
        url: url.href, scroll: [window.scrollX, window.scrollY] };
      history[push ? 'pushState' : 'replaceState'](stateWith(currentEntry), '', url.href);
      currentUrl = url.href;
      return true;
    },
    registerCleanup(callback) {
      if (typeof callback !== 'function') throw new TypeError('Cleanup must be a function.');
      cleanups.add(callback);
      return () => cleanups.delete(callback);
    }
  };

  function start() {
    seedScripts();
    if (!shell(document)) return; // Incomplete/old layouts retain ordinary browser navigation.
    statusElements();
    currentEntry = { owner, id: `${owner}:initial`, index: 0, url: location.href, scroll: [window.scrollX, window.scrollY] };
    history.replaceState(stateWith(currentEntry), '', location.href);
    history.scrollRestoration = 'manual';
    document.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : event.target?.parentElement;
      if (target?.closest('#navigation-retry') && failed) {
        event.preventDefault();
        if (!restoring) void navigate(failed.url, failed.options);
        return;
      }
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || restoring) return;
      const link = target?.closest('a[href]');
      if (!link || !link.matches('.sidebar-nav a.nav-link, a[data-navigation], #main a')) return;
      if (link.closest('[data-navigation="false"], [data-app-modal], [data-app-modal-open], [data-app-modal-close], [data-bs-toggle], [data-table-page], [data-table-reset], [download], [target], [onclick], [hx-get], [hx-post], [hx-put], [hx-patch], [hx-delete], [data-hx-get], [data-hx-post], [data-hx-put], [data-hx-patch], [data-hx-delete]')) return;
      // The layout disables HTMX boost on body; only a more local opt-out disables us.
      const boost = link.closest('[hx-boost], [data-hx-boost]');
      if (boost && boost !== document.body && (boost.getAttribute('hx-boost') || boost.getAttribute('data-hx-boost')) === 'false') return;
      const raw = link.getAttribute('href').trim();
      if (!raw) return;
      let url;
      try { url = urlFor(raw); } catch (_) { return; }
      if (!url || authPath.test(url.pathname)) return;
      if (url.pathname === location.pathname && url.search === location.search && (url.hash || raw.startsWith('#'))) {
        event.preventDefault();
        rememberScroll();
        if (window.SynapseNavigation.updateHistory(url.href, 'push')) {
          try {
            const target = document.getElementById(decodeURIComponent(url.hash.slice(1)));
            if (target) target.scrollIntoView();
            else if (!url.hash) window.scrollTo({ top: 0, behavior: 'instant' });
          } catch (_) { /* Invalid fragment encoding. */ }
          rememberScroll();
        }
        return;
      }
      event.preventDefault();
      void navigate(url.href);
    });
    const markDirty = event => {
      const form = event.target.form || event.target.closest?.('form');
      if (document.getElementById('main')?.contains(form) && isDirtyForm(form)) { dirty.add(form); ++editVersion; }
    };
    document.addEventListener('input', markDirty);
    document.addEventListener('change', markDirty);
    document.addEventListener('reset', event => { if (!event.defaultPrevented) dirty.delete(event.target); });

    let frame = 0;
    window.addEventListener('scroll', () => {
      if (!frame) frame = requestAnimationFrame(() => { frame = 0; rememberScroll(); });
    }, { passive: true });
    window.addEventListener('pagehide', () => { rememberScroll(); pending?.abort(); });
    window.addEventListener('popstate', event => {
      if (restoring) { restoring = false; return; }
      const entry = ownState(event.state);
      if (!committing) {
        pending?.abort();
        pending = null;
        ++sequence;
        busy(false);
      }
      const previous = new URL(currentUrl);
      if (entry && entry.url === location.href && previous.pathname === location.pathname && previous.search === location.search) {
        // Fragment history never replaces the form, so it must not prompt or
        // reload a dirty page just to restore its scroll position.
        currentEntry = entry;
        currentUrl = location.href;
        window.scrollTo({ left: entry.scroll[0], top: entry.scroll[1], behavior: 'instant' });
        return;
      }
      const table = document.querySelector('#main [data-live-table]');
      const tableEndpoint = table?.dataset.endpoint ? urlFor(table.dataset.endpoint) : null;
      // /index.php/x and /x are the same route; the table fetches the canonical endpoint itself.
      const route = pathname => pathname.replace(/^\/index\.php(?=\/)/, '');
      if (entry && entry.url === location.href && tableEndpoint &&
          route(previous.pathname) === route(location.pathname) &&
          route(location.pathname) === route(tableEndpoint.pathname)) {
        // Same-route table filters/pages, including index.php aliases, are owned by live-table.js.
        currentEntry = entry;
        currentUrl = location.href;
        return;
      }
      if (!entry || entry.url !== location.href) { location.reload(); return; }
      void navigate(location.href, { popEntry: entry });
    });
    emit('synapse:page-load', { root: document.getElementById('main'), url: currentUrl, initial: true, popstate: false });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();

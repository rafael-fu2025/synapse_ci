/** Regional section switching and history pagination for the Guidance client cumulative record. */
(function () {
  'use strict';
  if (window.SynapseGuidanceClientRecord) return;

  const instances = new Map();
  const selector = '[data-guidance-client-record]';
  const authPath = /(?:^|\/)(?:auth|login|logout|sign-in|sign-out|signin|signout)(?:\/|$)/i;

  function routePath(pathname) {
    return pathname.replace(/^\/index\.php(?=\/)/, '');
  }

  function allIncluding(root, query) {
    return [...(root.matches?.(query) ? [root] : []), ...root.querySelectorAll(query)];
  }

  function unsafeContent(container) {
    const visit = scope => {
      for (const element of allIncluding(scope, '*')) {
        if (element.matches('script, iframe, object, embed, base, style, link[rel~="stylesheet"]')) return true;
        for (const attribute of element.attributes) {
          const name = attribute.name.toLowerCase();
          const value = attribute.value.trim();
          if (name.startsWith('on') || name === 'srcdoc'
              || (['href', 'src', 'action', 'formaction', 'poster'].includes(name) && /^javascript:/i.test(value))) {
            return true;
          }
        }
        if (element.matches('template') && visit(element.content)) return true;
      }
      return false;
    };
    return visit(container);
  }

  function resolveUrls(root, base) {
    allIncluding(root, '[href], [src], [action], [formaction], [poster]').forEach(element => {
      for (const name of ['href', 'src', 'action', 'formaction', 'poster']) {
        if (!element.hasAttribute(name)) continue;
        const value = element.getAttribute(name);
        if (!value || value.startsWith('#')) continue;
        try {
          element.setAttribute(name, new URL(value, base).href);
        } catch (_) {
          // Leave malformed attribute for validation checks.
        }
      }
    });
  }

  function disposeBootstrap(root) {
    const types = ['Alert', 'Button', 'Carousel', 'Collapse', 'Dropdown', 'Modal', 'Offcanvas', 'Popover', 'ScrollSpy', 'Tab', 'Toast', 'Tooltip'];
    allIncluding(root, '*').forEach(element => {
      types.forEach(type => {
        try {
          window.bootstrap?.[type]?.getInstance?.(element)?.dispose();
        } catch (error) {
          console.error('Guidance client record Bootstrap cleanup failed.', error);
        }
      });
    });
  }

  function sameOriginUrl(value, base = location.href) {
    if (typeof value !== 'string' || !value.trim()) return null;
    try {
      const url = new URL(value, base);
      if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin || url.username || url.password) return null;
      return url;
    } catch (_) {
      return null;
    }
  }

  function securityRedirect(url) {
    if (window.SynapseNavigation?.requireFullDocument) {
      window.SynapseNavigation.requireFullDocument(url, { replace: true });
    } else {
      location.replace(url);
    }
  }

  function init(root) {
    if (instances.has(root)) return;
    const historyRegion = root.querySelector('[data-refresh-region="history"]');
    const endpoint = sameOriginUrl(root.dataset.endpoint || location.href);
    const scope = document.body?.dataset.navigationScope || '';
    if (!historyRegion || !endpoint) return;
    endpoint.hash = '';

    const initialPath = location.pathname;
    const allowedRoutes = new Set([routePath(endpoint.pathname), routePath(initialPath)]);
    const regionalOwner = root.dataset.refreshOwner || '';
    const events = new AbortController();
    const listenerOptions = { signal: events.signal };

    let sequence = 0;
    let controller = null;
    let finishLoading = null;
    let unregisterRegional = null;
    let failedRequest = null;

    function currentActiveTab(container = historyRegion) {
      const active = container.querySelector('.nav-tabs a[data-record-tab-link].active, .nav-tabs a[data-record-tab-link][aria-current="page"]');
      return active?.dataset.recordTabLink || null;
    }

    let committedTab = currentActiveTab();

    function setActiveTab(tabKey) {
      if (!tabKey) return;
      historyRegion.querySelectorAll('.nav-tabs a[data-record-tab-link]').forEach(link => {
        const active = link.dataset.recordTabLink === tabKey;
        link.classList.toggle('active', active);
        if (active) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
      });
    }

    function validDestination(url) {
      return url instanceof URL
        && url.origin === endpoint.origin
        && allowedRoutes.has(routePath(url.pathname))
        && !url.username
        && !url.password
        && !url.hash;
    }

    function sectionContainer() {
      return historyRegion.querySelector('#guidance-record-section') || historyRegion;
    }

    function clearError() {
      historyRegion.querySelectorAll('[data-record-error]').forEach(node => node.remove());
    }

    function showError(message) {
      clearError();
      const box = document.createElement('div');
      box.className = 'alert alert-danger d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3';
      box.setAttribute('role', 'alert');
      box.dataset.recordError = '';

      const copy = document.createElement('span');
      copy.textContent = message;

      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'btn btn-outline-danger btn-sm';
      retry.dataset.recordRetry = '';
      retry.textContent = 'Retry';

      box.append(copy, retry);
      sectionContainer().prepend(box);
    }

    function beginLoading() {
      clearLoading();
      const container = sectionContainer();
      historyRegion.setAttribute('aria-busy', 'true');
      container.classList.add('gc-section-loading');
      finishLoading = window.SynapseLoading?.begin(container, {
        label: 'Loading section…',
        skeleton: true,
      }) || null;
    }

    function clearLoading() {
      finishLoading?.();
      finishLoading = null;
      historyRegion.querySelectorAll('.gc-section-loading').forEach(node => {
        node.classList.remove('gc-section-loading');
      });
      historyRegion.removeAttribute('aria-busy');
    }

    function invalidate() {
      ++sequence;
      controller?.abort();
      controller = null;
      clearLoading();
    }

    function formUrl(form) {
      const action = form.getAttribute('action') || endpoint.href;
      const url = sameOriginUrl(action, location.href);
      if (!url) return null;
      url.search = '';
      url.hash = '';
      const data = new FormData(form);
      for (const name of new Set(data.keys())) {
        url.searchParams.delete(name);
        for (const value of data.getAll(name)) {
          if (typeof value === 'string') url.searchParams.append(name, value.trim());
        }
      }
      return validDestination(url) ? url : null;
    }

    async function load(destination, loadOptions = {}) {
      const {
        historyMode = 'push',
        focusHeading = false,
        pendingTab = null,
      } = loadOptions;

      let url;
      try {
        url = destination instanceof URL ? new URL(destination.href) : new URL(destination, location.href);
      } catch (_) {
        return false;
      }
      url.hash = '';
      if (!validDestination(url)) return false;

      invalidate();
      const token = sequence;
      const requestController = new AbortController();
      controller = requestController;
      failedRequest = { url: new URL(url.href), options: { historyMode, focusHeading, pendingTab } };

      clearError();
      const targetTab = pendingTab || url.searchParams.get('tab');
      if (targetTab) setActiveTab(targetTab);

      beginLoading();

      let timedOut = false;
      const timeout = setTimeout(() => {
        timedOut = true;
        requestController.abort();
      }, 20000);

      try {
        const response = await fetch(url.href, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          redirect: 'follow',
          referrer: location.href,
          referrerPolicy: 'same-origin',
          signal: requestController.signal,
          headers: {
            Accept: 'text/html',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Synapse-Navigation': '1',
            'X-Synapse-Refresh': '1',
            ...window.SynapseCsrf?.headers(),
          },
        });

        if (token !== sequence || requestController.signal.aborted || !root.isConnected) return false;

        const finalUrl = sameOriginUrl(response.url || url.href);
        if (!finalUrl) throw new Error('Invalid destination.');
        finalUrl.hash = '';

        if (window.SynapseCsrf?.fromResponse(response)) {
          setActiveTab(committedTab);
          return false;
        }
        if ([401, 403, 419].includes(response.status) || authPath.test(finalUrl.pathname)) {
          setActiveTab(committedTab);
          securityRedirect(finalUrl.href);
          return false;
        }
        if (!response.ok) {
          throw new Error(`Section request failed (${response.status}).`);
        }
        if (!/\btext\/html\b/i.test(response.headers.get('Content-Type') || '')) {
          throw new Error('Unexpected response format. Please retry.');
        }

        const html = await response.text();
        if (token !== sequence || requestController.signal.aborted || !root.isConnected) return false;

        const incomingDoc = new DOMParser().parseFromString(html, 'text/html');
        const incomingScope = incomingDoc.body?.dataset.navigationScope || '';
        if (scope && (!incomingScope || incomingScope !== scope)) {
          securityRedirect(finalUrl.href);
          return false;
        }
        if (incomingDoc.querySelector('base')) {
          throw new Error('The page layout changed. Reload this page before continuing.');
        }

        const incomingRoot = incomingDoc.querySelector(selector);
        const incomingHistory = incomingRoot?.querySelector('[data-refresh-region="history"]');
        if (!incomingRoot || !incomingHistory
            || (regionalOwner && incomingRoot.dataset.refreshOwner !== regionalOwner)
            || unsafeContent(incomingHistory)) {
          throw new Error('Unable to load this record section. Please retry.');
        }

        if (historyMode !== 'none' && window.SynapseNavigation?.canUpdateHistory
            && !window.SynapseNavigation.canUpdateHistory(finalUrl.href)) {
          throw new Error('Another navigation interrupted this update.');
        }

        const stagedNodes = Array.from(incomingHistory.childNodes, node => document.importNode(node, true));
        stagedNodes
          .filter(node => node.nodeType === Node.ELEMENT_NODE)
          .forEach(node => resolveUrls(node, finalUrl.href));

        const incomingKpiRoot = document.importNode(incomingRoot, true);
        resolveUrls(incomingKpiRoot, finalUrl.href);

        const scrollPosition = [window.scrollX, window.scrollY];

        clearLoading();
        window.SynapseLoading?.clear(historyRegion);
        window.SynapsePageLifecycle?.destroy(historyRegion);
        disposeBootstrap(historyRegion);

        const replace = () => {
          window.Alpine?.destroyTree(historyRegion);
          historyRegion.replaceChildren(...stagedNodes);
          window.Alpine?.initTree(historyRegion);
        };
        if (window.Alpine?.mutateDom) window.Alpine.mutateDom(replace);
        else replace();

        // Sync top KPI summary links so updated query params (e.g. per_page) stay consistent.
        const currentKpiLinks = Array.from(root.querySelectorAll('a[data-record-tab-link]'))
          .filter(link => !historyRegion.contains(link));
        const incomingKpiRootHistory = incomingKpiRoot.querySelector('[data-refresh-region="history"]');
        const incomingKpiLinks = Array.from(incomingKpiRoot.querySelectorAll('a[data-record-tab-link]'))
          .filter(link => !incomingKpiRootHistory?.contains(link));
        if (currentKpiLinks.length === incomingKpiLinks.length) {
          currentKpiLinks.forEach((link, index) => {
            link.href = incomingKpiLinks[index].href;
          });
        }

        const csrfName = incomingDoc.querySelector('meta[name="csrf-name"]')?.content;
        const csrfHash = incomingDoc.querySelector('meta[name="csrf-token"]')?.content;
        if (csrfName && csrfHash) {
          window.SynapseCsrf?.sync({
            name: csrfName,
            hash: csrfHash,
            header: incomingDoc.querySelector('meta[name="csrf-header"]')?.content,
          });
        }
        window.SynapseCsrf?.fromResponse(response);

        window.SynapsePageLifecycle?.init(historyRegion);
        window.htmx?.process(historyRegion);

        if (historyMode !== 'none') {
          const regional = regionalOwner && window.SynapseRefreshRegions?.enabled(regionalOwner)
            ? { owner: regionalOwner, regions: ['history'] }
            : null;
          if (window.SynapseNavigation) {
            if (!window.SynapseNavigation.updateHistory(finalUrl.href, historyMode, regional)) {
              throw new Error('History changed while loading section.');
            }
          } else {
            const method = historyMode === 'push' && finalUrl.href !== location.href ? 'pushState' : 'replaceState';
            history[method](history.state, '', finalUrl.href);
          }
        }

        committedTab = currentActiveTab() || targetTab || committedTab;
        failedRequest = null;

        window.scrollTo({ left: scrollPosition[0], top: scrollPosition[1], behavior: 'instant' });
        if (focusHeading) {
          const heading = historyRegion.querySelector('[data-refresh-heading]');
          if (heading) {
            if (!heading.hasAttribute('tabindex')) heading.setAttribute('tabindex', '-1');
            heading.focus({ preventScroll: true });
          }
        }

        return true;
      } catch (error) {
        if (token !== sequence || (!timedOut && (requestController.signal.aborted || error.name === 'AbortError'))) {
          return false;
        }
        setActiveTab(committedTab);
        showError(timedOut
          ? 'Loading this section timed out. Your current view is still displayed.'
          : error instanceof TypeError
            ? 'Connection or sign-in changed. Retry loading this section or reload the page.'
            : (error.message || 'Unable to load this record section. Your current view is still displayed.'));
        return false;
      } finally {
        clearTimeout(timeout);
        if (token === sequence) {
          controller = null;
          clearLoading();
        }
      }
    }

    root.addEventListener('click', event => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

      const retryButton = event.target.closest?.('[data-record-retry]');
      if (retryButton && historyRegion.contains(retryButton)) {
        event.preventDefault();
        if (failedRequest) {
          void load(failedRequest.url, failedRequest.options);
        }
        return;
      }

      const tabLink = event.target.closest?.('a[data-record-tab-link]');
      if (tabLink && root.contains(tabLink)) {
        if (tabLink.hasAttribute('download') || (tabLink.target && tabLink.target !== '_self')) return;
        const url = sameOriginUrl(tabLink.getAttribute('href') || '', location.href);
        if (!url) return;
        url.hash = '';
        if (!validDestination(url)) return;
        event.preventDefault();
        void load(url, {
          historyMode: 'push',
          focusHeading: true,
          pendingTab: tabLink.dataset.recordTabLink || null,
        });
        return;
      }

      const pageLink = event.target.closest?.('a[data-table-page]');
      if (pageLink && historyRegion.contains(pageLink)) {
        if (pageLink.hasAttribute('download') || (pageLink.target && pageLink.target !== '_self')) return;
        const url = sameOriginUrl(pageLink.getAttribute('href') || '', location.href);
        if (!url) return;
        url.hash = '';
        if (!validDestination(url)) return;
        event.preventDefault();
        void load(url, {
          historyMode: 'push',
          focusHeading: false,
          pendingTab: committedTab,
        });
      }
    }, listenerOptions);

    root.addEventListener('change', event => {
      const select = event.target;
      if (!(select instanceof HTMLSelectElement) || select.name !== 'per_page') return;
      const form = select.form;
      if (!form || !historyRegion.contains(form) || !form.matches('form[data-record-history-form]')) return;
      const url = formUrl(form);
      if (url) {
        void load(url, { historyMode: 'push', focusHeading: false, pendingTab: committedTab });
      }
    }, listenerOptions);

    root.addEventListener('submit', event => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || !historyRegion.contains(form) || !form.matches('form[data-record-history-form]')) return;
      event.preventDefault();
      const url = formUrl(form);
      if (url) {
        void load(url, { historyMode: 'push', focusHeading: false, pendingTab: committedTab });
      }
    }, listenerOptions);

    window.addEventListener('popstate', event => {
      if (!root.isConnected || !allowedRoutes.has(routePath(location.pathname))) return;
      const entry = event.state?.__synapseNavigation;
      if (regionalOwner && entry?.region?.owner === regionalOwner && Array.isArray(entry.region.regions)
          && entry.region.regions.length === 1 && entry.region.regions[0] === 'history'
          && window.SynapseRefreshRegions?.enabled(regionalOwner)) {
        return;
      }
      const url = sameOriginUrl(location.href);
      if (url) {
        url.hash = '';
        if (validDestination(url)) {
          void load(url, { historyMode: 'none', focusHeading: false });
        }
      }
    }, listenerOptions);

    document.addEventListener('synapse:before-navigate', event => {
      if (!event.detail?.regional) invalidate();
    }, listenerOptions);

    window.addEventListener('pagehide', invalidate, listenerOptions);

    if (regionalOwner && window.SynapseRefreshRegions?.enabled(regionalOwner)) {
      unregisterRegional = window.SynapseRefreshRegions.register({
        owner: regionalOwner,
        root,
        owns(request) {
          return request.regions.length === 1 && request.regions[0] === 'history';
        },
        canRefresh() {
          return controller === null;
        },
        cancel: invalidate,
        async refresh(request) {
          const refreshed = await load(request.url, {
            historyMode: 'none',
            focusHeading: request.cause === 'history',
          });
          return refreshed === true
            ? { status: 'refreshed', owner: regionalOwner, regions: ['history'], roots: [historyRegion] }
            : { status: 'failed', owner: regionalOwner, regions: ['history'], reason: 'guidance-client-record' };
        },
      });
    }

    instances.set(root, {
      destroy() {
        unregisterRegional?.();
        unregisterRegional = null;
        invalidate();
        events.abort();
        instances.delete(root);
      },
    });
  }

  function start(event) {
    for (const [root, state] of instances) {
      if (!root.isConnected) state.destroy();
    }
    const scope = event?.detail?.root || event?.detail?.elt || document;
    if (scope.matches?.(selector)) init(scope);
    scope.querySelectorAll(selector).forEach(init);
  }

  document.addEventListener('synapse:before-swap', event => {
    const scope = event.detail?.root;
    if (!scope) return;
    for (const [root, state] of instances) {
      if (root === scope || scope.contains(root) || !root.isConnected) state.destroy();
    }
  });
  document.addEventListener('synapse:page-load', start);
  document.addEventListener('htmx:load', start);
  window.addEventListener('pageshow', () => start());
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => start(), { once: true });
  else start();

  window.SynapseGuidanceClientRecord = { init: start };
})();

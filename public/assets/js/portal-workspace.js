/** Single-page right-pane controller for the Student/Employee Portal (/portal). */
(function () {
  'use strict';

  const PANE_SELECTOR = '#portal-workspace-pane[data-portal-workspace-pane]';
  const STYLES_SELECTOR = '#portal-pane-styles';
  const DOCK_LINK_SELECTOR = '.portal-app-dock [data-portal-pane-link]';
  const CALLSLIPS_STRIP_SELECTOR = '[data-portal-callslips-strip]';
  const EMERGENCY_CARD_SELECTOR = '[data-portal-emergency-card]';
  const EMERGENCY_TOGGLE_SELECTOR = '[data-portal-emergency-toggle]';
  const PROFILE_STACK_SELECTOR = '[data-portal-profile-stack]';
  const EMERGENCY_DRAWER_SELECTOR = '[data-portal-emergency-drawer]';
  const PROFILE_SETTINGS_FORM_SELECTOR = '[data-profile-photo-form], [data-profile-photo-remove], [data-emergency-contact-form]';
  const AUTH_PATH_RE = /(?:^|\/)(?:auth|login|logout|sign-in|sign-out|signin|signout)(?:\/|$)/i;
  const IGNORED_CLICK_SELECTOR = [
    '[data-navigation="false"]',
    '[data-no-spa]',
    '[data-app-modal]',
    '[data-app-modal-open]',
    '[data-app-modal-close]',
    '[data-table-page]',
    '[data-table-reset]',
    '[download]',
    '[target]:not([target="_self"])',
    '[onclick]',
    '[hx-get]',
    '[hx-post]',
    '[hx-put]',
    '[hx-patch]',
    '[hx-delete]',
    '[data-hx-get]',
    '[data-hx-post]',
    '[data-hx-put]',
    '[data-hx-patch]',
    '[data-hx-delete]',
  ].join(', ');

  if (window.SynapsePortalWorkspace) {
    window.SynapsePortalWorkspace.sync();
    return;
  }

  const localDirty = new WeakSet();
  const initialConfirm = window.confirm;
  let localEditVersion = 0;
  let activeRequest = null;
  let badgeRefreshTimer = null;
  let badgeRefreshAbort = null;
  let emergencyObserver = null;
  let emergencyTimer = null;
  let isHydratingShell = false;
  let shellUnavailable = false;
  let preHiddenMain = null;
  let initializedListeners = false;

  /** Return the active portal workspace container if mounted in #main. */
  function getPane() {
    const pane = document.querySelector(PANE_SELECTOR);
    return pane && pane.isConnected ? pane : null;
  }

  /** Parse a same-origin HTTP(S) URL or return null. */
  function urlFor(value, base = location.href) {
    if (typeof value !== 'string' || !value.trim()) return null;
    try {
      const url = new URL(value, base);
      if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin || url.username || url.password) {
        return null;
      }
      return url;
    } catch (_) {
      return null;
    }
  }

  /** Normalize route path without /index.php prefix or trailing slash. */
  function normalizeRoutePath(pathname) {
    return pathname.replace(/^\/index\.php(?=\/)/, '').replace(/\/+$/, '') || '/';
  }

  /** Find the unified /portal shell URL from the mounted pane or Personal Health sidebar link. */
  function findPortalShellUrl(options = {}) {
    const pane = getPane();
    if (!options.sidebarOnly && pane?.dataset.workspaceUrl) {
      const url = urlFor(pane.dataset.workspaceUrl);
      if (url) return url;
    }
    const links = document.querySelectorAll('.sidebar-nav a.nav-link[href]');
    for (const a of links) {
      const url = urlFor(a.getAttribute('href'));
      if (!url) continue;
      const path = normalizeRoutePath(url.pathname);
      if (path === '/portal' || path.endsWith('/portal')) {
        return url;
      }
    }
    return options.sidebarOnly ? null : urlFor('/portal');
  }

  /** Derive the portal base path (typically /portal) from the mounted pane or sidebar. */
  function portalBasePath(pane = getPane()) {
    const shellUrl = pane?.dataset.workspaceUrl
      ? urlFor(pane.dataset.workspaceUrl)
      : findPortalShellUrl({ sidebarOnly: true });
    const parsed = shellUrl || new URL('/portal', location.origin);
    return normalizeRoutePath(parsed.pathname);
  }

  /** Map a same-origin URL to a Quick Services pane key, or null for non-pane routes. */
  function classifyPortalPane(value, pane = getPane()) {
    const url = value instanceof URL ? value : urlFor(String(value || ''));
    if (!url || AUTH_PATH_RE.test(url.pathname)) return null;

    const path = normalizeRoutePath(url.pathname);
    const base = portalBasePath(pane);
    if (path === base || path === `${base}/appointments`) {
      return 'appointments';
    }
    if (!path.startsWith(`${base}/`)) {
      return null;
    }

    const sub = path.slice(base.length + 1);
    if (/\/pdf$/i.test(sub)) return null;
    if (sub === 'announcements' || sub.startsWith('announcements/')) return 'announcements';
    if (sub === 'surveys' || sub.startsWith('surveys/')) return 'surveys';
    if (sub === 'referrals' || sub.startsWith('referrals/')) return 'referrals';
    if (sub === 'assessments/mi' || sub.startsWith('assessments/mi/')) return 'surveys';
    if (sub === 'evaluations' || sub.startsWith('evaluations/')) return 'evaluations';
    if (sub === 'visits' || sub.startsWith('visits/')) return 'visits';
    if (sub === 'call-slips' || sub.startsWith('call-slips/')) return 'callslips';
    if (sub === 'settings') return 'settings';
    return null;
  }

  /** Determine whether a POST form inside the pane has user-modified inputs. */
  function isTrackablePostForm(form) {
    if (!(form instanceof HTMLFormElement)) return false;
    if ((form.getAttribute('method') || form.method || 'GET').toUpperCase() === 'GET') return false;
    return !form.matches(PROFILE_SETTINGS_FORM_SELECTOR) &&
      !form.closest('[data-navigation-dirty="false"], [hx-post], [hx-put], [hx-patch], [hx-delete]');
  }

  /** Inspect live field state for Profile & Settings AJAX forms inside the pane. */
  function hasProfileSettingsUnsavedChanges(pane, exceptForm = null) {
    const settingsRoot = pane.querySelector('[data-profile-settings]');
    if (!settingsRoot) return false;

    const photoForm = settingsRoot.querySelector('[data-profile-photo-form]');
    if (photoForm && photoForm !== exceptForm) {
      const photoInput = photoForm.querySelector('input[type="file"]');
      if (photoInput?.files?.length) return true;
    }

    const contactForm = settingsRoot.querySelector('[data-emergency-contact-form]');
    if (contactForm && contactForm !== exceptForm) {
      const fields = [
        contactForm.querySelector('[name="emergency_contact_name"]'),
        contactForm.querySelector('[name="emergency_contact_relationship"]'),
        contactForm.querySelector('[name="emergency_contact_phone"]'),
      ].filter(Boolean);
      if (fields.some((field) => field.value !== field.defaultValue)) {
        return true;
      }
    }

    return false;
  }

  /** Return true if any POST form inside #portal-workspace-pane has unsaved edits. */
  function hasPaneUnsavedChanges(pane, exceptForm = null) {
    if (!pane) return false;
    if (hasProfileSettingsUnsavedChanges(pane, exceptForm)) {
      return true;
    }
    const otherForms = Array.from(pane.querySelectorAll('form')).filter(
      (form) => !form.matches(PROFILE_SETTINGS_FORM_SELECTOR),
    );
    if (typeof window.SynapseNavigation?.hasUnsavedChanges === 'function') {
      return Boolean(
        otherForms.length > 0 &&
        window.SynapseNavigation.hasUnsavedChanges(exceptForm, { within: otherForms }),
      );
    }
    return otherForms.some(
      (form) => form !== exceptForm && form.isConnected && localDirty.has(form) && isTrackablePostForm(form),
    );
  }

  /** Share document navigation's veto and pane-scoped dirty consent. */
  async function mayLeavePane(pane, target, options = {}) {
    const detail = {
      url: target.href,
      from: window.SynapseNavigation?.workspace?.currentUrl || pane.dataset.currentPaneUrl || location.href,
      root: pane,
      portalPaneSwap: true,
      popstate: Boolean(options.popEntry),
      dirty: hasPaneUnsavedChanges(pane, options.exceptForm || null),
    };
    const guardOptions = { ...options, within: [pane], isDirty: () => hasPaneUnsavedChanges(pane, options.exceptForm || null) };
    const bridge = window.SynapseNavigation?.workspace;
    if (bridge) return await bridge.mayLeave(detail, guardOptions);
    if (!document.dispatchEvent(new CustomEvent('synapse:before-navigate', { detail, cancelable: true }))) return false;
    if (options.bypassDirtyCheck || !detail.dirty) return true;
    const message = 'You have unsaved changes. Leave this page?';
    if (window.SynapseConfirm?.confirm && window.confirm === initialConfirm) {
      return Boolean(await window.SynapseConfirm.confirm({
        title: 'Unsaved changes', message, confirmLabel: 'Leave page', cancelLabel: 'Stay on page', tone: 'warning',
      }));
    }
    return Boolean(window.confirm(message));
  }

  /** Clear SynapseNavigation + local dirty markers for forms about to be replaced. */
  function clearPaneDirtyState(pane) {
    if (!pane) return;
    pane.querySelectorAll('form').forEach((form) => {
      localDirty.delete(form);
      window.SynapseNavigation?.markSaved?.(form);
    });
  }

  /** Synchronize .sidebar-nav link active state with the active pane or server response. */
  function syncSidebarActive(paneKey, incomingDoc = null) {
    const currentNav = document.querySelector('.sidebar-nav');
    if (!currentNav) return;

    const incomingNav = incomingDoc?.querySelector?.('.sidebar-nav');
    if (incomingNav) {
      const currentLinks = currentNav.querySelectorAll('a.nav-link');
      const incomingLinks = incomingNav.querySelectorAll('a.nav-link');
      if (currentLinks.length === incomingLinks.length) {
        const prevScroll = currentNav.scrollTop;
        incomingLinks.forEach((incoming, index) => {
          const link = currentLinks[index];
          const isActive = incoming.classList.contains('active');
          link.classList.toggle('active', isActive);
          if (incoming.hasAttribute('aria-current')) {
            link.setAttribute('aria-current', incoming.getAttribute('aria-current') || 'page');
          } else if (isActive) {
            link.setAttribute('aria-current', 'page');
          } else {
            link.removeAttribute('aria-current');
          }
        });
        currentNav.scrollTop = prevScroll;
        return;
      }
    }

    if (!paneKey) return;
    currentNav.querySelectorAll('a.nav-link[href]').forEach((link) => {
      const linkUrl = urlFor(link.getAttribute('href'));
      const active = Boolean(linkUrl && classifyPortalPane(linkUrl) === paneKey);
      link.classList.toggle('active', active);
      if (active) {
        link.setAttribute('aria-current', 'page');
      } else {
        link.removeAttribute('aria-current');
      }
    });
  }

  /** Sync active highlight across the left-column Quick Services dock and left sidebar. */
  function setActiveTile(paneKey, incomingDoc = null) {
    document.querySelectorAll(DOCK_LINK_SELECTOR).forEach((tile) => {
      const active = Boolean(paneKey) && tile.dataset.portalPaneLink === paneKey;
      tile.classList.toggle('is-active', active);
      if (active) {
        tile.setAttribute('aria-current', 'page');
      } else {
        tile.removeAttribute('aria-current');
      }
    });
    syncSidebarActive(paneKey, incomingDoc);
  }

  /** Resolve relative URLs within an imported DOM fragment against finalUrl. */
  function resolveUrls(root, baseHref) {
    if (!root) return;
    root.querySelectorAll('[href], [src], [action], [formaction], [poster]').forEach((el) => {
      for (const attr of ['href', 'src', 'action', 'formaction', 'poster']) {
        if (!el.hasAttribute(attr)) continue;
        const val = el.getAttribute(attr);
        if (!val || val.startsWith('#')) continue;
        try {
          el.setAttribute(attr, new URL(val, baseHref).href);
        } catch (_) {
          // Leave invalid attributes intact for native validation.
        }
      }
    });
  }

  /** Synchronize CSRF hidden fields and meta tags from an incoming HTML document. */
  function syncCsrfFromDocument(doc, response) {
    const name = doc.querySelector('meta[name="csrf-name"]')?.content;
    const hash = doc.querySelector('meta[name="csrf-token"]')?.content;
    const header = doc.querySelector('meta[name="csrf-header"]')?.content;
    if (name && hash) {
      window.SynapseCsrf?.sync({ name, hash, header });
    }
    if (response) {
      window.SynapseCsrf?.fromResponse(response);
    }
  }

  /** Update Quick Services counters, tooltips, and call-slip strip from a fetched /portal document. */
  function syncDockAndStripFromDocument(doc) {
    if (!doc) return;

    doc.querySelectorAll(DOCK_LINK_SELECTOR).forEach((incomingTile) => {
      const key = incomingTile.dataset.portalPaneLink;
      const currentTile = key
        ? document.querySelector(`.portal-app-dock [data-portal-pane-link="${CSS.escape(key)}"]`)
        : null;
      if (!currentTile) return;

      for (const attr of ['data-app-tooltip', 'aria-label']) {
        if (incomingTile.hasAttribute(attr)) {
          currentTile.setAttribute(attr, incomingTile.getAttribute(attr) || '');
        }
      }
      const existingBadge = currentTile.querySelector('.portal-app-badge');
      const incomingBadge = incomingTile.querySelector('.portal-app-badge');
      if (incomingBadge && existingBadge) {
        existingBadge.textContent = incomingBadge.textContent;
        existingBadge.setAttribute('aria-label', incomingBadge.getAttribute('aria-label') || '');
      } else if (incomingBadge && !existingBadge) {
        currentTile.prepend(document.importNode(incomingBadge, true));
      } else if (!incomingBadge && existingBadge) {
        existingBadge.remove();
      }
    });

    const pane = getPane();
    const currentStrip = pane?.dataset.activePane === 'appointments'
      ? pane.querySelector(CALLSLIPS_STRIP_SELECTOR)
      : null;
    const incomingStrip = pane?.dataset.activePane === 'appointments'
      ? doc.querySelector(CALLSLIPS_STRIP_SELECTOR)
      : null;
    if (currentStrip && incomingStrip) {
      const wasOpen = currentStrip.querySelector('#portalCallSlipsBody')?.classList.contains('show');
      const imported = document.importNode(incomingStrip, true);
      const body = imported.querySelector('#portalCallSlipsBody');
      if (body && typeof wasOpen === 'boolean') {
        body.classList.toggle('show', wasOpen);
      }
      currentStrip.replaceWith(imported);
    } else if (currentStrip && !incomingStrip) {
      currentStrip.remove();
    }

    const currentEmergencyCard = document.querySelector(EMERGENCY_CARD_SELECTOR);
    const incomingEmergencyCard = doc.querySelector(EMERGENCY_CARD_SELECTOR);
    if (currentEmergencyCard && incomingEmergencyCard) {
      const currentContent = currentEmergencyCard.querySelector('[data-portal-emergency-content]');
      const incomingContent = incomingEmergencyCard.querySelector('[data-portal-emergency-content]');
      if (currentContent && incomingContent) {
        currentContent.replaceWith(document.importNode(incomingContent, true));
      } else {
        currentEmergencyCard.replaceWith(document.importNode(incomingEmergencyCard, true));
      }
    }
  }

  /** Stop observing any pending emergency contact feedback node. */
  function stopEmergencyObserver() {
    if (emergencyObserver) {
      emergencyObserver.disconnect();
      emergencyObserver = null;
    }
    if (emergencyTimer) {
      clearTimeout(emergencyTimer);
      emergencyTimer = null;
    }
  }

  /** Sync the left-rail Emergency Contact card once profile-settings.js reports save success. */
  function watchEmergencyContactSave(pane) {
    stopEmergencyObserver();
    const settingsRoot = pane.querySelector('[data-profile-settings]') || pane;
    const feedback = settingsRoot.querySelector('[data-emergency-contact-feedback]');
    if (!feedback) return;

    emergencyObserver = new MutationObserver(() => {
      if (!feedback.isConnected) {
        stopEmergencyObserver();
        return;
      }
      if (feedback.classList.contains('text-success') && feedback.textContent.trim() !== '') {
        stopEmergencyObserver();
        scheduleBadgeRefresh();
      } else if (feedback.classList.contains('text-danger') && feedback.textContent.trim() !== '') {
        stopEmergencyObserver();
      }
    });
    emergencyObserver.observe(feedback, {
      childList: true,
      characterData: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['class'],
    });
    emergencyTimer = window.setTimeout(stopEmergencyObserver, 15000);
  }

  /** Replace #portal-pane-styles with incoming sub-view #page-styles, or clear for appointments. */
  function syncPaneStyles(paneKey, incomingDoc, finalHref) {
    const styleSlot = document.querySelector(STYLES_SELECTOR);
    if (!styleSlot) return;

    if (paneKey === 'appointments') {
      styleSlot.replaceChildren();
      return;
    }

    const incomingStyles = incomingDoc.querySelector('#page-styles');
    if (!incomingStyles || incomingStyles.querySelector('script')) {
      styleSlot.replaceChildren();
      return;
    }

    const imported = document.importNode(incomingStyles, true);
    resolveUrls(imported, finalHref);
    styleSlot.replaceChildren(...Array.from(imported.childNodes));
  }

  /** Check whether an external script is already loaded in the host document. */
  function isScriptAlreadyLoaded(srcHref) {
    const cleanTarget = urlFor(srcHref);
    if (!cleanTarget) return false;
    const targetPath = normalizeRoutePath(cleanTarget.pathname);
    if (targetPath.endsWith('/portal-workspace.js') || targetPath.endsWith('/queue-live.js')) {
      return true;
    }
    return Array.from(document.scripts).some((script) => {
      if (!script.src) return false;
      const existing = urlFor(script.src);
      return Boolean(existing && normalizeRoutePath(existing.pathname) === targetPath);
    });
  }

  /** Execute sub-view inline and external scripts sequentially in #portal-pane-scripts. */
  async function runPaneScripts(scriptNodes, finalHref, signal) {
    let container = document.getElementById('portal-pane-scripts');
    if (!container) {
      container = document.createElement('div');
      container.id = 'portal-pane-scripts';
      container.hidden = true;
      document.body.appendChild(container);
    }
    container.replaceChildren();

    for (const source of scriptNodes) {
      if (signal.aborted) return;
      const type = (source.type || '').trim();
      if (type && !/^(?:text|application)\/(?:javascript|ecmascript)$/i.test(type)) continue;

      const rawSrc = source.getAttribute('src');
      if (rawSrc) {
        const resolved = urlFor(rawSrc, finalHref);
        if (!resolved || isScriptAlreadyLoaded(resolved.href)) continue;
        await new Promise((resolve) => {
          const el = document.createElement('script');
          el.src = resolved.href;
          el.onload = () => resolve();
          el.onerror = () => resolve();
          container.appendChild(el);
        });
      } else {
        const text = (source.textContent || '').trim();
        if (!text) continue;
        const el = document.createElement('script');
        el.textContent = text;
        container.appendChild(el);
      }
    }
  }

  /** Build DOM nodes to render inside #portal-workspace-pane from a fetched HTML document. */
  function extractPanePayload(paneKey, incomingDoc, finalHref) {
    const scriptNodes = [];
    const fragment = document.createDocumentFragment();

    if (paneKey === 'appointments') {
      const incomingPane = incomingDoc.querySelector(PANE_SELECTOR);
      const sourceRoot = incomingPane || incomingDoc.querySelector('#my-appointments');
      if (!sourceRoot) return null;

      const imported = document.importNode(sourceRoot, true);
      resolveUrls(imported, finalHref);
      imported.querySelectorAll('script').forEach((s) => s.remove());

      if (incomingPane) {
        fragment.append(...Array.from(imported.childNodes));
      } else {
        fragment.append(imported);
      }
      return { fragment, scriptNodes };
    }

    const incomingMain = incomingDoc.querySelector('#main .main-content');
    const incomingSection = incomingMain?.querySelector(':scope > .section');
    if (!incomingMain || !incomingSection) return null;

    // Header strip showing sub-view title + page_actions (e.g. "Back to Portal").
    const titleEl = incomingMain.querySelector('.pagetitle .page-title-copy h1');
    const actionsEl = incomingMain.querySelector('.pagetitle .page-actions');
    const titleText = (titleEl?.textContent || '').trim();
    const hasActions = Boolean(actionsEl && actionsEl.children.length > 0);
    if (titleText || hasActions) {
      const header = document.createElement('div');
      header.className = 'portal-workspace-header pb-2 mb-3 border-bottom';

      if (titleText) {
        const titleWrap = document.createElement('div');
        const h2 = document.createElement('h2');
        h2.className = 'h5 fw-bold mb-0 portal-workspace-title';
        h2.textContent = titleText;
        titleWrap.appendChild(h2);
        header.appendChild(titleWrap);
      }

      if (hasActions) {
        const actionsWrap = document.createElement('div');
        actionsWrap.className = 'd-flex flex-wrap gap-2 portal-workspace-actions ms-auto';
        const importedActions = document.importNode(actionsEl, true);
        resolveUrls(importedActions, finalHref);
        actionsWrap.append(...Array.from(importedActions.childNodes));
        header.appendChild(actionsWrap);
      }

      fragment.appendChild(header);
    }

    // Preserve any session flash alerts emitted above .section.
    incomingMain.querySelectorAll(':scope > .alert').forEach((alert) => {
      const importedAlert = document.importNode(alert, true);
      resolveUrls(importedAlert, finalHref);
      fragment.appendChild(importedAlert);
    });

    const importedSection = document.importNode(incomingSection, true);
    resolveUrls(importedSection, finalHref);

    // Move executable scripts out of the section fragment so they execute after mount.
    importedSection.querySelectorAll('script').forEach((script) => {
      scriptNodes.push(script);
      script.remove();
    });

    const pageScripts = incomingDoc.querySelector('#page-scripts');
    if (pageScripts) {
      pageScripts.querySelectorAll('script').forEach((script) => {
        scriptNodes.push(script);
      });
    }

    fragment.append(...Array.from(importedSection.childNodes));
    return { fragment, scriptNodes };
  }

  /** Swap the right-hand workspace pane using an already-parsed HTML Document. */
  async function applyDocumentToPane(pane, incomingDoc, finalHref, response, options = {}) {
    const finalUrl = urlFor(finalHref);
    if (!finalUrl) return false;

    const currentScope = document.body?.dataset.navigationScope;
    const incomingScope = incomingDoc.body?.dataset.navigationScope;
    if (currentScope && incomingScope && currentScope !== incomingScope) {
      location.assign(finalUrl.href);
      return false;
    }

    const paneKey = classifyPortalPane(finalUrl, pane);
    if (!paneKey) {
      location.assign(finalUrl.href);
      return false;
    }

    const payload = extractPanePayload(paneKey, incomingDoc, finalUrl.href);
    if (!payload) return false;

    if (paneKey === 'appointments') {
      syncDockAndStripFromDocument(incomingDoc);
    }

    stopEmergencyObserver();

    // Tear down sub-view listeners and widgets strictly inside #portal-workspace-pane.
    document.dispatchEvent(
      new CustomEvent('synapse:before-swap', {
        detail: { url: finalUrl.href, root: pane, portalPaneSwap: true },
      }),
    );

    clearPaneDirtyState(pane);
    window.SynapseNavigation?.workspace?.dispose(pane);
    syncPaneStyles(paneKey, incomingDoc, finalUrl.href);

    const doSwap = () => {
      window.Alpine?.destroyTree?.(pane);
      pane.replaceChildren(payload.fragment);
      window.Alpine?.initTree?.(pane);
    };
    if (window.Alpine?.mutateDom) {
      window.Alpine.mutateDom(doSwap);
    } else {
      doSwap();
    }

    pane.dataset.activePane = paneKey;
    pane.dataset.currentPaneUrl = finalUrl.href;
    setActiveTile(paneKey, incomingDoc);
    if (!options.skipCsrfSync) {
      syncCsrfFromDocument(incomingDoc, response);
    }

    const historyMode = options.history || 'push';
    if (options.navigation) {
      if (!options.navigation.commitHistory(finalUrl.href, historyMode)) return false;
    } else if (historyMode !== 'none' && window.SynapseNavigation?.updateHistory) {
      window.SynapseNavigation.updateHistory(finalUrl.href, historyMode);
    }

    if (incomingDoc.title) {
      document.title = incomingDoc.title;
    }

    if (payload.scriptNodes.length > 0) {
      const abortCtrl = options.abortController || new AbortController();
      await runPaneScripts(payload.scriptNodes, finalUrl.href, abortCtrl.signal);
    }

    window.htmx?.process?.(pane);
    const heading = pane.querySelector('.portal-workspace-title, h1, h2, [aria-labelledby] [id]');
    heading?.setAttribute('tabindex', '-1');
    heading?.focus({ preventScroll: true });
    document.dispatchEvent(
      new CustomEvent('synapse:page-load', {
        detail: { root: pane, url: finalUrl.href, portalPane: paneKey, popstate: Boolean(options.popEntry) },
      }),
    );
    document.dispatchEvent(
      new CustomEvent('synapse:page-ready', {
        detail: { root: pane, url: finalUrl.href, portalPane: paneKey },
      }),
    );

    const restoredScroll = options.popEntry?.scroll || (Array.isArray(options.scroll) ? options.scroll : null);
    if (restoredScroll) {
      window.scrollTo({ left: Number(restoredScroll[0]) || 0, top: Number(restoredScroll[1]) || 0, behavior: 'instant' });
    } else if (options.scroll === 'top' || (historyMode === 'push' && !options.preserveScroll)) {
      const paneTop = pane.getBoundingClientRect().top;
      const isStackedViewport = window.matchMedia('(max-width: 1199.98px)').matches;
      const shouldScrollToPane = options.scroll === 'top' || paneTop < 80 || (isStackedViewport && paneTop > 120);
      if (shouldScrollToPane) {
        window.scrollTo({
          top: Math.max(0, window.scrollY + paneTop - 76),
          behavior: 'smooth',
        });
      }
    }

    if (paneKey !== 'appointments' && !options.skipBadgeRefresh) {
      scheduleBadgeRefresh();
    }
    return true;
  }

  /** Fetch and swap the right-hand workspace pane for GET links or POST form submissions. */
  async function navigateWorkspace(value, options = {}) {
    const pane = getPane();
    const target = urlFor(value);
    const bridge = window.SynapseNavigation?.workspace;
    if (!pane || !target || bridge?.restoring) return false;

    const targetKey = classifyPortalPane(target, pane);
    if (!targetKey) {
      if (window.SynapseNavigation?._portalWorkspaceRaw) {
        return await window.SynapseNavigation._portalWorkspaceRaw(target.href, options);
      }
      location.assign(target.href);
      return false;
    }

    if (!await mayLeavePane(pane, target, options)) {
      if (options.popEntry) bridge?.restoreHistory(options.popEntry);
      return false;
    }
    const approvedEdits = bridge?.editVersion ?? localEditVersion;
    activeRequest?.abort();
    const navigation = bridge?.begin(options.popEntry);
    if (bridge && !navigation) {
      if (options.popEntry && !bridge.restoring) bridge.restoreHistory(options.popEntry);
      return false;
    }
    const controller = navigation?.controller || new AbortController();
    activeRequest = controller;
    const isCurrent = () => activeRequest === controller && !controller.signal.aborted && pane === getPane() &&
      (!navigation || navigation.isCurrent());
    let applied = false, restoreEntry = false, timedOut = false;
    const timeout = window.setTimeout(() => { timedOut = true; controller.abort(); }, 20000);

    const previousKey = pane.dataset.activePane || 'appointments';
    const previousPointerEvents = pane.style.pointerEvents;
    pane.classList.add('is-loading');
    // The outgoing editor stays usable until consent is rechecked before swap.
    // Preserve the existing opacity cue without the loading class's pointer lock.
    pane.style.pointerEvents = 'auto';
    pane.setAttribute('aria-busy', 'true');
    setActiveTile(targetKey);

    try {
      const fetchInit = {
        method: options.method || 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'follow',
        signal: controller.signal,
        headers: {
          Accept: 'text/html',
          'X-Synapse-Navigation': '1',
          'X-Synapse-Portal-Pane': '1',
          ...window.SynapseCsrf?.headers(),
        },
      };
      if (options.body) fetchInit.body = options.body;

      const response = await fetch(target.href, fetchInit);
      if (!isCurrent()) return false;
      if (window.SynapseAuth?.handle(response)) return false;

      const finalUrl = urlFor(response.url || target.href);
      if (!finalUrl || [401, 403, 419].includes(response.status) || AUTH_PATH_RE.test(finalUrl.pathname)) {
        location.assign((finalUrl || target).href);
        return false;
      }
      if (!response.ok) throw new Error(`Unable to load portal section (${response.status}).`);
      if (!/\btext\/html\b/i.test(response.headers.get('Content-Type') || '')) {
        location.assign(finalUrl.href);
        return false;
      }
      if (!finalUrl.hash) finalUrl.hash = target.hash;

      const html = await response.text();
      if (!isCurrent()) return false;
      const incomingDoc = new DOMParser().parseFromString(html, 'text/html');
      const changed = (bridge?.editVersion ?? localEditVersion) !== approvedEdits;
      // The old pane remains usable during GET. Recheck late edits and newly opened
      // modal/save vetoes before disposal, without a second prompt for the same draft.
      if (!await mayLeavePane(pane, finalUrl, { ...options, bypassDirtyCheck: options.bypassDirtyCheck || !changed })) {
        restoreEntry = true;
        return false;
      }
      if (!isCurrent()) return false;
      const currentScope = document.body?.dataset.navigationScope;
      const incomingScope = incomingDoc.body?.dataset.navigationScope;
      const needsFullDocument = !classifyPortalPane(finalUrl, pane) ||
        (currentScope && incomingScope && currentScope !== incomingScope);
      applied = await applyDocumentToPane(pane, incomingDoc, finalUrl.href, response, {
        ...options,
        history: options.history || 'push',
        abortController: controller,
        navigation,
      });
      restoreEntry = !applied && !needsFullDocument;
      return applied;
    } catch (error) {
      if (activeRequest !== controller || (controller.signal.aborted && !timedOut)) return false;
      restoreEntry = true;
      window.SynapseToast?.show(
        timedOut ? 'Loading timed out. Your current portal view has been kept.' :
          error?.message || 'Unable to load the selected section. Staying on current portal view.',
        { type: 'error', key: 'portal-workspace' },
      );
      return false;
    } finally {
      clearTimeout(timeout);
      navigation?.finish();
      if (activeRequest === controller) {
        activeRequest = null;
        pane.classList.remove('is-loading');
        pane.style.pointerEvents = previousPointerEvents;
        pane.removeAttribute('aria-busy');
        if (!applied) setActiveTile(previousKey);
        if (restoreEntry && options.popEntry) bridge?.restoreHistory(options.popEntry);
      }
    }
  }

  /** Quietly refresh Quick Services notification counters and call slips from /portal. */
  function scheduleBadgeRefresh() {
    const pane = getPane();
    if (!pane) return;
    clearTimeout(badgeRefreshTimer);
    badgeRefreshTimer = window.setTimeout(async () => {
      const currentPane = getPane();
      if (!currentPane) return;
      badgeRefreshAbort?.abort();
      const ctrl = new AbortController();
      badgeRefreshAbort = ctrl;
      try {
        const url = currentPane.dataset.workspaceUrl || '/portal';
        const response = await fetch(url, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          signal: ctrl.signal,
          headers: { Accept: 'text/html', 'X-Synapse-Portal-Badge-Sync': '1' },
        });
        if (!response.ok || ctrl.signal.aborted) return;
        const html = await response.text();
        if (ctrl.signal.aborted || !getPane()) return;
        const doc = new DOMParser().parseFromString(html, 'text/html');
        syncDockAndStripFromDocument(doc);
      } catch (_) {
        // Best-effort badge refresh only.
      }
    }, 180);
  }

  /** Mount /portal first if needed, then swap #portal-workspace-pane to targetHref. */
  async function mountAndOpenWorkspace(targetHref, targetPaneKey) {
    document.body.classList.remove('sidebar-open');
    const pane = getPane();
    if (pane) {
      return await navigateWorkspace(targetHref, { history: 'push' });
    }

    const portalShellUrl = findPortalShellUrl({ sidebarOnly: true });
    const rawNav = window.SynapseNavigation?._portalWorkspaceRaw
      || window.SynapseNavigation?.navigate?.bind(window.SynapseNavigation);
    if (shellUnavailable && rawNav) return await rawNav(targetHref, { history: 'push' });
    if (!portalShellUrl || !rawNav) {
      location.assign(targetHref);
      return false;
    }

    if (targetPaneKey === 'appointments') {
      return await rawNav(portalShellUrl.href, { history: 'push' });
    }

    const mounted = await rawNav(portalShellUrl.href, { history: 'push' });
    if (!mounted || !getPane()) {
      return false;
    }
    return await navigateWorkspace(targetHref, {
      history: 'replace',
      bypassDirtyCheck: true,
      scroll: 'top',
    });
  }

  /** Determine if a clicked anchor should swap inside #portal-workspace-pane or mount /portal first. */
  function shouldInterceptLink(link, pane) {
    if (link.closest(IGNORED_CLICK_SELECTOR)) return false;

    for (let ancestor = link; ancestor; ancestor = ancestor.parentElement) {
      if (ancestor.hasAttribute('data-bs-toggle') && ancestor.dataset.bsToggle !== 'tooltip') {
        return false;
      }
    }

    // Preserve live-filters.js handling of tabs, pagination, and filter resets inside sub-views.
    if (link.matches('[data-filter-link], [data-filter-reset]') && link.closest('[data-live-filters]')) {
      return false;
    }

    if (link.matches('.sidebar-nav a.nav-link')) {
      return Boolean(pane || findPortalShellUrl({ sidebarOnly: true }));
    }

    if (!pane) return false;

    return Boolean(
      link.closest('.portal-app-dock') ||
      pane.contains(link) ||
      link.hasAttribute('data-navigation') ||
      link.hasAttribute('data-portal-pane-target') ||
      document.getElementById('main')?.contains(link),
    );
  }

  /** Capture-phase click handler running before navigation.js. */
  function onDocumentClick(event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ||
        window.SynapseNavigation?.workspace?.restoring) {
      return;
    }

    const target = event.target instanceof Element ? event.target : event.target?.parentElement;
    if (!target) return;

    const pane = getPane();
    const emergencyToggle = target.closest(EMERGENCY_TOGGLE_SELECTOR);
    if (emergencyToggle) {
      event.preventDefault();
      const stack = emergencyToggle.closest(PROFILE_STACK_SELECTOR);
      const isOpen = stack?.classList.toggle('is-emergency-open') ?? false;
      emergencyToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      stack?.querySelector(EMERGENCY_DRAWER_SELECTOR)?.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
      return;
    }

    const link = target.closest('a[href]');
    if (!link || !shouldInterceptLink(link, pane)) return;

    const rawHref = (link.getAttribute('href') || '').trim();
    if (!rawHref || rawHref.startsWith('#')) return;

    const url = urlFor(rawHref);
    if (!url) return;

    const targetPaneKey = classifyPortalPane(url, pane);
    if (!targetPaneKey) return;

    event.preventDefault();
    event.stopPropagation();
    void mountAndOpenWorkspace(url.href, targetPaneKey);
  }

  /** Handle standard non-AJAX POST forms inside sub-views so redirects stay in-pane. */
  function onDocumentSubmit(event) {
    const pane = getPane();
    const form = event.target;
    if (!pane || !(form instanceof HTMLFormElement) || !pane.contains(form)) {
      return;
    }

    if (form.matches('[data-emergency-contact-form]')) {
      watchEmergencyContactSave(pane);
      return;
    }

    if (event.defaultPrevented) {
      return;
    }

    if ((form.getAttribute('method') || form.method || 'GET').toUpperCase() !== 'POST') return;
    if (form.matches(`[data-background-submit], [data-filter-form], ${PROFILE_SETTINGS_FORM_SELECTOR}, #survey-response-form, #guidance-evaluation-form, #mi-survey-form`)) {
      return;
    }

    const submitter = event.submitter;
    const rawAction = submitter?.getAttribute('formaction') || form.getAttribute('action') || location.href;
    const actionUrl = urlFor(rawAction);
    const paneKey = actionUrl ? classifyPortalPane(actionUrl, pane) : null;
    if (!actionUrl || !paneKey || paneKey === 'appointments') return;

    event.preventDefault();
    event.stopPropagation();
    const formData = submitter ? new FormData(form, submitter) : new FormData(form);
    void navigateWorkspace(actionUrl.href, {
      method: 'POST',
      body: formData,
      history: 'replace',
      bypassDirtyCheck: true,
      exceptForm: form,
    });
  }

  /** Wrap window.SynapseNavigation methods so mutation refreshes and saved markers stay synced. */
  function installNavigationWrapper() {
    const nav = window.SynapseNavigation;
    if (!nav || nav._portalWorkspaceWrapped) return;
    const rawNavigate = nav.navigate.bind(nav);
    const rawMarkSaved = typeof nav.markSaved === 'function' ? nav.markSaved.bind(nav) : null;
    nav._portalWorkspaceRaw = rawNavigate;
    nav._portalWorkspaceWrapped = true;
    nav.navigate = async function (value, options = {}) {
      const pane = getPane();
      if (pane && !options?.forceFullMain) {
        const target = urlFor(value);
        if (target && classifyPortalPane(target, pane)) {
          return await navigateWorkspace(target.href, {
            ...options,
            history: options.history || 'push',
            preserveScroll: options.preserveScroll || Array.isArray(options.scroll),
          });
        }
      }
      return await rawNavigate(value, options);
    };
    if (rawMarkSaved) {
      nav.markSaved = function (form, ...rest) {
        if (form) {
          localDirty.delete(form);
        }
        return rawMarkSaved(form, ...rest);
      };
    }
  }

  /** Sync active state when #portal-workspace-pane is present (initial load or soft nav return). */
  function sync() {
    installNavigationWrapper();
    const pane = getPane();
    if (!pane) return;
    if (!pane.dataset.currentPaneUrl) {
      pane.dataset.currentPaneUrl = location.href;
    }
    const activeKey = classifyPortalPane(pane.dataset.currentPaneUrl, pane) || pane.dataset.activePane || 'appointments';
    pane.dataset.activePane = activeKey;
    setActiveTile(activeKey);
  }

  /** Return portal shell hydration metadata when loaded directly on a portal sub-route. */
  function getStandaloneHydrationTarget() {
    if (getPane()) return null;
    const shellUrl = findPortalShellUrl({ sidebarOnly: true });
    if (!shellUrl) return null;
    const paneKey = classifyPortalPane(location.href);
    if (!paneKey || paneKey === 'appointments') return null;
    const main = document.getElementById('main');
    if (!main) return null;
    return { shellUrl, paneKey, main };
  }

  /** Load any external #page-scripts declared on /portal that are not yet in host document. */
  async function loadShellScripts(shellDoc, baseHref) {
    const container = document.getElementById('page-scripts');
    const incomingScripts = shellDoc?.querySelector('#page-scripts');
    if (!container || !incomingScripts) return;

    for (const source of incomingScripts.querySelectorAll('script[src]')) {
      const resolved = urlFor(source.getAttribute('src'), baseHref);
      if (!resolved) continue;
      const targetPath = normalizeRoutePath(resolved.pathname);
      if (targetPath.endsWith('/portal-workspace.js')) continue;

      const alreadyPresent = Array.from(document.scripts).some((script) => {
        if (!script.src) return false;
        const existing = urlFor(script.src);
        return Boolean(existing && normalizeRoutePath(existing.pathname) === targetPath);
      });
      if (alreadyPresent) continue;

      await new Promise((resolve) => {
        const el = document.createElement('script');
        el.src = resolved.href;
        el.onload = () => resolve();
        el.onerror = () => resolve();
        container.appendChild(el);
      });
    }
  }

  /** Clear pre-paint hydration hiding once the shell has mounted or fallen back. */
  function clearHydrationPreHide() {
    if (window.__portalHydrationFallbackTimer) {
      clearTimeout(window.__portalHydrationFallbackTimer);
      window.__portalHydrationFallbackTimer = null;
    }
    document.documentElement.classList.remove('portal-shell-hydrating');
    if (preHiddenMain) {
      preHiddenMain.style.opacity = '';
      preHiddenMain = null;
    }
    const currentMain = document.getElementById('main');
    if (currentMain) currentMain.style.opacity = '';
  }

  /** Hydrate direct loads/refreshes of portal sub-routes into the unified /portal workspace shell. */
  async function hydrateStandaloneSubView() {
    if (isHydratingShell) return false;
    const target = getStandaloneHydrationTarget();
    if (!target) {
      clearHydrationPreHide();
      return false;
    }

    const { shellUrl, main } = target;
    const standaloneHref = location.href;
    isHydratingShell = true;
    preHiddenMain = main;
    main.style.opacity = '0';

    try {
      const prefetch = window.__portalShellPrefetch;
      window.__portalShellPrefetch = null;
      const canUsePrefetch = Boolean(
        prefetch &&
        prefetch.href === shellUrl.href &&
        prefetch.promise &&
        typeof prefetch.promise.then === 'function'
      );
      let response = canUsePrefetch ? await prefetch.promise : null;
      if (!response) {
        response = await fetch(shellUrl.href, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          redirect: 'follow',
          headers: {
            Accept: 'text/html',
            'X-Synapse-Navigation': '1',
            'X-Synapse-Portal-Badge-Sync': '1',
          },
        });
      }
      // /portal is only an optional shell for independently authorized sub-routes.
      // A denied shell must not replace the functioning standalone page.
      const finalShellUrl = urlFor(response.url || shellUrl.href);
      if (response.status === 403 && finalShellUrl?.href === shellUrl.href) {
        shellUnavailable = true;
        return false;
      }
      if (window.SynapseAuth?.handle(response)) return false;
      if (!finalShellUrl || [401, 403, 419].includes(response.status) || AUTH_PATH_RE.test(finalShellUrl.pathname)) {
        location.assign((finalShellUrl || shellUrl).href);
        return false;
      }
      if (!response.ok) return false;

      const contentType = response.headers.get('Content-Type') || '';
      if (!/\btext\/html\b/i.test(contentType)) return false;

      const html = await response.text();
      if (getPane() || location.href !== standaloneHref || document.getElementById('main') !== main ||
          document.body.classList.contains('is-navigating')) return false;

      const shellDoc = new DOMParser().parseFromString(html, 'text/html');
      const currentScope = document.body?.dataset.navigationScope;
      const incomingScope = shellDoc.body?.dataset.navigationScope;
      if (currentScope && incomingScope && currentScope !== incomingScope) {
        location.assign(finalShellUrl.href);
        return false;
      }

      const shellMain = shellDoc.querySelector('#main');
      if (!shellMain || !shellMain.querySelector(PANE_SELECTOR)) {
        return false;
      }

      const liveMain = document.getElementById('main');
      if (!liveMain || !liveMain.isConnected) return false;
      // Hydration is optional; never discard a live draft or bypass an open modal/save.
      if (window.SynapseNavigation?.hasUnsavedChanges(null, { within: [liveMain] })) return false;
      const hydrationDetail = { url: standaloneHref, from: standaloneHref, root: liveMain, portalHydration: true };
      if (!document.dispatchEvent(new CustomEvent('synapse:before-navigate', { detail: hydrationDetail, cancelable: true })) ||
          hydrationDetail.dirty) return false;

      const subViewDoc = document.cloneNode(true);
      // Binding markers belong to live node identities, not imported clones.
      subViewDoc.querySelector('#survey-response-form')?.removeAttribute('data-bound');
      // The live sub-view was already enhanced by custom-dropdown.js; restore native
      // <select> markup in the clone so it is upgraded exactly once after mounting.
      subViewDoc.querySelectorAll('.bd-select').forEach((wrap) => {
        const select = wrap.querySelector(':scope > select');
        if (select) {
          select.classList.remove('bd-select-source');
          select.removeAttribute('data-bd-select');
          select.removeAttribute('tabindex');
          select.removeAttribute('aria-hidden');
          wrap.before(select);
        }
        wrap.remove();
      });
      const subViewHref = location.href;
      const savedScroll = { x: window.scrollX, y: window.scrollY };

      document.dispatchEvent(
        new CustomEvent('synapse:before-swap', {
          detail: { url: finalShellUrl.href, root: liveMain },
        }),
      );
      window.SynapseNavigation?.workspace?.dispose(liveMain);

      const livePageStyles = document.getElementById('page-styles');
      const shellPageStyles = shellDoc.querySelector('#page-styles');
      if (livePageStyles && shellPageStyles && !shellPageStyles.querySelector('script')) {
        const importedStyles = document.importNode(shellPageStyles, true);
        resolveUrls(importedStyles, finalShellUrl.href);
        livePageStyles.replaceChildren(...Array.from(importedStyles.childNodes));
      }

      const importedMain = document.importNode(shellMain, true);
      importedMain.querySelectorAll('script').forEach((s) => s.remove());
      resolveUrls(importedMain, finalShellUrl.href);

      const doMount = () => {
        window.Alpine?.destroyTree?.(liveMain);
        liveMain.replaceChildren(...Array.from(importedMain.childNodes));
        window.Alpine?.initTree?.(liveMain);
      };
      if (window.Alpine?.mutateDom) {
        window.Alpine.mutateDom(doMount);
      } else {
        doMount();
      }

      await loadShellScripts(shellDoc, finalShellUrl.href);

      const mountedPane = getPane();
      if (!mountedPane) return false;

      const applied = await applyDocumentToPane(mountedPane, subViewDoc, subViewHref, null, {
        history: 'none',
        preserveScroll: true,
        skipBadgeRefresh: true,
        skipCsrfSync: true,
      });

      syncCsrfFromDocument(shellDoc, response);
      if (savedScroll.x || savedScroll.y) {
        window.scrollTo(savedScroll.x, savedScroll.y);
      }
      return applied;
    } catch (_) {
      return false;
    } finally {
      isHydratingShell = false;
      clearHydrationPreHide();
    }
  }

  function init() {
    if (!initializedListeners) {
      initializedListeners = true;
      document.addEventListener('click', onDocumentClick, true);
      document.addEventListener('submit', onDocumentSubmit, false);

      const noteDirty = (event) => {
        const pane = getPane();
        const form = event.target?.form || event.target?.closest?.('form');
        if (pane && form && pane.contains(form)) {
          ++localEditVersion;
          if (isTrackablePostForm(form)) localDirty.add(form);
        }
      };
      document.addEventListener('input', noteDirty);
      document.addEventListener('change', noteDirty);
      document.addEventListener('reset', (event) => {
        if (!event.defaultPrevented && event.target instanceof HTMLFormElement) {
          localDirty.delete(event.target);
        }
      });

      document.addEventListener('synapse:page-load', () => sync());
      document.addEventListener('synapse:before-swap', (event) => {
        const root = event.detail?.root;
        const pane = getPane();
        if (!root || !pane || (root !== pane && !root.contains(pane))) return;
        stopEmergencyObserver();
        clearTimeout(badgeRefreshTimer);
        badgeRefreshAbort?.abort();
        if (!event.detail.portalPaneSwap) activeRequest?.abort();
      });
      document.addEventListener('synapse:filter-updated', (event) => {
        if (getPane() && event.detail?.root?.closest?.('#portal-workspace-pane')) {
          scheduleBadgeRefresh();
        }
      });
      document.addEventListener('refresh_committed', () => {
        if (getPane()) scheduleBadgeRefresh();
      });
      document.addEventListener('synapse:emergency-contact-updated', () => {
        if (getPane()) scheduleBadgeRefresh();
      });
    }

    sync();
    if (!getPane()) {
      void hydrateStandaloneSubView();
    } else {
      clearHydrationPreHide();
    }
  }

  window.SynapsePortalWorkspace = Object.freeze({
    isActive: () => Boolean(getPane()),
    owns: (url) => Boolean(classifyPortalPane(url)),
    navigate: (url, options = {}) => navigateWorkspace(url, options),
    refreshBadges: () => scheduleBadgeRefresh(),
    sync,
  });

  if (document.readyState === 'loading') {
    const preHideTarget = getStandaloneHydrationTarget();
    if (preHideTarget) {
      preHiddenMain = preHideTarget.main;
      preHiddenMain.style.opacity = '0';
    }
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();

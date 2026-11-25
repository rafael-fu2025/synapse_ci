/**
* Template Name: NiceAdmin v3
* Updated: 2026 with multi-section sidebar, dark mode, modern UI behaviors
* License: https://bootstrapmade.com/license/
*/

(function() {
  "use strict";

  /**
   * Easy selectors
   */
  const select = (el, all = false) => {
    el = el.trim();
    return all ? [...document.querySelectorAll(el)] : document.querySelector(el);
  };

  const on = (type, el, listener, all = false) => {
    if (all) select(el, all).forEach(e => e.addEventListener(type, listener));
    else select(el, all).addEventListener(type, listener);
  };

  const onscroll = (el, listener) => el.addEventListener('scroll', listener);

  /**
   * Sidebar toggle (collapse/expand)
   * App-shell desktops keep an interactive rail; demos retain off-canvas behavior.
   */
  const sidebar = select('.sidebar');
  const sidebarToggles = document.querySelectorAll('.sidebar-toggle, .toggle-sidebar-btn');
  const appShell = document.body.classList.contains('app-shell');
  const sidebarDesktop = window.matchMedia(appShell
    ? '(min-width: 992px)' : '(min-width: 1200px)');
  let collapsedPreference = document.body.dataset.sidebarCollapsed !== undefined
    ? document.body.dataset.sidebarCollapsed === 'true'
    : document.body.classList.contains('sidebar-collapsed');
  let drawerOpener = null;
  const sidebarTips = new Map();
  let sidebarTipsEnabled = false;
  const railCollapsed = () => appShell && sidebarDesktop.matches
    && document.body.classList.contains('sidebar-collapsed');
  const hideSidebarTips = () => sidebarTips.forEach(tip => tip.hide());
  const syncSidebarTips = () => {
    const enabled = railCollapsed();
    if (enabled === sidebarTipsEnabled) return;
    sidebarTipsEnabled = enabled;
    sidebarTips.forEach(tip => {
      if (enabled) tip.enable();
      else { tip.hide(); tip.disable(); }
    });
  };
  if (appShell) {
    document.querySelectorAll('.sidebar-nav a.nav-link').forEach(link => {
      const label = link.querySelector('.nav-text')?.textContent.trim();
      if (!label) return;
      if (!link.hasAttribute('aria-label') && !link.hasAttribute('aria-labelledby')) {
        link.setAttribute('aria-label', label);
      }
      link.setAttribute('data-sidebar-tooltip', '');
      if (!window.bootstrap?.Tooltip) return;
      // This persistent navigation is owned here, not by the generic initializer.
      bootstrap.Tooltip.getInstance(link)?.dispose();
      const tip = new bootstrap.Tooltip(link, {
        title: label, container: 'body', placement: 'right', trigger: 'hover focus',
        html: false, animation: false, delay: 0
      });
      tip.disable();
      sidebarTips.set(link, tip);
    });
    document.querySelector('.sidebar-nav')?.addEventListener('click', hideSidebarTips, { capture: true });
    sidebar?.addEventListener('scroll', hideSidebarTips, { capture: true, passive: true });
    window.addEventListener('scroll', hideSidebarTips, { capture: true, passive: true });
    window.addEventListener('resize', hideSidebarTips, { passive: true });
    window.addEventListener('pagehide', hideSidebarTips);
    document.addEventListener('show.bs.modal', hideSidebarTips);
    document.addEventListener('synapse:before-navigate', hideSidebarTips);
    if (sidebarDesktop.matches) document.body.classList.remove('sidebar-hidden');
  }
  const sidebarVisible = () => sidebarDesktop.matches
    ? appShell || !document.body.classList.contains('sidebar-hidden')
    : document.body.classList.contains('sidebar-open');
  const syncSidebar = () => {
    const visible = sidebarVisible();
    sidebarToggles.forEach(btn => {
      btn.setAttribute('aria-expanded', String(visible && !railCollapsed()));
      const label = sidebarDesktop.matches
        ? (visible && !railCollapsed() ? 'Collapse sidebar' : 'Expand sidebar')
        : (visible ? 'Close navigation' : 'Open navigation');
      btn.setAttribute('aria-label', label);
      if (btn.matches('[data-bs-toggle="tooltip"]') && btn.getAttribute('data-bs-title') !== label) {
        btn.setAttribute('data-bs-title', label);
        const tip = window.bootstrap?.Tooltip.getInstance(btn);
        if (tip) {
          tip.hide();
          tip.setContent({ '.tooltip-inner': label });
        }
      }
    });
    syncSidebarTips();
    if (sidebar) {
      sidebar.inert = !visible;
      sidebar.setAttribute('aria-hidden', String(!visible));
    }
  };
  const closeSidebar = (restoreFocus = false) => {
    document.body.classList.remove('sidebar-open');
    syncSidebar();
    if (restoreFocus) (drawerOpener || sidebarToggles[0])?.focus();
  };
  sidebarToggles.forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      if (sidebarDesktop.matches) {
        if (appShell) {
          collapsedPreference = document.body.classList.toggle('sidebar-collapsed');
          document.body.dataset.sidebarCollapsed = String(collapsedPreference);
          try {
            localStorage.setItem('synapse-sidebar-collapsed', collapsedPreference ? '1' : '0');
          } catch (_) { /* The rail still works when storage is disabled or full. */ }
        } else {
          document.body.classList.toggle('sidebar-hidden');
        }
      } else {
        drawerOpener = btn;
        document.body.classList.toggle('sidebar-open');
      }
      syncSidebar();
      if (!sidebarDesktop.matches && sidebarVisible()) {
        sidebar?.querySelector('.sidebar-close, a[href]')?.focus();
      }
    });
  });
  select('.sidebar-overlay')?.addEventListener('click', () => closeSidebar(true));
  document.addEventListener('keydown', e => {
    if (sidebarDesktop.matches || !sidebarVisible()) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      closeSidebar(true);
    }
    if (e.key === 'Tab' && sidebar) {
      const controls = [...sidebar.querySelectorAll('a[href], button:not([disabled]), [tabindex="0"]')]
        .filter(el => el.getClientRects().length && !el.closest('[inert]'));
      const first = controls[0], last = controls[controls.length - 1];
      if (first && (e.shiftKey ? document.activeElement === first : document.activeElement === last)) {
        e.preventDefault();
        (e.shiftKey ? last : first).focus();
      }
    }
  });
  sidebarDesktop.addEventListener('change', () => {
    if (appShell && sidebarDesktop.matches) {
      document.body.classList.remove('sidebar-hidden');
      document.body.classList.toggle('sidebar-collapsed', collapsedPreference);
    }
    closeSidebar();
  });
  // Navigation closes the persistent drawer by changing the body class.
  new MutationObserver(syncSidebar).observe(document.body, { attributes: true, attributeFilter: ['class'] });
  syncSidebar();

  /**
   * Sidebar submenu accordion (matches demo's open/close with smooth maxHeight animation)
   * - Only one submenu at the same level can be open at a time
   * - Animated via maxHeight transition (CSS handles the transition)
   * - Parent submenus auto-expand to fit nested submenu
   * - Active submenu auto-expands on page load
   * - Initial scroll restoration is handled before paint by sidebar-state.js
   */
  function initSidebarNav() {
    const sidebarNavRoot = document.querySelector('.sidebar-nav');
    if (!sidebarNavRoot) return;

    const navLinks = sidebarNavRoot.querySelectorAll('.has-submenu > .nav-link');

    navLinks.forEach(function(link) {
      link.addEventListener('click', function(e) {
        e.preventDefault();

        const parent = this.parentElement;
        const isOpen = parent.classList.contains('open');

        if (document.body.classList.contains('sidebar-collapsed') && window.matchMedia('(min-width: 992px)').matches) {
          document.body.classList.remove('sidebar-collapsed');
          document.body.dataset.sidebarCollapsed = 'false';
          try {
            localStorage.setItem('synapse-sidebar-collapsed', '0');
          } catch (_) { /* Keep working when storage is unavailable. */ }

          const siblings = parent.parentElement.querySelectorAll(':scope > .has-submenu.open');
          siblings.forEach(function(sibling) {
            if (sibling !== parent) closeSubmenu(sibling);
          });
          openSubmenu(parent);
          return;
        }

        // Close sibling submenus at the same level (accordion behavior)
        const siblings = parent.parentElement.querySelectorAll(':scope > .has-submenu.open');
        siblings.forEach(function(sibling) {
          if (sibling !== parent) closeSubmenu(sibling);
        });

        // Toggle current submenu
        if (isOpen) {
          closeSubmenu(parent);
        } else {
          openSubmenu(parent);
        }
      });
    });

    // Auto-expand active submenu on page load
    const activeItems = sidebarNavRoot.querySelectorAll('.nav-submenu .nav-link.active');
    activeItems.forEach(function(activeItem) {
      let parent = activeItem.closest('.has-submenu');
      while (parent) {
        openSubmenu(parent, false);
        parent = parent.parentElement.closest('.has-submenu');
      }
    });


  }

  function openSubmenu(item, animate) {
    if (animate === undefined) animate = true;
    const link = item.querySelector(':scope > .nav-link');
    const submenu = item.querySelector(':scope > .nav-submenu');
    if (!submenu) return;

    item.classList.add('open');
    if (link) link.setAttribute('aria-expanded', 'true');

    if (animate) {
      submenu.style.maxHeight = submenu.scrollHeight + 'px';
      updateParentHeight(item, false);
    } else {
      submenu.style.maxHeight = 'none';
      // No parent update needed since 'none' is auto-sized
    }
  }

  function closeSubmenu(item) {
    const link = item.querySelector(':scope > .nav-link');
    const submenu = item.querySelector(':scope > .nav-submenu');
    if (!submenu) return;

    // If the submenu was opened with 'none' (no animated height set), use a numeric
    // value first so the transition can animate the close smoothly.
    if (submenu.style.maxHeight === 'none' || submenu.style.maxHeight === '') {
      submenu.style.maxHeight = submenu.scrollHeight + 'px';
      // Force reflow so the browser registers the new value before we set it to 0
      void submenu.offsetHeight;
    }

    item.classList.remove('open');
    if (link) link.setAttribute('aria-expanded', 'false');
    submenu.style.maxHeight = null;

    // Also close any nested open submenus
    const nestedOpen = item.querySelectorAll('.has-submenu.open');
    nestedOpen.forEach(function(nested) {
      nested.classList.remove('open');
      const nestedLink = nested.querySelector(':scope > .nav-link');
      const nestedSubmenu = nested.querySelector(':scope > .nav-submenu');
      if (nestedLink) nestedLink.setAttribute('aria-expanded', 'false');
      if (nestedSubmenu) nestedSubmenu.style.maxHeight = null;
    });
  }

  function updateParentHeight(item, collapse) {
    const childSubmenu = item.querySelector(':scope > .nav-submenu');
    const delta = childSubmenu ? childSubmenu.scrollHeight : 0;

    let parent = item.parentElement.closest('.has-submenu.open');
    while (parent) {
      const parentSubmenu = parent.querySelector(':scope > .nav-submenu');
      if (parentSubmenu && parentSubmenu.style.maxHeight && parentSubmenu.style.maxHeight !== 'none') {
        const current = parseInt(parentSubmenu.style.maxHeight, 10) || 0;
        parentSubmenu.style.maxHeight = (collapse ? Math.max(0, current - delta) : current + delta) + 'px';
      }
      parent = parent.parentElement.closest('.has-submenu.open');
    }
  }


  // PHP owns route activation, including department paths and referral targets.
  // Template filename matching would conflate clinic and guidance appointments.
  function bootstrapSidebar() {
    initSidebarNav();
  }

  // Run after sidebar is in the DOM
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapSidebar);
  } else {
    bootstrapSidebar();
  }

  /**
   * Sidebar close button (mobile)
   */
  const sidebarClose = select('.sidebar-close');
  if (sidebarClose) {
    sidebarClose.addEventListener('click', function(e) {
      e.preventDefault();
      closeSidebar(true);
    });
  }

  /**
   * Search bar toggle (mobile)
   */
  if (select('.search-bar-toggle')) {
    on('click', '.search-bar-toggle', function(e) {
      e.preventDefault();
      select('.search-bar').classList.toggle('search-bar-show');
    });
  }

  /**
   * Navbar links active state on scroll
   */
  let navbarlinks = select('#navbar .scrollto', true);
  const navbarlinksActive = () => {
    let position = window.scrollY + 200;
    navbarlinks.forEach(navbarlink => {
      if (!navbarlink.hash) return;
      let section = select(navbarlink.hash);
      if (!section) return;
      if (position >= section.offsetTop && position <= (section.offsetTop + section.offsetHeight)) {
        navbarlink.classList.add('active');
      } else {
        navbarlink.classList.remove('active');
      }
    });
  };
  window.addEventListener('load', navbarlinksActive);
  onscroll(document, navbarlinksActive);

  /**
   * Header scrolled shadow
   */
  let selectHeader = select('#header');
  if (selectHeader) {
    const headerScrolled = () => {
      if (window.scrollY > 60) {
        selectHeader.classList.add('header-scrolled');
      } else {
        selectHeader.classList.remove('header-scrolled');
      }
    };
    window.addEventListener('load', headerScrolled);
    onscroll(document, headerScrolled);
  }

  /**
   * Back to top button
   */
  let backtotop = select('.back-to-top');
  if (backtotop) {
    const toggleBacktotop = () => {
      if (window.scrollY > 100) backtotop.classList.add('active');
      else backtotop.classList.remove('active');
    };
    window.addEventListener('load', toggleBacktotop);
    onscroll(document, toggleBacktotop);
  }

  /**
   * Dark mode toggle with localStorage persistence and radial pixel-dissolve reveal
   * Uses [data-theme="dark"] on <html> to match the v3 demo design tokens
   */
  const themeRoot = document.documentElement;
  let themeAnimTimer = 0;

  const prefersReducedMotion = () =>
    Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  const hideTooltipsIn = (selector) => {
    if (!window.bootstrap?.Tooltip) return;
    document.querySelectorAll(selector).forEach(el => {
      bootstrap.Tooltip.getInstance(el)?.hide();
    });
  };

  const dismissToggleTooltip = (triggerEl) => {
    if (triggerEl && window.bootstrap?.Tooltip) {
      const tipHost = triggerEl.matches('[data-bs-toggle="tooltip"]')
        ? triggerEl
        : triggerEl.closest('[data-bs-toggle="tooltip"]');
      if (tipHost) bootstrap.Tooltip.getInstance(tipHost)?.hide();
    }
    hideTooltipsIn('[data-bs-toggle="tooltip"]');
    document.querySelectorAll('.tooltip').forEach(t => t.remove());
  };

  const resolveRevealOrigin = (ev, triggerEl) => {
    if (ev && (ev.clientX || ev.clientY)) {
      return { x: ev.clientX, y: ev.clientY };
    }
    const target = triggerEl || ev?.currentTarget;
    if (target && target.getBoundingClientRect) {
      const rect = target.getBoundingClientRect();
      if (rect.width || rect.height) {
        return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
      }
    }
    return { x: window.innerWidth, y: window.innerHeight };
  };

  const applyThemeDom = (dark) => {
    const previous = themeRoot.getAttribute('data-theme');
    const next = dark ? 'dark' : 'light';
    themeRoot.setAttribute('data-theme', next);
    // For .theme-toggle: both icons exist in markup; CSS shows/hides by data-theme.
    // For legacy #darkModeToggle: swap icon class directly.
    const legacyIcon = document.querySelector('#darkModeToggle i');
    if (legacyIcon) {
      legacyIcon.className = dark ? 'bi bi-sun' : 'bi bi-moon-stars';
    }
    document.querySelectorAll('.tooltip').forEach(t => t.remove());
    // Charts resolve CSS tokens at render time, so they must be told to
    // re-read them. Fired after the attribute settles so tokens are current.
    if (previous !== next) {
      document.dispatchEvent(new CustomEvent('synapse:theme-change', {
        detail: { theme: next, dark: dark }
      }));
    }
  };

  /* Coordinated CSS color crossfade when View Transitions are unavailable. */
  const crossfadeTheme = (dark) => {
    themeRoot.classList.add('theme-anim');
    applyThemeDom(dark);
    clearTimeout(themeAnimTimer);
    themeAnimTimer = setTimeout(() => { themeRoot.classList.remove('theme-anim'); }, 520);
  };

  /* Radial dithered block-grid reveal from (x, y) via View Transitions API. */
  const revealTheme = (dark, x, y) => {
    const duration = 900;
    const fade = 7;
    const block = Math.max(9, Math.round(Math.min(window.innerWidth, window.innerHeight) / 72));
    const cols = Math.ceil(window.innerWidth / block) + 1;
    const rows = Math.ceil(window.innerHeight / block) + 1;
    const cx = x / block;
    const cy = y / block;
    const maxRadius = Math.hypot(Math.max(cx, cols - cx), Math.max(cy, rows - cy));
    const vt = themeRoot.__synapseThemeVt = document.startViewTransition(() => {
      themeRoot.classList.add('theme-instant');
      applyThemeDom(dark);
    });
    const cleanup = () => {
      if (themeRoot.__synapseThemeVt !== vt) return;
      themeRoot.classList.remove('theme-instant');
      themeRoot.style.removeProperty('--vt-mask');
    };
    vt.ready.then(() => {
      if (themeRoot.__synapseThemeVt !== vt) return;
      themeRoot.classList.remove('theme-instant');
      themeRoot.animate(
        { opacity: [1, 1] },
        { duration, easing: 'linear', pseudoElement: '::view-transition-new(root)' }
      );
      const cnv = document.createElement('canvas');
      cnv.width = cols;
      cnv.height = rows;
      const ctx = cnv.getContext('2d');
      if (!ctx) return;
      ctx.fillStyle = '#fff';
      const t0 = performance.now();
      (function frame(now) {
        if (themeRoot.__synapseThemeVt !== vt) return;
        const t = Math.min((now - t0) / duration, 1);
        const k = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
        const radius = k * (maxRadius + fade);
        ctx.clearRect(0, 0, cols, rows);
        for (let row = 0; row < rows; row++) {
          for (let col = 0; col < cols; col++) {
            const dist = Math.hypot(col - cx, row - cy);
            const hash = Math.abs(Math.sin(col * 127.1 + row * 311.7) * 43758.5453) % 1;
            const alpha = (radius - dist - (hash - 0.5) * 1.4) / fade;
            if (alpha > 0) {
              ctx.globalAlpha = alpha >= 1 ? 1 : alpha;
              ctx.fillRect(col, row, 1, 1);
            }
          }
        }
        ctx.globalAlpha = 1;
        themeRoot.style.setProperty('--vt-mask', 'url("' + cnv.toDataURL() + '")');
        if (t < 1) {
          requestAnimationFrame(frame);
        } else {
          themeRoot.style.removeProperty('--vt-mask');
        }
      })(t0);
    }).catch(cleanup);
    vt.finished.then(cleanup, cleanup);
  };

  const setTheme = (dark, ev = null, triggerEl = null) => {
    const previous = themeRoot.getAttribute('data-theme');
    const next = dark ? 'dark' : 'light';
    try {
      localStorage.setItem('na-dark-mode', dark ? '1' : '0');
    } catch (_) { /* Storage may be disabled or full. */ }
    dismissToggleTooltip(triggerEl || ev?.currentTarget || null);
    if (!ev || previous === next || prefersReducedMotion()) {
      applyThemeDom(dark);
      return;
    }
    if (!document.startViewTransition) {
      crossfadeTheme(dark);
      return;
    }
    const origin = resolveRevealOrigin(ev, triggerEl);
    revealTheme(dark, origin.x, origin.y);
  };

  const stored = localStorage.getItem('na-dark-mode');
  if (stored === '1') setTheme(true);

  document.querySelectorAll('#darkModeToggle, .theme-toggle').forEach((toggleBtn) => {
    toggleBtn.addEventListener('click', function(e) {
      e.preventDefault();
      const isDark = themeRoot.getAttribute('data-theme') === 'dark';
      setTheme(!isDark, e, toggleBtn);
    });
  });

  /**
   * Initiate Bootstrap tooltips with hover-only trigger (auto-dismiss on click)
   */
  const mobileTooltips = window.matchMedia('(max-width: 767.98px)');
  // Guard the shared show event so dynamically loaded tooltips follow the same rule.
  document.addEventListener('show.bs.tooltip', function(event) {
    if (mobileTooltips.matches) event.preventDefault();
  });
  mobileTooltips.addEventListener('change', function() {
    if (!mobileTooltips.matches) return;
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(element) {
      bootstrap.Tooltip.getInstance(element)?.hide();
    });
  });

  var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]:not([data-sidebar-tooltip])'));
  tooltipTriggerList.map(function(el) {
    // Header tooltips must stay below the header bar and never flip sideways
    // across adjacent header buttons (e.g. profile pill tooltip covering theme/notifications).
    var inHeader = Boolean(el.closest('.header'));
    var tip = new bootstrap.Tooltip(el, inHeader ? {
      trigger: 'hover',
      container: 'body',
      placement: 'bottom',
      fallbackPlacements: ['bottom'],
      boundary: 'viewport',
      popperConfig: function(defaultConfig) {
        var modifiers = (defaultConfig.modifiers || []).map(function(mod) {
          if (mod.name === 'flip') {
            return Object.assign({}, mod, {
              options: Object.assign({}, mod.options, { fallbackPlacements: ['bottom'], altAxis: false })
            });
          }
          if (mod.name === 'preventOverflow') {
            return Object.assign({}, mod, {
              options: Object.assign({}, mod.options, { boundary: 'viewport', padding: 8 })
            });
          }
          return mod;
        });
        return Object.assign({}, defaultConfig, { modifiers: modifiers });
      }
    } : { trigger: 'hover' });
    el.addEventListener('click', function() {
      tip.hide();
    });
    return tip;
  });

  function dropdownTooltips(toggle) {
    var root = toggle.closest('.dropdown') || toggle;
    var triggers = Array.from(root.querySelectorAll('[data-bs-toggle="tooltip"]'));
    if (root.matches('[data-bs-toggle="tooltip"]')) triggers.push(root);
    return triggers.map(function(el) {
      return bootstrap.Tooltip.getInstance(el);
    }).filter(Boolean);
  }

  // Wrapper tooltips must stay disabled while their dropdown is open.
  document.addEventListener('show.bs.dropdown', function(e) {
    if (e.target && e.target.closest && e.target.closest('.header')) {
      hideTooltipsIn('.header [data-bs-toggle="tooltip"]');
    }
    dropdownTooltips(e.target).forEach(function(tip) {
      tip.hide();
      tip.disable();
    });
  });

  document.addEventListener('hidden.bs.dropdown', function(e) {
    dropdownTooltips(e.target).forEach(function(tip) {
      tip.enable();
    });
  });

  window.addEventListener('blur', function() {
    hideTooltipsIn('.header [data-bs-toggle="tooltip"]');
  });
  document.addEventListener('synapse:before-navigate', function() {
    hideTooltipsIn('.header [data-bs-toggle="tooltip"]');
  });

  /**
   * Initiate Bootstrap popovers
   */
  var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
  popoverTriggerList.map(function(el) { return new bootstrap.Popover(el); });

  /**
   * Initiate Quill editors (if present)
   */
  if (select('.quill-editor-default')) {
    new Quill('.quill-editor-default', { theme: 'snow' });
  }
  if (select('.quill-editor-bubble')) {
    new Quill('.quill-editor-bubble', { theme: 'bubble' });
  }
  if (select('.quill-editor-full')) {
    new Quill('.quill-editor-full', {
      modules: {
        toolbar: [
          [{ font: [] }, { size: [] }],
          ['bold', 'italic', 'underline', 'strike'],
          [{ color: [] }, { background: [] }],
          [{ script: 'super' }, { script: 'sub' }],
          [{ list: 'ordered' }, { list: 'bullet' }, { indent: '-1' }, { indent: '+1' }],
          ['direction', { align: [] }],
          ['link', 'image', 'video'],
          ['clean']
        ]
      },
      theme: 'snow'
    });
  }

  /**
   * Initiate Lucide icons (if present)
   */
  if (typeof lucide !== 'undefined' && lucide.createIcons) {
    lucide.createIcons();
  }

})();

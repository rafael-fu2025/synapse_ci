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
   * Dark mode toggle with localStorage persistence
   * Uses [data-theme="dark"] on <html> to match the v3 demo design tokens
   */
  const setTheme = (dark) => {
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    localStorage.setItem('na-dark-mode', dark ? '1' : '0');
    // Update icon if toggle exists
    const icon = document.querySelector('.theme-toggle .theme-icon-dark, .theme-toggle .theme-icon-light, #darkModeToggle i');
    // For demo .theme-toggle: both icons exist in markup; CSS shows/hides by data-theme
    // For legacy #darkModeToggle: swap icon class
    if (icon && document.querySelector('#darkModeToggle')) {
      const i = document.querySelector('#darkModeToggle i');
      if (i) i.className = dark ? 'bi bi-sun' : 'bi bi-moon-stars';
    }
    // Clean up any lingering tooltips when theme toggles
    document.querySelectorAll('.tooltip').forEach(t => t.remove());
  };

  const stored = localStorage.getItem('na-dark-mode');
  if (stored === '1') setTheme(true);

  const darkToggle = select('#darkModeToggle');
  if (darkToggle) {
    darkToggle.addEventListener('click', function(e) {
      e.preventDefault();
      const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
      setTheme(!isDark);
    });
  }

  // Demo's .theme-toggle button (ph-moon / ph-sun icons toggled by data-theme via CSS)
  const themeToggle = select('.theme-toggle');
  if (themeToggle) {
    themeToggle.addEventListener('click', function(e) {
      e.preventDefault();
      const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
      setTheme(!isDark);
    });
  }

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
    var tip = new bootstrap.Tooltip(el, { trigger: 'hover' });
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

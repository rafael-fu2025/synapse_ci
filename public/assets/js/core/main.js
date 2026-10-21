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
   * Matches demo behavior: uses .sidebar-hidden on body to slide sidebar off-canvas
   */
  if (select('.sidebar-toggle') || select('.toggle-sidebar-btn')) {
    const onToggle = function(e) {
      e.preventDefault();
      if (window.innerWidth >= 1200) {
        document.body.classList.toggle('sidebar-hidden');
      } else {
        document.body.classList.toggle('sidebar-open');
      }
    };
    document.querySelectorAll('.sidebar-toggle, .toggle-sidebar-btn').forEach(btn => {
      btn.addEventListener('click', onToggle);
    });
  }

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
      document.body.classList.remove('sidebar-open');
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
  var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function(el) {
    var tip = new bootstrap.Tooltip(el, { trigger: 'hover' });
    el.addEventListener('click', function() {
      tip.hide();
    });
    return tip;
  });

  // Automatically dismiss open tooltips when any dropdown opens
  document.addEventListener('show.bs.dropdown', function(e) {
    var toggles = e.target.querySelectorAll('[data-bs-toggle="tooltip"]');
    toggles.forEach(function(t) {
      var tip = bootstrap.Tooltip.getInstance(t);
      if (tip) tip.hide();
    });
    var tipSelf = bootstrap.Tooltip.getInstance(e.target);
    if (tipSelf) tipSelf.hide();
    document.querySelectorAll('.tooltip').forEach(t => t.remove());
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

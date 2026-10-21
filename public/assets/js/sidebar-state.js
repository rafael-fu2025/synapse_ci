/* Runs directly after the sidebar markup, before the first painted frame. */
(function () {
  'use strict';

  const nav = document.querySelector('.sidebar-nav');
  if (!nav) return;
  const key = 'synapse-sidebar-scroll';
  const active = nav.querySelector('.nav-link.active');

  if (active) {
    active.setAttribute('aria-current', 'page');
    let parent = active.closest('.has-submenu');
    while (parent) {
      parent.classList.add('open');
      parent.querySelector(':scope > .nav-link')?.setAttribute('aria-expanded', 'true');
      const submenu = parent.querySelector(':scope > .nav-submenu');
      if (submenu) submenu.style.maxHeight = 'none';
      parent = parent.parentElement.closest('.has-submenu');
    }
  }

  try {
    const saved = sessionStorage.getItem(key);
    if (saved !== null && Number.isFinite(Number(saved))) nav.scrollTop = Math.max(0, Number(saved));
  } catch (_) { /* Navigation must still work when browser storage is unavailable. */ }

  if (active) {
    const bounds = nav.getBoundingClientRect();
    const link = active.getBoundingClientRect();
    if (link.top < bounds.top || link.bottom > bounds.top + nav.clientHeight) {
      nav.scrollTop += link.top - bounds.top - (nav.clientHeight - link.height) / 2;
    }
  }

  const save = () => {
    try { sessionStorage.setItem(key, String(nav.scrollTop)); }
    catch (_) { /* Storage may be disabled or full. */ }
  };
  nav.addEventListener('scroll', save, { passive: true });
  nav.addEventListener('click', save, { capture: true });
  window.addEventListener('pagehide', save);
  save();
  nav.dataset.scrollRestored = 'true';
})();

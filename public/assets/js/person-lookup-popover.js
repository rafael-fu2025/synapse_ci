/**
 * Anchors [data-person-lookup-menu] search result panels out of dialog flow
 * using viewport-fixed positioning so modal forms never jump or resize while
 * searching students or employees.
 */
(function () {
  'use strict';
  if (window.SynapsePersonLookupPopover) return;

  let rafId = 0;
  let syncing = false;

  function isVisible(menu) {
    if (!menu || !menu.isConnected || menu.hidden) return false;
    return getComputedStyle(menu).display !== 'none';
  }

  function positionMenu(menu) {
    const wrap = menu.closest('[data-person-lookup]');
    if (!wrap) return;
    const anchor = wrap.querySelector('[data-person-lookup-anchor]') || wrap.querySelector('input');
    if (!anchor) return;
    const rect = anchor.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return;

    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const gap = 4;
    const margin = 10;
    const maxCap = 260;
    const width = Math.min(rect.width, vw - margin * 2);
    const left = Math.max(margin, Math.min(rect.left, vw - width - margin));
    const below = vh - rect.bottom - gap - margin;
    const above = rect.top - gap - margin;
    const placeAbove = below < 140 && above > below;

    menu.dataset.popoverFixed = 'true';
    menu.style.left = `${Math.round(left)}px`;
    menu.style.width = `${Math.round(width)}px`;
    if (placeAbove) {
      menu.style.top = 'auto';
      menu.style.bottom = `${Math.round(vh - rect.top + gap)}px`;
      menu.style.maxHeight = `${Math.max(120, Math.min(maxCap, Math.floor(above)))}px`;
    } else {
      menu.style.bottom = 'auto';
      menu.style.top = `${Math.round(rect.bottom + gap)}px`;
      menu.style.maxHeight = `${Math.max(120, Math.min(maxCap, Math.floor(below)))}px`;
    }
  }

  function syncAll() {
    rafId = 0;
    syncing = true;
    try {
      const menus = document.querySelectorAll('[data-person-lookup-menu]');
      menus.forEach(menu => {
        if (isVisible(menu)) {
          positionMenu(menu);
        } else if (menu.dataset.popoverFixed) {
          delete menu.dataset.popoverFixed;
          menu.style.left = '';
          menu.style.top = '';
          menu.style.bottom = '';
          menu.style.width = '';
          menu.style.maxHeight = '';
        }
      });
    } finally {
      syncing = false;
    }
  }

  function schedule() {
    if (rafId) return;
    rafId = requestAnimationFrame(syncAll);
  }

  const observer = new MutationObserver(records => {
    if (syncing) return;
    if (!document.querySelector('[data-person-lookup-menu]')) return;
    for (const rec of records) {
      if (rec.type === 'childList') { schedule(); return; }
      const target = rec.target;
      if (target instanceof Element && (target.matches('[data-person-lookup-menu]') || target.closest('[data-person-lookup]'))) {
        schedule();
        return;
      }
    }
  });

  function start() {
    observer.observe(document.body, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ['style', 'hidden', 'class'],
    });
    window.addEventListener('resize', () => {
      if (document.querySelector('[data-person-lookup-menu]')) schedule();
    }, { passive: true });
    window.addEventListener('scroll', event => {
      if (event.target instanceof Element && event.target.closest('[data-person-lookup-menu]')) return;
      if (document.querySelector('[data-person-lookup-menu]')) schedule();
    }, { capture: true, passive: true });
    document.addEventListener('shown.bs.modal', schedule);
    document.addEventListener('synapse:page-load', schedule);
    schedule();
  }

  window.SynapsePersonLookupPopover = { sync: syncAll };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();

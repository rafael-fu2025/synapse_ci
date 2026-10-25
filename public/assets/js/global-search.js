/** Navigation-only search. Sources are the server-authorized sidebar and action links. */
(function () {
  'use strict';
  const dialog = document.getElementById('globalSearchDialog');
  const trigger = document.querySelector('[data-search-open]');
  if (!dialog || !trigger || !window.bootstrap?.Modal || dialog.dataset.initialized) return;
  dialog.dataset.initialized = 'true';
  const modal = bootstrap.Modal.getOrCreateInstance(dialog);
  const input = dialog.querySelector('input');
  const results = dialog.querySelector('[role="listbox"]');
  const empty = dialog.querySelector('[data-search-empty]');
  const status = dialog.querySelector('[data-search-status]');
  let items = [], matches = [], active = -1, opener = null;
  let phase = 'closed', pendingLink = null, replaying = false;
  const normalize = text => text.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

  function sources() {
    const pages = [];
    let context = 'Personal Health';
    document.querySelectorAll('#app-sidebar .nav-menu > li').forEach(item => {
      if (item.classList.contains('nav-heading')) context = item.textContent.trim();
      const link = item.querySelector('a.nav-link');
      if (link) pages.push({ link, label: link.querySelector('.nav-text')?.textContent.trim() || link.textContent.trim(), context, group: 'Pages' });
    });
    const actionLinks = Array.from(document.querySelectorAll('[data-search-actions]'))
      .flatMap(source => Array.from((source.content || source).querySelectorAll('a')));
    const actions = actionLinks.map(link => ({
      link, label: link.dataset.searchLabel, context: link.dataset.searchContext, group: 'Actions'
    }));
    return [...pages, ...actions].filter(item => item.label && item.link.href).map(item => ({
      ...item, terms: normalize(`${item.label} ${item.context} ${item.group}`)
    }));
  }
  function select(index) {
    active = index;
    const options = Array.from(results.querySelectorAll('[role="option"]'));
    options.forEach((option, i) => option.setAttribute('aria-selected', String(i === active)));
    if (options[active]) {
      input.setAttribute('aria-activedescendant', options[active].id);
      options[active].scrollIntoView({ block: 'nearest' });
    } else input.removeAttribute('aria-activedescendant');
  }
  function render() {
    const tokens = normalize(input.value).split(/\s+/).filter(Boolean);
    matches = items.filter(item => tokens.every(token => item.terms.includes(token)));
    results.replaceChildren();
    ['Pages', 'Actions'].forEach(group => {
      const grouped = matches.filter(item => item.group === group);
      if (!grouped.length) return;
      const section = document.createElement('div');
      section.setAttribute('role', 'group');
      section.setAttribute('aria-label', group);
      const heading = document.createElement('div');
      heading.className = 'global-search-group';
      heading.setAttribute('role', 'presentation');
      heading.textContent = group;
      section.append(heading);
      grouped.forEach(item => {
        const index = matches.indexOf(item);
        const link = document.createElement('a');
        link.href = item.link.href;
        link.className = 'global-search-result';
        link.id = `global-search-option-${index}`;
        link.dataset.searchIndex = String(index);
        link.setAttribute('role', 'option');
        link.tabIndex = -1;
        // Preserve modal-form behavior and any explicit full-navigation boundary.
        ['data-app-modal-open', 'data-navigation', 'target', 'rel'].forEach(name => {
          if (item.link.hasAttribute(name)) link.setAttribute(name, item.link.getAttribute(name));
        });
        const label = document.createElement('span');
        label.textContent = item.label;
        const context = document.createElement('small');
        context.textContent = item.context;
        link.append(label, context);
        section.append(link);
      });
      results.append(section);
    });
    empty.hidden = matches.length > 0;
    status.textContent = `${matches.length} ${matches.length === 1 ? 'result' : 'results'} available.`;
    input.setAttribute('aria-expanded', String(matches.length > 0));
    select(matches.length ? 0 : -1);
    results.scrollTop = 0;
  }
  function open() {
    // Do not steal focus from an existing form or confirmation dialog.
    if (phase !== 'closed' || document.querySelector('.modal.show, .modal-backdrop, dialog[open]')) return;
    opener = document.activeElement;
    items = sources();
    input.value = '';
    render();
    modal.show(opener);
  }
  trigger.hidden = false;
  const shortcut = trigger.querySelector('[data-search-shortcut]');
  if (shortcut && /Mac|iPhone|iPad/.test(navigator.platform)) shortcut.textContent = '⌘ K';
  trigger.addEventListener('click', open);
  dialog.addEventListener('show.bs.modal', () => { phase = 'showing'; });
  dialog.addEventListener('shown.bs.modal', () => {
    phase = 'open';
    if (pendingLink) modal.hide();
    else input.focus();
  });
  dialog.addEventListener('hide.bs.modal', () => {
    phase = 'hiding';
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
  });
  dialog.addEventListener('hidden.bs.modal', () => {
    phase = 'closed';
    const link = pendingLink;
    pendingLink = null;
    if (opener?.isConnected) opener.focus({ preventScroll: true });
    if (!link?.isConnected) return;
    // Bootstrap must finish removing its backdrop and scroll lock before a result
    // can open another modal through the existing delegated form handler.
    replaying = true;
    try { link.click(); } finally { replaying = false; }
  });
  dialog.addEventListener('click', event => {
    const link = event.target.closest('[data-search-index]');
    if (!link || replaying || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    event.stopPropagation();
    if (phase === 'hiding') return;
    pendingLink = link;
    if (phase === 'open') modal.hide();
  });
  dialog.addEventListener('keydown', event => {
    // Let Bootstrap handle Escape, but stop the search input's native clear action.
    if (event.key === 'Escape' && !event.isComposing) event.preventDefault();
  });
  input.addEventListener('input', render);
  input.addEventListener('keydown', event => {
    if (event.isComposing) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (matches.length) select((active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      if (active >= 0) results.querySelector(`#global-search-option-${active}`)?.click();
    }
  });
  document.addEventListener('keydown', event => {
    if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'k') {
      if (event.isComposing || document.querySelector('.modal.show:not(#globalSearchDialog), dialog[open]')) return;
      event.preventDefault();
      if (phase === 'open') input.focus();
      else open();
    }
  });
})();

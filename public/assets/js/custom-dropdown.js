/** Bootstrap-backed progressive enhancement; native selects own values and constraints. */
(function () {
  'use strict';

  const instances = new Map();
  let nextId = 0;
  let invalidTarget = null;
  const layoutClass = /^(?:(?:col|row|offset|order|flex|align-self|w|mw|h|mh|m|mt|mb|ms|me|mx|my|d|float|position)(?:-|$)|align-middle$)/;
  const layoutProperties = [
    'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
    'flex', 'flex-grow', 'flex-shrink', 'flex-basis', 'align-self', 'order',
    'grid-area', 'grid-column', 'grid-row', 'margin', 'margin-top',
    'margin-right', 'margin-bottom', 'margin-left', 'display', 'vertical-align'
  ];
  const visualProperties = [
    'font-size', 'font-family', 'font-weight', 'line-height', 'color',
    'background-color', 'border', 'border-radius', 'padding'
  ];

  function fieldLabel(select) {
    const labelledby = select.getAttribute('aria-labelledby');
    if (labelledby) {
      const text = labelledby.split(/\s+/).map((id) => {
        const element = document.getElementById(id);
        return element ? element.textContent.trim() : '';
      }).filter(Boolean).join(' ');
      if (text) return text;
    }
    return select.getAttribute('aria-label') ||
      Array.from(select.labels || []).map((label) => {
        // Implicit labels also contain the control: exclude generated menu text.
        const copy = label.cloneNode(true);
        copy.querySelectorAll('.bd-select, select').forEach((control) => control.remove());
        return copy.textContent.trim();
      }).join(' ') || 'Options';
  }

  function upgrade(select) {
    if (instances.has(select) || select.multiple || select.hasAttribute('data-native-select')) return;

    const original = {
      tabindex: select.getAttribute('tabindex'),
      hidden: select.getAttribute('aria-hidden'),
      marker: select.getAttribute('data-bd-select'),
      sourceClass: select.classList.contains('bd-select-source')
    };
    const id = 'bd-select-' + (++nextId);
    const wrap = document.createElement('div');
    const button = document.createElement('button');
    button.type = 'button';
    button.id = id;
    button.dataset.bsToggle = 'dropdown';
    button.setAttribute('role', 'combobox');
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-controls', id + '-list');
    button.setAttribute('aria-expanded', 'false');
    const label = document.createElement('span');
    label.className = 'bd-select-label';
    button.appendChild(label);
    const menu = document.createElement('div');
    menu.id = id + '-list';
    menu.className = 'dropdown-menu bd-select-menu';
    menu.setAttribute('role', 'listbox');
    const searchWrap = document.createElement('div');
    searchWrap.className = 'bd-select-search-wrap';
    searchWrap.hidden = true;
    const searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.className = 'form-control form-control-sm bd-select-search';
    searchInput.placeholder = 'Filter options...';
    searchInput.setAttribute('aria-label', 'Filter options');
    searchInput.autocomplete = 'off';
    const searchClear = document.createElement('button');
    searchClear.type = 'button';
    searchClear.className = 'bd-select-search-clear';
    searchClear.setAttribute('aria-label', 'Clear filter');
    searchClear.innerHTML = '&times;';
    searchClear.hidden = true;
    searchWrap.append(searchInput, searchClear);
    const emptyState = document.createElement('div');
    emptyState.className = 'bd-select-empty';
    emptyState.textContent = 'No matching options';
    emptyState.hidden = true;
    const error = document.createElement('div');
    error.id = id + '-error';
    error.className = 'invalid-feedback';
    error.setAttribute('role', 'alert');
    error.style.display = 'none';
    let items = [];
    let groups = [];
    let validationShown = false;
    let disposed = false;
    let dropdown = null;
    let typeAheadBuffer = '';
    let typeAheadTimer = 0;
    // Page/main-content scrolling moves the trigger but not an open menu. Scrolls
    // inside the menu or any scrollable content within the same picker/modal must
    // not dismiss it, or wheel-list auto-scrolling would close the menu on open.
    window.addEventListener('scroll', (event) => {
      if (disposed || !dropdown || !menu.classList.contains('show')) return;
      const target = event.target;
      if (target instanceof Element && (menu.contains(target) || wrap.contains(target))) return;
      const boundary = wrap.closest('.dtp-popup, .modal, [role="dialog"], form');
      if (target instanceof Node && boundary?.contains(target)) return;
      dropdown.hide();
    }, { capture: true, passive: true });

    function optionDisabled(option) {
      return option.disabled || (option.parentElement &&
        option.parentElement.tagName === 'OPTGROUP' && option.parentElement.disabled);
    }

    function isPlaceholder(option) {
      if (!option || option.value !== '') return false;
      if (option.hasAttribute('data-placeholder')) return true;
      // Only the first direct option gets implicit placeholder semantics.
      const first = Array.from(select.children).find((node) => node.tagName === 'OPTION');
      return option === first && (select.required || option.disabled || option.hidden ||
        /^[\s\-\u2013\u2014]*(?:choose|select|pick)\b/i.test(option.label));
    }

    function isSearchable() {
      if (select.hasAttribute('data-no-search') || select.closest('.dp-popup, .dtp-popup')) return false;
      return select.hasAttribute('data-searchable') || items.length >= 10;
    }

    function applyFilter(queryText) {
      const normalized = (queryText || '').trim().toLowerCase();
      searchClear.hidden = normalized === '';
      let visibleCount = 0;
      items.forEach((item) => {
        const optionHidden = Boolean(item.option.hidden || (item.option.parentElement && item.option.parentElement.hidden));
        const matches = !optionHidden && (!normalized || item.option.label.toLowerCase().includes(normalized));
        item.el.hidden = !matches;
        if (matches) visibleCount += 1;
      });
      groups.forEach(({ el, optionGroup }) => {
        const hasVisible = items.some((entry) => entry.groupEl === el && !entry.el.hidden);
        el.hidden = Boolean(optionGroup.hidden) || !hasVisible;
      });
      emptyState.hidden = !(isSearchable() && visibleCount === 0);
      if (dropdown && menu.classList.contains('show')) dropdown.update();
    }

    function rebuild() {
      const focused = items.find((item) => item.el === document.activeElement);
      items = [];
      groups = [];
      menu.replaceChildren(searchWrap);
      function addOption(option, parent, groupEl) {
        if (isPlaceholder(option)) return;
        const item = document.createElement('button');
        item.type = 'button';
        item.tabIndex = -1;
        item.className = 'dropdown-item' + (groupEl ? ' bd-item-grouped' : '');
        item.setAttribute('role', 'option');
        item.textContent = option.label;
        item.disabled = optionDisabled(option);
        item.classList.toggle('disabled', item.disabled);

        item.setAttribute('aria-disabled', String(item.disabled));
        item.hidden = option.hidden;
        parent.appendChild(item);
        items.push({ option, el: item, groupEl: groupEl || null });
      }
      Array.from(select.children).forEach((node) => {
        if (node.tagName === 'OPTION') addOption(node, menu, null);
        if (node.tagName !== 'OPTGROUP') return;
        const group = document.createElement('div');
        group.setAttribute('role', 'group');
        group.setAttribute('aria-label', node.label);
        group.setAttribute('aria-disabled', String(node.disabled));
        group.hidden = node.hidden;
        const header = document.createElement('h6');
        header.className = 'dropdown-header';
        header.setAttribute('aria-hidden', 'true');
        header.textContent = node.label;
        group.appendChild(header);
        Array.from(node.children).forEach((option) => {
          if (option.tagName === 'OPTION') addOption(option, group, group);
        });
        groups.push({ el: group, optionGroup: node });
        menu.appendChild(group);
      });
      menu.appendChild(emptyState);
      const searchable = isSearchable();
      searchWrap.hidden = !searchable;
      menu.classList.toggle('bd-select-searchable', searchable);
      if (!searchable) searchInput.value = '';
      applyFilter(searchInput.value);
      if (focused) {
        const replacement = items.find((item) => item.option === focused.option);
        if (replacement && !replacement.el.disabled && replacement.el.getClientRects().length) {
          replacement.el.focus();
        } else {
          button.focus();
        }
      }
    }

    function syncLayout() {
      const classes = Array.from(select.classList).filter((name) => name !== 'bd-select-source');
      wrap.className = 'dropdown bd-select ' + classes.filter((name) => layoutClass.test(name)).join(' ');
      button.className = 'form-select dropdown-toggle bd-select-toggle ' +
        classes.filter((name) => !layoutClass.test(name)).join(' ');
      // Only presentation is transferred: never clone name, form, data bindings or handlers.
      layoutProperties.forEach((property) => {
        wrap.style.removeProperty(property);
        if (select.style.getPropertyValue(property)) {
          wrap.style.setProperty(property, select.style.getPropertyValue(property), select.style.getPropertyPriority(property));
        }
      });
      visualProperties.forEach((property) => {
        button.style.removeProperty(property);
        if (select.style.getPropertyValue(property)) {
          button.style.setProperty(property, select.style.getPropertyValue(property), select.style.getPropertyPriority(property));
        }
      });
      if (!wrap.style.width) wrap.style.width = '100%';
      button.style.width = '100%';
      button.disabled = select.matches(':disabled');
      if (button.disabled && dropdown) dropdown.hide();
      if (original.tabindex !== null) button.setAttribute('tabindex', original.tabindex);
      button.setAttribute('aria-required', String(select.required));
      menu.setAttribute('aria-required', String(select.required));
      button.setAttribute('aria-invalid', String(select.getAttribute('aria-invalid') === 'true' ||
        select.classList.contains('is-invalid') || (validationShown && !select.validity.valid)));
      button.classList.toggle('is-invalid', button.getAttribute('aria-invalid') === 'true');
      const showError = validationShown && !select.validity.valid && !button.disabled;
      error.textContent = showError ? select.validationMessage : '';
      error.style.display = showError ? 'block' : 'none';
      const descriptions = [select.getAttribute('aria-describedby'), showError ? error.id : ''].filter(Boolean);
      if (descriptions.length) button.setAttribute('aria-describedby', descriptions.join(' '));
      else button.removeAttribute('aria-describedby');
      const errorLinks = [select.getAttribute('aria-errormessage'), showError ? error.id : ''].filter(Boolean);
      if (errorLinks.length) button.setAttribute('aria-errormessage', errorLinks.join(' '));
      else button.removeAttribute('aria-errormessage');
    }

    function sync(rebuildOptions) {
      if (disposed) return;
      if (rebuildOptions) rebuild();
      syncLayout();
      const option = select.options[select.selectedIndex];
      const text = option ? option.label.trim() : '';
      label.textContent = text;
      label.classList.toggle('bd-placeholder', !option || isPlaceholder(option));
      const name = fieldLabel(select);
      button.setAttribute('aria-label', text ? name + ': ' + text : name);
      menu.setAttribute('aria-label', name);
      items.forEach((item) => {
        item.el.classList.toggle('active', item.option === option);
        item.el.setAttribute('aria-selected', String(item.option === option));
      });
      if (dropdown && menu.classList.contains('show')) dropdown.update();
    }

    function onClick(event) {
      const item = items.find((entry) => entry.el === event.target.closest('.dropdown-item'));
      if (!item || select.matches(':disabled') || optionDisabled(item.option) ||
          item.option.hidden || (item.option.parentElement && item.option.parentElement.hidden)) return;
      const index = Array.from(select.options).indexOf(item.option);
      if (index < 0) return;
      button.focus({ preventScroll: true });
      // Rebuilding option nodes below detaches the click target before Bootstrap's
      // delegated auto-close runs, so close explicitly while the menu is intact.
      if (dropdown) dropdown.hide();
      if (index === select.selectedIndex) return;
      select.selectedIndex = index;
      sync(false);
      select.dispatchEvent(new Event('input', { bubbles: true }));
      select.dispatchEvent(new Event('change', { bubbles: true }));
      // A change handler may synchronously change the selection or its options.
      sync(true);
    }

    function onSync() { sync(true); }
    function onFocus() { if (!button.disabled) button.focus(); }
    function onInvalid(event) {
      // Cancel the browser's attempt to focus an aria-hidden native control.
      event.preventDefault();
      validationShown = true;
      sync(false);
      // A form can report several invalid controls in the same validation pass.
      if (!invalidTarget) {
        invalidTarget = button;
        queueMicrotask(() => {
          const target = invalidTarget;
          invalidTarget = null;
          if (target && target.isConnected && !target.disabled) target.focus();
        });
      }
    }
    function onShow(event) {
      searchInput.value = '';
      sync(true);
      if (button.disabled) {
        event.preventDefault();
        return;
      }
      const width = button.getBoundingClientRect().width;
      menu.style.minWidth = width + 'px';
      menu.style.width = wrap.closest('.dp-popup') ? 'max-content' : width + 'px';
    }
    function onShown() {
      const active = menu.querySelector('.dropdown-item.active:not([hidden])');
      if (active) {
        // Scroll only the menu, not a surrounding modal/page/date picker.
        const itemRect = active.getBoundingClientRect();
        const menuRect = menu.getBoundingClientRect();
        const searchHeight = !searchWrap.hidden ? searchWrap.getBoundingClientRect().height : 0;
        if (itemRect.top < menuRect.top + searchHeight) menu.scrollTop -= (menuRect.top + searchHeight) - itemRect.top;
        else if (itemRect.bottom > menuRect.bottom) menu.scrollTop += itemRect.bottom - menuRect.bottom;
      }
      if (!searchWrap.hidden && !window.matchMedia('(pointer: coarse)').matches) {
        searchInput.focus({ preventScroll: true });
      }
    }

    function firstVisibleEnabledItem() {
      return items.find((entry) => !entry.el.hidden && !entry.el.disabled && entry.el.getClientRects().length);
    }

    function onSearchWrapClick(event) {
      // Prevent Bootstrap Dropdown autoClose from dismissing when interacting with filter input/clear.
      event.stopPropagation();
    }

    function onSearchInput() {
      applyFilter(searchInput.value);
    }

    function onSearchClear() {
      searchInput.value = '';
      applyFilter('');
      searchInput.focus({ preventScroll: true });
    }

    function onSearchKeyDown(event) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        const first = firstVisibleEnabledItem();
        if (first) first.el.focus();
      } else if (event.key === 'Enter') {
        event.preventDefault();
        const visible = items.filter((entry) => !entry.el.hidden && !entry.el.disabled && entry.el.getClientRects().length);
        if (visible.length === 1) visible[0].el.click();
      } else if (event.key === 'Escape') {
        event.stopPropagation();
        if (searchInput.value !== '') {
          searchInput.value = '';
          applyFilter('');
        } else if (dropdown) {
          dropdown.hide();
          button.focus({ preventScroll: true });
        }
      }
    }

    function onButtonKeyDown(event) {
      if (button.disabled || menu.classList.contains('show')) return;
      if (event.key.length === 1 && event.key !== ' ' && !event.ctrlKey && !event.metaKey && !event.altKey) {
        clearTimeout(typeAheadTimer);
        typeAheadBuffer += event.key.toLowerCase();
        typeAheadTimer = window.setTimeout(() => { typeAheadBuffer = ''; }, 650);
        const match = items.find((entry) =>
          !entry.el.disabled && !entry.option.hidden &&
          entry.option.label.trim().toLowerCase().startsWith(typeAheadBuffer)
        );
        if (match) {
          const index = Array.from(select.options).indexOf(match.option);
          if (index >= 0 && index !== select.selectedIndex) {
            select.selectedIndex = index;
            sync(false);
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            sync(true);
          }
        }
      }
    }

    select.insertAdjacentElement('afterend', wrap);
    wrap.append(select, button, menu, error);
    select.classList.add('bd-select-source');
    select.dataset.bdSelect = '1';
    select.setAttribute('tabindex', '-1');
    select.setAttribute('aria-hidden', 'true');
    menu.addEventListener('click', onClick);
    searchWrap.addEventListener('click', onSearchWrapClick);
    searchInput.addEventListener('input', onSearchInput);
    searchInput.addEventListener('keydown', onSearchKeyDown);
    searchClear.addEventListener('click', onSearchClear);
    select.addEventListener('input', onSync);
    select.addEventListener('change', onSync);
    select.addEventListener('bd-select:sync', onSync);
    select.addEventListener('focus', onFocus);
    select.addEventListener('invalid', onInvalid);
    button.addEventListener('keydown', onButtonKeyDown);
    button.addEventListener('show.bs.dropdown', onShow);
    button.addEventListener('shown.bs.dropdown', onShown);

    // Keep the menu beside its trigger for Bootstrap's delegated keyboard handling
    // and the date picker's containment/Escape rules. Fixed Popper positioning
    // escapes ordinary scrolling ancestors without portaling outside the popup.
    function configureDropdown() {
      if (!dropdown && window.bootstrap && window.bootstrap.Dropdown) {
        dropdown = new window.bootstrap.Dropdown(button, {
          popperConfig(defaults) {
            return Object.assign({}, defaults, {
              strategy: 'fixed',
              modifiers: (defaults.modifiers || []).concat([
                { name: 'preventOverflow', options: { boundary: document.documentElement, rootBoundary: 'viewport', padding: 8 } },
                { name: 'flip', options: { boundary: document.documentElement, rootBoundary: 'viewport', padding: 8 } }
              ])
            });
          }
        });
      }
    }
    configureDropdown();
    // Also support Bootstrap loaded after this script but before interaction.
    button.addEventListener('pointerdown', configureDropdown);
    button.addEventListener('keydown', configureDropdown);
    button.addEventListener('click', configureDropdown);

    const observer = new MutationObserver(() => sync(true));
    observer.observe(select, {
      subtree: true, childList: true, characterData: true, attributes: true,
      attributeFilter: ['disabled', 'selected', 'label', 'value', 'hidden', 'class', 'style',
        'required', 'data-placeholder', 'aria-label', 'aria-labelledby', 'aria-describedby', 'aria-errormessage', 'aria-invalid']
    });
    const state = {
      wrap, button,
      sync: onSync,
      reset() { validationShown = false; sync(true); },
      handleSearchEscape() {
        if (disposed || !menu.classList.contains('show')) return false;
        if (searchInput.value !== '') {
          searchInput.value = '';
          applyFilter('');
          return true;
        }
        if (dropdown) {
          dropdown.hide();
          button.focus({ preventScroll: true });
          return true;
        }
        return false;
      },
      destroy() {
        if (disposed) return;
        disposed = true;
        observer.disconnect();
        if (dropdown) {
          dropdown.hide();
          dropdown.dispose();
        }
        clearTimeout(typeAheadTimer);
        menu.removeEventListener('click', onClick);
        searchWrap.removeEventListener('click', onSearchWrapClick);
        searchInput.removeEventListener('input', onSearchInput);
        searchInput.removeEventListener('keydown', onSearchKeyDown);
        searchClear.removeEventListener('click', onSearchClear);
        select.removeEventListener('input', onSync);
        select.removeEventListener('change', onSync);
        select.removeEventListener('bd-select:sync', onSync);
        select.removeEventListener('focus', onFocus);
        select.removeEventListener('invalid', onInvalid);
        button.removeEventListener('keydown', onButtonKeyDown);
        button.removeEventListener('show.bs.dropdown', onShow);
        button.removeEventListener('shown.bs.dropdown', onShown);
        button.removeEventListener('pointerdown', configureDropdown);
        button.removeEventListener('keydown', configureDropdown);
        button.removeEventListener('click', configureDropdown);
        if (invalidTarget === button) invalidTarget = null;
        if (!original.sourceClass) select.classList.remove('bd-select-source');
        [['tabindex', original.tabindex], ['aria-hidden', original.hidden], ['data-bd-select', original.marker]].forEach(([name, value]) => {
          if (value === null) select.removeAttribute(name);
          else select.setAttribute(name, value);
        });
        if (select.parentNode === wrap && wrap.parentNode) wrap.before(select);
        wrap.remove();
        instances.delete(select);
      }
    };
    instances.set(select, state);
    sync(true);
  }

  function init(root) {
    root = root || document;
    if (root.matches && root.matches('select.form-select')) upgrade(root);
    if (root.querySelectorAll) root.querySelectorAll('select.form-select').forEach(upgrade);
  }

  function destroy(root) {
    root = root || document;
    instances.forEach((state, select) => {
      if (root === select || root === state.wrap || root.contains(select)) state.destroy();
    });
  }

  window.BalaySelect = { init, destroy };
  document.addEventListener('click', (event) => {
    const label = event.target.closest('label');
    const state = label && instances.get(label.control);
    if (!state || event.target.closest('a, button, input, textarea, select')) return;
    event.preventDefault();
    if (!state.button.disabled) state.button.focus();
  });
  document.addEventListener('reset', (event) => {
    // Native reset applies defaultSelected after reset listeners have returned.
    setTimeout(() => {
      if (event.defaultPrevented) return;
      instances.forEach((state, select) => { if (select.form === event.target) state.reset(); });
    }, 0);
  }, true);
  document.addEventListener('htmx:load', (event) => init((event.detail && event.detail.elt) || event.target));
  document.addEventListener('htmx:beforeCleanupElement', (event) => destroy(event.target));
  // Bootstrap registers delegated dropdown keydown listeners in capture phase on document,
  // so intercept search-input Escape at window capture before Bootstrap closes the menu.
  window.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const target = event.target;
    if (!(target instanceof Element) || !target.classList.contains('bd-select-search')) return;
    const wrap = target.closest('.bd-select');
    const select = wrap && wrap.querySelector(':scope > select.form-select');
    const state = select && instances.get(select);
    if (!state || !state.handleSearchEscape()) return;
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
  }, true);
  const lifecycle = new MutationObserver((records) => {
    const removed = records.some((record) => record.removedNodes.length);
    if (removed) {
      instances.forEach((state, select) => {
        if (!select.isConnected || !state.wrap.contains(select)) state.destroy();
      });
    }
    records.forEach((record) => {
      if (record.type !== 'attributes') return;
      instances.forEach((state, select) => {
        if (record.target.contains(select)) state.sync();
      });
    });
  });
  lifecycle.observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  else init();
})();

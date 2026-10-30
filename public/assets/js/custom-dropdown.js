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
    const error = document.createElement('div');
    error.id = id + '-error';
    error.className = 'invalid-feedback';
    error.setAttribute('role', 'alert');
    error.style.display = 'none';
    let items = [];
    let validationShown = false;
    let disposed = false;
    let dropdown = null;
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

    function rebuild() {
      const focused = items.find((item) => item.el === document.activeElement);
      items = [];
      menu.replaceChildren();
      function addOption(option, parent, grouped) {
        if (isPlaceholder(option)) return;
        const item = document.createElement('button');
        item.type = 'button';
        item.tabIndex = -1;
        item.className = 'dropdown-item' + (grouped ? ' bd-item-grouped' : '');
        item.setAttribute('role', 'option');
        item.textContent = option.label;
        item.disabled = optionDisabled(option);
        item.classList.toggle('disabled', item.disabled);

        item.setAttribute('aria-disabled', String(item.disabled));
        item.hidden = option.hidden;
        parent.appendChild(item);
        items.push({ option, el: item });
      }
      Array.from(select.children).forEach((node) => {
        if (node.tagName === 'OPTION') addOption(node, menu, false);
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
          if (option.tagName === 'OPTION') addOption(option, group, true);
        });
        menu.appendChild(group);
      });
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
      const active = menu.querySelector('.dropdown-item.active');
      if (!active) return;
      // Scroll only the menu, not a surrounding modal/page/date picker.
      const itemRect = active.getBoundingClientRect();
      const menuRect = menu.getBoundingClientRect();
      if (itemRect.top < menuRect.top) menu.scrollTop -= menuRect.top - itemRect.top;
      else if (itemRect.bottom > menuRect.bottom) menu.scrollTop += itemRect.bottom - menuRect.bottom;
    }

    select.insertAdjacentElement('afterend', wrap);
    wrap.append(select, button, menu, error);
    select.classList.add('bd-select-source');
    select.dataset.bdSelect = '1';
    select.setAttribute('tabindex', '-1');
    select.setAttribute('aria-hidden', 'true');
    menu.addEventListener('click', onClick);
    select.addEventListener('input', onSync);
    select.addEventListener('change', onSync);
    select.addEventListener('bd-select:sync', onSync);
    select.addEventListener('focus', onFocus);
    select.addEventListener('invalid', onInvalid);
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
      destroy() {
        if (disposed) return;
        disposed = true;
        observer.disconnect();
        if (dropdown) {
          dropdown.hide();
          dropdown.dispose();
        }
        menu.removeEventListener('click', onClick);
        select.removeEventListener('input', onSync);
        select.removeEventListener('change', onSync);
        select.removeEventListener('bd-select:sync', onSync);
        select.removeEventListener('focus', onFocus);
        select.removeEventListener('invalid', onInvalid);
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

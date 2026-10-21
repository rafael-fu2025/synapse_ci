/** Page-owned plugins only; load once after core/main.js and before initial page-load. */
(function () {
  'use strict';
  if (window.SynapsePageLifecycle) return;
  const scopes = new WeakMap();
  const editors = new WeakMap();
  const editorSelector = '.quill-editor-default, .quill-editor-bubble, .quill-editor-full';

  function each(root, selector, callback) {
    if (root.matches?.(selector)) callback(root);
    root.querySelectorAll(selector).forEach(callback);
  }
  function quillFor(element) {
    return editors.get(element) || element.__quill || window.Quill?.find?.(element);
  }
  function init(root) {
    if (!root || scopes.has(root)) return;
    const events = new AbortController();
    scopes.set(root, events);
    each(root, '[data-bs-toggle="tooltip"]', element => {
      const Tooltip = window.bootstrap?.Tooltip;
      if (!Tooltip || Tooltip.getInstance(element)) return; // core/main.js owns initial instances.
      const tooltip = new Tooltip(element, { trigger: 'hover' });
      element.addEventListener('click', () => tooltip.hide(), { signal: events.signal });
    });
    each(root, '[data-bs-toggle="popover"]', element => {
      const Popover = window.bootstrap?.Popover;
      if (Popover && !Popover.getInstance(element)) new Popover(element);
    });
    if (window.Quill) each(root, editorSelector, element => {
      // Older Quill versions expose __quill; the container marker also protects
      // initial editors created by core/main.js when no lookup API is available.
      if (quillFor(element) || element.classList.contains('ql-container')) return;
      const options = { theme: element.matches('.quill-editor-bubble') ? 'bubble' : 'snow' };
      if (element.matches('.quill-editor-full')) options.modules = { toolbar: [
        [{ font: [] }, { size: [] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ color: [] }, { background: [] }],
        [{ script: 'super' }, { script: 'sub' }],
        [{ list: 'ordered' }, { list: 'bullet' }, { indent: '-1' }, { indent: '+1' }],
        ['direction', { align: [] }], ['link', 'image', 'video'], ['clean']
      ] };
      editors.set(element, new window.Quill(element, options));
    });
    window.BalaySelect?.init(root);
    window.BalayDatepicker?.init(root);
    window.BalayDateTimePicker?.init(root);
  }
  function destroy(root) {
    if (!root) return;
    scopes.get(root)?.abort();
    scopes.delete(root);
    // Navigation disposes all Bootstrap types next. Instance lookups make this
    // safe regardless of listener order, without touching persistent shell UI.
    each(root, '[data-bs-toggle="tooltip"], [data-bs-toggle="popover"]', element => {
      window.bootstrap?.Tooltip?.getInstance(element)?.dispose();
      window.bootstrap?.Popover?.getInstance(element)?.dispose();
    });
    each(root, editorSelector, element => {
      const editor = quillFor(element);
      // Quill has no public destroy API. Release focus/editing, not its internals;
      // removal releases the editor and its page-local toolbar DOM together.
      editor?.blur?.();
      editor?.disable?.();
      editors.delete(element);
    });
    window.BalaySelect?.destroy(root);
    // Datepicker owns removal cleanup; Alpine/HTMX own their respective trees.
  }
  window.SynapsePageLifecycle = { init, destroy };
  document.addEventListener('synapse:page-load', event => init(event.detail?.root));
  document.addEventListener('synapse:before-swap', event => destroy(event.detail?.root));
})();

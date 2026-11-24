/** PIF review actions copy current fields; page-owned listeners follow navigation. */
(function () {
  'use strict';
  const workspaces = new WeakSet();

  function init(root) {
    const workspace = root?.matches?.('.guidance-interview') ? root : root?.querySelector?.('.guidance-interview');
    if (!workspace || workspaces.has(workspace)) return;
    workspaces.add(workspace);
    const events = new AbortController();
    const submittedFields = new WeakMap();

    workspace.addEventListener('synapse:form-saved', event => {
      const form = event.detail?.form;
      const submitted = submittedFields.get(form);
      const editor = workspace.querySelector('#clinicalFormEditor');
      if (!submitted || !editor) return;
      const current = Array.from(new FormData(editor)).filter(([name]) => name.startsWith('fields['));
      if (JSON.stringify(submitted) !== JSON.stringify(current)) return;
      window.SynapseNavigation?.markSaved(editor);
    }, { signal: events.signal });

    workspace.querySelectorAll('form.js-sync-clinical-fields').forEach(form => {
      form.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        const editor = workspace.querySelector('#clinicalFormEditor');
        if (!editor) return;
        form.querySelectorAll('input[data-synced-field="1"]').forEach(input => input.remove());
        for (const [name, value] of new FormData(editor)) {
          if (!name.startsWith('fields[')) continue;
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = name;
          input.value = String(value);
          input.dataset.syncedField = '1';
          form.appendChild(input);
        }
      }, { capture: true, signal: events.signal });
    });

    window.SynapseNavigation?.registerCleanup(() => events.abort());
  }

  document.addEventListener('synapse:page-load', event => init(event.detail?.root || document));
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => init(document), { once: true });
  } else {
    init(document);
  }
})();

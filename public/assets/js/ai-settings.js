/** Administrator-owned provider setup. Secrets never enter browser storage, URLs, or feedback. */
(function () {
  'use strict';
  if (window.SynapseAiSettings) return;

  const instances = new Map();

  function mount(root) {
    const events = new AbortController();
    const form = root.querySelector('[data-ai-settings-form="save"]');
    const feedback = root.querySelector('[data-ai-settings-feedback]');
    const state = root.querySelector('[data-ai-settings-state]');
    if (!form || !feedback || !state) return () => events.abort();

    const toastKey = 'ai-settings';
    const connectionFields = ['base_url', 'api_key', 'remove_api_key', 'auth_mode', 'allow_private_network'];
    const allowedDefaultFields = ['max_input_tokens', 'max_output_tokens', 'thinking_mode', 'reasoning_effort', 'token_field', 'structured_output'];
    const modelSelect = root.querySelector('[data-ai-model-select]');
    const previewPanel = root.querySelector('[data-ai-model-preview]');
    const previewList = root.querySelector('[data-ai-model-preview-list]');
    const details = root.querySelector('[data-ai-model-details]');
    const previewSearch = root.querySelector('[data-ai-model-preview-search]');
    const catalogSearch = root.querySelector('[data-ai-model-catalog-search]');
    const modelDrawer = root.querySelector('[data-ai-model-drawer]');
    const modelDrawerOpen = root.querySelector('[data-ai-model-drawer-open]');
    const modelDrawerClose = root.querySelector('[data-ai-model-drawer-close]');
    const discoveryReady = !!(modelSelect && previewPanel && previewList && details &&
      root.querySelector('[data-ai-model-fetch]') && root.querySelector('[data-ai-model-import-selected]') &&
      root.querySelector('[data-ai-model-import-all]'));

    let owner;
    let busy = false;
    let activeAction = '';
    let dirty = false;
    let baseline = null;
    let pendingMutation = false;
    let stopped = false;
    let saved = false;
    let tested = false;
    let enabled = false;
    let statusKnown = true;
    let accessReady = false;
    let settings = null;
    let access = { ready: false, grants: [] };
    let catalog = { base_url: '', models: [], imported_at: null };
    let preview = null;
    let connectionRevision = 0;
    let discoveryPending = false;
    let drawerTrigger = null;
    let drawerFallbackOpen = false;
    const loadingCleanups = new Set();
    const pendingNavigationMessage = 'An AI settings change is still in progress. Wait for it to finish before leaving this page.';

    function toast(message, type) {
      try { window.SynapseToast?.show(message, { type, key: toastKey }); } catch (_) { /* Inline status remains authoritative. */ }
    }

    function endpoint(value, read = false) {
      const url = new URL(value, location.href);
      if (url.origin !== location.origin || !/^https?:$/.test(url.protocol) || url.username || url.password || url.search || url.hash ||
          !(read ? /\/admin\/ai\/status$/ : /\/admin\/ai\/(save|test|enable|disable|access|models\/preview|models\/import)$/).test(url.pathname)) {
        throw new Error('Invalid settings action.');
      }
      return url.href;
    }

    function canonicalBase(value) {
      try {
        const url = new URL(value.trim());
        if (!/^https?:$/.test(url.protocol) || url.username || url.password || url.search || url.hash) return '';
        return url.origin + url.pathname.replace(/\/+$/, '');
      } catch (_) { return ''; }
    }

    function matchingCatalog() {
      const base = canonicalBase(form.elements.namedItem('base_url').value);
      return !!base && base === canonicalBase(catalog.base_url);
    }

    function validPreview() {
      return preview && preview.revision === connectionRevision && preview.expires > Date.now() &&
        canonicalBase(preview.base_url) === canonicalBase(form.elements.namedItem('base_url').value);
    }

    function invalidatePreview() {
      preview = null;
      if (previewPanel) previewPanel.hidden = true;
      previewList?.replaceChildren();
    }

    function setProgressItem(name, value, itemState, title = '') {
      const item = root.querySelector(`[data-ai-progress-item="${name}"]`);
      const output = root.querySelector(`[data-ai-progress-${name}]`);
      if (item) item.dataset.state = itemState;
      if (output) {
        output.textContent = value;
        if (title) output.title = title;
        else output.removeAttribute('title');
      }
    }

    /** The only path that renders the setup summary from reconciled redacted state. */
    function renderProgress() {
      if (!settings) return;
      const grantCount = access.grants.length;
      const modelCount = catalog.models.length;
      const keyReady = settings.has_api_key || settings.auth_mode === 'none';
      const currentStep = settings.enabled ? 'activate' : (!settings.saved ? 'connection' :
        ((!access.ready || grantCount === 0) ? 'access' : 'activate'));

      setProgressItem('endpoint', settings.base_url || 'Not configured', settings.base_url ? 'complete' : 'pending', settings.base_url);
      setProgressItem('model', settings.model || 'Not selected', settings.model ? 'complete' : 'pending', settings.model);
      setProgressItem('key', settings.has_api_key ? 'Saved securely' : (settings.auth_mode === 'none' ? 'Not required' : 'Not saved'), keyReady ? 'complete' : 'pending');
      setProgressItem('imports', `${modelCount} imported`, modelCount ? 'complete' : 'optional');
      setProgressItem('test', statusKnown ? (settings.connection_tested ? 'Passed' : (settings.saved ? 'Required' : 'Save first')) : 'Status unknown',
        statusKnown && settings.connection_tested ? 'complete' : 'pending');
      setProgressItem('analytics', statusKnown ? (settings.enabled ? 'Enabled' : 'Paused') : 'Status unknown',
        statusKnown && settings.enabled ? 'complete' : 'paused');
      setProgressItem('access', access.ready ? 'Ready' : 'Unavailable', access.ready ? 'complete' : 'attention');
      setProgressItem('grants', `${grantCount} active`, grantCount ? 'complete' : 'pending');

      const launchCount = root.querySelector('[data-ai-model-launch-count]');
      if (launchCount) launchCount.textContent = String(modelCount);
      const catalogBadge = root.querySelector('[data-ai-model-catalog-badge]');
      if (catalogBadge) catalogBadge.textContent = `${modelCount} ${modelCount === 1 ? 'model' : 'models'}`;
      const overview = root.querySelector('[data-ai-overview-models]');
      if (overview) overview.textContent = `${modelCount} imported`;

      for (const link of root.querySelectorAll('[data-ai-progress-step]')) {
        const step = link.dataset.aiProgressStep;
        const output = link.querySelector('[data-ai-progress-step-state]');
        let value = 'Pending';
        let stepState = 'pending';
        if (step === 'connection') {
          value = settings.saved ? 'Saved' : 'Set up provider';
          stepState = settings.saved ? 'complete' : 'current';
        } else if (step === 'access') {
          value = !access.ready ? 'Storage unavailable' : `${grantCount} active ${grantCount === 1 ? 'grant' : 'grants'}`;
          stepState = !access.ready ? 'attention' : (grantCount ? 'complete' : 'current');
        } else if (step === 'activate') {
          value = settings.enabled ? 'Enabled' : (settings.connection_tested ? 'Ready to enable' : (settings.saved ? 'Test required' : 'Save first'));
          stepState = settings.enabled ? 'complete' : (settings.connection_tested ? 'ready' : 'pending');
        }
        link.dataset.state = stepState;
        if (output) output.textContent = value;
        if (step === currentStep) link.setAttribute('aria-current', 'step');
        else link.removeAttribute('aria-current');
      }

      const unitCounts = new Map();
      for (const grant of access.grants) unitCounts.set(grant.unit, (unitCounts.get(grant.unit) || 0) + 1);
      for (const output of root.querySelectorAll('[data-ai-unit-grant-count]')) {
        output.textContent = `${unitCounts.get(output.dataset.aiUnitGrantCount) || 0} active`;
      }

      const label = !statusKnown ? 'Status unknown · reload required' : (settings.enabled ? 'Aggregate AI enabled' :
        (settings.connection_tested ? 'Connection verified · analytics paused' : (settings.saved ? 'Saved · test connection next' : 'Not configured')));
      state.textContent = label;
      state.classList.toggle('bg-success', statusKnown && settings.enabled);
      state.classList.toggle('bg-secondary', !statusKnown || !settings.enabled);
    }

    function controls() {
      const freezeProviderFields = busy && ['save', 'test', 'reload'].includes(activeAction);
      const saveButton = form.querySelector('button[type="submit"]');
      const reloadButton = root.querySelector('[data-ai-settings-reload]');
      const testButton = root.querySelector('[data-ai-settings-test]');
      const enableButton = root.querySelector('[data-ai-settings-enable]');
      const disableButton = root.querySelector('[data-ai-settings-form="disable"] button[type="submit"]');
      for (const control of form.elements) {
        if (control === modelSelect || control.type === 'hidden' || control.matches('button, input[type="submit"], input[type="button"], input[type="reset"]')) continue;
        control.disabled = freezeProviderFields;
      }
      if (saveButton) saveButton.disabled = busy;
      if (reloadButton) reloadButton.disabled = busy;
      if (testButton) {
        testButton.disabled = busy || !saved || dirty || !statusKnown;
        testButton.textContent = enabled ? 'Pause analytics and test connection' : 'Test connection';
      }
      if (enableButton) enableButton.disabled = busy || !tested || dirty || !statusKnown;
      if (disableButton) disableButton.disabled = busy;
      root.querySelectorAll('[data-ai-settings-form="access"] button').forEach(button => {
        button.disabled = busy || !accessReady || button.closest('form').dataset.aiSourceBlocked === 'true';
      });
      if (discoveryReady) {
        modelSelect.disabled = freezeProviderFields || !matchingCatalog() || !catalog.models.length;
        root.querySelector('[data-ai-model-fetch]').disabled = busy;
        root.querySelector('[data-ai-model-import-selected]').disabled = busy || !validPreview() || !previewList.querySelector('input:checked');
        root.querySelector('[data-ai-model-import-all]').disabled = busy || !validPreview() || !preview.models.length;
      }
    }

    function editableEntries() {
      const entries = [];
      for (const control of form.elements) {
        if (!control.name || control.type === 'submit' || control.type === 'button' ||
            control.type === 'reset' || control.type === 'hidden' || control === modelSelect) continue;
        if ((control.type === 'checkbox' || control.type === 'radio') && !control.checked) continue;
        entries.push([control.name, control.value]);
      }
      return entries.sort((left, right) => left[0].localeCompare(right[0]) || left[1].localeCompare(right[1]));
    }

    function serializeEditable() {
      return JSON.stringify(editableEntries());
    }

    function markSavedAfterSharedListener() {
      // Run after the bubbling document listener that records changed POST forms.
      setTimeout(() => {
        if (!stopped && !dirty) window.SynapseNavigation?.markSaved(form);
      }, 0);
    }

    function captureBaseline() {
      baseline = serializeEditable();
      dirty = false;
      window.SynapseNavigation?.markSaved(form);
    }

    function updateDirtyState() {
      const wasDirty = dirty;
      dirty = baseline !== null && serializeEditable() !== baseline;
      if (dirty) feedback.textContent = 'Unsaved changes. Save before testing or enabling analytics.';
      else {
        if (wasDirty) feedback.textContent = 'Saved settings restored.';
        // A user gesture can emit both input and change; reassert clean state after each bubbles.
        markSavedAfterSharedListener();
      }
      return dirty;
    }

    function beginLoading(target, button, label) {
      let finish = () => {};
      try { finish = window.SynapseLoading?.begin(target, { label, button }) || finish; } catch (_) { /* Inline status remains authoritative. */ }
      let finished = false;
      const cleanup = () => {
        if (finished) return;
        finished = true;
        loadingCleanups.delete(cleanup);
        try { finish(); } catch (_) { /* Controls are also reconciled below. */ }
      };
      loadingCleanups.add(cleanup);
      return cleanup;
    }

    function finishAllLoading() {
      for (const cleanup of [...loadingCleanups]) cleanup();
    }

    function setBusy(action, value) {
      busy = value;
      activeAction = value ? action : '';
      controls();
    }

    function guardNavigation(event) {
      if (!pendingMutation) return;
      event.preventDefault();
      feedback.textContent = pendingNavigationMessage;
    }

    function guardUnload(event) {
      if (!pendingMutation) return;
      event.preventDefault();
      event.returnValue = pendingNavigationMessage;
      return pendingNavigationMessage;
    }

    document.addEventListener('synapse:before-navigate', guardNavigation, { signal: events.signal });
    window.addEventListener('beforeunload', guardUnload, { signal: events.signal });

    function validateRows(rows) {
      if (!Array.isArray(rows) || rows.length > 200) throw new Error('Invalid model catalog.');
      const ids = new Set();
      for (const row of rows) {
        if (!row || typeof row.id !== 'string' || !row.id.length || row.id.length > 191 ||
            typeof row.name !== 'string' || row.name.length > 191 || ids.has(row.id) ||
            !['context_window', 'max_output_tokens'].every(key => row[key] === null || (Number.isSafeInteger(row[key]) && row[key] > 0 && row[key] <= 1000000000)) ||
            !(row.supports_thinking === null || typeof row.supports_thinking === 'boolean') ||
            !row.sources || typeof row.sources !== 'object' || Array.isArray(row.sources) || Object.keys(row.sources).length > 20 ||
            Object.values(row.sources).some(value => typeof value !== 'string' || value.length > 200) ||
            !Array.isArray(row.warnings) || row.warnings.length > 10 || row.warnings.some(value => typeof value !== 'string' || value.length > 1000)) {
          throw new Error('Invalid model metadata.');
        }
        ids.add(row.id);
        const defaults = row.defaults;
        if (!defaults || !allowedDefaultFields.every(key => {
          const control = form.elements.namedItem(key);
          const value = defaults[key];
          return control.tagName === 'SELECT' ? [...control.options].some(option => option.value === value) :
            Number.isSafeInteger(value) && value >= Number(control.min) && value <= Number(control.max);
        })) throw new Error('Invalid model defaults.');
      }
      return rows;
    }

    function validateSettings(value) {
      if (!value || typeof value.version !== 'string' || typeof value.base_url !== 'string' || typeof value.model !== 'string' ||
          !['', 'disabled', 'enabled'].includes(value.thinking_mode) ||
          !Number.isSafeInteger(value.max_input_tokens) || !Number.isSafeInteger(value.max_output_tokens) ||
          ['saved', 'has_api_key', 'connection_tested', 'enabled', 'allow_private_network'].some(key => typeof value[key] !== 'boolean') ||
          !Array.isArray(value.approved_units) || value.approved_units.length > 3 ||
          value.approved_units.some(unit => !['clinic', 'guidance', 'bmg'].includes(unit)) ||
          Object.hasOwn(value, 'api_key') || Object.hasOwn(value, 'storage_key')) {
        throw new Error('The settings response was incomplete. Reload saved settings before continuing.');
      }
      for (const key of ['base_url', 'model', 'reasoning_effort', 'thinking_mode', 'max_input_tokens', 'max_output_tokens', 'auth_mode', 'token_field', 'structured_output']) {
        if (!['string', 'number'].includes(typeof value[key])) throw new Error('Invalid settings response.');
      }
      return value;
    }

    function validateAccess(value) {
      if (!value || typeof value.ready !== 'boolean' || !Array.isArray(value.grants) || value.grants.length > 40) {
        throw new Error('Invalid access response. Reload saved settings.');
      }
      const forms = [...root.querySelectorAll('[data-ai-settings-form="access"]')];
      const key = row => [row.unit, row.role, row.permission].join(':');
      const known = new Set(forms.map(item => key(Object.fromEntries(new FormData(item)))));
      const seen = new Set();
      for (const row of value.grants) {
        if (!row || !['unit', 'role', 'permission'].every(field => typeof row[field] === 'string') ||
            !known.has(key(row)) || seen.has(key(row))) throw new Error('Invalid access response. Reload saved settings.');
        seen.add(key(row));
      }
      return value;
    }

    function validateCatalog(value) {
      if (!value || typeof value.base_url !== 'string' || value.base_url.length > 2048 ||
          (value.base_url !== '' && !canonicalBase(value.base_url))) throw new Error('Invalid catalog endpoint.');
      return { base_url: value.base_url, models: validateRows(value.models), imported_at: value.imported_at ?? null };
    }

    function sourceLabel(value) {
      if (typeof value !== 'string' || value === 'unknown') return 'Unknown source';
      if (value.startsWith('provider: ')) return `Provider reported (${value.slice(10)})`;
      if (value === 'reviewed preset') return 'Reviewed recommendation (not provider metadata)';
      if (value === 'provider default') return 'Provider default (no explicit override)';
      if (value.startsWith('local safe budget') || value.startsWith('current config')) return `App recommendation (${value})`;
      return 'Unknown source';
    }

    function modelOptionLabel(row) {
      const name = row.name.trim();
      return name === row.id ? row.id : `${name} — ${row.id}`;
    }

    function detailRow(term, value, source) {
      const item = document.createElement('div');
      item.className = 'ai-settings-model-fact';
      const label = document.createElement('dt');
      label.textContent = term;
      const detail = document.createElement('dd');
      const primary = document.createElement('span');
      primary.className = 'ai-settings-model-fact-value';
      primary.textContent = value;
      detail.append(primary);
      if (source) {
        const metadata = document.createElement('small');
        metadata.textContent = source;
        detail.append(metadata);
      }
      item.append(label, detail);
      return item;
    }

    function describe(row, container) {
      container.replaceChildren();
      const heading = document.createElement('p');
      heading.className = 'ai-settings-model-details-title';
      heading.textContent = modelOptionLabel(row);
      const facts = document.createElement('dl');
      facts.className = 'ai-settings-model-facts';
      facts.append(
        detailRow('Context window', row.context_window === null ? 'Unknown' : row.context_window.toLocaleString(), sourceLabel(row.sources.context_window)),
        detailRow('Maximum output', row.max_output_tokens === null ? 'Unknown' : row.max_output_tokens.toLocaleString(), sourceLabel(row.sources.max_output_tokens)),
        detailRow('Thinking support', row.supports_thinking === null ? 'Unknown' : (row.supports_thinking ? 'Supported' : 'Not reported'), sourceLabel(row.sources.supports_thinking)),
      );

      const recommendations = document.createElement('details');
      recommendations.className = 'ai-settings-model-recommendations';
      const summary = document.createElement('summary');
      summary.textContent = 'Recommended configuration';
      const list = document.createElement('dl');
      list.className = 'ai-settings-model-facts mt-2';
      const labels = {
        max_input_tokens: 'Input budget', max_output_tokens: 'Output limit', thinking_mode: 'Thinking mode',
        reasoning_effort: 'Reasoning effort', token_field: 'Output token field', structured_output: 'Structured output',
      };
      for (const key of allowedDefaultFields) {
        const raw = row.defaults[key];
        const value = raw === '' ? 'Provider default' : (typeof raw === 'number' ? raw.toLocaleString() : String(raw));
        list.append(detailRow(labels[key] || key, value, sourceLabel(row.sources['defaults.' + key])));
      }
      recommendations.append(summary, list);
      container.append(heading, facts, recommendations);

      if (row.warnings.length) {
        const warnings = document.createElement('ul');
        warnings.className = 'ai-settings-model-warnings';
        for (const warning of row.warnings) {
          const item = document.createElement('li');
          item.textContent = warning;
          warnings.append(item);
        }
        container.append(warnings);
      }
    }

    function matchesSearch(row, query) {
      return `${row.id}\n${row.name}`.toLowerCase().includes(query.trim().toLowerCase());
    }

    function filterPreview() {
      if (!preview || !previewList) return;
      const rows = [...previewList.children];
      rows.forEach((element, index) => { element.hidden = !matchesSearch(preview.models[index], previewSearch?.value || ''); });
      const count = root.querySelector('[data-ai-model-preview-count]');
      if (count) count.textContent = `${rows.filter(element => !element.hidden).length} of ${rows.length} shown · ${previewList.querySelectorAll('input:checked').length} selected`;
    }

    function filterCatalog() {
      if (!discoveryReady) return;
      const selected = modelSelect.value;
      modelSelect.replaceChildren(new Option('Choose an imported model (optional)', ''));
      if (matchingCatalog()) {
        for (const row of catalog.models) {
          if (matchesSearch(row, catalogSearch?.value || '')) modelSelect.add(new Option(modelOptionLabel(row), row.id));
        }
      }
      if ([...modelSelect.options].some(option => option.value === selected)) modelSelect.value = selected;
      const count = root.querySelector('[data-ai-model-catalog-count]');
      if (count) count.textContent = matchingCatalog() ? `${modelSelect.options.length - 1} of ${catalog.models.length} matching. Filtering does not change active settings.` : '';
    }

    function renderCatalog() {
      if (!discoveryReady) return;
      filterCatalog();
      details.replaceChildren();
      const matches = matchingCatalog();
      root.querySelector('[data-ai-model-catalog-state]').textContent = !catalog.models.length
        ? 'No imported models yet. Fetch models or keep using a manual exact ID.'
        : (!matches ? 'This catalog belongs to a different endpoint. Fetch models for the current exact API base.'
          : `${catalog.models.length} imported model(s). Applying one fills recommendations but does not save them.`);
    }

    function applySettings(value) {
      settings = validateSettings(value);
      for (const key of ['base_url', 'model', 'reasoning_effort', 'thinking_mode', 'max_input_tokens', 'max_output_tokens', 'auth_mode', 'token_field', 'structured_output']) {
        form.elements.namedItem(key).value = String(settings[key]);
      }
      form.elements.namedItem('allow_private_network').checked = settings.allow_private_network;
      for (const unit of ['clinic', 'guidance', 'bmg']) form.elements.namedItem(`allow_${unit}`).checked = settings.approved_units.includes(unit);
      form.elements.namedItem('api_key').value = '';
      form.elements.namedItem('remove_api_key').checked = false;
      form.elements.namedItem('api_key').placeholder = settings.has_api_key ? 'Saved securely — leave blank to keep it' : 'Enter the provider API key';
      root.querySelector('[data-ai-key-state]').textContent = settings.has_api_key
        ? 'A key is saved. It is never sent back to this page.'
        : 'No key is saved. No-auth endpoints must be selected under Advanced.';
      root.querySelectorAll('input[name="version"]').forEach(input => { input.value = settings.version; });
      saved = settings.saved;
      tested = settings.connection_tested;
      enabled = settings.enabled;
      statusKnown = true;
      dirty = false;
      root.querySelector('[data-ai-settings-form="enable"]').hidden = enabled;
      root.querySelector('[data-ai-settings-form="disable"]').hidden = !enabled;
      root.querySelector('[name="confirm_analytics"]').checked = false;
      invalidatePreview();
      renderCatalog();
      captureBaseline();
    }

    function applyAccess(value) {
      access = validateAccess(value);
      accessReady = access.ready;
      const key = row => [row.unit, row.role, row.permission].join(':');
      const granted = new Set(access.grants.map(key));
      for (const item of root.querySelectorAll('[data-ai-settings-form="access"]')) {
        const row = Object.fromEntries(new FormData(item));
        const isGranted = granted.has(key(row));
        const operation = isGranted ? 'revoke' : 'grant';
        const verb = isGranted ? 'Revoke' : 'Grant';
        const permissionLabel = item.dataset.aiPermissionLabel;
        const sourceRestricted = item.dataset.aiSourceRestricted === 'true';
        const sourceBlocked = sourceRestricted && !isGranted;
        item.elements.namedItem('operation').value = operation;
        item.dataset.aiSourceBlocked = String(sourceBlocked);
        const button = item.querySelector('button');
        button.textContent = `${verb} ${permissionLabel}`;
        button.classList.toggle('btn-outline-danger', isGranted);
        button.classList.toggle('btn-outline-primary', !isGranted);
        button.setAttribute('aria-label', `${verb} ${permissionLabel} for ${item.dataset.aiRoleLabel} in ${item.dataset.aiUnitLabel}`);
        const permissionRow = item.closest('[data-ai-permission-row]');
        permissionRow.dataset.state = isGranted ? 'granted' : (sourceBlocked ? 'unavailable' : 'not-granted');
        const permissionState = permissionRow.querySelector('[data-ai-permission-state]');
        if (permissionState) permissionState.textContent = isGranted ? 'Granted' : (sourceBlocked ? 'Unavailable' : 'Not granted');
        const sourceReason = permissionRow.querySelector('[data-ai-source-reason]');
        if (sourceReason) sourceReason.textContent = isGranted
          ? 'Counsellors lack guidance.cases.read_any source access. This existing grant cannot enable generation and remains revocable.'
          : 'Unavailable: counsellors lack guidance.cases.read_any source access. An AI grant cannot supply it.';
      }
      root.querySelector('[data-ai-access-state]').textContent = accessReady
        ? 'Access storage ready. Changes apply on the next authorization check.'
        : 'AI access or audit storage is unavailable. Changes are disabled.';
    }

    function applyCatalog(value) {
      catalog = validateCatalog(value);
      if (catalogSearch) catalogSearch.value = '';
      renderCatalog();
    }

    function reconcile(value) {
      let catalogNeedsRender = false;
      if (Object.hasOwn(value, 'settings')) { applySettings(value.settings); catalogNeedsRender = true; }
      if (Object.hasOwn(value, 'access')) applyAccess(value.access);
      if (Object.hasOwn(value, 'catalog')) { applyCatalog(value.catalog); catalogNeedsRender = false; }
      if (catalogNeedsRender) renderCatalog();
      renderProgress();
      controls();
    }

    modelSelect?.addEventListener('change', () => {
      if (busy || stopped || !matchingCatalog()) return;
      const row = catalog.models.find(item => item.id === modelSelect.value);
      details.replaceChildren();
      if (!row) return;
      const changed = [];
      const setRecommended = (control, value) => {
        const next = String(value);
        if (control.value === next) return;
        control.value = next;
        changed.push(control);
      };
      setRecommended(form.elements.namedItem('model'), row.id);
      for (const key of Object.keys(row.defaults)) {
        if (allowedDefaultFields.includes(key)) setRecommended(form.elements.namedItem(key), row.defaults[key]);
      }
      for (const control of changed) {
        control.dispatchEvent(new Event('input', { bubbles: true }));
        control.dispatchEvent(new Event('change', { bubbles: true }));
      }
      describe(row, details);
      updateDirtyState();
      root.querySelector('[data-ai-model-defaults-note]').textContent = 'Recommendations applied to step 1. They remain editable and are not saved yet.';
      controls();
    }, { signal: events.signal });

    previewSearch?.addEventListener('input', filterPreview, { signal: events.signal });
    catalogSearch?.addEventListener('input', filterCatalog, { signal: events.signal });
    previewList?.addEventListener('change', () => { filterPreview(); controls(); }, { signal: events.signal });

    function showDrawer(trigger) {
      if (!modelDrawer) return;
      drawerTrigger = trigger || modelDrawerOpen;
      modelDrawerOpen?.setAttribute('aria-expanded', 'true');
      drawerFallbackOpen = true;
      modelDrawer.classList.add('show');
      modelDrawer.style.visibility = 'visible';
      modelDrawer.setAttribute('role', 'dialog');
      modelDrawer.setAttribute('aria-modal', 'true');
      modelDrawer.removeAttribute('aria-hidden');
      document.body.classList.add('ai-settings-drawer-open');
      modelDrawerClose?.focus({ preventScroll: true });
    }

    function hideDrawer() {
      if (!modelDrawer) return;
      modelDrawerOpen?.setAttribute('aria-expanded', 'false');
      drawerFallbackOpen = false;
      modelDrawer.classList.remove('show');
      modelDrawer.style.visibility = 'hidden';
      modelDrawer.removeAttribute('role');
      modelDrawer.removeAttribute('aria-modal');
      modelDrawer.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('ai-settings-drawer-open');
      modelDrawerOpen?.focus({ preventScroll: true });
    }

    modelDrawerOpen?.addEventListener('click', event => {
      event.preventDefault();
      showDrawer(event.currentTarget);
    }, { signal: events.signal });
    modelDrawerClose?.addEventListener('click', event => {
      event.preventDefault();
      hideDrawer();
    }, { signal: events.signal });
    document.addEventListener('keydown', event => {
      if (event.key !== 'Escape' || !drawerFallbackOpen) return;
      event.preventDefault();
      event.stopPropagation();
      hideDrawer();
    }, { capture: true, signal: events.signal });

    async function request(url, body, timeoutMs) {
      const requestOwner = new AbortController();
      owner = requestOwner;
      const timer = setTimeout(() => requestOwner.abort(), timeoutMs);
      try {
        const response = await fetch(endpoint(url, !body), {
          method: body ? 'POST' : 'GET', body, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: requestOwner.signal,
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(body ? window.SynapseCsrf?.headers() : {}) },
        });
        if (stopped || requestOwner.signal.aborted) throw new DOMException('Cancelled', 'AbortError');
        if (window.SynapseCsrf?.fromResponse(response)) {
          form.elements.namedItem('api_key').value = '';
          throw new Error('Sign-in navigation is in progress.');
        }
        if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Unexpected settings response. Check saved settings before retrying.');
        const raw = await response.text();
        if (stopped || requestOwner.signal.aborted) throw new DOMException('Cancelled', 'AbortError');
        if (raw.length > 524288) throw new Error('Settings response is too large.');
        const result = JSON.parse(raw);
        window.SynapseCsrf?.sync(result.csrf);
        if (!response.ok || result.success !== true) {
          const error = new Error(typeof result.message === 'string' ? result.message.slice(0, 1500) : 'Settings action failed.');
          error.confirmed = true;
          throw error;
        }
        return result;
      } finally {
        clearTimeout(timer);
        if (owner === requestOwner) owner = null;
      }
    }

    async function discover(all, importing) {
      if (busy || stopped || !discoveryReady) return;
      if (importing && !validPreview()) {
        invalidatePreview();
        renderProgress();
        controls();
        feedback.textContent = 'Preview expired or the connection changed. Fetch models again.';
        return;
      }
      if (importing && !preview.models.length) return;
      const body = new URLSearchParams();
      for (const input of form.querySelectorAll('input[type="hidden"]')) {
        if (input.name !== 'version' && input.name !== 'unit_selection') body.set(input.name, input.value);
      }
      if (importing) {
        const ids = all ? [] : [...previewList.querySelectorAll('input:checked')].map(input => input.value);
        if (!all && !ids.length) return;
        body.set('preview_token', preview.preview_token);
        body.set('model_ids', JSON.stringify(ids));
        body.set('import_all', all ? '1' : '0');
      } else {
        if (!form.elements.namedItem('base_url').reportValidity() || !form.elements.namedItem('api_key').reportValidity()) return;
        for (const key of ['version', ...connectionFields]) {
          const control = form.elements.namedItem(key);
          body.set(key, control.type === 'checkbox' ? (control.checked ? '1' : '0') : control.value);
        }
        invalidatePreview();
      }
      const revision = connectionRevision;
      const button = importing
        ? root.querySelector(all ? '[data-ai-model-import-all]' : '[data-ai-model-import-selected]')
        : root.querySelector('[data-ai-model-fetch]');
      const loadingTarget = importing ? root.querySelector('.ai-settings-preview-panel') : root.querySelector('.ai-settings-drawer-actions');
      const loadingLabel = importing ? (all ? 'Importing all models…' : 'Importing selected models…') : 'Fetching models…';
      const finishLoading = beginLoading(loadingTarget, button, loadingLabel);
      if (importing) pendingMutation = true;
      setBusy(importing ? 'import' : 'fetch', true);
      discoveryPending = true;
      feedback.textContent = importing ? 'Importing models…' : 'Fetching models using the current connection details…';
      try {
        const result = await request(importing ? root.dataset.modelImportUrl : root.dataset.modelPreviewUrl, body, 45000);
        if (stopped || revision !== connectionRevision) return;
        if (importing) {
          reconcile(result);
          invalidatePreview();
          feedback.textContent = 'Models imported. Apply one to fill recommendations, or keep the manual ID. Active settings and credentials are unchanged.';
        } else {
          const value = result.preview;
          const expires = typeof value?.expires_at === 'number' ? value.expires_at * 1000 : Date.parse(value?.expires_at);
          if (!value || typeof value.preview_token !== 'string' || !/^[a-f0-9]{64}$/.test(value.preview_token) ||
              !Number.isFinite(expires) || expires <= Date.now() || typeof value.base_url !== 'string' ||
              !canonicalBase(value.base_url) || canonicalBase(value.base_url) !== canonicalBase(form.elements.namedItem('base_url').value)) {
            throw new Error('Invalid preview.');
          }
          const rows = validateRows(value.models);
          preview = { preview_token: value.preview_token, base_url: value.base_url, models: rows, expires, revision };
          for (const row of rows) {
            const wrapper = document.createElement('div');
            wrapper.className = 'ai-settings-model-option';
            const label = document.createElement('label');
            label.className = 'ai-settings-model-option-label';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = row.id;
            input.className = 'form-check-input';
            const title = document.createElement('span');
            title.textContent = modelOptionLabel(row);
            const metadata = document.createElement('details');
            metadata.className = 'small mt-2';
            const summary = document.createElement('summary');
            summary.textContent = 'Capabilities and recommended defaults';
            metadata.append(summary);
            metadata.addEventListener('toggle', () => {
              if (metadata.open && metadata.childElementCount === 1) describe(row, metadata);
            }, { signal: events.signal });
            label.append(input, title);
            wrapper.append(label, metadata);
            previewList.append(wrapper);
          }
          if (previewSearch) previewSearch.value = '';
          filterPreview();
          previewPanel.hidden = false;
          const emptyMessage = 'No models compatible with the minimum app budget were returned. No models can be imported from this preview. Enter a manual exact model ID or check the provider endpoint.';
          root.querySelector('[data-ai-model-preview-state]').textContent = rows.length
            ? `${rows.length} model(s). Preview expires at ${new Date(expires).toLocaleTimeString()}. Select models or import all (up to 200).`
            : emptyMessage;
          feedback.textContent = rows.length ? 'Preview ready. Review capabilities before importing.' : emptyMessage;
          renderProgress();
        }
      } catch (error) {
        invalidatePreview();
        renderProgress();
        if (!stopped) feedback.textContent = revision !== connectionRevision ? 'Connection changed. Fetch models again.' :
          (error.confirmed ? error.message : 'Model discovery/import could not be confirmed. No automatic retry was made. Fetch a fresh preview before retrying.');
      } finally {
        discoveryPending = false;
        if (importing) pendingMutation = false;
        finishLoading();
        setBusy('', false);
      }
    }

    root.querySelector('[data-ai-model-fetch]')?.addEventListener('click', () => discover(false, false), { signal: events.signal });
    root.querySelector('[data-ai-model-import-selected]')?.addEventListener('click', () => discover(false, true), { signal: events.signal });
    root.querySelector('[data-ai-model-import-all]')?.addEventListener('click', () => discover(true, true), { signal: events.signal });

    function edited(event) {
      updateDirtyState();
      if (connectionFields.includes(event.target.name)) {
        ++connectionRevision;
        invalidatePreview();
        if (discoveryPending) owner?.abort();
        renderCatalog();
      }
      if (event.target.name === 'model') {
        if (modelSelect) modelSelect.value = '';
        details?.replaceChildren();
      }
      controls();
    }

    form.addEventListener('input', edited, { signal: events.signal });
    form.addEventListener('change', edited, { signal: events.signal });

    root.addEventListener('submit', async event => {
      const submitted = event.target.closest('[data-ai-settings-form]');
      if (!submitted || !root.contains(submitted)) return;
      event.preventDefault();
      const action = submitted.dataset.aiSettingsForm;
      if (busy || stopped || (action === 'access' && (!accessReady || submitted.dataset.aiSourceBlocked === 'true')) ||
          !submitted.reportValidity() || (dirty && ['test', 'enable'].includes(action))) return;
      const body = new URLSearchParams(new FormData(submitted));
      if (action === 'test' && enabled) {
        if (!window.confirm('Pause aggregate analytics and test the saved connection? Analytics will stay paused after success or failure until you explicitly enable it again.')) return;
        body.set('confirm_pause', '1');
      }
      const submitter = event.submitter || submitted.querySelector('button[type="submit"]');
      const labels = {
        save: 'Saving AI configuration…', test: 'Testing connection…', access: 'Updating department access…',
        enable: 'Enabling aggregate AI…', disable: 'Pausing aggregate AI…',
      };
      const finishLoading = beginLoading(submitted, submitter, labels[action] || 'Updating AI settings…');
      pendingMutation = true;
      setBusy(action, true);
      feedback.textContent = action === 'access' ? 'Updating department AI access…' :
        (action === 'test' ? 'Testing the saved connection with a harmless message…' :
          (action === 'enable' ? 'Enabling protected aggregate analytics…' :
            (action === 'disable' ? 'Pausing aggregate analytics…' : 'Saving AI configuration…')));
      try {
        const result = await request(submitted.action, body, action === 'test' ? 135000 : 15000);
        reconcile(result);
        feedback.textContent = result.message || 'AI configuration updated.';
        const tone = action === 'test' || action === 'enable' ? 'success' : (action === 'disable' ? 'warning' : 'info');
        toast(feedback.textContent, tone);
      } catch (error) {
        if (!stopped) {
          feedback.textContent = error.confirmed ? error.message : 'The result could not be confirmed. No automatic retry was made. Reload saved settings before submitting again.';
          toast(feedback.textContent, 'error');
        }
      } finally {
        if (action === 'test' && !stopped) {
          const message = feedback.textContent;
          try {
            const result = await request(root.dataset.statusUrl, null, 15000);
            reconcile(result);
            feedback.textContent = `${message} ${enabled ? 'Aggregate analytics is currently enabled.' : 'Aggregate analytics is paused; enabling it requires your explicit action.'}`;
          } catch (_) {
            statusKnown = false;
            saved = false;
            tested = false;
            enabled = false;
            renderProgress();
            feedback.textContent = `${message} Current analytics status could not be loaded. Reload saved settings before continuing; no automatic retry or enable was made.`;
          }
        }
        pendingMutation = false;
        finishLoading();
        renderProgress();
        setBusy('', false);
      }
    }, { signal: events.signal });

    root.querySelector('[data-ai-settings-reload]')?.addEventListener('click', async event => {
      if (busy || stopped || (dirty && !window.confirm('Discard unsaved settings and reload the saved configuration?'))) return;
      const button = event.currentTarget;
      const finishLoading = beginLoading(button.closest('.ai-settings-intro') || button.parentElement, button, 'Reloading saved settings…');
      setBusy('reload', true);
      try {
        const result = await request(root.dataset.statusUrl, null, 15000);
        reconcile(result);
        feedback.textContent = 'Saved settings loaded. The API key remains hidden.';
      } catch (error) {
        if (!stopped) feedback.textContent = error.message || 'Saved settings could not be loaded.';
      } finally {
        finishLoading();
        renderProgress();
        setBusy('', false);
      }
    }, { signal: events.signal });

    function parseTemplate(selector, attribute, fallback) {
      const raw = root.querySelector(selector)?.dataset[attribute];
      if (typeof raw !== 'string') return fallback;
      if (raw.length > 524288) throw new Error('Initial settings data is too large.');
      return JSON.parse(raw);
    }

    const initial = {};
    try { initial.settings = parseTemplate('[data-ai-settings-data]', 'settings', null); }
    catch (_) { feedback.textContent = 'Saved settings could not be read. Reload before making changes.'; }
    try { initial.access = parseTemplate('[data-ai-access-data]', 'access', { ready: false, grants: [] }); }
    catch (_) { initial.access = { ready: false, grants: [] }; }
    try { initial.catalog = parseTemplate('[data-ai-model-catalog]', 'catalog', { base_url: '', models: [], imported_at: null }); }
    catch (_) { initial.catalog = { base_url: '', models: [], imported_at: null }; }

    try {
      reconcile(initial);
      root.removeAttribute('aria-busy');
    } catch (_) {
      accessReady = false;
      access = { ready: false, grants: [] };
      catalog = { base_url: '', models: [], imported_at: null };
      const accessState = root.querySelector('[data-ai-access-state]');
      if (accessState) accessState.textContent = 'Access state could not be loaded. Access changes are disabled; reload saved settings.';
      const catalogState = root.querySelector('[data-ai-model-catalog-state]');
      if (catalogState) catalogState.textContent = 'Catalog unavailable. Fetch models again or keep using a manual ID.';
      renderProgress();
      controls();
      root.removeAttribute('aria-busy');
    }

    return () => {
      stopped = true;
      pendingMutation = false;
      busy = false;
      activeAction = '';
      discoveryPending = false;
      finishAllLoading();
      invalidatePreview();
      owner?.abort();
      form.elements.namedItem('api_key').value = '';
      if (drawerFallbackOpen) hideDrawer();
      else document.body.classList.remove('ai-settings-drawer-open');
      events.abort();
    };
  }

  function init(scope = document) {
    for (const [root, destroy] of instances) {
      if (!root.isConnected) { destroy(); instances.delete(root); }
    }
    const roots = [...scope.querySelectorAll('[data-ai-settings]')];
    if (scope.matches?.('[data-ai-settings]')) roots.unshift(scope);
    for (const root of roots) if (!instances.has(root)) instances.set(root, mount(root));
  }

  window.SynapseAiSettings = { init };
  document.addEventListener('synapse:page-load', event => init(event.detail?.root || document));
  document.addEventListener('synapse:before-swap', event => {
    for (const [root, destroy] of instances) {
      if (!event.detail?.root || event.detail.root === root || event.detail.root.contains(root)) {
        destroy();
        instances.delete(root);
      }
    }
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  else init();
})();

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
    let owner, busy = false, dirty = false, stopped = false;
    let preview = null, catalog = { base_url: '', models: [], imported_at: null }, connectionRevision = 0, discoveryPending = false;
    const toastKey = 'ai-settings';
    function toast(message, type) {
      try { window.SynapseToast?.show(message, { type, key: toastKey }); } catch (_) { /* Feedback already exists inline. */ }
    }
    const connectionFields = ['base_url', 'api_key', 'remove_api_key', 'auth_mode', 'allow_private_network'];
    const modelSelect = root.querySelector('[data-ai-model-select]');
    const previewPanel = root.querySelector('[data-ai-model-preview]');
    const previewList = root.querySelector('[data-ai-model-preview-list]');
    const details = root.querySelector('[data-ai-model-details]');
    const previewSearch = root.querySelector('[data-ai-model-preview-search]');
    const catalogSearch = root.querySelector('[data-ai-model-catalog-search]');
    const discoveryReady = !!(modelSelect && previewPanel && previewList && details &&
      root.querySelector('[data-ai-model-fetch]') && root.querySelector('[data-ai-model-import-selected]') && root.querySelector('[data-ai-model-import-all]'));
    let accessReady = root.querySelector('[data-ai-settings-form="access"] button')?.disabled === false;
    // Server-rendered readiness: unavailable access/audit storage disables grants until an explicit reload.
    const accessData = root.querySelector('[data-ai-access-data]');
    if (accessData) {
      try { const parsed = JSON.parse(accessData.dataset.access || '{}'); if (typeof parsed.ready === 'boolean') accessReady = parsed.ready; } catch (_) { accessReady = false; }
    }
    let saved = !!form.querySelector('[name="version"]').value;
    let tested = !root.querySelector('[data-ai-settings-enable]').disabled;
    let enabled = root.querySelector('[data-ai-settings-form="enable"]').hidden;
    function endpoint(value, read = false) {
      const url = new URL(value, location.href);
      if (url.origin !== location.origin || !/^https?:$/.test(url.protocol) || url.username || url.password || url.search || url.hash ||
          !(read ? /\/admin\/ai\/status$/ : /\/admin\/ai\/(save|test|enable|disable|access|models\/preview|models\/import)$/).test(url.pathname)) throw new Error('Invalid settings action.');
      return url.href;
    }
    function controls() {
      root.querySelectorAll('button, input:not([type="hidden"]), select, textarea').forEach(control => { control.disabled = busy; });
      root.querySelector('[data-ai-settings-test]').disabled = busy || !saved || dirty;
      root.querySelector('[data-ai-settings-enable]').disabled = busy || !tested || dirty;
      root.querySelectorAll('[data-ai-settings-form="access"] button').forEach(button => { button.disabled = busy || !accessReady; });
      if (discoveryReady) {
        modelSelect.disabled = busy || !matchingCatalog() || !catalog.models.length;
        root.querySelector('[data-ai-model-import-selected]').disabled = busy || !validPreview() || !previewList.querySelector('input:checked');
        root.querySelector('[data-ai-model-import-all]').disabled = busy || !validPreview() || !preview.models.length;
      }
      root.setAttribute('aria-busy', String(busy));
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
    function markDirty() {
      dirty = true;
      feedback.textContent = 'Unsaved changes. Save before testing or enabling analytics.';
    }
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
            !Array.isArray(row.warnings) || row.warnings.length > 10 || row.warnings.some(value => typeof value !== 'string' || value.length > 1000)) throw new Error('Invalid model metadata.');
        ids.add(row.id);
        const defaults = row.defaults;
        if (!defaults || !['max_input_tokens', 'max_output_tokens', 'thinking_mode', 'reasoning_effort', 'token_field', 'structured_output'].every(key => {
          const control = form.elements.namedItem(key), value = defaults[key];
          return control.tagName === 'SELECT' ? [...control.options].some(option => option.value === value) :
            Number.isSafeInteger(value) && value >= Number(control.min) && value <= Number(control.max);
        })) throw new Error('Invalid model defaults.');
      }
      return rows;
    }
    function sourceLabel(value) {
      if (typeof value !== 'string' || value === 'unknown') return 'Unknown source';
      if (value.startsWith('provider: ')) return `Provider reported (${value.slice(10)})`;
      if (value === 'reviewed preset') return 'Reviewed recommendation (not provider metadata)';
      if (value === 'provider default') return 'Provider default (no explicit override)';
      if (value.startsWith('local safe budget') || value.startsWith('current config')) return `App recommendation (${value})`;
      return 'Unknown source';
    }
    function describe(row, container) {
      const line = text => { const p = document.createElement('p'); p.className = 'mb-1'; p.textContent = text; container.append(p); };
      line(`Context window: ${row.context_window ?? 'Unknown'} · ${sourceLabel(row.sources.context_window)}. Output capacity: ${row.max_output_tokens ?? 'Unknown'} · ${sourceLabel(row.sources.max_output_tokens)}.`);
      line(`Thinking support: ${row.supports_thinking === null ? 'Unknown' : (row.supports_thinking ? 'Yes' : 'No')} · ${sourceLabel(row.sources.supports_thinking)}.`);
      line(`Recommended app input budget: ${row.defaults.max_input_tokens}; output limit: ${row.defaults.max_output_tokens}. The input allowance is conservative UTF-8 bytes plus framing, not an exact tokenizer or a context guarantee.`);
      for (const key of ['max_input_tokens', 'max_output_tokens', 'thinking_mode', 'reasoning_effort', 'token_field', 'structured_output']) {
        line(`${key}: ${row.defaults[key] === '' ? 'Provider default' : row.defaults[key]} · ${sourceLabel(row.sources['defaults.' + key])}`);
      }
      row.warnings.forEach(warning => line(`Note: ${warning}`));
    }
    function matchesSearch(row, query) {
      return `${row.id}\n${row.name}`.toLowerCase().includes(query.trim().toLowerCase());
    }
    function filterPreview() {
      if (!preview) return;
      const rows = [...previewList.children];
      rows.forEach((element, index) => { element.hidden = !matchesSearch(preview.models[index], previewSearch?.value || ''); });
      const count = root.querySelector('[data-ai-model-preview-count]');
      if (count) count.textContent = `${rows.filter(element => !element.hidden).length} of ${rows.length} shown · ${previewList.querySelectorAll('input:checked').length} selected`;
    }
    function filterCatalog() {
      if (!discoveryReady) return;
      const selected = modelSelect.value;
      modelSelect.replaceChildren(new Option('Choose an imported model (optional)', ''));
      if (matchingCatalog()) for (const row of catalog.models) {
        if (matchesSearch(row, catalogSearch?.value || '')) modelSelect.add(new Option(`${row.name} — ${row.id}`, row.id));
      }
      if ([...modelSelect.options].some(option => option.value === selected)) modelSelect.value = selected;
      const count = root.querySelector('[data-ai-model-catalog-count]');
      if (count) count.textContent = matchingCatalog() ? `${modelSelect.options.length - 1} of ${catalog.models.length} matching. Filtering does not change your active settings.` : '';
    }
    previewSearch?.addEventListener('input', filterPreview, { signal: events.signal });
    catalogSearch?.addEventListener('input', filterCatalog, { signal: events.signal });
    function renderCatalog() {
      if (!discoveryReady) return;
      if (catalogSearch) catalogSearch.value = '';
      filterCatalog();
      details.replaceChildren();
      const matches = matchingCatalog();
      root.querySelector('[data-ai-model-catalog-state]').textContent = !catalog.models.length
        ? 'No imported models yet. Fetch models or enter an exact ID manually.'
        : (!matches ? 'Imported models belong to a different endpoint and cannot be used here. Fetch models for this exact API base.'
          : `${catalog.models.length} imported model(s) available. Choose one explicitly to apply recommendations; importing alone changes no settings.`);
    }
    function applyCatalog(value) {
      if (!value || typeof value.base_url !== 'string' || value.base_url.length > 2048 ||
          (value.base_url !== '' && !canonicalBase(value.base_url))) throw new Error('Invalid catalog endpoint.');
      const rows = validateRows(value.models);
      catalog = { base_url: value.base_url, models: rows };
      const overview = root.querySelector('[data-ai-overview-models]');
      if (overview) overview.textContent = String(rows.length);
      renderCatalog();
    }
    modelSelect?.addEventListener('change', () => {
      if (busy || stopped || !matchingCatalog()) return;
      const row = catalog.models.find(item => item.id === modelSelect.value);
      details.replaceChildren();
      if (!row) return;
      form.elements.namedItem('model').value = row.id;
      for (const key of Object.keys(row.defaults)) {
        if (['max_input_tokens', 'max_output_tokens', 'thinking_mode', 'reasoning_effort', 'token_field', 'structured_output'].includes(key)) form.elements.namedItem(key).value = String(row.defaults[key]);
      }
      describe(row, details); markDirty();
      root.querySelector('[data-ai-model-defaults-note]').textContent = 'Recommended values applied by your selection. They remain editable and are not saved yet. Save, test, then enable when authorized.';
      controls();
    }, { signal: events.signal });
    previewList?.addEventListener('change', () => { filterPreview(); controls(); }, { signal: events.signal });
    async function discover(all, importing) {
      if (busy || stopped || !discoveryReady) return;
      if (importing && !validPreview()) {
        invalidatePreview(); controls(); feedback.textContent = 'Preview expired or the connection changed. Fetch models again.'; return;
      }
      if (importing && !preview.models.length) return;
      const body = new URLSearchParams();
      // Only discovery connection fields and the normal CSRF field; model validity is irrelevant.
      for (const input of form.querySelectorAll('input[type="hidden"]')) if (input.name !== 'version' && input.name !== 'unit_selection') body.set(input.name, input.value);
      if (importing) {
        const ids = all ? [] : [...previewList.querySelectorAll('input:checked')].map(input => input.value);
        if (!all && !ids.length) return;
        body.set('preview_token', preview.preview_token); body.set('model_ids', JSON.stringify(ids)); body.set('import_all', all ? '1' : '0');
      } else {
        if (!form.elements.namedItem('base_url').reportValidity() || !form.elements.namedItem('api_key').reportValidity()) return;
        for (const key of ['version', ...connectionFields]) {
          const control = form.elements.namedItem(key);
          body.set(key, control.type === 'checkbox' ? (control.checked ? '1' : '0') : control.value);
        }
        invalidatePreview();
      }
      const revision = connectionRevision;
      busy = true; discoveryPending = true; controls();
      feedback.textContent = importing ? 'Importing models…' : 'Fetching models using the current connection details…';
      try {
        const result = await request(importing ? root.dataset.modelImportUrl : root.dataset.modelPreviewUrl, body, 45000);
        if (stopped || revision !== connectionRevision) return;
        if (importing) {
          applyCatalog(result.catalog); invalidatePreview();
          feedback.textContent = 'Models imported. Choose an imported model to fill its recommendations. Credentials and active settings have not been saved.';
        } else {
          const value = result.preview;
          const expires = typeof value?.expires_at === 'number' ? value.expires_at * 1000 : Date.parse(value?.expires_at);
          if (!value || typeof value.preview_token !== 'string' || !/^[a-f0-9]{64}$/.test(value.preview_token) ||
              !Number.isFinite(expires) || expires <= Date.now() || typeof value.base_url !== 'string' ||
              !canonicalBase(value.base_url) || canonicalBase(value.base_url) !== canonicalBase(form.elements.namedItem('base_url').value)) throw new Error('Invalid preview.');
          const rows = validateRows(value.models);
          preview = { preview_token: value.preview_token, base_url: value.base_url, models: rows, expires, revision };
          for (const row of rows) {
            const wrapper = document.createElement('div'); wrapper.className = 'mb-3';
            const label = document.createElement('label'); label.className = 'd-flex gap-2 fw-semibold';
            const input = document.createElement('input'); input.type = 'checkbox'; input.value = row.id;
            const title = document.createElement('span'); title.textContent = `${row.name} — ${row.id}`;
            const metadata = document.createElement('details'); metadata.className = 'small mt-1';
            const summary = document.createElement('summary'); summary.textContent = 'Capabilities and recommended defaults';
            metadata.append(summary);
            metadata.addEventListener('toggle', () => {
              if (metadata.open && metadata.childElementCount === 1) describe(row, metadata);
            }, { signal: events.signal });
            label.append(input, title); wrapper.append(label, metadata); previewList.append(wrapper);
          }
          if (previewSearch) previewSearch.value = '';
          filterPreview();
          previewPanel.hidden = false;
          const emptyMessage = 'No models compatible with the minimum app budget were returned. No models can be imported from this preview. Enter a manual exact model ID or check the provider endpoint.';
          root.querySelector('[data-ai-model-preview-state]').textContent = rows.length
            ? `${rows.length} model(s). Preview expires at ${new Date(expires).toLocaleTimeString()}. Select models or import all (up to 200).`
            : emptyMessage;
          feedback.textContent = rows.length ? 'Preview ready. Review the reported capabilities and app recommendations before importing.' : emptyMessage;
        }
      } catch (error) {
        invalidatePreview();
        if (!stopped) feedback.textContent = revision !== connectionRevision ? 'Connection changed. Fetch models again.' :
          (error.confirmed ? error.message : 'Model discovery/import could not be confirmed. No automatic retry was made. Reload saved settings or fetch a fresh preview before retrying.');
      } finally { busy = false; discoveryPending = false; if (!stopped) controls(); }
    }
    root.querySelector('[data-ai-model-fetch]')?.addEventListener('click', () => discover(false, false), { signal: events.signal });
    root.querySelector('[data-ai-model-import-selected]')?.addEventListener('click', () => discover(false, true), { signal: events.signal });
    root.querySelector('[data-ai-model-import-all]')?.addEventListener('click', () => discover(true, true), { signal: events.signal });
    function applyAccess(access) {
      if (!access || typeof access.ready !== 'boolean' || !Array.isArray(access.grants) || access.grants.length > 40) throw new Error('Invalid access response. Reload saved settings.');
      const forms = [...root.querySelectorAll('[data-ai-settings-form="access"]')];
      const key = row => [row.unit, row.role, row.permission].join(':');
      const known = new Set(forms.map(item => key(Object.fromEntries(new FormData(item)))));
      const granted = new Set();
      for (const row of access.grants) {
        if (!row || !['unit', 'role', 'permission'].every(field => typeof row[field] === 'string') ||
            !known.has(key(row)) || granted.has(key(row))) throw new Error('Invalid access response. Reload saved settings.');
        granted.add(key(row));
      }
      accessReady = access.ready;
      for (const item of forms) {
        const row = Object.fromEntries(new FormData(item));
        const operation = granted.has(key(row)) ? 'revoke' : 'grant';
        const label = row.permission.split('.').pop();
        const verb = operation === 'grant' ? 'Grant' : 'Revoke';
        item.elements.namedItem('operation').value = operation;
        const button = item.querySelector('button');
        button.textContent = `${verb} ${label[0].toUpperCase()}${label.slice(1)}`;
        button.classList.toggle('btn-outline-danger', operation === 'revoke');
        button.classList.toggle('btn-outline-primary', operation === 'grant');
        button.setAttribute('aria-label', `${verb} ${label} for ${row.role} in ${row.unit}`);
      }
      root.querySelector('[data-ai-access-state]').textContent = accessReady
        ? 'Access storage ready. Changes take effect on the next authorization check.'
        : 'AI access or audit storage is unavailable. Access changes are disabled; existing permissions are unchanged.';
    }
    function apply(settings) {
      if (!settings || typeof settings.version !== 'string' || typeof settings.base_url !== 'string' || typeof settings.model !== 'string' ||
          !['', 'disabled', 'enabled'].includes(settings.thinking_mode) ||
          !Number.isSafeInteger(settings.max_input_tokens) || !Number.isSafeInteger(settings.max_output_tokens) ||
          ['saved', 'has_api_key', 'connection_tested', 'enabled', 'allow_private_network'].some(key => typeof settings[key] !== 'boolean') ||
          !Array.isArray(settings.approved_units) || settings.approved_units.length > 3 ||
          settings.approved_units.some(unit => !['clinic', 'guidance', 'bmg'].includes(unit)) ||
          Object.hasOwn(settings, 'api_key') || Object.hasOwn(settings, 'storage_key')) throw new Error('The settings response was incomplete. Reload saved settings before continuing.');
      for (const key of ['base_url', 'model', 'reasoning_effort', 'thinking_mode', 'max_input_tokens', 'max_output_tokens', 'auth_mode', 'token_field', 'structured_output']) {
        if (!['string', 'number'].includes(typeof settings[key])) throw new Error('Invalid settings response.');
        form.elements.namedItem(key).value = String(settings[key]);
      }
      form.elements.namedItem('allow_private_network').checked = settings.allow_private_network;
      for (const unit of ['clinic', 'guidance', 'bmg']) form.elements.namedItem(`allow_${unit}`).checked = settings.approved_units.includes(unit);
      form.elements.namedItem('api_key').value = '';
      form.elements.namedItem('remove_api_key').checked = false;
      form.elements.namedItem('api_key').placeholder = settings.has_api_key ? 'Saved securely — leave blank to keep it' : 'Enter the provider API key';
      root.querySelector('[data-ai-key-state]').textContent = settings.has_api_key ? 'A key is saved. It is never sent back to this page.' : 'No key is saved. Choose No authentication only for a trusted server.';
      root.querySelectorAll('input[name="version"]').forEach(input => { input.value = settings.version; });
      saved = settings.saved; tested = settings.connection_tested; enabled = settings.enabled; dirty = false;
      root.querySelector('[data-ai-settings-form="enable"]').hidden = enabled;
      root.querySelector('[data-ai-settings-form="disable"]').hidden = !enabled;
      root.querySelector('[name="confirm_analytics"]').checked = false;
      state.textContent = enabled ? 'Aggregate AI enabled' : (tested ? 'Connection verified · analytics paused' : (saved ? 'Saved · test connection next' : 'Not configured'));
      state.classList.toggle('bg-success', enabled); state.classList.toggle('bg-secondary', !enabled);
      invalidatePreview(); renderCatalog();
    }
    async function request(url, body, timeoutMs) {
      const requestOwner = new AbortController(); owner = requestOwner;
      const timer = setTimeout(() => requestOwner.abort(), timeoutMs);
      try {
        const response = await fetch(endpoint(url, !body), { method: body ? 'POST' : 'GET', body,
          credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: requestOwner.signal,
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(body ? window.SynapseCsrf?.headers() : {}) } });
        if (stopped || requestOwner.signal.aborted) throw new DOMException('Cancelled', 'AbortError');
        if (window.SynapseCsrf?.fromResponse(response)) { form.elements.namedItem('api_key').value = ''; throw new Error('Sign-in navigation is in progress.'); }
        if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Unexpected settings response. Check the saved settings before retrying.');
        const raw = await response.text();
        if (stopped || requestOwner.signal.aborted) throw new DOMException('Cancelled', 'AbortError');
        if (raw.length > 524288) throw new Error('Settings response is too large.');
        const result = JSON.parse(raw);
        window.SynapseCsrf?.sync(result.csrf);
        if (!response.ok || result.success !== true) {
          const error = new Error(typeof result.message === 'string' ? result.message.slice(0, 1500) : 'Settings action failed.');
          error.confirmed = true; throw error;
        }
        return result;
      } finally { clearTimeout(timer); if (owner === requestOwner) owner = null; }
    }
    function edited(event) {
      if (event.target.closest('[data-ai-model-discovery]')) return;
      markDirty();
      if (connectionFields.includes(event.target.name)) {
        connectionRevision++; invalidatePreview();
        if (discoveryPending) owner?.abort();
        renderCatalog();
      }
      if (event.target.name === 'model') { if (modelSelect) modelSelect.value = ''; details?.replaceChildren(); }
      controls();
    }
    form.addEventListener('input', edited, { signal: events.signal });
    form.addEventListener('change', edited, { signal: events.signal });
    root.addEventListener('submit', async event => {
      const submitted = event.target.closest('[data-ai-settings-form]');
      if (!submitted || !root.contains(submitted)) return;
      event.preventDefault();
      const action = submitted.dataset.aiSettingsForm;
      if (busy || stopped || (action === 'access' && !accessReady) || !submitted.reportValidity() || (dirty && ['test', 'enable'].includes(action))) return;
      const body = new URLSearchParams(new FormData(submitted));
      busy = true; controls();
      feedback.textContent = action === 'access' ? 'Updating department AI access…' : (action === 'test' ? 'Testing your saved connection with a harmless message…' : 'Saving AI configuration…');
      try {
        const result = await request(submitted.action, body, action === 'test' ? 135000 : 15000);
        if (action === 'access' || result.access) applyAccess(result.access);
        if (result.settings) apply(result.settings);
        if (result.catalog) applyCatalog(result.catalog);
        feedback.textContent = result.message || 'AI configuration updated.';
        const tone = action === 'test' ? 'success' : (action === 'enable' ? 'success' : (action === 'disable' ? 'warning' : 'info'));
        toast(result.message || 'AI configuration updated.', tone);
      } catch (error) {
        if (!stopped) {
          feedback.textContent = error.confirmed ? error.message : 'The result could not be confirmed. No automatic retry was made. Reload saved settings before submitting again.';
          toast(feedback.textContent, 'error');
        }
      } finally { busy = false; if (!stopped) controls(); }
    }, { signal: events.signal });
    // Explicit recovery only: never resubmit a possibly completed mutation.
    const reload = document.createElement('button'); reload.type = 'button'; reload.className = 'btn btn-link btn-sm px-0 mt-2'; reload.innerHTML = '<i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Reload saved settings';
    root.append(reload);
    // Section rail: highlight the section currently in view; plain anchors keep deep links working.
    const rail = [...root.querySelectorAll('.settings-nav-item')];
    if (rail.length && 'IntersectionObserver' in window) {
      const sections = rail.map(link => root.querySelector(link.getAttribute('href'))).filter(Boolean);
      const observer = new IntersectionObserver(entries => {
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          rail.forEach(link => link.classList.toggle('active', link.getAttribute('href') === `#${entry.target.id}`));
        }
      }, { rootMargin: '-20% 0px -70% 0px' });
      sections.forEach(section => observer.observe(section));
      events.signal.addEventListener('abort', () => observer.disconnect(), { once: true });
    }
    reload.addEventListener('click', async () => {
      if (busy || (dirty && !window.confirm('Discard unsaved settings and reload the saved configuration?'))) return;
      busy = true; controls();
      try { const result = await request(root.dataset.statusUrl, null, 15000); apply(result.settings); if (result.access) applyAccess(result.access); if (result.catalog) applyCatalog(result.catalog); feedback.textContent = 'Saved settings loaded. The API key remains hidden.'; }
      catch (error) { if (!stopped) feedback.textContent = error.message || 'Saved settings could not be loaded.'; }
      finally { busy = false; if (!stopped) controls(); }
    }, { signal: events.signal });
    try {
      const raw = root.querySelector('[data-ai-model-catalog]')?.dataset.catalog ?? '{"base_url":"","models":[]}';
      if (raw.length > 524288) throw new Error('Catalog too large.');
      applyCatalog(JSON.parse(raw));
    } catch (_) {
      const catalogState = root.querySelector('[data-ai-model-catalog-state]');
      if (catalogState) catalogState.textContent = 'Catalog unavailable. Fetch models again or enter an exact ID manually.';
    }
    // Reflect server-rendered readiness immediately instead of leaving a "Checking…" placeholder.
    const accessState = root.querySelector('[data-ai-access-state]');
    if (accessState) {
      accessState.textContent = accessReady
        ? 'Access storage ready. Changes take effect on the next authorization check.'
        : 'AI access or audit storage is unavailable. Access changes are disabled; existing permissions are unchanged.';
    }
    controls();
    return () => { stopped = true; invalidatePreview(); owner?.abort(); form.elements.namedItem('api_key').value = ''; events.abort(); };
  }
  function init(scope = document) {
    for (const [root, destroy] of instances) if (!root.isConnected) { destroy(); instances.delete(root); }
    const roots = [...scope.querySelectorAll('[data-ai-settings]')];
    if (scope.matches?.('[data-ai-settings]')) roots.unshift(scope);
    for (const root of roots) if (!instances.has(root)) instances.set(root, mount(root));
  }
  window.SynapseAiSettings = { init };
  document.addEventListener('synapse:page-load', event => init(event.detail?.root || document));
  document.addEventListener('synapse:before-swap', event => {
    for (const [root, destroy] of instances) if (!event.detail?.root || event.detail.root === root || event.detail.root.contains(root)) { destroy(); instances.delete(root); }
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true }); else init();
})();

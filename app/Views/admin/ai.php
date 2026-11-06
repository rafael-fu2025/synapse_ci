<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$access = $access ?? ['ready' => false, 'grants' => []];
$s = $settings;
$catalog = $catalog ?? ['base_url' => '', 'models' => [], 'imported_at' => null];
$accessReady = !empty($access['ready']);
$grantCount = count($access['grants'] ?? []);
$modelCount = count($catalog['models'] ?? []);
$state = $s['enabled'] ? 'Aggregate AI enabled' : ($s['connection_tested'] ? 'Connection verified · analytics paused' : ($s['saved'] ? 'Saved · test connection next' : 'Not configured'));
$currentStep = !$s['saved'] ? 'connection' : (($accessReady && $grantCount > 0) ? 'activate' : 'access');
if ($s['enabled']) {
    $currentStep = 'activate';
}
$connectionStep = $s['saved'] ? 'Saved' : 'Set up provider';
$accessStep = !$accessReady ? 'Storage unavailable' : ($grantCount . ' active ' . ($grantCount === 1 ? 'grant' : 'grants'));
$activateStep = $s['enabled'] ? 'Enabled' : ($s['connection_tested'] ? 'Ready to enable' : ($s['saved'] ? 'Test required' : 'Save first'));
$publicSettings = $s;
unset($publicSettings['api_key'], $publicSettings['storage_key']);
$aiUnits = ['clinic' => 'Clinic', 'guidance' => 'Guidance', 'bmg' => 'Facilities (BMG)'];
$aiRoles = [
    'clinic' => ['clinic_admin' => 'Clinic admin', 'clinic_staff' => 'Clinic staff', 'superadmin' => 'Superadmin'],
    'guidance' => ['guidance_admin' => 'Guidance admin', 'guidance_supervisor' => 'Guidance supervisor', 'counsellor' => 'Counsellor', 'superadmin' => 'Superadmin'],
    'bmg' => ['bmg_admin' => 'BMG admin', 'facilities_op' => 'Facilities operator', 'superadmin' => 'Superadmin'],
];
$aiPermissions = [
    'ai.insights.read' => ['label' => 'Read', 'title' => 'Read insights', 'description' => 'View approved aggregate insight results.'],
    'ai.insights.generate' => ['label' => 'Generate', 'title' => 'Generate insights', 'description' => 'Request a new aggregate analysis.'],
    'ai.insights.review' => ['label' => 'Review', 'title' => 'Review insights', 'description' => 'Review generated insight drafts.'],
    'ai.insights.approve' => ['label' => 'Approve', 'title' => 'Approve insights', 'description' => 'Approve reviewed insights for use.'],
];
$aiGranted = [];
$unitGrantCounts = array_fill_keys(array_keys($aiUnits), 0);
foreach ($access['grants'] ?? [] as $grant) {
    $key = ($grant['unit'] ?? '') . ':' . ($grant['role'] ?? '') . ':' . ($grant['permission'] ?? '');
    $aiGranted[$key] = true;
    if (isset($unitGrantCounts[$grant['unit'] ?? ''])) {
        ++$unitGrantCounts[$grant['unit']];
    }
}
?>
<section class="ai-settings-page" data-ai-settings
  data-status-url="<?= esc(site_url('admin/ai/status'), 'attr') ?>"
  data-model-preview-url="<?= esc(site_url('admin/ai/models/preview'), 'attr') ?>"
  data-model-import-url="<?= esc(site_url('admin/ai/models/import'), 'attr') ?>"
  aria-label="AI provider settings">
  <template data-ai-settings-data data-settings="<?= esc(json_encode($publicSettings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), 'attr') ?>"></template>
  <template data-ai-access-data data-access="<?= esc(json_encode($access, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), 'attr') ?>"></template>
  <template data-ai-model-catalog data-catalog="<?= esc(json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), 'attr') ?>"></template>

  <div class="ai-settings-intro mb-3">
    <div class="ai-settings-intro-copy">
      <p class="settings-page-subtitle mb-1">Connect an OpenAI-compatible provider, assign scoped access, then activate protected aggregate analytics.</p>
      <p data-ai-settings-feedback class="small mb-0" role="status" aria-live="polite"></p>
    </div>
    <div class="ai-settings-intro-actions">
      <span class="badge <?= $s['enabled'] ? 'bg-success' : 'bg-secondary' ?> p-2" data-ai-settings-state><?= esc($state) ?></span>
      <button class="btn btn-outline-secondary btn-sm" type="button" data-ai-settings-reload>
        <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Reload saved settings
      </button>
    </div>
  </div>

  <section class="card ai-settings-progress-card mb-3" aria-labelledby="ai-setup-summary-title">
    <div class="card-body">
      <div class="ai-settings-progress-heading">
        <div>
          <p class="ai-settings-eyebrow mb-1">Progressive setup</p>
          <h2 class="h6 mb-0" id="ai-setup-summary-title">Setup summary</h2>
        </div>
        <span class="small text-muted">All three steps remain available below.</span>
      </div>

      <nav class="ai-settings-step-nav" aria-label="AI setup steps">
        <ol class="ai-settings-step-list">
          <li>
            <a class="ai-settings-step-link" href="#ai-section-connection" data-ai-step-link data-ai-progress-step="connection" data-state="<?= $s['saved'] ? 'complete' : 'current' ?>"<?= $currentStep === 'connection' ? ' aria-current="step"' : '' ?>>
              <span class="ai-settings-step-number" aria-hidden="true">1</span>
              <span class="ai-settings-step-copy"><strong>Connection</strong><span data-ai-progress-step-state><?= esc($connectionStep) ?></span></span>
            </a>
          </li>
          <li>
            <a class="ai-settings-step-link" href="#ai-section-access" data-ai-step-link data-ai-progress-step="access" data-state="<?= !$accessReady ? 'attention' : ($grantCount > 0 ? 'complete' : 'current') ?>"<?= $currentStep === 'access' ? ' aria-current="step"' : '' ?>>
              <span class="ai-settings-step-number" aria-hidden="true">2</span>
              <span class="ai-settings-step-copy"><strong>Access</strong><span data-ai-progress-step-state><?= esc($accessStep) ?></span></span>
            </a>
          </li>
          <li>
            <a class="ai-settings-step-link" href="#ai-section-activation" data-ai-step-link data-ai-progress-step="activate" data-state="<?= $s['enabled'] ? 'complete' : ($s['connection_tested'] ? 'ready' : 'pending') ?>"<?= $currentStep === 'activate' ? ' aria-current="step"' : '' ?>>
              <span class="ai-settings-step-number" aria-hidden="true">3</span>
              <span class="ai-settings-step-copy"><strong>Activate</strong><span data-ai-progress-step-state><?= esc($activateStep) ?></span></span>
            </a>
          </li>
        </ol>
      </nav>

      <dl class="ai-settings-checklist mb-0" data-ai-progress>
        <div class="ai-settings-check-item" data-ai-progress-item="endpoint" data-state="<?= $s['base_url'] !== '' ? 'complete' : 'pending' ?>">
          <dt><i class="bi bi-hdd-network" aria-hidden="true"></i>Endpoint</dt>
          <dd data-ai-progress-endpoint title="<?= esc($s['base_url'], 'attr') ?>"><?= esc($s['base_url'] !== '' ? $s['base_url'] : 'Not configured') ?></dd>
        </div>
        <div class="ai-settings-check-item" data-ai-progress-item="model" data-state="<?= $s['model'] !== '' ? 'complete' : 'pending' ?>">
          <dt><i class="bi bi-cpu" aria-hidden="true"></i>Model</dt>
          <dd data-ai-progress-model title="<?= esc($s['model'], 'attr') ?>"><?= esc($s['model'] !== '' ? $s['model'] : 'Not selected') ?></dd>
        </div>
        <div class="ai-settings-check-item" data-ai-progress-item="key" data-state="<?= $s['has_api_key'] || $s['auth_mode'] === 'none' ? 'complete' : 'pending' ?>">
          <dt><i class="bi bi-key" aria-hidden="true"></i>API key</dt>
          <dd data-ai-progress-key><?= $s['has_api_key'] ? 'Saved securely' : ($s['auth_mode'] === 'none' ? 'Not required' : 'Not saved') ?></dd>
        </div>
        <div class="ai-settings-check-item" data-ai-progress-item="imports" data-state="<?= $modelCount > 0 ? 'complete' : 'optional' ?>">
          <dt><i class="bi bi-collection" aria-hidden="true"></i>Imported models</dt>
          <dd data-ai-progress-imports data-ai-overview-models><?= esc((string) $modelCount) ?> imported</dd>
        </div>
        <div class="ai-settings-check-item" data-ai-progress-item="test" data-state="<?= $s['connection_tested'] ? 'complete' : 'pending' ?>">
          <dt><i class="bi bi-plug" aria-hidden="true"></i>Connection test</dt>
          <dd data-ai-progress-test><?= $s['connection_tested'] ? 'Passed' : ($s['saved'] ? 'Required' : 'Save first') ?></dd>
        </div>
        <div class="ai-settings-check-item" data-ai-progress-item="analytics" data-state="<?= $s['enabled'] ? 'complete' : 'paused' ?>">
          <dt><i class="bi bi-activity" aria-hidden="true"></i>Analytics</dt>
          <dd data-ai-progress-analytics><?= $s['enabled'] ? 'Enabled' : 'Paused' ?></dd>
        </div>
        <div class="ai-settings-check-item" data-ai-progress-item="access" data-state="<?= $accessReady ? 'complete' : 'attention' ?>">
          <dt><i class="bi bi-shield-check" aria-hidden="true"></i>Access storage</dt>
          <dd data-ai-progress-access><?= $accessReady ? 'Ready' : 'Unavailable' ?></dd>
        </div>
        <div class="ai-settings-check-item" data-ai-progress-item="grants" data-state="<?= $grantCount > 0 ? 'complete' : 'pending' ?>">
          <dt><i class="bi bi-people" aria-hidden="true"></i>Active grants</dt>
          <dd data-ai-progress-grants><?= esc((string) $grantCount) ?> active</dd>
        </div>
      </dl>
    </div>
  </section>

  <div class="ai-settings-flow">
    <section class="card ai-settings-section" id="ai-section-connection" data-ai-step="connection" aria-labelledby="ai-connection-title">
      <div class="card-header ai-settings-section-header">
        <div class="ai-settings-section-title">
          <span class="ai-settings-section-number" aria-hidden="true">1</span>
          <div><p class="ai-settings-eyebrow mb-1">Connection</p><h2 class="h6 mb-0" id="ai-connection-title">Provider connection</h2></div>
        </div>
        <span class="small text-muted">Save before testing</span>
      </div>
      <div class="card-body">
        <p class="small text-muted ai-settings-section-lead">Set the endpoint, credential, exact model ID, and departments this provider may process. Discovery and testing never send patient records.</p>
        <form id="ai-settings-provider-form" method="post" action="<?= esc(site_url('admin/ai/save'), 'attr') ?>" data-ai-settings-form="save" autocomplete="off">
          <?= csrf_field() ?><input type="hidden" name="version" value="<?= esc($s['version'], 'attr') ?>">
          <div class="row g-3 ai-settings-connection-fields">
            <div class="col-12">
              <label class="form-label" for="ai-base-url">Base URL / API endpoint</label>
              <input class="form-control" id="ai-base-url" name="base_url" type="url" required maxlength="2048" placeholder="https://your-provider.example/v1" value="<?= esc($s['base_url'], 'attr') ?>" aria-describedby="ai-base-help">
              <div id="ai-base-help" class="form-text">Use the API base; SYNAPSE appends <code>/chat/completions</code> and keeps gateway prefixes.</div>
            </div>
            <div class="col-lg-6">
              <label class="form-label" for="ai-api-key">API key</label>
              <input class="form-control" id="ai-api-key" name="api_key" type="password" maxlength="4096" autocomplete="new-password" spellcheck="false" placeholder="<?= $s['has_api_key'] ? 'Saved securely — leave blank to keep it' : 'Enter the provider API key' ?>" aria-describedby="ai-key-help">
              <div id="ai-key-help" class="form-text" data-ai-key-state><?= $s['has_api_key'] ? 'A key is saved. It is never sent back to this page.' : 'No key is saved. No-auth endpoints must be selected under Advanced.' ?></div>
            </div>
            <div class="col-lg-6">
              <label class="form-label" for="ai-model">Model ID</label>
              <input class="form-control" id="ai-model" name="model" type="text" required maxlength="191" spellcheck="false" placeholder="Exact model ID from your provider" value="<?= esc($s['model'], 'attr') ?>" aria-describedby="ai-model-help">
              <div id="ai-model-help" class="form-text">Enter an exact ID manually or apply a recommendation from the model drawer.</div>
            </div>
            <div class="col-12">
              <div class="ai-settings-model-launch">
                <div>
                  <strong>Model discovery</strong>
                  <span><span data-ai-model-launch-count><?= esc((string) $modelCount) ?></span> imported for the saved catalog. Optional; the manual ID above always works.</span>
                </div>
                <button class="btn btn-outline-primary btn-sm" type="button" data-ai-model-drawer-open aria-controls="ai-settings-model-drawer" aria-expanded="false">
                  <i class="bi bi-collection me-1" aria-hidden="true"></i>Manage models
                </button>
              </div>
              <noscript><p class="alert alert-info mt-2 mb-0">Model discovery requires JavaScript. Enter an exact model ID in the Model ID field above, then save and test the connection.</p></noscript>
            </div>
          </div>

          <fieldset class="ai-settings-departments mt-3">
            <legend class="h6">Approved departments</legend>
            <input type="hidden" name="unit_selection" value="1">
            <p class="form-text mt-0">Select at least one department. This authorizes provider processing only; it does not grant staff access.</p>
            <div class="ai-settings-department-options">
              <?php foreach ($aiUnits as $unit => $label): ?>
                <div class="form-check">
                  <input class="form-check-input" id="ai-allow-<?= esc($unit, 'attr') ?>" name="allow_<?= esc($unit, 'attr') ?>" type="checkbox" value="1"<?= in_array($unit, $s['approved_units'] ?? ['clinic'], true) ? ' checked' : '' ?>>
                  <label class="form-check-label" for="ai-allow-<?= esc($unit, 'attr') ?>"><?= esc($label) ?></label>
                </div>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <details class="ai-settings-advanced mt-3">
            <summary>Advanced connection options</summary>
            <div class="row g-3 mt-1">
              <div class="col-md-4">
                <label class="form-label" for="ai-reasoning">Reasoning effort</label>
                <select class="form-select" id="ai-reasoning" name="reasoning_effort">
                  <?php foreach (['' => 'Provider default (recommended)', 'none' => 'None', 'minimal' => 'Minimal', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'xhigh' => 'Extra high'] as $value => $label): ?>
                    <option value="<?= esc($value, 'attr') ?>"<?= $s['reasoning_effort'] === $value ? ' selected' : '' ?>><?= esc($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="ai-thinking">Thinking mode</label>
                <select class="form-select" id="ai-thinking" name="thinking_mode" aria-describedby="ai-thinking-help">
                  <?php foreach (['' => 'Provider default', 'disabled' => 'Disabled', 'enabled' => 'Enabled'] as $value => $label): ?>
                    <option value="<?= esc($value, 'attr') ?>"<?= ($s['thinking_mode'] ?? '') === $value ? ' selected' : '' ?>><?= esc($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <div id="ai-thinking-help" class="form-text">Independent of reasoning effort; use only values supported by the model.</div>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="ai-auth-mode">Authentication</label>
                <select class="form-select" id="ai-auth-mode" name="auth_mode">
                  <?php foreach (['bearer' => 'Bearer API key (standard)', 'api_key_header' => 'X-API-Key header', 'none' => 'No authentication (trusted local server)'] as $value => $label): ?>
                    <option value="<?= esc($value, 'attr') ?>"<?= $s['auth_mode'] === $value ? ' selected' : '' ?>><?= esc($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="ai-input-tokens">Input token budget</label>
                <input class="form-control" id="ai-input-tokens" name="max_input_tokens" type="number" min="512" max="131072" step="1" required value="<?= (int) $s['max_input_tokens'] ?>">
                <div class="form-text">Conservative local UTF-8 allowance; oversized input is rejected, never truncated.</div>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="ai-output-tokens">Maximum output tokens</label>
                <input class="form-control" id="ai-output-tokens" name="max_output_tokens" type="number" min="1" max="32768" step="1" required value="<?= (int) $s['max_output_tokens'] ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label" for="ai-token-field">Provider output-limit field</label>
                <select class="form-select" id="ai-token-field" name="token_field">
                  <option value="max_tokens"<?= $s['token_field'] === 'max_tokens' ? ' selected' : '' ?>>max_tokens</option>
                  <option value="max_completion_tokens"<?= $s['token_field'] === 'max_completion_tokens' ? ' selected' : '' ?>>max_completion_tokens</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="ai-structured">Structured response</label>
                <select class="form-select" id="ai-structured" name="structured_output">
                  <option value="none"<?= $s['structured_output'] === 'none' ? ' selected' : '' ?>>Standard (locally validated JSON)</option>
                  <option value="json_object"<?= $s['structured_output'] === 'json_object' ? ' selected' : '' ?>>Provider JSON object mode</option>
                </select>
              </div>
              <div class="col-md-6">
                <div class="form-check mt-md-4">
                  <input class="form-check-input" id="ai-private-network" name="allow_private_network" type="checkbox" value="1"<?= $s['allow_private_network'] ? ' checked' : '' ?>>
                  <label class="form-check-label" for="ai-private-network">Allow this exact trusted local/private host and port.</label>
                  <div class="form-text">Metadata/link-local destinations and redirects remain blocked.</div>
                </div>
              </div>
              <div class="col-12">
                <div class="form-check">
                  <input class="form-check-input" id="ai-remove-key" name="remove_api_key" type="checkbox" value="1">
                  <label class="form-check-label" for="ai-remove-key">Remove the saved API key (required when switching to no authentication).</label>
                </div>
              </div>
            </div>
          </details>

          <div class="ai-settings-form-actions mt-3">
            <button class="btn btn-primary" type="submit">Save connection</button>
            <span class="small text-muted">Saving pauses analytics and requires a new connection test.</span>
          </div>
        </form>
      </div>
    </section>

    <div class="offcanvas offcanvas-end ai-settings-model-drawer" tabindex="-1" id="ai-settings-model-drawer"
      data-ai-model-drawer data-ai-model-discovery aria-labelledby="ai-settings-model-drawer-title" aria-describedby="ai-settings-model-drawer-description">
      <div class="offcanvas-header ai-settings-drawer-header">
        <div>
          <p class="ai-settings-eyebrow mb-1">Optional model catalog</p>
          <h2 class="offcanvas-title h5" id="ai-settings-model-drawer-title">Discover and import models</h2>
        </div>
        <button type="button" class="btn-close" data-ai-model-drawer-close aria-label="Close model drawer"></button>
      </div>
      <div class="offcanvas-body ai-settings-drawer-body">
        <p class="small text-muted" id="ai-settings-model-drawer-description">Fetch explicitly using the connection fields in step 1, review provider metadata, then import. Credentials are never copied into this drawer or browser storage.</p>

        <section class="ai-settings-catalog-panel" aria-labelledby="ai-settings-catalog-title">
          <div class="ai-settings-drawer-section-heading">
            <h3 class="h6 mb-0" id="ai-settings-catalog-title">Imported catalog</h3>
            <span class="badge text-bg-light" data-ai-model-catalog-badge><?= esc((string) $modelCount) ?> models</span>
          </div>
          <label class="form-label" for="ai-model-catalog-search">Search imported models</label>
          <input class="form-control form-control-sm mb-2" id="ai-model-catalog-search" type="search" maxlength="191" data-ai-model-catalog-search placeholder="Search model ID or name">
          <label class="form-label" for="ai-imported-model">Apply a model recommendation</label>
          <select class="form-select" id="ai-imported-model" form="ai-settings-provider-form" data-ai-model-select aria-describedby="ai-model-catalog-state ai-model-defaults-note">
            <option value="">Choose an imported model (optional)</option>
          </select>
          <p class="form-text mb-1" data-ai-model-catalog-count role="status"></p>
          <p class="form-text" id="ai-model-catalog-state" data-ai-model-catalog-state></p>
          <div class="small ai-settings-model-details" data-ai-model-details></div>
          <p class="form-text mb-0" id="ai-model-defaults-note" data-ai-model-defaults-note>Choosing a model fills the manual ID and recommended app options. Nothing changes until you save.</p>
        </section>

        <section class="ai-settings-preview-panel mt-4" data-ai-model-preview hidden aria-labelledby="ai-settings-preview-title">
          <div class="ai-settings-drawer-section-heading">
            <h3 class="h6 mb-0" id="ai-settings-preview-title">Available to import</h3>
            <span class="small text-muted">Preview only</span>
          </div>
          <p class="small mb-2" data-ai-model-preview-state></p>
          <label class="form-label" for="ai-model-preview-search">Search fetched models</label>
          <input class="form-control form-control-sm mb-2" id="ai-model-preview-search" type="search" maxlength="191" data-ai-model-preview-search placeholder="Search model ID or name">
          <p class="form-text" data-ai-model-preview-count role="status"></p>
          <div data-ai-model-preview-list class="ai-settings-model-list"></div>
        </section>
      </div>
      <div class="ai-settings-drawer-actions">
        <button class="btn btn-primary btn-sm" type="button" data-ai-model-fetch><i class="bi bi-cloud-download me-1" aria-hidden="true"></i>Fetch models</button>
        <button class="btn btn-outline-primary btn-sm" type="button" data-ai-model-import-selected disabled>Import selected</button>
        <button class="btn btn-outline-primary btn-sm" type="button" data-ai-model-import-all disabled>Import all</button>
        <p class="small text-muted mb-0">Selected adds to the catalog. Import all replaces it with the full preview, including filtered-out rows.</p>
      </div>
    </div>

    <section class="card ai-settings-section" id="ai-section-access" data-ai-step="access" aria-labelledby="ai-access-title">
      <div class="card-header ai-settings-section-header">
        <div class="ai-settings-section-title">
          <span class="ai-settings-section-number" aria-hidden="true">2</span>
          <div><p class="ai-settings-eyebrow mb-1">Access</p><h2 class="h6 mb-0" id="ai-access-title">Department AI access</h2></div>
        </div>
        <span class="small text-muted" data-ai-access-state><?= $accessReady ? 'Access storage ready. Changes apply on the next authorization check.' : 'AI access or audit storage is unavailable. Changes are disabled.' ?></span>
      </div>
      <div class="card-body">
        <p class="small text-muted ai-settings-section-lead">Grant each role only the AI actions it needs. These grants never provide source-data authority.</p>
        <div class="accordion ai-settings-access-accordion" id="ai-access-accordion">
          <?php $index = 0; foreach ($aiUnits as $unit => $unitLabel): ++$index; ?>
            <div class="accordion-item">
              <h3 class="accordion-header" id="ai-access-heading-<?= esc($unit, 'attr') ?>">
                <button class="accordion-button<?= $index === 1 ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#ai-access-<?= esc($unit, 'attr') ?>" aria-expanded="<?= $index === 1 ? 'true' : 'false' ?>" aria-controls="ai-access-<?= esc($unit, 'attr') ?>">
                  <span><?= esc($unitLabel) ?></span>
                  <span class="ai-settings-unit-count" data-ai-unit-grant-count="<?= esc($unit, 'attr') ?>"><?= esc((string) $unitGrantCounts[$unit]) ?> active</span>
                </button>
              </h3>
              <div id="ai-access-<?= esc($unit, 'attr') ?>" class="accordion-collapse collapse<?= $index === 1 ? ' show' : '' ?>" aria-labelledby="ai-access-heading-<?= esc($unit, 'attr') ?>" data-bs-parent="#ai-access-accordion">
                <div class="accordion-body">
                  <div class="ai-settings-role-grid">
                    <?php foreach ($aiRoles[$unit] as $role => $roleLabel): ?>
                      <fieldset class="ai-settings-role-fieldset" data-ai-access-role="<?= esc($role, 'attr') ?>">
                        <legend><?= esc($roleLabel) ?></legend>
                        <div class="ai-settings-permission-list">
                          <?php foreach ($aiPermissions as $permission => $permissionMeta):
                              $permissionName = substr($permission, strrpos($permission, '.') + 1);
                              $granted = isset($aiGranted[$unit . ':' . $role . ':' . $permission]);
                              $verb = $granted ? 'Revoke' : 'Grant';
                              $sourceRestricted = $role === 'counsellor' && $permission === 'ai.insights.generate';
                              $sourceBlocked = $sourceRestricted && !$granted;
                              $rowId = 'ai-access-' . $unit . '-' . $role . '-' . $permissionName;
                          ?>
                            <div class="ai-settings-permission-row" data-ai-permission-row data-state="<?= $granted ? 'granted' : ($sourceBlocked ? 'unavailable' : 'not-granted') ?>">
                              <div class="ai-settings-permission-copy">
                                <span class="ai-settings-permission-title" id="<?= esc($rowId, 'attr') ?>-label"><?= esc($permissionMeta['title']) ?></span>
                                <span class="ai-settings-permission-description"><?= esc($permissionMeta['description']) ?></span>
                                <span class="ai-settings-permission-state" id="<?= esc($rowId, 'attr') ?>-state" data-ai-permission-state><?= $granted ? 'Granted' : ($sourceBlocked ? 'Unavailable' : 'Not granted') ?></span>
                                <?php if ($sourceRestricted): ?>
                                  <span class="ai-settings-source-reason" id="<?= esc($rowId, 'attr') ?>-source" data-ai-source-reason><?= $granted
                                      ? 'Counsellors lack guidance.cases.read_any source access. This existing grant cannot enable generation and remains revocable.'
                                      : 'Unavailable: counsellors lack guidance.cases.read_any source access. An AI grant cannot supply it.' ?></span>
                                <?php endif; ?>
                              </div>
                              <form method="post" action="<?= esc(site_url('admin/ai/access'), 'attr') ?>" data-ai-settings-form="access" data-navigation-dirty="false"
                                data-ai-unit-label="<?= esc($unitLabel, 'attr') ?>" data-ai-role-label="<?= esc($roleLabel, 'attr') ?>"
                                data-ai-permission-label="<?= esc($permissionMeta['label'], 'attr') ?>" data-ai-source-restricted="<?= $sourceRestricted ? 'true' : 'false' ?>"
                                data-ai-source-blocked="<?= $sourceBlocked ? 'true' : 'false' ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="operation" value="<?= $granted ? 'revoke' : 'grant' ?>">
                                <input type="hidden" name="unit" value="<?= esc($unit, 'attr') ?>">
                                <input type="hidden" name="role" value="<?= esc($role, 'attr') ?>">
                                <input type="hidden" name="permission" value="<?= esc($permission, 'attr') ?>">
                                <button class="btn <?= $granted ? 'btn-outline-danger' : 'btn-outline-primary' ?> btn-sm" type="submit"
                                  <?= !$accessReady || $sourceBlocked ? 'disabled ' : '' ?>aria-label="<?= $verb ?> <?= esc($permissionMeta['label'], 'attr') ?> for <?= esc($roleLabel, 'attr') ?> in <?= esc($unitLabel, 'attr') ?>"
                                  aria-describedby="<?= esc($rowId, 'attr') ?>-state<?= $sourceRestricted ? ' ' . esc($rowId, 'attr') . '-source' : '' ?>"><?= $verb ?> <?= esc($permissionMeta['label']) ?></button>
                              </form>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </fieldset>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="card ai-settings-section" id="ai-section-activation" data-ai-step="activate" aria-labelledby="ai-activation-title">
      <div class="card-header ai-settings-section-header">
        <div class="ai-settings-section-title">
          <span class="ai-settings-section-number" aria-hidden="true">3</span>
          <div><p class="ai-settings-eyebrow mb-1">Activate</p><h2 class="h6 mb-0" id="ai-activation-title">Test and activate analytics</h2></div>
        </div>
        <span class="small text-muted">Explicit test and consent required</span>
      </div>
      <div class="card-body">
        <div class="ai-settings-activation-grid">
          <div class="ai-settings-activation-panel">
            <h3 class="h6">Test connection</h3>
            <p class="small">Tests the saved endpoint, key, model, and options with one fixed harmless message. Your provider may charge for it.</p>
            <form method="post" action="<?= esc(site_url('admin/ai/test'), 'attr') ?>" data-ai-settings-form="test" data-navigation-dirty="false">
              <?= csrf_field() ?><input type="hidden" name="version" value="<?= esc($s['version'], 'attr') ?>">
              <button class="btn btn-outline-primary" type="submit" data-ai-settings-test<?= !$s['saved'] ? ' disabled' : '' ?>><?= $s['enabled'] ? 'Pause analytics and test connection' : 'Test connection' ?></button>
              <p class="form-text mb-0">Testing pauses analytics before the probe. It stays paused until you enable it again.</p>
            </form>
          </div>
          <div class="ai-settings-activation-panel">
            <h3 class="h6">Protected aggregate analytics</h3>
            <p class="small">Enable only for an authorized provider and approved operational templates. Patient charts, identifiers, narratives, and counseling notes remain excluded.</p>
            <form method="post" action="<?= esc(site_url('admin/ai/enable'), 'attr') ?>" data-ai-settings-form="enable" data-navigation-dirty="false"<?= $s['enabled'] ? ' hidden' : '' ?>>
              <?= csrf_field() ?><input type="hidden" name="version" value="<?= esc($s['version'], 'attr') ?>">
              <div class="form-check mb-3">
                <input class="form-check-input" id="ai-confirm-analytics" name="confirm_analytics" type="checkbox" value="1" required>
                <label class="form-check-label" for="ai-confirm-analytics">I confirm this provider may process protected aggregate departmental analytics and the operational templates are approved.</label>
              </div>
              <button class="btn btn-success" type="submit" data-ai-settings-enable<?= !$s['connection_tested'] ? ' disabled' : '' ?>>Enable aggregate AI</button>
            </form>
            <form method="post" action="<?= esc(site_url('admin/ai/disable'), 'attr') ?>" data-ai-settings-form="disable" data-navigation-dirty="false"<?= !$s['enabled'] ? ' hidden' : '' ?>>
              <?= csrf_field() ?><input type="hidden" name="version" value="<?= esc($s['version'], 'attr') ?>">
              <button class="btn btn-outline-warning" type="submit">Pause aggregate AI</button>
            </form>
          </div>
        </div>
        <div class="ai-settings-privacy-note mt-3">
          <i class="bi bi-shield-lock" aria-hidden="true"></i>
          <p class="mb-0"><strong>Disclosure boundary:</strong> connection setup sends no patient charts, identifiers, complaint narratives, or counseling notes. Only disclosure-validated departmental aggregates may leave the server after activation.</p>
        </div>
      </div>
    </section>
  </div>
</section>
<script src="<?= esc(base_url('assets/js/ai-settings.js'), 'attr') ?>"></script>
<?= $this->endSection() ?>

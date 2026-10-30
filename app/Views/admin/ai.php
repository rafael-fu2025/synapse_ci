<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$access = $access ?? ['ready' => false, 'grants' => []];
$s = $settings;
$catalog = $catalog ?? ['base_url' => '', 'models' => [], 'imported_at' => null];
$state = $s['enabled'] ? 'Aggregate AI enabled' : ($s['connection_tested'] ? 'Connection verified · analytics paused' : ($s['saved'] ? 'Saved · test connection next' : 'Not configured'));
$modelCount = count($catalog['models'] ?? []);
?>
<section data-ai-settings data-status-url="<?= esc(site_url('admin/ai/status'), 'attr') ?>" data-model-preview-url="<?= esc(site_url('admin/ai/models/preview'), 'attr') ?>" data-model-import-url="<?= esc(site_url('admin/ai/models/import'), 'attr') ?>" aria-label="AI provider settings">
  <template data-ai-model-catalog data-catalog="<?= esc(json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), 'attr') ?>"></template>
  <div class="page-settings">
    <div class="ss-top mb-3">
      <div>
        <p class="settings-page-subtitle mb-1">Connect an OpenAI-compatible provider or a trusted self-hosted endpoint.</p>
        <p data-ai-settings-feedback class="small mb-0" role="status" aria-live="polite"></p>
      </div>
      <div class="page-header-actions d-flex align-items-center gap-2">
        <span class="badge <?= $s['enabled'] ? 'bg-success' : 'bg-secondary' ?> p-2" data-ai-settings-state><?= esc($state) ?></span>
      </div>
    </div>

    <div class="settings-overview mb-3">
      <div class="settings-overview-item">
        <span class="settings-overview-label">Endpoint</span>
        <span class="settings-overview-value text-truncate d-block" title="<?= esc($s['base_url'], 'attr') ?>"><?= esc($s['base_url'] !== '' ? $s['base_url'] : 'Not configured') ?></span>
        <span class="settings-overview-icon" aria-hidden="true"><i class="bi bi-hdd-network"></i></span>
      </div>
      <div class="settings-overview-item">
        <span class="settings-overview-label">Active model</span>
        <span class="settings-overview-value text-truncate d-block" title="<?= esc($s['model'], 'attr') ?>"><?= esc($s['model'] !== '' ? $s['model'] : 'None selected') ?></span>
        <span class="settings-overview-icon" aria-hidden="true"><i class="bi bi-cpu"></i></span>
      </div>
      <div class="settings-overview-item">
        <span class="settings-overview-label">Imported models</span>
        <span class="settings-overview-value" data-ai-overview-models><?= esc((string) $modelCount) ?></span>
        <span class="settings-overview-icon" aria-hidden="true"><i class="bi bi-collection"></i></span>
      </div>
      <div class="settings-overview-item">
        <span class="settings-overview-label">Thinking mode</span>
        <span class="settings-overview-value"><?= esc(['' => 'Provider default', 'disabled' => 'Disabled', 'enabled' => 'Enabled'][$s['thinking_mode']] ?? 'Provider default') ?></span>
        <span class="settings-overview-icon" aria-hidden="true"><i class="bi bi-lightbulb"></i></span>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-xl-3 col-lg-4">
        <div class="card ss-side-card" style="position: sticky; top: 1rem;">
          <div class="card-body p-2">
            <nav class="settings-nav" aria-label="AI settings sections">
              <a href="#ai-section-connection" class="settings-nav-item active">
                <i class="bi bi-sliders" aria-hidden="true"></i>
                <div class="settings-nav-text"><span class="settings-nav-label">Connection</span><span class="settings-nav-desc">Endpoint, key and model</span></div>
              </a>
              <a href="#ai-section-access" class="settings-nav-item">
                <i class="bi bi-people" aria-hidden="true"></i>
                <div class="settings-nav-text"><span class="settings-nav-label">Department access</span><span class="settings-nav-desc">Scoped AI permissions</span></div>
              </a>
              <a href="#ai-section-activation" class="settings-nav-item">
                <i class="bi bi-toggle-on" aria-hidden="true"></i>
                <div class="settings-nav-text"><span class="settings-nav-label">Activation</span><span class="settings-nav-desc">Test and enable analytics</span></div>
              </a>
            </nav>
          </div>
        </div>
        <div class="card mt-3 ss-side-note">
          <div class="card-body">
            <h6 class="ss-side-note-title">Before you enable</h6>
            <p class="ss-side-note-text">Connection setup does not send patient charts, identifiers, complaint narratives, or counseling notes. Only disclosure-validated departmental aggregates may leave the server, and only after you authorize the provider.</p>
          </div>
        </div>
      </div>

      <div class="col-xl-9 col-lg-8">
        <div class="card ss-card mb-3" id="ai-section-connection">
          <div class="card-header"><span class="card-title h6 fw-semibold mb-0 d-block">Provider connection</span></div>
          <div class="card-body">
            <p class="small text-muted">Enter the endpoint and key, fetch and import models, then choose your active model. Save and test before enabling analytics. No patient data is sent by discovery or the connection test.</p>
            <form method="post" action="<?= esc(site_url('admin/ai/save'), 'attr') ?>" data-ai-settings-form="save" autocomplete="off">
              <?= csrf_field() ?><input type="hidden" name="version" value="<?= esc($s['version'], 'attr') ?>">
              <div class="row g-3">
                <div class="col-12"><label class="form-label" for="ai-base-url">Base URL / API endpoint</label>
                  <input class="form-control" id="ai-base-url" name="base_url" type="url" required maxlength="2048" placeholder="https://your-provider.example/v1" value="<?= esc($s['base_url'], 'attr') ?>" aria-describedby="ai-base-help">
                  <div id="ai-base-help" class="form-text">Use the API base, such as <code>https://host/v1</code>. SYNAPSE adds <code>/chat/completions</code> and preserves any gateway prefix.</div>
                </div>
                <div class="col-md-6"><label class="form-label" for="ai-api-key">API key</label>
                  <input class="form-control" id="ai-api-key" name="api_key" type="password" maxlength="4096" autocomplete="new-password" spellcheck="false" placeholder="<?= $s['has_api_key'] ? 'Saved securely — leave blank to keep it' : 'Enter the provider API key' ?>" aria-describedby="ai-key-help">
                  <div id="ai-key-help" class="form-text" data-ai-key-state><?= $s['has_api_key'] ? 'A key is saved. It is never sent back to this page.' : 'No key is saved. For a no-auth local server, choose No authentication under Advanced.' ?></div>
                </div>
                <div class="col-12" data-ai-model-discovery>
                  <button class="btn btn-outline-primary" type="button" data-ai-model-fetch><i class="bi bi-cloud-download me-1" aria-hidden="true"></i>Fetch models</button>
                  <p class="form-text">Uses the connection details above, including unsaved changes. Fetching and importing do not save credentials, change the active model, or enable analytics. Only models compatible with minimum app budget are listed; manual ID remains available.</p>
                  <div data-ai-model-preview hidden>
                    <h3 class="h6">Models available to import</h3>
                    <p class="form-text">Only models compatible with minimum app budget are listed; manual ID remains available.</p>
                    <p class="small" data-ai-model-preview-state></p>
                    <p class="form-text" data-ai-model-preview-count role="status"></p>
                    <input class="form-control form-control-sm mb-2" type="search" maxlength="191" data-ai-model-preview-search placeholder="Filter available models" aria-label="Filter available models">
                    <div data-ai-model-preview-list class="border rounded p-3 mb-2 overflow-auto" style="max-height: 24rem"></div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                      <button class="btn btn-outline-primary btn-sm" type="button" data-ai-model-import-selected>Import selected</button>
                      <button class="btn btn-outline-primary btn-sm" type="button" data-ai-model-import-all>Import all</button>
                      <span class="small text-muted">Import selected adds to the existing catalog. Import all replaces the imported catalog with this preview; removes retired entries. Import all includes every model in this preview, not just matches.</span>
                    </div>
                  </div>
                  <div class="mt-3">
                    <label class="form-label" for="ai-imported-model">Imported models for this endpoint</label>
                    <select class="form-select" id="ai-imported-model" data-ai-model-select aria-describedby="ai-model-catalog-state ai-model-defaults-note"><option value="">Choose an imported model (optional)</option></select>
                    <input class="form-control form-control-sm mt-2" id="ai-model-catalog-search" type="search" maxlength="191" data-ai-model-catalog-search placeholder="Search model ID or name">
                    <p class="form-text" data-ai-model-catalog-count role="status"></p>
                    <p class="form-text" id="ai-model-catalog-state" data-ai-model-catalog-state></p>
                    <div class="small" data-ai-model-details></div>
                    <p class="form-text" id="ai-model-defaults-note" data-ai-model-defaults-note>Choosing a model fills its ID and recommended app limits and options. All values remain editable; save and test to use them.</p>
                  </div>
                </div>
                <div class="col-md-6"><label class="form-label" for="ai-model">Model name / manual exact ID</label>
                  <input class="form-control" id="ai-model" name="model" type="text" required maxlength="191" spellcheck="false" placeholder="Exact model ID from your provider" value="<?= esc($s['model'], 'attr') ?>">
                </div>
                <div class="col-md-4"><label class="form-label" for="ai-reasoning">Reasoning effort</label>
                  <select class="form-select" id="ai-reasoning" name="reasoning_effort">
                    <?php foreach (['' => 'Provider default (recommended)', 'none' => 'None', 'minimal' => 'Minimal', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'xhigh' => 'Extra high'] as $value => $label): ?>
                    <option value="<?= esc($value, 'attr') ?>"<?= $s['reasoning_effort'] === $value ? ' selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach; ?>
                  </select><div class="form-text">Only choose a level supported by your endpoint/model.</div>
                </div>
                <div class="col-md-4"><label class="form-label" for="ai-input-tokens">Input token budget</label>
                  <input class="form-control" id="ai-input-tokens" name="max_input_tokens" type="number" min="512" max="131072" step="1" required value="<?= (int) $s['max_input_tokens'] ?>">
                  <div class="form-text">Local conservative allowance: UTF-8 bytes plus framing. Not an exact provider tokenizer; oversized input is rejected, never truncated.</div>
                </div>
                <div class="col-md-4"><label class="form-label" for="ai-output-tokens">Maximum output tokens</label>
                  <input class="form-control" id="ai-output-tokens" name="max_output_tokens" type="number" min="1" max="32768" step="1" required value="<?= (int) $s['max_output_tokens'] ?>">
                  <div class="form-text">Sent to the provider as the selected output-limit field.</div>
                </div>
              </div>
              <fieldset class="mt-4">
                <legend class="h6">Approved departments</legend>
                <input type="hidden" name="unit_selection" value="1">
                <p class="form-text">Choose which departments this provider may process. Select at least one. This does not grant anyone AI or source-data access.</p>
                <?php foreach (['clinic' => 'Clinic', 'guidance' => 'Guidance', 'bmg' => 'Facilities (BMG)'] as $unit => $label): ?>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" id="ai-allow-<?= $unit ?>" name="allow_<?= $unit ?>" type="checkbox" value="1"<?= in_array($unit, $s['approved_units'] ?? ['clinic'], true) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="ai-allow-<?= $unit ?>"><?= esc($label) ?></label>
                  </div>
                <?php endforeach; ?>
              </fieldset>
              <details class="mt-4"><summary class="fw-semibold">Advanced connection options</summary>
                <div class="row g-3 mt-1">
                  <div class="col-md-4"><label class="form-label" for="ai-thinking">Thinking mode</label>
                    <select class="form-select" id="ai-thinking" name="thinking_mode" aria-describedby="ai-thinking-help">
                      <?php foreach (['' => 'Provider default', 'disabled' => 'Disabled', 'enabled' => 'Enabled'] as $value => $label): ?>
                      <option value="<?= esc($value, 'attr') ?>"<?= ($s['thinking_mode'] ?? '') === $value ? ' selected' : '' ?>><?= esc($label) ?></option>
                      <?php endforeach; ?>
                    </select><div id="ai-thinking-help" class="form-text">Independent of reasoning effort. Choose Disabled for MiniMax endpoints that require thinking off; use only options supported by your endpoint/model.</div>
                  </div>
                  <div class="col-md-4"><label class="form-label" for="ai-auth-mode">Authentication</label>
                    <select class="form-select" id="ai-auth-mode" name="auth_mode">
                      <?php foreach (['bearer' => 'Bearer API key (standard)', 'api_key_header' => 'X-API-Key header', 'none' => 'No authentication (trusted local server)'] as $value => $label): ?><option value="<?= esc($value, 'attr') ?>"<?= $s['auth_mode'] === $value ? ' selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4"><label class="form-label" for="ai-token-field">Provider output-limit field</label>
                    <select class="form-select" id="ai-token-field" name="token_field">
                      <option value="max_tokens"<?= $s['token_field'] === 'max_tokens' ? ' selected' : '' ?>>max_tokens</option>
                      <option value="max_completion_tokens"<?= $s['token_field'] === 'max_completion_tokens' ? ' selected' : '' ?>>max_completion_tokens</option>
                    </select>
                  </div>
                  <div class="col-md-4"><label class="form-label" for="ai-structured">Structured response</label>
                    <select class="form-select" id="ai-structured" name="structured_output">
                      <option value="none"<?= $s['structured_output'] === 'none' ? ' selected' : '' ?>>Standard (locally validated JSON)</option>
                      <option value="json_object"<?= $s['structured_output'] === 'json_object' ? ' selected' : '' ?>>Provider JSON object mode</option>
                    </select>
                  </div>
                  <div class="col-12"><div class="form-check">
                    <input class="form-check-input" id="ai-private-network" name="allow_private_network" type="checkbox" value="1"<?= $s['allow_private_network'] ? ' checked' : '' ?>>
                    <label class="form-check-label" for="ai-private-network">This is a trusted local/private AI server; allow this exact host and port.</label>
                    <div class="form-text">Use HTTPS where possible. This does not permit metadata/link-local destinations or redirects. HTTP on a private network is unencrypted and requires your explicit acceptance.</div>
                  </div></div>
                  <div class="col-12"><div class="form-check">
                    <input class="form-check-input" id="ai-remove-key" name="remove_api_key" type="checkbox" value="1">
                    <label class="form-check-label" for="ai-remove-key">Remove the saved API key (required when switching to no authentication).</label>
                  </div></div>
                </div>
              </details>
              <div class="d-flex gap-2 flex-wrap align-items-center mt-4">
                <button class="btn btn-primary" type="submit">Save settings</button>
                <span class="small text-muted">Changing settings pauses aggregate analytics and requires a new connection test.</span>
              </div>
            </form>
          </div>
        </div>

        <div class="card ss-card mb-3" id="ai-section-access">
          <div class="card-header"><span class="card-title h6 fw-semibold mb-0 d-block">Department AI access</span></div>
          <div class="card-body">
            <p class="small text-muted mb-2" data-ai-access-state>Checking access storage…</p>
            <template data-ai-access-data data-access="<?= esc(json_encode(['ready' => (bool) ($access['ready'] ?? false)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') ?>"></template>
            <p class="small text-muted">Grant scoped AI permissions per department and role for this tenant. Existing RBAC permissions remain unchanged, and these grants never supply source-data authority.</p>
            <?php
            $aiUnits = ['clinic' => 'Clinic', 'guidance' => 'Guidance', 'bmg' => 'Facilities (BMG)'];
            $aiRoles = ['clinic' => ['clinic_admin' => 'Clinic admin', 'clinic_staff' => 'Clinic staff', 'superadmin' => 'Superadmin'],
                'guidance' => ['guidance_admin' => 'Guidance admin', 'guidance_supervisor' => 'Guidance supervisor', 'counsellor' => 'Counsellor', 'superadmin' => 'Superadmin'],
                'bmg' => ['bmg_admin' => 'BMG admin', 'facilities_op' => 'Facilities operator', 'superadmin' => 'Superadmin']];
            $aiPermissions = ['ai.insights.read' => 'Read', 'ai.insights.generate' => 'Generate', 'ai.insights.review' => 'Review', 'ai.insights.approve' => 'Approve'];
            ?>
            <div class="accordion" id="ai-access-accordion">
              <?php $index = 0; foreach ($aiUnits as $unit => $unitLabel): ++$index; ?>
              <div class="accordion-item">
                <h3 class="accordion-header" id="ai-access-heading-<?= esc($unit, 'attr') ?>">
                  <button class="accordion-button<?= $index === 1 ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#ai-access-<?= esc($unit, 'attr') ?>" aria-expanded="<?= $index === 1 ? 'true' : 'false' ?>" aria-controls="ai-access-<?= esc($unit, 'attr') ?>"><?= esc($unitLabel) ?></button>
                </h3>
                <div id="ai-access-<?= esc($unit, 'attr') ?>" class="accordion-collapse collapse<?= $index === 1 ? ' show' : '' ?>" aria-labelledby="ai-access-heading-<?= esc($unit, 'attr') ?>" data-bs-parent="#ai-access-accordion">
                  <div class="accordion-body">
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                      <thead><tr><th scope="col">Role</th><?php foreach ($aiPermissions as $permissionLabel): ?><th scope="col"><?= esc($permissionLabel) ?></th><?php endforeach; ?></tr></thead>
                      <tbody>
                      <?php foreach ($aiRoles[$unit] as $role => $roleLabel): ?>
                        <tr><th scope="row"><?= esc($roleLabel) ?></th>
                        <?php foreach ($aiPermissions as $permission => $permissionLabel): ?>
                          <td><form method="post" action="<?= esc(site_url('admin/ai/access'), 'attr') ?>" data-ai-settings-form="access" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="operation" value="grant"><input type="hidden" name="unit" value="<?= esc($unit, 'attr') ?>">
                            <input type="hidden" name="role" value="<?= esc($role, 'attr') ?>"><input type="hidden" name="permission" value="<?= esc($permission, 'attr') ?>">
                            <button class="btn btn-outline-primary btn-sm" type="submit" aria-label="Grant <?= esc($permissionLabel, 'attr') ?> for <?= esc($roleLabel, 'attr') ?> in <?= esc($unitLabel, 'attr') ?>">Grant <?= esc($permissionLabel) ?></button>
                          </form></td>
                        <?php endforeach; ?>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table></div>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="card ss-card mb-3" id="ai-section-activation">
          <div class="card-header"><span class="card-title h6 fw-semibold mb-0 d-block">Activation</span></div>
          <div class="card-body">
            <h3 class="h6">Test connection</h3>
            <p class="small">Tests the saved endpoint, API key, model, reasoning effort, thinking mode, and output option with a fixed harmless message. Your provider may charge for this small test.</p>
            <form method="post" action="<?= esc(site_url('admin/ai/test'), 'attr') ?>" data-ai-settings-form="test">
              <?= csrf_field() ?><input type="hidden" name="version" value="<?= esc($s['version'], 'attr') ?>">
              <button class="btn btn-outline-primary" type="submit" data-ai-settings-test<?= !$s['saved'] ? ' disabled' : '' ?>>Test connection</button>
            </form>
            <hr class="my-4">
            <h3 class="h6">Protected aggregate analytics</h3>
            <p class="small">Connection setup alone does not send departmental data. Enable only when this provider is authorized to process protected aggregate statistics and you approve the operational program templates. Patient charts, identifiers, complaint narratives, and counseling notes stay excluded.</p>
            <form method="post" action="<?= esc(site_url('admin/ai/enable'), 'attr') ?>" data-ai-settings-form="enable"<?= $s['enabled'] ? ' hidden' : '' ?>>
              <?= csrf_field() ?><input type="hidden" name="version" value="<?= esc($s['version'], 'attr') ?>">
              <div class="form-check mb-3"><input class="form-check-input" id="ai-confirm-analytics" name="confirm_analytics" type="checkbox" value="1" required>
                <label class="form-check-label" for="ai-confirm-analytics">I confirm this provider may process protected aggregate departmental analytics, and the operational program templates are approved.</label>
              </div>
              <button class="btn btn-success" type="submit" data-ai-settings-enable<?= !$s['connection_tested'] ? ' disabled' : '' ?>>Enable aggregate AI</button>
            </form>
            <form method="post" action="<?= esc(site_url('admin/ai/disable'), 'attr') ?>" data-ai-settings-form="disable"<?= !$s['enabled'] ? ' hidden' : '' ?>>
              <?= csrf_field() ?><input type="hidden" name="version" value="<?= esc($s['version'], 'attr') ?>">
              <button class="btn btn-outline-warning" type="submit">Pause aggregate AI</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<script src="<?= esc(base_url('assets/js/ai-settings.js'), 'attr') ?>"></script>
<?= $this->endSection() ?>

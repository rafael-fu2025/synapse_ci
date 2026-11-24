<?php
$baseUrl = site_url('/admin/archive');
$eligible = $eligible ?? [];
$eligibleType = $eligibleType ?? '';
$eligibleQuery = $eligibleQuery ?? '';
$allowedTypes = $allowedTypes ?? [];
$scope = $scope ?? [];
?>
<div class="adm-band">
  <form method="GET" action="<?= esc($baseUrl, 'attr') ?>" class="arc-filter-grid" aria-label="Browse archive-eligible records">
    <input type="hidden" name="tab" value="eligible">

    <div class="arc-filter-field">
      <label for="elig-entity" class="adm-filter-label form-label small mb-1">Record Type</label>
      <select id="elig-entity" name="entity_type" class="form-select form-select-sm adm-input">
        <?php foreach ($allowedTypes as $typeKey => $typeLabel): ?>
          <option value="<?= esc($typeKey, 'attr') ?>" <?= $eligibleType === $typeKey ? 'selected' : '' ?>>
            <?= esc($typeLabel) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="arc-filter-field">
      <label for="elig-q" class="adm-filter-label form-label small mb-1">Search reference</label>
      <input id="elig-q" type="search" name="q" maxlength="120" value="<?= esc($eligibleQuery, 'attr') ?>"
             class="form-control form-control-sm adm-input" placeholder="Case #, title, drum code, or ID">
    </div>

    <div class="arc-filter-actions">
      <button type="submit" class="btn btn-primary btn-sm adm-btn">Show Eligible</button>
    </div>
  </form>
  <p class="small text-muted mt-2 mb-0">
    Only closed, cleared, completed, cancelled/past, or inactive operational items matching business safety rules appear here (up to 50 newest per category).
  </p>
</div>

<div class="arc-table-wrap">
  <?php if ($eligible === []): ?>
    <div class="arc-empty" role="status">
      <i class="bi bi-check2-circle" aria-hidden="true"></i>
      <p class="fw-semibold mb-1">No archive-eligible records found for this category.</p>
      <p class="small mb-0">Active clinical encounters, open guidance cases, published surveys, and active team tasks must be closed before archiving.</p>
    </div>
  <?php else: ?>
    <table class="arc-table">
      <thead>
        <tr>
          <th>Reference</th>
          <th>Module &amp; Type</th>
          <th>Workflow Status</th>
          <th>Last Updated</th>
          <?php if (!empty($scope['can_manage']) || !empty($scope['can_holds'])): ?>
            <th>Actions</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($eligible as $item): ?>
          <tr data-arc-row-key="<?= esc($item['entity_type'] . ':' . (int) $item['entity_id'], 'attr') ?>">
            <td>
              <span class="arc-ref-title"><?= esc($item['record_ref']) ?></span>
              <span class="arc-subtext arc-mono">
                ID #<?= (int) $item['entity_id'] ?><?= !empty($item['unit']) ? ' · ' . esc(ucfirst((string) $item['unit'])) : '' ?>
              </span>
            </td>
            <td>
              <span class="arc-pill"><?= esc($item['module_label']) ?></span>
              <?php if (!empty($item['confidential'])): ?>
                <span class="arc-pill is-confidential">Confidential (Masked)</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="arc-pill"><?= esc(ucwords(str_replace('_', ' ', (string) $item['status']))) ?></span>
              <?php if (!empty($item['has_active_hold'])): ?>
                <span class="arc-pill is-hold"><i class="bi bi-shield-lock" aria-hidden="true"></i> Legal Hold</span>
              <?php endif; ?>
            </td>
            <td class="arc-mono"><?= esc($item['updated_at'] ? substr((string) $item['updated_at'], 0, 16) : '—') ?></td>
            <?php if (!empty($scope['can_manage']) || !empty($scope['can_holds'])): ?>
              <td>
                <div class="arc-row-actions">
                  <?php if (!empty($scope['can_manage'])): ?>
                    <button type="button"
                            class="btn btn-outline-danger btn-sm"
                            <?= !empty($item['has_active_hold']) ? 'disabled title="Blocked by active legal hold"' : '' ?>
                            data-arc-open-archive-modal
                            data-arc-entity-type="<?= esc($item['entity_type'], 'attr') ?>"
                            data-arc-entity-id="<?= (int) $item['entity_id'] ?>"
                            data-arc-record-ref="<?= esc($item['record_ref'], 'attr') ?>">
                      Archive
                    </button>
                  <?php endif; ?>
                  <?php if (empty($item['has_active_hold']) && !empty($scope['can_holds'])): ?>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm"
                            data-arc-open-hold-modal
                            data-arc-entity-type="<?= esc($item['entity_type'], 'attr') ?>"
                            data-arc-entity-id="<?= (int) $item['entity_id'] ?>"
                            data-arc-record-ref="<?= esc($item['record_ref'], 'attr') ?>">
                      Place Hold
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

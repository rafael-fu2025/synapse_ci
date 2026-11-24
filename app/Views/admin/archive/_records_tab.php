<?php
$baseUrl = site_url('/admin/archive');
$filters = $filters ?? ['state' => 'archived', 'module' => '', 'entity_type' => '', 'retention' => 'all', 'q' => '', 'per_page' => 25];
$records = $records ?? [];
$modules = $modules ?? [];
$scope = $scope ?? [];
$allowedTypes = $allowedTypes ?? [];
$pagination = $pagination ?? ['page' => 1, 'per_page' => 25, 'total' => 0, 'filtered' => 0, 'pages' => 1];
?>
<div class="adm-band">
  <form method="GET" action="<?= esc($baseUrl, 'attr') ?>" class="arc-filter-grid" aria-label="Filter archive records">
    <input type="hidden" name="tab" value="records">

    <div class="arc-filter-field">
      <label for="arc-q" class="adm-filter-label form-label small mb-1">Search reference or reason</label>
      <input id="arc-q" type="search" name="q" maxlength="120" value="<?= esc($filters['q'] ?? '', 'attr') ?>"
             class="form-control form-control-sm adm-input" placeholder="Reference code, case number, or reason">
    </div>

    <div class="arc-filter-field">
      <label for="arc-module" class="adm-filter-label form-label small mb-1">Module</label>
      <select id="arc-module" name="module" class="form-select form-select-sm adm-input">
        <option value="">All authorized</option>
        <?php foreach ($scope['modules'] ?? [] as $mKey): ?>
          <option value="<?= esc($mKey, 'attr') ?>" <?= ($filters['module'] ?? '') === $mKey ? 'selected' : '' ?>>
            <?= esc($modules[$mKey] ?? ucfirst($mKey)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="arc-filter-field">
      <label for="arc-entity" class="adm-filter-label form-label small mb-1">Record Type</label>
      <select id="arc-entity" name="entity_type" class="form-select form-select-sm adm-input">
        <option value="">All record types</option>
        <?php foreach ($allowedTypes as $typeKey => $typeLabel): ?>
          <option value="<?= esc($typeKey, 'attr') ?>" <?= ($filters['entity_type'] ?? '') === $typeKey ? 'selected' : '' ?>>
            <?= esc($typeLabel) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="arc-filter-field">
      <label for="arc-state" class="adm-filter-label form-label small mb-1">Archive State</label>
      <select id="arc-state" name="state" class="form-select form-select-sm adm-input">
        <option value="archived" <?= ($filters['state'] ?? 'archived') === 'archived' ? 'selected' : '' ?>>Archived</option>
        <option value="restored" <?= ($filters['state'] ?? '') === 'restored' ? 'selected' : '' ?>>Restored</option>
        <option value="all" <?= ($filters['state'] ?? '') === 'all' ? 'selected' : '' ?>>All states</option>
      </select>
    </div>

    <div class="arc-filter-field">
      <label for="arc-retention" class="adm-filter-label form-label small mb-1">Governance</label>
      <select id="arc-retention" name="retention" class="form-select form-select-sm adm-input">
        <option value="all" <?= ($filters['retention'] ?? 'all') === 'all' ? 'selected' : '' ?>>Any status</option>
        <option value="past_due" <?= ($filters['retention'] ?? '') === 'past_due' ? 'selected' : '' ?>>Past retention target</option>
        <option value="held" <?= ($filters['retention'] ?? '') === 'held' ? 'selected' : '' ?>>Under legal hold</option>
      </select>
    </div>

    <div class="arc-filter-actions">
      <button type="submit" class="btn btn-primary btn-sm adm-btn">Apply</button>
      <a href="<?= esc($baseUrl . '?tab=records', 'attr') ?>" class="btn btn-outline-secondary btn-sm adm-btn">Reset</a>
    </div>
  </form>
</div>

<div class="arc-table-wrap">
  <?php if ($records === []): ?>
    <div class="arc-empty" role="status">
      <i class="bi bi-archive" aria-hidden="true"></i>
      <p class="fw-semibold mb-1">No archive records match these filters.</p>
      <p class="small mb-0">Try clearing filters or inspect eligible records to archive closed items.</p>
    </div>
  <?php else: ?>
    <table class="arc-table">
      <thead>
        <tr>
          <th>Reference</th>
          <th>Module &amp; Type</th>
          <th>State &amp; Governance</th>
          <th>Reason &amp; Actor</th>
          <th>Retention Target</th>
          <?php if (!empty($scope['can_manage']) || !empty($scope['can_holds'])): ?>
            <th>Actions</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody<?= ($filters['state'] ?? 'archived') === 'all' ? ' data-arc-keep-row-on-restore="1"' : '' ?>>
        <?php foreach ($records as $row): ?>
          <tr data-arc-row-key="<?= esc($row['entity_type'] . ':' . (int) $row['entity_id'], 'attr') ?>">
            <td>
              <span class="arc-ref-title"><?= esc($row['record_ref']) ?></span>
              <span class="arc-subtext arc-mono">ID #<?= (int) $row['entity_id'] ?><?= !empty($row['unit']) ? ' · ' . esc(ucfirst((string) $row['unit'])) : '' ?></span>
            </td>
            <td>
              <span class="arc-pill"><?= esc($row['module_label'] ?? ucfirst((string) $row['module'])) ?></span>
              <span class="arc-subtext"><?= esc($row['entity_label'] ?? $row['entity_type']) ?></span>
            </td>
            <td>
              <div class="d-flex flex-wrap gap-1">
                <span class="arc-pill <?= $row['state'] === 'archived' ? 'is-archived' : 'is-restored' ?>">
                  <?= esc(ucfirst((string) $row['state'])) ?>
                </span>
                <?php if (!empty($row['has_active_hold'])): ?>
                  <span class="arc-pill is-hold"><i class="bi bi-shield-lock" aria-hidden="true"></i> Legal Hold</span>
                <?php endif; ?>
                <?php if (!empty($row['past_retention'])): ?>
                  <span class="arc-pill is-past-due">Past Retention</span>
                <?php endif; ?>
              </div>
              <span class="arc-subtext arc-mono">
                Archived <?= esc(substr((string) $row['archived_at'], 0, 16)) ?> UTC
              </span>
              <?php if (!empty($row['restored_at'])): ?>
                <span class="arc-subtext arc-mono">
                  Restored <?= esc(substr((string) $row['restored_at'], 0, 16)) ?> UTC
                </span>
              <?php endif; ?>
            </td>
            <td>
              <span><?= esc($row['reason']) ?></span>
              <span class="arc-subtext">
                By <?= esc($row['archived_by_username'] ?: ('User #' . (int) $row['archived_by_user_id'])) ?>
              </span>
              <?php if (!empty($row['restore_reason'])): ?>
                <span class="arc-subtext">
                  Restore note: <?= esc($row['restore_reason']) ?>
                  (<?= esc($row['restored_by_username'] ?: ('User #' . (int) $row['restored_by_user_id'])) ?>)
                </span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($row['policy_code'])): ?>
                <span class="arc-mono fw-semibold"><?= esc($row['policy_code']) ?></span>
                <span class="arc-subtext">Due <?= esc($row['retention_due_at'] ? substr((string) $row['retention_due_at'], 0, 10) : '—') ?></span>
              <?php else: ?>
                <span class="arc-subtext">No policy matched</span>
              <?php endif; ?>
            </td>
            <?php if (!empty($scope['can_manage']) || !empty($scope['can_holds'])): ?>
              <td>
                <div class="arc-row-actions">
                  <?php if ($row['state'] === 'archived' && !empty($scope['can_manage'])): ?>
                    <button type="button"
                            class="btn btn-outline-primary btn-sm"
                            data-arc-open-restore-modal
                            data-arc-entity-type="<?= esc($row['entity_type'], 'attr') ?>"
                            data-arc-entity-id="<?= (int) $row['entity_id'] ?>"
                            data-arc-record-ref="<?= esc($row['record_ref'], 'attr') ?>">
                      Restore
                    </button>
                  <?php endif; ?>
                  <?php if (empty($row['has_active_hold']) && !empty($scope['can_holds'])): ?>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm"
                            data-arc-open-hold-modal
                            data-arc-entity-type="<?= esc($row['entity_type'], 'attr') ?>"
                            data-arc-entity-id="<?= (int) $row['entity_id'] ?>"
                            data-arc-record-ref="<?= esc($row['record_ref'], 'attr') ?>">
                      Hold
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

<?php if ($records !== []): ?>
  <div class="adm-band">
    <?= view('partials/table_pagination', [
        'pagination' => $pagination,
        'baseUrl'    => $baseUrl,
        'filters'    => [],
        'paginationExtraQuery' => array_filter([
            'tab'         => 'records',
            'state'       => $filters['state'] ?? null,
            'module'      => ($filters['module'] ?? '') !== '' ? $filters['module'] : null,
            'entity_type' => ($filters['entity_type'] ?? '') !== '' ? $filters['entity_type'] : null,
            'retention'   => ($filters['retention'] ?? 'all') !== 'all' ? $filters['retention'] : null,
            'q'           => ($filters['q'] ?? '') !== '' ? $filters['q'] : null,
        ], static fn ($v): bool => $v !== null),
        'paginationLabel' => 'Archive record pages',
    ], ['saveData' => false]) ?>
  </div>
<?php endif; ?>

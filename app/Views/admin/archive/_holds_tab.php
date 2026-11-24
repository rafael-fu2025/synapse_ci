<?php
$baseUrl = site_url('/admin/archive');
$holds = $holds ?? [];
$holdsFilters = $holdsFilters ?? ['status' => 'active', 'module' => '', 'q' => ''];
$holdsPagination = $holdsPagination ?? ['page' => 1, 'per_page' => 25, 'total' => 0, 'filtered' => 0, 'pages' => 1];
$scope = $scope ?? [];
$modules = $modules ?? [];
?>
<div class="adm-band">
  <form method="GET" action="<?= esc($baseUrl, 'attr') ?>" class="arc-filter-grid" aria-label="Filter legal holds">
    <input type="hidden" name="tab" value="holds">

    <div class="arc-filter-field">
      <label for="hold-q" class="adm-filter-label form-label small mb-1">Search held record or reason</label>
      <input id="hold-q" type="search" name="q" maxlength="120" value="<?= esc($holdsFilters['q'] ?? '', 'attr') ?>"
             class="form-control form-control-sm adm-input" placeholder="Reference, hold reason, or docket code">
    </div>

    <div class="arc-filter-field">
      <label for="hold-module" class="adm-filter-label form-label small mb-1">Module</label>
      <select id="hold-module" name="module" class="form-select form-select-sm adm-input">
        <option value="">All authorized</option>
        <?php foreach ($scope['modules'] ?? [] as $mKey): ?>
          <option value="<?= esc($mKey, 'attr') ?>" <?= ($holdsFilters['module'] ?? '') === $mKey ? 'selected' : '' ?>>
            <?= esc($modules[$mKey] ?? ucfirst($mKey)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="arc-filter-field">
      <label for="hold-status" class="adm-filter-label form-label small mb-1">Hold Status</label>
      <select id="hold-status" name="status" class="form-select form-select-sm adm-input">
        <option value="active" <?= ($holdsFilters['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active holds</option>
        <option value="lifted" <?= ($holdsFilters['status'] ?? '') === 'lifted' ? 'selected' : '' ?>>Lifted history</option>
        <option value="all" <?= ($holdsFilters['status'] ?? '') === 'all' ? 'selected' : '' ?>>All holds</option>
      </select>
    </div>

    <div class="arc-filter-actions">
      <button type="submit" class="btn btn-primary btn-sm adm-btn">Apply</button>
      <a href="<?= esc($baseUrl . '?tab=holds', 'attr') ?>" class="btn btn-outline-secondary btn-sm adm-btn">Reset</a>
    </div>
  </form>
</div>

<div class="arc-table-wrap">
  <?php if ($holds === []): ?>
    <div class="arc-empty" role="status">
      <i class="bi bi-shield-check" aria-hidden="true"></i>
      <p class="fw-semibold mb-1">No legal holds found for the selected filter.</p>
      <p class="small mb-0">Active holds prevent archiving and preserve records pending review or investigation.</p>
    </div>
  <?php else: ?>
    <table class="arc-table">
      <thead>
        <tr>
          <th>Target Record</th>
          <th>Status &amp; Reference</th>
          <th>Hold Reason &amp; Custody</th>
          <th>Timeline</th>
          <?php if (!empty($scope['can_holds'])): ?>
            <th>Action</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($holds as $h): $isActive = (int) ($h['is_active'] ?? 0) === 1; ?>
          <tr>
            <td>
              <span class="arc-ref-title"><?= esc($h['record_ref']) ?></span>
              <span class="arc-subtext arc-mono"><?= esc($h['entity_type']) ?> #<?= (int) $h['entity_id'] ?> · <?= esc($modules[$h['module']] ?? ucfirst((string) $h['module'])) ?></span>
            </td>
            <td>
              <span class="arc-pill <?= $isActive ? 'is-hold' : 'is-restored' ?>">
                <?= $isActive ? 'Active Hold' : 'Lifted' ?>
              </span>
              <?php if (!empty($h['reference_code'])): ?>
                <span class="arc-subtext arc-mono">Ref: <?= esc($h['reference_code']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <span><?= esc($h['reason']) ?></span>
              <span class="arc-subtext">Placed by <?= esc($h['placed_by_username'] ?: ('User #' . (int) $h['placed_by_user_id'])) ?></span>
              <?php if (!$isActive && !empty($h['lift_reason'])): ?>
                <span class="arc-subtext">Lift reason: <?= esc($h['lift_reason']) ?> (<?= esc($h['lifted_by_username'] ?: ('User #' . (int) $h['lifted_by_user_id'])) ?>)</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="arc-subtext arc-mono">Placed <?= esc(substr((string) $h['placed_at'], 0, 16)) ?> UTC</span>
              <?php if (!empty($h['lifted_at'])): ?>
                <span class="arc-subtext arc-mono">Lifted <?= esc(substr((string) $h['lifted_at'], 0, 16)) ?> UTC</span>
              <?php endif; ?>
            </td>
            <?php if (!empty($scope['can_holds'])): ?>
              <td>
                <?php if ($isActive): ?>
                  <button type="button"
                          class="btn btn-outline-danger btn-sm"
                          data-arc-open-lift-modal
                          data-arc-hold-id="<?= (int) $h['id'] ?>"
                          data-arc-record-ref="<?= esc($h['record_ref'], 'attr') ?>">
                    Lift Hold
                  </button>
                <?php else: ?>
                  <span class="small text-muted">—</span>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($holds !== [] && $holdsPagination !== null): ?>
  <div class="adm-band">
    <?= view('partials/table_pagination', [
        'pagination'          => $holdsPagination,
        'baseUrl'             => $baseUrl,
        'filters'             => [],
        'paginationPageParam' => 'holds_page',
        'paginationExtraQuery' => array_filter([
            'tab'    => 'holds',
            'status' => $holdsFilters['status'] ?? null,
            'module' => ($holdsFilters['module'] ?? '') !== '' ? $holdsFilters['module'] : null,
            'q'      => ($holdsFilters['q'] ?? '') !== '' ? $holdsFilters['q'] : null,
        ], static fn ($v): bool => $v !== null),
        'paginationLabel'     => 'Legal hold pages',
    ], ['saveData' => false]) ?>
  </div>
<?php endif; ?>

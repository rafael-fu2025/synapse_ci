<?= $this->extend('layouts/app') ?>
<?php
$id = (int) $medicine['id'];
$isActive = (int) $medicine['is_active'] === 1;
$canManage = !empty($canManage);
$canWrite = !empty($canWrite);
$page = (int) $movementPagination['page'];
$operationIds = array_fill_keys(array_map('intval', array_column($operations, 'id')), true);
$movementIds = array_fill_keys(array_map('intval', array_column($movements, 'id')), true);
$batchNumbers = [];
foreach ($batches as $batch) {
    $batchNumbers[(int) $batch['id']] = $batch['batch_number'];
}
$batchToday = date('Y-m-d');
$batchNear = date('Y-m-d', strtotime('+90 days'));
$stockMetrics = [
    'usable_stock' => ['label' => 'Usable stock', 'icon' => 'bi-check2-circle', 'tone' => !empty($medicine['low_stock']) ? 'amber' : 'emerald'],
    'total_stock' => ['label' => 'Physical on hand', 'icon' => 'bi-box-seam', 'tone' => 'slate'],
    'expired_stock' => ['label' => 'Expired stock', 'icon' => 'bi-calendar-x', 'tone' => 'crimson'],
    'quarantined_stock' => ['label' => 'Quarantined stock', 'icon' => 'bi-shield-exclamation', 'tone' => 'amber'],
];
?>
<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 clinic-page-actions inv-page-actions">
  <a href="<?= esc(site_url('/clinic/inventory')) ?>" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>All Medicines
  </a>
  <?php if ($canManage): ?>
    <a href="<?= esc(site_url('/clinic/inventory/' . $id . '/edit')) ?>" class="btn btn-outline-secondary" data-app-modal-open>
      <i class="bi bi-pencil" aria-hidden="true"></i>Edit Medicine
    </a>
  <?php endif; ?>
  <?php if ($canWrite && $isActive): ?>
    <a href="<?= esc(site_url('/clinic/inventory/' . $id . '/receive')) ?>" class="btn btn-primary" data-app-modal-open>
      <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>Receive Stock
    </a>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<section class="inv-workspace inventory-show-section" data-refresh-owner="inventory.detail.<?= $id ?>"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">

  <div data-refresh-region="summary" data-refresh-heading tabindex="-1" class="inv-detail-summary-stack">
    <div class="inv-detail-hero">
      <div class="inv-detail-hero-head">
        <div class="inv-detail-title-group">
          <h2 class="inv-detail-name"><?= esc($medicine['name']) ?></h2>
          <span class="inv-status-inline <?= $isActive ? 'inv-status-inline--active' : 'inv-status-inline--archived' ?>">
            <span class="inv-dot" aria-hidden="true"></span><?= $isActive ? 'Active' : 'Archived' ?>
          </span>
          <?php if (!empty($medicine['low_stock'])): ?>
            <span class="inv-status-inline inv-status-inline--warn">
              <span class="inv-dot" aria-hidden="true"></span>At / below reorder level
            </span>
          <?php endif; ?>
        </div>
        <span class="inv-detail-unit-meta">Unit: <?= esc($medicine['unit_of_measure']) ?></span>
      </div>

      <dl class="inv-spec-grid">
        <?php foreach ([
            'generic_name' => 'Generic name',
            'category' => 'Category',
            'strength' => 'Strength',
            'dosage_form' => 'Dosage form',
            'unit_of_measure' => 'Stock unit',
        ] as $field => $label): ?>
          <div class="inv-spec-item">
            <dt><?= esc($label) ?></dt>
            <dd><?= esc($medicine[$field] ?? 'Not specified') ?></dd>
          </div>
        <?php endforeach; ?>
        <div class="inv-spec-item">
          <dt>Reorder level</dt>
          <dd class="inv-mono"><?= number_format((int) $medicine['minimum_stock_level']) ?> <?= esc($medicine['unit_of_measure']) ?></dd>
        </div>
      </dl>

      <div class="inv-detail-hero-foot">
        Every quantity below is a whole stock unit (<?= esc($medicine['unit_of_measure']) ?>), not a fractional dose. Archived medicines cannot be received or dispensed.
      </div>
    </div>

    <div class="inv-kpi-grid">
      <?php foreach ($stockMetrics as $field => $meta): ?>
        <?php $qty = (int) $medicine[$field]; ?>
        <div class="inv-kpi-card" data-tone="<?= esc($meta['tone']) ?>" data-active="<?= ($field === 'usable_stock' || $qty > 0) ? 'true' : 'false' ?>">
          <div class="inv-kpi-head">
            <h3 class="inv-kpi-label"><?= esc($meta['label']) ?></h3>
            <span class="inv-kpi-icon" aria-hidden="true"><i class="bi <?= esc($meta['icon']) ?>"></i></span>
          </div>
          <div class="inv-kpi-metric-row">
            <div class="inv-kpi-value"><?= number_format($qty) ?></div>
            <span class="inv-kpi-unit"><?= esc($medicine['unit_of_measure']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="inv-note-bar <?= (int) $medicine['near_expiry_stock'] > 0 ? 'inv-note-bar--warn' : '' ?>">
      Usable stock expiring within 90 days: <strong class="inv-mono"><?= number_format((int) $medicine['near_expiry_stock']) ?> <?= esc($medicine['unit_of_measure']) ?></strong>. Usable and blocked stock are subsets of physical stock; expired and quarantined subsets may overlap, and near-expiry stock is part of usable stock. Do not sum these figures.
    </div>
  </div>

  <div data-refresh-region="batches">
    <div class="inv-card" id="inventory-batches">
      <div class="inv-card-header">
        <div class="inv-card-header-stack">
          <div class="inv-card-title-row">
            <h2 class="inv-card-title">Batches</h2>
            <span class="inv-card-count"><?= number_format(count($batches)) ?> <?= count($batches) === 1 ? 'lot' : 'lots' ?></span>
          </div>
          <p class="inv-card-sub">Ordered by expiry date (FEFO). Received is cumulative receipts for the lot; remaining is physical stock.</p>
        </div>
      </div>
      <div class="inv-table-wrap">
        <table class="table align-middle mb-0 inv-table" data-mobile-cards="false">
          <caption class="visually-hidden">All batches, quantities in <?= esc($medicine['unit_of_measure']) ?></caption>
          <thead>
            <tr>
              <th scope="col">Lot / batch</th>
              <th scope="col">Full expiry date</th>
              <th scope="col">Status</th>
              <th scope="col" class="text-end">Received</th>
              <th scope="col" class="text-end">Remaining</th>
              <?php if ($canManage): ?><th scope="col" class="text-end">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if ($batches === []): ?>
              <tr class="inv-empty-row">
                <td colspan="<?= $canManage ? 6 : 5 ?>" class="inv-empty-cell">No batches have been received.</td>
              </tr>
            <?php endif; ?>
            <?php foreach ($batches as $batch): ?>
              <?php
                $isBatchExpired = $batch['expiration_date'] < $batchToday;
                $isBatchNear = !$isBatchExpired && $batch['status'] === 'active' && (int) $batch['remaining_quantity'] > 0 && $batch['expiration_date'] <= $batchNear;
                $isQuarantined = $batch['status'] === 'quarantined';
              ?>
              <tr id="inventory-batch-<?= (int) $batch['id'] ?>" class="inv-row">
                <th scope="row" class="fw-normal text-break inv-cell-header">
                  <div class="inv-cell-primary inv-mono"><?= esc($batch['batch_number']) ?></div>
                  <small class="inv-cell-sub">Batch #<?= (int) $batch['id'] ?></small>
                </th>
                <td>
                  <span class="inv-mobile-label">Full expiry date</span>
                  <div class="inv-expiry-iso <?= $isBatchExpired ? 'inv-expiry-iso--danger' : '' ?>"><?= esc($batch['expiration_date']) ?></div>
                  <?php if ($isBatchExpired): ?>
                    <div class="inv-expiry-flag">
                      <span class="inv-status-inline inv-status-inline--danger"><span class="inv-dot" aria-hidden="true"></span>Expired</span>
                    </div>
                  <?php elseif ($isBatchNear): ?>
                    <div class="inv-expiry-flag">
                      <span class="inv-status-inline inv-status-inline--warn"><span class="inv-dot" aria-hidden="true"></span>Within 90 days</span>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="inv-mobile-label">Status</span>
                  <span class="inv-status-inline <?= $isQuarantined ? 'inv-status-inline--warn' : 'inv-status-inline--active' ?>">
                    <span class="inv-dot" aria-hidden="true"></span><?= esc(ucfirst($batch['status'])) ?>
                  </span>
                </td>
                <td class="text-end"><span class="inv-mobile-label">Received</span><span class="inv-mono"><?= number_format((int) $batch['initial_quantity']) ?></span></td>
                <td class="text-end"><span class="inv-mobile-label">Remaining</span><span class="inv-stock-num"><?= number_format((int) $batch['remaining_quantity']) ?></span></td>
                <?php if ($canManage): ?>
                  <td class="text-end inv-cell-actions">
                    <div class="inv-row-actions">
                      <?php if ((int) $batch['remaining_quantity'] > 0): ?>
                        <a href="<?= esc(site_url('/clinic/inventory/batches/' . (int) $batch['id'] . '/adjust')) ?>" class="btn btn-outline-danger btn-sm" data-app-modal-open>Remove Stock</a>
                      <?php endif; ?>
                      <a href="<?= esc(site_url('/clinic/inventory/batches/' . (int) $batch['id'] . '/status')) ?>" class="btn btn-outline-secondary btn-sm" data-app-modal-open><?= $isQuarantined ? 'Review Quarantine' : 'Quarantine / Status' ?></a>
                    </div>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div data-refresh-region="operations">
    <div class="inv-card" id="inventory-operations">
      <div class="inv-card-header">
        <div class="inv-card-header-stack">
          <div class="inv-card-title-row">
            <h2 class="inv-card-title">Latest operations</h2>
            <span class="inv-card-count"><?= number_format(count($operations)) ?></span>
          </div>
          <p class="inv-card-sub">Latest 100 operations only, newest first; independent of the movement page. One correction per dispensation. Legacy allocations with a null operation ID cannot be corrected.</p>
        </div>
      </div>
      <div class="inv-table-wrap">
        <table class="table align-middle mb-0 inv-table" data-mobile-cards="false">
          <caption class="visually-hidden">Latest 100 inventory operations and correction references</caption>
          <thead>
            <tr>
              <th scope="col">Operation / date</th>
              <th scope="col">Source / reference</th>
              <th scope="col">Reason</th>
              <th scope="col">Recorded by</th>
              <th scope="col">Correction</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($operations === []): ?>
              <tr class="inv-empty-row">
                <td colspan="5" class="inv-empty-cell">No ledger operations recorded. Legacy balances may still appear in movements.</td>
              </tr>
            <?php endif; ?>
            <?php foreach ($operations as $operation): ?>
              <?php
                $opTone = match ($operation['type']) {
                    'receipt' => 'inv-status-inline--active',
                    'dispense' => 'inv-status-inline--brand',
                    'reversal' => 'inv-status-inline--warn',
                    'adjustment' => 'inv-status-inline--danger',
                    default => 'inv-status-inline--quarantined',
                };
              ?>
              <tr id="inventory-operation-<?= (int) $operation['id'] ?>" class="inv-row">
                <th scope="row" class="fw-normal inv-cell-header">
                  <div class="inv-op-head">
                    <span class="inv-op-id">#<?= (int) $operation['id'] ?></span>
                    <span class="inv-status-inline <?= esc($opTone) ?>"><span class="inv-dot" aria-hidden="true"></span><?= esc(ucfirst($operation['type'])) ?></span>
                  </div>
                  <small class="inv-cell-sub inv-mono"><?= esc($operation['created_at']) ?></small>
                </th>
                <td class="text-break">
                  <span class="inv-mobile-label">Source / reference</span>
                  <?php if ($operation['type'] === 'receipt'): ?>
                    <div class="inv-cell-primary">Supplier: <?= esc($operation['supplier'] ?? 'Not provided') ?></div>
                    <small class="inv-cell-sub">Reference: <?= esc($operation['reference'] ?? 'Not provided') ?></small>
                  <?php elseif ($operation['encounter_id'] !== null): ?>
                    <span class="inv-cell-primary inv-mono">Encounter #<?= (int) $operation['encounter_id'] ?></span>
                  <?php else: ?>
                    <span class="inv-no-alerts">Inventory ledger</span>
                  <?php endif; ?>
                </td>
                <td class="text-break inv-cell-full">
                  <span class="inv-mobile-label">Reason</span>
                  <?= esc($operation['reason']) ?>
                </td>
                <td class="inv-actor-cell">
                  <span class="inv-mobile-label">Recorded by</span>
                  <?= esc($operation['actor_name'] ?? ('#' . (int) $operation['actor_id'])) ?>
                </td>
                <td class="inv-cell-actions">
                  <span class="inv-mobile-label">Correction</span>
                  <?php if ($operation['type'] === 'reversal'): ?>
                    <div class="inv-cell-primary"><?= $operation['reversal_mode'] === 'restore_unissued' ? 'Restored all unissued units' : 'Record only — stock unchanged' ?></div>
                    <?php if (isset($operationIds[(int) $operation['reversal_of']])): ?>
                      <a href="#inventory-operation-<?= (int) $operation['reversal_of'] ?>" class="inv-ref-link inv-mono small">Original #<?= (int) $operation['reversal_of'] ?></a>
                    <?php else: ?>
                      <small class="inv-cell-sub">Original #<?= (int) $operation['reversal_of'] ?> (outside latest 100)</small>
                    <?php endif; ?>
                  <?php elseif ($operation['type'] === 'dispense' && $operation['reversed_by_operation_id'] !== null): ?>
                    <div class="inv-cell-primary">Corrected · <?= $operation['correction_mode'] === 'restore_unissued' ? 'Unissued stock restored' : 'Record only' ?></div>
                    <?php if (isset($operationIds[(int) $operation['reversed_by_operation_id']])): ?>
                      <a href="#inventory-operation-<?= (int) $operation['reversed_by_operation_id'] ?>" class="inv-ref-link inv-mono small">Correction #<?= (int) $operation['reversed_by_operation_id'] ?></a>
                    <?php else: ?>
                      <small class="inv-cell-sub">Correction #<?= (int) $operation['reversed_by_operation_id'] ?> (outside latest 100)</small>
                    <?php endif; ?>
                  <?php elseif ($operation['type'] === 'dispense'): ?>
                    <?php if ($canManage): ?>
                      <a href="<?= esc(site_url('/clinic/inventory/operations/' . (int) $operation['id'] . '/reverse')) ?>" class="btn btn-outline-danger btn-sm" data-app-modal-open>Review Correction</a>
                    <?php else: ?>
                      <span class="inv-no-alerts">Not corrected</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="inv-no-alerts">Not applicable</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div data-refresh-region="movements">
    <div class="inv-card" id="inventory-movements">
      <div class="inv-card-header">
        <div class="inv-card-header-stack">
          <div class="inv-card-title-row">
            <h2 class="inv-card-title">Stock movements</h2>
            <span class="inv-card-count">Page <?= $page ?></span>
          </div>
          <p class="inv-card-sub"><?= (int) $movementPagination['per_page'] ?> movements per page, newest first. Signed quantities use <?= esc($medicine['unit_of_measure']) ?>; zero indicates no physical stock change.</p>
        </div>
      </div>
      <div class="inv-table-wrap">
        <table class="table align-middle mb-0 inv-table" data-mobile-cards="false">
          <caption class="visually-hidden">Paginated stock movement ledger in <?= esc($medicine['unit_of_measure']) ?></caption>
          <thead>
            <tr>
              <th scope="col">Movement / date</th>
              <th scope="col">Batch</th>
              <th scope="col" class="text-end">Signed quantity</th>
              <th scope="col">Actor user ID</th>
              <th scope="col">Reason</th>
              <th scope="col">Source reference</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($movements === []): ?>
              <tr class="inv-empty-row">
                <td colspan="6" class="inv-empty-cell">No movements on this page.</td>
              </tr>
            <?php endif; ?>
            <?php foreach ($movements as $movement): ?>
              <?php
                $delta = (int) $movement['delta'];
                $deltaClass = $delta > 0 ? 'inv-delta--pos' : ($delta < 0 ? 'inv-delta--neg' : 'inv-delta--zero');
              ?>
              <tr id="inventory-movement-<?= (int) $movement['id'] ?>" class="inv-row">
                <th scope="row" class="fw-normal inv-cell-header">
                  <div class="inv-op-id">#<?= (int) $movement['id'] ?></div>
                  <small class="inv-cell-sub inv-mono"><?= esc($movement['created_at']) ?></small>
                </th>
                <td>
                  <span class="inv-mobile-label">Batch</span>
                  <a href="#inventory-batch-<?= (int) $movement['batch_id'] ?>" class="inv-ref-link inv-mono inv-batch-link"><?= esc($batchNumbers[(int) $movement['batch_id']] ?? ('Batch #' . (int) $movement['batch_id'])) ?></a>
                </td>
                <td class="text-end text-nowrap">
                  <span class="inv-mobile-label">Signed quantity</span>
                  <span class="inv-delta <?= esc($deltaClass) ?>"><?= $delta > 0 ? '+' : '' ?><?= number_format($delta) ?></span>
                </td>
                <td>
                  <span class="inv-mobile-label">Actor user ID</span>
                  <span class="<?= $movement['actor_id'] === null ? 'inv-no-alerts' : 'inv-mono' ?>"><?= $movement['actor_id'] === null ? 'Not recorded (legacy)' : '#' . (int) $movement['actor_id'] ?></span>
                </td>
                <td class="text-break inv-cell-full">
                  <span class="inv-mobile-label">Reason</span>
                  <?= esc($movement['reason']) ?>
                </td>
                <td class="inv-cell-full">
                  <span class="inv-mobile-label">Source reference</span>
                  <?php if ($movement['operation_id'] === null): ?>
                    <span class="inv-no-alerts">Legacy opening balance — no operation ID; correction unavailable</span>
                  <?php elseif (isset($operationIds[(int) $movement['operation_id']])): ?>
                    <a href="#inventory-operation-<?= (int) $movement['operation_id'] ?>" class="inv-ref-link inv-mono small">Operation #<?= (int) $movement['operation_id'] ?></a>
                  <?php else: ?>
                    <span class="inv-no-alerts">Operation #<?= (int) $movement['operation_id'] ?> (outside latest 100)</span>
                  <?php endif; ?>
                  <?php if ($movement['original_movement_id'] !== null): ?>
                    <div class="small mt-1">
                      <?php if (isset($movementIds[(int) $movement['original_movement_id']])): ?>
                        <a href="#inventory-movement-<?= (int) $movement['original_movement_id'] ?>" class="inv-ref-link inv-mono">Original movement #<?= (int) $movement['original_movement_id'] ?></a>
                      <?php else: ?>
                        <span class="inv-no-alerts">Original movement #<?= (int) $movement['original_movement_id'] ?> (on another page)</span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="inv-movement-footer">
        <form method="get" action="<?= esc(site_url('/clinic/inventory/' . $id)) ?>#inventory-movements" class="inv-movement-perpage inventory-movement-perpage">
          <label for="movementPerPage">Movements per page</label>
          <div>
            <select id="movementPerPage" name="per_page" class="form-select form-select-sm">
              <?php foreach ([20, 25, 50, 100] as $size): ?>
                <option value="<?= $size ?>"<?= $size === (int) $movementPagination['per_page'] ? ' selected' : '' ?>><?= $size ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-outline-secondary btn-sm">Apply</button>
        </form>
        <?= view('partials/table_pagination', [
            'pagination' => $movementPagination, 'filters' => [],
            'baseUrl' => site_url('/clinic/inventory/' . $id),
            'paginationLabel' => 'Stock movement pages',
        ], ['saveData' => false]) ?>
      </div>
    </div>
  </div>
</section>
<?= $this->endSection() ?>

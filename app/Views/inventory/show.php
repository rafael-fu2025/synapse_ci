<?= $this->extend('layouts/app') ?>
<?php
$id = (int) $medicine['id'];
$canManage = !empty($canManage);
$canWrite = !empty($canWrite);
$page = (int) $movementPage;
$operationIds = array_fill_keys(array_map('intval', array_column($operations, 'id')), true);
$movementIds = array_fill_keys(array_map('intval', array_column($movements, 'id')), true);
$batchNumbers = [];
foreach ($batches as $batch) {
    $batchNumbers[(int) $batch['id']] = $batch['batch_number'];
}
?>
<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <a href="<?= esc(site_url('/clinic/inventory')) ?>" class="btn btn-outline-secondary">All Medicines</a>
  <?php if ($canManage): ?><a href="<?= esc(site_url('/clinic/inventory/' . $id . '/edit')) ?>" class="btn btn-outline-primary" data-app-modal-open>Edit Medicine</a><?php endif; ?>
  <?php if ($canWrite && (int) $medicine['is_active'] === 1): ?><a href="<?= esc(site_url('/clinic/inventory/' . $id . '/receive')) ?>" class="btn btn-primary" data-app-modal-open>Receive Stock</a><?php endif; ?>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card mb-4"><div class="card-body pt-3">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h2 class="h5 mb-0 text-break"><?= esc($medicine['name']) ?></h2>
    <span class="badge <?= (int) $medicine['is_active'] === 1 ? 'bg-success' : 'bg-secondary' ?>"><?= (int) $medicine['is_active'] === 1 ? 'Active' : 'Archived' ?></span>
    <?php if (!empty($medicine['low_stock'])): ?><span class="badge bg-warning text-dark">At / below reorder level</span><?php endif; ?>
  </div>
  <dl class="row mb-0">
    <?php foreach (['generic_name' => 'Generic name', 'category' => 'Category', 'strength' => 'Strength', 'dosage_form' => 'Dosage form', 'unit_of_measure' => 'Stock unit'] as $field => $label): ?>
      <dt class="col-sm-3 col-lg-2"><?= esc($label) ?></dt><dd class="col-sm-9 col-lg-10 text-break"><?= esc($medicine[$field] ?? 'Not specified') ?></dd>
    <?php endforeach; ?>
    <dt class="col-sm-3 col-lg-2">Reorder level</dt><dd class="col-sm-9 col-lg-10"><?= number_format((int) $medicine['minimum_stock_level']) ?> <?= esc($medicine['unit_of_measure']) ?></dd>
  </dl>
  <p class="small text-muted mb-0">Every quantity below is a whole stock unit (<?= esc($medicine['unit_of_measure']) ?>), not a fractional dose. Archived medicines cannot be received or dispensed.</p>
</div></div>
<div class="row g-3 mb-3">
  <?php foreach (['usable_stock' => 'Usable stock', 'total_stock' => 'Physical on hand', 'expired_stock' => 'Expired stock', 'quarantined_stock' => 'Quarantined stock'] as $field => $label): ?>
    <div class="col-sm-6 col-xl-3"><div class="card h-100 mb-0"><div class="card-body pt-3">
      <h3 class="h6 text-muted"><?= esc($label) ?></h3>
      <div class="fs-3 fw-semibold"><?= number_format((int) $medicine[$field]) ?></div>
      <small class="text-muted"><?= esc($medicine['unit_of_measure']) ?></small>
    </div></div></div>
  <?php endforeach; ?>
</div>
<p class="small text-muted mb-4">Usable stock expiring within 90 days: <strong><?= number_format((int) $medicine['near_expiry_stock']) ?> <?= esc($medicine['unit_of_measure']) ?></strong>. Usable and blocked stock are subsets of physical stock; expired and quarantined subsets may overlap, and near-expiry stock is part of usable stock. Do not sum these figures.</p>
<div class="card mb-4" id="inventory-batches">
  <div class="card-header bg-transparent py-3"><h2 class="h5 mb-1">Batches</h2><p class="small text-muted mb-0">Ordered by expiry date. Received is cumulative receipts for the lot; remaining is physical stock.</p></div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <caption class="visually-hidden">All batches, quantities in <?= esc($medicine['unit_of_measure']) ?></caption>
      <thead><tr><th scope="col" class="ps-3">Lot / batch</th><th scope="col">Full expiry date</th><th scope="col">Status</th><th scope="col" class="text-end">Received</th><th scope="col" class="text-end">Remaining</th><?php if ($canManage): ?><th scope="col" class="text-end pe-3">Actions</th><?php endif; ?></tr></thead>
      <tbody>
        <?php if ($batches === []): ?><tr><td colspan="<?= $canManage ? 6 : 5 ?>" class="text-center py-4 text-muted">No batches have been received.</td></tr><?php endif; ?>
        <?php foreach ($batches as $batch): ?>
          <tr id="inventory-batch-<?= (int) $batch['id'] ?>">
            <th scope="row" class="ps-3 fw-normal text-break"><?= esc($batch['batch_number']) ?><small class="d-block text-muted">Batch #<?= (int) $batch['id'] ?></small></th>
            <td><?= esc($batch['expiration_date']) ?><?php if ($batch['expiration_date'] < date('Y-m-d')): ?><span class="badge bg-danger d-block">Expired</span><?php endif; ?></td>
            <td><span class="badge <?= $batch['status'] === 'quarantined' ? 'bg-warning text-dark' : 'bg-success' ?>"><?= esc(ucfirst($batch['status'])) ?></span></td>
            <td class="text-end"><?= number_format((int) $batch['initial_quantity']) ?></td>
            <td class="text-end"><?= number_format((int) $batch['remaining_quantity']) ?></td>
            <?php if ($canManage): ?><td class="text-end pe-3"><div class="d-flex flex-wrap gap-2 justify-content-end">
              <?php if ((int) $batch['remaining_quantity'] > 0): ?><a href="<?= esc(site_url('/clinic/inventory/batches/' . (int) $batch['id'] . '/adjust')) ?>" class="btn btn-outline-danger btn-sm" data-app-modal-open>Remove Stock</a><?php endif; ?>
              <a href="<?= esc(site_url('/clinic/inventory/batches/' . (int) $batch['id'] . '/status')) ?>" class="btn btn-outline-secondary btn-sm" data-app-modal-open><?= $batch['status'] === 'quarantined' ? 'Review Quarantine' : 'Quarantine / Status' ?></a>
            </div></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="card mb-4" id="inventory-operations">
  <div class="card-header bg-transparent py-3"><h2 class="h5 mb-1">Latest operations</h2><p class="small text-muted mb-0">Latest 100 operations only, newest first; independent of the movement page. One correction per dispensation. Legacy allocations with a null operation ID cannot be corrected.</p></div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <caption class="visually-hidden">Latest 100 inventory operations and correction references</caption>
      <thead><tr><th scope="col" class="ps-3">Operation / date</th><th scope="col">Source / reference</th><th scope="col">Reason</th><th scope="col">Actor user ID</th><th scope="col" class="pe-3">Correction</th></tr></thead>
      <tbody>
        <?php if ($operations === []): ?><tr><td colspan="5" class="text-center py-4 text-muted">No ledger operations recorded. Legacy balances may still appear in movements.</td></tr><?php endif; ?>
        <?php foreach ($operations as $operation): ?>
          <tr id="inventory-operation-<?= (int) $operation['id'] ?>">
            <th scope="row" class="ps-3 fw-normal">#<?= (int) $operation['id'] ?> · <?= esc(ucfirst($operation['type'])) ?><small class="d-block text-muted"><?= esc($operation['created_at']) ?></small></th>
            <td class="text-break">
              <?php if ($operation['type'] === 'receipt'): ?>
                <div>Supplier: <?= esc($operation['supplier'] ?? 'Not provided') ?></div>
                <small class="text-muted">Reference: <?= esc($operation['reference'] ?? 'Not provided') ?></small>
              <?php elseif ($operation['encounter_id'] !== null): ?>
                Encounter #<?= (int) $operation['encounter_id'] ?>
              <?php else: ?><span class="text-muted">Inventory ledger</span><?php endif; ?>
            </td>
            <td class="text-break"><?= esc($operation['reason']) ?></td>
            <td>#<?= (int) $operation['actor_id'] ?></td>
            <td class="pe-3">
              <?php if ($operation['type'] === 'reversal'): ?>
                <div><?= $operation['reversal_mode'] === 'restore_unissued' ? 'Restored all unissued units' : 'Record only — stock unchanged' ?></div>
                <?php if (isset($operationIds[(int) $operation['reversal_of']])): ?>
                  <a href="#inventory-operation-<?= (int) $operation['reversal_of'] ?>">Original #<?= (int) $operation['reversal_of'] ?></a>
                <?php else: ?><small class="text-muted">Original #<?= (int) $operation['reversal_of'] ?> (outside latest 100)</small><?php endif; ?>
              <?php elseif ($operation['type'] === 'dispense' && $operation['reversed_by_operation_id'] !== null): ?>
                <div>Corrected · <?= $operation['correction_mode'] === 'restore_unissued' ? 'Unissued stock restored' : 'Record only' ?></div>
                <?php if (isset($operationIds[(int) $operation['reversed_by_operation_id']])): ?>
                  <a href="#inventory-operation-<?= (int) $operation['reversed_by_operation_id'] ?>">Correction #<?= (int) $operation['reversed_by_operation_id'] ?></a>
                <?php else: ?><small class="text-muted">Correction #<?= (int) $operation['reversed_by_operation_id'] ?> (outside latest 100)</small><?php endif; ?>
              <?php elseif ($operation['type'] === 'dispense'): ?>
                <?php if ($canManage): ?><a href="<?= esc(site_url('/clinic/inventory/operations/' . (int) $operation['id'] . '/reverse')) ?>" class="btn btn-outline-danger btn-sm" data-app-modal-open>Review Correction</a><?php else: ?><span class="text-muted">Not corrected</span><?php endif; ?>
              <?php else: ?><span class="text-muted">Not applicable</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="card" id="inventory-movements">
  <div class="card-header bg-transparent py-3"><h2 class="h5 mb-1">Stock movements</h2><p class="small text-muted mb-0">Page <?= $page ?> · Up to 100 movements per page, newest first. Signed quantities use <?= esc($medicine['unit_of_measure']) ?>; zero indicates no physical stock change.</p></div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <caption class="visually-hidden">Paginated stock movement ledger in <?= esc($medicine['unit_of_measure']) ?></caption>
      <thead><tr><th scope="col" class="ps-3">Movement / date</th><th scope="col">Batch</th><th scope="col" class="text-end">Signed quantity</th><th scope="col">Actor user ID</th><th scope="col">Reason</th><th scope="col" class="pe-3">Source reference</th></tr></thead>
      <tbody>
        <?php if ($movements === []): ?><tr><td colspan="6" class="text-center py-4 text-muted">No movements on this page.</td></tr><?php endif; ?>
        <?php foreach ($movements as $movement): ?>
          <tr id="inventory-movement-<?= (int) $movement['id'] ?>">
            <th scope="row" class="ps-3 fw-normal">#<?= (int) $movement['id'] ?><small class="d-block text-muted"><?= esc($movement['created_at']) ?></small></th>
            <td><a href="#inventory-batch-<?= (int) $movement['batch_id'] ?>"><?= esc($batchNumbers[(int) $movement['batch_id']] ?? ('Batch #' . (int) $movement['batch_id'])) ?></a></td>
            <td class="text-end text-nowrap fw-semibold"><?= (int) $movement['delta'] > 0 ? '+' : '' ?><?= number_format((int) $movement['delta']) ?></td>
            <td><?= $movement['actor_id'] === null ? 'Not recorded (legacy)' : '#' . (int) $movement['actor_id'] ?></td>
            <td class="text-break"><?= esc($movement['reason']) ?></td>
            <td class="pe-3">
              <?php if ($movement['operation_id'] === null): ?>
                <span class="text-muted small">Legacy opening balance — no operation ID; correction unavailable</span>
              <?php elseif (isset($operationIds[(int) $movement['operation_id']])): ?>
                <a href="#inventory-operation-<?= (int) $movement['operation_id'] ?>">Operation #<?= (int) $movement['operation_id'] ?></a>
              <?php else: ?><span class="text-muted small">Operation #<?= (int) $movement['operation_id'] ?> (outside latest 100)</span><?php endif; ?>
              <?php if ($movement['original_movement_id'] !== null): ?>
                <div class="small">
                  <?php if (isset($movementIds[(int) $movement['original_movement_id']])): ?>
                    <a href="#inventory-movement-<?= (int) $movement['original_movement_id'] ?>">Original movement #<?= (int) $movement['original_movement_id'] ?></a>
                  <?php else: ?>Original movement #<?= (int) $movement['original_movement_id'] ?> (on another page)<?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
    <span class="small text-muted"><?= number_format(count($movements)) ?> movements on page <?= $page ?></span>
    <nav aria-label="Stock movement pages"><ul class="pagination pagination-sm mb-0">
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
        <?php if ($page > 1): ?><a class="page-link" href="<?= esc(site_url('/clinic/inventory/' . $id) . '?page=' . ($page - 1) . '#inventory-movements') ?>" rel="prev">Previous</a><?php else: ?><span class="page-link" aria-disabled="true">Previous</span><?php endif; ?>
      </li>
      <li class="page-item active"><span class="page-link" aria-current="page"><?= $page ?></span></li>
      <li class="page-item <?= empty($hasMoreMovements) ? 'disabled' : '' ?>">
        <?php if (!empty($hasMoreMovements)): ?><a class="page-link" href="<?= esc(site_url('/clinic/inventory/' . $id) . '?page=' . ($page + 1) . '#inventory-movements') ?>" rel="next">Next</a><?php else: ?><span class="page-link" aria-disabled="true">Next</span><?php endif; ?>
      </li>
    </ul></nav>
  </div>
</div>
<?= $this->endSection() ?>

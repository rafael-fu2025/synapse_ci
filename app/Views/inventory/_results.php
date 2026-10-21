<?php
$inventoryRows = $medicines ?? [];
$inventoryFiltered = (int) ($pagination['filtered'] ?? count($inventoryRows));
$inventoryCounts = ($counts ?? []) + ['low' => 0, 'expired' => 0, 'quarantined' => 0, 'near' => 0];
// Legacy callers supply the complete filtered list without pagination or aggregate counts.
if (!isset($counts) && !isset($pagination)) {
    foreach ($inventoryRows as $inventoryRow) {
        $inventoryCounts['low'] += !empty($inventoryRow['low_stock']) ? 1 : 0;
        $inventoryCounts['expired'] += (int) ($inventoryRow['expired_stock'] ?? 0) > 0 ? 1 : 0;
        $inventoryCounts['quarantined'] += (int) ($inventoryRow['quarantined_stock'] ?? 0) > 0 ? 1 : 0;
        $inventoryCounts['near'] += (int) ($inventoryRow['near_expiry_stock'] ?? 0) > 0 ? 1 : 0;
    }
}
?>
<h2 class="h6">Current filtered results · <?= number_format($inventoryFiltered) ?> medicines</h2>
<div class="row g-3 mb-3">
  <?php foreach (['low' => 'Medicines at / below reorder level', 'expired' => 'Medicines with expired stock', 'quarantined' => 'Medicines with quarantined stock', 'near' => 'Medicines with usable stock expiring within 90 days'] as $key => $label): ?>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100 mb-0"><div class="card-body pt-3">
        <div class="text-muted small"><?= esc($label) ?></div>
        <div class="fs-3 fw-semibold"><?= number_format((int) $inventoryCounts[$key]) ?></div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>
<p class="small text-muted">Counts describe all filtered medicines across all pages, not global totals. A medicine can appear in multiple alert counts. Quantities across different stock units are not added together.</p>
<div class="card">
  <div class="card-header bg-transparent py-3"><h2 class="h5 mb-0">Medicines <span class="badge bg-secondary"><?= number_format($inventoryFiltered) ?></span></h2></div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <caption class="visually-hidden">Filtered medicine catalog with usable and physical stock in each medicine's stock unit</caption>
      <thead><tr><th scope="col" class="ps-3">Medicine</th><th scope="col">Strength / form</th><th scope="col">Stock unit</th><th scope="col" class="text-end">Usable</th><th scope="col" class="text-end">On hand</th><th scope="col">Alerts</th><th scope="col" class="text-end pe-3">Actions</th></tr></thead>
      <tbody>
        <?php if ($inventoryRows === []): ?><tr><td colspan="7" class="text-center py-5 text-muted">No medicines match these filters. Try another name or reset the filter.</td></tr><?php endif; ?>
        <?php foreach ($inventoryRows as $medicine): ?>
          <?php $id = (int) $medicine['id']; ?>
          <tr>
            <th scope="row" class="ps-3 fw-normal text-break">
              <a href="<?= esc(site_url('/clinic/inventory/' . $id)) ?>" class="fw-semibold"><?= esc($medicine['name']) ?></a>
              <small class="d-block text-muted">Generic: <?= esc($medicine['generic_name'] ?? 'Not specified') ?></small>
              <span class="badge <?= (int) $medicine['is_active'] === 1 ? 'bg-success' : 'bg-secondary' ?>"><?= (int) $medicine['is_active'] === 1 ? 'Active' : 'Archived' ?></span>
            </th>
            <td class="text-break"><?= esc($medicine['strength'] ?? 'Not specified') ?><small class="d-block text-muted"><?= esc($medicine['dosage_form']) ?></small></td>
            <td><?= esc($medicine['unit_of_measure']) ?></td>
            <td class="text-end fw-semibold"><?= number_format((int) $medicine['usable_stock']) ?><small class="d-block text-muted">Reorder: <?= number_format((int) $medicine['minimum_stock_level']) ?></small></td>
            <td class="text-end"><?= number_format((int) $medicine['total_stock']) ?></td>
            <td>
              <?php if (!empty($medicine['low_stock'])): ?><span class="badge bg-warning text-dark d-inline-block mb-1">Low stock</span><?php endif; ?>
              <?php if ((int) $medicine['expired_stock'] > 0): ?><span class="badge bg-danger d-inline-block mb-1">Expired: <?= number_format((int) $medicine['expired_stock']) ?></span><?php endif; ?>
              <?php if ((int) $medicine['quarantined_stock'] > 0): ?><span class="badge bg-secondary d-inline-block mb-1">Quarantined: <?= number_format((int) $medicine['quarantined_stock']) ?></span><?php endif; ?>
              <?php if ((int) $medicine['near_expiry_stock'] > 0): ?><span class="badge bg-warning text-dark d-inline-block mb-1">Within 90 days: <?= number_format((int) $medicine['near_expiry_stock']) ?></span><?php endif; ?>
              <?php if (empty($medicine['low_stock']) && (int) $medicine['expired_stock'] === 0 && (int) $medicine['quarantined_stock'] === 0 && (int) $medicine['near_expiry_stock'] === 0): ?><span class="text-muted small">No stock alerts</span><?php endif; ?>
            </td>
            <td class="text-end pe-3"><div class="d-flex flex-wrap gap-2 justify-content-end">
              <a href="<?= esc(site_url('/clinic/inventory/' . $id)) ?>" class="btn btn-outline-primary btn-sm">Details</a>
              <?php if (!empty($canManage)): ?><a href="<?= esc(site_url('/clinic/inventory/' . $id . '/edit')) ?>" class="btn btn-outline-secondary btn-sm" data-app-modal-open>Edit</a><?php endif; ?>
              <?php if (!empty($canWrite) && (int) $medicine['is_active'] === 1): ?><a href="<?= esc(site_url('/clinic/inventory/' . $id . '/receive')) ?>" class="btn btn-primary btn-sm" data-app-modal-open>Receive</a><?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?= view('partials/table_pagination', [
    'pagination' => $pagination ?? null, 'rowCount' => count($inventoryRows),
    'filters' => ($filters ?? []) + ['q' => '', 'status' => 'all'],
    'baseUrl' => $baseUrl ?? site_url('/clinic/inventory'),
    'totalLabel' => 'all medicines', 'paginationLabel' => 'Medicine catalog pages',
], ['saveData' => false]) ?>

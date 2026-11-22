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
$kpiDefinitions = [
    'low' => [
        'label' => 'Reorder alert',
        'sub' => 'At or below reorder level',
        'icon' => 'bi-exclamation-triangle',
        'tone' => 'amber',
    ],
    'expired' => [
        'label' => 'Expired stock',
        'sub' => 'Medicines with expired lots',
        'icon' => 'bi-calendar-x',
        'tone' => 'crimson',
    ],
    'quarantined' => [
        'label' => 'Quarantined',
        'sub' => 'Medicines with blocked lots',
        'icon' => 'bi-shield-exclamation',
        'tone' => 'slate',
    ],
    'near' => [
        'label' => 'Expiring within 90 days',
        'sub' => 'Usable lots nearing expiry',
        'icon' => 'bi-clock-history',
        'tone' => 'amber',
    ],
];
$todayIso = date('Y-m-d');
$nearIso = date('Y-m-d', strtotime('+90 days'));
?>
<div class="inv-results-stack">
  <div class="inv-kpi-section">
    <h2 class="visually-hidden">Current filtered results · <?= number_format($inventoryFiltered) ?> medicines</h2>
    <div class="inv-kpi-grid">
      <?php foreach ($kpiDefinitions as $key => $meta): ?>
        <?php $metricValue = (int) $inventoryCounts[$key]; ?>
        <div class="inv-kpi-card" data-tone="<?= esc($meta['tone']) ?>" data-active="<?= $metricValue > 0 ? 'true' : 'false' ?>">
          <div class="inv-kpi-head">
            <div class="inv-kpi-label"><?= esc($meta['label']) ?></div>
            <span class="inv-kpi-icon" aria-hidden="true"><i class="bi <?= esc($meta['icon']) ?>"></i></span>
          </div>
          <div class="inv-kpi-metric-row">
            <div class="inv-kpi-value fs-3"><?= number_format($metricValue) ?></div>
          </div>
          <div class="inv-kpi-sub"><?= esc($meta['sub']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="inv-scope-note">Current filtered results · <?= number_format($inventoryFiltered) ?> <?= $inventoryFiltered === 1 ? 'medicine' : 'medicines' ?>. Alert counts span all filtered pages, not global totals; a medicine can appear in multiple counts, and quantities across different stock units are not summed.</p>
  </div>

  <div class="inv-card">
    <div class="inv-card-header">
      <div class="inv-card-header-stack">
        <div class="inv-card-title-row">
          <h2 class="inv-card-title">Medicines</h2>
          <span class="inv-card-count"><?= number_format($inventoryFiltered) ?></span>
        </div>
      </div>
    </div>
    <div class="inv-table-wrap">
      <table class="table align-middle mb-0 inventory-table inv-table" data-mobile-cards="false">
        <caption class="visually-hidden">Filtered medicine catalog with usable and physical stock in each medicine's stock unit</caption>
        <thead>
          <tr>
            <th scope="col">Medicine</th>
            <th scope="col">Strength / form</th>
            <th scope="col">Stock unit</th>
            <th scope="col" class="text-end">Usable</th>
            <th scope="col" class="text-end">On hand</th>
            <th scope="col">Earliest expiry</th>
            <th scope="col">Alerts</th>
            <th scope="col" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($inventoryRows === []): ?>
            <tr class="inv-empty-row">
              <td colspan="8" class="inv-empty-cell">No medicines match these filters. Try another name or reset the filter.</td>
            </tr>
          <?php endif; ?>
          <?php foreach ($inventoryRows as $medicine): ?>
            <?php
              $id = (int) $medicine['id'];
              $isActive = (int) $medicine['is_active'] === 1;
              $usable = (int) $medicine['usable_stock'];
              $total = (int) $medicine['total_stock'];
              $reorder = (int) $medicine['minimum_stock_level'];
              $blocked = max(0, $total - $usable);
              $nextExpIso = !empty($medicine['next_expiration_date']) ? (string) $medicine['next_expiration_date'] : null;
              $expiredOnlyIso = !empty($medicine['earliest_expired_date']) ? (string) $medicine['earliest_expired_date'] : null;
              $expIsNear = $nextExpIso !== null && $nextExpIso >= $todayIso && $nextExpIso <= $nearIso;

              if (!$isActive) {
                  $barPct = 0;
                  $barTone = 'muted';
              } elseif ($usable <= 0) {
                  $barPct = 0;
                  $barTone = 'crimson';
              } elseif ($reorder > 0) {
                  $barPct = min(100, (int) round(($usable / max(1, $reorder * 2)) * 100));
                  $barTone = !empty($medicine['low_stock']) ? 'amber' : 'emerald';
              } else {
                  $barPct = 100;
                  $barTone = 'emerald';
              }
            ?>
            <tr class="inv-row">
              <th scope="row" class="fw-normal text-break inv-med-cell inv-cell-header">
                <div class="inv-med-top">
                  <a href="<?= esc(site_url('/clinic/inventory/' . $id)) ?>" class="inv-med-link"><?= esc($medicine['name']) ?></a>
                </div>
                <small class="inv-med-generic">Generic: <?= esc($medicine['generic_name'] ?? 'Not specified') ?></small>
                <div class="inv-med-status-row">
                  <span class="inv-status-inline <?= $isActive ? 'inv-status-inline--active' : 'inv-status-inline--archived' ?>">
                    <span class="inv-dot" aria-hidden="true"></span><?= $isActive ? 'Active' : 'Archived' ?>
                  </span>
                </div>
              </th>
              <td class="text-break">
                <span class="inv-mobile-label">Strength / form</span>
                <div class="inv-cell-primary"><?= esc($medicine['strength'] ?? 'Not specified') ?></div>
                <small class="inv-cell-sub"><?= esc($medicine['dosage_form']) ?></small>
              </td>
              <td>
                <span class="inv-mobile-label">Stock unit</span>
                <span class="inv-unit-code"><?= esc($medicine['unit_of_measure']) ?></span>
              </td>
              <td class="text-end inv-stock-cell">
                <span class="inv-mobile-label">Usable</span>
                <div class="inv-stock-num"><?= number_format($usable) ?></div>
                <div class="inv-stock-track" aria-hidden="true">
                  <span class="inv-stock-fill" data-tone="<?= esc($barTone) ?>" style="width: <?= $barPct ?>%;"></span>
                </div>
                <small class="inv-cell-sub">Reorder: <?= number_format($reorder) ?></small>
              </td>
              <td class="text-end">
                <span class="inv-mobile-label">On hand</span>
                <div class="inv-stock-num inv-stock-num--muted"><?= number_format($total) ?></div>
                <?php if ($blocked > 0): ?>
                  <small class="inv-cell-sub"><?= number_format($blocked) ?> blocked</small>
                <?php endif; ?>
              </td>
              <td class="inv-expiry-cell">
                <span class="inv-mobile-label">Earliest expiry</span>
                <?php if ($nextExpIso !== null): ?>
                  <div class="inv-expiry-iso"><?= esc($nextExpIso) ?></div>
                  <small class="inv-cell-sub"><?= esc(date('M j, Y', strtotime($nextExpIso))) ?></small>
                  <?php if ($expIsNear): ?>
                    <div class="inv-expiry-flag">
                      <span class="inv-status-inline inv-status-inline--warn"><span class="inv-dot" aria-hidden="true"></span>Within 90 days</span>
                    </div>
                  <?php endif; ?>
                <?php elseif ($expiredOnlyIso !== null): ?>
                  <div class="inv-expiry-iso inv-expiry-iso--danger"><?= esc($expiredOnlyIso) ?></div>
                  <small class="inv-cell-sub"><?= esc(date('M j, Y', strtotime($expiredOnlyIso))) ?></small>
                  <div class="inv-expiry-flag">
                    <span class="inv-status-inline inv-status-inline--danger"><span class="inv-dot" aria-hidden="true"></span>Expired lot only</span>
                  </div>
                <?php else: ?>
                  <span class="inv-no-alerts">No active lots</span>
                <?php endif; ?>
              </td>
              <td class="inv-alerts-cell inv-cell-full">
                <span class="inv-mobile-label">Alerts</span>
                <?php
                  $hasAlert = !empty($medicine['low_stock'])
                      || (int) $medicine['expired_stock'] > 0
                      || (int) $medicine['quarantined_stock'] > 0
                      || (int) $medicine['near_expiry_stock'] > 0;
                ?>
                <?php if ($hasAlert): ?>
                  <div class="inv-alert-stack">
                    <?php if (!empty($medicine['low_stock'])): ?>
                      <span class="inv-status-inline inv-status-inline--warn"><span class="inv-dot" aria-hidden="true"></span>Low stock</span>
                    <?php endif; ?>
                    <?php if ((int) $medicine['expired_stock'] > 0): ?>
                      <span class="inv-status-inline inv-status-inline--danger"><span class="inv-dot" aria-hidden="true"></span>Expired: <?= number_format((int) $medicine['expired_stock']) ?></span>
                    <?php endif; ?>
                    <?php if ((int) $medicine['quarantined_stock'] > 0): ?>
                      <span class="inv-status-inline inv-status-inline--quarantined"><span class="inv-dot" aria-hidden="true"></span>Quarantined: <?= number_format((int) $medicine['quarantined_stock']) ?></span>
                    <?php endif; ?>
                    <?php if ((int) $medicine['near_expiry_stock'] > 0): ?>
                      <span class="inv-status-inline inv-status-inline--warn"><span class="inv-dot" aria-hidden="true"></span>Within 90 days: <?= number_format((int) $medicine['near_expiry_stock']) ?></span>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <span class="inv-no-alerts">No stock alerts</span>
                <?php endif; ?>
              </td>
              <td class="text-end inv-cell-actions">
                <div class="inv-row-actions">
                  <a href="<?= esc(site_url('/clinic/inventory/' . $id)) ?>" class="btn btn-outline-secondary btn-sm">Details</a>
                  <?php if (!empty($canManage)): ?>
                    <a href="<?= esc(site_url('/clinic/inventory/' . $id . '/edit')) ?>" class="btn btn-outline-secondary btn-sm" data-app-modal-open>Edit</a>
                  <?php endif; ?>
                  <?php if (!empty($canWrite) && $isActive): ?>
                    <a href="<?= esc(site_url('/clinic/inventory/' . $id . '/receive')) ?>" class="btn btn-primary btn-sm" data-app-modal-open>Receive</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="inv-pagination-band">
      <?= view('partials/table_pagination', [
          'pagination' => $pagination ?? null, 'rowCount' => count($inventoryRows),
          'filters' => ($filters ?? []) + ['q' => '', 'status' => 'all'],
          'baseUrl' => $baseUrl ?? site_url('/clinic/inventory'),
          'totalLabel' => 'all medicines', 'paginationLabel' => 'Medicine catalog pages',
      ], ['saveData' => false]) ?>
    </div>
  </div>
</div>

<?php
$can = static fn (string $permission): bool => ($actor['role'] ?? '') === 'superadmin' || in_array($permission, $actor['permissions'] ?? [], true);
$labels = ['submitted' => 'Submitted', 'acknowledged' => 'Acknowledged', 'under_review' => 'Under review', 'converted_to_case' => 'Case linked', 'closed' => 'Closed'];
?>
<div data-table-content>
<div class="card"><div class="table-responsive">
<table class="table table-hover align-middle mb-0 small">
  <thead><tr><th class="ps-3">Referral</th><th>Student / employee</th><th>Route</th><th>Priority</th><th>Shared handoff</th><th>Status</th><th>Sent by</th><th class="pe-3">Actions</th></tr></thead>
  <tbody>
  <?php if ($referrals === []): ?><tr><td colspan="8" class="text-center text-muted p-4">No referrals match this folder and search.</td></tr><?php endif; ?>
  <?php foreach ($referrals as $ref): ?>
    <?php
    $receiving = $ref['target_module'] === $unit;
    $linked = $ref['has_target'] ?? (!empty($ref['target_encounter_id']) || !empty($ref['target_case_id']));
    $canHandoff = $receiving && $can('referrals.review') && $can($unit === 'clinic' ? 'clinic.encounters.create' : 'guidance.cases.create') && $can($unit === 'clinic' ? 'clinic.encounters.read' : 'guidance.cases.read');
    $base = site_url('/' . $unit . '/referrals/' . (int) $ref['id']);
    ?>
    <tr>
      <td class="ps-3"><strong><?= esc($ref['referral_code']) ?></strong><small class="d-block text-muted"><?= esc($ref['created_at']) ?></small></td>
      <td><strong><?= esc($ref['last_name'] . ', ' . $ref['first_name']) ?></strong><small class="d-block text-muted"><?= esc($ref['mis_id']) ?> · <?= esc(ucfirst($ref['kind'])) ?></small></td>
      <td><?= esc(ucfirst($ref['source_module']) . ' → ' . ucfirst($ref['target_module'])) ?></td>
      <td><span class="badge <?= $ref['priority'] === 'crisis' ? 'bg-danger' : ($ref['priority'] === 'urgent' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= esc(ucfirst($ref['priority'])) ?></span></td>
      <td style="min-width:180px;max-width:300px"><strong><?= esc(ucwords(str_replace('_', ' ', $ref['reason_code']))) ?></strong><details class="mt-1"><summary>View shared summary</summary><p class="text-muted mb-0 mt-2" style="white-space:pre-wrap;overflow-wrap:anywhere"><?= esc($ref['reason_description'] ?? '') ?></p></details></td>
      <td><span class="badge <?= $ref['status'] === 'closed' ? 'bg-secondary' : 'bg-primary' ?>"><?= esc($labels[$ref['status']] ?? $ref['status']) ?></span><?php if ($ref['target_module'] === 'clinic' && $linked): ?><small class="d-block text-muted mt-1">Clinic visit linked</small><?php endif; ?></td>
      <td><?= esc($ref['issuer_name'] ?? 'Former staff') ?></td>
      <td class="pe-3"><div class="d-flex flex-wrap gap-2">
        <?php if ($receiving && $ref['status'] === 'submitted' && $can('referrals.acknowledge')): ?>
          <form action="<?= esc($base . '/acknowledge') ?>" method="POST"><?= csrf_field() ?><button class="btn btn-outline-primary btn-sm" type="submit">Acknowledge</button></form>
        <?php endif; ?>
        <?php if ($receiving && $ref['status'] === 'acknowledged' && $can('referrals.review')): ?>
          <form action="<?= esc($base . '/review') ?>" method="POST"><?= csrf_field() ?><button class="btn btn-outline-primary btn-sm" type="submit">Start review</button></form>
        <?php endif; ?>
        <?php if ($canHandoff && !$linked && in_array($ref['status'], ['acknowledged','under_review'], true)): ?>
          <a href="<?= esc($base . '/handoff') ?>" class="btn btn-success btn-sm" data-app-modal-open><?= $unit === 'clinic' ? 'Start / Link Visit' : 'Open / Link Case' ?></a>
        <?php endif; ?>
        <?php if ($receiving && $linked && !empty($ref['can_open_target'])): ?>
          <a class="btn btn-outline-success btn-sm" href="<?= esc(site_url($unit === 'clinic' ? '/clinic/encounters/' . (int) $ref['target_encounter_id'] : '/guidance/cases/' . (int) $ref['target_case_id'])) ?>">Open linked <?= $unit === 'clinic' ? 'visit' : 'case' ?></a>
        <?php endif; ?>
        <?php if ($receiving && in_array($ref['status'], ['acknowledged','under_review','converted_to_case'], true) && $can('referrals.close')): ?>
          <form action="<?= esc($base . '/close') ?>" method="POST" data-confirm="Close this referral handoff? This does not close its linked clinic visit or guidance case." data-confirm-title="Close referral" data-confirm-label="Close referral" data-confirm-tone="warning"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm" type="submit">Close referral</button></form>
        <?php endif; ?>
        <?php if (!$receiving): ?><span class="text-muted">Receiving team manages this handoff</span><?php elseif ($ref['status'] === 'closed' && !$linked): ?><span class="text-muted">Closed</span><?php elseif ($ref['status'] === 'converted_to_case' && !$linked): ?><span class="text-muted">Legacy case conversion—review linkage</span><?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div><div class="card-body pt-3">
<?= view('partials/table_pagination', ['pagination' => $pagination, 'filters' => $filters, 'baseUrl' => $baseUrl, 'paginationExtraQuery' => ['tab' => $tab], 'totalLabel' => 'referrals in this folder', 'paginationLabel' => 'Referral pages'], ['saveData' => false]) ?>
</div></div>
</div>

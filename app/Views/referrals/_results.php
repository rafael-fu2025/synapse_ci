<?php
$can = static fn (string $permission): bool => ($actor['role'] ?? '') === 'superadmin' || in_array($permission, $actor['permissions'] ?? [], true);
$labels = \App\Services\Referrals\ReferralService::LIST_STATUS_LABELS;
$statusTones = [
    'submitted' => 'submitted',
    'acknowledged' => 'acknowledged',
    'under_review' => 'review',
    'converted_to_case' => 'linked',
    'closed' => 'closed',
];
?>
<?php
$folderCountsAttr = isset($counts) && is_array($counts)
    ? ' data-folder-counts="' . esc(json_encode(array_map('intval', $counts), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') . '"'
    : '';
?>
<div data-table-content<?= $folderCountsAttr ?>>
  <div class="rw-table-wrap">
    <table class="table align-middle mb-0 referral-table rw-table" data-no-mobile-cards="true">
      <thead>
        <tr>
          <th scope="col">Referral &amp; route</th>
          <th scope="col">Student / employee</th>
          <th scope="col">Priority</th>
          <th scope="col">Shared handoff</th>
          <th scope="col">Status</th>
          <th scope="col" class="rw-col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($referrals === []): ?>
        <tr class="rw-empty-row">
          <td colspan="6" class="rw-empty-cell">
            <div class="rw-empty-state">
              <div class="rw-empty-icon"><i class="bi bi-inbox" aria-hidden="true"></i></div>
              <strong>No referrals found</strong>
              <p class="mb-0">No referrals match this folder and search.</p>
            </div>
          </td>
        </tr>
      <?php endif; ?>
      <?php foreach ($referrals as $ref): ?>
        <?php
        $receiving = $ref['target_module'] === $unit;
        $linked = $ref['has_target'] ?? (!empty($ref['target_encounter_id']) || !empty($ref['target_case_id']));
        $canHandoff = $receiving && $can('referrals.review') && $can($unit === 'clinic' ? 'clinic.encounters.create' : 'guidance.cases.create') && $can($unit === 'clinic' ? 'clinic.encounters.read' : 'guidance.cases.read');
        $base = site_url('/' . $unit . '/referrals/' . (int) $ref['id']);
        $priorityTone = match ($ref['priority'] ?? 'routine') {
            'crisis' => 'crisis',
            'urgent' => 'urgent',
            default => 'routine',
        };
        $statusTone = $statusTones[$ref['status'] ?? ''] ?? 'closed';
        $formattedDate = (string) ($ref['created_at'] ?? '');
        $createdTime = strtotime($formattedDate);
        $displayDate = $createdTime ? date('M j, Y · g:i A', $createdTime) : $formattedDate;
        ?>
        <tr class="rw-row" data-rw-priority="<?= esc($priorityTone, 'attr') ?>" data-rw-status="<?= esc($statusTone, 'attr') ?>">
          <td class="rw-cell-ref">
            <div class="rw-ref-code-row">
              <strong class="rw-code"><?= esc($ref['referral_code']) ?></strong>
              <span class="rw-route-chip"><?= esc(ucfirst($ref['source_module']) . ' → ' . ucfirst($ref['target_module'])) ?></span>
            </div>
            <time class="rw-timestamp"><?= esc($displayDate) ?></time>
          </td>

          <td class="rw-cell-person">
            <strong class="rw-person-name"><?= esc($ref['last_name'] . ', ' . $ref['first_name']) ?></strong>
            <div class="rw-person-meta">
              <span class="rw-mis-id"><?= esc($ref['mis_id']) ?></span>
              <span aria-hidden="true">·</span>
              <span><?= esc(ucfirst($ref['kind'])) ?></span>
            </div>
            <span class="rw-issuer">Sent by <?= esc($ref['issuer_name'] ?? 'Former staff') ?></span>
          </td>

          <td class="rw-cell-priority">
            <span class="rw-mobile-label" aria-hidden="true">Priority</span>
            <span class="rw-pill rw-pill--priority" data-tone="<?= esc($priorityTone, 'attr') ?>"><span class="rw-dot" aria-hidden="true"></span><?= esc(ucfirst($ref['priority'])) ?></span>
          </td>

          <td class="referral-handoff-cell rw-cell-handoff">
            <span class="rw-mobile-label" aria-hidden="true">Shared handoff</span>
            <strong class="rw-reason-title"><?= esc(ucwords(str_replace('_', ' ', $ref['reason_code']))) ?></strong>
            <details class="rw-summary-disclosure">
              <summary>View shared summary</summary>
              <p class="mb-0 referral-summary-text"><?= esc($ref['reason_description'] ?? '') ?></p>
            </details>
          </td>

          <td class="rw-cell-status">
            <span class="rw-mobile-label" aria-hidden="true">Status</span>
            <div class="rw-status-stack">
              <span class="rw-pill rw-pill--status" data-tone="<?= esc($statusTone, 'attr') ?>"><span class="rw-dot" aria-hidden="true"></span><?= esc($labels[$ref['status']] ?? $ref['status']) ?></span>
              <?php if ($ref['target_module'] === 'clinic' && $linked): ?>
                <small class="rw-linked-note"><i class="bi bi-link-45deg" aria-hidden="true"></i> Clinic visit linked</small>
              <?php endif; ?>
            </div>
          </td>

          <td class="rw-cell-actions">
            <div class="table-card-actions rw-row-actions">
              <?php if ($receiving && $ref['status'] === 'submitted' && $can('referrals.acknowledge')): ?>
                <form action="<?= esc($base . '/acknowledge') ?>" method="POST" data-background-submit><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit">Acknowledge</button></form>
              <?php endif; ?>
              <?php if ($receiving && $ref['status'] === 'acknowledged' && $can('referrals.review')): ?>
                <form action="<?= esc($base . '/review') ?>" method="POST" data-background-submit><?= csrf_field() ?><button class="btn btn-outline-primary btn-sm" type="submit">Start review</button></form>
              <?php endif; ?>
              <?php if ($canHandoff && !$linked && in_array($ref['status'], ['acknowledged', 'under_review'], true)): ?>
                <a href="<?= esc($base . '/handoff') ?>" class="btn btn-primary btn-sm" data-app-modal-open><?= $unit === 'clinic' ? 'Start / Link Visit' : 'Open / Link Case' ?></a>
              <?php endif; ?>
              <?php if ($receiving && $linked && !empty($ref['can_open_target'])): ?>
                <a class="btn btn-outline-success btn-sm" href="<?= esc(site_url($unit === 'clinic' ? '/clinic/encounters/' . (int) $ref['target_encounter_id'] : '/guidance/cases/' . (int) $ref['target_case_id'])) ?>">Open linked <?= $unit === 'clinic' ? 'visit' : 'case' ?></a>
              <?php endif; ?>
              <?php if ($receiving && in_array($ref['status'], ['acknowledged', 'under_review', 'converted_to_case'], true) && $can('referrals.close')): ?>
                <form action="<?= esc($base . '/close') ?>" method="POST" data-background-submit data-confirm="Close this referral handoff? This does not close its linked clinic visit or guidance case." data-confirm-title="Close referral" data-confirm-label="Close referral" data-confirm-tone="warning"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm" type="submit">Close referral</button></form>
              <?php endif; ?>
              <?php if (!$receiving): ?>
                <span class="rw-muted-state">Receiving team manages this handoff</span>
              <?php elseif ($ref['status'] === 'closed' && !$linked): ?>
                <span class="rw-muted-state">Closed</span>
              <?php elseif ($ref['status'] === 'converted_to_case' && !$linked): ?>
                <span class="rw-muted-state">Legacy case conversion—review linkage</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="rw-pagination-band">
    <?= view('partials/table_pagination', ['pagination' => $pagination, 'filters' => $filters, 'baseUrl' => $baseUrl, 'paginationExtraQuery' => ['tab' => $tab], 'totalLabel' => 'referrals in this folder', 'paginationLabel' => 'Referral pages'], ['saveData' => false]) ?>
  </div>
</div>

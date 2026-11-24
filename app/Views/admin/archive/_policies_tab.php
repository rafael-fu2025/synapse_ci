<?php
$policies = $policies ?? [];
$scope = $scope ?? [];
$modules = $modules ?? [];
$immutableLedgers = $immutableLedgers ?? [];
?>
<div class="adm-band d-flex flex-wrap align-items-center justify-content-between gap-2">
  <div>
    <h3 class="adm-intro-title">Retention Schedules</h3>
    <p class="adm-intro-desc mb-0">
      Most specific active rule wins (<code>module + entity_type</code> &rarr; <code>module + *</code> &rarr; <code>all + *</code>).
      Purge remains permanently disabled across all schedules.
    </p>
  </div>
  <?php if (!empty($scope['can_policies'])): ?>
    <button type="button" class="btn btn-primary btn-sm" data-arc-open-policy-modal>
      <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Retention Policy
    </button>
  <?php endif; ?>
</div>

<div class="arc-table-wrap">
  <?php if ($policies === []): ?>
    <div class="arc-empty" role="status">
      <i class="bi bi-calendar2-check" aria-hidden="true"></i>
      <p class="fw-semibold mb-0">No retention policies configured yet.</p>
    </div>
  <?php else: ?>
    <table class="arc-table">
      <thead>
        <tr>
          <th>Code &amp; Title</th>
          <th>Scope</th>
          <th>Retention Term</th>
          <th>Legal / Institutional Basis</th>
          <th>Status</th>
          <?php if (!empty($scope['can_policies'])): ?>
            <th>Actions</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($policies as $pol): $active = (int) ($pol['is_active'] ?? 0) === 1; ?>
          <tr>
            <td>
              <span class="arc-mono fw-semibold"><?= esc($pol['policy_code']) ?></span>
              <span class="arc-ref-title"><?= esc($pol['title']) ?></span>
            </td>
            <td>
              <span class="arc-pill"><?= esc($pol['module'] === 'all' ? 'All modules' : ($modules[$pol['module']] ?? ucfirst((string) $pol['module']))) ?></span>
              <span class="arc-subtext arc-mono">Entity: <?= esc($pol['entity_type']) ?></span>
            </td>
            <td>
              <strong><?= (int) $pol['duration_months'] ?> months</strong>
              <?php if (!empty($pol['auto_archive_after_months'])): ?>
                <span class="arc-subtext">Review archive after <?= (int) $pol['auto_archive_after_months'] ?> mo</span>
              <?php endif; ?>
              <span class="arc-subtext">Purge: <strong>Disabled</strong></span>
            </td>
            <td><?= esc($pol['legal_basis']) ?></td>
            <td>
              <span class="arc-pill <?= $active ? 'is-restored' : '' ?>">
                <?= $active ? 'Active' : 'Inactive' ?>
              </span>
            </td>
            <?php if (!empty($scope['can_policies'])): ?>
              <td>
                <div class="arc-row-actions">
                  <button type="button"
                          class="btn btn-outline-secondary btn-sm"
                          data-arc-open-policy-modal
                          data-arc-policy-id="<?= (int) $pol['id'] ?>"
                          data-arc-policy-code="<?= esc($pol['policy_code'], 'attr') ?>"
                          data-arc-policy-module="<?= esc($pol['module'], 'attr') ?>"
                          data-arc-policy-entity="<?= esc($pol['entity_type'], 'attr') ?>"
                          data-arc-policy-title="<?= esc($pol['title'], 'attr') ?>"
                          data-arc-policy-duration="<?= (int) $pol['duration_months'] ?>"
                          data-arc-policy-auto="<?= esc((string) ($pol['auto_archive_after_months'] ?? ''), 'attr') ?>"
                          data-arc-policy-basis="<?= esc($pol['legal_basis'], 'attr') ?>">
                    Edit
                  </button>
                  <form method="POST" action="<?= esc(site_url('/admin/archive/policies/' . (int) $pol['id'] . '/toggle'), 'attr') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_url" value="/admin/archive?tab=policies">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                      <?= $active ? 'Disable' : 'Activate' ?>
                    </button>
                  </form>
                </div>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="adm-band">
  <h3 class="adm-intro-title">Strictly Immutable Compliance Ledgers</h3>
  <p class="adm-intro-desc">
    These append-only governance and clinical provenance tables are never eligible for archiving or soft-deletion, enforcing tamper-evident history:
  </p>
  <div class="arc-immutable-grid">
    <?php foreach ($immutableLedgers as $ledgerTable => $ledgerDesc): ?>
      <div class="arc-immutable-item">
        <span class="arc-mono fw-semibold d-block"><?= esc($ledgerTable) ?></span>
        <span class="arc-subtext"><?= esc($ledgerDesc) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/archive-workspace.css'), 'attr') ?>?v=<?= @filemtime(FCPATH . 'assets/css/pages/archive-workspace.css') ?: '1' ?>">
<?= $this->endSection() ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <?php if (!empty($scope['can_holds'])): ?>
    <button type="button"
            class="btn btn-outline-primary btn-sm"
            data-arc-open-hold-modal
            data-arc-entity-type=""
            data-arc-entity-id=""
            data-arc-record-ref="">
      <i class="bi bi-shield-lock me-1" aria-hidden="true"></i> Place Legal Hold
    </button>
  <?php endif; ?>
  <?php if (!empty($scope['can_policies']) && ($tab ?? 'records') === 'policies'): ?>
    <button type="button"
            class="btn btn-primary btn-sm"
            data-arc-open-policy-modal>
      <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> New Retention Policy
    </button>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$tab = $tab ?? 'records';
$baseUrl = site_url('/admin/archive');
$kpis = $kpis ?? ['archived_total' => 0, 'active_holds' => 0, 'past_retention_count' => 0, 'restored_30d' => 0, 'by_module' => []];
$scope = $scope ?? ['modules' => [], 'units' => [], 'read_only' => true, 'can_manage' => false, 'can_policies' => false, 'can_holds' => false];

$tabs = [
    'records'  => ['label' => 'Archived Records', 'icon' => 'bi-archive', 'count' => (int) $kpis['archived_total']],
    'holds'    => ['label' => 'Legal Holds', 'icon' => 'bi-shield-lock', 'count' => (int) $kpis['active_holds']],
    'policies' => ['label' => 'Retention Policies', 'icon' => 'bi-calendar2-check', 'count' => null],
    'eligible' => ['label' => 'Archive Eligible', 'icon' => 'bi-inbox', 'count' => null],
];
?>
<section class="adm-workspace arc-workspace" data-archive-workspace>
  <div class="adm-sheet">
    <!-- Intro & Compliance Notice Band -->
    <div class="adm-band">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <h2 class="adm-intro-title">Institutional Archive, Retention &amp; Legal Hold Governance</h2>
          <p class="adm-intro-desc">
            Unified reversible archive directory scoped to your authorized campus modules.
            Clinical and guidance record contents remain confidential; only non-PHI tracking references appear here.
          </p>
        </div>
        <span class="adm-status is-active">
          <span><?= !empty($scope['read_only']) ? 'Read-Only Governance Scope' : 'Reversible Soft-Archive Active' ?></span>
        </span>
      </div>

      <div class="adm-note" role="note">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        <div>
          <strong>Reversible archive only — permanent deletion is disabled.</strong>
          Every archive, restore, legal-hold, and retention policy update writes atomically to the SHA-256 hash-chained audit ledger.
          Append-only compliance ledgers (Audit Events, Medicine Inventory Operations &amp; Movements, and Clinical/Guidance Revisions, Attestations, Consent Receipts, and Document Issuances) can never be archived or removed.
        </div>
      </div>

      <div class="arc-kpi-grid mt-3" aria-label="Archive summary metrics">
        <div class="arc-kpi-card">
          <span class="arc-kpi-label">Archived Records</span>
          <span class="arc-kpi-value" data-arc-kpi="archived_total"><?= number_format((int) $kpis['archived_total']) ?></span>
          <span class="arc-kpi-meta">Currently archived in your scope</span>
        </div>
        <div class="arc-kpi-card">
          <span class="arc-kpi-label">Active Legal Holds</span>
          <span class="arc-kpi-value"><?= number_format((int) $kpis['active_holds']) ?></span>
          <span class="arc-kpi-meta">Protected from archiving &amp; changes</span>
        </div>
        <div class="arc-kpi-card">
          <span class="arc-kpi-label">Past Retention Target</span>
          <span class="arc-kpi-value"><?= number_format((int) $kpis['past_retention_count']) ?></span>
          <span class="arc-kpi-meta">Retained (review only; no auto-purge)</span>
        </div>
        <div class="arc-kpi-card">
          <span class="arc-kpi-label">Restored (30 Days)</span>
          <span class="arc-kpi-value" data-arc-kpi="restored_30d"><?= number_format((int) $kpis['restored_30d']) ?></span>
          <span class="arc-kpi-meta">Recovered back into module lists</span>
        </div>
      </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="arc-tabs-band">
      <nav class="arc-tabs" aria-label="Archive center sections">
        <?php foreach ($tabs as $tabKey => $meta): $isActive = $tab === $tabKey; ?>
          <a href="<?= esc($baseUrl . '?tab=' . $tabKey, 'attr') ?>"
             class="arc-tab<?= $isActive ? ' is-active' : '' ?>"
             <?= $isActive ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= esc($meta['icon'], 'attr') ?>" aria-hidden="true"></i>
            <span><?= esc($meta['label']) ?></span>
            <?php if ($meta['count'] !== null): ?>
              <span class="arc-tab-count"<?= $tabKey === 'records' ? ' data-arc-kpi="archived_total"' : '' ?>><?= number_format((int) $meta['count']) ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
      <div class="small text-muted">
        Scope:
        <?php foreach ($scope['modules'] as $i => $modKey): ?>
          <?= $i > 0 ? ' · ' : '' ?><strong><?= esc($modules[$modKey] ?? ucfirst($modKey)) ?></strong>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($tab === 'holds'): ?>
      <?= $this->include('admin/archive/_holds_tab') ?>
    <?php elseif ($tab === 'policies'): ?>
      <?= $this->include('admin/archive/_policies_tab') ?>
    <?php elseif ($tab === 'eligible'): ?>
      <?= $this->include('admin/archive/_eligible_tab') ?>
    <?php else: ?>
      <?= $this->include('admin/archive/_records_tab') ?>
    <?php endif; ?>
  </div>
</section>

<?= $this->include('admin/archive/_modals') ?>
<?= $this->endSection() ?>

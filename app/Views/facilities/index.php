<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/bmg.css'), 'attr') ?>">
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/bmg-workstation.css'), 'attr') ?>">
<?= $this->endSection() ?>
<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <?php if (!empty($canConfigure)): ?>
  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#unitModal"><i class="bi bi-plus-lg" aria-hidden="true"></i>Add drum</button>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$archivedView = !empty($archivedView);
$perPage = (int) $pagination['per_page'];
?>
<section class="bmg-workspace bmg-workstation ce-workspace" aria-label="BMG drum workstation" data-bmg-base-url="<?= esc(site_url('/facilities'), 'attr') ?>" data-refresh-owner="facilities.list" <?= !empty($openCreate) && !empty($canConfigure) ? 'data-bmg-open-create' : '' ?>>
  <header class="card ce-card bmg-workstation-intro">
    <div class="card-body bmg-intro-body">
      <div class="bmg-intro-copy">
        <span class="bmg-eyebrow">Facilities · BMG workstation</span>
        <h2 class="card-title">Mechanized drum rotation</h2>
        <p class="bmg-caption mb-0">Add a drum, register its controller, review its schedule, then deliberately enable only after safety checks.</p>
      </div>
      <span class="ce-tag"><i class="bi bi-shield-lock" aria-hidden="true"></i> <?= empty($hardwareEnabled) ? 'Motor control disabled' : 'Motor controls permission-gated' ?></span>
    </div>
  </header>

  <div class="row bmg-metrics" aria-label="Drums at a glance" data-refresh-region="stats">
    <?php foreach ([['Total drums', $stats['total'], $archivedView ? 'All archived drum records' : 'All current drum records', 'bi-box-seam'], ['Active units', $stats['active'], 'Configured operational status', 'bi-check-circle'], ['Maintenance', $stats['maintenance'], 'Marked for maintenance', 'bi-tools'], ['Bound controllers', $stats['bound'], 'Not a connection-health count', 'bi-cpu']] as [$label, $value, $description, $icon]): ?>
    <div class="col-sm-6 col-xl-3">
      <div class="card ce-card bmg-stat">
        <div class="card-body">
          <span class="bmg-stat-label"><i class="bi <?= esc($icon, 'attr') ?>" aria-hidden="true"></i><?= esc($label) ?></span>
          <strong class="bmg-stat-number"><?= (int) $value ?></strong>
          <span class="bmg-caption"><?= esc($description) ?></span>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (empty($hardwareEnabled)): ?>
  <div class="ce-notice" role="note"><div class="ce-notice-body"><i class="bi bi-info-circle ce-notice-icon" aria-hidden="true"></i><div><strong>Setup is separate from motor operation.</strong> Hardware control is disabled. Configuration admins can register controllers and review schedule settings; motor commands remain unavailable.</div></div></div>
  <?php elseif (empty($canHardware)): ?>
  <div class="ce-notice ce-notice-sky" role="note"><div class="ce-notice-body"><i class="bi bi-shield-lock ce-notice-icon" aria-hidden="true"></i><div>Your account does not have explicit hardware-control permission. Motor commands are unavailable.</div></div></div>
  <?php endif; ?>

  <?php if (!$archivedView): ?>
  <section class="card ce-card bmg-overview-setup" aria-labelledby="bmg-overview-setup-title">
    <div class="card-header ce-card-header"><h2 class="card-title ce-card-title" id="bmg-overview-setup-title"><i class="bi bi-list-check" aria-hidden="true"></i>Set up a drum safely</h2><span class="ce-tag">No automatic motor operation</span></div>
    <div class="card-body">
      <ol class="bmg-onboarding-list">
        <li><strong>Add the drum</strong><span class="bmg-caption">Save its code, name and capacity. A saved record does not start a motor.</span></li>
        <li><strong>Register and verify the controller</strong><span class="bmg-caption">Open Manage drum for the setup checklist. USB commissioning requires physical motor-power isolation.</span></li>
        <li><strong>Review before enabling</strong><span class="bmg-caption">Check daily times and duration. Permissions and hardware gates still apply; nothing is enabled automatically.</span></li>
      </ol>
    </div>
  </section>
  <?php endif; ?>

  <section class="bmg-directory-section" data-refresh-region="units" aria-label="<?= $archivedView ? 'Archived drum records' : 'Current drum records' ?>">
    <div class="bmg-directory-toolbar">
      <div class="bmg-heading-copy"><h2><?= $archivedView ? 'Archived drums' : 'Drum workspace' ?></h2><p class="bmg-caption mb-0"><?= $archivedView ? 'History is retained. Restoring leaves the drum offline and its controller revoked.' : 'Select a drum to manage its operations' ?></p></div>
      <div class="bmg-directory-controls">
        <?php if (!empty($canConfigure)): ?>
        <nav class="nav nav-pills bmg-record-tabs" aria-label="Drum records">
          <a class="nav-link bmg-record-tab <?= !$archivedView ? 'active' : '' ?>" href="<?= esc(site_url('/facilities'), 'attr') ?>" <?= !$archivedView ? 'aria-current="page"' : '' ?>><i class="bi bi-box-seam" aria-hidden="true"></i><span>Current drums</span></a>
          <a class="nav-link bmg-record-tab <?= $archivedView ? 'active' : '' ?>" href="<?= esc(site_url('/facilities') . '?view=archived', 'attr') ?>" <?= $archivedView ? 'aria-current="page"' : '' ?>><i class="bi bi-archive" aria-hidden="true"></i><span>Archived drums</span></a>
        </nav>
        <?php endif; ?>
        <form method="get" action="<?= esc(site_url('/facilities'), 'attr') ?>" class="bmg-page-size-form">
          <?php if ($archivedView): ?><input type="hidden" name="view" value="archived"><?php endif; ?>
          <label for="drums-per-page" class="form-label mb-0">Drums per page</label>
          <select id="drums-per-page" name="per_page" class="form-select form-select-sm">
            <?php foreach ([20, 25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-outline-secondary btn-sm">Apply</button>
        </form>
      </div>
    </div>
    <?php if ($units === []): ?>
    <div class="card ce-card"><div class="card-body bmg-directory-empty"><i class="bi bi-box-seam" aria-hidden="true"></i><h2 class="card-title"><?= $archivedView ? 'No archived drums' : 'No drums registered' ?></h2><p class="bmg-caption mb-0"><?= $archivedView ? 'Archived records will appear here. No operational history is permanently deleted.' : (!empty($canConfigure) ? 'Use Add drum to set up your first unit. Rotation stays disabled until deliberately configured.' : 'A user with configuration access can register the first drum.') ?></p></div></div>
    <?php else: ?>
    <div class="bmg-drum-grid">
      <?php foreach ($units as $item): ?>
        <?php require __DIR__ . '/_drum_card.php'; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="bmg-directory-pagination">
      <?= view('partials/table_pagination', [
          'pagination' => $pagination, 'baseUrl' => site_url('/facilities'), 'filters' => [],
          'paginationExtraQuery' => $archivedView ? ['view' => 'archived'] : [],
          'paginationLabel' => $archivedView ? 'Archived drum pages' : 'Current drum pages',
      ]) ?>
    </div>
    <?php if (!empty($canConfigure)): $editUnit = null; require __DIR__ . '/_unit_modal.php'; endif; ?>
  </section>
</section>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/bmg.js'), 'attr') ?>"></script>
<script src="<?= esc(base_url('assets/js/bmg-gear.js'), 'attr') ?>"></script>
<?= $this->endSection() ?>

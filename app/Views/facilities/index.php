<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/bmg.css'), 'attr') ?>">
<?= $this->endSection() ?>
<?= $this->section('page_actions') ?>
<?php if (!empty($canConfigure)): ?><button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#unitModal"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add drum</button><?php endif; ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$archivedView = !empty($archivedView);
$perPage = (int) $pagination['per_page'];
?>
<div class="bmg-workspace" data-bmg-base-url="<?= esc(site_url('/facilities'), 'attr') ?>" <?= !empty($openCreate) && !empty($canConfigure) ? 'data-bmg-open-create' : '' ?>>
  <header class="card mb-3">
    <div class="card-body pt-3 d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div>
        <div class="small text-muted">Facilities · BMG workspace</div>
        <h2 class="card-title py-2 mb-0">Mechanized drum rotation</h2>
        <p class="small text-muted mb-0">Add a drum, register its controller, review its schedule, then deliberately enable only after safety checks.</p>
      </div>
      <span class="badge bg-light text-dark border"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i><?= empty($hardwareEnabled) ? 'Motor control disabled' : 'Motor controls permission-gated' ?></span>
    </div>
  </header>
  <div class="row g-3 mb-3" aria-label="Drums at a glance">
    <?php foreach ([['Total drums', $stats['total'], $archivedView ? 'All archived drum records' : 'All current drum records', 'bi-box-seam'], ['Active units', $stats['active'], 'Configured operational status', 'bi-check-circle'], ['Maintenance', $stats['maintenance'], 'Marked for maintenance', 'bi-tools'], ['Bound controllers', $stats['bound'], 'Not a connection-health count', 'bi-cpu']] as [$label, $value, $description, $icon]): ?>
    <div class="col-sm-6 col-xl-3"><div class="card h-100 mb-0"><div class="card-body pt-3">
      <div class="d-flex align-items-center gap-2 mb-2"><i class="bi <?= esc($icon, 'attr') ?> text-primary" aria-hidden="true"></i><span class="small text-muted"><?= esc($label) ?></span></div>
      <strong class="fs-4 d-block"><?= (int) $value ?></strong><span class="small text-muted"><?= esc($description) ?></span>
    </div></div></div>
    <?php endforeach; ?>
  </div>
  <?php if (empty($hardwareEnabled)): ?>
  <div class="alert alert-secondary" role="note"><strong><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Setup is separate from motor operation.</strong> Hardware control is disabled. Configuration admins can register controllers and review schedule settings; motor commands remain unavailable.</div>
  <?php elseif (empty($canHardware)): ?>
  <div class="alert alert-info" role="note">Your account does not have explicit hardware-control permission. Motor commands are unavailable.</div>
  <?php endif; ?>
  <?php if (!$archivedView): ?>
  <div class="card mb-4"><div class="card-body pt-3">
    <h2 class="card-title p-0 mb-3">Set up a drum safely</h2>
    <ol class="row g-3 ps-3 mb-0">
      <li class="col-md-4"><strong class="d-block small">Add the drum</strong><span class="small text-muted">Save its code, name and capacity. A saved record does not start a motor.</span></li>
      <li class="col-md-4"><strong class="d-block small">Register and verify the controller</strong><span class="small text-muted">Open Manage drum for the setup checklist. USB commissioning requires physical motor-power isolation.</span></li>
      <li class="col-md-4"><strong class="d-block small">Review before enabling</strong><span class="small text-muted">Check daily times and duration. Permissions and hardware gates still apply; nothing is enabled automatically.</span></li>
    </ol>
  </div></div>
  <?php endif; ?>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div><h2 class="h5 mb-1"><?= $archivedView ? 'Archived drums' : 'Drum workspace' ?></h2><span class="small text-muted"><?= $archivedView ? 'History is retained. Restoring leaves the drum offline and its controller revoked.' : 'Select a drum to manage its operations' ?></span></div>
    <?php if (!empty($canConfigure)): ?>
    <nav class="d-flex gap-2" aria-label="Drum records">
      <a class="btn btn-sm <?= !$archivedView ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= esc(site_url('/facilities'), 'attr') ?>" <?= !$archivedView ? 'aria-current="page"' : '' ?>>Current drums</a>
      <a class="btn btn-sm <?= $archivedView ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= esc(site_url('/facilities') . '?view=archived', 'attr') ?>" <?= $archivedView ? 'aria-current="page"' : '' ?>>Archived drums</a>
    </nav>
    <?php endif; ?>
  </div>
  <form method="get" action="<?= esc(site_url('/facilities'), 'attr') ?>" class="d-flex align-items-center gap-2 mb-3">
    <?php if ($archivedView): ?><input type="hidden" name="view" value="archived"><?php endif; ?>
    <label for="drums-per-page" class="small text-muted">Drums per page</label>
    <select id="drums-per-page" name="per_page" class="form-select form-select-sm w-auto">
      <?php foreach ([20, 25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline-secondary btn-sm">Apply</button>
  </form>
  <?php if ($units === []): ?>
  <div class="card"><div class="card-body pt-4 pb-4 text-center"><i class="bi bi-box-seam fs-2 text-muted" aria-hidden="true"></i><h2 class="card-title"><?= $archivedView ? 'No archived drums' : 'No drums registered' ?></h2><p class="small text-muted mb-0"><?= $archivedView ? 'Archived records will appear here. No operational history is permanently deleted.' : (!empty($canConfigure) ? 'Use Add drum to set up your first unit. Rotation stays disabled until deliberately configured.' : 'A user with configuration access can register the first drum.') ?></p></div></div>
  <?php else: ?>
  <div class="bmg-drum-grid">
    <?php foreach ($units as $item): ?>
      <?php require __DIR__ . '/_drum_card.php'; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?= view('partials/table_pagination', [
      'pagination' => $pagination, 'baseUrl' => site_url('/facilities'), 'filters' => [],
      'paginationExtraQuery' => $archivedView ? ['view' => 'archived'] : [],
      'paginationLabel' => $archivedView ? 'Archived drum pages' : 'Current drum pages',
  ]) ?>
</div>
<?php if (!empty($canConfigure)): $editUnit = null; require __DIR__ . '/_unit_modal.php'; endif; ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/bmg.js'), 'attr') ?>"></script>
<script src="<?= esc(base_url('assets/js/bmg-gear.js'), 'attr') ?>"></script>
<?= $this->endSection() ?>

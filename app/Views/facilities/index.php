<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/bmg.css'), 'attr') ?>">
<?= $this->endSection() ?>
<?= $this->section('page_actions') ?>
<?php if (!empty($canConfigure)): ?><button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#unitModal"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add drum</button><?php endif; ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$active = count(array_filter($units, static fn (array $item): bool => $item['unit']['status'] === 'active'));
$maintenance = count(array_filter($units, static fn (array $item): bool => $item['unit']['status'] === 'maintenance'));
$boundCount = count(array_filter($units, static fn (array $item): bool => $item['device'] !== null));
?>
<div class="bmg-workspace" data-bmg-base-url="<?= esc(site_url('/facilities'), 'attr') ?>">
  <header class="bmg-hero">
    <div class="d-flex align-items-center gap-3">
      <span class="bmg-icon" aria-hidden="true"><i class="bi bi-arrow-repeat"></i></span>
      <div><div class="bmg-eyebrow">Facilities · BMG workspace</div><h2 class="h4 mb-1">Mechanized drum rotation</h2><p class="bmg-caption mb-0">One place for drum setup, rotation schedules and operational records.</p></div>
    </div>
    <span class="bmg-status-pill <?= empty($hardwareEnabled) ? 'is-muted' : '' ?>"><i class="bi bi-shield-lock" aria-hidden="true"></i><?= empty($hardwareEnabled) ? 'Motor control disabled' : 'Motor controls permission-gated' ?></span>
  </header>
  <div class="bmg-stat-grid" aria-label="Drums at a glance">
    <?php foreach ([['Drums shown', count($units), 'Registered unit records'], ['Active units', $active, 'Configured operational status'], ['Maintenance', $maintenance, 'Marked for maintenance'], ['Bound controllers', $boundCount, 'Not a connection-health count']] as [$label, $value, $description]): ?>
    <div class="bmg-stat"><div class="bmg-stat-label"><?= esc($label) ?></div><div class="bmg-stat-value bmg-stat-number"><?= (int) $value ?></div><div class="bmg-caption"><?= esc($description) ?></div></div>
    <?php endforeach; ?>
  </div>
  <?php if (empty($hardwareEnabled)): ?>
  <div class="bmg-callout bmg-callout-muted" role="note"><strong><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Setup is separate from motor operation.</strong> Hardware control is disabled. Configuration admins can register controllers and review schedule settings; motor commands remain unavailable.</div>
  <?php elseif (empty($canHardware)): ?>
  <div class="bmg-callout" role="note">Your account does not have explicit hardware-control permission. Motor commands are unavailable.</div>
  <?php endif; ?>
  <div class="bmg-section-heading"><h2>Drum workspace</h2><span class="bmg-caption">Select a drum to manage its operations</span></div>
  <?php if ($units === []): ?>
  <div class="bmg-panel"><div class="bmg-empty py-5"><span class="bmg-icon mb-3" aria-hidden="true"><i class="bi bi-box-seam"></i></span><h2 class="h5">No drums registered</h2><p class="bmg-caption mb-0"><?= !empty($canConfigure) ? 'Use Add drum to set up your first unit. Rotation stays disabled until deliberately configured.' : 'A user with configuration access can register the first drum.' ?></p></div></div>
  <?php else: ?>
  <div class="bmg-drum-grid">
    <?php foreach ($units as $item): $drum = $item['unit']; $plan = $item['schedule']; $bound = $item['device']; ?>
    <article class="bmg-panel bmg-drum-card">
      <div class="bmg-panel-heading">
        <div><div class="bmg-eyebrow"><?= esc($drum['unit_code']) ?></div><h3 class="bmg-drum-title"><?= esc($drum['name']) ?></h3></div>
        <span class="bmg-status-pill <?= $drum['status'] === 'active' ? '' : 'is-muted' ?>"><?= esc(ucfirst($drum['status'])) ?></span>
      </div>
      <div class="bmg-panel-body">
        <div class="bmg-schedule-preview">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><span class="small fw-semibold"><i class="bi bi-calendar-week me-1" aria-hidden="true"></i>Daily schedule</span><span class="bmg-status-pill is-muted"><?= !empty($plan['enabled']) ? 'Saved enabled' : 'Saved disabled' ?></span></div>
          <div class="bmg-time-list"><?php if ($plan['times'] === []): ?><span class="bmg-caption">No times saved</span><?php else: foreach ($plan['times'] as $time): ?><span class="bmg-time"><?= esc($time) ?></span><?php endforeach; endif; ?></div>
          <div class="bmg-caption">Asia/Manila · <?= (int) $plan['duration_seconds'] ?> seconds per run · version <?= (int) $plan['version'] ?></div>
        </div>
        <dl class="bmg-fact-grid">
          <div class="bmg-fact"><dt>Capacity</dt><dd><?= esc($drum['drum_capacity_kg']) ?> kg</dd></div>
          <div class="bmg-fact"><dt>Device binding</dt><dd><?= $bound === null ? 'No active device bound' : esc($bound['chip_mac']) ?></dd></div>
          <div class="bmg-fact"><dt>Last contact</dt><dd><?= esc($bound['last_seen_at'] ?? 'Not reported') ?></dd></div>
        </dl>
        <p class="bmg-caption mb-0">Contact does not establish motor state. Saved schedules are intent until the controller reports an applied version.</p>
      </div>
      <div class="bmg-drum-footer"><span class="bmg-caption"><i class="bi bi-cpu me-1" aria-hidden="true"></i><?= $bound === null ? 'Controller setup needed' : 'Controller registered' ?></span><a href="<?= esc(site_url('/facilities/unit/' . (int) $drum['id']), 'attr') ?>" class="btn btn-outline-primary btn-sm">Manage drum<span class="visually-hidden"> <?= esc($drum['unit_code']) ?></span><i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></a></div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php if (!empty($canConfigure)): $editUnit = null; require __DIR__ . '/_unit_modal.php'; endif; ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="<?= esc(base_url('assets/js/bmg.js')) ?>"></script><?= $this->endSection() ?>

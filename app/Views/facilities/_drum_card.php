<?php
// Zynapse DrumCard's gear/identity/facts/footer layout, using saved Humus records only.
$drum = $item['unit'];
$plan = $item['schedule'];
$bound = $item['device'];
$gearSeed = (int) $drum['id'];
$isArchived = !empty($archivedView);
?>
<article class="card bmg-drum-card mb-0 h-100 position-relative" data-bmg-gear-host>
  <div class="card-body pt-3">
    <div class="d-flex justify-content-center mb-3"><?php require __DIR__ . '/_gear.php'; ?></div>
    <div class="d-flex justify-content-between align-items-start gap-2 border-bottom pb-3 mb-3">
      <div class="min-w-0">
        <div class="small text-muted fw-semibold"><?= esc($drum['unit_code']) ?></div>
        <h3 class="card-title py-1 mb-0"><?= esc($drum['name']) ?></h3>
      </div>
      <span class="badge bg-light text-dark border"><?= $isArchived ? 'Archived' : esc(ucfirst($drum['status'])) ?></span>
    </div>
    <dl class="bmg-fact-grid">
      <?php if ($isArchived): ?><div class="bmg-fact"><dt>Archived at</dt><dd><?= esc($drum['deleted_at']) ?></dd></div><?php endif; ?>
      <div class="bmg-fact"><dt>Capacity</dt><dd><?= esc($drum['drum_capacity_kg']) ?> kg</dd></div>
      <div class="bmg-fact"><dt>Device binding</dt><dd><?= $bound === null ? 'No active device bound' : esc($bound['chip_mac']) ?></dd></div>
      <div class="bmg-fact"><dt>Last contact</dt><dd><?= esc($bound['last_seen_at'] ?? 'Not reported') ?></dd></div>
      <div class="bmg-fact"><dt>Motor state / RPM</dt><dd>Unknown · not measured</dd></div>
    </dl>
    <div class="border rounded p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <span class="small fw-semibold"><i class="bi bi-calendar-week me-1" aria-hidden="true"></i>Daily schedule</span>
        <span class="badge bg-light text-dark border"><?= !empty($plan['enabled']) ? 'Saved enabled' : 'Saved disabled' ?></span>
      </div>
      <div class="d-flex flex-wrap gap-2 mb-2">
        <?php if ($plan['times'] === []): ?><span class="small text-muted">No times saved</span><?php else: foreach ($plan['times'] as $time): ?><span class="badge bg-light text-dark border"><?= esc($time) ?></span><?php endforeach; endif; ?>
      </div>
      <p class="small text-muted mb-0">Asia/Manila · <?= (int) $plan['duration_seconds'] ?> seconds per run · version <?= (int) $plan['version'] ?></p>
    </div>
    <p class="small text-muted mb-0">Contact does not establish motor state. Saved schedules are intent until the controller reports an applied version. Gear illustration only; no live RPM telemetry.</p>
  </div>
  <div class="card-footer bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
    <span class="small text-muted"><i class="bi bi-cpu me-1" aria-hidden="true"></i><?= $bound === null ? 'Controller setup needed' : 'Controller registered' ?></span>
    <?php if ($isArchived && !empty($canConfigure)): ?>
    <form action="<?= esc(site_url('/facilities/unit/' . (int) $drum['id'] . '/restore'), 'attr') ?>" method="POST" data-bmg-submit
          data-confirm="Restore this drum as offline with its schedule disabled? Controller registration is required again; no motor will be started."
          data-confirm-title="Restore drum" data-confirm-label="Restore offline" data-confirm-tone="warning">
      <?= csrf_field() ?>
      <input type="hidden" name="expected_version" value="<?= (int) $plan['version'] ?>">
      <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Restore drum<span class="visually-hidden"> <?= esc($drum['unit_code']) ?></span></button>
    </form>
    <?php elseif (!$isArchived): ?>
    <a href="<?= esc(site_url('/facilities/unit/' . (int) $drum['id']), 'attr') ?>" class="btn btn-outline-primary btn-sm stretched-link">Manage drum<span class="visually-hidden"> <?= esc($drum['unit_code']) ?></span><i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></a>
    <?php endif; ?>
  </div>
</article>

<?php
// Gear artwork is an illustration; all facts below come from saved records.
$drum = $item['unit'];
$plan = $item['schedule'];
$bound = $item['device'];
$gearSeed = (int) $drum['id'];
$isArchived = !empty($archivedView);
$statusTone = $isArchived ? 'slate' : match ($drum['status']) {
    'active' => 'emerald',
    'maintenance' => 'amber',
    default => 'slate',
};
?>
<article class="card ce-card bmg-drum-card mb-0 h-100 position-relative" data-bmg-gear-host>
  <div class="card-body">
    <div class="bmg-drum-heading">
      <?php require __DIR__ . '/_gear.php'; ?>
      <div class="bmg-drum-identity">
        <span class="bmg-code"><?= esc($drum['unit_code']) ?></span>
        <h3 class="card-title"><?= esc($drum['name']) ?></h3>
        <span class="ce-tag"><span class="ce-status-dot ce-status-<?= $statusTone ?>"><?= $isArchived ? 'Archived' : esc(ucfirst($drum['status'])) ?></span></span>
      </div>
    </div>
    <dl class="bmg-fact-grid">
      <?php if ($isArchived): ?><div class="bmg-fact"><dt>Archived at</dt><dd><?= esc($drum['deleted_at']) ?></dd></div><?php endif; ?>
      <div class="bmg-fact"><dt>Capacity</dt><dd class="bmg-number"><?= esc($drum['drum_capacity_kg']) ?> kg</dd></div>
      <div class="bmg-fact"><dt>Device binding</dt><dd><?= $bound === null ? 'No active device bound' : '<span class="bmg-code">' . esc($bound['chip_mac']) . '</span>' ?></dd></div>
      <div class="bmg-fact"><dt>Last contact</dt><dd><?= esc($bound['last_seen_at'] ?? 'Not reported') ?></dd></div>
      <div class="bmg-fact"><dt>Motor state / RPM</dt><dd>Unknown · not measured</dd></div>
    </dl>
    <div class="bmg-saved-schedule">
      <div class="bmg-saved-schedule-heading">
        <span><i class="bi bi-calendar-week" aria-hidden="true"></i>Daily schedule</span>
        <span class="ce-tag"><?= !empty($plan['enabled']) ? 'Saved enabled' : 'Saved disabled' ?></span>
      </div>
      <div class="bmg-time-tags">
        <?php if ($plan['times'] === []): ?><span class="bmg-caption">No times saved</span><?php else: foreach ($plan['times'] as $time): ?><span class="ce-tag bmg-number"><?= esc($time) ?></span><?php endforeach; endif; ?>
      </div>
      <p class="bmg-caption mb-0">Asia/Manila · <?= (int) $plan['rotations'] ?> rotations per run · version <?= (int) $plan['version'] ?></p>
    </div>
    <p class="bmg-caption bmg-record-caution mb-0">Contact does not establish motor state. Saved schedules are intent until the controller reports an applied version. Gear illustration only; no live RPM telemetry.</p>
  </div>
  <div class="card-footer bmg-drum-footer">
    <span class="bmg-caption"><i class="bi bi-cpu" aria-hidden="true"></i><?= $bound === null ? 'Controller setup needed' : 'Controller registered' ?></span>
    <?php if ($isArchived && !empty($canConfigure)): ?>
    <form action="<?= esc(site_url('/facilities/unit/' . (int) $drum['id'] . '/restore'), 'attr') ?>" method="POST" data-bmg-submit
          data-confirm="Restore this drum as offline with its schedule disabled? Controller registration is required again; no motor will be started."
          data-confirm-title="Restore drum" data-confirm-label="Restore offline" data-confirm-tone="warning">
      <?= csrf_field() ?>
      <input type="hidden" name="expected_version" value="<?= (int) $plan['version'] ?>">
      <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Restore drum<span class="visually-hidden"> <?= esc($drum['unit_code']) ?></span></button>
    </form>
    <?php elseif (!$isArchived): ?>
    <a href="<?= esc(site_url('/facilities/unit/' . (int) $drum['id']), 'attr') ?>" class="btn btn-primary btn-sm stretched-link">Manage drum<span class="visually-hidden"> <?= esc($drum['unit_code']) ?></span><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    <?php endif; ?>
  </div>
</article>

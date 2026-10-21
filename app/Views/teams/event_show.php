<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<h2 class="h5 text-break mb-3"><?= esc($event['title']) ?></h2>
<?php if ($event['status'] === 'cancelled'): ?><div class="alert alert-secondary" role="status">This event has been cancelled.</div><?php endif; ?>
<dl class="row mb-3"><dt class="col-sm-3">Starts</dt><dd class="col-sm-9"><?= esc($startLocal->format('M j, Y · g:i A')) ?></dd><dt class="col-sm-3">Ends</dt><dd class="col-sm-9"><?= esc($endLocal->format('M j, Y · g:i A')) ?></dd><dt class="col-sm-3">Timezone</dt><dd class="col-sm-9">Asia/Manila</dd><dt class="col-sm-3">Location</dt><dd class="col-sm-9 text-break"><?= esc($event['location'] !== '' ? $event['location'] : 'Not specified') ?></dd><dt class="col-sm-3">Organizer</dt><dd class="col-sm-9"><?= esc($memberNames[$event['created_by_user_id']] ?? 'Former member') ?></dd></dl>
<?php if ($event['description'] !== ''): ?><h3 class="h6">Description</h3><p class="teams-preserve"><?= esc($event['description']) ?></p><?php endif; ?>
<h3 class="h6 mt-3">Participants (<?= count($event['participants']) ?>)</h3><ul class="list-unstyled"><?php foreach ($event['participants'] as $member): ?><li class="mb-1"><i class="bi bi-person me-2" aria-hidden="true"></i><?= esc($member['name']) ?></li><?php endforeach; ?></ul><?php if ($event['participants'] === []): ?><p class="text-muted small">No current workstation participants selected.</p><?php endif; ?>
<p class="small text-muted">Internal coordination only. This event does not reserve a patient appointment, block provider availability, or send notifications.</p>
<div class="form-actions">
  <a class="btn btn-outline-secondary" href="<?= esc($cancelUrl) ?>" data-app-modal-close>Close</a>
  <?php if ($event['canEdit'] && $event['status'] !== 'cancelled'): ?>
    <a class="btn btn-primary" href="<?= esc($baseUrl . '/events/' . $event['id'] . '/edit') ?>" data-app-modal-route>Edit event</a>
  <?php endif; ?>
</div>
<?php if ($event['canEdit'] && $event['status'] !== 'cancelled'): ?>
  <form class="mt-3 text-end" action="<?= esc($baseUrl . '/events/' . $event['id'] . '/cancel') ?>" method="post" data-modal-submit data-confirm="Cancel this internal team event? It will remain in calendar history. Patient appointments are unaffected." data-confirm-title="Cancel team event" data-confirm-label="Cancel event" data-confirm-tone="danger">
    <?= csrf_field() ?><input type="hidden" name="version" value="<?= $event['version'] ?>"><button class="btn btn-outline-danger" type="submit">Cancel event</button>
  </form>
<?php endif; ?>
<?= $this->endSection() ?>

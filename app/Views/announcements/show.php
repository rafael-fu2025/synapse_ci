<?php require __DIR__ . '/_dates.php'; ?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<section class="portal-engagement">
  <div class="pe-reading">
    <a class="btn btn-outline-secondary btn-sm mb-3" href="<?= esc(site_url('/portal/announcements')) ?>"><i class="bi bi-arrow-left me-2" aria-hidden="true"></i>All announcements</a>
    <article class="card pe-content-card" aria-labelledby="announcement-title"><div class="card-body p-4 p-lg-5">
      <div class="pe-card-top"><span class="pe-department"><i class="bi bi-megaphone" aria-hidden="true"></i><?= esc(ucfirst($announcement['unit'])) ?></span><span class="pe-state"><?= $announcement['read_at'] === null ? 'Unread' : 'Read' ?></span></div>
      <h1 id="announcement-title" class="h3 fw-bold text-break mb-3"><?= esc($announcement['title']) ?></h1>
      <p class="small text-muted mb-0">Published <time datetime="<?= esc($announcementDate($announcement['published_at'], 'c')) ?>"><?= esc($announcementDate($announcement['published_at'], 'd M Y, g:i A')) ?></time> (Asia/Manila)</p>
      <div class="pe-reading-body"><?= esc($announcement['body']) ?></div>
      <?php if ($announcement['ends_at'] !== null): ?><p class="small text-muted">Available until <?= esc($announcementDate($announcement['ends_at'], 'd M Y, g:i A')) ?> (Asia/Manila)</p><?php endif; ?>
      <div class="pe-card-footer">
        <?php if ($announcement['read_at'] === null): ?>
        <div><form method="POST" data-background-submit action="<?= esc(site_url('/portal/announcements/' . (int) $announcement['id'] . '/read')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-2" aria-hidden="true"></i>Mark as read</button></form><p class="form-text mb-0">Records that you marked this notice read, not acknowledgement or comprehension.</p></div>
        <?php else: ?><p class="pe-completion"><i class="bi bi-check-circle" aria-hidden="true"></i>Marked read <?= esc($announcementDate($announcement['read_at'], 'd M Y, g:i A')) ?> (Asia/Manila)</p><?php endif; ?>
      </div>
    </div></article>
  </div>
</section>
<?= $this->endSection() ?>

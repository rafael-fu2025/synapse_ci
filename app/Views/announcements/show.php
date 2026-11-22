<?php require __DIR__ . '/_dates.php'; ?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $unread = $announcement['read_at'] === null; ?>
<section class="portal-engagement pe-announcement-detail"
         data-refresh-owner="portal-announcement-detail.<?= (int) $announcement['id'] ?>"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div class="pe-reading">
    <article class="an-sheet" aria-labelledby="announcement-title">
      <header class="an-reading-head">
        <div class="an-reading-meta-top">
          <span class="pe-department">
            <?= view('partials/unit_vector_icon', ['unit' => $announcement['unit']]) ?><?= esc(ucfirst($announcement['unit'])) ?>
          </span>
          <span class="pe-state <?= $unread ? 'pe-state-pending' : 'pe-state-read' ?>" data-refresh-region="read-state" data-refresh-heading tabindex="-1"><?= $unread ? 'Unread' : 'Read' ?></span>
        </div>

        <h2 id="announcement-title" class="an-article-title"><?= esc($announcement['title']) ?></h2>

        <p class="an-dateline">
          <span><i class="bi bi-calendar3" aria-hidden="true"></i>Published <time datetime="<?= esc($announcementDate($announcement['published_at'], 'c')) ?>"><?= esc($announcementDate($announcement['published_at'], 'd M Y, g:i A')) ?></time> (Asia/Manila)</span>
          <?php if ($announcement['ends_at'] !== null): ?>
            <span>Available until <?= esc($announcementDate($announcement['ends_at'], 'd M Y, g:i A')) ?> (Asia/Manila)</span>
          <?php endif; ?>
        </p>
      </header>

      <div class="an-reading-body-band">
        <div class="pe-reading-body"><?= esc($announcement['body']) ?></div>
      </div>

      <footer class="an-reading-footer" data-refresh-region="read-action">
        <?php if ($unread): ?>
          <div class="an-read-action-stack">
            <form method="POST" data-background-submit action="<?= esc(site_url('/portal/announcements/' . (int) $announcement['id'] . '/read')) ?>">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-primary an-btn">
                <i class="bi bi-check2" aria-hidden="true"></i>Mark as read
              </button>
            </form>
            <p class="an-read-note">Records that you marked this notice read, not acknowledgement or comprehension.</p>
          </div>
        <?php else: ?>
          <p class="pe-completion">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>Marked read <?= esc($announcementDate($announcement['read_at'], 'd M Y, g:i A')) ?> (Asia/Manila)
          </p>
        <?php endif; ?>
      </footer>
    </article>
  </div>
</section>
<?= $this->endSection() ?>

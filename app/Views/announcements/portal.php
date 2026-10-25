<?php
require __DIR__ . '/_dates.php';
$filters = $filters ?? ['q' => '', 'unit' => 'all', 'status' => 'all'];
$counts = $counts ?? ['all' => 0, 'unread' => 0, 'read' => 0];
$tabUrl = static fn (string $state): string => $baseUrl . '?' . http_build_query(['q' => $filters['q'], 'unit' => $filters['unit'], 'status' => $state]);
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<section class="portal-engagement" aria-labelledby="campus-updates-title"
         data-live-filters
         data-filter-url="<?= esc($baseUrl) ?>"
         data-filter-key="portal-announcements"
         data-live-events="notifications.changed">
  <header class="pe-header">
    <span class="pe-header-icon" aria-hidden="true"><i class="bi bi-megaphone"></i></span>
    <div><p class="pe-eyebrow">CAMPUS CONNECTION</p><h2 id="campus-updates-title">Campus updates</h2><p class="text-muted mb-0">News and notices from your Clinic and Guidance offices, selected for your university profile.</p></div>
  </header>
  <div class="card pe-toolbar">
    <div class="card-body p-3 p-lg-4">
      <form method="get" action="<?= esc($baseUrl) ?>" class="row g-3 align-items-end" role="search" aria-label="Search announcements" data-filter-form>
        <input type="hidden" name="status" value="<?= esc($filters['status']) ?>">
        <div class="col-md-6"><label for="notice-search" class="form-label">Search announcements</label><input id="notice-search" type="search" name="q" class="form-control" maxlength="120" placeholder="Search a title or keyword" value="<?= esc($filters['q']) ?>"></div>
        <div class="col-md-3"><label for="notice-unit" class="form-label">Published by</label><select id="notice-unit" name="unit" class="form-select"><?php foreach (['all' => 'All departments', 'clinic' => 'Clinic', 'guidance' => 'Guidance'] as $value => $label): ?><option value="<?= esc($value) ?>" <?= $filters['unit'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button><a href="<?= esc($baseUrl) ?>" class="btn btn-outline-secondary" data-filter-reset>Reset</a></div>
      </form>
    </div>
  </div>
  <?= view('partials/live_filter_feedback') ?>
  <div data-filter-region="results">
  <nav class="pe-tabs" aria-label="Announcement read status">
    <?php foreach (['all' => 'All updates', 'unread' => 'Unread', 'read' => 'Read'] as $state => $label): ?>
    <a class="pe-tab <?= $filters['status'] === $state ? 'is-active' : '' ?>" data-filter-link href="<?= esc($tabUrl($state)) ?>" <?= $filters['status'] === $state ? 'aria-current="page"' : '' ?>><?= esc($label) ?><span class="pe-tab-count"><?= number_format($counts[$state]) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="pe-feed">
    <?php foreach ($announcements as $notice):
      $unread = $notice['read_at'] === null;
      $excerpt = trim(preg_replace('/\s+/u', ' ', $notice['excerpt'] ?? '') ?? '');
      $excerpt = mb_strimwidth($excerpt, 0, 220, '…', 'UTF-8');
    ?>
    <article class="card pe-content-card <?= $unread ? 'pe-unread' : '' ?>" aria-labelledby="notice-title-<?= (int) $notice['id'] ?>">
      <div class="card-body p-4">
        <div class="pe-card-top"><span class="pe-department"><i class="bi <?= $notice['unit'] === 'clinic' ? 'bi-heart-pulse' : 'bi-chat-heart' ?>" aria-hidden="true"></i><?= esc(ucfirst($notice['unit'])) ?></span><span class="pe-state <?= $unread ? 'pe-state-pending' : '' ?>"><?= $unread ? 'Unread' : 'Read' ?></span></div>
        <h3 id="notice-title-<?= (int) $notice['id'] ?>" class="pe-card-title"><?= esc($notice['title']) ?></h3>
        <?php if ($excerpt !== ''): ?><p class="pe-excerpt"><?= esc($excerpt) ?></p><?php endif; ?>
        <div class="pe-card-footer">
          <div class="pe-meta"><span><i class="bi bi-calendar3" aria-hidden="true"></i>Published <time datetime="<?= esc($announcementDate($notice['published_at'], 'c')) ?>"><?= esc($announcementDate($notice['published_at'], 'd M Y')) ?></time></span><?php if (!empty($notice['ends_at'])): ?><span>Available until <?= esc($announcementDate($notice['ends_at'], 'd M Y, g:i A')) ?> (Manila)</span><?php endif; ?></div>
          <a href="<?= esc($baseUrl . '/' . (int) $notice['id']) ?>" class="btn btn-outline-primary btn-sm" aria-label="<?= esc('Read announcement: ' . $notice['title']) ?>">Read announcement<i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></a>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
    <?php if ($announcements === []): ?>
    <div class="card pe-empty"><i class="bi bi-mailbox" aria-hidden="true"></i><h3><?= $filters['status'] === 'unread' && $filters['q'] === '' ? 'You’re all caught up' : 'No announcements found' ?></h3><p>No current announcements match your profile and selected filters.</p><a href="<?= esc($baseUrl) ?>" class="btn btn-outline-primary btn-sm" data-filter-reset>View all updates</a></div>
    <?php endif; ?>
  </div>
  <?= view('partials/content_pagination', ['pagination' => $pagination, 'filters' => $filters, 'baseUrl' => $baseUrl, 'paginationLabel' => 'Announcement pages', 'liveFilters' => true], ['saveData' => false]) ?>
  </div>
</section>
<?= $this->endSection() ?>

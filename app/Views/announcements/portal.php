<?php
require __DIR__ . '/_dates.php';
$filters = $filters ?? ['q' => '', 'unit' => 'all', 'status' => 'all'];
$counts = $counts ?? ['all' => 0, 'unread' => 0, 'read' => 0];
$tabUrl = static fn (string $state): string => $baseUrl . '?' . http_build_query(['q' => $filters['q'], 'unit' => $filters['unit'], 'status' => $state]);
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<a href="<?= esc(site_url('/portal'), 'attr') ?>" class="portal-back-btn">
  <i class="bi bi-arrow-left" aria-hidden="true"></i>Back to My Health Portal
</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<section class="portal-engagement pe-announcements" aria-labelledby="campus-updates-title"
         data-live-filters
         data-filter-url="<?= esc($baseUrl) ?>"
         data-filter-key="portal-announcements"
         data-live-events="notifications.changed">
  <div class="an-sheet">

    <!-- Band 1: Intro -->
    <div class="an-band">
      <h2 class="an-intro-title" id="campus-updates-title">Campus updates</h2>
      <p class="an-intro-desc">News and notices from your Clinic and Guidance offices, selected for your university profile.</p>
    </div>

    <!-- Band 2: Search, Department Filter, Status Tabs & Editorial Feed -->
    <div class="an-band">
      <form method="get" action="<?= esc($baseUrl) ?>" class="an-filter-form" role="search" aria-label="Search announcements" data-filter-form>
        <input type="hidden" name="status" value="<?= esc($filters['status']) ?>">
        <div class="an-filter-field">
          <label for="notice-search" class="an-filter-label">Search announcements</label>
          <input id="notice-search" type="search" name="q" class="form-control an-search-input" maxlength="120" placeholder="Search a title or keyword" value="<?= esc($filters['q']) ?>">
        </div>
        <div class="an-filter-field">
          <label for="notice-unit" class="an-filter-label">Published by</label>
          <select id="notice-unit" name="unit" class="form-select an-unit-select">
            <?php foreach (['all' => 'All departments', 'clinic' => 'Clinic', 'guidance' => 'Guidance'] as $value => $label): ?>
              <option value="<?= esc($value) ?>" <?= $filters['unit'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="an-filter-actions">
          <button class="btn btn-primary an-btn" type="submit"><i class="bi bi-search" aria-hidden="true"></i>Search</button>
          <a href="<?= esc($baseUrl) ?>" class="btn btn-outline-secondary an-btn" data-filter-reset>Reset</a>
        </div>
      </form>

      <?= view('partials/live_filter_feedback') ?>

      <div data-filter-region="results">
        <nav class="pe-tabs" aria-label="Announcement read status">
          <?php foreach (['all' => 'All updates', 'unread' => 'Unread', 'read' => 'Read'] as $state => $label): ?>
            <a class="pe-tab <?= $filters['status'] === $state ? 'is-active' : '' ?>" data-filter-link href="<?= esc($tabUrl($state)) ?>" <?= $filters['status'] === $state ? 'aria-current="page"' : '' ?>>
              <?= esc($label) ?><span class="pe-tab-count"><?= number_format($counts[$state]) ?></span>
            </a>
          <?php endforeach; ?>
        </nav>

        <?php if ($announcements !== []): ?>
          <div class="pe-feed">
            <?php foreach ($announcements as $notice):
              $unread = $notice['read_at'] === null;
              $excerpt = trim(preg_replace('/\s+/u', ' ', $notice['excerpt'] ?? '') ?? '');
              $excerpt = mb_strimwidth($excerpt, 0, 220, '…', 'UTF-8');
            ?>
            <article class="pe-content-card <?= $unread ? 'is-unread' : 'is-read' ?>" aria-labelledby="notice-title-<?= (int) $notice['id'] ?>">
              <div class="pe-card-top">
                <span class="pe-department">
                  <?php if ($unread): ?><span class="an-unread-dot" aria-hidden="true"></span><?php endif; ?>
                  <?= view('partials/unit_vector_icon', ['unit' => $notice['unit']]) ?><?= esc(ucfirst($notice['unit'])) ?>
                </span>
                <span class="pe-state <?= $unread ? 'pe-state-pending' : '' ?>"><?= $unread ? 'Unread' : 'Read' ?></span>
              </div>

              <h3 id="notice-title-<?= (int) $notice['id'] ?>" class="pe-card-title"><?= esc($notice['title']) ?></h3>

              <?php if ($excerpt !== ''): ?>
                <p class="pe-excerpt"><?= esc($excerpt) ?></p>
              <?php endif; ?>

              <div class="pe-card-footer">
                <div class="pe-meta">
                  <span><i class="bi bi-calendar3" aria-hidden="true"></i>Published <time datetime="<?= esc($announcementDate($notice['published_at'], 'c')) ?>"><?= esc($announcementDate($notice['published_at'], 'd M Y')) ?></time></span>
                  <?php if (!empty($notice['ends_at'])): ?>
                    <span>Available until <?= esc($announcementDate($notice['ends_at'], 'd M Y, g:i A')) ?> (Manila)</span>
                  <?php endif; ?>
                </div>
                <a href="<?= esc($baseUrl . '/' . (int) $notice['id']) ?>" class="btn <?= $unread ? 'btn-primary' : 'btn-outline-secondary' ?> an-btn" aria-label="<?= esc('Read announcement: ' . $notice['title']) ?>">
                  Read announcement<i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="pe-empty">
            <i class="bi bi-mailbox" aria-hidden="true"></i>
            <h3><?= $filters['status'] === 'unread' && $filters['q'] === '' ? 'You’re all caught up' : 'No announcements found' ?></h3>
            <p>No current announcements match your profile and selected filters.</p>
            <a href="<?= esc($baseUrl) ?>" class="btn btn-outline-secondary an-btn" data-filter-reset>View all updates</a>
          </div>
        <?php endif; ?>

        <?= view('partials/content_pagination', ['pagination' => $pagination, 'filters' => $filters, 'baseUrl' => $baseUrl, 'paginationLabel' => 'Announcement pages', 'liveFilters' => true], ['saveData' => false]) ?>
      </div>
    </div>

  </div>
</section>
<?= $this->endSection() ?>

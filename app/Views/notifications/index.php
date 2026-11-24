<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/pages/notification-history.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $destinations = \App\Services\NotificationService::destinationUrls(); ?>
<div class="notification-history notif-workspace" data-notification-history
     data-history-url="<?= esc(site_url('notifications/history'), 'attr') ?>"
     data-read-base="<?= esc(site_url('notifications'), 'attr') ?>"
     data-read-all-url="<?= esc(site_url('notifications/read-all'), 'attr') ?>"
     data-destinations="<?= esc(json_encode($destinations, JSON_THROW_ON_ERROR), 'attr') ?>">

  <div class="notif-hero mb-3">
    <div class="notif-hero-content">
      <div class="notif-hero-badge">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        <span>Personal Live Alerts</span>
      </div>
      <p class="notif-hero-desc text-muted mb-0">
        Active notifications and campus activity directed to your account. Opening an alert or navigating to its destination does not mark it as read.
      </p>
    </div>
  </div>

  <div class="card notif-sheet">
    <div class="card-body notif-body">
      <div class="notif-toolbar d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div class="btn-group notif-filter-group" role="group" aria-label="Filter notifications">
          <button type="button" class="btn notif-filter-btn active" data-history-filter="all" aria-pressed="true">All</button>
          <button type="button" class="btn notif-filter-btn" data-history-filter="unread" aria-pressed="false">Unread</button>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 notif-toolbar-actions">
          <span class="small text-muted notif-unread-chip" data-history-unread>Checking unread count…</span>
          <button type="button" class="btn btn-primary btn-sm notif-btn-mark-all" data-history-mark-all disabled>Mark all read</button>
          <button type="button" class="btn btn-outline-secondary btn-sm notif-btn-refresh" data-history-refresh>Refresh</button>
        </div>
      </div>

      <div class="alert alert-warning notif-error-alert d-none" role="alert" data-history-error>
        <span data-history-error-text></span>
        <button type="button" class="btn btn-outline-secondary btn-sm ms-2" data-history-retry>Retry refresh</button>
      </div>

      <p class="small text-muted notif-status-line" role="status" aria-live="polite" aria-atomic="true" data-history-status>Loading notifications…</p>

      <ul class="list-unstyled mb-0 notif-list" data-history-list aria-label="Personal notifications"></ul>

      <noscript><p class="alert alert-info">Enable JavaScript to load and manage your notifications.</p></noscript>

      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mt-3 pt-3 notif-pagination-bar">
        <p class="small text-muted mb-0 notif-range" data-history-range>Loading notification count…</p>
        <span class="visually-hidden" data-history-page>Page 1</span>
        <nav aria-label="Notification pages">
          <ul class="pagination pagination-sm flex-wrap mb-0 notif-pagination synapse-pagination" data-history-pagination></ul>
        </nav>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/notification-history.js') ?>"></script>
<?= $this->endSection() ?>

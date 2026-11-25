<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">

  <title><?= esc($title ?? 'Dashboard') ?> - Synapse Campus Health & Services</title>
  <meta content="University Health Services and Guidance Platform" name="description">

  <meta name="csrf-name" content="<?= csrf_token() ?>">
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">
  <meta name="synapse-login-url" content="<?= esc(site_url('auth/login'), 'attr') ?>">
  <?php $refreshRegions = config(\Config\RefreshRegions::class); ?>
  <meta name="synapse-refresh-regions-enabled" content="<?= $refreshRegions->enabled ? '1' : '0' ?>">
  <meta name="synapse-refresh-region-owners" content="<?= esc(implode(' ', $refreshRegions->owners), 'attr') ?>">
  <?php if (config(\Config\Realtime::class)->enabled): ?>
    <meta name="synapse-realtime-ticket-url" content="<?= esc(site_url('realtime/ticket'), 'attr') ?>">
  <?php endif; ?>

  <!-- Favicons -->
  <link rel="icon" type="image/x-icon" href="<?= base_url('favicon.ico') ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= base_url('favicon-192.png') ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= base_url('favicon-180.png') ?>">

  <script src="<?= base_url('assets/js/auth-theme-init.js') ?>"></script>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/boxicons/css/boxicons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/quill/quill.snow.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/simple-datatables/style.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/phosphor/style.css') ?>" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="<?= base_url('assets/css/style.css') ?>" rel="stylesheet">


  <!-- Offline-ready JS helpers: HTMX & Alpine.js -->
  <meta name="htmx-config" content='{"includeIndicatorStyles":false}'>
  <script src="<?= base_url('assets/vendor/htmx/htmx.min.js') ?>"></script>
  <script defer src="<?= base_url('assets/vendor/alpine/alpine.min.js') ?>"></script>

  <?php
  $portalShellHref = \App\Services\PortalContext::urlFor('main', 'portal');
  $portalShellUser = session()->get('user');
  $hasPortalPerson = is_array($portalShellUser) && !empty($portalShellUser['person_id']);
  $canPrefetchPortalShell = $hasPortalPerson
      && in_array('portal.records.read', $portalShellUser['permissions'] ?? [], true)
      && in_array('portal.appointments.read', $portalShellUser['permissions'] ?? [], true);
  ?>
  <!-- Pre-paint suppression + early shell prefetch to prevent flashing legacy single-page sub-views on reload -->
  <script src="<?= base_url('assets/js/portal-shell-init.js') ?>" data-portal-shell-href="<?= esc($portalShellHref, 'attr') ?>" data-portal-shell-prefetch="<?= $canPrefetchPortalShell ? '1' : '0' ?>"></script>

  <template id="page-styles" data-page-style-owner="head"></template>
  <?= $this->renderSection('styles') ?>
  <template data-page-styles-end></template>
</head>

<?php // Path-based workstations share a shell; dedicated hosts retain their portal boundary. ?>
<body class="app-shell" hx-boost="false" data-navigation-scope="<?= esc(hash('sha256', serialize([session()->get('user'), session()->get('tenant_id'), \App\Services\PortalContext::hostPortal() ?? 'single-host'])), 'attr') ?>" data-user-id="<?= esc((string) (session()->get('user')['id'] ?? '')) ?>" data-tenant-id="<?= esc((string) (session()->get('tenant_id') ?? '')) ?>">
  <?= $this->include('partials/header') ?>
  <?= $this->include('partials/sidebar') ?>
  <?= $this->include('partials/search_actions') ?>
  <?= $this->include('partials/global_search') ?>

  <main id="main" class="main">
    <div class="main-content">
      <div class="pagetitle d-flex justify-content-between align-items-center mb-3">
        <div class="page-title-copy">
          <h1 class="h3 mb-0"><?= esc($pageTitle ?? $title ?? 'Dashboard') ?></h1>
          <?php if (!empty($breadcrumbs)): ?>
            <nav aria-label="Breadcrumb">
              <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= site_url('/') ?>">Home</a></li>
                <?php foreach ($breadcrumbs as $crumb): ?>
                  <?php if (!empty($crumb['url'])): ?>
                    <li class="breadcrumb-item"><a href="<?= esc($crumb['url']) ?>"><?= esc($crumb['label']) ?></a></li>
                  <?php else: ?>
                    <li class="breadcrumb-item active"><?= esc($crumb['label']) ?></li>
                  <?php endif; ?>
                <?php endforeach; ?>
              </ol>
            </nav>
          <?php endif; ?>
        </div>
        <div class="page-actions">
          <?= $this->renderSection('page_actions') ?>
        </div>
      </div>

      <?php if (empty($formModalPage)): ?>
        <?= $this->include('partials/alerts') ?>
      <?php endif; ?>

      <section class="section">
        <?= $this->renderSection('content') ?>
      </section>
    </div>
  </main>

  <!-- Theme-Aware Logout Confirmation Modal -->
  <div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable synapse-logout-dialog">
      <div class="modal-content border-0 shadow">
        <div class="modal-header border-bottom py-3">
          <h5 class="modal-title fs-6 fw-bold text-dark d-flex align-items-center mb-0" id="logoutModalLabel">
            <i class="bi bi-box-arrow-right text-primary me-2 fs-5" aria-hidden="true"></i>Confirm Sign Out
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body synapse-confirm-body">
          <p class="synapse-confirm-lead">Are you sure you want to sign out?</p>
          <p class="synapse-confirm-text mb-0">Your active session will be ended. Please ensure any open records or consultation notes are saved.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <form action="<?= site_url('/auth/logout') ?>" method="POST" class="m-0">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">Yes, Sign Out</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Theme-Aware Confirmation Modal (shared; driven by assets/js/confirm.js) -->
  <div class="modal fade" id="synapseConfirmModal" tabindex="-1" aria-labelledby="synapseConfirmTitle" aria-describedby="synapseConfirmMessage" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable synapse-confirm-dialog">
      <div class="modal-content border-0 shadow">
        <!-- Header: icon inherits tone; title is the single accessible name. -->
        <div class="modal-header border-bottom py-3">
          <h5 class="modal-title fs-6 fw-bold text-dark d-flex align-items-center mb-0" id="synapseConfirmTitle">
            <i class="bi bi-question-circle-fill me-2 fs-5 text-primary" data-confirm-icon aria-hidden="true"></i>
            <span data-confirm-title-text>Please confirm</span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body synapse-confirm-body">
          <p class="synapse-confirm-text mb-0" id="synapseConfirmMessage" data-confirm-message>Are you sure?</p>
          <div class="synapse-confirm-reason" data-confirm-reason-group hidden>
            <label class="synapse-confirm-label" for="synapseConfirmReason">Cancellation reason <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" id="synapseConfirmReason" class="form-control synapse-confirm-input" maxlength="255" aria-describedby="synapseConfirmReasonHelp" data-confirm-reason-input disabled>
            <p class="synapse-confirm-help mb-0" id="synapseConfirmReasonHelp">Required, up to 255 characters. Keep it brief; do not include confidential medical or counseling details.</p>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-confirm-cancel>Cancel</button>
          <button type="button" class="btn btn-danger" data-confirm-accept>Yes, continue</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Vendor JS Files -->
  <script src="<?= base_url('assets/vendor/apexcharts/apexcharts.min.js') ?>"></script>
  <script src="<?= base_url('assets/js/chart-theme.js') ?>"></script>
  <script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
  <script src="<?= base_url('assets/vendor/simple-datatables/simple-datatables.js') ?>"></script>
  <!-- Custom widgets: BalaySelect upgrades native selects; BalayDatepicker reuses BalaySelect
       for its month/year controls, so it must load after custom-dropdown.js. -->
  <script src="<?= base_url('assets/js/custom-dropdown.js') ?>"></script>
  <script src="<?= base_url('assets/js/datepicker.js') ?>"></script>
  <script src="<?= base_url('assets/js/datetimepicker.js') ?>"></script>
  <script src="<?= base_url('assets/js/date-range-picker.js') ?>"></script>
  <script src="<?= base_url('assets/js/loading.js') ?>"></script>
  <script src="<?= base_url('assets/js/form-loading.js') ?>"></script>
  <script src="<?= base_url('assets/js/auth-session.js') ?>"></script>
  <script src="<?= base_url('assets/js/csrf.js') ?>"></script>
  <script src="<?= base_url('assets/js/toast.js') ?>"></script>
  <script src="<?= base_url('assets/js/banner-dismiss.js') ?>"></script>
  <script src="<?= base_url('assets/js/realtime.js') ?>"></script>
  <script src="<?= base_url('assets/js/mis-sync.js') ?>"></script>
  <script src="<?= base_url('assets/js/report-download.js') ?>"></script>
  <script src="<?= base_url('assets/js/notifications.js') ?>"></script>
  <script src="<?= base_url('assets/js/confirm.js') ?>"></script>
  <script src="<?= base_url('assets/js/care-forms.js') ?>"></script>
  <script src="<?= base_url('assets/js/person-lookup-popover.js') ?>"></script>
  <script src="<?= base_url('assets/js/availability-workspace.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/availability-workspace.js') ?: '1' ?>"></script>
  <script src="<?= base_url('assets/js/availability-filters.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/availability-filters.js') ?: '1' ?>"></script>
    <script src="<?= base_url('assets/js/live-filters.js') ?>"></script>
  <?= $this->include('admin/carousel/_form_scripts') ?>
  <script src="<?= base_url('assets/js/survey-builder.js') ?>"></script>
  <script src="<?= base_url('assets/js/profile-settings.js') ?>"></script>
  <script src="<?= base_url('assets/js/page-styles.js') ?>"></script>
  <script src="<?= base_url('assets/js/form-modal.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/form-modal.js') ?: '2' ?>"></script>
  <script src="<?= base_url('assets/js/responsive-tables.js') ?>"></script>
  <script src="<?= base_url('assets/js/core/main.js') ?>"></script>
  <script src="<?= base_url('assets/js/page-lifecycle.js') ?>"></script>
  <script src="<?= base_url('assets/js/navigation.js') ?>"></script>
  <script src="<?= base_url('assets/js/portal-workspace.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/portal-workspace.js') ?: '1' ?>"></script>
  <script src="<?= base_url('assets/js/refresh-regions.js') ?>"></script>
  <script src="<?= base_url('assets/js/team-chat.js') ?>"></script>
  <script src="<?= base_url('assets/js/archive-actions.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/archive-actions.js') ?: '1' ?>"></script>
  <script src="<?= base_url('assets/js/background-actions.js') ?>"></script>
  <script src="<?= base_url('assets/js/global-search.js') ?>"></script>

  <div id="page-scripts" hidden><?= $this->renderSection('scripts') ?></div>
  <div class="navigation-feedback">
    <div id="navigation-status" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>
    <button id="navigation-retry" class="btn btn-sm btn-primary" type="button" hidden>Retry navigation</button>
    <a id="navigation-fallback" class="btn btn-sm btn-outline-secondary" data-navigation="false" hidden>Open the full page</a>
  </div>
</body>

</html>

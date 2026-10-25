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
  <?php if (config(\Config\Realtime::class)->enabled): ?>
    <meta name="synapse-realtime-ticket-url" content="<?= esc(site_url('realtime/ticket'), 'attr') ?>">
  <?php endif; ?>

  <!-- Favicons -->
  <link rel="icon" type="image/x-icon" href="<?= base_url('favicon.ico') ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= base_url('favicon-192.png') ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= base_url('favicon-180.png') ?>">

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Nunito+Sans:wght@400;500;600;700;800&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/boxicons/css/boxicons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/quill/quill.snow.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/simple-datatables/style.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/phosphor/style.css') ?>" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="<?= base_url('assets/css/style.css') ?>" rel="stylesheet">

  <!-- Inline theme restoration to prevent FOUC (Flash of unstyled content) -->
  <script>
    (function() {
      const storedTheme = localStorage.getItem('na-dark-mode');
      if (storedTheme === '1' || (!storedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.setAttribute('data-theme', 'dark');
      } else {
        document.documentElement.setAttribute('data-theme', 'light');
      }
    })();
  </script>

  <!-- Offline-ready JS helpers: HTMX & Alpine.js -->
  <script src="<?= base_url('assets/vendor/htmx/htmx.min.js') ?>"></script>
  <script defer src="<?= base_url('assets/vendor/alpine/alpine.min.js') ?>"></script>

  <style>

    /* Synapse Logo Light/Night Mode visibility toggle */
    .synapse-logo-light {
      display: block !important;
    }
    .synapse-logo-dark {
      display: none !important;
    }

    [data-theme="dark"] .synapse-logo-light {
      display: none !important;
    }
    [data-theme="dark"] .synapse-logo-dark {
      display: block !important;
    }

    [data-theme="dark"] .header-logo img {
      filter: none !important;
    }


  </style>

</head>

<?php // Path-based workstations share a shell; dedicated hosts retain their portal boundary. ?>
<body class="app-shell" hx-boost="false" data-navigation-scope="<?= esc(hash('sha256', serialize([session()->get('user'), session()->get('tenant_id'), \App\Services\PortalContext::hostPortal() ?? 'single-host'])), 'attr') ?>">
  <div id="page-styles" hidden><?= $this->renderSection('styles') ?></div>
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
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
      <div class="modal-content border-0 shadow">
        <div class="modal-header border-bottom py-3">
          <h5 class="modal-title fs-6 fw-bold text-dark d-flex align-items-center mb-0" id="logoutModalLabel">
            <i class="bi bi-box-arrow-right text-primary me-2 fs-5"></i> Confirm Sign Out
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4 text-center">
          <div class="mb-3">
            <i class="bi bi-shield-lock text-primary fs-1 opacity-75"></i>
          </div>
          <h6 class="fw-bold mb-1 text-dark">Are you sure you want to sign out?</h6>
          <p class="text-muted small mb-0">Your active session will be ended. Please ensure any open records or consultation notes are saved.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <form action="<?= site_url('/auth/logout') ?>" method="POST">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i> Yes, Sign Out</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Theme-Aware Confirmation Modal (shared; driven by assets/js/confirm.js) -->
  <div class="modal fade" id="synapseConfirmModal" tabindex="-1" aria-labelledby="synapseConfirmTitle" aria-describedby="synapseConfirmMessage" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
      <div class="modal-content border-0 shadow">
        <!-- Header: icon inherits tone; title is the single accessible name. -->
        <div class="modal-header border-bottom py-3">
          <h5 class="modal-title fs-6 fw-bold text-dark d-flex align-items-center mb-0" id="synapseConfirmTitle">
            <i class="bi bi-question-circle-fill me-2 fs-5 text-primary" data-confirm-icon aria-hidden="true"></i>
            <span data-confirm-title-text>Please confirm</span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4 text-center">
          <div class="mb-3">
            <i class="bi bi-question-circle-fill text-primary fs-1 opacity-75" data-confirm-icon data-confirm-icon-body aria-hidden="true"></i>
          </div>
          <p class="text-muted small mb-0" id="synapseConfirmMessage" data-confirm-message>Are you sure?</p>
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
  <script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
  <script src="<?= base_url('assets/vendor/simple-datatables/simple-datatables.js') ?>"></script>
  <!-- Custom widgets: BalaySelect upgrades native selects; BalayDatepicker reuses BalaySelect
       for its month/year controls, so it must load after custom-dropdown.js. -->
  <script src="<?= base_url('assets/js/custom-dropdown.js') ?>"></script>
  <script src="<?= base_url('assets/js/datepicker.js') ?>"></script>
  <script src="<?= base_url('assets/js/datetimepicker.js') ?>"></script>
  <script src="<?= base_url('assets/js/loading.js') ?>"></script>
  <script src="<?= base_url('assets/js/form-loading.js') ?>"></script>
  <script src="<?= base_url('assets/js/auth-session.js') ?>"></script>
  <script src="<?= base_url('assets/js/csrf.js') ?>"></script>
  <script src="<?= base_url('assets/js/toast.js') ?>"></script>
  <script src="<?= base_url('assets/js/realtime.js') ?>"></script>
  <script src="<?= base_url('assets/js/mis-sync.js') ?>"></script>
  <script src="<?= base_url('assets/js/report-download.js') ?>"></script>
  <script src="<?= base_url('assets/js/notifications.js') ?>"></script>
  <script src="<?= base_url('assets/js/confirm.js') ?>"></script>
  <script src="<?= base_url('assets/js/care-forms.js') ?>"></script>
  <script src="<?= base_url('assets/js/availability-workspace.js') ?>"></script>
  <script src="<?= base_url('assets/js/availability-filters.js') ?>"></script>
    <script src="<?= base_url('assets/js/live-filters.js') ?>"></script>
  <?= $this->include('admin/carousel/_form_scripts') ?>
  <script src="<?= base_url('assets/js/survey-builder.js') ?>"></script>
  <script src="<?= base_url('assets/js/profile-settings.js') ?>"></script>
  <script src="<?= base_url('assets/js/form-modal.js') ?>"></script>
  <script src="<?= base_url('assets/js/responsive-tables.js') ?>"></script>
  <script src="<?= base_url('assets/js/core/main.js') ?>"></script>
  <script src="<?= base_url('assets/js/page-lifecycle.js') ?>"></script>
  <script src="<?= base_url('assets/js/team-chat.js') ?>"></script>
  <script src="<?= base_url('assets/js/navigation.js') ?>"></script>
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

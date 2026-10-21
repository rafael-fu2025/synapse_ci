<header class="header">
  <div class="header-left">
    <button class="sidebar-toggle" title="Toggle Sidebar" aria-label="Toggle sidebar">
      <span class="menu-lines" aria-hidden="true">
        <span></span>
        <span></span>
        <span></span>
      </span>
    </button>

    <a href="<?= site_url('/') ?>" class="header-brand d-flex align-items-center text-decoration-none" aria-label="SYNAPSE home">
      <span class="header-logo d-flex align-items-center">
        <!-- Light mode mark (Synapse Maroon) -->
        <img src="<?= base_url('assets/img/brand/synapse-maroon.png') ?>"
             alt="SYNAPSE"
             class="synapse-logo-light"
             style="height: 38px; width: auto; object-fit: contain;">

        <!-- Dark mode mark (Synapse White) -->
        <img src="<?= base_url('assets/img/brand/synapse-white.png') ?>"
             alt="SYNAPSE"
             class="synapse-logo-dark"
             style="height: 38px; width: auto; object-fit: contain;">
      </span>
      <div class="header-context ms-2 d-flex flex-column justify-content-center">
        <strong class="header-context-title fs-5 fw-bold mb-0" style="letter-spacing: 0.04em; line-height: 1.1;">SYNAPSE</strong>
        <span class="header-context-tagline text-muted" style="font-size: 0.68rem; font-weight: 600; letter-spacing: 0.02em; line-height: 1.1;">Foundation University</span>
      </div>
    </a>
  </div>

  <div class="header-search-wrap flex-grow-1 mx-3 d-none d-md-block"></div>

  <div class="header-right">
    <div class="header-actions-desktop d-flex align-items-center gap-2">

      <?= $this->include('partials/notifications') ?>
      <?= $this->include('partials/workstation_switcher') ?>

      <!-- Theme toggle with tooltip placeholder -->
      <div class="header-action-wrap" data-bs-title="Toggle Theme (Light / Dark mode)" data-bs-toggle="tooltip" data-bs-placement="bottom">
        <button class="header-action theme-toggle" type="button" aria-label="Toggle dark mode">
          <i class="bi bi-moon-stars theme-icon-dark"></i>
          <i class="bi bi-sun theme-icon-light"></i>
        </button>
      </div>

      <?php $currentUser = session()->get('user') ?? null; ?>
      <?php $role = $currentUser['role'] ?? 'student'; ?>
      <?php 
        $userEmail = !empty($currentUser['email']) 
            ? $currentUser['email'] 
            : 'user@foundationu.com';
        $roleLabel = ucwords(str_replace('_', ' ', $role));
        $profileDefaultAvatar = \App\Services\ProfileAvatar::dataUri($currentUser ?? []);
        $profilePhotoUrl = $profileDefaultAvatar;
        $profileTenantId = (int) ($currentUser['tenant_id'] ?? 0);
        $profilePersonId = (int) ($currentUser['person_id'] ?? 0);
        if ((new \App\Services\ProfilePhotoService())->exists($profileTenantId, $profilePersonId)) {
            $profilePhotoUrl = site_url('account/photo');
        }
      ?>
      <div class="header-action-wrap dropdown user-dropdown"
           data-bs-title="Account: <?= esc($userEmail) ?> (<?= esc($roleLabel) ?>)"
           data-bs-toggle="tooltip"
           data-bs-placement="bottom">
        <button class="dropdown-toggle user-trigger" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User account and profile menu">
          <img src="<?= esc($profilePhotoUrl) ?>" alt="User Avatar" class="user-avatar" data-profile-avatar onerror="this.onerror=null;this.src='<?= esc($profileDefaultAvatar, 'attr') ?>'">
          <div class="user-brief d-none d-md-flex text-start">
            <span class="user-email" placeholder="user@foundationu.com"><?= esc($userEmail) ?></span>
            <span class="user-role"><?= esc($roleLabel) ?></span>
          </div>
        </button>

        <div class="dropdown-menu dropdown-menu-end user-menu">
          <div class="user-menu-header">
            <img src="<?= esc($profilePhotoUrl) ?>" alt="User" class="user-menu-avatar" data-profile-avatar onerror="this.onerror=null;this.src='<?= esc($profileDefaultAvatar, 'attr') ?>'">
            <div class="user-menu-info">
              <div class="user-menu-name"><?= esc($currentUser['name'] ?? 'User') ?></div>
              <div class="user-menu-email"><?= esc($userEmail) ?></div>
            </div>
          </div>
          <div class="user-menu-body">
            <a class="user-menu-item" href="<?= \App\Services\PortalContext::urlFor('main', 'portal') ?>">
              <i class="bi bi-person"></i>
              <span>My Health Portal</span>
            </a>
            <?php if ($profilePersonId > 0 && $role !== 'guest'): ?>
              <a class="user-menu-item" data-app-modal-open href="<?= esc(\App\Services\PortalContext::urlFor('main', 'portal/settings')) ?>">
                <i class="bi bi-person-gear" aria-hidden="true"></i><span>Profile &amp; Settings</span>
              </a>
            <?php endif; ?>
            <?php if (in_array($role, ['clinic_admin', 'clinic_staff', 'superadmin'], true)): ?>
              <a class="user-menu-item" href="<?= \App\Services\PortalContext::urlFor('clinic', 'clinic') ?>">
                <i class="bi bi-hospital"></i>
                <span>Clinic Console</span>
              </a>
            <?php endif; ?>
            <?php if (in_array($role, ['guidance_admin', 'guidance_supervisor', 'counsellor', 'superadmin'], true)): ?>
              <a class="user-menu-item" href="<?= \App\Services\PortalContext::urlFor('guidance', 'guidance') ?>">
                <i class="bi bi-person-heart"></i>
                <span>Guidance Console</span>
              </a>
            <?php endif; ?>
            <?php if (in_array($role, ['bmg_admin', 'facilities_op', 'superadmin'], true)): ?>
              <a class="user-menu-item" href="<?= \App\Services\PortalContext::urlFor('bmg', 'facilities/dashboard') ?>">
                <i class="bi bi-recycle"></i>
                <span>Facilities Console</span>
              </a>
            <?php endif; ?>
            <?php if (in_array($role, ['superadmin', 'audit_reader'], true)): ?>
              <a class="user-menu-item" href="<?= \App\Services\PortalContext::urlFor('main', 'admin/audit') ?>">
                <i class="bi bi-fingerprint"></i>
                <span>Audit Ledger</span>
              </a>
            <?php endif; ?>
          </div>
          <div class="user-menu-footer">
            <button type="button" class="btn btn-primary user-menu-logout w-100" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
              <i class="bi bi-box-arrow-right"></i>
              <span>Sign Out</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

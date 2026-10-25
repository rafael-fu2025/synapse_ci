<header class="header">
  <div class="header-left">
    <button class="sidebar-toggle" type="button" data-bs-toggle="tooltip" data-bs-title="Toggle sidebar" data-bs-placement="bottom" aria-label="Toggle sidebar" aria-controls="app-sidebar" aria-expanded="false">
      <span class="menu-lines" aria-hidden="true">
        <span></span>
        <span></span>
        <span></span>
      </span>
    </button>

  </div>

  <div class="header-search-wrap flex-grow-1 mx-3">
    <button type="button" class="header-action global-search-trigger" data-search-open aria-haspopup="dialog" aria-controls="globalSearchDialog" aria-label="Search pages and actions" aria-keyshortcuts="Control+k Meta+k" hidden>
      <i class="bi bi-search" aria-hidden="true"></i>
      <span class="global-search-trigger-label">Search pages and actions…</span>
      <kbd data-search-shortcut aria-hidden="true">Ctrl K</kbd>
    </button>
  </div>

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
        $userName = trim((string) ($currentUser['name'] ?? '')) ?: ($currentUser['username'] ?? 'User');
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
           data-bs-title="Account: <?= esc($userName) ?> (<?= esc($roleLabel) ?>)"
           data-bs-toggle="tooltip"
           data-bs-placement="bottom">
        <button type="button" class="dropdown-toggle user-trigger" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User account and profile menu">
          <img src="<?= esc($profilePhotoUrl) ?>" alt="User Avatar" class="user-avatar" data-profile-avatar onerror="this.onerror=null;this.src='<?= esc($profileDefaultAvatar, 'attr') ?>'">
          <div class="user-brief d-none d-md-flex text-start">
            <span class="user-name"><?= esc($userName) ?></span>
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

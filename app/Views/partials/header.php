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
          <img src="<?= esc($profilePhotoUrl) ?>" alt="User Avatar" class="user-avatar" data-profile-avatar data-fallback-src="<?= esc($profileDefaultAvatar, 'attr') ?>">
          <div class="user-brief d-none d-md-flex text-start">
            <span class="user-name"><?= esc($userName) ?></span>
            <span class="user-role"><?= esc($roleLabel) ?></span>
          </div>
        </button>

        <div class="dropdown-menu dropdown-menu-end user-menu">
          <div class="user-menu-header">
            <img src="<?= esc($profilePhotoUrl) ?>" alt="User" class="user-menu-avatar" data-profile-avatar data-fallback-src="<?= esc($profileDefaultAvatar, 'attr') ?>">
            <div class="user-menu-info">
              <div class="user-menu-name"><?= esc($currentUser['name'] ?? 'User') ?></div>
              <div class="user-menu-email"><?= esc($userEmail) ?></div>
              <span class="user-menu-role-badge"><?= esc($roleLabel) ?></span>
            </div>
          </div>
          <div class="user-menu-body">
            <a class="user-menu-item" href="<?= \App\Services\PortalContext::urlFor('main', 'portal') ?>">
              <span class="user-menu-icon" aria-hidden="true"><i class="bi bi-person"></i></span>
              <span>My Health Portal</span>
            </a>
            <?php if ($profilePersonId > 0 && $role !== 'guest'): ?>
              <a class="user-menu-item" data-navigation href="<?= esc(\App\Services\PortalContext::urlFor('main', 'portal/settings')) ?>">
                <span class="user-menu-icon" aria-hidden="true"><i class="bi bi-person-gear"></i></span>
                <span>Profile &amp; Settings</span>
              </a>
            <?php endif; ?>
            <?php 
              $hasStaffConsoles = in_array($role, [
                'clinic_admin', 'clinic_physician', 'clinic_staff', 'clinic_intern',
                'guidance_admin', 'guidance_supervisor', 'counsellor', 'guidance_intern',
                'bmg_admin', 'facilities_op', 'bmg_intern',
                'superadmin', 'audit_reader'
              ], true);
            ?>
            <?php if ($hasStaffConsoles): ?>
              <div class="user-menu-divider" role="separator"></div>
              <div class="user-menu-section-header">Staff Consoles</div>
              <?php if (in_array($role, ['clinic_admin', 'clinic_physician', 'clinic_staff', 'clinic_intern', 'superadmin'], true)): ?>
                <a class="user-menu-item" href="<?= \App\Services\PortalContext::urlFor('clinic', 'clinic') ?>">
                  <span class="user-menu-icon" aria-hidden="true"><?= view('partials/unit_vector_icon', ['unit' => 'clinic', 'class' => 'unit-btn-icon']) ?></span>
                  <span>Clinic Console</span>
                </a>
              <?php endif; ?>
              <?php if (in_array($role, ['guidance_admin', 'guidance_supervisor', 'counsellor', 'guidance_intern', 'superadmin'], true)): ?>
                <a class="user-menu-item" href="<?= \App\Services\PortalContext::urlFor('guidance', 'guidance') ?>">
                  <span class="user-menu-icon" aria-hidden="true"><?= view('partials/unit_vector_icon', ['unit' => 'guidance', 'class' => 'unit-btn-icon']) ?></span>
                  <span>Guidance Console</span>
                </a>
              <?php endif; ?>
              <?php if (in_array($role, ['bmg_admin', 'facilities_op', 'bmg_intern', 'superadmin'], true)): ?>
                <a class="user-menu-item" href="<?= \App\Services\PortalContext::urlFor('bmg', 'facilities/dashboard') ?>">
                  <span class="user-menu-icon" aria-hidden="true"><i class="bi bi-recycle"></i></span>
                  <span>Facilities Console</span>
                </a>
              <?php endif; ?>
              <?php if (in_array($role, ['superadmin', 'audit_reader'], true)): ?>
                <a class="user-menu-item" href="<?= \App\Services\PortalContext::urlFor('main', 'admin/audit') ?>">
                  <span class="user-menu-icon" aria-hidden="true"><i class="bi bi-fingerprint"></i></span>
                  <span>Audit Ledger</span>
                </a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="user-menu-footer">
            <button type="button" class="btn btn-primary user-menu-logout w-100" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
              <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
              <span>Sign Out</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<?php
use App\Services\PortalContext;

// Authentication filters refresh this identity before protected views render.
$currentUser = session()->get('user') ?? null;
$role = $currentUser['role'] ?? 'guest';
$uri = trim(service('uri')->getRoutePath(), '/');
$canContent = static fn (string $permission): bool => $role === 'superadmin'
    || in_array($permission, $currentUser['permissions'] ?? [], true);
$canAll = static function (array $permissions) use ($canContent): bool {
    foreach ($permissions as $permission) {
        if (!$canContent($permission)) { return false; }
    }
    return true;
};
$isActive = static function (string $prefix) use ($uri): bool {
    $prefix = trim($prefix, '/');
    return $uri === $prefix || str_starts_with($uri, $prefix . '/');
};
$sidebarLink = static function (string $portal, string $path, string $label, string $icon,
    array $permissions = [], bool $exact = false, bool $queueBadge = false) use ($role, $uri, $isActive, $canAll): string {
    if (!PortalContext::isRolePermitted($role, $portal) || !$canAll($permissions)) { return ''; }
    $active = $exact ? ($uri === $path || $uri === $path . '/dashboard') : $isActive($path);
    return '<li class="nav-item"><a class="nav-link ' . ($active ? 'active' : '') . '" href="'
        . esc(PortalContext::urlFor($portal, $path)) . '"><span class="nav-icon"><i class="' . esc($icon)
        . '"></i></span><span class="nav-text">' . esc($label) . '</span>'
        . ($queueBadge ? '<span class="badge bg-danger-subtle text-danger rounded-pill ms-auto">Queue</span>' : '')
        . '</a></li>';
};
$canMain = PortalContext::isRolePermitted($role, 'main') && PortalContext::compatibleHost($role);
$canAuditNavigation = $canMain && in_array($role, ['superadmin', 'audit_reader'], true) && $canContent('audit.read');
$canReportsDashboard = $canMain && in_array($role, ['superadmin', 'audit_reader', 'report_viewer'], true) && $canContent('reports.read');
$canExportNavigation = $canMain && in_array($role, ['superadmin', 'audit_reader', 'report_viewer', 'clinic_admin', 'guidance_admin', 'bmg_admin'], true)
    && $canAll(['reports.read', 'reports.export']);
$canAccessNavigation = $canMain && in_array($role, ['superadmin', 'clinic_admin', 'guidance_admin'], true) && $canContent('rbac.manage');
$canMisNavigation = $canMain && in_array($role, ['superadmin', 'clinic_admin', 'guidance_admin'], true) && $canContent('mis.sync');
?>
<aside class="sidebar">
  <div class="sidebar-shell">
    <button class="sidebar-close" type="button" aria-label="Close sidebar"><i class="bi bi-x-lg"></i></button>
    <nav class="sidebar-nav">
      <ul class="nav-menu">
        <?php if (PortalContext::showsWorkstation('clinic', $role)): ?>
          <li class="nav-heading mt-2 mb-1 px-3 text-uppercase small text-muted">Clinic Workstation</li>
          <?= $sidebarLink('clinic', 'clinic', 'Clinic Dashboard', 'ph-light ph-squares-four', ['clinic.queue.read', 'clinic.encounters.read', 'clinic.appointments.read', 'clinic.inventory.read'], true) ?>
          <?= $sidebarLink('clinic', 'clinic/queue', 'Live Queue Board', 'ph-light ph-kanban', ['clinic.queue.read'], false, true) ?>
          <?= $sidebarLink('clinic', 'clinic/encounters', 'Encounters & Stepper', 'ph-light ph-heartbeat', ['clinic.encounters.read']) ?>
          <?= $sidebarLink('clinic', 'clinic/patients', 'Patients & Charts', 'ph-light ph-users', ['clinic.patients.read']) ?>
          <?= $sidebarLink('clinic', 'clinic/appointments', 'Appointments', 'ph-light ph-calendar', ['clinic.appointments.read']) ?>
          <?= $sidebarLink('clinic', 'clinic/inventory', 'Medicine Inventory', 'bi bi-capsule', ['clinic.inventory.read']) ?>
          <?= $sidebarLink('clinic', 'clinic/referrals', 'Referrals', 'ph-light ph-arrows-left-right', ['referrals.read']) ?>
          <?= $sidebarLink('clinic', 'clinic/teams', 'Clinic Teams', 'bi bi-people') ?>
          <?php if (in_array($role, ['clinic_admin', 'superadmin'], true)): ?>
            <?= $sidebarLink('clinic', 'clinic/announcements', 'Clinic Announcements', 'bi bi-megaphone', ['clinic.announcements.manage']) ?>
          <?php endif; ?>
        <?php endif; ?>

        <?php if (PortalContext::showsWorkstation('guidance', $role)): ?>
          <li class="nav-heading mt-3 mb-1 px-3 text-uppercase small text-muted">Guidance Workstation</li>
          <?= $sidebarLink('guidance', 'guidance', 'Guidance Dashboard', 'ph-light ph-squares-four', ['guidance.cases.read', 'guidance.queue.read', 'referrals.read'], true) ?>
          <?= $sidebarLink('guidance', 'guidance/queue', 'Guidance Queue', 'ph-light ph-clock-countdown', ['guidance.queue.read']) ?>
          <?= $sidebarLink('guidance', 'guidance/cases', 'Counseling Cases (Encrypted)', 'ph-light ph-folder-notch-open', ['guidance.cases.read']) ?>
          <?= $sidebarLink('guidance', 'guidance/appointments', 'Appointments', 'ph-light ph-calendar-check', ['guidance.schedule.read']) ?>
          <?= $sidebarLink('guidance', 'guidance/referrals', 'Referrals', 'ph-light ph-arrows-left-right', ['referrals.read']) ?>
          <?= $sidebarLink('guidance', 'guidance/teams', 'Guidance Teams', 'bi bi-people') ?>
          <?php if (in_array($role, ['guidance_admin', 'guidance_supervisor', 'superadmin'], true)): ?>
            <?= $sidebarLink('guidance', 'guidance/announcements', 'Guidance Announcements', 'bi bi-megaphone', ['guidance.announcements.manage']) ?>
            <?= $sidebarLink('guidance', 'guidance/surveys', 'Surveys & Responses', 'bi bi-clipboard-check', ['guidance.surveys.manage']) ?>
            <?= $sidebarLink('guidance', 'guidance/question-bank', 'Question Bank', 'bi bi-collection', ['guidance.surveys.manage']) ?>
          <?php endif; ?>
        <?php endif; ?>

        <?php if (PortalContext::showsWorkstation('bmg', $role)): ?>
          <li class="nav-heading mt-3 mb-1 px-3 text-uppercase small text-muted">Facilities (BMG)</li>
          <?= $sidebarLink('bmg', 'facilities/dashboard', 'Facilities Dashboard', 'ph-light ph-squares-four', ['facilities.read']) ?>
          <?php if ($canContent('facilities.read')): ?>
          <li class="nav-item"><a class="nav-link <?= $isActive('facilities') && !$isActive('facilities/dashboard') && !$isActive('facilities/teams') ? 'active' : '' ?>" href="<?= esc(PortalContext::urlFor('bmg', 'facilities')) ?>"><span class="nav-icon"><i class="ph-light ph-recycle"></i></span><span class="nav-text">Drum Rotation</span></a></li>
          <?php endif; ?>
          <?= $sidebarLink('bmg', 'facilities/teams', 'Facilities Teams', 'bi bi-people') ?>
        <?php endif; ?>

        <!-- Personal self-service remains available to staff acting as employees. -->
        <li class="nav-heading mt-3 mb-1 px-3 text-uppercase small text-muted">Personal Health</li>
        <li class="nav-item"><a class="nav-link <?= $isActive('portal') && !$isActive('portal/announcements') && !$isActive('portal/surveys') && !$isActive('portal/settings') ? 'active' : '' ?>" href="<?= esc(PortalContext::urlFor('main', 'portal')) ?>"><span class="nav-icon"><i class="ph-light ph-user-circle"></i></span><span class="nav-text">My Health Portal</span></a></li>
        <li class="nav-item"><a class="nav-link <?= $isActive('portal/announcements') ? 'active' : '' ?>" href="<?= esc(PortalContext::urlFor('main', 'portal/announcements')) ?>"><span class="nav-icon"><i class="bi bi-megaphone"></i></span><span class="nav-text">Announcements</span></a></li>
        <?php if (!empty($currentUser['person_id'])): ?>
          <li class="nav-item"><a class="nav-link <?= $isActive('portal/surveys') ? 'active' : '' ?>" href="<?= esc(PortalContext::urlFor('main', 'portal/surveys')) ?>"><span class="nav-icon"><i class="bi bi-clipboard-check"></i></span><span class="nav-text">My Surveys</span></a></li>
        <?php endif; ?>
        <?php if (!empty($currentUser['person_id']) && $role !== 'guest'): ?>
          <li class="nav-item"><a class="nav-link <?= $isActive('portal/settings') ? 'active' : '' ?>" data-app-modal-open href="<?= esc(PortalContext::urlFor('main', 'portal/settings')) ?>"><span class="nav-icon"><i class="bi bi-person-gear" aria-hidden="true"></i></span><span class="nav-text">Profile &amp; Settings</span></a></li>
        <?php endif; ?>

        <?php if ($canAuditNavigation || $canReportsDashboard || $canExportNavigation): ?>
          <li class="nav-heading mt-3 mb-1 px-3 text-uppercase small text-muted">Governance</li>
          <?php if ($canAuditNavigation): ?>
            <?= $sidebarLink('main', 'admin', 'Admin Dashboard', 'ph-light ph-squares-four', ['audit.read'], true) ?>
            <?= $sidebarLink('main', 'admin/audit', 'Audit Chain Ledger', 'ph-light ph-fingerprint', ['audit.read']) ?>
          <?php elseif ($canReportsDashboard): ?>
            <?= $sidebarLink('main', 'dashboard', 'Reports Dashboard', 'ph-light ph-squares-four', ['reports.read']) ?>
          <?php endif; ?>
          <?php if ($canExportNavigation): ?>
            <?= $sidebarLink('main', 'reports/export', 'Operational reports', 'bi bi-download', ['reports.read', 'reports.export']) ?>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($canAccessNavigation || $canMisNavigation): ?>
          <li class="nav-heading mt-3 mb-1 px-3 text-uppercase small text-muted">Administration</li>
          <?php if ($canAccessNavigation): ?>
            <?= $sidebarLink('main', 'admin/access', 'Employees & Access', 'bi bi-person-lock', ['rbac.manage']) ?>
          <?php endif; ?>
          <?php if ($canMisNavigation): ?>
            <?= $sidebarLink('main', 'admin/mis-sync', 'MIS reference synchronization', 'bi bi-arrow-repeat', ['mis.sync']) ?>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($canMain && $canContent('rbac.manage')): ?>
          <li class="nav-heading mt-3 mb-1 px-3 text-uppercase small text-muted">Appearance</li>
          <?= $sidebarLink('main', 'admin/carousel', 'Login Carousel', 'ph-light ph-images', ['rbac.manage']) ?>
        <?php endif; ?>
      </ul>
    </nav>
  </div>
</aside>
<script src="<?= base_url('assets/js/sidebar-state.js') ?>"></script>

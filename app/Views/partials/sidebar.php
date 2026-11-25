<?php
use App\Services\PortalContext;
use App\Services\Ai\AiAccess;

// Authentication filters refresh this identity before protected views render.
$currentUser = session()->get('user') ?? null;
$role = $currentUser['role'] ?? 'guest';
$isSelfServiceRole = in_array($role, ['student', 'employee'], true);
$showPersonalHeading = !$isSelfServiceRole;
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
    array $permissions = [], bool $exact = false) use ($role, $uri, $isActive, $canAll): string {
    if (!PortalContext::isRolePermitted($role, $portal) || !$canAll($permissions)) { return ''; }
    $active = $exact ? ($uri === $path || $uri === $path . '/dashboard') : $isActive($path);
    return '<li class="nav-item"><a class="nav-link' . ($active ? ' active' : '') . '" href="'
        . esc(PortalContext::urlFor($portal, $path)) . '"><span class="nav-icon"><i class="' . esc($icon)
        . '"></i></span><span class="nav-text">' . esc($label) . '</span>'

        . '</a></li>';
};
$sidebarSubmenuLink = static function (string $portal, string $path, string $label,
    array $permissions = [], bool $exact = false) use ($role, $uri, $isActive, $canAll): string {
    if (!PortalContext::isRolePermitted($role, $portal) || !$canAll($permissions)) { return ''; }
    $active = $exact ? ($uri === $path || $uri === $path . '/dashboard') : $isActive($path);
    return '<li><a class="nav-link' . ($active ? ' active' : '') . '" href="'
        . esc(PortalContext::urlFor($portal, $path)) . '"><span class="nav-dot" aria-hidden="true"></span><span class="nav-text">'
        . esc($label) . '</span></a></li>';
};
$sidebarGroup = static function (string $label, string $icon, array $items) use ($role, $canAll, $sidebarLink, $sidebarSubmenuLink): string {
    $authorized = [];
    foreach ($items as $item) {
        if (($item['visible'] ?? true) !== true) { continue; }
        if (!PortalContext::isRolePermitted($role, $item['portal']) || !$canAll($item['permissions'] ?? [])) { continue; }
        $authorized[] = $item;
    }
    if ($authorized === []) { return ''; }
    if (count($authorized) === 1) {
        $only = $authorized[0];
        return $sidebarLink($only['portal'], $only['path'], $only['label'], $only['icon'], $only['permissions'] ?? [], (bool) ($only['exact'] ?? false));
    }
    $renderedItems = [];
    $isOpen = false;
    foreach ($authorized as $item) {
        $markup = $sidebarSubmenuLink($item['portal'], $item['path'], $item['label'], $item['permissions'] ?? [], (bool) ($item['exact'] ?? false));
        if ($markup === '') { continue; }
        if (str_contains($markup, 'class="nav-link active"')) { $isOpen = true; }
        $renderedItems[] = $markup;
    }
    if ($renderedItems === []) { return ''; }
    return '<li class="nav-item has-submenu' . ($isOpen ? ' open' : '') . '">'
        . '<button type="button" class="nav-link" aria-expanded="' . ($isOpen ? 'true' : 'false') . '">'
        . '<span class="nav-icon"><i class="' . esc($icon) . '" aria-hidden="true"></i></span>'
        . '<span class="nav-text">' . esc($label) . '</span>'
        . '<span class="nav-arrow" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>'
        . '</button>'
        . '<ul class="nav-submenu">'
        . implode("\n", $renderedItems)
        . '</ul></li>';
};
$canAiInsights = static function (string $unit) use ($currentUser): bool {
    try {
        AiAccess::assertActor($currentUser, (int) ($currentUser['tenant_id'] ?? 0), 'ai.insights.read', $unit);
        return true;
    } catch (\Throwable) { return false; }
};
$canMain = PortalContext::isRolePermitted($role, 'main') && PortalContext::compatibleHost($role);
$canAuditNavigation = $canMain && in_array($role, ['superadmin', 'audit_reader'], true) && $canContent('audit.read');
$canArchiveNavigation = $canMain && $canContent('archive.read')
    && in_array($role, ['superadmin', 'clinic_admin', 'guidance_admin', 'guidance_supervisor', 'bmg_admin', 'audit_reader'], true);
$canReportsDashboard = $canMain && in_array($role, ['superadmin', 'audit_reader', 'report_viewer'], true) && $canContent('reports.read');
$canExportNavigation = $canMain && in_array($role, ['superadmin', 'audit_reader', 'report_viewer', 'clinic_admin', 'guidance_admin', 'bmg_admin'], true)
    && $canAll(['reports.read', 'reports.export']);
$canAccessNavigation = $canMain && in_array($role, ['superadmin', 'clinic_admin', 'guidance_admin'], true) && $canContent('rbac.manage');
$canInternsNavigation = $canMain && in_array($role, ['superadmin', 'clinic_admin', 'guidance_admin', 'bmg_admin'], true) && $canContent('interns.manage');
$canMisNavigation = $canMain && in_array($role, ['superadmin', 'clinic_admin', 'guidance_admin'], true) && $canContent('mis.sync');
?>
<aside class="sidebar" id="app-sidebar" aria-label="Main navigation">
  <div class="sidebar-shell">
    <div class="sidebar-brand-row">
      <a href="<?= site_url('/') ?>" class="sidebar-brand" aria-label="SYNAPSE home">
        <img src="<?= base_url('assets/img/brand/synapse-white.png') ?>" class="sidebar-brand-logo" alt="" width="38" height="38">
        <span class="sidebar-brand-copy">
          <strong>SYNAPSE</strong>
          <span>Foundation University</span>
        </span>
      </a>
      <button class="sidebar-close" type="button" aria-label="Close sidebar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <nav class="sidebar-nav">
      <ul class="nav-menu">
        <?php if (PortalContext::showsWorkstation('clinic', $role)): ?>
          <li class="nav-heading mt-2 mb-1 px-3 text-uppercase small text-muted">Clinic Workstation</li>
          <?= $sidebarLink('clinic', 'clinic', 'Clinic Dashboard', 'bi bi-grid-1x2', ['clinic.queue.read', 'clinic.encounters.read', 'clinic.appointments.read', 'clinic.inventory.read'], true) ?>
          <?= $sidebarGroup('Front Desk & Intake', 'bi bi-inbox', [
              ['portal' => 'clinic', 'path' => 'clinic/queue', 'label' => 'Clinic Queue', 'icon' => 'bi bi-clock-history', 'permissions' => ['clinic.queue.read']],
              ['portal' => 'clinic', 'path' => 'clinic/appointments', 'label' => 'Appointments', 'icon' => 'bi bi-calendar-check', 'permissions' => ['clinic.appointments.read']],
              ['portal' => 'clinic', 'path' => 'clinic/referrals', 'label' => 'Referrals', 'icon' => 'bi bi-arrow-left-right', 'permissions' => ['referrals.read']],
          ]) ?>
          <?= $sidebarGroup('Clinical Care', 'bi bi-heart-pulse', [
              ['portal' => 'clinic', 'path' => 'clinic/patients', 'label' => 'Patients & Charts', 'icon' => 'bi bi-person-lines-fill', 'permissions' => ['clinic.patients.read']],
              ['portal' => 'clinic', 'path' => 'clinic/encounters', 'label' => 'Encounters & Stepper', 'icon' => 'bi bi-heart-pulse', 'permissions' => ['clinic.encounters.read']],
              ['portal' => 'clinic', 'path' => 'clinic/isolation', 'label' => 'Isolation & Outbreaks', 'icon' => 'bi bi-shield-exclamation', 'permissions' => ['clinic.isolation.read']],
          ]) ?>
          <?= $sidebarGroup('Inventory & Reports', 'bi bi-capsule', [
              ['portal' => 'clinic', 'path' => 'clinic/inventory', 'label' => 'Medicine Inventory', 'icon' => 'bi bi-capsule', 'permissions' => ['clinic.inventory.read']],
              ['portal' => 'clinic', 'path' => 'clinic/reports', 'label' => 'Periodic & CHED Reports', 'icon' => 'bi bi-file-earmark-bar-graph', 'permissions' => ['clinic.reports.export', 'reports.read']],
          ]) ?>
          <?= $sidebarGroup('Team & Insights', 'bi bi-people', [
              ['portal' => 'clinic', 'path' => 'clinic/teams', 'label' => 'Clinic Teams', 'icon' => 'bi bi-people'],
              ['portal' => 'clinic', 'path' => 'clinic/announcements', 'label' => 'Clinic Announcements', 'icon' => 'bi bi-megaphone', 'permissions' => ['clinic.announcements.manage'], 'visible' => in_array($role, ['clinic_admin', 'superadmin'], true)],
              ['portal' => 'clinic', 'path' => 'clinic/insights', 'label' => 'Insights & Program Planning', 'icon' => 'bi bi-lightbulb', 'visible' => $canAiInsights('clinic')],
          ]) ?>
        <?php endif; ?>

        <?php if (PortalContext::showsWorkstation('guidance', $role)): ?>
          <li class="nav-heading mt-3 mb-1 px-3 text-uppercase small text-muted">Guidance Workstation</li>
          <?= $sidebarLink('guidance', 'guidance', 'Guidance Dashboard', 'bi bi-grid-1x2', ['guidance.cases.read', 'guidance.queue.read', 'referrals.read'], true) ?>
          <?= $sidebarGroup('Front Desk & Intake', 'bi bi-inbox', [
              ['portal' => 'guidance', 'path' => 'guidance/queue', 'label' => 'Guidance Queue', 'icon' => 'bi bi-clock-history', 'permissions' => ['guidance.queue.read']],
              ['portal' => 'guidance', 'path' => 'guidance/appointments', 'label' => 'Appointments', 'icon' => 'bi bi-calendar-check', 'permissions' => ['guidance.schedule.read']],
              ['portal' => 'guidance', 'path' => 'guidance/referrals', 'label' => 'Referrals', 'icon' => 'bi bi-arrow-left-right', 'permissions' => ['referrals.read']],
              ['portal' => 'guidance', 'path' => 'guidance/intake', 'label' => 'Intake & Safety Triage', 'icon' => 'bi bi-inbox', 'permissions' => ['guidance.intake.process']],
              ['portal' => 'guidance', 'path' => 'guidance/call-slips', 'label' => 'Call Slips', 'icon' => 'bi bi-ticket-perforated', 'permissions' => ['guidance.call_slips.issue']],
          ]) ?>
          <?= $sidebarGroup('Clients & Counseling', 'bi bi-folder2-open', [
              ['portal' => 'guidance', 'path' => 'guidance/clients', 'label' => 'Clients & Records', 'icon' => 'bi bi-person-lines-fill', 'permissions' => ['guidance.cases.read']],
              ['portal' => 'guidance', 'path' => 'guidance/cases', 'label' => 'Counseling Cases', 'icon' => 'bi bi-folder2-open', 'permissions' => ['guidance.cases.read']],
              ['portal' => 'guidance', 'path' => 'guidance/episodes', 'label' => 'Guidance Episodes', 'icon' => 'bi bi-journal-medical', 'permissions' => ['guidance.cases.read']],
          ]) ?>
          <?= $sidebarGroup('Assessments & Surveys', 'bi bi-clipboard2-check', [
              ['portal' => 'guidance', 'path' => 'guidance/assessments/mi', 'label' => 'MI Assessments', 'icon' => 'bi bi-stars', 'permissions' => ['guidance.assessments.view']],
              ['portal' => 'guidance', 'path' => 'guidance/evaluations/dashboard', 'label' => 'Service Evaluations', 'icon' => 'bi bi-bar-chart-line', 'permissions' => ['guidance.evaluations.view_aggregates']],
              ['portal' => 'guidance', 'path' => 'guidance/surveys', 'label' => 'Surveys & Responses', 'icon' => 'bi bi-clipboard-check', 'permissions' => ['guidance.surveys.manage'], 'visible' => in_array($role, ['guidance_admin', 'guidance_supervisor', 'superadmin'], true)],
              ['portal' => 'guidance', 'path' => 'guidance/question-bank', 'label' => 'Question Bank', 'icon' => 'bi bi-collection', 'permissions' => ['guidance.surveys.manage'], 'visible' => in_array($role, ['guidance_admin', 'guidance_supervisor', 'superadmin'], true)],
          ]) ?>
          <?= $sidebarGroup('Clinical Supervision', 'bi bi-shield-check', [
              ['portal' => 'guidance', 'path' => 'guidance/clinical/cosign-queue', 'label' => 'Co-Sign Queue', 'icon' => 'bi bi-pen', 'permissions' => ['guidance.clinical.cosign']],
              ['portal' => 'guidance', 'path' => 'guidance/governance/practitioners', 'label' => 'Practitioners & Supervision', 'icon' => 'bi bi-person-badge', 'permissions' => ['guidance.cases.read'], 'visible' => $role !== 'guidance_intern'],
              ['portal' => 'guidance', 'path' => 'guidance/governance/gates', 'label' => 'Rollout Gates', 'icon' => 'bi bi-shield-check', 'permissions' => ['guidance.cases.read'], 'visible' => $role !== 'guidance_intern'],
          ]) ?>
          <?= $sidebarGroup('Team & Insights', 'bi bi-people', [
              ['portal' => 'guidance', 'path' => 'guidance/teams', 'label' => 'Guidance Teams', 'icon' => 'bi bi-people'],
              ['portal' => 'guidance', 'path' => 'guidance/announcements', 'label' => 'Guidance Announcements', 'icon' => 'bi bi-megaphone', 'permissions' => ['guidance.announcements.manage'], 'visible' => in_array($role, ['guidance_admin', 'guidance_supervisor', 'superadmin'], true)],
              ['portal' => 'guidance', 'path' => 'guidance/insights', 'label' => 'Insights & Program Planning', 'icon' => 'bi bi-lightbulb', 'visible' => $canAiInsights('guidance')],
          ]) ?>
        <?php endif; ?>

        <?php if (PortalContext::showsWorkstation('bmg', $role)): ?>
          <li class="nav-heading mt-3 mb-1 px-3 text-uppercase small text-muted">Facilities (BMG)</li>
          <?= $sidebarLink('bmg', 'facilities/dashboard', 'Facilities Dashboard', 'bi bi-grid-1x2', ['facilities.read']) ?>
          <?php if ($canContent('facilities.read')): ?>
          <li class="nav-item"><a class="nav-link <?= $isActive('facilities') && !$isActive('facilities/dashboard') && !$isActive('facilities/teams') ? 'active' : '' ?>" href="<?= esc(PortalContext::urlFor('bmg', 'facilities')) ?>"><span class="nav-icon"><i class="ph-light ph-recycle"></i></span><span class="nav-text">Drum Rotation</span></a></li>
          <?php endif; ?>
          <?= $sidebarLink('bmg', 'facilities/teams', 'Facilities Teams', 'bi bi-people') ?>
          <?php if ($canAiInsights('bmg')): ?>
            <?= $sidebarLink('bmg', 'facilities/insights', 'Insights & Program Planning', 'bi bi-lightbulb') ?>
          <?php endif; ?>
        <?php endif; ?>

        <!-- Personal self-service remains available to staff acting as employees. -->
        <?php if ($showPersonalHeading): ?>
          <li class="nav-heading mt-3 mb-1 px-3 text-uppercase small text-muted">Personal Health</li>
        <?php endif; ?>
        <li class="nav-item<?= $isSelfServiceRole ? ' personal-portal-entry' : '' ?>"><a class="nav-link <?= $isActive('portal') && !$isActive('portal/announcements') && !$isActive('portal/surveys') && !$isActive('portal/settings') && !$isActive('portal/visits') && !$isActive('portal/referrals') && !$isActive('portal/assessments') && !$isActive('portal/evaluations') ? 'active' : '' ?>" href="<?= esc(PortalContext::urlFor('main', 'portal')) ?>"><span class="nav-icon"><i class="ph-light ph-user-circle"></i></span><span class="nav-text">My Health Portal</span></a></li>
        <?php if (!empty($currentUser['person_id']) && in_array('portal.records.read', $currentUser['permissions'] ?? [], true)): ?>
          <li class="nav-item"><a class="nav-link <?= $isActive('portal/visits') ? 'active' : '' ?>" href="<?= esc(PortalContext::urlFor('main', 'portal/visits')) ?>"><span class="nav-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span><span class="nav-text">My Visit History</span></a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link <?= $isActive('portal/announcements') ? 'active' : '' ?>" href="<?= esc(PortalContext::urlFor('main', 'portal/announcements')) ?>"><span class="nav-icon"><i class="bi bi-megaphone"></i></span><span class="nav-text">Announcements</span></a></li>
        <?php if (!empty($currentUser['person_id'])): ?>
          <li class="nav-item"><a class="nav-link <?= $isActive('portal/surveys') || $isActive('portal/assessments/mi') ? 'active' : '' ?>" href="<?= esc(PortalContext::urlFor('main', 'portal/surveys')) ?>"><span class="nav-icon"><i class="bi bi-clipboard-check"></i></span><span class="nav-text">My Surveys</span></a></li>
          <?php if ($role !== 'guest' && in_array(($currentUser['person_kind'] ?? ($role === 'employee' ? 'employee' : null)), ['employee'], true)): ?>
            <li class="nav-item"><a class="nav-link <?= $isActive('portal/referrals') ? 'active' : '' ?>" href="<?= esc(PortalContext::urlFor('main', 'portal/referrals/create')) ?>"><span class="nav-icon"><i class="bi bi-envelope-paper"></i></span><span class="nav-text">Guidance Referral</span></a></li>
          <?php endif; ?>
          <?php if ($canContent('guidance.evaluations.submit')): ?>
            <li class="nav-item"><a class="nav-link <?= $isActive('portal/evaluations') ? 'active' : '' ?>" href="<?= esc(PortalContext::urlFor('main', 'portal/evaluations/redeem')) ?>"><span class="nav-icon"><i class="bi bi-chat-square-heart"></i></span><span class="nav-text">Service Evaluation</span></a></li>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($currentUser['person_id']) && $role !== 'guest'): ?>
          <li class="nav-item"><a class="nav-link <?= $isActive('portal/settings') ? 'active' : '' ?>" href="<?= esc(PortalContext::urlFor('main', 'portal/settings')) ?>"><span class="nav-icon"><i class="bi bi-person-gear" aria-hidden="true"></i></span><span class="nav-text">Profile &amp; Settings</span></a></li>
        <?php endif; ?>

        <?php if ($canAuditNavigation || $canReportsDashboard || $canExportNavigation || $canArchiveNavigation): ?>
          <li class="nav-heading mt-3 mb-1 px-3 text-uppercase small text-muted">Governance</li>
          <?php if ($canAuditNavigation): ?>
            <?= $sidebarLink('main', 'admin', 'Admin Dashboard', 'bi bi-grid-1x2', ['audit.read'], true) ?>
            <?= $sidebarLink('main', 'admin/audit', 'Audit Chain Ledger', 'ph-light ph-fingerprint', ['audit.read']) ?>
          <?php elseif ($canReportsDashboard): ?>
            <?= $sidebarLink('main', 'dashboard', 'Reports Dashboard', 'bi bi-grid-1x2', ['reports.read']) ?>
          <?php endif; ?>
          <?php if ($canArchiveNavigation): ?>
            <?= $sidebarLink('main', 'admin/archive', 'Archive Management', 'bi bi-archive', ['archive.read']) ?>
          <?php endif; ?>
          <?php if ($canExportNavigation): ?>
            <?= $sidebarLink('main', 'reports/export', 'Operational reports', 'bi bi-download', ['reports.read', 'reports.export']) ?>
          <?php endif; ?>
        <?php endif; ?>

        <?php $canAiSettings = $canMain && $role === 'superadmin'
            && (in_array('rbac.manage', $currentUser['permissions'] ?? [], true) || in_array('ai.configuration.read', $currentUser['permissions'] ?? [], true)); ?>
        <?php if ($canAccessNavigation || $canInternsNavigation || $canMisNavigation || $canAiSettings): ?>
          <li class="nav-heading mt-3 mb-1 px-3 text-uppercase small text-muted">Administration</li>
          <?php if ($canAccessNavigation): ?>
            <?= $sidebarLink('main', 'admin/access', 'Employees & Access', 'bi bi-person-lock', ['rbac.manage']) ?>
          <?php endif; ?>
          <?php if ($canInternsNavigation): ?>
            <?= $sidebarLink('main', 'admin/interns', 'Student Interns', 'bi bi-mortarboard', ['interns.manage']) ?>
          <?php endif; ?>
          <?php if ($canMisNavigation): ?>
            <?= $sidebarLink('main', 'admin/mis-sync', 'MIS reference synchronization', 'bi bi-arrow-repeat', ['mis.sync']) ?>
          <?php endif; ?>
          <?php if ($canAiSettings): ?>
            <?= $sidebarLink('main', 'admin/ai', 'AI Settings', 'bi bi-sliders') ?>
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
<button class="sidebar-overlay" type="button" tabindex="-1" aria-label="Close navigation"></button>
<script src="<?= base_url('assets/js/sidebar-state.js') ?>"></script>

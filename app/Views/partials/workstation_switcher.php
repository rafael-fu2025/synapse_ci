<?php
$switchUser = session()->get('user') ?? [];
$switchRole = $switchUser['role'] ?? 'guest';
$switchOptions = [];
foreach (['clinic' => 'Clinic workstation', 'guidance' => 'Guidance workstation', 'bmg' => 'Facilities workstation'] as $switchPortal => $switchLabel) {
    if (\App\Services\PortalContext::isRolePermitted($switchRole, $switchPortal)) {
        $switchOptions[$switchPortal] = $switchLabel;
    }
}
?>
<?php if ($switchOptions !== [] && \App\Services\PortalContext::hasDedicatedHost()): ?>
<div class="dropdown">
  <button class="header-action" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Switch workspace" title="Switch workspace"><i class="bi bi-grid" aria-hidden="true"></i></button>
  <ul class="dropdown-menu dropdown-menu-end">
    <li><h6 class="dropdown-header">Switch workspace</h6></li>
    <li><a class="dropdown-item" href="<?= esc(\App\Services\PortalContext::urlFor('main', 'portal')) ?>" data-navigation="false">Personal health portal</a></li>
    <?php foreach ($switchOptions as $switchPortal => $switchLabel): ?><li><a class="dropdown-item" href="<?= esc(\App\Services\PortalContext::urlFor($switchPortal, \App\Services\PortalContext::homePath($switchPortal))) ?>" data-navigation="false"><?= esc($switchLabel) ?></a></li><?php endforeach; ?>
    <?php if ($switchRole === 'superadmin'): ?><li><a class="dropdown-item" href="<?= esc(\App\Services\PortalContext::urlFor('main', 'admin')) ?>" data-navigation="false">Administration</a></li><?php endif; ?>
    <li><hr class="dropdown-divider"></li><li class="px-3 small text-muted" style="max-width: 260px">Workstation hosts have separate sign-in sessions.</li>
  </ul>
</div>
<?php endif; ?>

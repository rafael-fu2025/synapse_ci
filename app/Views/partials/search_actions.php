<?php
use App\Services\PortalContext;

// Protected-page filters refresh this identity; destinations still enforce authorization.
$searchActionUser = session()->get('user') ?? [];
$searchActionRole = $searchActionUser['role'] ?? 'guest';
$searchActionPermissions = $searchActionUser['permissions'] ?? [];
$searchActions = [];
$searchActionCan = static fn (string $permission): bool => $searchActionRole === 'superadmin'
    || in_array($permission, $searchActionPermissions, true);

if ((int) ($searchActionUser['id'] ?? 0) > 0 && PortalContext::isRolePermitted($searchActionRole, 'main')) {
    // Personal booking has no superadmin bypass and also requires a linked person.
    if ((int) ($searchActionUser['person_id'] ?? 0) > 0
        && in_array('portal.appointments.create', $searchActionPermissions, true)) {
        $searchActions[] = ['main', 'portal/appointments/clinic/create', 'Book clinic appointment', 'Personal Health'];
        $searchActions[] = ['main', 'portal/appointments/guidance/create', 'Book guidance appointment', 'Personal Health'];
    }

    foreach (['clinic', 'guidance'] as $searchActionUnit) {
        if (!PortalContext::showsWorkstation($searchActionUnit, $searchActionRole)) {
            continue;
        }
        $searchActionContext = ucfirst($searchActionUnit) . ' Workstation';
        $searchActionAppointmentPermission = $searchActionUnit === 'clinic'
            ? 'clinic.appointments.write' : 'guidance.schedule.manage';
        if ($searchActionCan($searchActionAppointmentPermission)) {
            $searchActions[] = [$searchActionUnit, $searchActionUnit . '/appointments/create',
                'Create ' . $searchActionUnit . ' appointment', $searchActionContext];
        }
        if ($searchActionCan('referrals.create')) {
            $searchActions[] = [$searchActionUnit, $searchActionUnit . '/referrals/create',
                'Create referral to ' . ($searchActionUnit === 'clinic' ? 'guidance' : 'clinic'), $searchActionContext];
        }
        if ($searchActionUnit === 'guidance' && $searchActionCan('guidance.cases.create')) {
            $searchActions[] = ['guidance', 'guidance/cases/create', 'Open new guidance case', $searchActionContext];
        }
    }
}
?>
<template hidden data-search-actions>
  <?php foreach ($searchActions as [$searchActionPortal, $searchActionPath, $searchActionLabel, $searchActionContext]): ?>
    <a href="<?= esc(PortalContext::urlFor($searchActionPortal, $searchActionPath), 'attr') ?>" data-search-label="<?= esc($searchActionLabel, 'attr') ?>" data-search-context="<?= esc($searchActionContext, 'attr') ?>" data-app-modal-open><?= esc($searchActionLabel) ?></a>
  <?php endforeach; ?>
</template>

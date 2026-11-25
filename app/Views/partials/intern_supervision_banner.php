<?php
$internUser = $user ?? (session()->get('user') ?? []);
$placement = is_array($internUser['intern_placement'] ?? null) ? $internUser['intern_placement'] : null;
$internRole = (string) ($internUser['role'] ?? '');
if ($placement === null && in_array($internRole, \App\Services\StudentInternService::INTERN_ROLES, true) && !empty($internUser['id']) && !empty($internUser['tenant_id'])) {
    $placement = (new \App\Services\StudentInternService())->activePlacementForUser((int) $internUser['id'], (int) $internUser['tenant_id']);
}
if ($placement === null) {
    return;
}
$endsLabel = (string) ($placement['ends_at'] ?? '');
try {
    $dt = new \DateTimeImmutable($endsLabel, new \DateTimeZone('Asia/Manila'));
    $endsLabel = $dt->format('M j, Y g:i A') . ' PHT';
} catch (\Throwable) {
}
?>
<div class="intern-supervision-banner" role="status" aria-label="Active student internship supervision">
  <span class="intern-supervision-badge"><i class="bi bi-mortarboard" aria-hidden="true"></i> Student Intern Placement</span>
  <span class="intern-supervision-copy">
    Supervised by <strong><?= esc($placement['supervisor_name'] ?? 'Assigned Supervisor') ?></strong>
    <?php if (!empty($placement['academic_term'])): ?>
      · <?= esc($placement['academic_term']) ?>
    <?php endif; ?>
    · Access expires <strong><?= esc($endsLabel) ?></strong>
  </span>
  <span class="intern-supervision-note">Staff workstation actions are supervised and logged; use <a href="<?= esc(\App\Services\PortalContext::urlFor('main', 'portal')) ?>">My Health Portal</a> for personal records.</span>
</div>

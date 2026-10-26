<?php $notificationDestinations ??= \App\Services\NotificationService::destinationUrls(); ?>
<div class="header-action-wrap dropdown notification-dropdown" data-notifications
     data-list-url="<?= esc(site_url('/notifications'), 'attr') ?>"
     data-read-base="<?= esc(site_url('/notifications'), 'attr') ?>"
     data-read-all-url="<?= esc(site_url('/notifications/read-all'), 'attr') ?>"
     data-portal-url="<?= esc($notificationDestinations['portal'], 'attr') ?>"
     data-clinic-referrals-url="<?= esc($notificationDestinations['clinicReferrals'], 'attr') ?>"
     data-guidance-referrals-url="<?= esc($notificationDestinations['guidanceReferrals'], 'attr') ?>"
     data-clinic-referral-history-url="<?= esc($notificationDestinations['clinicReferralHistory'] ?? '', 'attr') ?>"
     data-guidance-referral-history-url="<?= esc($notificationDestinations['guidanceReferralHistory'] ?? '', 'attr') ?>"
     data-clinic-appointments-url="<?= esc($notificationDestinations['clinicAppointments'] ?? '', 'attr') ?>"
     data-guidance-appointments-url="<?= esc($notificationDestinations['guidanceAppointments'] ?? '', 'attr') ?>"
     data-clinic-referral-sent-url="<?= esc($notificationDestinations['clinicReferralSent'] ?? '', 'attr') ?>"
     data-guidance-referral-sent-url="<?= esc($notificationDestinations['guidanceReferralSent'] ?? '', 'attr') ?>"
     data-guidance-cases-url="<?= esc($notificationDestinations['guidanceCases'] ?? '', 'attr') ?>"
     data-clinic-inventory-url="<?= esc($notificationDestinations['clinicInventory'] ?? '', 'attr') ?>"
     data-facilities-operations-url="<?= esc($notificationDestinations['facilitiesOperations'] ?? '', 'attr') ?>"
     data-announcements-url="<?= esc($notificationDestinations['announcements'], 'attr') ?>"
     data-surveys-url="<?= esc($notificationDestinations['surveys'], 'attr') ?>"
     data-clinic-teams-url="<?= esc($notificationDestinations['clinicTeams'], 'attr') ?>"
     data-clinic-team-calendar-url="<?= esc($notificationDestinations['clinicTeamCalendar'], 'attr') ?>"
     data-guidance-teams-url="<?= esc($notificationDestinations['guidanceTeams'], 'attr') ?>"
     data-guidance-team-calendar-url="<?= esc($notificationDestinations['guidanceTeamCalendar'], 'attr') ?>"
     data-facilities-teams-url="<?= esc($notificationDestinations['facilitiesTeams'], 'attr') ?>"
     data-facilities-team-calendar-url="<?= esc($notificationDestinations['facilitiesTeamCalendar'], 'attr') ?>"
     data-clinic-team-chat-url="<?= esc($notificationDestinations['clinicTeamChat'] ?? '', 'attr') ?>"
     data-guidance-team-chat-url="<?= esc($notificationDestinations['guidanceTeamChat'] ?? '', 'attr') ?>"
     data-facilities-team-chat-url="<?= esc($notificationDestinations['facilitiesTeamChat'] ?? '', 'attr') ?>"
     data-bs-title="Notifications" data-bs-toggle="tooltip" data-bs-placement="bottom">
  <button class="header-action dropdown-toggle" type="button" id="notificationBell"
          data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
          aria-controls="notificationMenu" aria-label="Notifications">
    <i class="bi bi-bell" aria-hidden="true"></i>
    <span class="header-badge d-none" data-notification-badge aria-hidden="true"></span>
  </button>
  <div class="dropdown-menu dropdown-menu-end notification-menu" id="notificationMenu" aria-labelledby="notificationBell">
    <div class="notification-header">
      <div><h6>Notifications</h6><span data-notification-count role="status" aria-live="polite">Checking notifications…</span></div>
      <button class="btn btn-primary btn-sm notification-mark-all" type="button" data-notification-mark-all disabled>Mark all read</button>
    </div>
    <div class="notification-feedback d-none" data-notification-error role="alert">
      <span data-notification-error-text></span>
      <button class="btn btn-outline-secondary btn-sm ms-2" type="button" data-notification-retry>Retry</button>
    </div>
    <ul class="notification-list list-unstyled mb-0" data-notification-list aria-label="Recent personal notifications">
      <li class="p-3 small text-muted">Loading notifications…</li>
    </ul>
    <div class="notification-footer">
      <a href="<?= esc(site_url('notifications/inbox'), 'attr') ?>">View all notifications <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
  </div>
</div>

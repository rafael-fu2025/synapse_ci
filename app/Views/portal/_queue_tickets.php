<?php $tickets = $activeTickets ?? (!empty($activeTicket) ? [$activeTicket] : []); ?>
<section data-personal-queue data-queue-url="<?= esc(site_url('/portal/queue'), 'attr') ?>" class="portal-queue-section mb-3" aria-label="My waiting room tickets">
  <div data-personal-queue-tickets aria-live="polite" aria-atomic="true">
    <?php if ($tickets === []): ?>
      <div class="portal-queue-empty" data-queue-empty>
        <span class="portal-queue-empty-icon" aria-hidden="true">
          <i class="bi bi-card-checklist"></i>
        </span>
        <div class="portal-queue-empty-copy">
          <p class="portal-queue-empty-title">No active waiting room tickets today.</p>
          <p class="portal-queue-empty-body">Your live waiting room ticket appears here once the clinic or guidance center checks you in.</p>
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($tickets as $ticket): ?>
        <?php
        $unitKey = ($ticket['unit'] ?? 'clinic') === 'guidance' ? 'guidance' : 'clinic';
        $statusKey = (string) ($ticket['status'] ?? 'waiting');
        $statusModifier = in_array($statusKey, ['waiting', 'called', 'in_consultation', 'skipped'], true) ? $statusKey : 'waiting';
        $statusText = $statusKey === 'in_consultation' ? 'In Consultation' : ucfirst($statusKey);
        $position = $ticket['position'] ?? null;
        $ahead = $ticket['people_ahead'] ?? null;
        $minutes = $ticket['estimated_wait_minutes'] ?? null;
        $positionLabel = match ($statusKey) {
            'called' => 'Your turn', 'in_consultation' => 'In progress', 'skipped' => 'On hold',
            default => is_int($position) && $position > 0 ? '#' . $position : '—',
        };
        $aheadLabel = $statusKey === 'skipped' ? '—'
            : (in_array($statusKey, ['called', 'in_consultation'], true) ? '0' : (is_int($ahead) && $ahead >= 0 ? (string) $ahead : '—'));
        $waitLabel = match ($statusKey) {
            'called' => 'Now', 'in_consultation' => 'In progress', 'skipped' => 'Ask reception',
            default => $minutes === 0 ? 'You’re next' : (is_int($minutes) && $minutes > 0 ? '~' . $minutes . ' min' : 'Not enough recent data'),
        };
        $priorityText = !empty($ticket['priority']) ? ucfirst((string) $ticket['priority']) : 'Normal';
        ?>
        <div class="portal-queue-card portal-queue-card--<?= esc($unitKey, 'attr') ?> portal-queue-card--<?= esc($statusModifier, 'attr') ?>" data-queue-unit="<?= esc($unitKey, 'attr') ?>" data-queue-status="<?= esc($statusModifier, 'attr') ?>">
          <div class="portal-queue-head">
            <div class="portal-queue-copy">
              <span class="portal-queue-pill">Live waiting ticket</span>
              <h3 class="portal-queue-code"><?= esc($ticket['ticket_code']) ?></h3>
            </div>
            <div class="portal-queue-priority">
              <span class="portal-queue-priority-label">Priority</span>
              <span class="portal-queue-priority-value"><?= esc($priorityText) ?></span>
            </div>
          </div>
          <p class="portal-queue-meta"><?= $unitKey === 'clinic' ? 'University Clinic' : 'Guidance & Counseling Center' ?> · Status: <strong><?= esc($statusText) ?></strong></p>
          <dl class="portal-queue-stats" data-personal-queue-progress>
            <div class="portal-queue-stat"><dt class="portal-queue-stat-label">Your place in line</dt><dd class="portal-queue-stat-val" data-queue-position><?= esc($positionLabel) ?></dd></div>
            <div class="portal-queue-stat portal-queue-stat--divided"><dt class="portal-queue-stat-label">People ahead</dt><dd class="portal-queue-stat-val" data-queue-ahead><?= esc($aheadLabel) ?></dd></div>
            <div class="portal-queue-stat portal-queue-stat--divided"><dt class="portal-queue-stat-label">Estimated wait</dt><dd class="portal-queue-stat-val<?= strlen($waitLabel) > 14 ? ' is-long' : '' ?>" data-queue-wait><?= esc($waitLabel) ?></dd></div>
          </dl>
          <?php if ($statusKey === 'waiting'): ?>
            <p class="portal-queue-note">Position includes people already called or being seen ahead of you. Wait estimates use recent consultations; urgent cases and staff availability may change the order and waiting time.</p>
          <?php elseif ($statusKey === 'skipped'): ?>
            <p class="portal-queue-note">Your ticket is on hold. Please speak to reception to rejoin the waiting line.</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <p class="small text-muted mt-2 mb-0" data-queue-feedback role="status" hidden></p>
</section>

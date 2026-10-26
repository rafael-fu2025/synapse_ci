<?php $tickets = $activeTickets ?? (!empty($activeTicket) ? [$activeTicket] : []); ?>
<section data-personal-queue data-queue-url="<?= esc(site_url('/portal/queue'), 'attr') ?>" class="mb-4" aria-label="My waiting room tickets">
  <div data-personal-queue-tickets aria-live="polite" aria-atomic="true">
    <?php if ($tickets === []): ?>
      <div class="alert alert-light border mb-0" data-queue-empty>No active waiting room tickets today.</div>
    <?php else: ?>
      <?php foreach ($tickets as $ticket): ?>
        <?php
        $position = $ticket['position'] ?? null;
        $ahead = $ticket['people_ahead'] ?? null;
        $minutes = $ticket['estimated_wait_minutes'] ?? null;
        $positionLabel = match ($ticket['status']) {
            'called' => 'Your turn', 'in_consultation' => 'In progress', 'skipped' => 'On hold',
            default => is_int($position) && $position > 0 ? '#' . $position : '—',
        };
        $aheadLabel = $ticket['status'] === 'skipped' ? '—'
            : (in_array($ticket['status'], ['called', 'in_consultation'], true) ? '0' : (is_int($ahead) && $ahead >= 0 ? (string) $ahead : '—'));
        $waitLabel = match ($ticket['status']) {
            'called' => 'Now', 'in_consultation' => 'In progress', 'skipped' => 'Ask reception',
            default => $minutes === 0 ? 'You’re next' : (is_int($minutes) && $minutes > 0 ? '~' . $minutes . ' min' : 'Not enough recent data'),
        };
        ?>
        <div class="card border-0 shadow-sm bg-primary text-white p-4 mb-2">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
              <span class="badge bg-white text-primary text-uppercase mb-2">Live Waiting Room Ticket</span>
              <h3 class="fw-bold mb-1 display-6 text-white"><?= esc($ticket['ticket_code']) ?></h3>
              <p class="mb-0 opacity-75"><?= $ticket['unit'] === 'clinic' ? 'University Clinic' : 'Guidance & Counseling Center' ?> · Status: <strong><?= esc($ticket['status'] === 'in_consultation' ? 'In Consultation' : ucfirst($ticket['status'])) ?></strong></p>
            </div>
            <span class="badge bg-light text-dark px-3 py-2 fw-semibold">Priority: <?= esc(ucfirst($ticket['priority'])) ?></span>
          </div>
          <dl class="row g-3 mt-2 mb-0" data-personal-queue-progress>
            <div class="col-12 col-sm-4"><dt class="small fw-normal opacity-75">Your place in line</dt><dd class="fs-5 fw-bold mb-0" data-queue-position><?= esc($positionLabel) ?></dd></div>
            <div class="col-12 col-sm-4"><dt class="small fw-normal opacity-75">People ahead</dt><dd class="fs-5 fw-bold mb-0" data-queue-ahead><?= esc($aheadLabel) ?></dd></div>
            <div class="col-12 col-sm-4"><dt class="small fw-normal opacity-75">Estimated wait</dt><dd class="fs-5 fw-bold mb-0" data-queue-wait><?= esc($waitLabel) ?></dd></div>
          </dl>
          <?php if ($ticket['status'] === 'waiting'): ?>
            <p class="small opacity-75 mt-3 mb-0">Position includes people already called or being seen ahead of you. Wait estimates use recent consultations; urgent cases and staff availability may change the order and waiting time.</p>
          <?php elseif ($ticket['status'] === 'skipped'): ?>
            <p class="small opacity-75 mt-3 mb-0">Your ticket is on hold. Please speak to reception to rejoin the waiting line.</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <p class="small text-muted mt-2 mb-0" data-queue-feedback role="status" hidden></p>
</section>

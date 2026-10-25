<?php $tickets = $activeTickets ?? (!empty($activeTicket) ? [$activeTicket] : []); ?>
<section data-personal-queue data-queue-url="<?= esc(site_url('/portal/queue'), 'attr') ?>" class="mb-4" aria-label="My waiting room tickets">
  <div data-personal-queue-tickets aria-live="polite" aria-atomic="true">
    <?php if ($tickets === []): ?>
      <div class="alert alert-light border mb-0" data-queue-empty>No active waiting room tickets today.</div>
    <?php else: ?>
      <?php foreach ($tickets as $ticket): ?>
        <div class="card border-0 shadow-sm bg-primary text-white p-4 mb-2">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
              <span class="badge bg-white text-primary text-uppercase mb-2">Live Waiting Room Ticket</span>
              <h3 class="fw-bold mb-1 display-6 text-white"><?= esc($ticket['ticket_code']) ?></h3>
              <p class="mb-0 opacity-75"><?= $ticket['unit'] === 'clinic' ? 'University Clinic' : 'Guidance & Counseling Center' ?> · Status: <strong><?= esc($ticket['status'] === 'in_consultation' ? 'In Consultation' : ucfirst($ticket['status'])) ?></strong></p>
            </div>
            <span class="badge bg-light text-dark px-3 py-2 fw-semibold">Priority: <?= esc(ucfirst($ticket['priority'])) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <p class="small text-muted mt-2 mb-0" data-queue-feedback role="status" hidden></p>
</section>

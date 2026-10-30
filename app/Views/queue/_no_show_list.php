<?php if (empty($noShows)): ?>
  <div class="queue-history-empty text-muted text-center py-5">
    <i class="bi bi-person-check fs-2 d-block mb-2" aria-hidden="true"></i>
    <p class="mb-0">No patients marked no-show for this date.</p>
  </div>
<?php else: ?>
  <ul class="list-group list-group-flush queue-no-show-list" aria-label="Patients marked no-show">
    <?php foreach ($noShows as $absent): ?>
      <li class="list-group-item d-flex align-items-start gap-3 px-0 py-3">
        <span class="badge bg-secondary flex-shrink-0"><?= esc($absent['ticket_code']) ?></span>
        <span class="queue-history-name small fw-semibold"><?= esc($absent['last_name'] . ', ' . $absent['first_name']) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

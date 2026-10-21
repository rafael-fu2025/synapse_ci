<a class="team-calendar-item is-<?= esc($item['type']) ?> <?= in_array($item['status'], ['cancelled', 'done'], true) ? 'is-complete' : '' ?>" href="<?= esc($item['url']) ?>" data-app-modal-open>
  <span class="team-calendar-item-meta"><i class="bi <?= $item['type'] === 'task' ? 'bi-check2-square' : 'bi-calendar-event' ?> me-1" aria-hidden="true"></i><?= esc($item['label']) ?><?= $item['status'] === 'cancelled' ? ' · Cancelled' : ($item['status'] === 'done' ? ' · Done' : '') ?></span>
  <span><?= esc($item['title']) ?></span>
</a>

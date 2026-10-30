<?php if ($item['queue_date'] <= \App\Services\Scheduling\SchedulingClock::today() && empty($item['started_at']) && empty($item['completed_at'])): ?>
<form action="<?= site_url('/' . $unit . '/queue/no-show/' . $item['id']) ?>" method="POST" class="mt-2" hx-post="<?= site_url('/' . $unit . '/queue/no-show/' . $item['id']) ?>" hx-target="#queue-board-container" hx-swap="innerHTML" hx-include="#queueDateFilter_iso" hx-disabled-elt="find button" data-confirm="Explicitly mark this absence as no-show? Any linked appointment will also be marked no-show. Clinical records are never closed." data-confirm-title="Mark no-show" data-confirm-label="Mark no-show" data-confirm-tone="danger">
  <?= csrf_field() ?>
  <button class="btn btn-outline-danger btn-sm w-100" type="submit">Mark no-show</button>
</form>
<?php endif; ?>

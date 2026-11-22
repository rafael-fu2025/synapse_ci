<button type="button" class="btn btn-outline-secondary btn-sm queue-history-trigger"
        data-bs-toggle="offcanvas" data-bs-target="#queueNoShowHistory" aria-controls="queueNoShowHistory">
  <i class="bi bi-clock-history" aria-hidden="true"></i>
  <span>No-show history</span>
  <span class="queue-history-count-tag" data-queue-no-show-count><?= count($noShows) ?></span>
</button>
<div class="offcanvas offcanvas-end queue-history-drawer" tabindex="-1" id="queueNoShowHistory"
     aria-labelledby="queueNoShowHistoryLabel" aria-describedby="queueNoShowHistoryDescription">
  <div class="offcanvas-header">
    <h2 class="offcanvas-title d-flex align-items-center gap-2 mb-0" id="queueNoShowHistoryLabel">
      <span>No-show history</span>
      <span class="queue-history-count-tag" data-queue-no-show-count><?= count($noShows) ?></span>
    </h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close no-show history"></button>
  </div>
  <div class="queue-history-drawer-meta">
    <p class="mb-2" id="queueNoShowHistoryDescription">Marked absent by staff, not completed consultations. Returning patients need a new ticket.</p>
    <p class="mb-0">Queue date: <strong class="q-mono" data-queue-no-show-date><?= esc($queueDate) ?></strong></p>
  </div>
  <div class="offcanvas-body" data-queue-no-show-list>
    <?= view('queue/_no_show_list', ['noShows' => $noShows]) ?>
  </div>
</div>

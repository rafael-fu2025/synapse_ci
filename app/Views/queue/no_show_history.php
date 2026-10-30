<button type="button" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2"
        data-bs-toggle="offcanvas" data-bs-target="#queueNoShowHistory" aria-controls="queueNoShowHistory">
  <i class="bi bi-clock-history" aria-hidden="true"></i>
  No-show history <span class="badge bg-secondary" data-queue-no-show-count><?= count($noShows) ?></span>
</button>
<div class="offcanvas offcanvas-end queue-history-drawer" tabindex="-1" id="queueNoShowHistory"
     aria-labelledby="queueNoShowHistoryLabel" aria-describedby="queueNoShowHistoryDescription">
  <div class="offcanvas-header border-bottom">
    <h2 class="offcanvas-title h5 d-flex align-items-center gap-2" id="queueNoShowHistoryLabel">
      No-show history <span class="badge bg-secondary" data-queue-no-show-count><?= count($noShows) ?></span>
    </h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close no-show history"></button>
  </div>
  <div class="px-3 pt-3">
    <p class="small text-muted mb-2" id="queueNoShowHistoryDescription">Marked absent by staff, not completed consultations. Returning patients need a new ticket.</p>
    <p class="small text-muted mb-3">Queue date: <span data-queue-no-show-date><?= esc($queueDate) ?></span></p>
  </div>
  <div class="offcanvas-body pt-0" data-queue-no-show-list>
    <?= view('queue/_no_show_list', ['noShows' => $noShows]) ?>
  </div>
</div>
